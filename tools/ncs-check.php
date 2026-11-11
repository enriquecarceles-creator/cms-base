<?php
declare(strict_types=1);

/*
 * Batería de pruebas del conector de NCS Facturación, con los archivos REALES de pruebas/ (fuera de git).
 *
 *   php tools/ncs-check.php            → comprueba todo (sale con código 1 si algo falla, 3 si faltan los archivos)
 *   php tools/ncs-check.php --update   → reescribe tools/ncs-expected.json con lo que hay ahora (hazlo solo tras revisar
 *                                         un cambio de NCS y adaptar el conector)
 *
 * Qué comprueba: formato y firma de cada archivo (tablas y columnas con su tipo: si NCS cambia algo, falla), recuentos,
 * detección por firma, perfil de sistema de catálogo (columnas que usa, AMPLIACIOND* excluida), fotos por carpeta
 * ARTICULO_<código>, sincronización completa del ERP (clientes, facturas, líneas, tallas y colores, huellas, segunda
 * pasada sin cambios), copia de seguridad sin datos personales y que NINGÚN dato personal de los archivos aparece en
 * ningún archivo versionado ni en lo que se va a commitear.
 *
 * Privacidad: todo se ejecuta sobre una copia temporal de app/ y una base de datos temporal (se borran al terminar);
 * la salida solo lleva recuentos y nombres de tablas y columnas. tools/ncs-expected.json no tiene datos personales.
 */

$root = str_replace('\\', '/', dirname(__DIR__));
$pru = $root . '/pruebas';
$expFile = $root . '/tools/ncs-expected.json';
$update = in_array('--update', $argv, true);
$files = ['FALMACEN.MDB', 'FCUENTAS.MDB', 'FHISTORI.MDB'];
foreach ($files as $f) {
    if (!is_file($pru . '/' . $f)) {
        fwrite(STDERR, "NCS: falta pruebas/$f (los archivos reales de NCS no están en este equipo): no se puede comprobar el conector.\n");
        exit(3);
    }
}
@ini_set('memory_limit', '1G');
@set_time_limit(600);

/* ---------- Entorno aislado: copia de app/ y base de datos temporal ---------- */
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/ncs-check-' . getmypid();
$rm = function (string $d) use (&$rm): void {
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        is_dir("$d/$n") ? $rm("$d/$n") : @unlink("$d/$n");
    }
    @rmdir($d);
};
$cp = function (string $from, string $to) use (&$cp): void {
    @mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        is_dir("$from/$n") ? $cp("$from/$n", "$to/$n") : copy("$from/$n", "$to/$n");
    }
};
register_shutdown_function(function () use ($tmp, $rm) { $rm($tmp); });
$cp($root . '/app', $tmp . '/app');
@mkdir($tmp . '/data', 0777, true);
@mkdir($tmp . '/uploads', 0777, true);
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $tmp . '/app/bootstrap.php';
require_once $tmp . '/app/profiles.php';
require_once $tmp . '/app/photofolders.php';
require_once $tmp . '/app/auth.php';
require_once $tmp . '/app/erp.php';

/* ---------- Utilidades de la batería ---------- */
$fails = 0;
$total = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$fails, &$total): void {
    $total++;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '  OK     ' : '  FALLO  ') . $name . ($detail !== '' ? ' — ' . $detail : '') . "\n";
};
$section = fn(string $t) => print("\n== $t\n");
$typeName = [1 => 'sí/no', 2 => 'byte', 3 => 'entero', 4 => 'entero largo', 5 => 'moneda', 6 => 'real', 7 => 'doble', 8 => 'fecha', 9 => 'binario', 10 => 'texto', 11 => 'memo', 15 => 'guid', 16 => 'decimal'];

