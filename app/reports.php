<?php
declare(strict_types=1);

/*
 * Informes (/admin/listados): motor común. Cada informe es un archivo app/reports/*.php que devuelve un array y aparece solo en el menú
 * (ver app/reports/LEEME.md). El motor se ocupa de: pantalla de selección (Desde/Hasta, tipo de listado, agrupar, ordenar, casillas),
 * recordar la última selección de cada usuario, agrupar con subtotales y total general, paginar en pantalla y exportar a CSV,
 * Excel (.xlsx con ZipArchive) e impresión A4 / PDF. Nada se guarda en el servidor: el archivo se genera y se descarga directamente.
 *
 * Convenciones de los datos: dinero en céntimos (enteros), fechas aaaammdd (enteros), unidades como número decimal.
 */

require_once __DIR__ . '/reports_lib.php';

const REP_PER_PAGE = 100;      // líneas por página en pantalla
const REP_MAX_ROWS = 100000;   // tope de filas de un informe
const REP_SECTIONS = ['Ventas', 'Documentos', 'Clientes', 'Artículos', 'Fiscal'];

/* ---------- Registro de informes ---------- */

/** Todos los informes (app/reports/*.php), por id. */
function reports_all(): array
{
    static $all = null;
    if ($all !== null) {
        return $all;
    }
    $all = [];
    foreach (glob(__DIR__ . '/reports/*.php') ?: [] as $f) {
        $def = require $f;
        if (is_array($def) && !empty($def['id']) && isset($def['rows']) && is_callable($def['rows'])) {
            $def += ['title' => $def['id'], 'section' => 'Ventas', 'order' => 50, 'perm' => 'erp', 'help' => '', 'filters' => [], 'variants' => ['' => 'Listado'],
                'groups' => [], 'orders' => [], 'options' => [], 'notice' => '', 'history' => 'ventas'];
            $all[$def['id']] = $def;
        }
    }
    uasort($all, function ($a, $b) {
        $sa = array_search($a['section'], REP_SECTIONS, true);
        $sb = array_search($b['section'], REP_SECTIONS, true);
        return [$sa === false ? 99 : $sa, $a['order'], $a['title']] <=> [$sb === false ? 99 : $sb, $b['order'], $b['title']];
    });
    return $all;
}

/** ¿Puede este rol ver el informe? 'erp' = Clientes y Ventas (admin y erp), 'catalog' = catálogo (admin y catalogo), 'all' = cualquiera con sesión. */
function report_allowed(array $def, string $role): bool
{
    return match ($def['perm']) {
        'erp' => in_array($role, ['admin', 'erp'], true),
        'catalog' => in_array($role, ['admin', 'catalogo'], true),
        'all' => in_array($role, ['admin', 'erp', 'catalogo'], true),
        default => $role === 'admin',
    };
}

/** ¿Puede este rol ver el stock? (campo interno del catálogo: solo admin y catálogo) */
function report_can_stock(): bool
{
    return in_array(current_role(), ['admin', 'catalogo'], true);
}

/** Entradas del menú «Informes» para admin_menu(): [etiqueta, dirección, [tramos], quién]. */
function reports_menu_pages(): array
{
    $out = [['Todos los informes', '/admin/listados', ['listados'], 'all']];
    foreach (reports_all() as $d) {
        $out[] = [$d['title'], '/admin/listados/' . $d['id'], ['listados/' . $d['id']], match ($d['perm']) { 'erp' => 'erp', 'catalog' => 'catalog', 'all' => 'all', default => 'admin' }];
    }
    return $out;
}

/* ---------- Selección (filtros) ---------- */

function rep_date_in(mixed $s): int
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim((string)$s), $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? (int)($m[1] . $m[2] . $m[3]) : 0;
}

function rep_date_out(int $d): string
{
    return $d > 0 ? sprintf('%04d-%02d-%02d', intdiv($d, 10000), intdiv($d, 100) % 100, $d % 100) : '';
}

/** Valores de un select (cerrados): opciones [valor => texto] de un filtro (puede ser una función). */
function rep_filter_options(array $flt): array
{
    $o = $flt['options'] ?? [];
    return is_callable($o) ? (array)$o() : (array)$o;
}

