<?php
declare(strict_types=1);

/*
 * Seguridad de acceso al panel (primer paso de la separación de helpers que describe CLAUDE.md §7):
 *
 * - Bloqueo por usuario: 5 contraseñas incorrectas seguidas → 15 minutos bloqueado. Siempre temporal; un admin
 *   puede desbloquear desde Usuarios y un restablecimiento de contraseña también desbloquea. Los fallos caducan
 *   a los 15 minutos. Es ADEMÁS del bloqueo por IP de admin_login().
 * - Sitios conocidos: cada fila de login_devices es un «sitio» = red de la IP + navegador (máximo 10 por usuario,
 *   los más recientes). Si entras desde una red o un navegador que no están en ninguna fila, se avisa por correo.
 *   La IP se compara por red (/24 en IPv4, /48 en IPv6) y el navegador sin número de versión: con IPs dinámicas y
 *   navegadores que se actualizan solos, comparar exacto avisaría casi a diario y los avisos acabarían ignorados.
 *   El primer acceso de un usuario sin sitios registrados (p. ej. justo tras actualizar) se registra sin avisar.
 * - Nada de esto debe impedir entrar: el envío del correo va protegido y un usuario sin correo no da error.
 */

const AUTH_MAX_FAILS = 5;
const AUTH_LOCK_SECS = 900;
const AUTH_KEEP_SITES = 10;

/** Un solo mensaje para usuario inexistente, contraseña incorrecta y usuario bloqueado: no revela si el usuario existe. */
const AUTH_GENERIC_ERROR = 'Usuario o contraseña incorrectos. Si fallas varias veces seguidas, el acceso se bloquea unos minutos.';

/**
 * Envía un correo de texto (UTF-8) desde noreply@<dominio>. Para pruebas, si existe la variable de entorno
 * CMS_MAIL_LOG el correo se añade a ese archivo en vez de enviarse (como CMS_SQL_LOG con las consultas).
 */
function app_mail(string $to, string $subject, string $body): bool
{
    $host = preg_replace('/^www\./', '', (string)parse_url(site_origin(), PHP_URL_HOST));
    $from = str_replace(["\r", "\n"], ' ', site_name()) . ' <noreply@' . ($host ?: 'localhost') . '>';
    $log = (string)getenv('CMS_MAIL_LOG');
    if ($log !== '') {
        return @file_put_contents($log, "TO: $to\nFROM: $from\nSUBJECT: $subject\n\n$body\n=====\n", FILE_APPEND) !== false;
    }
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "From: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n");
}

/* ---------- Bloqueo por usuario ---------- */

function auth_locked(array $user): bool
{
    return (int)($user['locked_until'] ?? 0) > time();
}

/** Anota una contraseña incorrecta de un usuario que existe. Al llegar a AUTH_MAX_FAILS lo bloquea AUTH_LOCK_SECS. */
function auth_register_failure(int $uid): void
{
    $db = db();
    $now = time();
    $st = $db->prepare('SELECT failed_attempts, last_failed_at FROM users WHERE id = ?');
    $st->execute([$uid]);
    $r = $st->fetch();
    if (!$r) {
        return;
    }
    $n = ($now - (int)$r['last_failed_at'] > AUTH_LOCK_SECS ? 0 : (int)$r['failed_attempts']) + 1; // los fallos antiguos no suman
    if ($n >= AUTH_MAX_FAILS) {
        $db->prepare('UPDATE users SET failed_attempts = 0, last_failed_at = ?, locked_until = ? WHERE id = ?')->execute([$now, $now + AUTH_LOCK_SECS, $uid]);
    } else {
        $db->prepare('UPDATE users SET failed_attempts = ?, last_failed_at = ? WHERE id = ?')->execute([$n, $now, $uid]);
    }
}

