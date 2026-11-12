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
    'cobros_precargables' => erp_preload_candidates(),
    'facturas_origen_ncs' => $one("SELECT COUNT(*) FROM erp_invoices WHERE origin = 'ncs'"),
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
foreach (['clientes', 'facturas', 'lineas', 'total_cents', 'facturas_negativas', 'lineas_talla', 'lineas_talla_texto', 'lineas_color', 'lineas_color_texto', 'cliente_contado', 'iva_total_cuadra', 'iva_indices_usados', 'iva_porcentaje_por_indice', 'facturas_iva_incluido', 'iva_desglose_cuadra', 'cobros_precargables', 'facturas_origen_ncs'] as $k) {
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

/* 5c. Formulario de factura: datos propios del CMS (nunca los de NCS) */
$section('Formulario de factura (datos propios del CMS)');
$check('etiquetas: se limpian, sin repetir y máximo 10', erp_tags_list('Reclamar, <b>urgente</b>, reclamar, a,b,c,d,e,f,g,h,i,j,k') === ['Reclamar', 'urgente', 'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
$check('solo las facturas con origen «cms» son editables; las de NCS, no', erp_invoice_editable(['origin' => 'cms']) && !erp_invoice_editable(['origin' => 'ncs']) && !erp_invoice_editable([]));
$check('fecha de cobro: ida y vuelta del campo de fecha', erp_day_from_input('2022-03-15') === 20220315 && erp_day_from_input('2022-02-31') === 0 && erp_day_to_input(20220315) === '2022-03-15');
$cand = erp_preload_candidates();
$done = erp_preload_paid();
$check('precarga de cobros: marca las candidatas una sola vez', $done === $cand && $one('SELECT COUNT(*) FROM erp_invoice_state WHERE paid = 1') === $cand && $one("SELECT COUNT(*) FROM erp_invoice_events WHERE kind = 'cobro'") === $cand && erp_preload_candidates() === 0, "$done facturas");
$check('precarga: cobradas en la fecha de la venta', $one('SELECT COUNT(*) FROM erp_invoice_state s JOIN erp_invoices i ON i.id = s.invoice_id WHERE s.paid_day <> i.inv_day') === 0);
$firstId = (int)$db->query('SELECT id FROM erp_invoices ORDER BY id LIMIT 1')->fetchColumn();
erp_event($firstId, 'nota', 'prueba', 'batería', 0);
$check('el historial anota hechos con su usuario', $one("SELECT COUNT(*) FROM erp_invoice_events WHERE invoice_id = $firstId AND kind = 'nota' AND username = 'batería'") === 1);
/* Enviar factura por email (B3b): enlace seguro, correo HTML con la factura real, sello de anulada */
$section('Enviar factura por email (enlace seguro)');
$realNum = (string)$db->query('SELECT inv_number FROM erp_invoices WHERE annulled = 0 AND total_cents > 0 ORDER BY id LIMIT 1')->fetchColumn();
$lk = erp_link_create($firstId, 'prueba@ejemplo.com', 30, 'batería');
$check('enlace: código de 64 hex y solo se guarda su huella sha256', preg_match('/^[a-f0-9]{64}$/', $lk['token']) === 1 && $one("SELECT COUNT(*) FROM erp_invoice_links WHERE token_hash = '" . hash('sha256', $lk['token']) . "'") === 1 && $one("SELECT COUNT(*) FROM erp_invoice_links WHERE token_hash = '" . $lk['token'] . "'") === 0);
$found = erp_link_find($lk['token']);
$check('enlace: se encuentra por su código y está vigente', $found !== null && (int)$found['id'] === $lk['id'] && erp_link_state($found) === 'vigente' && erp_link_find(str_repeat('a', 64)) === null);
$check('enlace: caduca a los 30 días', $lk['expires'] - time() > 29 * 86400 && $lk['expires'] - time() <= 30 * 86400);
$check('enlace: rango de días 7–365 (30 por defecto)', erp_link_days_default() === 30 && ERP_LINK_DAYS_MIN === 7 && ERP_LINK_DAYS_MAX === 365);
$check('enlace: no se anula uno de otra factura', erp_link_revoke($firstId + 1, $lk['id']) === false && erp_link_state(erp_link_find($lk['token'])) === 'vigente');
$check('enlace: anular lo deja anulado, lo anota y no se repite', erp_link_revoke($firstId, $lk['id']) === true && erp_link_state(erp_link_find($lk['token'])) === 'anulado' && erp_link_revoke($firstId, $lk['id']) === false && $one("SELECT COUNT(*) FROM erp_invoice_events WHERE invoice_id = $firstId AND kind = 'enlace_revocado'") === 1);
$lk2 = erp_link_create($firstId, 'prueba@ejemplo.com', 7, 'batería');
$db->prepare('UPDATE erp_invoice_links SET expires_at = ? WHERE id = ?')->execute([time() - 5, $lk2['id']]);
$check('enlace: caducado', erp_link_state(erp_link_find($lk2['token'])) === 'caducado');
$vars = erp_invoice_doc_vars($realNum);
$html = $vars ? erp_invoice_email_html($vars, "Hola\nMensaje de prueba", 'https://ejemplo.test/factura/' . $lk['token'], time() + 86400) : '';
$check('correo HTML con una factura real: número, total, enlace y líneas', $vars !== null && str_contains($html, $realNum) && str_contains($html, erp_money((int)$vars['inv']['total_cents'])) && str_contains($html, '/factura/' . $lk['token']) && substr_count($html, '<tr>') >= 3, $realNum);
$check('correo HTML: sin enlaces ni datos del panel y sin sello si no está anulada', !str_contains($html, '/admin') && !str_contains($html, $vars['d']['stamp']));
if ($vars) {
    $vars['inv']['annulled'] = 1;
    $htmlAnn = erp_invoice_email_html($vars, 'x', 'https://ejemplo.test/factura/x', time() + 86400);
    $check('correo HTML de una factura anulada lleva el sello', str_contains($htmlAnn, $vars['d']['stamp']));
    $txt = erp_invoice_email_text($vars, 'x', 'https://ejemplo.test/factura/x', time() + 86400);
    $check('correo de texto de una factura anulada lleva el sello', str_contains($txt, $vars['d']['stamp']));
}
$check('correo de envío: nunca el del cliente de contado', erp_send_default_to(['email_to' => ''], ['email' => 'x@ejemplo.com'], true) === '' && erp_send_default_to(['email_to' => ''], ['email' => 'x@ejemplo.com'], false) === 'x@ejemplo.com' && erp_send_default_to(['email_to' => 'a@ejemplo.com'], ['email' => 'x@ejemplo.com'], false) === 'a@ejemplo.com');
$res3 = erp_stage_build($pru . '/FCUENTAS.MDB', $pru . '/FHISTORI.MDB', $pru . '/FALMACEN.MDB');
erp_job_clear();
$check('sincronizar de nuevo no toca el estado propio (cobros, notas, historial)', $one('SELECT COUNT(*) FROM erp_invoice_state') === $cand && $res3['counts']['inv']['new'] + $res3['counts']['inv']['changed'] === 0);

/* 5d. Datos: Historial, Exportar y Perfiles (con los datos reales; nada de esto se imprime) */
$section('Datos: Historial, Exportar y Perfiles');
require_once $tmp . '/app/import.php';
require_once $tmp . '/app/history.php';
require_once $tmp . '/app/export.php';
require_once $tmp . '/app/profiles.php';
$h0 = $one('SELECT COUNT(*) FROM data_history');
history_add('importacion', 'articulos', 'Prueba', 'ok', '3 nuevos', 'batería');
$check('historial: anota con usuario, tipo y resultado', $one("SELECT COUNT(*) FROM data_history WHERE username = 'batería' AND kind = 'importacion' AND what = 'articulos' AND result = 'ok'") === 1);
$ins = $db->prepare('INSERT INTO data_history (created_at, user_id, username, kind, what, origin, result, detail) VALUES (?,?,?,?,?,?,?,?)');
$db->beginTransaction();
for ($i = 0; $i < 1100; $i++) {
    $ins->execute([time(), 0, 'x', 'importacion', 'articulos', 'o', 'ok', 'd']);
}
$db->commit();
history_add('importacion', 'articulos', 'Prueba', 'ok', 'recorte');
for ($i = 0; $i < 60; $i++) { // el recorte se hace cada 50 filas
    history_add('importacion', 'articulos', 'Prueba', 'ok', 'recorte', 'x');
}
$check('historial: no pasa de ~1.000 filas (se recorta)', $one('SELECT COUNT(*) FROM data_history') <= HISTORY_KEEP + 50);
$check('historial: cada rol ve solo lo suyo', history_whats_for('admin') === null && history_whats_for('erp') === ['clientes', 'ventas', 'clientes_ventas', 'cobros'] && !in_array('clientes', history_whats_for('catalogo'), true));
[$k, $w] = import_history_kind(['source' => 'fotos-carpetas']);
[$k2, $w2] = import_history_kind(['source' => 'base-datos', 'mode' => 'update']);
$check('historial: tipo de cada importación (fotos / precios / artículos)', [$k, $w] === ['fotos', 'fotos'] && [$k2, $w2] === ['importacion', 'precios'] && import_history_kind(['source' => 'hoja-calculo']) === ['importacion', 'articulos']);
$db->exec("DELETE FROM data_history WHERE kind = 'sincronizacion'");
erp_finish(['counts' => ['cust' => ['new' => 3, 'changed' => 1], 'inv' => ['new' => 7, 'changed' => 2]], 'ftp_files' => []]);
$check('historial: la sincronización manual se anota con números', $one("SELECT COUNT(*) FROM data_history WHERE kind = 'sincronizacion' AND what = 'clientes_ventas' AND result = 'ok' AND detail LIKE 'clientes: 3 nuevos, 1 cambiados%'") === 1);
$check('historial: sin datos personales en ninguna fila', $one("SELECT COUNT(*) FROM data_history WHERE detail LIKE '%@%' OR origin LIKE '%@%'") === 0);
$db->exec("DELETE FROM data_history");

$cell = fn($r) => array_filter($r, fn($c) => is_string($c) && preg_match('/^[=+\-@\t\r]/', $c) && !preg_match('/^-?[\d.]+(,\d+)?$/', $c)); // un número negativo («-12,50») no es una fórmula
$rows = [];
$cnt = 0;
$put = function (array $r) use (&$rows) { $rows[] = $r; };
export_customers_write($put, true, $cnt);
$nCust = $one('SELECT COUNT(*) FROM erp_customers');
$check('exportar clientes (reales): una fila por cliente y cabecera', $cnt === $nCust && count($rows) === $nCust + 1 && $rows[0][0] === 'Código', "$cnt clientes");
$bad = 0;
foreach ($rows as $r) {
    $bad += count($cell($r));
}
$check('exportar clientes: ninguna celda puede ejecutarse como fórmula en Excel', $bad === 0);
$retail = erp_retail_code();
$rows2 = [];
$cnt2 = 0;
export_customers_write(function (array $r) use (&$rows2) { $rows2[] = $r; }, false, $cnt2);
$check('exportar clientes: sin el cliente de contado por defecto', $retail === '' || $cnt2 === $nCust - 1, "$cnt2 de $nCust");
$check('exportar clientes: nunca salen notas internas ni datos bancarios', !preg_match('/nota|iban|banco|mandato|tarjeta/i', implode(';', $rows[0])));

$rows = [];
$cnt = 0;
$inv = 0;
export_sales_write(function (array $r) use (&$rows) { $rows[] = $r; }, [], false, $cnt, $inv);
$nInv = $one('SELECT COUNT(*) FROM erp_invoices');
$sumDb = $one('SELECT COALESCE(SUM(total_cents), 0) FROM erp_invoices');
$sumCsv = 0;
foreach (array_slice($rows, 1) as $r) {
    $sumCsv += (int)round((float)str_replace(',', '.', $r[6]) * 100);
}
$check('exportar ventas (reales): una fila por factura y el total cuadra al céntimo', $cnt === $nInv && $inv === $nInv && $sumCsv === $sumDb, "$cnt facturas");
$rows = [];
$cnt = 0;
$inv = 0;
export_sales_write(function (array $r) use (&$rows) { $rows[] = $r; }, [], true, $cnt, $inv);
$nLines = $one('SELECT COUNT(*) FROM erp_invoice_lines');
$noLines = $one('SELECT COUNT(*) FROM erp_invoices i WHERE NOT EXISTS (SELECT 1 FROM erp_invoice_lines l WHERE l.invoice_id = i.id)');
$check('exportar ventas con líneas: una fila por línea (más las facturas sin líneas)', $cnt === $nLines + $noLines && $inv === $nInv, "$cnt filas");
$bad = 0;
foreach ($rows as $r) {
    $bad += count($cell($r));
}
$check('exportar ventas: ninguna celda puede ejecutarse como fórmula', $bad === 0);
$rows = [];
$cnt = 0;
export_articles_write(function (array $r) use (&$rows) { $rows[] = $r; }, 0, '', false, $cnt);
$check('exportar artículos (reales): todos, sin campos internos', $cnt === $one('SELECT COUNT(*) FROM products') && !in_array('Stock (interno)', $rows[0], true));
$rows = [];
$cnt = 0;
export_articles_write(function (array $r) use (&$rows) { $rows[] = $r; }, 0, '', true, $cnt);
$internal = $one('SELECT COUNT(*) FROM custom_fields WHERE is_public = 0');
$check('exportar artículos: los campos internos solo con la casilla', $internal === 0 || count($rows[0]) > 5 + $one('SELECT COUNT(*) FROM custom_fields WHERE is_public = 1'));

$sys = (int)$db->query('SELECT id FROM import_profiles WHERE is_system = 1 ORDER BY id LIMIT 1')->fetchColumn();
$dup = profiles_duplicate($sys);
$check('perfiles: duplicar uno de sistema crea uno propio con el mismo emparejamiento', $dup > 0 && $one("SELECT is_system FROM import_profiles WHERE id = $dup") === 0
    && $db->query("SELECT mapping_json FROM import_profiles WHERE id = $dup")->fetchColumn() === $db->query("SELECT mapping_json FROM import_profiles WHERE id = $sys")->fetchColumn());
profiles_rename($dup, 'Mi perfil de prueba');
$check('perfiles: renombrar el propio', (string)$db->query("SELECT name FROM import_profiles WHERE id = $dup")->fetchColumn() === 'Mi perfil de prueba');
$thrown = [];
foreach ([fn() => profiles_rename($sys, 'Otro'), fn() => profiles_delete($sys), fn() => profiles_rename($dup, IMPORT_SYSTEM_PROFILES[0]['name']), fn() => profiles_rename($dup, '')] as $fn) {
    try {
        $fn();
        $thrown[] = false;
    } catch (RuntimeException $e) {
        $thrown[] = true;
    }
}
$check('perfiles: el de sistema no se renombra ni se borra, y no se repiten nombres', $thrown === [true, true, true, true] && $one("SELECT COUNT(*) FROM import_profiles WHERE id = $sys") === 1);
profiles_delete($dup);
$check('perfiles: el propio se borra', $one("SELECT COUNT(*) FROM import_profiles WHERE id = $dup") === 0);

/* 5e. Series de documentos: letras de las facturas importadas de NCS y numeración */
$section('Series de documentos');
require_once $tmp . '/app/doc_series.php';
$ncsLetters = array_keys(series_ncs_letters());
$check('series: las letras de las facturas importadas se leen de erp_invoices', $ncsLetters !== [] && count(array_filter($ncsLetters, fn($l) => preg_match('/^[A-Z]+$/', $l))) === count($ncsLetters), implode(',', $ncsLetters));
$seed = array_column($db->query('SELECT letter FROM doc_series ORDER BY id')->fetchAll(), 'letter');
$check('series: F, R, A y P sembradas', $seed === ['F', 'R', 'A', 'P']);
$errs = [];
$postS = ['s' => [], 'default' => [], 'new' => ['type' => 'factura', 'letter' => $ncsLetters[0] ?? 'V', 'digits' => 5, 'with_year' => '']];
foreach (series_all() as $sid => $sr) {
    $postS['s'][$sid] = ['descr' => $sr['descr'], 'active' => '1', 'letter' => $sr['letter'], 'digits' => $sr['digits'], 'with_year' => $sr['with_year']];
}
series_plan($postS, series_ncs_letters(), (int)date('Y'), $errs);
$check('series: una serie SIN año con la letra de NCS queda bloqueada', count($errs) === 1 && str_contains($errs[0], 'repetir'));
$postS['new']['with_year'] = '1';
$errs = [];
series_plan($postS, series_ncs_letters(), (int)date('Y'), $errs);
$check('series: con año se permite (y se avisa en pantalla)', $errs === []);
$fid = (int)$db->query("SELECT id FROM doc_series WHERE letter = 'F'")->fetchColumn();
$db->beginTransaction();
$n1 = series_next_number($fid, 2026)['number'];
$db->rollBack();
$n2 = series_next_number($fid, 2026)['number'];
$check('series: numeración correlativa y sin huecos tras un rollback', $n1 === $n2 && $n2 === 1);
$check('series: copia de seguridad: las series van dentro y sus contadores también', in_array('doc_series', DB_TABLES, true) && in_array('doc_series_counters', DB_TABLES, true));

/* 5f. Parámetros de documentos con los datos reales: formas de pago, tarifas, IVA y bloqueo de periodos */
$section('Parámetros de documentos');
require_once $tmp . '/app/doc_params.php';
$realCodes = array_map('strval', array_keys(pay_codes_in_use()));
$check('parámetros: los códigos de forma de pago de NCS se leen de clientes y facturas', $realCodes !== [] && $realCodes === array_map('strval', erp_pay_codes()), implode(',', $realCodes));
$before = $one('SELECT COUNT(*) FROM doc_pay_methods');
$added = pay_methods_import_missing();
$check('parámetros: traer las formas de pago de NCS da de alta cada código una vez', $added === count($realCodes) && $one('SELECT COUNT(*) FROM doc_pay_methods') === $before + $added && pay_methods_import_missing() === 0, "$added códigos");
$check('parámetros: cada código importado tiene su fila y no se puede borrar mientras se use', count(array_diff($realCodes, array_map('strval', array_column(pay_methods_all(), 'code')))) === 0);
$names = erp_pay_names();
$check('parámetros: erp_pay_label sigue dando «F. pago NN» o el nombre', $realCodes === [] || preg_match('/^(.+ \(' . preg_quote($realCodes[0], '/') . '\)|F\. pago ' . preg_quote($realCodes[0], '/') . ')$/', erp_pay_label($realCodes[0])) === 1);
$tariffsUsed = array_map('intval', $db->query('SELECT DISTINCT tariff FROM erp_customers WHERE tariff > 0 ORDER BY tariff')->fetchAll(PDO::FETCH_COLUMN));
$tariffNums = array_map('intval', array_column(tariffs_all(), 'num'));
$check('parámetros: las tarifas de los clientes de NCS existen en la tabla de tarifas (1-3)', array_diff($tariffsUsed, $tariffNums) === [], 'usadas: ' . implode(',', $tariffsUsed));
$vatCount = [];
foreach ($db->query('SELECT vat_json FROM erp_invoices WHERE vat_json IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) as $j) {
    foreach (json_decode((string)$j, true) ?: [] as $row) {
        if (($row['pct'] ?? null) !== null && (int)$row['base'] !== 0) {
            $k = (string)(int)round((float)$row['pct'] * 100);
            $vatCount[$k] = ($vatCount[$k] ?? 0) + 1;
        }
    }
}
arsort($vatCount);
$vatKnown = array_map(fn($r) => (string)(int)$r['pct_bp'], vat_all());
// Las facturas importadas pueden llevar cualquier porcentaje (se calcula por factura); la tabla es para los documentos NUEVOS: el más usado en NCS debe estar
$check('parámetros: el tipo de IVA más usado en las facturas de NCS está entre los de la tabla', $vatCount !== [] && in_array((string)array_key_first($vatCount), $vatKnown, true), 'en NCS: ' . json_encode($vatCount));
save_setting('docp_lock_until', '20221231');
setting('', '', true);
$firstDay = (int)$db->query('SELECT MIN(inv_day) FROM erp_invoices')->fetchColumn();
$check('parámetros: bloqueo de periodos con las fechas de las facturas importadas', period_is_locked(20221231) && period_is_locked(20220101) && !period_is_locked(20230101) && $firstDay > 0);
save_setting('docp_lock_until', '0');
setting('', '', true);

/* 5g. Pedidos y albaranes (motor de documentos) con artículos y clientes reales de NCS */
$section('Pedidos y albaranes');
require_once $tmp . '/app/docs_engine.php';
// Un artículo real de TART con tallas (T00, huecos de 4) y precio (PVP2), dado de alta aquí como lo haría el perfil de NCS
// (en este punto de la batería el catálogo no está importado); de cada fila solo se toman CODIGO, DESCRIPCION, T00 y PVP2.
$src = null;
foreach ($readers['FALMACEN.MDB']->rows('TART') as $r) {
    if (trim((string)($r['CODIGO'] ?? '')) !== '' && preg_match('/\S\s{2,}\S/', (string)($r['T00'] ?? '')) && (float)($r['PVP2'] ?? 0) > 0) {
        $src = ['ref' => trim((string)$r['CODIGO']), 'name' => trim((string)($r['DESCRIPCION'] ?? '')), 't00' => (string)$r['T00'], 'pvp' => (string)$r['PVP2']];
        break;
    }
}
fields_all(true);
$fPrecio = docs_field_named(['precio'], ['price'])['id'] ?? field_create('Precio', 'price', '€', 1);
$fTallas = docs_field_named(['tallas'])['id'] ?? field_create('Tallas', 'text', '', 1);
fields_all(true);
$art = null;
if ($src) {
    $db->exec("INSERT INTO categories (name, slug, sort) VALUES ('Prueba pedidos', 'prueba-pedidos', 99)");
    $catId = (int)$db->query("SELECT id FROM categories WHERE slug = 'prueba-pedidos'")->fetchColumn();
    $db->prepare('INSERT INTO products (category_id, ref, name, slug, visible, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?)')
        ->execute([$catId, $src['ref'], $src['name'] !== '' ? $src['name'] : $src['ref'], 'prueba-pedidos-1', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $art = ['id' => (int)$db->query("SELECT id FROM products WHERE slug = 'prueba-pedidos-1'")->fetchColumn(), 'ref' => $src['ref']];
    product_fields_save($art['id'], [$fPrecio => $src['pvp'], $fTallas => $src['t00']]);
}
$cust = $db->prepare('SELECT * FROM erp_customers WHERE tariff > 0 AND cust_code <> ? ORDER BY id LIMIT 1');
$cust->execute([erp_retail_code()]);
$cust = $cust->fetch();
$opts = $art ? docs_product_options((int)$art['id']) : ['sizes' => []];
$check('pedidos: las tallas de T00 (huecos de ancho fijo) se separan en valores sueltos', $art && count($opts['sizes']) >= 1 && !array_filter($opts['sizes'], fn($s) => preg_match('/\s{2,}/', $s)), count($opts['sizes']) . ' tallas');
$check('pedidos: el artículo tiene precio en la tarifa del cliente', $art && $cust && docs_product_price((int)$art['id'], (int)$cust['tariff']) !== null);
$blank = docs_blank('pedido');
$post = ['h' => ['series_id' => $blank['series_id'], 'day' => date('Y-m-d'), 'cust_code' => $cust['cust_code'] ?? '', 'cust_prev' => '', 'vat_incl' => '1', 'tariff' => '1'],
    'l' => [['id' => 0, 'oref' => '', 'ref' => strtolower((string)($art['ref'] ?? '')), 'qty' => '2', 'size' => $opts['sizes'][0] ?? '', 'vat' => (string)docs_vat_default()]]];
$st = docs_from_post($blank, [], $post);
$check('pedidos: el formulario completa el cliente real (datos, tarifa y forma de pago)', $st['errors'] === [] && $st['doc']['cust_name'] !== '' && (int)$st['doc']['tariff'] === (int)($cust['tariff'] ?? -1) && $st['doc']['pay_code'] === (string)($cust['pay_code'] ?? ''), implode(' ', $st['errors']));
$check('pedidos: la referencia escrita en minúsculas se enlaza al artículo', ($st['lines'][0]['product_id'] ?? 0) === (int)($art['id'] ?? -1) && ($st['lines'][0]['ref'] ?? '') === (string)($art['ref'] ?? ''));
$tot = docs_totals($st['lines'], true, false);
$check('pedidos: con IVA incluido, base + cuota = suma de las líneas', $tot['total'] === (int)round(array_sum(array_column($st['lines'], 'amount_u')) / 100));
$oid = docs_save($st['doc'], $st['lines']);
$order = docs_get($oid);
$check('pedidos: se guarda con su número de la serie P', (bool)preg_match('#^P\d{5}/\d{2}$#', (string)$order['doc_code']) && $order['status'] === 'pendiente', (string)$order['doc_code']);
$aid = docs_serve($order);
$check('pedidos: «Servir» crea el albarán y el pedido queda servido', docs_get($oid)['status'] === 'servido' && docs_get($aid)['doc_type'] === 'albaran' && (int)docs_get($aid)['total_cents'] === (int)$order['total_cents']);
docs_annul(docs_get($aid));
$check('pedidos: anular el albarán devuelve el pedido a pendiente', docs_get($oid)['status'] === 'pendiente' && docs_get($aid)['status'] === 'anulado');
$check('pedidos: van siempre en las copias (DB_TABLES), no en ERP_TABLES', !array_diff(['doc_headers', 'doc_lines', 'doc_events'], DB_TABLES) && !array_intersect(['doc_headers', 'doc_lines', 'doc_events'], ERP_TABLES));
$bkDocs = $tmp . '/data/backup-docs.sqlite';
$db->exec("VACUUM INTO '" . str_replace("'", "''", $bkDocs) . "'");
auth_scrub_backup($bkDocs, false);
$bkPdo = new PDO('sqlite:' . $bkDocs);
$inBackup = (int)$bkPdo->query('SELECT COUNT(*) FROM doc_headers')->fetchColumn();
$bkPdo = null;
@unlink($bkDocs);
$check('pedidos: la copia por defecto (sin la casilla) los lleva', $inBackup === $one('SELECT COUNT(*) FROM doc_headers') && $inBackup > 0, "$inBackup documentos");
// Se quitan los de esta prueba: llevan el nombre de un cliente real y la prueba siguiente comprueba que la copia no lleva clientes de NCS
$db->exec('DELETE FROM doc_events');
$db->exec('DELETE FROM doc_lines');
$db->exec('DELETE FROM doc_headers');
$sumNcs = $one('SELECT COUNT(*) FROM erp_invoices');
$check('pedidos: no tocan las facturas de NCS', $sumNcs === $one('SELECT COUNT(*) FROM erp_invoices') && $one("SELECT COUNT(*) FROM erp_invoices WHERE origin <> 'ncs'") === 0);

/* 6. Copia de seguridad sin datos personales */
$section('Copia de seguridad sin datos personales');
$sample = (string)$db->query("SELECT name FROM erp_customers WHERE LENGTH(name) > 12 ORDER BY id LIMIT 1")->fetchColumn();
$db->prepare('INSERT INTO mail_log (sent_at, to_email, subject, ok, transport, error) VALUES (?,?,?,?,?,?)')->execute([time(), 'destino-unico-prueba@ejemplo.com', 'asunto', 1, 'smtp', '']);
$db->prepare('INSERT INTO backup_log (created_at, username, with_erp, size_bytes) VALUES (?,?,?,?)')->execute([time(), 'usuario-unico-prueba', 0, 1]);
$bk = $tmp . '/data/backup-test.sqlite';
$db->exec("VACUUM INTO '" . str_replace("'", "''", $bk) . "'");
auth_scrub_backup($bk, false);
$bytes = (string)file_get_contents($bk);
@unlink($bk);
$check('la copia por defecto no lleva clientes ni facturas (ni restos en el archivo)', $sample !== '' && !str_contains($bytes, $sample));
$check('la copia no lleva el registro de correos ni el historial de descargas (ni restos en el archivo)', !str_contains($bytes, 'destino-unico-prueba@ejemplo.com') && !str_contains($bytes, 'usuario-unico-prueba'));

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
