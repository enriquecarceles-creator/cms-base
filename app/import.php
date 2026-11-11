<?php
declare(strict_types=1);

/*
 * Importador: núcleo común a todos los orígenes (WordPress, hojas de cálculo, otras aplicaciones…).
 *
 * Cómo funciona:
 *   1) Un «origen» (archivo en app/importers/) lee lo que sube el usuario y devuelve un PLAN:
 *      lista de artículos con sus textos y fotos. Nada se guarda todavía.
 *   2) El usuario revisa el plan y marca lo que quiere.
 *   3) Este núcleo crea los artículos y descarga/copia las fotos por tandas (import_run).
 *
 * El estado se guarda en data/import_job.json. Las fotos que llegan dentro de un ZIP se
 * descomprimen en data/import_files/ y se borran al terminar o descartar.
 * Para añadir un origen nuevo, lee app/importers/LEEME.md.
 */

const IMPORT_FILE = DATA_DIR . '/import_job.json';
const IMPORT_FILES_DIR = DATA_DIR . '/import_files';
const IMPORT_FTP_DIR = DATA_DIR . '/importar';
const IMPORT_DB_FILE = DATA_DIR . '/import_db.sqlite';
const IMPORT_MAX_IMAGES_PER_ITEM = 12;
const IMPORT_CSV_MAX_IMAGES = 40;
const IMPORT_IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

/* ---------- Orígenes disponibles ---------- */

/**
 * Carga los orígenes de app/importers/*.php. Cada archivo devuelve un array:
 *   id, label, help, order, fields (lista de campos del formulario), analyze (callable que recibe
 *   ['post' => [...], 'files' => [campo => ruta temporal|null]] y devuelve el plan).
 */
function import_sources(): array
{
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $all = [];
    foreach (glob(__DIR__ . '/importers/*.php') ?: [] as $f) {
        $def = require $f;
        if (is_array($def) && !empty($def['id']) && isset($def['analyze']) && is_callable($def['analyze'])) {
            $def += ['label' => $def['id'], 'help' => '', 'order' => 50, 'fields' => [], 'recommended' => false];
            $all[$def['id']] = $def;
        }
    }
    uasort($all, fn($a, $b) => $a['order'] <=> $b['order'] ?: strcmp($a['label'], $b['label']));
    return $all;
}