$watch = ['FALMACEN.MDB' => ['TART', 'TFAM'], 'FCUENTAS.MDB' => ['TCLIENTES', 'TPROVEED'], 'FHISTORI.MDB' => ['TCHVENTAS', 'TLHVENTAS']];
$readers = [];
$now = ['files' => [], 'photos' => [], 'erp' => []];
$section('Archivos de NCS: formato, firma del esquema y recuentos');
foreach ($watch as $file => $tables) {
    $m = $readers[$file] = new MdbReader($pru . '/' . $file);
    $all = $m->tables();
    $info = ['format' => $m->isJet3() ? 'Access 97 (Jet3)' : 'Access 2000+ (Jet4/ACE)', 'tables' => []];
    foreach ($tables as $t) {
        if (!isset($all[$t])) {
            $info['tables'][$t] = null;
            continue;
        }
        $def = $m->tdef($all[$t]);
        $cols = [];
        foreach ($def['cols'] as $c) {
            $cols[] = $c['name'] . ':' . ($typeName[$c['type']] ?? $c['type']) . ($c['type'] === 10 ? '(' . $c['size'] . ')' : '');
        }
        $info['tables'][$t] = ['rows' => $def['rows'], 'columns' => $cols];
    }
    $now['files'][$file] = $info;
}

/* ---------- Fotos por carpeta y emparejamiento con TART ---------- */
$codes = [];
foreach ($readers['FALMACEN.MDB']->rows('TART') as $r) {
    $codes[] = [trim((string)$r['CODIGO']), true];
}
$photoDir = $pru . '/FOTOS_ARTICULOS_001';
$folders = is_dir($photoDir) ? pf_dir_folders($photoDir) : [];
$idx = pf_index($codes);
$kinds = ['exact' => 0, 'loose' => 0, 'ambiguous' => 0, 'none' => 0];
foreach ($folders as $fo) {
    $kinds[pf_lookup($idx, (string)pf_folder_code($fo))[1]]++;
}
$now['photos'] = ['folders' => count($folders)] + $kinds;

/* ---------- ERP: sincronización completa en una base temporal ---------- */
$res = erp_stage_build($pru . '/FCUENTAS.MDB', $pru . '/FHISTORI.MDB', $pru . '/FALMACEN.MDB');
$job = ['status' => 'run', 'phase' => 'cust', 'cursor' => 0, 'has_art' => true, 'counts' => $res['counts']];
$guard = 0;
while (!erp_run($job, microtime(true) + 30)) {
    if (!empty($job['error']) || ++$guard > 200) {
        break;
    }
}
$db = db();
$one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
$now['erp'] = [
    'plan' => $res['counts'],
    'clientes' => $one('SELECT COUNT(*) FROM erp_customers'),
    'facturas' => $one('SELECT COUNT(*) FROM erp_invoices'),
    'lineas' => $one('SELECT COUNT(*) FROM erp_invoice_lines'),
    'total_cents' => $one('SELECT COALESCE(SUM(total_cents), 0) FROM erp_invoices WHERE annulled = 0'),
    'facturas_negativas' => $one('SELECT COUNT(*) FROM erp_invoices WHERE total_cents < 0'),
    'lineas_talla' => $one('SELECT COUNT(*) FROM erp_invoice_lines WHERE size_no > 0'),
    'lineas_talla_texto' => $one("SELECT COUNT(*) FROM erp_invoice_lines WHERE size_no > 0 AND size_txt <> ''"),
    'lineas_color' => $one('SELECT COUNT(*) FROM erp_invoice_lines WHERE color_no > 0'),
    'lineas_color_texto' => $one("SELECT COUNT(*) FROM erp_invoice_lines WHERE color_no > 0 AND color_txt <> ''"),
    'cliente_contado' => $res['retail_suggest'] !== '' ? 'propuesto' : 'ninguno',
    'facturas_iva_incluido' => $one('SELECT COUNT(*) FROM erp_invoices WHERE iva_incl = 1'),
];
// Desglose de IVA guardado (vat_json): Σ(base+cuota+recargo−retención) debe dar el total de cada factura (céntimos, ±2)
$vatOk = 0;
foreach ($db->query('SELECT total_cents, vat_json FROM erp_invoices')->fetchAll() as $r) {
    $s = 0;
    foreach ((array)json_decode((string)$r['vat_json'], true) as $row) {
        $s += (int)$row['base'] + (int)$row['cuota'] + (int)$row['rec'] - (int)$row['ret'];
    }
    $vatOk += abs($s - (int)$r['total_cents']) <= 2 ? 1 : 0;
}
$now['erp']['iva_desglose_cuadra'] = $vatOk;

