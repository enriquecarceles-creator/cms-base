<?php
declare(strict_types=1);

/*
 * Envío de correo (Web → Correo). Sin librerías. TODOS los correos del CMS pasan por mail_send() (app_mail() es un atajo).
 *
 * Transportes: `mail()` de PHP (por defecto, como siempre) o SMTP con cliente propio por sockets (SSL, STARTTLS o sin cifrado;
 * AUTH LOGIN/PLAIN). Los datos del SMTP, contraseña incluida, van en data/mail.php (no en la base de datos: no viajan en las
 * copias de seguridad, igual que data/db.php) y la contraseña nunca se vuelve a mostrar. El resto (remitente, «responder a»,
 * avisos, transporte) va en settings (mail_*). Los últimos 50 envíos quedan en la tabla mail_log (sin el cuerpo).
 * Si un envío falla se guarda mail_last_error y el Inicio avisa a los administradores hasta que otro envío salga bien.
 * Con la variable de entorno CMS_MAIL_LOG=/ruta los correos se anotan en ese archivo en vez de enviarse (pruebas).
 * SMTP contra servidores reales con TLS: sin probar (solo contra un servidor falso en local).
 */

const MAIL_SMTP_FILE = DATA_DIR . '/mail.php';
const MAIL_LOG_KEEP = 50;

/** Quita saltos de línea (evita inyectar cabeceras). */
function mail_clean(string $s): string
{
    return trim(str_replace(["\r", "\n", "\0"], ' ', $s));
}

/** Ajustes de correo con sus valores por defecto. */
function mail_settings(): array
{
    $host = preg_replace('/^www\./', '', (string)parse_url(site_origin(), PHP_URL_HOST));
    $from = trim(setting('mail_from'));
    $transport = setting('mail_transport', 'mail');
    return [
        'from_name' => mail_clean(setting('mail_from_name', site_name())) ?: site_name(),
        'from' => filter_var($from, FILTER_VALIDATE_EMAIL) ? $from : 'noreply@' . ($host ?: 'localhost'),
        'reply_to' => filter_var(trim(setting('mail_reply_to')), FILTER_VALIDATE_EMAIL) ? trim(setting('mail_reply_to')) : '',
        'bcc' => filter_var(trim(setting('mail_bcc')), FILTER_VALIDATE_EMAIL) ? trim(setting('mail_bcc')) : '',
        'transport' => $transport === 'smtp' ? 'smtp' : 'mail',
    ];
}

/** Datos del SMTP (data/mail.php) o null. */
function mail_smtp_config(): ?array
{
    if (!is_file(MAIL_SMTP_FILE)) {
        return null;
    }
    $c = include MAIL_SMTP_FILE;
    if (!is_array($c) || empty($c['host'])) {
        return null;
    }
    return [
        'host' => (string)$c['host'], 'port' => (int)($c['port'] ?? 587), 'secure' => in_array($c['secure'] ?? '', ['ssl', 'tls', 'none'], true) ? $c['secure'] : 'tls',
        'user' => (string)($c['user'] ?? ''), 'pass' => (string)($c['pass'] ?? ''),
    ];
}

function mail_smtp_save(array $c): void
{
    $keep = ['host' => (string)$c['host'], 'port' => (int)$c['port'], 'secure' => (string)$c['secure'], 'user' => (string)$c['user'], 'pass' => (string)$c['pass']];
    $php = "<?php\n// Correo SMTP de la web (Web → Correo). No la compartas: contiene la contraseña.\nreturn " . var_export($keep, true) . ";\n";
    if (@file_put_contents(MAIL_SMTP_FILE, $php, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar data/mail.php (¿permisos de la carpeta data/?).');
    }
    @chmod(MAIL_SMTP_FILE, 0640);
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(MAIL_SMTP_FILE, true); // si no, el servidor podría seguir usando la versión anterior
    }
}

