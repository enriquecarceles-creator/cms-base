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

// El envío de correo (app_mail(), mail_send(), SMTP, registro) está en app/mail.php; todos los correos pasan por allí.
require_once __DIR__ . '/mail.php';

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

/* ---------- Doble verificación (TOTP, RFC 6238) ----------
 * Opcional por usuario. Tras la contraseña se pide un código de 6 dígitos de una app (Google Authenticator, Aegis…)
 * o uno de los 10 códigos de recuperación (un solo uso). Sin librerías: HMAC-SHA1 con hash_hmac y base32 propio.
 * La clave va en users.totp_secret (hace falta en claro para calcular el código) y NO viaja en las copias de seguridad
 * (backup_make() la quita de la copia). Los códigos de recuperación se guardan solo como huella (sha256).
 */

const TOTP_DIGITS = 6;
const TOTP_PERIOD = 30;
const TOTP_PENDING_SECS = 300;   // tiempo para escribir el código tras acertar la contraseña
const TOTP_RECOVERY_COUNT = 10;
const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

/** Devuelve null si el texto no es base32 válido. Admite minúsculas, espacios y guiones. */
function base32_decode(string $txt): ?string
{
    $txt = strtoupper(preg_replace('/[\s-]+/', '', $txt) ?? '');
    $txt = rtrim($txt, '=');
    if ($txt === '' || strspn($txt, BASE32_ALPHABET) !== strlen($txt)) {
        return null;
    }
    $bits = '';
    foreach (str_split($txt) as $ch) {
        $bits .= str_pad(decbin((int)strpos(BASE32_ALPHABET, $ch)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** Clave nueva: 20 bytes aleatorios en base32 (32 letras). */
function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

/** Código de 6 dígitos de un periodo de 30 s (RFC 4226/6238, HMAC-SHA1). */
function totp_code(string $secretB32, int $step): string
{
    $key = base32_decode($secretB32) ?? '';
    $h = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xFFFFFFFF), $key, true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % (10 ** TOTP_DIGITS)), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * ¿Es válido el código? Acepta el periodo anterior, el actual y el siguiente (reloj del móvil algo desfasado) y
 * solo periodos posteriores a $lastStep (un código no vale dos veces). Devuelve el periodo acertado o null.
 */
function totp_check(string $secretB32, string $input, int $lastStep = 0, ?int $now = null): ?int
{
    $input = preg_replace('/\D+/', '', $input) ?? '';
    if (strlen($input) !== TOTP_DIGITS || base32_decode($secretB32) === null) {
        return null;
    }
    $cur = intdiv($now ?? time(), TOTP_PERIOD);
    $found = null;
    foreach ([$cur - 1, $cur, $cur + 1] as $step) { // se recorren los tres siempre
        if (hash_equals(totp_code($secretB32, $step), $input) && $step > $lastStep) {
            $found = $step;
        }
    }
    return $found;
}

/** Enlace que abren las apps de autenticación (otpauth://). */
function totp_uri(string $secretB32, string $account): string
{
    $issuer = str_replace(["\r", "\n"], ' ', site_name());
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}

/** Clave en grupos de 4 para leerla y teclearla: «ABCD EFGH …». */
function totp_pretty(string $secretB32): string
{
    return trim(chunk_split($secretB32, 4, ' '));
}

/** 10 códigos de recuperación «xxxxx-xxxxx» (sin letras confusas). */
function auth_new_recovery_codes(): array
{
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out = [];
    while (count($out) < TOTP_RECOVERY_COUNT) {
        $c = '';
        for ($i = 0; $i < 10; $i++) {
            $c .= $alpha[random_int(0, strlen($alpha) - 1)];
        }
        $out[$c] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    return array_values($out);
}

function auth_recovery_hash(string $code): string
{
    return hash('sha256', preg_replace('/[^a-z0-9]/', '', strtolower($code)) ?? '');
}

/** Guarda (en lugar de los anteriores) las huellas de unos códigos de recuperación nuevos. */
function auth_save_recovery_codes(int $uid, array $codes): void
{
    $db = db();
    $db->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$uid]);
    $ins = $db->prepare('INSERT INTO user_recovery_codes (user_id, code_hash, used_at) VALUES (?,?,0)');
    foreach ($codes as $c) {
        $ins->execute([$uid, auth_recovery_hash($c)]);
    }
}

function auth_recovery_left(int $uid): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at = 0');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** Gasta un código de recuperación si existe y no se usó. Devuelve si valía. */
function auth_use_recovery_code(int $uid, string $input): bool
{
    $norm = preg_replace('/[^a-z0-9]/', '', strtolower($input)) ?? '';
    if (strlen($norm) !== 10) {
        return false;
    }
    $st = db()->prepare('UPDATE user_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at = 0');
    $st->execute([time(), $uid, auth_recovery_hash($norm)]);
    return $st->rowCount() === 1;
}

/**
 * Comprueba lo escrito en el segundo paso: código de la app (6 dígitos) o de recuperación. Sin efectos si falla.
 * Con la app, guarda el periodo usado de forma atómica (dos peticiones con el mismo código no pasan las dos).
 */
function auth_verify_second_factor(array $user, string $input): bool
{
    $input = trim($input);
    if (preg_match('/^\d[\d\s]*$/', $input)) {
        $step = totp_check((string)$user['totp_secret'], $input, (int)$user['totp_last_step']);
        if ($step === null) {
            return false;
        }
        $st = db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?');
        $st->execute([$step, (int)$user['id'], $step]);
        return $st->rowCount() === 1;
    }
    return auth_use_recovery_code((int)$user['id'], $input);
}

/** Quita la doble verificación de un usuario (clave y códigos de recuperación). */
function auth_totp_disable(int $uid): void
{
    $db = db();
    $db->prepare("UPDATE users SET totp_secret = '', totp_enabled = 0, totp_last_step = 0 WHERE id = ?")->execute([$uid]);
    $db->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$uid]);
}