/** Entrada correcta: limpia los fallos y anota el último acceso. */
function auth_register_success(int $uid): void
{
    db()->prepare('UPDATE users SET failed_attempts = 0, last_failed_at = 0, locked_until = 0, last_login_at = ? WHERE id = ?')->execute([time(), $uid]);
}

/** Quita el bloqueo y los fallos (botón de Usuarios, cambio de contraseña, restablecimiento por correo). */
function auth_unlock(int $uid): void
{
    db()->prepare('UPDATE users SET failed_attempts = 0, last_failed_at = 0, locked_until = 0 WHERE id = ?')->execute([$uid]);
}

/* ---------- Sitios conocidos ---------- */

/** Red de una IP: «88.12.34.0/24» (IPv4) o «2a01:4f8:1c0::/48» (IPv6). Vacío si no es una IP. */
function auth_ip_net(string $ip): string
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return '';
    }
    if (strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10) . "\xff\xff") { // IPv4 escrita como IPv6
        $bin = substr($bin, 12);
    }
    if (strlen($bin) === 4) {
        return ord($bin[0]) . '.' . ord($bin[1]) . '.' . ord($bin[2]) . '.0/24';
    }
    return strlen($bin) === 16 ? inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10)) . '/48' : '';
}

/** Navegador y sistema: [texto para comparar sin versión, texto para mostrar con versión]. */
function auth_browser(string $ua): array
{
    $ua = mb_substr($ua, 0, 400);
    $os = 'otro sistema';
    foreach (['Windows' => '/Windows/', 'Android' => '/Android/', 'iPhone o iPad' => '/iPhone|iPad|iPod/', 'macOS' => '/Macintosh|Mac OS X/', 'ChromeOS' => '/CrOS/', 'Linux' => '/Linux|X11/'] as $name => $re) {
        if (preg_match($re, $ua)) {
            $os = $name;
            break;
        }
    }
    $name = 'Navegador desconocido';
    $ver = '';
    foreach (['Edge' => '/Edg(?:e|A|iOS)?\/(\d+)/', 'Opera' => '/(?:OPR|Opera)\/(\d+)/', 'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/', 'Chrome' => '/(?:Chrome|CriOS)\/(\d+)/',
        'Safari' => '/Version\/(\d+)[^ ]* (?:Mobile\/\S+ )?Safari/', 'curl' => '/^curl\/(\d+)/'] as $n => $re) {
        if (preg_match($re, $ua, $m)) {
            $name = $n;
            $ver = $m[1];
            break;
        }
    }
    return [$name . ' en ' . $os, $name . ($ver !== '' ? ' ' . $ver : '') . ' en ' . $os];
}

