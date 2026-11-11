<?php
declare(strict_types=1);

/*
 * Fotos por carpeta de artículo (programas de gestión como NCS Facturación):
 *
 *   FOTOS_ARTICULOS_001/ARTICULO_<CODIGO>/<CODIGO>_2.jpg, <CODIGO>_3.jpg … <CODIGO>_GIF.gif
 *
 * - El código del artículo es el de la CARPETA (los archivos a veces llevan otro).
 * - Se empareja con la referencia sin distinguir mayúsculas ni signos (guiones, espacios, barras…):
 *   BUBN3633L = BUBN3633-L, CASN01322 = CASN013/22. Primero se busca la coincidencia exacta.
 * - Orden: por el último número del nombre (_2, _3…); la más baja es la principal. Sin número = 1.
 *   Los GIF van siempre al final.
 * - GIF: archivos .gif o con «_GIF» en el nombre. Se saltan salvo que se pidan (se guardan como
 *   imagen fija: save_image() convierte a WebP con el primer fotograma).
 * - Origen: un ZIP subido o, si no, el ZIP o la carpeta más reciente de data/importar/ que tenga
 *   subcarpetas ARTICULO_… La carpeta se lee tal cual; del ZIP solo se extraen las fotos que se usan.
 *
 * Lo usan el origen «Fotos por carpeta de artículo» (app/importers/fotos-carpetas.php) y el origen
 * «Base de datos» (casilla «Fotos por carpeta de artículo», marcada en el perfil NCS).
 */

const PF_PREFIX = 'ARTICULO_';
const PF_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

/** Código comparable: mayúsculas y solo letras y números («CASN013/22» → «CASN01322»). */
function pf_norm(string $s): string
{
    return strtoupper((string)preg_replace('/[^A-Za-z0-9]+/', '', $s));
}

/** ¿Es una carpeta de artículo? Devuelve su código o null. */
function pf_folder_code(string $name): ?string
{
    return stripos($name, PF_PREFIX) === 0 && strlen($name) > strlen(PF_PREFIX) ? substr($name, strlen(PF_PREFIX)) : null;
}

/** Subcarpetas ARTICULO_… directamente dentro de $dir. */
function pf_dir_folders(string $dir): array
{
    $out = [];
    foreach (@scandir($dir) ?: [] as $n) {
        if ($n !== '.' && $n !== '..' && pf_folder_code($n) !== null && is_dir($dir . '/' . $n)) {
            $out[] = $n;
        }
    }
    return $out;
}

/** ¿El ZIP tiene carpetas ARTICULO_…? */
function pf_zip_has_folders(string $path): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $found = false;
    for ($i = 0; $i < $zip->numFiles && !$found; $i++) {
        $found = (bool)preg_match('#(^|/)' . PF_PREFIX . '[^/]+/[^/]+$#i', str_replace('\\', '/', (string)$zip->getNameIndex($i)));
    }
    $zip->close();
    return $found;
}

/**
 * Busca el origen de las fotos: el ZIP subido o lo más reciente de data/importar/.
 * @return array{type: string, path: string, label: string}|null
 */