/** Ejecuta el análisis de un origen con lo enviado en el formulario. */
function import_analyze_source(string $id, array $post, array $files): array
{
    $src = import_sources()[$id] ?? null;
    if (!$src) {
        throw new RuntimeException('Origen de importación desconocido.');
    }
    $in = ['post' => [], 'files' => [], 'names' => []];
    foreach ($src['fields'] as $f) {
        $n = $f['name'];
        if (($f['type'] ?? 'text') === 'file') {
            $u = $files[$n] ?? null;
            $ok = $u && ($u['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$u['tmp_name']);
            if ($u && ($u['error'] ?? 4) === UPLOAD_ERR_INI_SIZE) {
                throw new RuntimeException('«' . $f['label'] . '» pesa más de lo que admite el servidor (' . ini_get('upload_max_filesize') . '). Súbelo por FTP a la carpeta data/importar/ y vuelve a analizar.');
            }
            $in['files'][$n] = $ok ? (string)$u['tmp_name'] : null;
            $in['names'][$n] = $ok ? basename((string)$u['name']) : '';
            if (!$ok && !empty($f['required'])) {
                throw new RuntimeException('Falta el archivo: «' . $f['label'] . '».');
            }
        } else {
            $v = trim((string)($post[$n] ?? ''));
            if ($v === '' && !empty($f['required'])) {
                throw new RuntimeException('Rellena «' . $f['label'] . '».');
            }
            $in['post'][$n] = $v;
        }
    }
    @set_time_limit(180);
    $plan = ($src['analyze'])($in);
    if (($plan['stage'] ?? '') === 'map') {
        // El origen necesita un paso más (p. ej. elegir tabla y columnas): se guarda tal cual.
        return $plan + ['source' => $id, 'source_label' => $src['label'], 'origin' => $src['label']];
    }
    $plan += ['origin' => $src['label'], 'allowed' => [], 'items' => [], 'unassigned' => [], 'warnings' => []];
    $plan['source'] = $id;
    $plan['source_label'] = $src['label'];
    $plan['created'] = time();
    return $plan;
}

/** Paso intermedio de emparejar columnas (solo orígenes con 'map'). Devuelve el trabajo actualizado o el plan. */
function import_map_source(array $job, array $post, array $files): array
{
    $src = import_sources()[$job['source'] ?? ''] ?? null;
    if (!$src || empty($src['map'])) {
        throw new RuntimeException('Este origen no tiene paso de columnas.');
    }
    @set_time_limit(180);
    $plan = ($src['map'])($job, $post, $files);
    if (($plan['stage'] ?? '') === 'map') {
        return $plan;
    }
    $plan += ['origin' => $src['label'], 'allowed' => [], 'items' => [], 'unassigned' => [], 'warnings' => []];
    $plan['source'] = $job['source'];
    $plan['source_label'] = $src['label'];
    $plan['created'] = time();
    return $plan;
}

/** Archivo subido o, si no, el más reciente dejado por FTP en data/importar/ con una de esas extensiones. */
function import_ftp_file(?string $uploaded, array $exts): ?string
{
    if ($uploaded) {
        return $uploaded;
    }
    $found = [];
    foreach (glob(IMPORT_FTP_DIR . '/*') ?: [] as $f) {
        $n = strtolower(basename($f));
        foreach ($exts as $x) {
            if (str_ends_with($n, '.' . $x) && is_file($f)) {
                $found[] = $f;
            }
        }
    }
    usort($found, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $found[0] ?? null;
}

/* ---------- Utilidades para orígenes basados en archivos ---------- */

/** Nombre de columna normalizado: minúsculas, sin acentos ni signos («Imágenes (URL)» → «imagenes url»). */
function import_norm(string $s): string
{
    $s = mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $s)));
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c']);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/**
 * Lee un CSV tal como lo exportan WordPress, Excel, Google Sheets, Shopify, PrestaShop…
 * Detecta el separador (, ; o tabulador) y convierte de Windows-1252 a UTF-8 si hace falta.
 * @return array{0: string[], 1: array<int, string[]>} [cabecera, filas]
 */
function import_read_csv(string $file, int $maxRows = 5000): array
{
    $raw = (string)@file_get_contents($file, false, null, 0, 60 * 1024 * 1024);
    if ($raw === '') {
        throw new RuntimeException('El archivo está vacío.');
    }
    if (str_starts_with($raw, 'PK')) {
        throw new RuntimeException('Este archivo es un Excel (.xlsx) o un ZIP, no un CSV. En Excel: Archivo → Guardar como → «CSV UTF-8 (delimitado por comas)».');
    }
    if (str_starts_with($raw, "\xFF\xFE") || str_starts_with($raw, "\xFE\xFF")) {
        $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
    }
    $raw = (string)preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    if (str_starts_with(ltrim($raw), '<')) {
        throw new RuntimeException('Este archivo es un XML o una página web, no un CSV.');
    }
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = (string)mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    $first = strtok($raw, "\n");
    $delim = ',';
    $bestN = substr_count((string)$first, ',');
    foreach ([';', "\t"] as $d) {
        if (substr_count((string)$first, $d) > $bestN) {
            $delim = $d;
            $bestN = substr_count((string)$first, $d);
        }
    }
    $fh = fopen('php://temp', 'w+b');
    fwrite($fh, $raw);
    rewind($fh);
    $head = fgetcsv($fh, 0, $delim, '"', '');
    if (!$head || count($head) < 2) {
        fclose($fh);
        throw new RuntimeException('No se reconocen las columnas del CSV (la primera fila debe tener los títulos de las columnas).');
    }
    $head = array_map(fn($h) => trim((string)$h), $head);
    $rows = [];
    $n = 0;
    while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false && $n++ < $maxRows) {
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) > 0) {
            $rows[] = array_map('strval', $r);
        }
    }
    fclose($fh);
    if (!$rows) {
        throw new RuntimeException('El CSV no tiene filas con datos.');
    }
    return [$head, $rows];
}