/** Lee la selección del usuario (de $_GET o de lo recordado) y la deja validada. */
function rep_parse(array $def, array $src): array
{
    $sel = ['v' => '', 'g' => '', 'o' => '', 'dir' => 'asc', 'f' => [], 'opt' => [], 'pag' => max(1, (int)($src['pag'] ?? 1))];
    $v = (string)($src['v'] ?? '');
    $sel['v'] = isset($def['variants'][$v]) ? $v : (string)array_key_first($def['variants']);
    $g = (string)($src['g'] ?? '');
    $sel['g'] = ($g !== '' && isset($def['groups'][$g])) ? $g : '';
    $o = (string)($src['o'] ?? '');
    $sel['o'] = isset($def['orders'][$o]) ? $o : (string)(array_key_first($def['orders']) ?? '');
    $sel['dir'] = (($src['dir'] ?? '') === 'desc') ? 'desc' : 'asc';
    foreach ($def['filters'] as $flt) {
        $n = $flt['name'];
        $type = $flt['type'] ?? 'text';
        if ($type === 'date') {
            $sel['f'][$n] = ['d' => rep_date_in($src[$n . '_d'] ?? ''), 'h' => rep_date_in($src[$n . '_h'] ?? '')];
        } elseif ($type === 'code') {
            $sel['f'][$n] = ['d' => mb_substr(trim((string)($src[$n . '_d'] ?? '')), 0, 60), 'h' => mb_substr(trim((string)($src[$n . '_h'] ?? '')), 0, 60)];
        } elseif ($type === 'select') {
            $val = (string)($src[$n] ?? '');
            $sel['f'][$n] = array_key_exists($val, rep_filter_options($flt)) && $val !== '' ? $val : '';
        } else {
            $sel['f'][$n] = mb_substr(trim((string)($src[$n] ?? '')), 0, 80);
        }
    }
    $submitted = isset($src['salida']) || isset($src['go']);
    foreach ($def['options'] as $op) {
        $sel['opt'][$op['name']] = $submitted ? (empty($src['x_' . $op['name']]) ? 0 : 1) : (int)($op['default'] ?? 0);
    }
    return $sel;
}

/** Datos de la selección que se pueden recordar (sin los filtros marcados como personales, p. ej. NIF). */
function rep_prefs_pack(array $def, array $src): array
{
    $keep = [];
    $personal = [];
    foreach ($def['filters'] as $flt) {
        if (!empty($flt['personal'])) {
            $personal[] = $flt['name'];
        }
    }
    foreach ($src as $k => $val) {
        if (!is_string($k) || !is_scalar($val) || in_array($k, ['salida', 'go', 'pag', 'reset'], true)) {
            continue;
        }
        if (preg_match('/^(v|g|o|dir|x_\w+|\w+_d|\w+_h|\w+)$/', $k) && !in_array(preg_replace('/_[dh]$/', '', $k), $personal, true)) {
            $keep[$k] = mb_substr((string)$val, 0, 80);
        }
    }
    return $keep;
}

function rep_prefs_key(string $id): string
{
    return 'rep_' . (int)($_SESSION['uid'] ?? 0) . '_' . preg_replace('/[^a-z0-9_-]/i', '', $id);
}

function rep_prefs_load(string $id): array
{
    $raw = (string)db_setting(rep_prefs_key($id), '');
    $a = $raw !== '' ? json_decode($raw, true) : null;
    return is_array($a) ? $a : [];
}

/* ---------- Ayudas para los informes ---------- */

/** Añade a $w/$a una condición «columna entre desde y hasta» (códigos de texto, sin distinguir mayúsculas). */
function rep_cond_code(string &$w, array &$a, string $col, array $range): void
{
    if (($range['d'] ?? '') !== '') {
        $w .= ' AND UPPER(' . $col . ') >= ?';
        $a[] = mb_strtoupper((string)$range['d']);
    }
    if (($range['h'] ?? '') !== '') {
        $w .= ' AND UPPER(' . $col . ') <= ?';
        $a[] = mb_strtoupper((string)$range['h']);
    }
}

function rep_cond_date(string &$w, array &$a, string $col, array $range): void
{
    if ((int)($range['d'] ?? 0) > 0) {
        $w .= ' AND ' . $col . ' >= ?';
        $a[] = (int)$range['d'];
    }
    if ((int)($range['h'] ?? 0) > 0) {
        $w .= ' AND ' . $col . ' <= ?';
        $a[] = (int)$range['h'];
    }
}

