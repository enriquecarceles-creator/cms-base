<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');
define('UPLOAD_DIR', ROOT . '/uploads');
require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Madrid');

// Carpeta de instalación respecto a la raíz web ('' en la raíz del dominio, '/cms' en una subcarpeta).
// Se saca de la dirección del script (index.php o instalar.php están siempre en la carpeta de la web);
// si no se puede, de DOCUMENT_ROOT. Así funciona también en hostings con rutas enlazadas (IONOS…).
$__script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
if ($__script !== '' && in_array(basename($__script), ['index.php', 'instalar.php', 'reset-clave.php'], true)) {
    $__base = dirname($__script);
} else {
    $__doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $__root = realpath(ROOT) ?: ROOT;
    $__base = ($__doc !== '' && str_starts_with($__root, $__doc)) ? substr($__root, strlen($__doc)) : '';
}
define('BASE', rtrim(str_replace('\\', '/', $__base), '/.'));
unset($__script, $__doc, $__root, $__base);

/* ---------- Utilidades ---------- */

function e(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return BASE . $path;
}

function asset(string $file): string
{
    $v = @filemtime(ROOT . '/assets/' . $file) ?: 1;
    return BASE . '/assets/' . $file . '?v=' . $v;
}

function thumb_name(string $file): string
{
    return preg_replace('/(\.\w+)$/', '-t$1', $file);
}

function img_url(?string $file, bool $thumb = false): ?string
{
    if (!$file) {
        return null;
    }
    return BASE . '/uploads/' . ($thumb ? thumb_name($file) : $file);
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function site_origin(): string
{
    return (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function slugify(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
    ]);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    return $s !== '' ? $s : 'articulo';
}

function unique_slug(string $table, string $base, int $ignoreId = 0): string
{
    $base = rtrim(substr($base, 0, 170), '-'); // cabe en VARCHAR(191) con el sufijo -2, -3…
    $slug = $base;
    $i = 2;
    $st = db()->prepare("SELECT COUNT(*) FROM $table WHERE slug = ? AND id <> ?");
    while (true) {
        $st->execute([$slug, $ignoreId]);
        if ((int)$st->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '-' . $i++;
    }
}

/* ---------- Base de datos ---------- */

function db(): CmsPdo
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    require_once __DIR__ . '/schema.php';
    $cfg = db_config();
    $fresh = $cfg['driver'] === 'sqlite' && (!is_file(DB_SQLITE_FILE) || filesize(DB_SQLITE_FILE) === 0);
    $pdo = db_connect($cfg);
    if ($fresh || ($cfg['driver'] !== 'sqlite' && !in_array('settings', db_tables($pdo), true))) {
        install_schema($pdo);
    } elseif (db_schema_version($pdo) < SCHEMA_VERSION) {
        migrate_schema($pdo);
    }
    $tz = (string)$pdo->query("SELECT value FROM settings WHERE key = 'timezone'")->fetchColumn();
    if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
        date_default_timezone_set($tz);
    }
    return $pdo;
}

