<?php
declare(strict_types=1);
/*
 * Asistente de instalación. Se abre en  /instalar.php  (o al visitar la web recién subida).
 * Pasos: servidor → base de datos → nueva web o restaurar copia → empresa → opciones → administrador.
 * Cuando termina intenta borrarse solo; si no puede, BÓRRALO por FTP.
 */
if (!defined('ROOT')) {
    // Si se abre directamente por su URL real, carga lo necesario.
    require __DIR__ . '/app/bootstrap.php';
}
require_once ROOT . '/app/backup.php';
start_session();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');

$self = __FILE__;
$installed = is_installed();
// Misma dirección por la que se ha abierto (/instalar.php o /admin/instalar.php; con otra dirección de panel en data/panel.txt,
// /<esa dirección>/instalar.php). Los enlaces al panel pasan por url(), que ya usa la dirección configurada.
$base = (string)strtok((string)($_SERVER['REQUEST_URI'] ?? url('/instalar.php')), '?');
const INST_STEPS = 6;

function inst_page(string $body, int $step = 0): void
{
    $bar = '';
    if ($step > 0) {
        $names = ['Servidor', 'Base de datos', 'Inicio', 'Empresa', 'Opciones', 'Administrador'];
        $bar = '<ol class="inst-steps">';
        foreach ($names as $i => $n) {
            $bar .= '<li' . ($i + 1 === $step ? ' aria-current="step"' : ($i + 1 < $step ? ' class="done"' : '')) . '>' . e($n) . '</li>';
        }
        $bar .= '</ol>';
    }
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex,nofollow"><title>Instalación</title>'
        . '<link rel="stylesheet" href="' . e(asset('admin.css')) . '"></head><body class="bare"><main class="narrow inst"><div class="box">'
        . $bar . $body . '</div></main></body></html>';
}

function inst_errors(array $errors): string
{
    $h = '';
    foreach ($errors as $er) {
        $h .= '<p class="flash err">' . e($er) . '</p>';
    }
    return $h;
}

function inst_go(int $step): never
{
    header('Location: ' . strtok((string)($_SERVER['REQUEST_URI'] ?? url('/instalar.php')), '?') . '?paso=' . $step);
    exit;
}

/* ---- Ya instalada ---- */
if ($installed && empty($_SESSION['inst_done'])) {
    inst_page('<h1>Ya está instalada</h1><p>Esta web ya tiene un administrador, así que el asistente está desactivado.</p>'
        . '<p class="flash err"><strong>Borra el archivo <code>instalar.php</code></strong> de la carpeta de la web (por FTP).</p>'
        . '<p><a class="btn" href="' . e(url('/admin')) . '">Ir al panel</a></p>');
    return;
}

/* ---- Final: intentar borrarse ---- */
if ($installed) {
    $restored = !empty($_SESSION['inst_restored']);
    unset($_SESSION['inst_done'], $_SESSION['inst_restored'], $_SESSION['inst']);
    $gone = @unlink($self);
    inst_page('<h1>' . ($restored ? '¡Copia restaurada!' : '¡Instalación terminada!') . '</h1>'
        . ($restored ? '<p>La web tiene ya los artículos, fotos, textos y usuarios de la copia. Entra con tu usuario y contraseña de siempre.</p>'
            : '<p>Ya puedes crear familias y artículos o traerlos de tu web antigua desde <strong>Importar</strong>.</p>')
        . ($gone
            ? '<p class="flash ok">El asistente se ha borrado solo.</p>'
            : '<p class="flash err"><strong>Borra ahora el archivo <code>instalar.php</code></strong> por FTP: no he podido hacerlo yo.</p>')
        . '<p><a class="btn" href="' . e(url($restored ? '/admin/login' : '/admin')) . '">Entrar al panel</a> <a href="' . e(url('/')) . '" target="_blank" rel="noopener">Ver la web ↗</a></p>');
    return;
}

$step = max(1, min(INST_STEPS, (int)($_GET['paso'] ?? 1)));
$errors = [];
$d = ($_SESSION['inst'] ?? []) + [
    'company_name' => '', 'trade_name' => '', 'tax_id' => '', 'email' => '', 'phone' => '', 'address' => '',
    'country' => 'es', 'brand_color' => '#daaa00', 'logo' => '', 'tagline' => '',
    'phone_prefix' => '34', 'timezone' => 'Europe/Madrid', 'noindex' => '0',
    'mods' => array_keys(FEATURES),
    'fields' => [], 'fields_extra' => '',
];
// No se puede saltar pasos: hay que haber completado el anterior.
if ($step >= 3 && !db_configured()) {
    inst_go(2);
}
if ($step >= 5 && $d['company_name'] === '') {
    inst_go(4);
}