/**
 * Busca columnas por sus nombres posibles (sin importar mayúsculas ni acentos).
 * $want = ['name' => ['nombre', 'name', 'title'], ...]  →  ['name' => índice de columna, ...]
 */
function import_map_columns(array $head, array $want): array
{
    $norm = array_map('import_norm', $head);
    $out = [];
    foreach ($want as $key => $aliases) {
        foreach ($aliases as $a) {
            $i = array_search(import_norm($a), $norm, true);
            if ($i !== false) {
                $out[$key] = $i;
                break;
            }
        }
    }
    return $out;
}

function import_files_clear(): void
{
    if (!is_dir(IMPORT_FILES_DIR)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(IMPORT_FILES_DIR, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir(IMPORT_FILES_DIR);
}

/**
 * Descomprime solo las fotos de un ZIP en data/import_files/.
 * @return array<string, string> [nombre de archivo en minúsculas => nombre guardado]
 */
function import_extract_zip(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Este servidor no tiene la extensión ZIP de PHP. Sube las fotos por otra vía (por URL en el CSV).');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('No se pudo abrir el ZIP de fotos.');
    }
    import_files_clear();
    if (!is_dir(IMPORT_FILES_DIR)) {
        mkdir(IMPORT_FILES_DIR, 0750, true);
    }
    $map = [];
    $total = 0;
    for ($i = 0; $i < $zip->numFiles && count($map) < 5000; $i++) {
        $st = $zip->statIndex($i);
        $name = (string)($st['name'] ?? '');
        if ($name === '' || str_ends_with($name, '/') || str_contains($name, '__MACOSX') || str_starts_with(basename($name), '.')) {
            continue;
        }
        $base = basename(str_replace('\\', '/', $name));
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        if (!in_array($ext, IMPORT_IMAGE_EXT, true) || ($st['size'] ?? 0) > 25 * 1024 * 1024) {
            continue;
        }
        $total += (int)$st['size'];
        if ($total > 800 * 1024 * 1024) {
            throw new RuntimeException('El ZIP tiene demasiadas fotos (más de 800 MB descomprimido). Divídelo en varios.');
        }
        $key = mb_strtolower($base);
        if (isset($map[$key])) {
            continue;
        }
        $safe = sprintf('%05d', count($map)) . '.' . $ext;
        $data = $zip->getFromIndex($i);
        if ($data === false || file_put_contents(IMPORT_FILES_DIR . '/' . $safe, $data) === false) {
            continue;
        }
        $map[$key] = $safe;
    }
    $zip->close();
    if (!$map) {
        throw new RuntimeException('El ZIP no contiene fotos JPG, PNG, WebP o GIF.');
    }
    return $map;
}

/** ZIP subido al formulario o, si no, el más reciente subido por FTP a data/importar/. */
function import_zip_input(?string $uploaded): ?string
{
    if ($uploaded) {
        return $uploaded;
    }
    $found = glob(IMPORT_FTP_DIR . '/*.zip') ?: [];
    usort($found, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $found[0] ?? null;
}

/** Artículo ya existente: primero por referencia, después por nombre. */
function import_existing_index(): array
{
    $rows = db()->query('SELECT p.id, p.category_id, p.ref, p.name, p.dimensions, p.description,
        (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id) AS n_img FROM products p')->fetchAll();
    $idx = ['ref' => [], 'name' => []];
    foreach ($rows as $p) {
        if (trim($p['ref']) !== '') {
            $idx['ref'][mb_strtolower(trim($p['ref']))] = $p;
        }
        $idx['name'][slugify($p['name'])] = $p;
    }
    return $idx;
}

function import_existing_find(array $idx, string $name, string $ref): ?array
{
    if ($ref !== '' && isset($idx['ref'][mb_strtolower(trim($ref))])) {
        return $idx['ref'][mb_strtolower(trim($ref))];
    }
    return $idx['name'][slugify($name)] ?? null;
}

/**
 * Artículo del plan con todos los campos que espera la pantalla de revisión y el importador.
 * $images: lista de ['key' => id único, 'urls' => [...]] o ['key' => ..., 'file' => nombre en data/import_files/].
 */
function import_plan_item(?array $existing, string $name, array $path, string $ref, string $dims, string $desc, array $images, int $visible = 1, array $fields = []): array
{
    // Campos libres: [id de campo => valor] ya normalizados; se quitan los vacíos
    $all = fields_all();
    $clean = [];
    foreach ($fields as $fid => $v) {
        if (isset($all[(int)$fid]) && ($v = field_clean($all[(int)$fid], $v)) !== '') {
            $clean[(int)$fid] = $v;
        }
    }
    $catId = $existing ? (int)$existing['category_id'] : ($path ? import_csv_category($path, false) : null);
    return [
        'pid' => $existing ? (int)$existing['id'] : null,
        'cat_id' => $catId,
        'cat_path' => $existing ? [] : $path,
        'cat_name' => $existing ? '' : implode(' › ', $path),
        'name' => $existing ? $existing['name'] : $name,
        'ref' => $ref, 'dims' => $dims, 'desc' => $desc,
        'visible' => $visible,
        'cur_ref' => $existing['ref'] ?? '', 'cur_dims' => $existing['dimensions'] ?? '',
        'cur_desc' => $existing['description'] ?? '',
        'have_images' => (int)($existing['n_img'] ?? 0),
        'images' => array_map(fn($im) => $im + ['preview' => $im['urls'][0] ?? ''], array_slice($images, 0, IMPORT_CSV_MAX_IMAGES)),
        'fields' => $clean,
    ];
}

/** «sí», «1», «publicado», «active»… → 1;  «no», «0», «oculto», «draft»… → 0;  vacío → $default. */
function import_bool(string $v, int $default = 1): int
{
    $v = import_norm($v);
    if ($v === '') {
        return $default;
    }
    return in_array($v, ['0', 'no', 'false', 'falso', 'hidden', 'oculto', 'draft', 'borrador', 'inactivo', 'inactive', 'archived', 'archivado', 'private', 'privado'], true) ? 0 : 1;
}

/* ---------- Estado en disco ---------- */

function import_load(): ?array
{
    if (!is_file(IMPORT_FILE)) {
        return null;
    }
    $j = json_decode((string)file_get_contents(IMPORT_FILE), true);
    return is_array($j) ? $j : null;
}

function import_save(array $job): void
{
    $tmp = IMPORT_FILE . '.tmp';
    file_put_contents($tmp, json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, IMPORT_FILE);
}

function import_clear(): void
{
    @unlink(IMPORT_FILE);
    @unlink(IMPORT_DB_FILE);
    import_files_clear();
}

/* ---------- Red (con comprobaciones de seguridad) ---------- */

function import_hosts(string $origin): array
{
    $h = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $bare = preg_replace('/^www\./', '', $h);
    return array_values(array_unique([$h, $bare, 'www.' . $bare]));
}

/** Solo se permiten los servidores de la web indicada, y nunca redes privadas. Devuelve la IP validada. */
function import_check_host(string $host, array $allowed): string
{
    $host = strtolower($host);
    if (!in_array($host, $allowed, true)) {
        throw new RuntimeException('Dirección no permitida: ' . $host);
    }
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
    if (!$ips) {
        throw new RuntimeException('No se encuentra el servidor ' . $host . '.');
    }
    foreach ($ips as $ip) {
        $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        if (!$public && !IMPORT_ALLOW_LOCAL) {
            throw new RuntimeException('La dirección ' . $host . ' apunta a una red privada y no se permite.');
        }
    }
    return $ips[0];
}

/** @return array{0:string,1:string,2:string} [contenido, tipo, url final] */
function import_http(string $url, array $allowed, int $cap = 15728640): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Este hosting no tiene cURL activado. Pide a tu proveedor que lo active.');
    }
    for ($hop = 0; $hop < 5; $hop++) {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            throw new RuntimeException('Dirección no válida.');
        }
        $ip = import_check_host($p['host'], $allowed);
        $port = $p['port'] ?? ($scheme === 'https' ? 443 : 80);
        $body = '';
        $location = null;
        $type = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'CatalogImporter/1.0',
            CURLOPT_RESOLVE => [$p['host'] . ':' . $port . ':' . $ip],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => function ($c, string $line) use (&$location, &$type): int {
                if (stripos($line, 'location:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'content-type:') === 0) {
                    $type = strtolower(trim(substr($line, 13)));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($c, string $data) use (&$body, $cap): int {
                $body .= $data;
                return strlen($body) > $cap ? 0 : strlen($data);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 300 && $code < 400 && $location) {
            $url = import_abs($location, $url);
            continue;
        }
        if (strlen($body) > $cap) {
            throw new RuntimeException('El archivo es demasiado grande.');
        }
        if ($ok === false) {
            throw new RuntimeException('No se pudo descargar: ' . ($err !== '' ? $err : 'error de red'));
        }
        if ($code !== 200) {
            throw new RuntimeException('El servidor respondió HTTP ' . $code . '.');
        }
        return [$body, $type, $url];
    }
    throw new RuntimeException('Demasiadas redirecciones.');
}

/** Convierte un enlace (absoluto o relativo) en una URL completa, sin ancla. */
function import_abs(string $href, string $page): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
    if ($href === '' || $href[0] === '#') {
        return '';
    }
    $p = parse_url($page);
    if (!$p || empty($p['host'])) {
        return '';
    }
    $origin = ($p['scheme'] ?? 'https') . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href)) {
        return preg_replace('/#.*$/', '', $href);
    }
    if (str_starts_with($href, '//')) {
        return preg_replace('/#.*$/', '', ($p['scheme'] ?? 'https') . ':' . $href);
    }
    $href = preg_replace('/#.*$/', '', $href);
    [$pathPart, $query] = array_pad(explode('?', $href, 2), 2, null);
    if ($pathPart === '' || $pathPart[0] !== '/') {
        $pathPart = rtrim(dirname($p['path'] ?? '/'), '/') . '/' . $pathPart;
    }
    $segs = [];
    foreach (explode('/', $pathPart) as $s) {
        if ($s === '' || $s === '.') {
            continue;
        }
        if ($s === '..') {
            array_pop($segs);
        } else {
            $segs[] = $s;
        }
    }
    $path = '/' . implode('/', $segs) . (str_ends_with($pathPart, '/') && $segs ? '/' : '');
    return $origin . $path . ($query !== null ? '?' . $query : '');
}