function setting(string $key, string $default = ''): string
{
    static $all = null;
    if ($all === null) {
        $all = db()->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return (isset($all[$key]) && $all[$key] !== '') ? $all[$key] : $default;
}

/* ---------- Nombres de la jerarquía del catálogo (Web → Configuración) ---------- */

/** Nombres por defecto: clave => valor (se guardan en settings como term_<clave>; el género en term_gender: f/m). */
const TERM_DEFAULTS = [
    'family' => 'Familia',
    'families' => 'Familias',
    'sub' => 'Subfamilia',
    'subs' => 'Subfamilias',
];

/**
 * Nombre visible de un nivel del catálogo: term('family') → «Familia»; term('families', true) → «familias»
 * (en mitad de una frase solo se pasa a minúscula la primera letra). Solo para textos que se ven:
 * las direcciones (/catalogo/…) y los nombres internos (categories, family…) no cambian.
 */
function term(string $k, bool $lower = false): string
{
    $v = setting('term_' . $k, TERM_DEFAULTS[$k] ?? $k);
    return $lower ? mb_strtolower(mb_substr($v, 0, 1)) . mb_substr($v, 1) : $v;
}

/** Palabra según el género de esos nombres: tg('una', 'un'), tg('creada', 'creado')… (femenino por defecto). */
function tg(string $fem, string $masc): string
{
    return setting('term_gender', 'f') === 'm' ? $masc : $fem;
}

/** Módulos con su explicación (la de «Fila a escala» usa los nombres del catálogo). */
function features_help(): array
{
    $all = FEATURES;
    $all['lineup'][1] = 'En ' . tg('una', 'un') . ' ' . term('family', true) . ' con ' . term('subs', true) . ', ' . tg('las', 'los') . ' muestra en fila con su tamaño real relativo (según las medidas).';
    return $all;
}

/** ¿Hay ya un administrador? (si no, la web está sin instalar) */
function is_installed(): bool
{
    if (!db_configured()) {
        return false; // aún no se ha elegido base de datos
    }
    return (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
}

/** ¿Está activado un módulo opcional? (Web → Configuración). Por defecto, activado. */
function feature(string $name): bool
{
    return setting('mod_' . $name, '1') === '1';
}

/** Módulos opcionales: clave => [nombre, explicación]. */
const FEATURES = [
    'reps' => ['Representantes', 'Página de contacto con representantes por zonas.'],
    'slider' => ['Slider de portada', 'Pase de imágenes grandes en la portada.'],
    'pdf' => ['Catálogo en PDF', 'Versión para imprimir o guardar en PDF (/catalogo/completo).'],
    'sketch' => ['Boceto con medidas', 'Dibujo técnico «alto × ancho» en la ficha del artículo, a partir de las medidas.'],
    'lineup' => ['Fila a escala', 'En una familia con subfamilias, las muestra en fila con su tamaño real relativo (según las medidas).'],
    'map' => ['Mapa en Contacto', 'Mapa de Google y botones «Cómo llegar» con la dirección de la empresa.'],
];

/** Países habituales: código => [nombre, prefijo telefónico, zona horaria]. */
const COUNTRIES = [
    'es' => ['España', '34', 'Europe/Madrid'],
    'es-cn' => ['España (Canarias)', '34', 'Atlantic/Canary'],
    'pt' => ['Portugal', '351', 'Europe/Lisbon'],
    'fr' => ['Francia', '33', 'Europe/Paris'],
    'it' => ['Italia', '39', 'Europe/Rome'],
    'de' => ['Alemania', '49', 'Europe/Berlin'],
    'gb' => ['Reino Unido', '44', 'Europe/London'],
    'ad' => ['Andorra', '376', 'Europe/Andorra'],
    'mx' => ['México', '52', 'America/Mexico_City'],
    'co' => ['Colombia', '57', 'America/Bogota'],
    'ar' => ['Argentina', '54', 'America/Argentina/Buenos_Aires'],
    'cl' => ['Chile', '56', 'America/Santiago'],
    'pe' => ['Perú', '51', 'America/Lima'],
    'ec' => ['Ecuador', '593', 'America/Guayaquil'],
    'uy' => ['Uruguay', '598', 'America/Montevideo'],
    've' => ['Venezuela', '58', 'America/Caracas'],
    'us' => ['Estados Unidos', '1', 'America/New_York'],
];

/** Enlace tel: con el prefijo del país configurado (si el número no lo lleva ya). */
function tel_href(string $phone): string
{
    $phone = trim($phone);
    $digits = preg_replace('/\D+/', '', $phone);
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($phone, '+')) {
        return 'tel:+' . $digits;
    }
    if (str_starts_with($digits, '00')) {
        return 'tel:+' . substr($digits, 2);
    }
    $prefix = preg_replace('/\D+/', '', setting('phone_prefix', '34'));
    return 'tel:' . ($prefix !== '' ? '+' . $prefix : '') . $digits;
}

/* ---------- Sesión, CSRF y avisos (solo panel) ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('cat_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => BASE !== '' ? BASE : '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => is_https(),
    ]);
    session_start();
}

/** ¿Hay un usuario del panel con sesión abierta? (para mostrar atajos de edición en la web pública) */
function staff_logged_in(): bool
{
    if (empty($_COOKIE['cat_admin'])) {
        return false;
    }
    start_session();
    return !empty($_SESSION['uid']) && current_role() !== '';
}

/** Menú lateral del catálogo: familias y subfamilias. */
function catalog_nav(?array $current): string
{
    $rows = db()->query('SELECT c.id, c.name, c.slug, c.parent_id,
            (SELECT ref FROM products p WHERE p.category_id = c.id AND p.visible = 1 AND p.ref <> \'\' ORDER BY p.sort, p.id LIMIT 1) AS ref
        FROM categories c ORDER BY c.sort, c.name')->fetchAll();
    $roots = [];
    $kids = [];
    foreach ($rows as $r) {
        if ($r['parent_id']) {
            $kids[(int)$r['parent_id']][] = $r;
        } else {
            $roots[] = $r;
        }
    }
    $refKey = fn($r) => trim((string)$r['ref']) === '' ? 'zzz' : sprintf('%08d', (int)preg_replace('/\D+/', '', (string)$r['ref']));
    foreach ($kids as &$list) {
        usort($list, fn($a, $b) => strcmp($refKey($a), $refKey($b)) ?: strnatcasecmp($a['name'], $b['name']));
    }
    unset($list);
    $curId = $current ? (int)$current['id'] : 0;
    $curParent = $current ? (int)($current['parent_id'] ?? 0) : 0;
    $h = '<nav class="cat-nav" aria-label="' . e(term('families')) . '"><p class="cat-nav-t">' . e(term('families')) . '</p><ul>';
    $h .= '<li><a href="' . e(url('/catalogo')) . '"' . (!$current ? ' class="on"' : '') . '>Todo el catálogo</a></li>';
    foreach ($roots as $r) {
        $id = (int)$r['id'];
        $on = $curId === $id;
        $open = $on || $curParent === $id;
        $link = '<a href="' . e(url('/catalogo/' . $r['slug'])) . '"' . ($on ? ' class="on"' : ($open ? ' class="in"' : '')) . '>' . e($r['name']) . '</a>';
        if (!empty($kids[$id])) {
            $h .= '<li><details class="nv"' . ($open ? ' open' : '') . '><summary>' . $link . '</summary><ul>';
        } else {
            $h .= '<li>' . $link;
        }
        if (!empty($kids[$id])) {
            foreach ($kids[$id] as $k) {
                $h .= '<li><a href="' . e(url('/catalogo/' . $k['slug'])) . '"' . ($curId === (int)$k['id'] ? ' class="on"' : '') . '>' . (trim((string)$k['ref']) !== '' ? '<b class="rf">Ref.' . e(trim((string)$k['ref'])) . '</b> ' : '') . e($k['name']) . '</a></li>';
            }
            $h .= '</ul></details>';
        }
        $h .= '</li>';
    }
    if (feature('pdf')) $h .= '<li class="nav-pdf"><a href="' . e(url('/catalogo/completo')) . '"><svg viewBox="0 0 32 32" width="18" height="18" aria-hidden="true"><path d="M7 2h13l7 7v21H7z" fill="#e2231a"/><path d="M20 2l7 7h-7z" fill="#f59a95"/><text x="16" y="24" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="8.5" fill="#fff">PDF</text></svg> Catálogo en PDF</a></li>';
    return $h . '</ul></nav><script>if(matchMedia("(max-width:900px)").matches)document.querySelectorAll(".cat-nav details").forEach(function(d){d.open=true})</script>';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $ok = hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''));
    if (!$ok) {
        http_response_code(400);
        exit('La sesión ha caducado. Vuelve atrás, recarga la página y repite la acción.');
    }
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/** Rol del usuario con sesión: 'admin' (todo) o 'catalogo' (artículos, familias, fotos e importar). */
function current_role(): string
{
    static $role = null;
    if ($role === null) {
        $st = db()->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute([(int)($_SESSION['uid'] ?? 0)]);
        $role = (string)($st->fetchColumn() ?: '');
    }
    return $role;
}

function is_admin(): bool
{
    return current_role() === 'admin';
}

function require_login(): void
{
    if (empty($_SESSION['uid']) || current_role() === '') {
        $_SESSION = [];
        redirect('/admin/login');
    }
}

/* ---------- Plantillas ---------- */

function render(string $view, array $vars = [], ?string $layout = 'layout'): void
{
    extract($vars, EXTR_SKIP);
    ob_start();
    require ROOT . '/app/views/' . $view . '.php';
    $content = ob_get_clean();
    if ($layout === null) {
        echo $content;
        return;
    }
    require ROOT . '/app/views/' . $layout . '.php';
}

function not_found(): void
{
    http_response_code(404);
    render('notfound', ['title' => 'Página no encontrada · ' . site_name(), 'noindex' => true]);
}

function placeholder_svg(): string
{
    return '<svg class="ph-svg" viewBox="0 0 64 64" aria-hidden="true"><ellipse cx="32" cy="16" rx="18" ry="6" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 16v32c0 3.3 8 6 18 6s18-2.7 18-6V16" fill="none" stroke="currentColor" stroke-width="2"/><path d="M14 28c0 3.3 8 6 18 6s18-2.7 18-6" fill="none" stroke="currentColor" stroke-width="2" opacity=".5"/></svg>';
}

function product_card(array $p): string
{
    $href = e(url('/producto/' . $p['slug']));
    $img = $p['image']
        ? fit_img($p['image'], $p['name'])
        : placeholder_svg();
    $meta = [];
    if ($p['ref'] !== '') {
        $meta[] = 'Ref. ' . e($p['ref']);
    }
    if ($p['dimensions'] !== '') {
        $meta[] = e($p['dimensions']);
    }
    $price = price_map()[(int)$p['id']] ?? '';
    return '<a class="card" href="' . $href . '"><span class="ph">' . $img . '</span>'
        . '<span class="card-body"><strong>' . e($p['name']) . '</strong>'
        . ($meta ? '<small>' . implode(' · ', $meta) . '</small>' : '')
        . ($price !== '' ? '<span class="price">' . e($price) . '</span>' : '')
        . '</span></a>';
}

/**
 * Caja que ocupa el producto dentro de su foto (sin los márgenes blancos/transparentes), con caché.
 * Devuelve [izquierda, arriba, ancho, alto] como fracciones 0–1 y la proporción ancho/alto del recorte.
 */
function cover_box(string $file): array
{
    $none = ['l' => 0.0, 't' => 0.0, 'w' => 1.0, 'h' => 1.0, 'ratio' => 1.0];
    $path = ROOT . '/uploads/' . $file;
    if ($file === '' || !is_file($path)) {
        return $none;
    }
    $gi = @getimagesize($path);
    if ($gi && $gi[1] > 0) {
        $none['ratio'] = round($gi[0] / $gi[1], 4); // sin recorte: proporción real de la foto
    }
    $cacheFile = ROOT . '/data/boxes.json';
    $cache = is_file($cacheFile) ? (json_decode((string)@file_get_contents($cacheFile), true) ?: []) : [];
    $mt = (int)filemtime($path);
    if (isset($cache[$file]) && ($cache[$file]['mt'] ?? 0) === $mt) {
        return $cache[$file];
    }
    $src = @imagecreatefromstring((string)@file_get_contents($path));
    if (!$src) {
        return $none;
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $w = 200;
    $h = max(1, (int)round($sh * $w / max(1, $sw)));
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagecopyresampled($im, $src, 0, 0, 0, 0, $w, $h, $sw, $sh); // sobre blanco: lo transparente cuenta como blanco
    $rows = array_fill(0, $h, 0);
    $cols = array_fill(0, $w, 0);
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            if (255 - min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255) > 30) {
                $rows[$y]++;
                $cols[$x]++;
            }
        }
    }
    $y0 = $h; $y1 = -1; $x0 = $w; $x1 = -1;
    foreach ($rows as $y => $n) {
        if ($n >= 2) { $y0 = min($y0, $y); $y1 = max($y1, $y); }
    }
    foreach ($cols as $x => $n) {
        if ($n >= 2) { $x0 = min($x0, $x); $x1 = max($x1, $x); }
    }
    if ($y1 < $y0 || $x1 < $x0 || ($x1 - $x0) < 8 || ($y1 - $y0) < 8) {
        return $none;
    }
    $box = [
        'mt' => $mt,
        'l' => round($x0 / $w, 4), 't' => round($y0 / $h, 4),
        'w' => round(($x1 - $x0 + 1) / $w, 4), 'h' => round(($y1 - $y0 + 1) / $h, 4),
    ];
    $box['ratio'] = round(($box['w'] * $sw) / ($box['h'] * $sh), 4);
    $cache[$file] = $box;
    @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
    return $box;
}