/** El sitio desde el que se hace esta petición. */
function auth_current_site(): array
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    [$cmp, $text] = auth_browser((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return ['ip' => $ip, 'ip_net' => auth_ip_net($ip), 'browser' => $cmp, 'browser_text' => $text];
}

/**
 * Registra el sitio actual entre los conocidos del usuario (máximo AUTH_KEEP_SITES, los más recientes).
 * Devuelve null si no hay que avisar, o ['new_ip' => bool, 'new_browser' => bool] si la red o el navegador son nuevos.
 */
function auth_register_site(int $uid): ?array
{
    $db = db();
    $s = auth_current_site();
    $now = time();
    $st = $db->prepare('SELECT id, ip_net, browser FROM login_devices WHERE user_id = ?');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    $hit = null;
    foreach ($rows as $r) {
        if ($r['ip_net'] === $s['ip_net'] && $r['browser'] === $s['browser']) {
            $hit = (int)$r['id'];
        }
    }
    $newIp = $rows && !in_array($s['ip_net'], array_column($rows, 'ip_net'), true);
    $newBr = $rows && !in_array($s['browser'], array_column($rows, 'browser'), true);
    if ($hit !== null) {
        $db->prepare('UPDATE login_devices SET last_seen = ? WHERE id = ?')->execute([$now, $hit]);
    } else {
        $db->prepare('INSERT INTO login_devices (user_id, ip_net, browser, first_seen, last_seen) VALUES (?,?,?,?,?)')
            ->execute([$uid, $s['ip_net'], $s['browser'], $now, $now]);
        $all = $db->prepare('SELECT id FROM login_devices WHERE user_id = ? ORDER BY last_seen DESC, id DESC');
        $all->execute([$uid]);
        $del = $db->prepare('DELETE FROM login_devices WHERE id = ?');
        foreach (array_slice($all->fetchAll(PDO::FETCH_COLUMN), AUTH_KEEP_SITES) as $old) {
            $del->execute([(int)$old]);
        }
    }
    return ($newIp || $newBr) ? ['new_ip' => (bool)$newIp, 'new_browser' => (bool)$newBr] : null;
}

/** Sitios conocidos de un usuario, el más reciente primero. */
function auth_sites(int $uid): array
{
    $st = db()->prepare('SELECT ip_net, browser, first_seen, last_seen FROM login_devices WHERE user_id = ? ORDER BY last_seen DESC, id DESC');
    $st->execute([$uid]);
    return $st->fetchAll();
}

/**
 * «Olvidar sitios conocidos»: borra todos y deja solo el actual. Así, si pierdes un móvil u ordenador, volver a
 * entrar desde ahí avisará por correo (si se dejara la lista vacía, el siguiente acceso, de quien fuera, se
 * registraría sin avisar). Devuelve cuántos sitios había.
 */
function auth_forget_sites(int $uid): int
{
    $n = count(auth_sites($uid));
    db()->prepare('DELETE FROM login_devices WHERE user_id = ?')->execute([$uid]);
    $s = auth_current_site();
    $now = time();
    db()->prepare('INSERT INTO login_devices (user_id, ip_net, browser, first_seen, last_seen) VALUES (?,?,?,?,?)')->execute([$uid, $s['ip_net'], $s['browser'], $now, $now]);
    return $n;
}

/** Correo de «acceso desde un sitio nuevo». false si el usuario no tiene correo válido o no se pudo enviar (nunca lanza error). */
function auth_alert_new_site(array $user, array $why): bool
{
    try {
        $to = trim((string)($user['email'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $s = auth_current_site();
        $motivo = $why['new_ip'] && $why['new_browser'] ? 'dirección IP y navegador nuevos' : ($why['new_ip'] ? 'dirección IP nueva' : 'navegador nuevo');
        $body = "Hola " . $user['username'] . ",\r\n\r\n"
            . "Se ha iniciado sesión en tu cuenta del panel de " . site_name() . " desde un sitio que no habíamos visto antes:\r\n\r\n"
            . "  Cuándo:        " . date('d/m/Y H:i') . ' (' . date_default_timezone_get() . ")\r\n"
            . "  Dirección IP:  " . $s['ip'] . "\r\n"
            . "  Navegador:     " . $s['browser_text'] . "\r\n"
            . "  Motivo:        " . $motivo . "\r\n\r\n"
            . "Si has sido tú, no tienes que hacer nada.\r\n\r\n"
            . "Si NO has sido tú:\r\n"
            . "  1. Cambia tu contraseña ahora mismo: " . site_origin() . url('/admin/clave') . "\r\n"
            . "  2. Avisa a un administrador de la web.\r\n";
        return app_mail($to, 'Nuevo acceso a tu cuenta · Panel ' . site_name(), $body);
    } catch (Throwable $e) {
        return false;
    }
}

/** Tras una entrada correcta: limpia fallos, anota el acceso, registra el sitio y avisa si es nuevo. Nunca impide entrar. */
function auth_after_login(array $user): void
{
    try {
        auth_register_success((int)$user['id']);
        $why = auth_register_site((int)$user['id']);
        if ($why) {
            auth_alert_new_site($user, $why);
        }
    } catch (Throwable $e) {
        error_log((string)$e);
    }
}