// IVA de las facturas: qué índices se usan, a qué porcentaje corresponden (CUOTA/BASE) y si las sumas cuadran con TOTAL
$ivaOk = 0;
$ivaUsed = [];
$ivaPct = [];
foreach ($readers['FHISTORI.MDB']->rows('TCHVENTAS') as $h) {
    $sum = 0.0;
    for ($i = 0; $i < 10; $i++) {
        $bs = (float)$h["BASE$i"];
        $cu = (float)$h["CUOTA$i"];
        $sum += $bs + $cu + (float)$h["RECARGO$i"] - (float)$h["RETENCION$i"];
        if ($bs != 0.0) {
            $ivaUsed[$i] = true;
            $p = (string)round($cu / $bs * 100);
            $ivaPct[$i][$p] = ($ivaPct[$i][$p] ?? 0) + 1;
        }
    }
    $ivaOk += abs($sum - (float)$h["TOTAL"]) <= 0.02 ? 1 : 0;
}
ksort($ivaUsed);
$dom = [];
foreach ($ivaPct as $i => $m) {
    arsort($m);
    $dom[(string)$i] = (int)array_key_first($m);
}
$now["erp"]["iva_total_cuadra"] = $ivaOk;
$now["erp"]["iva_indices_usados"] = array_keys($ivaUsed);
$now["erp"]["iva_porcentaje_por_indice"] = $dom;