/**
 * Foto «igualada»: recorta por CSS el producto (sin márgenes) y lo centra ocupando siempre
 * la misma proporción del cuadrado, para que todas las fotos tengan el mismo tamaño visual.
 */
function fit_img(string $file, string $alt, int $fill = 84, float $aspect = 1.0, bool $thumb = true): string
{
    $b = cover_box($file);
    $r = max(0.05, (float)$b['ratio']);
    // Cuadro de proporción $aspect (ancho/alto): el producto cabe entero sin deformarse.
    if ($r >= $aspect) {
        $bw = $fill;
        $bh = $fill * $aspect / $r;
    } else {
        $bh = $fill;
        $bw = $fill * $r / $aspect;
    }
    $iw = $bw / max(0.01, $b['w']);          // tamaño de la imagen completa, en %
    $ih = $bh / max(0.01, $b['h']);
    $left = (100 - $bw) / 2 - $b['l'] * $iw;
    $top = (100 - $bh) / 2 - $b['t'] * $ih;
    return '<img class="fit" src="' . e(img_url($file, $thumb)) . '" alt="' . e($alt) . '" loading="lazy" width="480" height="480" style="width:'
        . round($iw, 2) . '%;height:' . round($ih, 2) . '%;left:' . round($left, 2) . '%;top:' . round($top, 2) . '%">';
}

