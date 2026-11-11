<?php
declare(strict_types=1);

/*
 * Fotos subidas por FTP: copia JPG/PNG/WebP a la carpeta uploads/ y desde el panel
 * se enlazan a sus artículos (reconoce el nombre de la foto original o del artículo).
 */

function ftp_norm(string $s): string
{
    $s = strtolower(pathinfo($s, PATHINFO_FILENAME));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = preg_replace('/(photoroom|removebg|preview|pixelcut|export|scaled|copia|copy)/', '', $s);
    $s = preg_replace('/-\d+x\d+$/', '', $s);
    return preg_replace('/[^a-z0-9]+/', '', $s);
}

/** Huella visual de una foto: recorta al producto, reduce a 10×10 y guarda colores + proporción. */
function ftp_sig(string $path): ?array
{
    $src = @imagecreatefromstring((string)@file_get_contents($path));
    if (!$src) {
        return null;
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    $w = 96;
    $h = max(1, (int)round($sh * $w / max(1, $sw)));
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
    imagecopyresampled($im, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
    $x0 = $w; $x1 = -1; $y0 = $h; $y1 = -1;
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $c = imagecolorat($im, $x, $y);
            if (255 - min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255) > 30) {
                $x0 = min($x0, $x); $x1 = max($x1, $x); $y0 = min($y0, $y); $y1 = max($y1, $y);
            }
        }
    }
    if ($x1 - $x0 < 6 || $y1 - $y0 < 6) {
        return null;
    }
    $cw = $x1 - $x0 + 1;
    $ch = $y1 - $y0 + 1;
    $t = imagecreatetruecolor(10, 10);
    imagecopyresampled($t, $im, 0, 0, $x0, $y0, 10, 10, $cw, $ch);
    $v = [round($cw / $ch, 3)];
    for ($y = 0; $y < 10; $y++) {
        for ($x = 0; $x < 10; $x++) {
            $c = imagecolorat($t, $x, $y);
            array_push($v, ($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
        }
    }
    return $v;
}

function ftp_dist(array $a, array $b): float
{
    $d = 0.0;
    for ($i = 1; $i < 301; $i++) {
        $d += abs($a[$i] - $b[$i]);
    }
    $color = $d / 300 / 255;                                   // 0..1
    $ratio = abs(log(max(0.05, $a[0]) / max(0.05, $b[0])));    // 0 = misma proporción
    return $color + 0.15 * min(1.5, $ratio);
}

/** Huellas de las fotos actuales de los artículos (con caché en data/sigs.json). */
function ftp_existing_sigs(): array
{
    $cacheFile = ROOT . '/data/sigs.json';
    $cache = is_file($cacheFile) ? (json_decode((string)@file_get_contents($cacheFile), true) ?: []) : [];
    $out = [];
    $changed = false;
    foreach (db()->query('SELECT product_id, file FROM product_images ORDER BY product_id, sort')->fetchAll() as $r) {
        $path = UPLOAD_DIR . '/' . thumb_name($r['file']);
        if (!is_file($path)) {
            $path = UPLOAD_DIR . '/' . $r['file'];
        }
        if (!is_file($path)) {
            continue;
        }
        $mt = (int)filemtime($path);
        $k = $r['file'];
        if (!isset($cache[$k]) || ($cache[$k]['mt'] ?? 0) !== $mt) {
            $cache[$k] = ['mt' => $mt, 'sig' => ftp_sig($path)];
            $changed = true;
        }
        if ($cache[$k]['sig']) {
            $out[] = ['pid' => (int)$r['product_id'], 'file' => $r['file'], 'sig' => $cache[$k]['sig']];
        }
    }
    if ($changed) {
        @file_put_contents($cacheFile, json_encode($cache), LOCK_EX);
    }
    return $out;
}

/** Archivos de imagen sueltos en uploads/ (y uploads/entrada/) que aún no son fotos de la web. */
function ftp_candidates(): array
{
    $out = [];
    foreach ([UPLOAD_DIR, UPLOAD_DIR . '/entrada'] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        foreach (scandir($dir) ?: [] as $f) {
            $path = $dir . '/' . $f;
            if (!is_file($path) || !preg_match('/\.(jpe?g|png|webp)$/i', $f)) {
                continue;
            }
            if (preg_match('/^[a-f0-9]{16}(-t)?\.(webp|png)$/', $f) || preg_match('/-t\.(webp|png)$/', $f)) {
                continue; // fotos ya gestionadas por la web
            }
            $out[] = ['name' => $f, 'dir' => $dir === UPLOAD_DIR ? '' : 'entrada/', 'path' => $path, 'norm' => ftp_norm($f)];
        }
    }
    usort($out, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    return $out;
}

/** Mejor artículo para cada candidato: [product_id|null, motivo]. */
function ftp_match(array $cand, array $imgs, array $prods): array
{
    $n = $cand['norm'];
    if ($n === '') {
        return [null, ''];
    }
    $best = [0, null, ''];
    foreach ($imgs as $im) {
        $s = ftp_norm(basename((string)$im['src']));
        if ($s === '') {
            continue;
        }
        $score = $s === $n ? 3 : ((strlen($s) >= 6 && strlen($n) >= 6 && (str_contains($n, $s) || str_contains($s, $n))) ? 2 : 0);
        if ($score > $best[0]) {
            $best = [$score, (int)$im['product_id'], 'foto original'];
        }
    }
    if ($best[0] < 2) {
        foreach ($prods as $p) {
            $s = ftp_norm((string)$p['slug']);
            if (strlen($s) >= 5 && (str_contains($n, $s) || $n === $s)) {
                $best = [1, (int)$p['id'], 'nombre'];
                break;
            }
        }
    }
    return [$best[1], $best[2]];
}

/** Mapa nombre-sin-extensión de las fotos de la web => [id de imagen, id de artículo, archivo]. */
function ftp_stem_map(): array
{
    $map = [];
    foreach (db()->query('SELECT id, product_id, file FROM product_images')->fetchAll() as $r) {
        $map[strtolower(pathinfo($r['file'], PATHINFO_FILENAME))] = [(int)$r['id'], (int)$r['product_id'], $r['file']];
    }
    return $map;
}

function ftp_cand_stem(array $c): string
{
    return strtolower(preg_replace('/-t$/', '', pathinfo($c['name'], PATHINFO_FILENAME)));
}

/** Sustituye en su sitio la foto de la web que tiene el mismo nombre. */
function ftp_replace_in_place(array $c, array $hit): bool
{
    [$imgId, $pid, $old] = $hit;
    $new = save_image(['name' => $c['name'], 'tmp_name' => $c['path'], 'error' => UPLOAD_ERR_OK, 'size' => 0], 1400, false);
    $db = db();
    $db->prepare('UPDATE product_images SET file = ?, enh = \'ok\' WHERE id = ?')->execute([$new, $imgId]);
    $db->prepare('UPDATE categories SET cover = ? WHERE cover = ?')->execute([$new, $old]);
    delete_image($old);
    sync_gallery($pid);
    ftp_archive($c);
    return true;
}

function ftp_archive(array $c): void
{
    $dest = ENH_ORIG_DIR . '/ftp';
    if (!is_dir($dest)) {
        @mkdir($dest, 0755, true);
    }
    if (!@rename($c['path'], $dest . '/' . $c['name'])) {
        @unlink($c['path']);
    }
}

/** Fotos de la web cuyo archivo ya no existe en uploads/. */
function ftp_broken(): array
{
    $out = [];
    foreach (db()->query('SELECT id, product_id, file, src FROM product_images')->fetchAll() as $r) {
        if (!is_file(UPLOAD_DIR . '/' . $r['file']) || !is_file(UPLOAD_DIR . '/' . thumb_name($r['file']))) {
            $out[] = $r;
        }
    }
    return $out;
}

function admin_ftp(): void
{
    $db = db();
    $prods = $db->query('SELECT id, name, ref, slug FROM products ORDER BY name COLLATE NOCASE')->fetchAll();
    $imgs = $db->query("SELECT product_id, src FROM product_images WHERE src <> ''")->fetchAll();

    $stems = ftp_stem_map();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repair') {
        csrf_check();
        $pids = [];
        foreach (ftp_broken() as $r) {
            $db->prepare('DELETE FROM product_images WHERE id = ?')->execute([$r['id']]);
            delete_image($r['file']);
            $pids[$r['product_id']] = true;
        }
        foreach (array_keys($pids) as $pid) {
            sync_gallery((int)$pid);
        }
        require_once __DIR__ . '/history.php';
        history_add('fotos', 'fotos', 'Fotos por FTP', 'ok', count($pids) . ' artículo' . (count($pids) === 1 ? '' : 's') . ' limpiado' . (count($pids) === 1 ? '' : 's') . ' de fotos rotas');
        flash(count($pids) . ' artículo(s) limpiados de fotos rotas. Ahora puedes volver a traerlas desde Importar.');
        redirect('/admin/ftp');
    }

    // Enlazado automático de las fotos con el mismo nombre que una foto de la web (por tandas)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auto') {
        csrf_check();
        save_setting('ftp_auto', '1');
        save_setting('ftp_done', '0');
        save_setting('ftp_fail', '0');
        redirect('/admin/ftp');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stop') {
        csrf_check();
        save_setting('ftp_auto', '0');
        redirect('/admin/ftp');
    }
    if (db_setting('ftp_auto') === '1') {
        $t0 = microtime(true);
        $done = (int)db_setting('ftp_done');
        $fail = (int)db_setting('ftp_fail');
        $left = 0;
        foreach (ftp_candidates() as $c) {
            $hit = $stems[ftp_cand_stem($c)] ?? null;
            if (!$hit) {
                continue;
            }
            if (microtime(true) - $t0 > 12) {
                $left++;
                continue;
            }
            try {
                ftp_replace_in_place($c, $hit);
                $done++;
            } catch (Throwable $e) {
                @rename($c['path'], $c['path'] . '.error');
                $fail++;
            }
        }
        save_setting('ftp_done', (string)$done);
        save_setting('ftp_fail', (string)$fail);
        if ($left === 0) {
            save_setting('ftp_auto', '0');
            require_once __DIR__ . '/history.php';
            history_add('fotos', 'fotos', 'Fotos por FTP (enlazado automático)', $fail ? 'parcial' : 'ok', $done . ' foto' . ($done === 1 ? '' : 's') . ' enlazada' . ($done === 1 ? '' : 's') . ($fail ? ', ' . $fail . ' con error' : ''));
            flash($done . ' fotos enlazadas.' . ($fail ? ' ' . $fail . ' con error (se renombraron a .error).' : ''), $fail ? 'err' : 'ok');
            redirect('/admin/ftp');
        }
        admin_render('ftp', ['rows' => [], 'prods' => [], 'broken' => 0, 'running' => true, 'done' => $done, 'left' => $left, 'exact' => 0, 'title' => 'Fotos subidas por FTP']);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        @set_time_limit(300);
        $replace = ($_POST['mode'] ?? 'replace') === 'replace';
        $byName = [];
        foreach (ftp_candidates() as $c) {
            $byName[$c['dir'] . $c['name']] = $c;
        }
        $plan = [];
        $inplace = 0;
        $errors = [];
        foreach ((array)($_POST['pid'] ?? []) as $key => $pid) {
            $key = (string)$key;
            $pid = (int)$pid;
            if ($pid > 0 && isset($byName[$key])) {
                $hit = $stems[ftp_cand_stem($byName[$key])] ?? null;
                if ($hit && $hit[1] === $pid) {
                    try {
                        ftp_replace_in_place($byName[$key], $hit);
                        $inplace++;
                    } catch (Throwable $e) {
                        $errors[] = $byName[$key]['name'] . ': ' . $e->getMessage();
                    }
                    continue;
                }
                $plan[$pid][] = $byName[$key];
            }
        }
        $done = $inplace;
        foreach ($plan as $pid => $list) {
            $ex = $db->prepare('SELECT 1 FROM products WHERE id = ?');
            $ex->execute([$pid]);
            if (!$ex->fetchColumn()) {
                continue;
            }
            $newFiles = [];
            foreach ($list as $c) {
                try {
                    $newFiles[] = [save_image(['name' => $c['name'], 'tmp_name' => $c['path'], 'error' => UPLOAD_ERR_OK, 'size' => 0], 1400, false), $c];
                } catch (Throwable $e) {
                    $errors[] = $c['name'] . ': ' . $e->getMessage();
                }
            }
            if (!$newFiles) {
                continue;
            }
            if ($replace) {
                foreach (gallery_for($pid) as $g) {
                    $db->prepare('DELETE FROM product_images WHERE id = ?')->execute([$g['id']]);
                    delete_image($g['file']);
                }
            }
            $first = true;
            foreach ($newFiles as [$file, $c]) {
                if ($first && !$replace) {
                    // se añade y pasa a ser la portada
                    $db->prepare('UPDATE product_images SET sort = sort + 1 WHERE product_id = ?')->execute([$pid]);
                    $db->prepare('INSERT INTO product_images (product_id, file, src, sort, enh) VALUES (?,?,?,0,?)')->execute([$pid, $file, '', enh_note($file)]);
                    sync_gallery($pid);
                } else {
                    add_product_image($pid, $file);
                }
                $first = false;
                $dest = ENH_ORIG_DIR . '/ftp';
                if (!is_dir($dest)) {
                    @mkdir($dest, 0755, true);
                }
                if (!@rename($c['path'], $dest . '/' . $c['name'])) {
                    @unlink($c['path']);
                }
                $done++;
            }
        }
        require_once __DIR__ . '/history.php';
        history_add('fotos', 'fotos', 'Fotos por FTP', $errors ? ($done > 0 ? 'parcial' : 'error') : 'ok', $done . ' foto' . ($done === 1 ? '' : 's') . ' enlazada' . ($done === 1 ? '' : 's') . ($errors ? ', ' . count($errors) . ' con error' : ''));
        flash($done . ' foto' . ($done === 1 ? '' : 's') . ' enlazada' . ($done === 1 ? '' : 's') . '.' . ($errors ? ' Fallos: ' . implode(' · ', array_slice($errors, 0, 5)) : ''), $errors ? 'err' : 'ok');
        redirect('/admin/ftp');
    }

    @set_time_limit(300);
    $cands = ftp_candidates();
    $nonExact = array_filter($cands, fn($c) => !isset($stems[ftp_cand_stem($c)]));
    $existing = $nonExact ? ftp_existing_sigs() : [];
    $covers = [];
    foreach ($db->query('SELECT id, image FROM products')->fetchAll() as $p) {
        $covers[(int)$p['id']] = $p['image'];
    }
    $rows = [];
    $exact = 0;
    foreach ($cands as $c) {
        $hit = $stems[ftp_cand_stem($c)] ?? null;
        if ($hit) {
            $rows[] = $c + ['pid' => $hit[1], 'why' => 'misma foto (mismo nombre)', 'cur' => $hit[2], 'exact' => true];
            $exact++;
            continue;
        }
        [$pid, $why] = ftp_match($c, $imgs, $prods);
        $cur = $pid ? ($covers[$pid] ?? null) : null;
        if (!$pid && $existing) {
            $sig = ftp_sig($c['path']);
            if ($sig) {
                $bestD = 9.0;
                foreach ($existing as $e) {
                    $d = ftp_dist($sig, $e['sig']);
                    if ($d < $bestD) {
                        $bestD = $d;
                        $pid = $e['pid'];
                        $cur = $e['file'];
                    }
                }
                if ($bestD < 0.22) {
                    $why = 'parecido a la foto actual';
                } else {
                    $pid = null;
                    $cur = null;
                }
            }
        }
        $rows[] = $c + ['pid' => $pid, 'why' => $why, 'cur' => $cur];
    }
    admin_render('ftp', ['rows' => $rows, 'prods' => $prods, 'exact' => $exact, 'running' => false, 'broken' => count(ftp_broken()), 'title' => 'Fotos subidas por FTP']);
}
