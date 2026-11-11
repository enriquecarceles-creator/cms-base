<?php
declare(strict_types=1);

/* Mejora de fotos: recorta el sobrante, centra el artículo sobre fondo blanco y unifica el tamaño visual. */

const ENH_SIZE = 1400;        // lado del lienzo cuadrado
const ENH_FILL = 0.86;        // fracción del lienzo que ocupa el artículo
const ENH_ORIG_DIR = UPLOAD_DIR . '/orig';

/** Estado de mejora de las fotos recién subidas en esta petición (para add_product_image). */
function enh_note(string $file, ?string $status = null): string
{
    static $n = [];
    if ($status !== null) {
        $n[$file] = $status;
    }
    return $n[$file] ?? '';
}

function save_setting(string $key, string $value): void
{
    db_put_setting(db(), $key, $value);
}

function db_setting(string $key): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    return (string)($st->fetchColumn() ?: '');
}

/**
 * Devuelve la foto mejorada (lienzo cuadrado blanco) o null si no se puede de forma fiable
 * (fondo no uniforme, imagen vacía…). $why explica el motivo.
 */
function enhance_gd(GdImage $src, ?string &$why = null, bool $transparent = false): ?GdImage
{
    $why = null;
    $src = resize_gd($src, ENH_SIZE);
    $w = imagesx($src);
    $h = imagesy($src);
    $flat = imagecreatetruecolor($w, $h);
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagealphablending($flat, true);
    imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);

    // Versión pequeña para analizar rápido.
    $sc = min(1.0, 240 / max($w, $h));
    $aw = max(8, (int)round($w * $sc));
    $ah = max(8, (int)round($h * $sc));
    $a = imagecreatetruecolor($aw, $ah);
    imagecopyresampled($a, $flat, 0, 0, 0, 0, $aw, $ah, $w, $h);
    $rgb = static function (int $c): array {
        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    };

    // Color de fondo = mediana del borde (muestreado a resolución completa, para notar texturas); debe ser casi uniforme.
    $edge = [];
    $stepX = max(1, intdiv($w, 300));
    $stepY = max(1, intdiv($h, 300));
    for ($x = 0; $x < $w; $x += $stepX) {
        $edge[] = $rgb(imagecolorat($flat, $x, 1));
        $edge[] = $rgb(imagecolorat($flat, $x, $h - 2));
    }
    for ($y = 0; $y < $h; $y += $stepY) {
        $edge[] = $rgb(imagecolorat($flat, 1, $y));
        $edge[] = $rgb(imagecolorat($flat, $w - 2, $y));
    }
    $bg = [];
    foreach ([0, 1, 2] as $ch) {
        $v = array_column($edge, $ch);
        sort($v);
        $bg[$ch] = $v[intdiv(count($v), 2)];
    }
    $near = 0;
    foreach ($edge as $p) {
        if (max(abs($p[0] - $bg[0]), abs($p[1] - $bg[1]), abs($p[2] - $bg[2])) <= 26) {
            $near++;
        }
    }
    if ($near / count($edge) < 0.85) {
        $why = 'fondo no uniforme';
        return null;
    }

    // Caja que contiene el artículo (ignora motas sueltas).
    $rows = array_fill(0, $ah, 0);
    $cols = array_fill(0, $aw, 0);
    for ($y = 0; $y < $ah; $y++) {
        for ($x = 0; $x < $aw; $x++) {
            [$r, $g, $b] = $rgb(imagecolorat($a, $x, $y));
            if (max(abs($r - $bg[0]), abs($g - $bg[1]), abs($b - $bg[2])) > 34) {
                $rows[$y]++;
                $cols[$x]++;
            }
        }
    }
    $ys = array_keys(array_filter($rows, fn($n) => $n >= 2));
    $xs = array_keys(array_filter($cols, fn($n) => $n >= 2));
    if (!$ys || !$xs) {
        $why = 'no se distingue el artículo';
        return null;
    }
    $m = 6;
    $x0 = max(0, (int)floor(min($xs) / $sc) - $m);
    $y0 = max(0, (int)floor(min($ys) / $sc) - $m);
    $x1 = min($w, (int)ceil((max($xs) + 1) / $sc) + $m);
    $y1 = min($h, (int)ceil((max($ys) + 1) / $sc) + $m);
    $cw = $x1 - $x0;
    $ch = $y1 - $y0;
    if ($cw < 40 || $ch < 40) {
        $why = 'artículo demasiado pequeño en la foto';
        return null;
    }
    $crop = imagecreatetruecolor($cw, $ch);
    imagecopy($crop, $flat, 0, 0, $x0, $y0, $cw, $ch);

    if ($transparent) {
        $tc = enh_make_transparent($crop, $cw, $ch, $bg, $rgb);
        if (!$tc) {
            $why = 'no se pudo separar el fondo';
            return null;
        }
        $crop = $tc;
    } elseif (max(255 - $bg[0], 255 - $bg[1], 255 - $bg[2]) > 6) {
        for ($y = 0; $y < $ch; $y++) {
            for ($x = 0; $x < $cw; $x++) {
                [$r, $g, $b] = $rgb(imagecolorat($crop, $x, $y));
                $d = max(abs($r - $bg[0]), abs($g - $bg[1]), abs($b - $bg[2]));
                if ($d <= 16) {
                    imagesetpixel($crop, $x, $y, 0xFFFFFF);
                } elseif ($d < 40) {
                    $t = ($d - 16) / 24;
                    imagesetpixel($crop, $x, $y, ((int)round(255 + ($r - 255) * $t) << 16) | ((int)round(255 + ($g - 255) * $t) << 8) | (int)round(255 + ($b - 255) * $t));
                }
            }
        }
    }

    // Lienzo cuadrado blanco con el artículo centrado y a tamaño homogéneo.
    $scale = min(ENH_SIZE * ENH_FILL / $cw, ENH_SIZE * ENH_FILL / $ch, 2.0);
    $nw = max(1, (int)round($cw * $scale));
    $nh = max(1, (int)round($ch * $scale));
    $out = imagecreatetruecolor(ENH_SIZE, ENH_SIZE);
    if ($transparent) {
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
    } else {
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
    }
    imagecopyresampled($out, $crop, intdiv(ENH_SIZE - $nw, 2), intdiv(ENH_SIZE - $nh, 2), 0, 0, $nw, $nh, $cw, $ch);
    return $out;
}