if ($update) {
    $now['ncs_version'] = is_file($expFile) ? (json_decode((string)file_get_contents($expFile), true)['ncs_version'] ?? 'sin anotar') : 'sin anotar';
    $now['note'] = 'Contrato del conector de NCS. Sin datos personales: solo nombres de tablas y columnas, tipos y recuentos. Regenerar con «php tools/ncs-check.php --update» tras revisar un cambio de NCS.';
    file_put_contents($expFile, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "tools/ncs-expected.json actualizado con lo que hay ahora en pruebas/.\n";
    exit(0);
}
$exp = is_file($expFile) ? json_decode((string)file_get_contents($expFile), true) : null;
if (!is_array($exp)) {
    fwrite(STDERR, "NCS: falta tools/ncs-expected.json (créalo con --update tras revisar los datos).\n");
    exit(3);
}

/* 1. Formato, firma y recuentos */
foreach ($now['files'] as $file => $info) {
    $e = $exp['files'][$file] ?? null;
    $check("$file: formato", $e && $info['format'] === $e['format'], $info['format']);
    foreach ($info['tables'] as $t => $d) {
        $et = $e['tables'][$t] ?? null;
        if ($d === null) {
            $check("$file · $t: existe", false, 'la tabla ya no está');
            continue;
        }
        $added = array_values(array_diff($d['columns'], (array)($et['columns'] ?? [])));
        $gone = array_values(array_diff((array)($et['columns'] ?? []), $d['columns']));
        $check("$file · $t: firma del esquema (" . count($d['columns']) . ' columnas)', !$added && !$gone,
            ($added ? 'columnas nuevas o cambiadas: ' . implode(', ', array_slice($added, 0, 8)) . ' ' : '') . ($gone ? 'columnas que faltan o cambiaron: ' . implode(', ', array_slice($gone, 0, 8)) : ''));
        $check("$file · $t: filas", $et && $d['rows'] === $et['rows'], (string)$d['rows'] . ($et ? ' (esperadas ' . $et['rows'] . ')' : ''));
    }
}

/* 2. Detección por firma de tablas */
$section('Detección automática por firma de tablas');
$kind = function (array $tables): ?string {
    $has = fn(string ...$t) => !array_diff($t, array_keys($tables));
    return $has('TART', 'TFAM') ? 'almacen' : ($has('TCLIENTES') ? 'cuentas' : ($has('TCHVENTAS', 'TLHVENTAS') ? 'historico' : null));
};
foreach (['FALMACEN.MDB' => 'almacen', 'FCUENTAS.MDB' => 'cuentas', 'FHISTORI.MDB' => 'historico'] as $f => $want) {
    $got = $kind($readers[$f]->tables());
    $check("$f se reconoce como «{$want}»", $got === $want, (string)$got);
}

/* 3. Perfil de sistema del catálogo */
$section('Perfil de sistema «NCS Facturación — Almacén»');
profiles_ensure_system();
$tables = [];
foreach ($readers['FALMACEN.MDB']->tables() as $t => $pg) {
    $tables[$t] = $readers['FALMACEN.MDB']->columns($pg);
}
$compat = profiles_compatible($tables, 'catalog');
$p = $compat ? reset($compat) : null;
$check('se aplica solo a estas tablas (un único perfil NCS)', count($compat) === 1 && $p && str_starts_with((string)$p['name'], 'NCS Facturación'), $p ? (string)$p['name'] : 'ninguno');
if ($p) {
    $map = $p['mapping'];
    $used = [$map['table'] => array_filter([$map['ref'], $map['name'], $map['family'], $map['visible'], $map['img']])];
    foreach ($map['fields'] as $col) {
        $used['TART'][] = $col;
    }
    $used[$map['fam_table']] = array_merge($used[$map['fam_table']] ?? [], [$map['fam_id'], $map['fam_name']]);
    $missing = [];
    foreach ($used as $t => $cols) {
        foreach ($cols as $c) {
            if (profiles_column($tables[$t] ?? [], (string)$c) === null) {
                $missing[] = "$t.$c";
            }
        }
    }
    $check('todas las columnas que usa el perfil existen', !$missing, implode(', ', $missing));
    $forbidden = array_values(array_filter($tables['TART'], fn($c) => profiles_excluded(['AMPLIACIOND*'], (string)$c)));
    $check('AMPLIACIOND* y sus traducciones están prohibidas', in_array('AMPLIACIOND', $forbidden, true) && count($forbidden) >= 1 && in_array('AMPLIACIOND*', $p['rules']['exclude_columns'], true), count($forbidden) . ' columnas prohibidas');
}

/* 4. Fotos por carpeta */
$section('Fotos por carpeta ARTICULO_<código>');
foreach (['folders' => 'carpetas', 'exact' => 'emparejadas exactas', 'loose' => 'emparejadas sin signos', 'ambiguous' => 'ambiguas', 'none' => 'sin artículo'] as $k => $label) {
    $check("$label", $now['photos'][$k] === ($exp['photos'][$k] ?? null), $now['photos'][$k] . ' (esperadas ' . ($exp['photos'][$k] ?? '?') . ')');
}

/* 5. Sincronización del ERP */
$section('Sincronización del ERP (base temporal)');
$check('sin errores y termina', empty($job['error']) && $guard <= 200);
foreach (['clientes', 'facturas', 'lineas', 'total_cents', 'facturas_negativas', 'lineas_talla', 'lineas_talla_texto', 'lineas_color', 'lineas_color_texto', 'cliente_contado', 'iva_total_cuadra', 'iva_indices_usados', 'iva_porcentaje_por_indice', 'facturas_iva_incluido', 'iva_desglose_cuadra'] as $k) {
    $check("ERP · $k", $now['erp'][$k] === ($exp['erp'][$k] ?? null), json_encode($now['erp'][$k]) . ' (esperado ' . json_encode($exp['erp'][$k] ?? '?') . ')');
}
$check('el plan contaba lo mismo que se guardó', $res['counts']['cust']['new'] === $now['erp']['clientes'] && $res['counts']['inv']['new'] === $now['erp']['facturas'] && $res['counts']['lines'] === $now['erp']['lineas']);
$check('IVA: en todas las facturas Σ(BASE+CUOTA+RECARGO−RETENCION) = TOTAL (±0,02)', $now['erp']['iva_total_cuadra'] === $now['erp']['facturas'], $now['erp']['iva_total_cuadra'] . ' de ' . $now['erp']['facturas']);
$res2 = erp_stage_build($pru . '/FCUENTAS.MDB', $pru . '/FHISTORI.MDB', $pru . '/FALMACEN.MDB');
$c2 = $res2['counts'];
$check('segunda pasada: todo «sin cambios» (idempotente)', $c2['cust']['new'] + $c2['cust']['changed'] === 0 && $c2['inv']['new'] + $c2['inv']['changed'] === 0, "clientes {$c2['cust']['same']} · facturas {$c2['inv']['same']}");
erp_job_clear();

/* 5b. Factura como documento: serie, nombres de pago y la plantilla se pinta con todo su contenido */
$section('Factura como documento');
$check('serie por defecto = letras iniciales del número', erp_serie('A00012') === 'A' && erp_serie('000123') === '' && erp_serie('V00001') === 'V');
save_setting('erp_serie_mode', 'n');
save_setting('erp_serie_n', '2');
setting('', '', true);
$check('serie parametrizable: primeros N caracteres', erp_serie('A1B23') === 'A1');
save_setting('erp_serie_mode', 'ninguna');
setting('', '', true);
$check('serie parametrizable: ninguna', erp_serie('A00012') === '');
save_setting('erp_serie_mode', 'letras');
save_setting('erp_pay_names', json_encode(['03' => 'Transferencia']));
setting('', '', true);
$check('nombre de forma de pago editable', erp_pay_label('03') === 'Transferencia (03)' && erp_pay_label('77') === 'F. pago 77' && erp_pay_label('') === '');
$ds = doc_settings();
$check('ajustes de diseño con valores por defecto válidos', $ds['template'] === 'classic' && preg_match('/^#[0-9a-f]{6}$/i', $ds['color']) === 1 && count($ds['cols']) === 9 && $ds['margin_mm'] === 15);
$pick = $db->query("SELECT inv_number FROM erp_invoices WHERE vat_json LIKE '%\"rec\":%' AND vat_json NOT LIKE '%\"rec\":0%' ORDER BY id LIMIT 1")->fetchColumn();
$pickNeg = $db->query('SELECT inv_number FROM erp_invoices WHERE total_cents < 0 ORDER BY id LIMIT 1')->fetchColumn();
$db->prepare('UPDATE erp_invoices SET annulled = 1 WHERE inv_number = ?')->execute([$pickNeg]);
foreach ([[$pick, 'con recargo', false], [$pickNeg, 'negativa y anulada', true]] as [$num, $label, $stamp]) {
    $vars = erp_invoice_doc_vars((string)$num);
    ob_start();
    render('documents/invoice_classic', $vars, null);
    $html = (string)ob_get_clean();
    $okTotal = str_contains($html, '>' . erp_money((int)$vars['inv']['total_cents']) . '<');
    $okLines = substr_count($html, 'class="num"') >= count($vars['lines']);
    $check("documento «{$label}»: total, líneas, sello y pie legal", $okTotal && $okLines && str_contains($html, 'class="stamp"') === $stamp && str_contains($html, 'Copia informativa'));
}
$db->prepare('UPDATE erp_invoices SET annulled = 0 WHERE inv_number = ?')->execute([$pickNeg]);

/* 6. Copia de seguridad sin datos personales */
$section('Copia de seguridad sin datos personales');
$sample = (string)$db->query("SELECT name FROM erp_customers WHERE LENGTH(name) > 12 ORDER BY id LIMIT 1")->fetchColumn();
$bk = $tmp . '/data/backup-test.sqlite';
$db->exec("VACUUM INTO '" . str_replace("'", "''", $bk) . "'");
auth_scrub_backup($bk, false);
$bytes = (string)file_get_contents($bk);
@unlink($bk);
$check('la copia por defecto no lleva clientes ni facturas (ni restos en el archivo)', $sample !== '' && !str_contains($bytes, $sample));

/* 7. Ningún dato personal en el repositorio */
$section('Datos personales fuera del repositorio');
$personal = [];
foreach ($readers['FCUENTAS.MDB']->rows('TCLIENTES') as $r) {
    foreach (['NIF' => 8, 'EMAIL' => 8, 'TELEFONO1' => 9, 'TELEFONO2' => 9, 'NOMBRE' => 14, 'NCOMERCIAL' => 14, 'DOMICILIO' => 14] as $col => $min) {
        $v = trim((string)($r[$col] ?? ''));
        if (strlen($v) >= $min) {
            $personal[$v] = true;
        }
    }
}
$personal = array_map('strval', array_keys($personal)); // las claves numéricas pasan a ser enteros
$git = fn(string $args) => (string)shell_exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>&1');
$tracked = array_values(array_filter(explode("\n", trim($git('ls-files')))));
$bad = array_filter($tracked, fn($f) => preg_match('#^pruebas/|\.(mdb|accdb|sqlite|sqlite3|db|csv)$#i', $f));
$check('ningún .mdb, .sqlite, .csv ni pruebas/ está versionado', !$bad, implode(', ', array_slice($bad, 0, 5)));
/** Busca valores en el contenido de unos archivos (rutas absolutas). Devuelve los archivos con alguna coincidencia. */
$scan = function (array $paths, array $values): array {
    $hits = [];
    foreach ($paths as $path) {
        if (!is_file($path) || filesize($path) > 3_000_000 || preg_match('/\.(png|jpe?g|gif|webp|zip|ico|woff2?)$/i', $path)) {
            continue;
        }
        $c = (string)file_get_contents($path);
        foreach ($values as $v) {
            if (str_contains($c, $v)) {
                $hits[] = $path;
                break;
            }
        }
    }
    return $hits;
};
// Autoprueba del detector con un valor INVENTADO en la carpeta temporal (jamás se escribe un dato real en el proyecto)
$fake = 'ZZ-DATO-PERSONAL-INVENTADO-' . getmypid();
file_put_contents($tmp . '/data/autoprueba.txt', "contenido con $fake dentro\n");
$check('el detector encuentra un dato inventado plantado (autoprueba)', $scan([$tmp . '/data/autoprueba.txt'], [$fake]) !== [] && $scan([$tmp . '/data/autoprueba.txt'], ['otro-valor']) === []);
$hits = $scan(array_map(fn($f) => $root . '/' . $f, $tracked), $personal);
$check('ningún dato personal de los archivos de NCS aparece en archivos versionados (' . count($personal) . ' valores buscados)', !$hits, implode(', ', array_slice(array_map(fn($p) => substr($p, strlen($root) + 1), $hits), 0, 5)));
$diff = $git('diff --cached');
$dh = false;
foreach ($personal as $v) {
    if (str_contains($diff, $v)) {
        $dh = true;
        break;
    }
}
$check('tampoco en lo que se va a commitear', !$dh);

echo "\nNCS: $total pruebas, $fails " . ($fails === 1 ? 'fallo' : 'fallos') . ($fails ? " — el conector NO se da por terminado.\n" : ".\n");
exit($fails ? 1 : 0);