/* ---------- Fotos ---------- */

function import_original(string $u): string
{
    return (string)preg_replace('/-\d{2,4}x\d{2,4}(?=\.(?:jpe?g|png|webp|gif)$)/i', '', $u);
}

/** Direcciones a probar para descargar: primero en la web indicada, después en el servidor original. */
function import_download_urls(array $urls, string $origin): array
{
    $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $out = [];
    foreach ($urls as $u) {
        $h = strtolower((string)parse_url($u, PHP_URL_HOST));
        if ($oh !== '' && $h !== $oh && $h !== 'www.' . $oh && 'www.' . $h !== $oh) {
            $out[] = $origin . (string)parse_url($u, PHP_URL_PATH);
        }
        $out[] = $u;
    }
    return array_values(array_unique($out));
}

/* ---------- Interpretación de textos ---------- */

function import_dims(string $s): string
{
    if (preg_match('/(\d+(?:[.,]\d+)?)\s*[x×X]\s*(\d+(?:[.,]\d+)?)(?:\s*[x×X]\s*(\d+(?:[.,]\d+)?))?\s*(m\/m|mm|cm|m)?/u', $s, $m)) {
        $unit = strtolower($m[4] ?? '');
        if ($unit === '' || $unit === 'm/m') {
            $unit = 'mm';
        }
        return $m[1] . ' x ' . $m[2] . (!empty($m[3]) ? ' x ' . $m[3] : '') . ' ' . $unit;
    }
    return '';
}