/** Texto «contiene» sin distinguir mayúsculas en una o varias columnas. */
function rep_cond_like(string &$w, array &$a, array $cols, string $text): void
{
    if ($text === '') {
        return;
    }
    $like = '%' . strtr(mb_strtolower($text), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $parts = [];
    foreach ($cols as $c) {
        $parts[] = "LOWER($c) LIKE ? ESCAPE '!'";
        $a[] = $like;
    }
    $w .= ' AND (' . implode(' OR ', $parts) . ')';
}

function rep_month_key(int $day): string
{
    return $day > 0 ? sprintf('%04d-%02d', intdiv($day, 10000), intdiv($day, 100) % 100) : '';
}

/** Familias del catálogo [id => nombre] (el primer nivel). */
function rep_families(): array
{
    $o = [];
    foreach (db()->query('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY sort, name')->fetchAll() as $r) {
        $o[(string)$r['id']] = (string)$r['name'];
    }
    return $o;
}

function rep_pay_options(): array
{
    $o = [];
    foreach (pay_methods_all() as $m) {
        $o[(string)$m['code']] = (string)$m['name'];
    }
    return $o;
}

function rep_series_options(?array $types = null): array
{
    $o = [];
    foreach (series_all() as $s) {
        if ($types === null || in_array($s['doc_type'], $types, true)) {
            $o[(string)$s['letter']] = (string)$s['letter'] . ($s['descr'] !== '' ? ' · ' . $s['descr'] : '');
        }
    }
    return $o;
}

/** Nombre del cliente para un listado (nombre comercial o razón social). */
function rep_cust_label(string $code, string $name, string $trade): string
{
    $n = $trade !== '' ? $trade : $name;
    return $n !== '' ? $n : $code;
}

/* ---------- Ejecución: filas → líneas con grupos, subtotales y total ---------- */

/** Ejecuta el informe: devuelve ['cols', 'lines', 'rows', 'capped', 'sel']. Cada línea: ['t' => 'row'|'gh'|'sub'|'total', ...]. */
function rep_run(array $def, array $sel): array
{
    @set_time_limit(300);
    $rows = (array)($def['rows'])($sel);
    $capped = count($rows) > REP_MAX_ROWS;
    if ($capped) {
        $rows = array_slice($rows, 0, REP_MAX_ROWS);
    }
    $cols = rep_columns($def, $sel);
    // Orden
    $ord = $def['orders'][$sel['o']] ?? null;
    $okeys = $ord ? (array)$ord['by'] : [];
    $gf = $sel['g'] !== '' ? $def['groups'][$sel['g']] : null;
    $dir = $sel['dir'] === 'desc' ? -1 : 1;
    $cmp = static function ($x, $y): int {
        if (is_int($x) || is_float($x) || is_int($y) || is_float($y)) {
            return $x <=> $y;
        }
        return strnatcasecmp((string)$x, (string)$y);
    };
    usort($rows, function ($a, $b) use ($okeys, $gf, $dir, $cmp) {
        if ($gf) {
            $k = $gf['sort'] ?? $gf['field'];
            $c = $cmp($a[$k] ?? '', $b[$k] ?? '');
            if ($c !== 0) {
                return $c;
            }
        }
        foreach ($okeys as $k) {
            $c = $cmp($a[$k] ?? '', $b[$k] ?? '') * $dir;
            if ($c !== 0) {
                return $c;
            }
        }
        return 0;
    });
    $sumCols = array_values(array_filter($cols, fn($c) => !empty($c['sum'])));
    $zero = fn() => array_fill_keys(array_column($sumCols, 'key'), 0);
    $add = function (array &$acc, array $r) use ($sumCols) {
        foreach ($sumCols as $c) {
            $acc[$c['key']] += $r[$c['key']] ?? 0;
        }
    };
    $lines = [];
    $total = $zero();
    $n = 0;
    $cur = null;
    $sub = null;
    $subN = 0;
    foreach ($rows as $r) {
        if ($gf) {
            $g = (string)($r[$gf['field']] ?? '');
            if ($cur === null || $g !== $cur) {
                if ($cur !== null) {
                    $lines[] = ['t' => 'sub', 'label' => 'Subtotal ' . ($cur !== '' ? $cur : '(sin dato)'), 'sum' => $sub, 'n' => $subN];
                }
                $cur = $g;
                $sub = $zero();
                $subN = 0;
                $lines[] = ['t' => 'gh', 'label' => $gf['label'] . ': ' . ($g !== '' ? $g : '(sin dato)')];
            }
            $add($sub, $r);
            $subN++;
        }
        $lines[] = ['t' => 'row', 'r' => $r];
        $add($total, $r);
        $n++;
    }
    if ($gf && $cur !== null) {
        $lines[] = ['t' => 'sub', 'label' => 'Subtotal ' . ($cur !== '' ? $cur : '(sin dato)'), 'sum' => $sub, 'n' => $subN];
    }
    $lines[] = ['t' => 'total', 'label' => 'Total general', 'sum' => $total, 'n' => $n];
    return ['cols' => $cols, 'lines' => $lines, 'rows' => $n, 'capped' => $capped, 'sel' => $sel];
}

function rep_columns(array $def, array $sel): array
{
    $c = $def['columns'] ?? [];
    return is_callable($c) ? (array)$c($sel['v'], $sel) : (array)$c;
}

/* ---------- Formato de las celdas ---------- */

/** Texto de una celda según su tipo, para pantalla/impresión ('html') o CSV ('csv'). */
function rep_cell(array $col, mixed $v, string $mode = 'html'): string
{
    $t = $col['type'] ?? 'text';
    if ($v === null || $v === '') {
        return '';
    }
    switch ($t) {
        case 'date':
            return (int)$v > 0 ? erp_day((int)$v) : '';
        case 'money':
            return $mode === 'csv' ? number_format((int)$v / 100, 2, ',', '') : number_format((int)$v / 100, 2, ',', '.');
        case 'qty':
            $f = (float)$v;
            $s = abs($f - round($f)) < 0.00005 ? number_format($f, 0, ',', $mode === 'csv' ? '' : '.') : rtrim(rtrim(number_format($f, 3, ',', $mode === 'csv' ? '' : '.'), '0'), ',');
            return $s;
        case 'int':
            return (string)(int)$v;
        case 'pct':
            return rtrim(rtrim(number_format((float)$v, 2, ',', ''), '0'), ',') . ($mode === 'csv' ? '' : ' %');
        default:
            return $mode === 'csv' ? erp_csv_text((string)$v) : (string)$v;
    }
}

/** Descripción legible de la selección (para la cabecera impresa, el CSV y el Excel). */
function rep_selection_text(array $def, array $sel): string
{
    $p = [];
    $p[] = 'Listado: ' . ($def['variants'][$sel['v']] ?? '');
    if ($sel['g'] !== '') {
        $p[] = 'Agrupado por: ' . mb_strtolower($def['groups'][$sel['g']]['label']);
    }
    foreach ($def['filters'] as $flt) {
        $val = $sel['f'][$flt['name']] ?? '';
        $type = $flt['type'] ?? 'text';
        $lab = $flt['label'];
        if ($type === 'date') {
            if ($val['d'] || $val['h']) {
                $p[] = $lab . ': ' . ($val['d'] ? 'desde ' . erp_day($val['d']) : '') . ($val['d'] && $val['h'] ? ' ' : '') . ($val['h'] ? 'hasta ' . erp_day($val['h']) : '');
            }
        } elseif ($type === 'code') {
            if ($val['d'] !== '' || $val['h'] !== '') {
                $p[] = $lab . ': ' . ($val['d'] !== '' ? 'desde ' . $val['d'] : '') . ($val['d'] !== '' && $val['h'] !== '' ? ' ' : '') . ($val['h'] !== '' ? 'hasta ' . $val['h'] : '');
            }
        } elseif ($type === 'select') {
            if ($val !== '') {
                $p[] = $lab . ': ' . (rep_filter_options($flt)[$val] ?? $val);
            }
        } elseif ($val !== '') {
            $p[] = $lab . ': «' . $val . '»';
        }
    }
    foreach ($def['options'] as $op) {
        if (!empty($sel['opt'][$op['name']])) {
            $p[] = $op['label'];
        }
    }
    return implode(' · ', $p);
}

/* ---------- Pantalla ---------- */

/** Despacha /admin/listados[/<id>[/buscar]]. */
function admin_reports(string $id, string $sub): void
{
    $role = current_role();
    $all = reports_all();
    if ($id === '') {
        $by = [];
        foreach ($all as $d) {
            if (report_allowed($d, $role)) {
                $by[$d['section']][] = $d;
            }
        }
        admin_render('reports_index', ['by' => $by, 'title' => 'Informes', 'crumb' => 'Todos los informes']);
        return;
    }
    $def = $all[$id] ?? null;
    if (!$def || !report_allowed($def, $role)) {
        if ($def) {
            flash('Tu usuario no tiene acceso a ese informe.', 'err');
            redirect('/admin/listados');
        }
        not_found();
        return;
    }
    if ($def['perm'] === 'erp' && !erp_guard()) { // datos de clientes y ventas: con la doble verificación si está exigida
        return;
    }
    if ($sub === 'buscar') {
        rep_lookup_json($def);
        return;
    }
    $out = (string)($_GET['salida'] ?? '');
    if (!in_array($out, ['pantalla', 'imprimir', 'csv', 'xlsx'], true)) {
        $out = '';
    }
    if (isset($_GET['reset'])) {
        db()->prepare('DELETE FROM settings WHERE key = ?')->execute([rep_prefs_key($id)]);
        redirect('/admin/listados/' . $id);
    }
    $src = $_GET;
    $ran = $out !== '' || isset($_GET['go']);
    if (!$ran) { // entrada sin selección: la última de este usuario
        $src = rep_prefs_load($id);
        if ($src) {
            $src['go'] = '1';
            $ran = true;
        }
        if (!$src) {
            $src = [];
            $ran = false;
        }
    }
    $sel = rep_parse($def, $src);
    if ($ran && ($out !== '' || isset($_GET['go']))) {
        save_setting(rep_prefs_key($id), json_encode(rep_prefs_pack($def, $_GET), JSON_UNESCAPED_UNICODE));
    }
    $v = ['def' => $def, 'sel' => $sel, 'ran' => $ran, 'canZip' => class_exists('ZipArchive'), 'title' => $def['title'], 'crumb' => $def['title'], 'selText' => rep_selection_text($def, $sel)];
    if (!$ran) {
        admin_render('report', $v + ['res' => null, 'page' => 1, 'pages' => 1]);
        return;
    }
    $res = rep_run($def, $sel);
    if (in_array($out, ['csv', 'xlsx', 'imprimir'], true)) {
        require_once __DIR__ . '/history.php';
        history_add('exportacion', (string)$def['history'], 'Informe: ' . $def['title'] . ' (' . ['csv' => 'CSV', 'xlsx' => 'Excel', 'imprimir' => 'PDF'][$out] . ')', 'ok', $res['rows'] . ' filas');
    }
    if ($out === 'csv') {
        rep_out_csv($def, $res, $sel);
        return;
    }
    if ($out === 'xlsx') {
        if (!class_exists('ZipArchive')) {
            flash('Este servidor no tiene la extensión zip: usa CSV.', 'err');
            redirect('/admin/listados/' . $id);
        }
        rep_out_xlsx($def, $res, $sel);
        return;
    }
    if ($out === 'imprimir') {
        header('Cache-Control: no-store');
        render('admin/report_print', $v + ['res' => $res, 'co' => doc_company(), 'when' => date('d/m/Y H:i')], null);
        return;
    }
    // Pantalla paginada (por líneas: filas, cabeceras de grupo y subtotales)
    $lines = $res['lines'];
    $pages = max(1, (int)ceil(count($lines) / REP_PER_PAGE));
    $page = min($pages, $sel['pag']);
    $slice = array_slice($lines, ($page - 1) * REP_PER_PAGE, REP_PER_PAGE);
    if ($slice && $sel['g'] !== '' && $slice[0]['t'] === 'row') { // la página empieza a mitad de un grupo: se repite su nombre
        for ($i = ($page - 1) * REP_PER_PAGE - 1; $i >= 0; $i--) {
            if ($lines[$i]['t'] === 'gh') {
                array_unshift($slice, ['t' => 'gh', 'label' => $lines[$i]['label'] . ' (continuación)']);
                break;
            }
        }
    }
    admin_render('report', $v + ['res' => ['lines' => $slice] + $res, 'page' => $page, 'pages' => $pages]);
}

/** Buscador de códigos (el «…» de NCS): ?l=cliente|articulo&q= → JSON [{v, l}], máx. 20. */
function rep_lookup_json(array $def): void
{
    header('Content-Type: application/json; charset=utf-8');
    $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
    $out = [];
    if (mb_strlen($q) >= 1) {
        $esc = strtr(mb_strtolower($q), ['!' => '!!', '%' => '!%', '_' => '!_']);
        if (($_GET['l'] ?? '') === 'cliente' && in_array(current_role(), ['admin', 'erp'], true)) {
            $st = db()->prepare("SELECT cust_code, name, trade_name, nif FROM erp_customers WHERE LOWER(cust_code) LIKE ? ESCAPE '!' OR LOWER(name) LIKE ? ESCAPE '!' OR LOWER(trade_name) LIKE ? ESCAPE '!'
                ORDER BY CASE WHEN LOWER(cust_code) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END, cust_code LIMIT 20");
            $st->execute(['%' . $esc . '%', '%' . $esc . '%', '%' . $esc . '%', $esc . '%']);
            foreach ($st->fetchAll() as $r) {
                $out[] = ['v' => (string)$r['cust_code'], 'l' => rep_cust_label((string)$r['cust_code'], (string)$r['name'], (string)$r['trade_name'])];
            }
        } elseif (($_GET['l'] ?? '') === 'articulo') {
            $st = db()->prepare("SELECT ref, name FROM products WHERE ref <> '' AND (ref LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!') ORDER BY CASE WHEN ref LIKE ? ESCAPE '!' THEN 0 ELSE 1 END, ref LIMIT 20");
            $st->execute(['%' . $esc . '%', '%' . $esc . '%', $esc . '%']);
            foreach ($st->fetchAll() as $r) {
                $out[] = ['v' => (string)$r['ref'], 'l' => (string)$r['name']];
            }
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

/* ---------- CSV ---------- */

function rep_filename(array $def, string $ext): string
{
    return preg_replace('/[^a-z0-9-]+/i', '-', (string)$def['id']) . '-' . date('Y-m-d') . '.' . $ext;
}

/** Filas de texto planas (cabecera, filas, subtotales y total) para CSV y Excel: [['t' => tipo, 'c' => [celdas crudas]]]. */
function rep_flat(array $res): array
{
    $cols = $res['cols'];
    $out = [];
    foreach ($res['lines'] as $ln) {
        if ($ln['t'] === 'row') {
            $out[] = ['t' => 'row', 'c' => array_map(fn($c) => $ln['r'][$c['key']] ?? null, $cols)];
        } elseif ($ln['t'] === 'gh') {
            $out[] = ['t' => 'gh', 'c' => [$ln['label']]];
        } else {
            $c = [];
            foreach ($cols as $i => $col) {
                $c[] = $i === 0 ? $ln['label'] . ($ln['n'] ? ' (' . $ln['n'] . ')' : '') : (!empty($col['sum']) ? ($ln['sum'][$col['key']] ?? 0) : null);
            }
            $out[] = ['t' => $ln['t'], 'c' => $c];
        }
    }
    return $out;
}

function rep_out_csv(array $def, array $res, array $sel): void
{
    @set_time_limit(300);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rep_filename($def, 'csv') . '"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    $o = fopen('php://output', 'w');
    $put = fn(array $r) => fputcsv($o, $r, ';', '"', '\\');
    $put([(string)$def['title']]);
    $put([rep_selection_text($def, $sel)]);
    if ($def['notice'] !== '') {
        $put([(string)$def['notice']]);
    }
    $put(['Generado el ' . date('d/m/Y H:i')]);
    $put([]);
    $cols = $res['cols'];
    $put(array_map(fn($c) => (string)$c['label'], $cols));
    foreach (rep_flat($res) as $ln) {
        if ($ln['t'] === 'gh') {
            $put([erp_csv_text((string)$ln['c'][0])]);
            continue;
        }
        $row = [];
        foreach ($cols as $i => $col) {
            $v = $ln['c'][$i] ?? null;
            $row[] = ($ln['t'] !== 'row' && $i === 0) ? (string)$v : rep_cell($col, $v, 'csv');
        }
        $put($row);
    }
    fclose($o);
}

/* ---------- Excel (.xlsx con ZipArchive, sin librerías) ---------- */

function rep_xml(string $s): string
{
    return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Letra(s) de columna de Excel: 0 → A, 26 → AA. */
function rep_col_letter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** aaaammdd → número de serie de Excel (días desde 1899-12-30). */
function rep_excel_date(int $d): int
{
    return (int)((gmmktime(0, 0, 0, intdiv($d, 100) % 100, $d % 100, intdiv($d, 10000)) - gmmktime(0, 0, 0, 12, 30, 1899)) / 86400);
}

/**
 * Genera el .xlsx en un archivo temporal y lo devuelve (se borra justo después de enviarlo). Números como números, fechas como fechas
 * (formato dd/mm/aaaa) y dinero con dos decimales. Estilos: 0 normal, 1 fecha, 2 dinero, 3 negrita, 4 negrita+dinero, 5 cabecera, 6 título.
 */
function rep_xlsx_build(array $def, array $res, array $sel): string
{
    $cols = $res['cols'];
    $rows = [];
    $r = 1;
    $cell = static function (int $c, int $row, mixed $v, string $kind, int $style = 0): string {
        $ref = rep_col_letter($c) . $row;
        if ($v === null || $v === '') {
            return $style > 0 ? '<c r="' . $ref . '" s="' . $style . '"/>' : '';
        }
        if ($kind === 'n') {
            return '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . (is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : (string)(int)$v) . '</v></c>';
        }
        return '<c r="' . $ref . '" t="inlineStr"' . ($style ? ' s="' . $style . '"' : '') . '><is><t xml:space="preserve">' . rep_xml((string)$v) . '</t></is></c>';
    };
    $push = function (array $cells) use (&$rows, &$r) {
        $rows[] = '<row r="' . $r . '">' . implode('', $cells) . '</row>';
        $r++;
    };
    $push([$cell(0, $r, (string)$def['title'], 's', 6)]);
    $push([$cell(0, $r, rep_selection_text($def, $sel), 's')]);
    if ($def['notice'] !== '') {
        $push([$cell(0, $r, (string)$def['notice'], 's', 3)]);
    }
    $push([$cell(0, $r, 'Generado el ' . date('d/m/Y H:i'), 's')]);
    $push([]);
    $hdr = [];
    foreach ($cols as $i => $c) {
        $hdr[] = $cell($i, $r, (string)$c['label'], 's', 5);
    }
    $headRow = $r;
    $push($hdr);
    foreach (rep_flat($res) as $ln) {
        $cells = [];
        if ($ln['t'] === 'gh') {
            $cells[] = $cell(0, $r, (string)$ln['c'][0], 's', 3);
        } else {
            foreach ($cols as $i => $col) {
                $v = $ln['c'][$i] ?? null;
                $bold = $ln['t'] !== 'row';
                if ($bold && $i === 0) {
                    $cells[] = $cell($i, $r, (string)$v, 's', 3);
                    continue;
                }
                $t = $col['type'] ?? 'text';
                if ($v === null || $v === '') {
                    $cells[] = $bold ? $cell($i, $r, null, 's', 3) : '';
                } elseif ($t === 'money') {
                    $cells[] = $cell($i, $r, round((int)$v / 100, 2), 'n', $bold ? 4 : 2);
                } elseif ($t === 'date') {
                    $cells[] = (int)$v > 0 ? $cell($i, $r, rep_excel_date((int)$v), 'n', 1) : '';
                } elseif ($t === 'qty' || $t === 'int' || $t === 'pct') {
                    $cells[] = $cell($i, $r, $t === 'int' ? (int)$v : (float)$v, 'n', $bold ? 3 : 0);
                } else {
                    $cells[] = $cell($i, $r, (string)$v, 's', $bold ? 3 : 0);
                }
            }
        }
        $push($cells);
    }
    $widths = '';
    foreach ($cols as $i => $c) {
        $w = (int)($c['w'] ?? (match ($c['type'] ?? 'text') { 'date' => 12, 'money' => 14, 'qty', 'int', 'pct' => 11, default => 24 }));
        $widths .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headRow . '" topLeftCell="A' . ($headRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="15"/><cols>' . $widths . '</cols><sheetData>' . implode('', $rows) . '</sheetData></worksheet>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8E6E0"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="4" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $name = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', ' ', (string)$def['title']) ?? 'Informe', 0, 31);
    $tmp = tempnam(sys_get_temp_dir(), 'rep');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
        throw new RuntimeException('No se pudo crear el Excel.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . rep_xml($name) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', $styles);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
    return $tmp;
}

function rep_out_xlsx(array $def, array $res, array $sel): void
{
    $tmp = rep_xlsx_build($def, $res, $sel);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . rep_filename($def, 'xlsx') . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp); // el archivo no se queda en el servidor
}