/** Cabecera con nombre: «Nombre <correo>» con el nombre codificado si lleva acentos. */
function mail_addr(string $name, string $addr): string
{
    $name = mail_clean($name);
    return ($name !== '' ? (preg_match('/^[\x20-\x7E]*$/', $name) ? '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"' : '=?UTF-8?B?' . base64_encode($name) . '?=') . ' ' : '') . '<' . $addr . '>';
}

/** Destinatarios de los avisos del sistema: los de «Avisos del sistema» (Web → Correo) o, si no hay, los administradores con correo. */
function mail_notify_recipients(): array
{
    $list = [];
    foreach (preg_split('/[\s,;]+/', setting('mail_notify')) ?: [] as $a) {
        if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
            $list[] = $a;
        }
    }
    if (!$list) {
        foreach (db()->query("SELECT email FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN) as $a) {
            $a = trim((string)$a);
            if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) {
                $list[] = $a;
            }
        }
    }
    return array_values(array_unique($list));
}

/* ---------- Registro y aviso de fallos ---------- */

/** Anota un envío en mail_log (solo los últimos MAIL_LOG_KEEP) y mantiene el aviso del Inicio. Nunca lanza errores. */
function mail_record(string $to, string $subject, bool $ok, string $transport, string $error): void
{
    try {
        $db = db();
        $db->prepare('INSERT INTO mail_log (sent_at, to_email, subject, ok, transport, error) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([time(), mb_substr($to, 0, 250), mb_substr($subject, 0, 250), $ok ? 1 : 0, $transport, mb_substr($error, 0, 250)]);
        $ids = $db->query('SELECT id FROM mail_log ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > MAIL_LOG_KEEP) {
            $db->prepare('DELETE FROM mail_log WHERE id < ?')->execute([(int)$ids[MAIL_LOG_KEEP - 1]]);
        }
        if ($ok) {
            if (db_setting('mail_last_error') !== '') { // directo de la base: setting() guarda una copia por petición
                save_setting('mail_last_error', '');
            }
        } else {
            save_setting('mail_last_error', (string)json_encode(['at' => time(), 'error' => mb_substr($error, 0, 250)], JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        error_log('Registro de correo: ' . get_class($e)); // nunca impide el envío
    }
}

/** Último fallo de envío todavía sin resolver: ['at' => marca de tiempo, 'error' => texto] o null. */
function mail_last_error(): ?array
{
    $j = json_decode(setting('mail_last_error'), true);
    return is_array($j) && isset($j['at'], $j['error']) ? ['at' => (int)$j['at'], 'error' => (string)$j['error']] : null;
}

/* ---------- Envío ---------- */

/** Atajo para los correos de texto del CMS (recuperar contraseña, avisos…). */
function app_mail(string $to, string $subject, string $body): bool
{
    return mail_send(['to' => $to, 'subject' => $subject, 'text' => $body])['ok'];
}

/**
 * Envía un correo. $m: to, subject, text, html (opcional), reply_to (opcional), bcc (opcional, además de la copia configurada).
 * Devuelve ['ok' => bool, 'error' => texto sin datos secretos]. Cada intento queda en mail_log.
 */
function mail_send(array $m): array
{
    $cfg = mail_settings();
    $to = mail_clean((string)$m['to']);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El destinatario no es un correo válido.'];
    }
    $subject = mail_clean((string)$m['subject']);
    $text = str_replace(["\r\n", "\r"], "\n", (string)$m['text']);
    $html = isset($m['html']) ? (string)$m['html'] : '';
    $replyTo = mail_clean((string)($m['reply_to'] ?? $cfg['reply_to']));
    $bcc = array_values(array_unique(array_filter([$cfg['bcc'], mail_clean((string)($m['bcc'] ?? ''))], fn($b) => $b !== '' && filter_var($b, FILTER_VALIDATE_EMAIL))));
    $log = (string)getenv('CMS_MAIL_LOG');
    if ($log !== '') { // pruebas: se anota en un archivo en vez de enviarse
        $ok = @file_put_contents($log, "TO: $to\nFROM: " . mail_addr($cfg['from_name'], $cfg['from']) . "\nSUBJECT: $subject\n" . ($bcc ? 'BCC: ' . implode(', ', $bcc) . "\n" : '') . "\n$text\n" . ($html !== '' ? "---HTML---\n$html\n" : '') . "=====\n", FILE_APPEND) !== false;
        $err = $ok ? '' : 'No se pudo escribir el registro de pruebas.';
        mail_record($to, $subject, $ok, 'prueba', $err);
        return ['ok' => $ok, 'error' => $err];
    }
    $headers = ['From: ' . mail_addr($cfg['from_name'], $cfg['from']), 'MIME-Version: 1.0', 'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/^.*@/', '', $cfg['from']) ?: 'localhost') . '>'];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    $b64 = fn(string $s) => rtrim(chunk_split(base64_encode($s), 76, "\r\n"));
    if ($html !== '') {
        $bd = '=_cms_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $bd . '"';
        $body = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($text)
            . "\r\n--$bd\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . $b64($html) . "\r\n--$bd--\r\n";
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = $b64($text) . "\r\n";
    }
    $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    if ($cfg['transport'] === 'smtp') {
        $smtp = mail_smtp_config();
        if (!$smtp) {
            $err = 'El correo SMTP no está configurado (Web → Correo).';
        } else {
            $raw = implode("\r\n", $headers) . "\r\nTo: <$to>\r\nSubject: $encSubject\r\n\r\n" . $body;
            $err = mail_smtp_send($smtp, $cfg['from'], array_merge([$to], $bcc), $raw);
        }
        mail_record($to, $subject, $err === '', 'smtp', $err);
        return ['ok' => $err === '', 'error' => $err];
    }
    if ($bcc) {
        $headers[] = 'Bcc: ' . implode(', ', $bcc);
    }
    $ok = @mail($to, $encSubject, $body, implode("\r\n", $headers));
    $err = $ok ? '' : 'El servidor no aceptó el correo (mail() de PHP). Prueba con SMTP en Web → Correo.';
    mail_record($to, $subject, $ok, 'mail', $err);
    return ['ok' => $ok, 'error' => $err];
}

/** Cliente SMTP mínimo. Devuelve '' si todo fue bien o el motivo del fallo en lenguaje sencillo (nunca la contraseña). */
function mail_smtp_send(array $c, string $from, array $rcpts, string $raw): string
{
    $host = $c['host'];
    $port = $c['port'] > 0 ? $c['port'] : 587;
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client(($c['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        $why = mail_clean($errstr);
        if ($c['secure'] === 'ssl' && preg_match('/ssl|certificate|handshake|crypto/i', $why)) {
            return 'No se pudo abrir la conexión cifrada (SSL) con ' . $host . ':' . $port . '. Comprueba que el cifrado sea el que pide tu proveedor (puerto 465 = SSL, 587 = STARTTLS) y que el servidor sea el nombre exacto que indica el proveedor.';
        }
        return 'No se pudo conectar con ' . $host . ':' . $port . '. Revisa el servidor y el puerto; algunos hostings bloquean el puerto 25.' . ($why !== '' ? ' (' . mb_substr($why, 0, 120) . ')' : '');
    }
    stream_set_timeout($fp, 20);
    $last = '';
    $read = function () use ($fp, &$last): int {
        $all = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $all .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        $last = trim($all);
        return (int)substr($all, 0, 3);
    };
    $say = function (string $cmd) use ($fp, $read): int {
        fwrite($fp, $cmd . "\r\n");
        return $read();
    };
    $fail = function (string $what) use ($fp, &$last): string {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return $what . ($last !== '' ? ' El servidor dijo: ' . mb_substr(mail_clean($last), 0, 160) : '');
    };
    $ehloHost = preg_replace('/[^a-z0-9.\-]/i', '', (string)parse_url(site_origin(), PHP_URL_HOST)) ?: 'localhost';
    if ($read() !== 220) {
        return $fail('El servidor no respondió como un servidor de correo. Revisa el servidor, el puerto y el cifrado (el puerto 465 suele ser SSL; el 587, STARTTLS).');
    }
    if ($say('EHLO ' . $ehloHost) !== 250) {
        return $fail('El servidor rechazó el saludo.');
    }
    $caps = $last;
    if ($c['secure'] === 'tls') {
        if (stripos($caps, 'STARTTLS') === false || $say('STARTTLS') !== 220 || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return $fail('No se pudo iniciar el cifrado STARTTLS. Prueba con SSL (puerto 465) o revisa que el servidor lo admita.');
        }
        if ($say('EHLO ' . $ehloHost) !== 250) {
            return $fail('El servidor rechazó el saludo tras activar el cifrado.');
        }
        $caps = $last;
    }
    if ($c['user'] !== '') {
        if (stripos($caps, 'AUTH') !== false && stripos($caps, 'LOGIN') === false && stripos($caps, 'PLAIN') !== false) {
            $code = $say('AUTH PLAIN ' . base64_encode("\0" . $c['user'] . "\0" . $c['pass']));
        } else {
            $code = $say('AUTH LOGIN');
            if ($code === 334) {
                $code = $say(base64_encode($c['user']));
            }
            if ($code === 334) {
                $code = $say(base64_encode($c['pass']));
            }
        }
        if ($code !== 235) {
            return $fail('El servidor rechazó el usuario o la contraseña. Suele ser la dirección de correo completa; si usas una cuenta con verificación en dos pasos, hace falta una «contraseña de aplicación».');
        }
    }
    if ($say('MAIL FROM:<' . $from . '>') !== 250) {
        return $fail('El servidor no aceptó la dirección del remitente. Usa un buzón del mismo dominio de la web (o el propio buzón del SMTP).');
    }
    foreach ($rcpts as $r) {
        $code = $say('RCPT TO:<' . $r . '>');
        if ($code !== 250 && $code !== 251) {
            return $fail('El servidor no aceptó el destinatario ' . $r . '. Puede que la dirección no exista o que el servidor no deje enviar a direcciones de fuera.');
        }
    }
    if ($say('DATA') !== 354) {
        return $fail('El servidor no aceptó el mensaje.');
    }
    $raw = preg_replace("/\r\n|\r|\n/", "\r\n", $raw);
    $raw = preg_replace('/^\./m', '..', $raw); // dot-stuffing
    fwrite($fp, $raw . "\r\n.\r\n");
    if ($read() !== 250) {
        return $fail('El servidor no confirmó la entrega del mensaje (puede que lo considere spam o que supere su límite de envíos).');
    }
    @fwrite($fp, "QUIT\r\n");
    @fclose($fp);
    return '';
}

/* ---------- Pantalla Web → Correo (solo administradores) ---------- */

function admin_mail(): void
{
    $cfg = mail_settings();
    $smtp = mail_smtp_config();
    $f = [
        'transport' => $cfg['transport'],
        'from_name' => setting('mail_from_name'),
        'from' => setting('mail_from'),
        'reply_to' => setting('mail_reply_to'),
        'notify' => setting('mail_notify'),
        'host' => $smtp['host'] ?? '',
        'port' => (string)($smtp['port'] ?? 587),
        'secure' => $smtp['secure'] ?? 'tls',
        'user' => $smtp['user'] ?? '',
    ];
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'test') {
            $to = mail_clean((string)($_POST['test_to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                flash('Escribe un correo válido al que mandar la prueba.', 'err');
            } else {
                $r = mail_send(['to' => $to, 'subject' => 'Prueba de correo · ' . site_name(),
                    'text' => "Este es un correo de prueba de " . site_name() . ".\n\nSi lo lees, el envío de correo de la web funciona.\n"]);
                if ($r['ok']) {
                    flash('Correo de prueba enviado a ' . $to . '. Si no llega en un par de minutos, mira la carpeta de spam.');
                } else {
                    flash('No se pudo enviar el correo de prueba. ' . $r['error'], 'err');
                }
            }
            redirect('/admin/configuracion/correo');
        }
        if ($action === 'save') {
            $f['transport'] = ($_POST['transport'] ?? '') === 'smtp' ? 'smtp' : 'mail';
            $f['from_name'] = mb_substr(mail_clean((string)($_POST['from_name'] ?? '')), 0, 80);
            $f['from'] = mail_clean((string)($_POST['from'] ?? ''));
            $f['reply_to'] = mail_clean((string)($_POST['reply_to'] ?? ''));
            $f['notify'] = mb_substr(mail_clean((string)($_POST['notify'] ?? '')), 0, 400);
            $f['host'] = mail_clean((string)($_POST['host'] ?? ''));
            $f['port'] = trim((string)($_POST['port'] ?? ''));
            $f['secure'] = in_array($_POST['secure'] ?? '', ['tls', 'ssl', 'none'], true) ? (string)$_POST['secure'] : 'tls';
            $f['user'] = mb_substr(mail_clean((string)($_POST['user'] ?? '')), 0, 200);
            $pass = (string)($_POST['pass'] ?? '');
            if ($f['from'] !== '' && !filter_var($f['from'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'La dirección del remitente no es un correo válido.';
            }
            if ($f['reply_to'] !== '' && !filter_var($f['reply_to'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'La dirección de «Responder a» no es un correo válido.';
            }
            $notify = [];
            foreach (preg_split('/[\s,;]+/', $f['notify']) ?: [] as $a) {
                if ($a === '') {
                    continue;
                }
                if (!filter_var($a, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'En los avisos del sistema, «' . mb_substr($a, 0, 60) . '» no es un correo válido.';
                } else {
                    $notify[] = $a;
                }
            }
            if (count($notify) > 5) {
                $errors[] = 'Como mucho 5 direcciones para los avisos del sistema.';
            }
            $smtpWanted = $f['transport'] === 'smtp' || $f['host'] !== '';
            $port = (int)$f['port'];
            if ($smtpWanted) {
                if ($f['host'] === '' || !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.\-]*[A-Za-z0-9])?$/', $f['host'])) {
                    $errors[] = 'Escribe el servidor SMTP (por ejemplo smtp.tudominio.com), sin «http://» ni espacios.';
                }
                if ($port < 1 || $port > 65535) {
                    $errors[] = 'El puerto debe ser un número entre 1 y 65535 (lo habitual: 587 con STARTTLS, 465 con SSL).';
                }
                // La contraseña guardada solo se conserva si no cambian el servidor ni el usuario: no se le entrega a otro servidor.
                if ($pass === '' && $smtp && ($smtp['host'] !== $f['host'] || $smtp['user'] !== $f['user']) && $smtp['pass'] !== '') {
                    $errors[] = 'Has cambiado el servidor o el usuario: escribe también la contraseña.';
                }
            }
            if (!$errors) {
                try {
                    if ($smtpWanted) {
                        mail_smtp_save(['host' => $f['host'], 'port' => $port, 'secure' => $f['secure'], 'user' => $f['user'],
                            'pass' => $pass !== '' ? $pass : ($smtp['pass'] ?? '')]);
                    }
                    save_setting('mail_transport', $f['transport']);
                    save_setting('mail_from_name', $f['from_name']);
                    save_setting('mail_from', $f['from']);
                    save_setting('mail_reply_to', $f['reply_to']);
                    save_setting('mail_notify', implode(', ', $notify));
                    flash('Configuración de correo guardada. Pulsa «Enviar correo de prueba» para comprobarla.');
                    redirect('/admin/configuracion/correo');
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
    $log = db()->query('SELECT sent_at, to_email, subject, ok, transport, error FROM mail_log ORDER BY id DESC LIMIT ' . MAIL_LOG_KEEP)->fetchAll();
    $me = db()->prepare('SELECT email FROM users WHERE id = ?');
    $me->execute([(int)($_SESSION['uid'] ?? 0)]);
    admin_render('mail', [
        'f' => $f, 'errors' => $errors, 'hasPass' => (bool)($smtp && $smtp['pass'] !== ''),
        'defaultFrom' => $cfg['from'], 'log' => $log, 'lastError' => mail_last_error(),
        'myEmail' => (string)$me->fetchColumn(), 'cfg' => 'correo', 'title' => 'Correo',
    ]);
}