/**
 * Quita el fondo exterior: solo se vuelve transparente la zona de color de fondo CONECTADA con el borde de la foto
 * (así el blanco de dentro del producto no se pierde). Devuelve null si no hay un exterior claro.
 */
function enh_make_transparent(GdImage $crop, int $cw, int $ch, array $bg, callable $rgb): ?GdImage
{
    $cs = 4;
    $gw = intdiv($cw + $cs - 1, $cs);
    $gh = intdiv($ch + $cs - 1, $cs);
    $fg = array_fill(0, $gw * $gh, 0);
    for ($y = 0; $y < $ch; $y++) {
        $row = intdiv($y, $cs) * $gw;
        for ($x = 0; $x < $cw; $x++) {
            if ($fg[$row + intdiv($x, $cs)]) {
                continue;
            }
            [$r, $g, $b] = $rgb(imagecolorat($crop, $x, $y));
            if (max(abs($r - $bg[0]), abs($g - $bg[1]), abs($b - $bg[2])) > 16) {
                $fg[$row + intdiv($x, $cs)] = 1;
            }
        }
    }
    // Relleno desde el borde por las celdas de fondo.
    $out = array_fill(0, $gw * $gh, 0);
    $q = [];
    $push = function (int $gx, int $gy) use (&$q, &$out, $fg, $gw, $gh): void {
        if ($gx < 0 || $gy < 0 || $gx >= $gw || $gy >= $gh) {
            return;
        }
        $i = $gy * $gw + $gx;
        if (!$fg[$i] && !$out[$i]) {
            $out[$i] = 1;
            $q[] = $i;
        }
    };
    for ($x = 0; $x < $gw; $x++) {
        $push($x, 0);
        $push($x, $gh - 1);
    }
    for ($y = 0; $y < $gh; $y++) {
        $push(0, $y);
        $push($gw - 1, $y);
    }
    while ($q) {
        $i = array_pop($q);
        $gx = $i % $gw;
        $gy = intdiv($i, $gw);
        $push($gx + 1, $gy);
        $push($gx - 1, $gy);
        $push($gx, $gy + 1);
        $push($gx, $gy - 1);
    }
    // Debe haber un exterior claro: la mayoría del borde de la foto tiene que ser fondo.
    $border = 0;
    $borderOut = 0;
    for ($gy = 0; $gy < $gh; $gy++) {
        for ($gx = 0; $gx < $gw; $gx++) {
            if ($gx === 0 || $gy === 0 || $gx === $gw - 1 || $gy === $gh - 1) {
                $border++;
                $borderOut += $out[$gy * $gw + $gx];
            }
        }
    }
    if ($borderOut < $border * 0.4) {
        return null;
    }
    // Zona de trabajo = exterior + una celda alrededor (donde está el borde del producto).
    $zone = $out;
    for ($gy = 0; $gy < $gh; $gy++) {
        for ($gx = 0; $gx < $gw; $gx++) {
            if ($out[$gy * $gw + $gx]) {
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $nx = $gx + $dx;
                        $ny = $gy + $dy;
                        if ($nx >= 0 && $ny >= 0 && $nx < $gw && $ny < $gh) {
                            $zone[$ny * $gw + $nx] = 1;
                        }
                    }
                }
            }
        }
    }
    $res = imagecreatetruecolor($cw, $ch);
    imagealphablending($res, false);
    imagesavealpha($res, true);
    for ($y = 0; $y < $ch; $y++) {
        $row = intdiv($y, $cs) * $gw;
        for ($x = 0; $x < $cw; $x++) {
            $c = imagecolorat($crop, $x, $y);
            if (!$zone[$row + intdiv($x, $cs)]) {
                imagesetpixel($res, $x, $y, $c);
                continue;
            }
            [$r, $g, $b] = $rgb($c);
            $d = max(abs($r - $bg[0]), abs($g - $bg[1]), abs($b - $bg[2]));
            if ($d <= 16) {
                imagesetpixel($res, $x, $y, 0x7F000000 | 0xFFFFFF);
            } elseif ($d < 40) {
                $t = ($d - 16) / 24;   // opacidad 0..1; se quita la parte de fondo mezclada en el borde
                $nr = max(0, min(255, (int)round($bg[0] + ($r - $bg[0]) / $t)));
                $ng = max(0, min(255, (int)round($bg[1] + ($g - $bg[1]) / $t)));
                $nb = max(0, min(255, (int)round($bg[2] + ($b - $bg[2]) / $t)));
                imagesetpixel($res, $x, $y, ((127 - (int)round(127 * $t)) << 24) | ($nr << 16) | ($ng << 8) | $nb);
            } else {
                imagesetpixel($res, $x, $y, $c);
            }
        }
    }
    return $res;
}