/* ---- 1. Servidor ---- */
if ($step === 1) {
    $checks = [
        ['PHP 8.1 o superior (tienes ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.1.0', '>='), true],
        ['SQLite (pdo_sqlite)', extension_loaded('pdo_sqlite'), true],
        ['Textos (mbstring)', extension_loaded('mbstring'), true],
        ['Imágenes (gd)', extension_loaded('gd'), true],
        ['Imágenes WebP', function_exists('gd_info') && !empty(gd_info()['WebP Support']), false],
        ['ZIP (copias de seguridad e importar fotos)', class_exists('ZipArchive'), false],
        ['cURL (importar desde otra web)', function_exists('curl_init'), false],
        ['Se puede escribir en data/', is_writable(DATA_DIR), true],
        ['Se puede escribir en uploads/', is_writable(UPLOAD_DIR), true],
        ['Tamaño máximo de subida: ' . ini_get('upload_max_filesize'), true, false],
    ];
    $ok = true;
    $html = '<h1>Instalación</h1><p>Comprobación del servidor.</p><ul class="checks">';
    foreach ($checks as [$label, $pass, $req]) {
        if (!$pass && $req) {
            $ok = false;
        }
        $html .= '<li>' . ($pass ? '✅' : ($req ? '❌' : '⚠️')) . ' ' . e($label) . ($pass || $req ? '' : ' <small>(opcional)</small>') . '</li>';
    }
    $html .= '</ul>';
    $html .= $ok ? '<p class="buttons"><a class="btn" href="?paso=2">Continuar</a></p>'
        : '<p class="flash err">Corrige lo marcado con ❌ (versión de PHP en el panel del hosting y permisos 755/775 de las carpetas) y recarga esta página.</p>';
    inst_page($html, 1);
    return;
}