/**
 * Recuperación por FTP: si existe data/sin-2fa.txt (un nombre de usuario por línea) se quita la doble verificación de
 * esos usuarios y el archivo se borra. Si no se puede borrar, no se hace nada (no quedaría desactivada para siempre).
 */
function auth_ftp_disable_totp(): void
{
    $f = DATA_DIR . '/sin-2fa.txt';
    if (!is_file($f)) {
        return;
    }
    $names = array_values(array_filter(array_map('trim', preg_split('/\R/', (string)@file_get_contents($f)) ?: []), 'strlen'));
    if (!@unlink($f) || is_file($f)) {
        return;
    }
    $st = db()->prepare('SELECT id FROM users WHERE username = ?');
    foreach (array_slice($names, 0, 50) as $n) {
        $st->execute([$n]);
        if ($id = $st->fetchColumn()) {
            auth_totp_disable((int)$id);
            auth_unlock((int)$id);
        }
    }
}

/** Quita de un archivo SQLite de copia de seguridad las claves de doble verificación, los códigos de recuperación y los registros de correo y de descargas. */
function auth_scrub_backup(string $sqliteFile, bool $withErp = false): void
{
    $pdo = db_connect(['driver' => 'sqlite', 'file' => $sqliteFile]);
    if (in_array('totp_secret', db_columns($pdo, 'users'), true)) {
        $pdo->exec("UPDATE users SET totp_secret = '', totp_enabled = 0, totp_last_step = 0");
    }
    if (in_array('user_recovery_codes', db_tables($pdo), true)) {
        $pdo->exec('DELETE FROM user_recovery_codes');
    }
    foreach (['mail_log', 'backup_log'] as $t) { // registro de correos (direcciones) e historial de descargas: nunca en la copia
        if (in_array($t, db_tables($pdo), true)) {
            $pdo->exec('DELETE FROM ' . $t);
        }
    }
    if (!$withErp) { // clientes y ventas (datos personales) no van en la copia salvo que se pida
        foreach (array_reverse(ERP_TABLES) as $t) {
            if (in_array($t, db_tables($pdo), true)) {
                $pdo->exec('DELETE FROM ' . $t);
            }
        }
    }
    // DELETE deja el contenido en las páginas libres del archivo SQLite; VACUUM lo reescribe sin ellas (si no, los datos borrados seguirían dentro de la copia)
    $pdo->exec('VACUUM');
}