/** Prepara las subfamilias para la fila «por tamaños»: escala visual, etiqueta y medidas. */
function lineup_items(array $subs): array
{
    foreach ($subs as &$c) {
        $dim = 0;
        if (preg_match_all('/\d+(?:[.,]\d+)?/', (string)($c['first_dims'] ?? ''), $m)) {
            $dim = max(array_map(fn($v) => (float)str_replace(',', '.', $v), $m[0]));
        }
        $c['mm'] = $dim;
        $c['box'] = cover_box((string)($c['cover'] ?? ''));
        $ref = trim((string)($c['first_ref'] ?? ''));
        $c['lu_label'] = ($ref !== '' ? 'Ref.' . $ref . ' ' : '') . $c['name'];
        $c['lu_dims'] = trim(preg_replace('/\s*mm\.?$/i', '', (string)($c['first_dims'] ?? '')));
    }
    unset($c);
    return $subs;
}

function family_tile(array $c): string
{
    $img = !empty($c['cover'])
        ? fit_img($c['cover'], '')
        : placeholder_svg();
    return '<a class="fam" href="' . e(url('/catalogo/' . $c['slug'])) . '"><span class="fam-img">' . $img . '</span>'
        . '<span class="fam-name"><span>' . e($c['name']) . '</span><small>' . (int)$c['n'] . '</small></span></a>';
}