/* ---- 2. Base de datos ---- */
if ($step === 2) {
    $cfg = db_config();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $drv = (string)($_POST['driver'] ?? 'sqlite');
        $drv = isset(DB_ENGINES[$drv]) ? $drv : 'sqlite';
        $cfg = ['driver' => $drv];
        foreach (['host', 'port', 'name', 'user', 'pass'] as $k) {
            $cfg[$k] = trim((string)($_POST[$k] ?? ''));
        }
        if ($drv === 'sqlite') {
            $cfg = ['driver' => 'sqlite'];
        } elseif ($cfg['name'] === '' || $cfg['user'] === '') {
            $errors[] = 'Escribe el nombre de la base de datos y el usuario (los da el panel del hosting).';
        } elseif ($cfg['port'] !== '' && !ctype_digit($cfg['port'])) {
            $errors[] = 'El puerto debe ser un número.';
        }
        if (!$errors) {
            try {
                $pdo = db_connect($cfg);
                $tables = db_tables($pdo);
                // ¿Tablas de otra aplicación con el mismo nombre?
                $conflict = [];
                foreach (['users' => 'pass_hash', 'settings' => 'value', 'products' => 'slug', 'categories' => 'slug'] as $t => $col) {
                    if (in_array($t, $tables, true) && !in_array($col, array_map('strtolower', db_columns($pdo, $t)), true)) {
                        $conflict[] = $t;
                    }
                }
                if ($conflict) {
                    throw new RuntimeException('Esa base de datos ya tiene tablas de otra aplicación con los mismos nombres (' . implode(', ', $conflict) . '). Usa una base de datos vacía.');
                }
                $existing = in_array('users', $tables, true) && (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
                if ($drv !== 'sqlite' && !$existing) {
                    // Prueba de permisos: crear y borrar una tabla
                    $pdo->rawExec('CREATE TABLE cms_prueba (id INT)');
                    $pdo->rawExec('DROP TABLE cms_prueba');
                }
                $pdo = null;
                db_save_config($cfg);
                if ($existing) {
                    // Ya hay una web en esa base de datos: se usa tal cual
                    session_regenerate_id(true);
                    $_SESSION = ['inst_done' => 1, 'inst_restored' => 1];
                    header('Location: ' . $base);
                    exit;
                }
                db(); // crea las tablas
                inst_go(3);
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $errors[] = 'No se puede conectar: ' . (str_contains($msg, 'Access denied') || str_contains($msg, 'Login failed') ? 'usuario o contraseña incorrectos.'
                    : (str_contains($msg, 'Unknown database') || str_contains($msg, 'Cannot open database') ? 'la base de datos «' . $cfg['name'] . '» no existe (créala antes en el panel del hosting).'
                    : (preg_match('/getaddrinfo|No such host|Connection refused|timed out|network/i', $msg) ? 'no se encuentra el servidor «' . $cfg['host'] . '».'
                    : (str_contains($msg, 'command denied') || str_contains($msg, 'permission') ? 'el usuario no tiene permiso para crear tablas.' : mb_substr(preg_replace('/\s+/', ' ', $msg), 0, 200)))));
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
    $opts = '';
    foreach (DB_ENGINES as $k => [$label, $ext]) {
        $ok = db_engine_available($k);
        $help = match ($k) {
            'sqlite' => 'Recomendado. Un archivo dentro de data/: sin servidor, sin contraseñas, y la copia de seguridad es inmediata. Va sobrado para catálogos de miles de artículos.',
            'mysql' => 'Si el hosting la incluye (IONOS, la mayoría) y quieres más rendimiento con mucho tráfico o que otra aplicación lea los mismos datos. Crea antes la base de datos en el panel del hosting.',
            default => 'Para servidores Windows o empresas que ya usan SQL Server. Necesita la extensión pdo_sqlsrv de PHP, que los hostings Linux normalmente no tienen.',
        };
        $opts .= '<label class="check"><input type="radio" name="driver" value="' . e($k) . '"' . ($cfg['driver'] === $k ? ' checked' : '') . ($ok ? '' : ' disabled') . '> <span><b>' . e($label) . '</b>'
            . ($ok ? '' : ' <small class="bad">— no disponible: falta la extensión ' . e($ext) . '</small>') . '<br><small>' . e($help) . '</small></span></label>';
    }
    $h = '<h1>Base de datos</h1><p>Dónde se guardan artículos, familias, textos y usuarios. Las fotos van siempre en la carpeta uploads/.</p>' . inst_errors($errors)
        . '<form method="post" class="form" autocomplete="off">' . csrf_field()
        . '<fieldset><legend>Motor</legend>' . $opts . '</fieldset>'
        . '<fieldset><legend>Solo para MariaDB / MySQL y SQL Server</legend>'
        . '<div class="two"><label>Servidor <input name="host" value="' . e($cfg['host']) . '" placeholder="localhost o db5001234567.hosting-data.io"></label>'
        . '<label>Puerto <input name="port" value="' . e($cfg['port']) . '" inputmode="numeric" placeholder="3306 / 1433 (vacío = el normal)"></label></div>'
        . '<label>Nombre de la base de datos <input name="name" value="' . e($cfg['name']) . '"></label>'
        . '<div class="two"><label>Usuario <input name="user" value="' . e($cfg['user']) . '" autocomplete="off"></label>'
        . '<label>Contraseña <input type="password" name="pass" value="" autocomplete="new-password"></label></div>'
        . '<small>Los datos los da el panel del hosting al crear la base de datos. Usa una base de datos vacía solo para esta web. Si ya tiene una web de este CMS instalada, se usará tal cual.</small></fieldset>'
        . '<p class="buttons"><a href="?paso=1">← Atrás</a> <button class="btn" type="submit">Probar y continuar</button></p></form>';
    inst_page($h, 2);
    return;
}

/* ---- 3. Nueva web o restaurar una copia ---- */
if ($step === 3) {
    $ftpZip = backup_ftp_zip();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $errors[] = 'La copia pesa más de lo que admite el servidor (' . ini_get('post_max_size') . '). Súbela por FTP a la carpeta data/restaurar/ y recarga esta página.';
        } else {
            csrf_check();
            if (($_POST['mode'] ?? '') === 'nueva') {
                inst_go(4);
            }
            $f = $_FILES['backup'] ?? null;
            $path = ($f && ($f['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$f['tmp_name'])) ? (string)$f['tmp_name'] : $ftpZip;
            if (!$path) {
                $errors[] = 'Elige el ZIP de la copia de seguridad.';
            } else {
                try {
                    @set_time_limit(300);
                    backup_restore($path);
                    if ($path === $ftpZip) {
                        @unlink($ftpZip);
                    }
                    session_regenerate_id(true);
                    $_SESSION = ['inst_done' => 1, 'inst_restored' => 1];
                    header('Location: ' . $base);
                    exit;
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
    $h = '<h1>¿Cómo empiezas?</h1>' . inst_errors($errors)
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="mode" value="nueva">'
        . '<div class="choice"><h2>Web nueva</h2><p>Para una empresa nueva: escribes sus datos, subes su logotipo y la web empieza vacía. Después puedes importar el catálogo desde WordPress o una hoja de cálculo.</p>'
        . '<button class="btn" type="submit">Empezar una web nueva</button></div></form>'
        . '<form method="post" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="mode" value="restaurar">'
        . '<div class="choice"><h2>Restaurar una copia de seguridad</h2><p>Para trasladar una web ya hecha a este servidor: sube el ZIP de <em>Web → Empresa → Descargar copia de seguridad</em>. Se recuperan artículos, fotos, textos y usuarios.</p>'
        . ($ftpZip ? '<p class="flash ok">Hay una copia subida por FTP: <code>' . e(basename($ftpZip)) . '</code>. Se usará si no eliges otra.</p>' : '')
        . '<label>Copia de seguridad (ZIP) <input type="file" name="backup" accept=".zip,application/zip"></label>'
        . '<small>Si pesa más de ' . e(ini_get('upload_max_filesize')) . ', súbela por FTP a <code>data/restaurar/</code> y recarga esta página.</small>'
        . '<p class="buttons"><button class="btn ghost" type="submit">Restaurar la copia</button></p></div></form>'
        . '<p class="buttons"><a href="?paso=2">← Atrás</a></p>';
    inst_page($h, 3);
    return;
}

/* ---- 4. Empresa ---- */
if ($step === 4) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $errors[] = 'El logotipo pesa demasiado para el servidor.';
        } else {
            csrf_check();
            foreach (['company_name', 'trade_name', 'tax_id', 'email', 'phone', 'address', 'tagline'] as $k) {
                $d[$k] = trim((string)($_POST[$k] ?? ''));
            }
            $c = (string)($_POST['brand_color'] ?? '');
            $d['brand_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? $c : '#daaa00';
            $country = (string)($_POST['country'] ?? 'es');
            if (isset(COUNTRIES[$country]) && $country !== $d['country']) {
                [, $d['phone_prefix'], $d['timezone']] = COUNTRIES[$country];
            }
            $d['country'] = isset(COUNTRIES[$country]) ? $country : 'otro';
            if ($d['company_name'] === '') {
                $errors[] = 'Escribe el nombre de la empresa.';
            }
            if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'El correo no es válido.';
            }
            if (!empty($_FILES['logo']['name']) && ($_FILES['logo']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                try {
                    $new = save_image($_FILES['logo'], 1200, false);
                    if ($d['logo'] !== '') {
                        delete_image($d['logo']);
                    }
                    $d['logo'] = $new;
                } catch (RuntimeException $e) {
                    $errors[] = 'Logotipo: ' . $e->getMessage();
                }
            }
            $_SESSION['inst'] = $d;
            if (!$errors) {
                inst_go(5);
            }
        }
    }
    $opt = '';
    foreach (COUNTRIES as $k => [$n]) {
        $opt .= '<option value="' . e($k) . '"' . ($d['country'] === $k ? ' selected' : '') . '>' . e($n) . '</option>';
    }
    $opt .= '<option value="otro"' . ($d['country'] === 'otro' ? ' selected' : '') . '>Otro (lo ajustas en el paso siguiente)</option>';
    $h = '<h1>Datos de la empresa</h1><p>Todo se puede cambiar después en <em>Web → Empresa</em>.</p>' . inst_errors($errors)
        . '<form method="post" enctype="multipart/form-data" class="form">' . csrf_field()
        . '<label>Razón social * <input name="company_name" value="' . e($d['company_name']) . '" required placeholder="Ej.: Muebles García S.L."></label>'
        . '<label>Nombre comercial <input name="trade_name" value="' . e($d['trade_name']) . '" placeholder="El que sale en la cabecera (si es distinto)"></label>'
        . '<label>Lema <input name="tagline" value="' . e($d['tagline']) . '" placeholder="Frase corta: «Fabricantes desde 1960»"></label>'
        . '<div class="two"><label>CIF / NIF <input name="tax_id" value="' . e($d['tax_id']) . '"></label>'
        . '<label>País <select name="country">' . $opt . '</select><small>Para el prefijo de teléfono y la hora.</small></label></div>'
        . '<div class="two"><label>Teléfono <input name="phone" value="' . e($d['phone']) . '"></label>'
        . '<label>Correo <input type="email" name="email" value="' . e($d['email']) . '"></label></div>'
        . '<label>Dirección <textarea name="address" rows="2">' . e($d['address']) . '</textarea></label>'
        . '<div class="two"><fieldset><legend>Logotipo</legend>'
        . ($d['logo'] !== '' ? '<img src="' . e(img_url($d['logo'])) . '" alt="" style="max-width:180px;max-height:90px;object-fit:contain;display:block;margin-bottom:6px">' : '')
        . '<input type="file" name="logo" accept="image/png,image/jpeg,image/webp"><small>PNG con fondo transparente, mejor.</small></fieldset>'
        . '<label>Color de marca <input type="color" name="brand_color" value="' . e($d['brand_color']) . '" style="height:44px;padding:4px"><small>Detalles, enlaces y líneas de la web.</small></label></div>'
        . '<p class="buttons"><a href="?paso=3">← Atrás</a> <button class="btn" type="submit">Continuar</button></p></form>';
    inst_page($h, 4);
    return;
}

/* ---- 5. Opciones ---- */
if ($step === 5) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $d['mods'] = array_values(array_intersect(array_keys(FEATURES), (array)($_POST['mods'] ?? [])));
        $d['phone_prefix'] = preg_replace('/\D+/', '', (string)($_POST['phone_prefix'] ?? ''));
        $tz = (string)($_POST['timezone'] ?? '');
        $d['timezone'] = in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Europe/Madrid';
        $d['noindex'] = empty($_POST['noindex']) ? '0' : '1';
        $d['fields'] = array_values(array_intersect(array_keys(FIELD_PRESETS), (array)($_POST['fields'] ?? [])));
        $d['fields_extra'] = mb_substr(trim((string)($_POST['fields_extra'] ?? '')), 0, 2000);
        $_SESSION['inst'] = $d;
        inst_go(6);
    }
    $mods = '';
    foreach (FEATURES as $k => [$n, $help]) {
        $mods .= '<label class="check"><input type="checkbox" name="mods[]" value="' . e($k) . '"' . (in_array($k, $d['mods'], true) ? ' checked' : '') . '> <span><b>' . e($n) . '</b> — ' . e($help) . '</span></label>';
    }
    $fieldsHtml = '';
    foreach (FIELD_PRESETS as $k => [$n, $kind, $unit, $pub]) {
        $fieldsHtml .= '<label class="check"><input type="checkbox" name="fields[]" value="' . e($k) . '"' . (in_array($k, $d['fields'], true) ? ' checked' : '') . '> <span>' . e($n)
            . ' <small>(' . e(mb_strtolower(FIELD_KINDS[$kind][0])) . ($unit !== '' ? ', ' . e($unit) : '') . ($pub ? '' : ', interno') . ')</small></span></label>';
    }
    $tzs = '';
    foreach (timezone_identifiers_list() as $tz) {
        $tzs .= '<option' . ($tz === $d['timezone'] ? ' selected' : '') . '>' . e($tz) . '</option>';
    }
    $h = '<h1>Opciones de la web</h1><p>Elige qué partes quieres usar. Se pueden cambiar cuando quieras en <em>Web → Configuración</em>.</p>'
        . '<form method="post" class="form">' . csrf_field()
        . '<fieldset><legend>Módulos</legend>' . $mods . '</fieldset>'
        . '<div class="two"><label>Prefijo telefónico del país <input name="phone_prefix" value="' . e($d['phone_prefix']) . '" inputmode="numeric" maxlength="4"><small>Se añade a los teléfonos para llamar desde el móvil. Vacío = sin prefijo.</small></label>'
        . '<label>Zona horaria <select name="timezone">' . $tzs . '</select></label></div>'
        . '<fieldset><legend>Campos del catálogo</legend><small>Además de nombre, referencia, medidas, descripción y fotos. Los «internos» no se ven en la web. Se pueden cambiar después en <em>Web → Campos</em>.</small><div class="fchips">' . $fieldsHtml . '</div>'
        . '<label>Otros campos (uno por línea) <textarea name="fields_extra" rows="3" placeholder="Composición&#10;País de origen&#10;Unidades por caja">' . e($d['fields_extra']) . '</textarea><small>Se crean como texto visible en la web; el tipo se cambia luego en Web → Campos.</small></label></fieldset>'
        . '<label class="check"><input type="checkbox" name="noindex" value="1"' . ($d['noindex'] === '1' ? ' checked' : '') . '> <span><b>Ocultar a Google por ahora</b> — recomendable mientras la pruebas en un subdominio. Desactívalo al publicarla.</span></label>'
        . '<p class="buttons"><a href="?paso=4">← Atrás</a> <button class="btn" type="submit">Continuar</button></p></form>';
    inst_page($h, 5);
    return;
}

/* ---- 6. Administrador e instalación ---- */
$username = '';
$email = $d['email'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (mb_strlen($username) < 3) {
        $errors[] = 'El usuario debe tener al menos 3 caracteres.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El email no es válido.';
    }
    if (mb_strlen($pass) < 10) {
        $errors[] = 'La contraseña debe tener al menos 10 caracteres.';
    }
    if ($pass !== (string)($_POST['password2'] ?? '')) {
        $errors[] = 'Las contraseñas no coinciden.';
    }
    if (!$errors && is_installed()) {
        $errors[] = 'Otra persona acaba de terminar la instalación. Recarga la página.';
    }
    if (!$errors) {
        $db = db();
        $db->beginTransaction();
        $db->prepare("INSERT INTO users (username, pass_hash, role, email) VALUES (?, ?, 'admin', ?)")
            ->execute([$username, password_hash($pass, PASSWORD_DEFAULT), $email]);
        $uid = (int)$db->lastInsertId();
        $set = [
            'company_name' => $d['company_name'], 'trade_name' => $d['trade_name'] !== '' ? $d['trade_name'] : $d['company_name'],
            'tax_id' => $d['tax_id'], 'email' => $d['email'], 'phone' => $d['phone'], 'address' => $d['address'], 'tagline' => $d['tagline'],
            'brand_color' => $d['brand_color'], 'logo' => $d['logo'], 'country' => $d['country'],
            'phone_prefix' => $d['phone_prefix'], 'timezone' => $d['timezone'], 'noindex' => $d['noindex'],
            'home_desc' => mb_substr(($d['trade_name'] ?: $d['company_name']) . ($d['tagline'] !== '' ? '. ' . $d['tagline'] : ''), 0, 160),
        ];
        foreach (FEATURES as $k => $_) {
            $set['mod_' . $k] = in_array($k, $d['mods'], true) ? '1' : '0';
        }
        foreach ($set as $k => $v) {
            save_setting($k, (string)$v);
        }
        foreach ($d['fields'] as $slug) {
            [$fn, $fk, $fu, $fp, $fc] = FIELD_PRESETS[$slug];
            try {
                field_create($fn, $fk, $fu, $fp, $fc);
            } catch (RuntimeException $e) {
            }
        }
        foreach (preg_split('/\R/', $d['fields_extra']) as $line) {
            if (trim($line) !== '') {
                try {
                    field_create(trim($line));
                } catch (RuntimeException $e) {
                }
            }
        }
        $db->commit();
        session_regenerate_id(true);
        $_SESSION = ['uid' => $uid, 'inst_done' => 1];
        header('Location: ' . $base);
        exit;
    }
}
$h = '<h1>Cuenta de administrador</h1><p>Con ella entrarás al panel (<code>' . e(url('/admin')) . '</code>). Después podrás crear más usuarios.</p>' . inst_errors($errors)
    . '<form method="post" autocomplete="off" class="form">' . csrf_field()
    . '<label>Usuario <input name="username" value="' . e($username) . '" required minlength="3" autocomplete="username"></label>'
    . '<label>Email (para recuperar la contraseña) <input type="email" name="email" value="' . e($email) . '" autocomplete="email"></label>'
    . '<label>Contraseña (mínimo 10 caracteres) <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>'
    . '<label>Repite la contraseña <input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>'
    . '<p class="buttons"><a href="?paso=5">← Atrás</a> <button class="btn" type="submit">Instalar</button></p></form>';
inst_page($h, 6);