function enh_write(GdImage $img, string $file): void
{
    $webp = str_ends_with($file, '.webp');
    foreach ([[ENH_SIZE, $file], [480, thumb_name($file)]] as [$max, $name]) {
        $i = $max === ENH_SIZE ? $img : resize_gd($img, $max);
        $ok = $webp ? imagewebp($i, UPLOAD_DIR . '/' . $name, 86) : imagepng($i, UPLOAD_DIR . '/' . $name, 6);
        if (!$ok) {
            throw new RuntimeException('No se pudo guardar la foto (permisos de uploads/).');
        }
    }
}

/** Mejora una foto ya guardada (con copia del original). Devuelve 'ok' o 'skip:motivo'. */
function enhance_stored(array $row): string
{
    $file = (string)$row['file'];
    $path = UPLOAD_DIR . '/' . $file;
    $set = fn(string $s) => db()->prepare('UPDATE product_images SET enh = ? WHERE id = ?')->execute([$s, $row['id']]);
    $src = is_file($path) ? @imagecreatefromstring((string)file_get_contents($path)) : false;
    if (!$src) {
        $set('skip:no se puede leer');
        return 'skip:no se puede leer';
    }
    $new = enhance_gd($src, $why, db_setting('enhance_bg') === 'transparent');
    if (!$new) {
        $set('skip:' . $why);
        return 'skip:' . $why;
    }
    if (!is_dir(ENH_ORIG_DIR)) {
        @mkdir(ENH_ORIG_DIR, 0755, true);
    }
    if (!is_file(ENH_ORIG_DIR . '/' . $file)) {
        copy($path, ENH_ORIG_DIR . '/' . $file);
    }
    enh_write($new, $file);
    $set('ok');
    return 'ok';
}