function pf_find_source(?string $uploadedZip): ?array
{
    if ($uploadedZip) {
        return pf_zip_has_folders($uploadedZip) ? ['type' => 'zip', 'path' => $uploadedZip, 'label' => 'el ZIP subido'] : null;
    }
    $cands = [];
    if (is_dir(IMPORT_FTP_DIR)) {
        if (pf_dir_folders(IMPORT_FTP_DIR)) {
            $cands[] = ['type' => 'dir', 'path' => IMPORT_FTP_DIR, 'label' => 'data/importar/', 'mtime' => (int)filemtime(IMPORT_FTP_DIR)];
        }
        foreach (@scandir(IMPORT_FTP_DIR) ?: [] as $n) {
            $p = IMPORT_FTP_DIR . '/' . $n;
            if ($n === '.' || $n === '..' || pf_folder_code($n) !== null) {
                continue;
            }
            if (is_dir($p) && pf_dir_folders($p)) {
                $cands[] = ['type' => 'dir', 'path' => $p, 'label' => 'data/importar/' . $n . '/', 'mtime' => (int)filemtime($p)];
            } elseif (is_file($p) && str_ends_with(strtolower($n), '.zip') && pf_zip_has_folders($p)) {
                $cands[] = ['type' => 'zip', 'path' => $p, 'label' => 'data/importar/' . $n, 'mtime' => (int)filemtime($p)];
            }
        }
    }
    usort($cands, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
    if (!$cands) {
        return null;
    }
    unset($cands[0]['mtime']);
    return $cands[0];
}

/** Datos de una foto por su nombre: número de orden y si es GIF. */
function pf_file_info(string $name): array
{
    $num = preg_match('/_(\d+)\.[a-z0-9]+$/i', $name, $m) ? (int)$m[1] : 1;
    $gif = str_ends_with(strtolower($name), '.gif') || (bool)preg_match('/_gif(_|\.|$)/i', $name);
    return ['name' => $name, 'num' => $num, 'gif' => $gif];
}

/**
 * Lee las carpetas del origen.
 * @return array<string, array{folder: string, code: string, files: array}> [nombre de carpeta => datos]
 *   files: ['name', 'num', 'gif', 'rel' (carpeta: ruta dentro de data/importar/) | 'idx' (ZIP: nº de entrada)]
 */
function pf_scan(array $src): array
{
    $out = [];
    // Las carpetas vacías también cuentan (salen en el resumen como «sin fotos»)
    $add = function (string $folder, ?array $file) use (&$out): void {
        $out[$folder] ??= ['folder' => $folder, 'code' => (string)pf_folder_code($folder), 'files' => []];
        if ($file) {
            $out[$folder]['files'][] = $file;
        }
    };
    if ($src['type'] === 'dir') {
        $base = realpath(IMPORT_FTP_DIR) ?: IMPORT_FTP_DIR;
        $root = realpath($src['path']) ?: $src['path'];
        $relRoot = ltrim(str_replace('\\', '/', substr($root, strlen($base))), '/');
        foreach (pf_dir_folders($src['path']) as $folder) {
            $add($folder, null);
            foreach (@scandir($src['path'] . '/' . $folder) ?: [] as $n) {
                if (is_file($src['path'] . '/' . $folder . '/' . $n) && in_array(strtolower(pathinfo($n, PATHINFO_EXTENSION)), PF_EXT, true)) {
                    $add($folder, pf_file_info($n) + ['rel' => ($relRoot !== '' ? $relRoot . '/' : '') . $folder . '/' . $n]);
                }
            }
        }
    } else {
        $zip = new ZipArchive();
        if ($zip->open($src['path']) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP de fotos.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            if (str_contains($name, '__MACOSX')) {
                continue;
            }
            if (preg_match('#(?:^|/)(' . PF_PREFIX . '[^/]+)/$#i', $name, $m)) {
                $add($m[1], null); // entrada de carpeta (puede estar vacía)
                continue;
            }
            if (!preg_match('#(?:^|/)(' . PF_PREFIX . '[^/]+)/([^/]+)$#i', $name, $m)) {
                continue;
            }
            if (in_array(strtolower(pathinfo($m[2], PATHINFO_EXTENSION)), PF_EXT, true) && !str_starts_with($m[2], '.')) {
                $add($m[1], pf_file_info($m[2]) + ['idx' => $i]);
            }
        }
        $zip->close();
    }
    foreach ($out as &$f) {
        // Primero las fotos normales por número; los GIF siempre al final (nunca son la principal)
        usort($f['files'], fn($a, $b) => $a['gif'] <=> $b['gif'] ?: ($a['num'] <=> $b['num'] ?: strnatcasecmp($a['name'], $b['name'])));
    }
    unset($f);
    ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/**
 * Índice para emparejar códigos: $items = [[código, dato], …].
 * @return array{exact: array<string, array>, norm: array<string, array>}
 */
function pf_index(array $items): array
{
    $idx = ['exact' => [], 'norm' => []];
    foreach ($items as [$code, $data]) {
        $code = trim((string)$code);
        if ($code === '') {
            continue;
        }
        $idx['exact'][mb_strtoupper($code)][] = $data;
        $n = pf_norm($code);
        if ($n !== '') {
            $idx['norm'][$n][] = $data;
        }
    }
    return $idx;
}

/**
 * Busca un código en el índice.
 * @return array{0: mixed, 1: string} [dato o null, 'exact' | 'loose' | 'ambiguous' | 'none']
 */
function pf_lookup(array $idx, string $code): array
{
    $hits = $idx['exact'][mb_strtoupper(trim($code))] ?? [];
    if (count($hits) === 1) {
        return [$hits[0], 'exact'];
    }
    if (count($hits) > 1) {
        return [null, 'ambiguous'];
    }
    $hits = $idx['norm'][pf_norm($code)] ?? [];
    if (count($hits) === 1) {
        return [$hits[0], 'loose'];
    }
    return [null, $hits ? 'ambiguous' : 'none'];
}

/** Abre el ZIP del origen (o null si es una carpeta). */
function pf_open(array $src): ?ZipArchive
{
    if ($src['type'] !== 'zip') {
        return null;
    }
    $zip = new ZipArchive();
    if ($zip->open($src['path']) !== true) {
        throw new RuntimeException('No se pudo abrir el ZIP de fotos.');
    }
    return $zip;
}

/**
 * Fotos de una carpeta, listas para el plan del importador (en orden; sin GIF salvo $gifs).
 * Carpeta: ['key', 'ftp' => ruta dentro de data/importar/, 'label', 'preview'].
 * ZIP: se extrae cada foto a data/import_files/ → ['key', 'file', 'label'].
 * La clave ('carpeta:ARTICULO_X/archivo') se guarda en product_images.src: así no se duplica al repetir.
 */
function pf_images(array $folder, bool $gifs, ?ZipArchive $zip): array
{
    $out = [];
    foreach ($folder['files'] as $f) {
        if ($f['gif'] && !$gifs) {
            continue;
        }
        $key = 'carpeta:' . $folder['folder'] . '/' . $f['name'];
        if (isset($f['rel'])) {
            $out[] = ['key' => $key, 'ftp' => $f['rel'], 'label' => $f['name'], 'preview' => url('/admin/importar?ftpfoto=' . rawurlencode($f['rel']))];
            continue;
        }
        if (!$zip) {
            continue;
        }
        $st = $zip->statIndex((int)$f['idx']);
        if (!$st || ($st['size'] ?? 0) > 25 * 1024 * 1024) {
            continue;
        }
        if (!is_dir(IMPORT_FILES_DIR)) {
            mkdir(IMPORT_FILES_DIR, 0750, true);
        }
        $safe = 'pf_' . substr(md5($key), 0, 16) . '.' . strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!is_file(IMPORT_FILES_DIR . '/' . $safe)) {
            $data = $zip->getFromIndex((int)$f['idx']);
            if ($data === false || file_put_contents(IMPORT_FILES_DIR . '/' . $safe, $data) === false) {
                continue;
            }
        }
        $out[] = ['key' => $key, 'file' => $safe, 'label' => $f['name']];
    }
    return $out;
}

/** Ruta real de una foto dejada por FTP en data/importar/ (o null si no existe o se sale de la carpeta). */
function pf_ftp_path(string $rel): ?string
{
    $base = realpath(IMPORT_FTP_DIR);
    $path = realpath(IMPORT_FTP_DIR . '/' . str_replace('\\', '/', $rel));
    if (!$base || !$path || !is_file($path) || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $path;
}