/* ---------- Paso 2: importar lo marcado ---------- */

function import_build_job(array $plan, array $post): array
{
    $append = !empty($post['append']);
    $items = [];
    $all = !empty($post['all']); // plan grande: se importa todo lo que se pueda (sin casillas una a una)
    foreach ($plan['items'] as $i => $it) {
        $sel = $all ? ['on' => 1, 'texts' => !empty($post['texts']) ? 1 : 0, 'img' => array_keys($it['images'])] : ($post['items'][$i] ?? null);
        if (!$sel || empty($sel['on'])) {
            continue;
        }
        if (!$it['pid'] && !$it['cat_id'] && empty($it['cat_path'])) {
            continue; // artículo nuevo sin familia: no se puede crear
        }
        $pick = array_map('intval', (array)($sel['img'] ?? []));
        $images = [];
        foreach ($it['images'] as $k => $im) {
            if (in_array($k, $pick, true)) {
                $images[] = ['key' => $im['key'], 'urls' => $im['urls'] ?? [], 'file' => $im['file'] ?? '', 'state' => 'pending', 'msg' => ''];
            }
        }
        if ($it['pid'] && $it['have_images'] > 0 && !$append) {
            $images = [];
        }
        $items[] = [
            'pid' => $it['pid'], 'cat_id' => $it['cat_id'], 'cat_path' => $it['cat_path'] ?? [],
            'visible' => $it['visible'] ?? 1, 'name' => $it['name'],
            'ref' => $it['ref'], 'dims' => $it['dims'], 'desc' => $it['desc'], 'fields' => $it['fields'] ?? [],
            'texts' => !$it['pid'] || !empty($sel['texts']),
            'images' => $images, 'ready' => false, 'done' => false,
        ];
    }
    return [
        'status' => 'running', 'origin' => $plan['origin'], 'allowed' => $plan['allowed'],
        'source' => $plan['source'] ?? '', 'source_label' => $plan['source_label'] ?? '',
        'items' => $items, 'started' => time(), 'lock' => 0,
    ];
}