function category_card(array $c): string
{
    $img = !empty($c['cover'])
        ? fit_img($c['cover'], '')
        : placeholder_svg();
    $n = (int)$c['n'];
    $meta = [];
    if ($n === 1) {
        if (($c['first_ref'] ?? '') !== '') {
            $meta[] = 'Ref. ' . e($c['first_ref']);
        }
        if (($c['first_dims'] ?? '') !== '') {
            $meta[] = e($c['first_dims']);
        }
    }
    $small = $meta ? implode(' · ', $meta) : $n . ' artículo' . ($n === 1 ? '' : 's');
    return '<a class="card" href="' . e(url('/catalogo/' . $c['slug'])) . '"><span class="ph">' . $img . '</span>'
        . '<span class="card-body"><strong>' . e($c['name']) . '</strong><small>' . $small . '</small></span></a>';
}

/* ---------- Imágenes ---------- */

function resize_gd(GdImage $src, int $max): GdImage
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $dst;
}

/** Valida y guarda una foto subida. Genera versión grande (1400 px) y miniatura (480 px). */
function save_image(array $f, int $maxFull = 1400, bool $enhance = true): string
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('La foto es demasiado grande para el servidor (límite ' . ini_get('upload_max_filesize') . ').');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No se pudo subir la foto (código ' . $err . ').');
    }
    if ($f['size'] > MAX_UPLOAD_BYTES) {
        throw new RuntimeException('La foto pesa más de 8 MB.');
    }
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF];
    if (!$info || !in_array($info[2], $allowed, true)) {
        throw new RuntimeException('Formato no válido. Usa JPG, PNG o WebP.');
    }
    if ($info[0] * $info[1] > 40000000) {
        throw new RuntimeException('La foto tiene demasiados píxeles (máximo 40 megapíxeles).');
    }
    $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
    if (!$src) {
        throw new RuntimeException('No se pudo leer la imagen.');
    }
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($f['tmp_name']);
        $rot = match ((int)($ex['Orientation'] ?? 1)) {
            3 => 180, 6 => -90, 8 => 90, default => 0,
        };
        if ($rot !== 0) {
            $r = imagerotate($src, $rot, 0);
            if ($r) {
                $src = $r;
            }
        }
    }
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    $webp = function_exists('imagewebp');
    $ext = $webp ? 'webp' : 'png';
    $base = bin2hex(random_bytes(8));
    $enh = '';
    if ($enhance && db_setting('auto_enhance') === '1') {
        $better = enhance_gd($src, $why, db_setting('enhance_bg') === 'transparent');
        $enh = $better ? 'ok' : 'skip:' . $why;
        $src = $better ?: $src;
    }
    foreach ([[$maxFull, ''], [480, '-t']] as [$max, $suffix]) {
        $img = resize_gd($src, $max);
        $path = UPLOAD_DIR . "/$base$suffix.$ext";
        $ok = $webp ? imagewebp($img, $path, 82) : imagepng($img, $path, 6);
        if (!$ok) {
            throw new RuntimeException('No se pudo guardar la imagen en el servidor (¿permisos de la carpeta uploads?).');
        }
    }
    enh_note("$base.$ext", $enh);
    return "$base.$ext";
}