function enhance_restore(array $row): bool
{
    $file = (string)$row['file'];
    $bak = ENH_ORIG_DIR . '/' . $file;
    if (!is_file($bak)) {
        return false;
    }
    $img = @imagecreatefromstring((string)file_get_contents($bak));
    if (!$img) {
        return false;
    }
    copy($bak, UPLOAD_DIR . '/' . $file);
    $t = resize_gd($img, 480);
    str_ends_with($file, '.webp') ? imagewebp($t, UPLOAD_DIR . '/' . thumb_name($file), 82) : imagepng($t, UPLOAD_DIR . '/' . thumb_name($file), 6);
    @unlink($bak);
    db()->prepare("UPDATE product_images SET enh = '' WHERE id = ?")->execute([$row['id']]);
    return true;
}

/* ---------- Pantalla del panel ---------- */

function admin_photos(): void
{
    $db = db();
    if (!is_dir(ENH_ORIG_DIR)) {
        @mkdir(ENH_ORIG_DIR, 0755, true);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';
        @set_time_limit(120);
        try {
            if ($action === 'trial') {
                $rows = $db->query("SELECT * FROM product_images WHERE enh = '' ORDER BY RANDOM() LIMIT 6")->fetchAll();
                $_SESSION['enh_trial'] = array_column($rows, 'id');
                foreach ($rows as $r) {
                    enhance_stored($r);
                }
                flash('Prueba hecha con ' . count($rows) . ' fotos. Compara abajo; si no te gusta, pulsa «Deshacer todo».');
            } elseif ($action === 'zip_skipped' || $action === 'zip_all') {
                photos_zip($action === 'zip_all');
                return;
            } elseif ($action === 'all') {
                save_setting('enhance_run', '1');
            } elseif ($action === 'stop') {
                save_setting('enhance_run', '0');
            } elseif ($action === 'undo') {
                $n = 0;
                foreach ($db->query("SELECT * FROM product_images WHERE enh = 'ok'")->fetchAll() as $r) {
                    $n += enhance_restore($r) ? 1 : 0;
                }
                $db->exec("UPDATE product_images SET enh = '' WHERE enh LIKE 'skip:%'");
                save_setting('enhance_run', '0');
                unset($_SESSION['enh_trial']);
                flash($n . ' fotos devueltas a su versión original.');
            } elseif ($action === 'mark_done') {
                $n = (int)$db->query("SELECT COUNT(*) FROM product_images WHERE enh = ''")->fetchColumn();
                $db->exec("UPDATE product_images SET enh = 'retocada' WHERE enh = ''");
                flash($n . ' fotos marcadas como ya retocadas. «Mejorar fotos» no las tocará.');
            } elseif ($action === 'retry') {
                $db->exec("UPDATE product_images SET enh = '' WHERE enh LIKE 'skip:%'");
            } elseif ($action === 'bg') {
                save_setting('enhance_bg', ($_POST['bg'] ?? '') === 'transparent' ? 'transparent' : 'white');
                flash('Fondo guardado. Para aplicarlo a las fotos ya mejoradas pulsa «Rehacer todas».');
            } elseif ($action === 'redo') {
                foreach ($db->query("SELECT * FROM product_images WHERE enh = 'ok'")->fetchAll() as $r) {
                    enhance_restore($r);
                }
                $db->exec("UPDATE product_images SET enh = '' WHERE enh LIKE 'skip:%'");
                save_setting('enhance_run', '1');
            } elseif ($action === 'auto') {
                save_setting('auto_enhance', !empty($_POST['auto']) ? '1' : '0');
                flash('Ajuste guardado.');
            }
        } catch (Throwable $e) {
            error_log((string)$e);
            flash('Error al procesar las fotos: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'inesperado.'), 'err');
            save_setting('enhance_run', '0');
        }
        redirect('/admin/fotos');
    }

    // Tanda automática mientras haya fotos pendientes.
    $running = db_setting('enhance_run') === '1';
    if ($running) {
        $deadline = microtime(true) + 15;
        @set_time_limit(60);
        while (microtime(true) < $deadline) {
            $r = $db->query("SELECT * FROM product_images WHERE enh = '' ORDER BY id LIMIT 1")->fetch();
            if (!$r) {
                break;
            }
            try {
                enhance_stored($r);
            } catch (Throwable $e) {
                error_log((string)$e);
                $db->prepare("UPDATE product_images SET enh = 'skip:error' WHERE id = ?")->execute([$r['id']]);
            }
        }
        if (!$db->query("SELECT 1 FROM product_images WHERE enh = '' LIMIT 1")->fetchColumn()) {
            save_setting('enhance_run', '0');
            $running = false;
            flash('Terminado: todas las fotos se han revisado.');
        }
    }

    $count = fn(string $w) => (int)$db->query("SELECT COUNT(*) FROM product_images WHERE $w")->fetchColumn();
    $stats = [
        'total' => $count('1=1'), 'ok' => $count("enh = 'ok'"), 'manual' => $count("enh = 'retocada'"), 'skip' => $count("enh LIKE 'skip:%'"), 'pending' => $count("enh = ''"),
    ];
    $trial = [];
    if (!empty($_SESSION['enh_trial'])) {
        $in = implode(',', array_map('intval', $_SESSION['enh_trial']));
        $trial = $db->query("SELECT i.*, p.name FROM product_images i JOIN products p ON p.id = i.product_id WHERE i.id IN ($in)")->fetchAll();
    }
    $skipped = $db->query("SELECT i.file, i.enh, p.name FROM product_images i JOIN products p ON p.id = i.product_id WHERE i.enh LIKE 'skip:%' ORDER BY p.name LIMIT 60")->fetchAll();
    admin_render('photos', [
        'stats' => $stats, 'running' => $running, 'trial' => $trial, 'skipped' => $skipped,
        'auto' => db_setting('auto_enhance') === '1', 'bgmode' => db_setting('enhance_bg') === 'transparent' ? 'transparent' : 'white', 'refresh' => $running, 'title' => 'Fotos',
    ]);
}


/** Descarga en ZIP las fotos (en JPG, con el mismo nombre que en la web) para retocarlas fuera, p. ej. en Photoroom. */
function photos_zip(bool $all): void
{
    if (!class_exists('ZipArchive')) {
        flash('Este servidor no tiene la extensión ZIP de PHP. Descarga las fotos por FTP desde la carpeta uploads.', 'err');
        redirect('/admin/fotos');
    }
    @set_time_limit(300);
    $where = $all ? '1=1' : "i.enh LIKE 'skip:%'";
    $rows = db()->query("SELECT i.file FROM product_images i WHERE $where ORDER BY i.id")->fetchAll();
    $tmp = tempnam(sys_get_temp_dir(), 'fotos');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        flash('No se pudo crear el ZIP.', 'err');
        redirect('/admin/fotos');
    }
    $n = 0;
    $tmpFiles = [];
    foreach ($rows as $r) {
        $path = UPLOAD_DIR . '/' . $r['file'];
        $orig = ENH_ORIG_DIR . '/' . $r['file'];
        if (is_file($orig)) {
            $path = $orig; // si se mejoró, se usa la original
        }
        $src = is_file($path) ? @imagecreatefromstring((string)file_get_contents($path)) : false;
        if (!$src) {
            continue;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $flat = imagecreatetruecolor($w, $h);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $src, 0, 0, 0, 0, $w, $h);
        $t = tempnam(sys_get_temp_dir(), 'f') ;
        imagejpeg($flat, $t, 92);
        $zip->addFile($t, pathinfo($r['file'], PATHINFO_FILENAME) . '.jpg');
        $tmpFiles[] = $t;
        $n++;
    }
    $zip->close();
    foreach ($tmpFiles as $t) {
        @unlink($t);
    }
    if ($n === 0) {
        @unlink($tmp);
        flash('No hay fotos que descargar.', 'err');
        redirect('/admin/fotos');
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . ($all ? 'fotos-todas' : 'fotos-fondo-irregular') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}