/** Crea el artículo si es nuevo, o rellena solo los huecos vacíos si ya existe. */
function import_prepare_item(array &$it): void
{
    $db = db();
    $now = date('Y-m-d H:i:s');
    if ($it['pid']) {
        if ($it['texts']) {
            $st = $db->prepare('SELECT ref, dimensions, description FROM products WHERE id = ?');
            $st->execute([$it['pid']]);
            $row = $st->fetch();
            if ($row) {
                $set = [];
                $args = [];
                foreach ([['ref', 'ref'], ['dimensions', 'dims'], ['description', 'desc']] as [$col, $f]) {
                    if ($row[$col] === '' && $it[$f] !== '') {
                        $set[] = "$col = ?";
                        $args[] = $it[$f];
                    }
                }
                if ($set) {
                    $args[] = $now;
                    $args[] = $it['pid'];
                    $db->prepare('UPDATE products SET ' . implode(', ', $set) . ', updated_at = ? WHERE id = ?')->execute($args);
                }
            }
            if (!empty($it['fields'])) {
                product_fields_save((int)$it['pid'], $it['fields'], true); // solo los campos vacíos
            }
        }
        return;
    }
    if (!$it['cat_id'] && !empty($it['cat_path'])) {
        $it['cat_id'] = import_csv_category($it['cat_path'], true);
    }
    $slug = unique_slug('products', slugify(($it['ref'] !== '' ? 'ref ' . $it['ref'] . ' ' : '') . $it['name']));
    $max = $db->prepare('SELECT COALESCE(MAX(sort), 0) + 10 FROM products WHERE category_id = ?');
    $max->execute([$it['cat_id']]);
    $db->prepare('INSERT INTO products (category_id, ref, name, slug, dimensions, description, visible, sort, created_at, updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$it['cat_id'], mb_substr($it['ref'], 0, 120), mb_substr($it['name'], 0, 250), $slug, mb_substr($it['dims'], 0, 250), $it['desc'], (int)($it['visible'] ?? 1), (int)$max->fetchColumn(), $now, $now]);
    $it['pid'] = (int)$db->lastInsertId();
    $it['created'] = true;
    if (!empty($it['fields'])) {
        product_fields_save($it['pid'], $it['fields']);
    }
}

/** Descarga una foto y la añade a la galería. Devuelve 'ok' o 'dup'. */
function import_fetch_image(array $im, array $allowed, string $origin, int $pid): string
{
    $st = db()->prepare('SELECT 1 FROM product_images WHERE product_id = ? AND src = ?');
    $st->execute([$pid, $im['key']]);
    if ($st->fetchColumn()) {
        return 'dup';
    }
    if (!empty($im['file'])) {
        // Foto que venía en un ZIP (ya descomprimida en data/import_files/)
        $path = IMPORT_FILES_DIR . '/' . basename((string)$im['file']);
        if (!is_file($path)) {
            throw new RuntimeException('La foto ya no está en el servidor (¿se descartó la importación?).');
        }
        $file = save_image(['error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'tmp_name' => $path, 'name' => basename($path)]);
        add_product_image($pid, $file, $im['key']);
        return 'ok';
    }
    $last = null;
    foreach (import_download_urls($im['urls'] ?? [], $origin) as $u) {
        try {
            [$body] = import_http($u, $allowed);
        } catch (RuntimeException $e) {
            $last = $e;
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($tmp, $body);
        try {
            $file = save_image(['error' => UPLOAD_ERR_OK, 'size' => strlen($body), 'tmp_name' => $tmp, 'name' => basename($u)]);
        } catch (RuntimeException $e) {
            @unlink($tmp);
            $last = $e;
            continue;
        }
        @unlink($tmp);
        add_product_image($pid, $file, $im['key']);
        return 'ok';
    }
    throw $last ?? new RuntimeException('No se pudo descargar la foto.');
}

function import_budget(): int
{
    $m = (int)ini_get('max_execution_time');
    return $m > 0 ? max(5, min(20, $m - 10)) : 20;
}

/** Procesa el trabajo hasta agotar el tiempo del lote; se continúa solo en la siguiente petición. */
function import_run(array &$job, float $deadline): void
{
    $job['lock'] = time() + 45;
    import_save($job);
    // Los cambios en la base de datos y el estado del trabajo se guardan juntos cada 2 s:
    // si el servidor corta la petición, no se repite ni se duplica nada.
    $db = db();
    $db->beginTransaction();
    $last = microtime(true);
    $flush = function (bool $reopen = true) use (&$job, $db, &$last): void {
        $db->commit();
        import_save($job);
        if ($reopen) {
            $db->beginTransaction();
        }
        $last = microtime(true);
    };
    $stop = function () use (&$job, $flush): void {
        $job['lock'] = 0;
        $flush(false);
    };
    foreach ($job['items'] as &$it) {
        if ($it['done']) {
            continue;
        }
        if (microtime(true) > $deadline) {
            $stop();
            return;
        }
        if (!$it['ready']) {
            try {
                import_prepare_item($it);
            } catch (Throwable $e) {
                $it['error'] = $e instanceof RuntimeException ? $e->getMessage() : 'Error inesperado.';
                $it['done'] = true;
                continue;
            }
            $it['ready'] = true;
        }
        foreach ($it['images'] as &$im) {
            if ($im['state'] !== 'pending') {
                continue;
            }
            if (microtime(true) > $deadline) {
                $stop();
                return;
            }
            try {
                $im['state'] = import_fetch_image($im, $job['allowed'], $job['origin'], (int)$it['pid']);
            } catch (Throwable $e) {
                $im['state'] = 'err';
                $im['msg'] = $e instanceof RuntimeException ? $e->getMessage() : 'Error inesperado.';
            }
            if (microtime(true) - $last > 2) {
                $flush();
            }
        }
        unset($im);
        $it['done'] = true;
        if (microtime(true) - $last > 2) {
            $flush();
        }
    }
    unset($it);
    $db->commit();
    $job['status'] = 'done';
    $job['lock'] = 0;
    $job['finished'] = time();
    import_save($job);
    import_files_clear();
    @unlink(IMPORT_DB_FILE);
}

function import_csv_title_case(string $s): string
{
    $small = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'con', 'en', 'para'];
    return preg_replace_callback('/[\p{L}\p{N}]+/u', function ($m) use ($small) {
        $w = $m[0];
        if (preg_match('/\d/', $w) || in_array(mb_strtolower($w), $small, true)) {
            return $w;
        }
        if (mb_strlen($w) > 1 && $w === mb_strtoupper($w)) {
            return mb_strtoupper(mb_substr($w, 0, 1)) . mb_strtolower(mb_substr($w, 1));
        }
        return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
    }, $s);
}

function import_csv_clean_text(string $s): string
{
    $s = preg_replace('/\[\/?et_pb[^\]]*\]/u', '', $s);
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = str_replace(["\xC2\xA0", '\\n'], [' ', "\n"], $s);
    $lines = array_filter(array_map(fn($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), explode("\n", $s)), fn($l) => $l !== '');
    return implode("\n", $lines);
}

/** Busca (o crea si $create) la familia/subfamilia. Devuelve su id, o null si no existe y no se crea. */
function import_csv_category(array $path, bool $create): ?int
{
    $db = db();
    $match = function (array $rows, string $name): ?array {
        $a = slugify($name);
        foreach ($rows as $r) {            // 1.º coincidencia exacta (Gigante ≠ Super Gigante)
            if ($a === slugify($r['name'])) {
                return $r;
            }
        }
        // 2.º igual salvo plurales (Botes multiusos ≈ Bote multiuso). Nunca «Zapatillas» ≈ «Zapatillas señora».
        $same = function (string $x, string $y): bool {
            $wx = explode('-', $x);
            $wy = explode('-', $y);
            if (count($wx) !== count($wy)) {
                return false;
            }
            foreach ($wx as $i => $w) {
                $v = $wy[$i];
                if ($w !== $v && $w . 's' !== $v && $v . 's' !== $w && $w . 'es' !== $v && $v . 'es' !== $w) {
                    return false;
                }
            }
            return true;
        };
        foreach ($rows as $r) {
            if ($same($a, slugify($r['name']))) {
                return $r;
            }
        }
        return null;
    };
    $parentId = null;
    foreach ($path as $level => $name) {
        $st = $parentId === null
            ? $db->query('SELECT id, name, slug FROM categories WHERE parent_id IS NULL')
            : $db->prepare('SELECT id, name, slug FROM categories WHERE parent_id = ?');
        if ($parentId !== null) {
            $st->execute([$parentId]);
        }
        $found = $match($st->fetchAll(), $name);
        if ($found) {
            $parentId = (int)$found['id'];
            continue;
        }
        if (!$create) {
            return null;
        }
        $base = $level > 0 ? (string)$db->query('SELECT slug FROM categories WHERE id = ' . (int)$parentId)->fetchColumn() . ' ' : '';
        $max = $db->prepare('SELECT COALESCE(MAX(sort), 0) + 10 FROM categories WHERE ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?'));
        $max->execute($parentId === null ? [] : [$parentId]);
        $db->prepare('INSERT INTO categories (name, slug, sort, parent_id) VALUES (?,?,?,?)')
            ->execute([$name, unique_slug('categories', slugify($base . $name)), (int)$max->fetchColumn(), $parentId]);
        $parentId = (int)$db->lastInsertId();
    }
    return $parentId;
}