function delete_image(?string $file): void
{
    if (!$file || !preg_match('/^[a-f0-9]{16}\.(webp|png)$/', $file)) {
        return;
    }
    @unlink(UPLOAD_DIR . '/' . $file);
    @unlink(UPLOAD_DIR . '/' . thumb_name($file));
}

/* ---------- Galería de fotos de cada artículo ---------- */

/** Convierte $_FILES['images'] (varios archivos) en una lista simple de archivos. */
function normalize_files(?array $f): array
{
    if (!$f || !isset($f['name'])) {
        return [];
    }
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $name) {
        $out[] = ['name' => $name, 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function gallery_for(int $productId): array
{
    $st = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort, id');
    $st->execute([$productId]);
    return $st->fetchAll();
}

/** Añade una foto al final de la galería y mantiene la portada al día. */
function add_product_image(int $productId, string $file, string $src = ''): void
{
    $st = db()->prepare('SELECT COALESCE(MAX(sort), -1) + 1 FROM product_images WHERE product_id = ?');
    $st->execute([$productId]);
    db()->prepare('INSERT INTO product_images (product_id, file, src, sort, enh) VALUES (?,?,?,?,?)')
        ->execute([$productId, $file, $src, (int)$st->fetchColumn(), enh_note($file)]);
    sync_gallery($productId);
}

function remove_product_image(int $productId, int $imageId): void
{
    $st = db()->prepare('SELECT file FROM product_images WHERE id = ? AND product_id = ?');
    $st->execute([$imageId, $productId]);
    $file = $st->fetchColumn();
    if ($file !== false) {
        db()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
        delete_image((string)$file);
    }
}

/** Pone una foto como portada (la primera de la galería). */
function set_cover(int $productId, int $imageId): void
{
    db()->prepare('UPDATE product_images SET sort = -1 WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
}

/** Renumera la galería y guarda la primera foto como portada del artículo. */
function sync_gallery(int $productId): void
{
    $rows = gallery_for($productId);
    $upd = db()->prepare('UPDATE product_images SET sort = ? WHERE id = ?');
    foreach ($rows as $i => $r) {
        $upd->execute([$i, $r['id']]);
    }
    db()->prepare('UPDATE products SET image = ? WHERE id = ?')->execute([$rows[0]['file'] ?? null, $productId]);
}

function delete_product_files(int $productId): void
{
    foreach (gallery_for($productId) as $g) {
        delete_image($g['file']);
    }
}

require_once __DIR__ . '/photos.php';
require_once __DIR__ . '/ftp.php';
require_once __DIR__ . '/fields.php';


/** Boceto técnico en líneas a partir de «alto x ancho» (mm). Formas: cilindrica, rectangular, conica. null si faltan medidas. */
function sketch_svg(string $dims, bool $slot = false, string $shape = ''): ?string
{
    if (!preg_match_all('/\d+(?:[.,]\d+)?/', $dims, $m) || count($m[0]) < 2) {
        return null;
    }
    $h = (float)str_replace(',', '.', $m[0][0]);   // alto
    $w = (float)str_replace(',', '.', $m[0][1]);   // ancho
    if ($h <= 0 || $w <= 0) {
        return null;
    }
    $rect = $shape === 'rectangular';
    $slot = $slot || $shape === 'ranura';
    $cone = $shape === 'conica';
    $unit = stripos($dims, 'cm') !== false ? 'cm' : 'mm';
    $fmt = fn(float $v) => rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',');
    $bw = $rect ? 125 : 150; $bh = 190;
    $k = min($bw / $w, $bh / $h);
    $pw = $w * $k; $ph = $h * $k;
    $r = fn(float $v) => round($v, 1);
    $ink = '#1c1b19';
    $x0 = 20 + (150 - $pw) / 2 - ($rect ? 0 : 0); $x1 = $x0 + $pw;
    $T = fn(string $d, string $extra = '') => '<path' . ($extra !== '' ? ' ' . $extra : '') . ' d="' . $d . '"/>';
    $o = '<svg class="sketch" viewBox="0 0 250 262" role="img" aria-label="Boceto: alto ' . $fmt($h) . ' ' . $unit . ', ancho ' . $fmt($w) . ' ' . $unit . '" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="' . $ink . '" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">';
    if ($rect) {
        $dx = min($pw * 0.22, 26.0); $dy = $dx * 0.55;
        $yt = 22 + ($bh - $ph) / 2 + $dy; $yb = $yt + $ph - $dy;           // frente: de $yt a $yb
        $o .= $T('M' . $r($x0) . ' ' . $r($yt) . 'H' . $r($x1) . 'V' . $r($yb) . 'H' . $r($x0) . 'Z');
        $o .= $T('M' . $r($x0) . ' ' . $r($yt) . 'L' . $r($x0 + $dx) . ' ' . $r($yt - $dy) . 'H' . $r($x1 + $dx) . 'L' . $r($x1) . ' ' . $r($yt));
        $o .= $T('M' . $r($x1 + $dx) . ' ' . $r($yt - $dy) . 'V' . $r($yb - $dy) . 'L' . $r($x1) . ' ' . $r($yb));
        $o .= $T('M' . $r($x0) . ' ' . $r($yb) . 'L' . $r($x0 + $dx) . ' ' . $r($yb - $dy) . 'H' . $r($x1 + $dx), 'stroke-dasharray="3 3" stroke-opacity=".45"');
        $o .= $T('M' . $r($x0 + $dx) . ' ' . $r($yt - $dy) . 'V' . $r($yb - $dy), 'stroke-dasharray="3 3" stroke-opacity=".45"');
        $yTop = $yt - $dy; $yBot = $yb; $xR = $x1 + $dx; $cx = ($x0 + $x1) / 2; $yc = $yb + 16;
    } else {
        $ry = max(5.0, min($pw * 0.13, 22.0));
        $rxT = $pw / 2; $rxB = $cone ? $rxT * 0.78 : $rxT; $ryB = $cone ? $ry * 0.78 : $ry;
        $cx = ($x0 + $x1) / 2;
        $top = 22 + ($bh - $ph) / 2 + $ry; $bot = $top + $ph - $ry - $ryB;
        $bx0 = $cx - $rxB; $bx1 = $cx + $rxB;
        $o .= $T('M' . $r($x0) . ' ' . $r($top) . 'L' . $r($bx0) . ' ' . $r($bot) . 'A' . $r($rxB) . ' ' . $r($ryB) . ' 0 0 0 ' . $r($bx1) . ' ' . $r($bot) . 'L' . $r($x1) . ' ' . $r($top));
        $o .= $T('M' . $r($bx0) . ' ' . $r($bot) . 'A' . $r($rxB) . ' ' . $r($ryB) . ' 0 0 1 ' . $r($bx1) . ' ' . $r($bot), 'stroke-dasharray="3 3" stroke-opacity=".45"');
        $o .= '<ellipse cx="' . $r($cx) . '" cy="' . $r($top) . '" rx="' . $r($rxT) . '" ry="' . $r($ry) . '"/>';
        $o .= '<ellipse cx="' . $r($cx) . '" cy="' . $r($top) . '" rx="' . $r($rxT * 0.9) . '" ry="' . $r($ry * 0.82) . '" stroke-opacity=".5"/>';
        if ($slot) {
            $o .= $T('M' . $r($cx - $rxT * 0.3) . ' ' . $r($top) . 'L' . $r($cx + $rxT * 0.3) . ' ' . $r($top - $ry * 0.18), 'stroke-width="2.4"');
        }
        $yTop = $top - $ry; $yBot = $bot + $ryB; $xR = $x1; $yc = $bot + $ryB + 16;
    }
    // cota de ancho (debajo)
    $o .= '<g style="stroke:var(--gold,#b8914a)" stroke-width="1">' . $T('M' . $r($x0) . ' ' . $r($yc - 12) . 'V' . $r($yc + 4) . 'M' . $r($x1) . ' ' . $r($yc - 12) . 'V' . $r($yc + 4)
        . 'M' . $r($x0) . ' ' . $r($yc) . 'H' . $r($x1) . 'M' . $r($x0 + 5) . ' ' . $r($yc - 3) . 'L' . $r($x0) . ' ' . $r($yc) . 'L' . $r($x0 + 5) . ' ' . $r($yc + 3)
        . 'M' . $r($x1 - 5) . ' ' . $r($yc - 3) . 'L' . $r($x1) . ' ' . $r($yc) . 'L' . $r($x1 - 5) . ' ' . $r($yc + 3)) . '</g>';
    $o .= '<text x="' . $r($cx) . '" y="' . $r($yc + 16) . '" text-anchor="middle" fill="' . $ink . '" stroke="none" font-size="14" font-family="Inter,Arial,sans-serif">' . e($fmt($w)) . ' ' . $unit . '</text>';
    // cota de alto (derecha)
    $xc = $xR + 18;
    $o .= '<g style="stroke:var(--gold,#b8914a)" stroke-width="1">' . $T('M' . $r($xR + 4) . ' ' . $r($yTop) . 'H' . $r($xc + 4) . 'M' . $r($xR + 4) . ' ' . $r($yBot) . 'H' . $r($xc + 4)
        . 'M' . $r($xc) . ' ' . $r($yTop) . 'V' . $r($yBot) . 'M' . $r($xc - 3) . ' ' . $r($yTop + 5) . 'L' . $r($xc) . ' ' . $r($yTop) . 'L' . $r($xc + 3) . ' ' . $r($yTop + 5)
        . 'M' . $r($xc - 3) . ' ' . $r($yBot - 5) . 'L' . $r($xc) . ' ' . $r($yBot) . 'L' . $r($xc + 3) . ' ' . $r($yBot - 5)) . '</g>';
    $o .= '<text transform="translate(' . $r($xc + 15) . ' ' . $r(($yTop + $yBot) / 2) . ') rotate(90)" text-anchor="middle" fill="' . $ink . '" stroke="none" font-size="14" font-family="Inter,Arial,sans-serif">' . e($fmt($h)) . ' ' . $unit . '</text>';
    return $o . '</svg>';
}


/* ---------- Marca de la empresa (todo sale de Empresa en el panel) ---------- */

/** Nombre comercial; si no hay, la razón social. */
function site_name(): string
{
    $n = trim(setting('trade_name'));
    return $n !== '' ? $n : (trim(setting('company_name')) !== '' ? trim(setting('company_name')) : 'Mi empresa');
}

/** URL de un archivo de marca guardado en uploads/ (logotipo, banner, icono) o null. */
function brand_file(string $key): ?string
{
    $f = setting($key);
    return ($f !== '' && preg_match('/^[\w.\-]+$/', $f) && is_file(ROOT . '/uploads/' . $f)) ? img_url($f) : null;
}

function brand_logo(): ?string
{
    return brand_file('logo');
}

function brand_banner(): ?string
{
    return brand_file('footer_banner');
}

function brand_favicon(): ?string
{
    return brand_file('favicon') ?? brand_file('logo');
}

/** Variables CSS con el color de marca (#RRGGBB) elegido en el panel. */
function brand_color_css(): string
{
    $h = max(24, min(110, (int)setting('logo_height') ?: 64));
    $out = ':root{--logo-h:' . $h . 'px}';
    $c = setting('brand_color');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $c)) {
        return $out;
    }
    $r = hexdec(substr($c, 1, 2)); $g = hexdec(substr($c, 3, 2)); $b = hexdec(substr($c, 5, 2));
    $dark = sprintf('#%02x%02x%02x', (int)($r * .62), (int)($g * .62), (int)($b * .62));
    return $out . ':root{--gold:' . $c . ';--gold-text:' . $dark . '}';
}
