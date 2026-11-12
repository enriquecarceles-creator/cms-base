<?php
declare(strict_types=1);

/*
 * Inicio del panel como cuadro de mandos (/admin).
 *
 * Todo sale de erp_invoices / erp_invoice_lines (facturas de NCS y copias de las emitidas en el CMS: las de prueba no se copian, así que no cuentan)
 * y de doc_headers / doc_dues (pedidos, albaranes, borradores y vencimientos). Los importes de ventas son BASE sin IVA; las rectificativas y
 * devoluciones llevan importe negativo y restan solas. Consultas agrupadas y portables (nada de funciones de fecha del motor): con 12.000
 * facturas va en pocos milisegundos. Las gráficas son SVG hecho en PHP (sin librerías ni internet), con viewBox para que se adapten al móvil.
 * Cada bloque se enseña solo a quien corresponde: ventas e importes, a administradores y rol ERP (con la doble verificación si se exige).
 */

const DASH_PERIODS = ['mes' => 'Este mes', 'trimestre' => 'Este trimestre', 'anio' => 'Este año', 'anterior' => 'Año anterior', 'custom' => 'Fechas a medida'];

/** Periodo elegido en el selector: ['key', 'from', 'to' (aaaammdd), 'label']. Por defecto, este año. */
function dash_period(array $src, ?int $today = null): array
{
    $today ??= (int)date('Ymd');
    $y = intdiv($today, 10000);
    $m = intdiv($today, 100) % 100;
    $key = (string)($src['p'] ?? 'anio');
    $key = isset(DASH_PERIODS[$key]) ? $key : 'anio';
    $from = $y * 10000 + 101;
    $to = $today;
    if ($key === 'mes') {
        $from = $y * 10000 + $m * 100 + 1;
    } elseif ($key === 'trimestre') {
        $from = $y * 10000 + (intdiv($m - 1, 3) * 3 + 1) * 100 + 1;
    } elseif ($key === 'anterior') {
        $from = ($y - 1) * 10000 + 101;
        $to = ($y - 1) * 10000 + 1231;
    } elseif ($key === 'custom') {
        $f = erp_day_from_input((string)($src['desde'] ?? ''));
        $t = erp_day_from_input((string)($src['hasta'] ?? ''));
        if ($f > 0 && $t > 0 && $f <= $t) {
            $from = $f;
            $to = $t;
        } else {
            $key = 'anio';
        }
    }
    return ['key' => $key, 'from' => $from, 'to' => $to, 'label' => $key === 'custom' ? erp_day($from) . ' – ' . erp_day($to) : DASH_PERIODS[$key]];
}

/** Mismo tramo un año antes (aaaammdd): para comparar. */
function dash_prev_year(int $day): int
{
    return $day - 10000;
}

/** Variación en % de $cur frente a $prev (null si no hay con qué comparar). */
function dash_delta(int $cur, int $prev): ?float
{
    return $prev === 0 ? null : round(($cur - $prev) / abs($prev) * 100, 1);
}

/** Ventas (base sin IVA, en céntimos) entre dos días. Las anuladas no cuentan; las rectificativas y devoluciones restan. */
function dash_sales(int $from, int $to): int
{
    $st = db()->prepare('SELECT COALESCE(SUM(base_cents), 0) FROM erp_invoices WHERE annulled = 0 AND inv_day >= ? AND inv_day <= ?');
    $st->execute([$from, $to]);
    return (int)$st->fetchColumn();
}

/** Todas las cifras de los bloques de ventas. $today = aaaammdd. */
function dash_sales_data(array $period, ?int $today = null): array
{
    $db = db();
    $today ??= (int)date('Ymd');
    $y = intdiv($today, 10000);
    $m = intdiv($today, 100) % 100;
    $mStart = $y * 10000 + $m * 100 + 1;
    $yStart = $y * 10000 + 101;
    $retail = erp_retail_code();
    $d = ['today' => $today];

    // Tarjetas: este mes y este año frente al mismo tramo del año anterior
    $d['month'] = dash_sales($mStart, $today);
    $d['month_prev'] = dash_sales(dash_prev_year($mStart), dash_prev_year($today));
    $d['year'] = dash_sales($yStart, $today);
    $d['year_prev'] = dash_sales(dash_prev_year($yStart), dash_prev_year($today));
    $st = $db->prepare('SELECT COUNT(*) FROM erp_invoices WHERE annulled = 0 AND total_cents > 0 AND inv_day >= ? AND inv_day <= ?');
    $st->execute([$mStart, $today]);
    $d['invoices_month'] = (int)$st->fetchColumn();

    // Pendiente de cobro (sin el cliente de contado: sus tickets se cobran en caja) y vencido
    $retailSql = $retail !== '' ? ' AND i.cust_code <> ?' : '';
    $args = $retail !== '' ? [$retail] : [];
    $st = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(i.total_cents), 0) AS t FROM erp_invoices i LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id
        WHERE i.annulled = 0 AND i.total_cents > 0 AND COALESCE(s.paid, 0) = 0' . $retailSql);
    $st->execute($args);
    $r = $st->fetch();
    $d['pending_n'] = (int)$r['n'];
    $d['pending'] = (int)$r['t'];
    // Vencido: cuotas sin cobrar de facturas del CMS cuyo día ya pasó (los vencimientos de NCS no se guardan)
    $st = $db->prepare("SELECT COUNT(DISTINCT h.id) AS n, COALESCE(SUM(u.amount_cents), 0) AS t FROM doc_dues u JOIN doc_headers h ON h.id = u.doc_id
        LEFT JOIN erp_invoices i ON i.inv_number = h.doc_code LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id
        WHERE u.paid = 0 AND u.due_day < ? AND h.status = 'emitida' AND h.is_test = 0 AND COALESCE(s.paid, 0) = 0");
    $st->execute([$today]);
    $r = $st->fetch();
    $d['overdue_n'] = (int)$r['n'];
    $d['overdue'] = (int)$r['t'];

    // Pendiente de facturar: pedidos (pendientes, parciales o servidos sin facturar) y albaranes pendientes
    $st = $db->query("SELECT doc_type, COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS t FROM doc_headers
        WHERE (doc_type = 'pedido' AND status IN ('pendiente', 'parcial', 'servido')) OR (doc_type = 'albaran' AND status = 'pendiente') GROUP BY doc_type");
    $d['orders_n'] = 0;
    $d['orders'] = 0;
    $d['notes_n'] = 0;
    $d['notes'] = 0;
    foreach ($st->fetchAll() as $r) {
        $k = $r['doc_type'] === 'pedido' ? 'orders' : 'notes';
        $d[$k . '_n'] = (int)$r['n'];
        $d[$k] = (int)$r['t'];
    }

    // Clientes
    $st = $db->prepare('SELECT COUNT(*) FROM erp_customers WHERE blocked = 0 AND ended_day = 0' . ($retail !== '' ? ' AND cust_code <> ?' : ''));
    $st->execute($retail !== '' ? [$retail] : []);
    $d['customers'] = (int)$st->fetchColumn();
    $st = $db->prepare('SELECT COUNT(*) FROM erp_customers WHERE created_day >= ?' . ($retail !== '' ? ' AND cust_code <> ?' : ''));
    $st->execute(array_merge([$mStart], $retail !== '' ? [$retail] : []));
    $d['customers_new'] = (int)$st->fetchColumn();

    // Facturas de prueba (solo si el modo de prueba está activo): aparte y marcadas
    $d['test'] = null;
    if (docp_bool('test_mode')) {
        $r = $db->query("SELECT COUNT(*) AS n, COALESCE(SUM(base_cents), 0) AS t FROM doc_headers WHERE is_test = 1 AND status = 'emitida'")->fetch();
        $d['test'] = ['n' => (int)$r['n'], 'total' => (int)$r['t']];
    }

    // Ventas por mes: 12 meses que terminan en el actual y los mismos del año anterior (una sola consulta)
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $t = $y * 12 + ($m - 1) - $i;
        $months[] = ['y' => intdiv($t, 12), 'm' => $t % 12 + 1];
    }
    $lo = ($months[0]['y'] - 1) * 10000 + $months[0]['m'] * 100 + 1;
    $st = $db->prepare('SELECT inv_year, inv_month, SUM(base_cents) AS t FROM erp_invoices WHERE annulled = 0 AND inv_day >= ? AND inv_day <= ? GROUP BY inv_year, inv_month');
    $st->execute([$lo, $today]);
    $byMonth = [];
    foreach ($st->fetchAll() as $r) {
        $byMonth[(int)$r['inv_year'] * 100 + (int)$r['inv_month']] = (int)$r['t'];
    }
    $d['months'] = [];
    foreach ($months as $mo) {
        $d['months'][] = ['label' => erp_month_label($mo['y'], $mo['m']), 'cur' => $byMonth[$mo['y'] * 100 + $mo['m']] ?? 0, 'prev' => $byMonth[($mo['y'] - 1) * 100 + $mo['m']] ?? 0,
            'title' => erp_month_label($mo['y'], $mo['m'], true)];
    }

    // Mejores clientes del periodo (sin el de contado)
    $st = $db->prepare('SELECT cust_code, SUM(base_cents) AS t FROM erp_invoices WHERE annulled = 0 AND inv_day >= ? AND inv_day <= ?' . ($retail !== '' ? ' AND cust_code <> ?' : '')
        . ' GROUP BY cust_code ORDER BY t DESC LIMIT 10');
    $st->execute(array_merge([$period['from'], $period['to']], $retail !== '' ? [$retail] : []));
    $top = $st->fetchAll();
    $names = [];
    if ($top) {
        $codes = array_column($top, 'cust_code');
        $q = $db->prepare('SELECT cust_code, name, trade_name FROM erp_customers WHERE cust_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')');
        $q->execute($codes);
        foreach ($q->fetchAll() as $r) {
            $names[(string)$r['cust_code']] = $r['trade_name'] !== '' ? (string)$r['trade_name'] : (string)$r['name'];
        }
    }
    $d['top_clients'] = [];
    foreach ($top as $r) {
        if ((int)$r['t'] > 0) {
            $c = (string)$r['cust_code'];
            $d['top_clients'][] = ['label' => ($names[$c] ?? '') !== '' ? $names[$c] : $c, 'value' => (int)$r['t'], 'href' => '/admin/clientes/' . rawurlencode($c)];
        }
    }

    // Artículos del periodo (una consulta agrupada): los 10 más vendidos y, con el mapa del catálogo, las familias
    $st = $db->prepare("SELECT l.article_code, MAX(l.title) AS title, SUM(l.qty_milli) AS q, SUM(l.amount_cents) AS a FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id
        WHERE i.annulled = 0 AND i.inv_day >= ? AND i.inv_day <= ? AND l.kind = 0 AND l.article_code <> '' GROUP BY l.article_code");
    $st->execute([$period['from'], $period['to']]);
    $arts = $st->fetchAll();
    usort($arts, fn($a, $b) => (int)$b['a'] <=> (int)$a['a']);
    $d['top_articles'] = [];
    foreach (array_slice($arts, 0, 10) as $a) {
        if ((int)$a['a'] > 0) {
            $d['top_articles'][] = ['label' => trim((string)$a['article_code'] . ' ' . (string)$a['title']), 'value' => (int)$a['a'], 'sub' => erp_qty((int)$a['q']) . ' uds.'];
        }
    }
    $map = erp_family_map();
    $fams = [];
    foreach ($arts as $a) {
        $f = $map[mb_strtolower((string)$a['article_code'])]['fam'] ?? 'Sin familia';
        $fams[$f] = ($fams[$f] ?? 0) + (int)$a['a'];
    }
    arsort($fams);
    $d['families'] = [];
    foreach ($fams as $f => $v) {
        if ($v > 0) {
            $d['families'][] = ['label' => (string)$f, 'value' => $v];
        }
    }
    $d['period_total'] = dash_sales($period['from'], $period['to']);
    return $d;
}

/** Listas rápidas: últimos documentos, próximos vencimientos (7 días), vencidos y borradores. */
function dash_lists(?int $today = null): array
{
    $db = db();
    $today ??= (int)date('Ymd');
    $in7 = (int)(new DateTime((string)$today))->modify('+7 days')->format('Ymd');
    $row = function (array $r): array {
        $k = DOCS_KINDS[$r['doc_type']] ?? ['label' => (string)$r['doc_type'], 'path' => '/admin/pedidos'];
        return ['label' => $k['label'], 'code' => $r['status'] === 'borrador' ? '(borrador)' : (string)$r['doc_code'], 'cust' => (string)$r['cust_name'], 'day' => (int)$r['doc_day'],
            'total' => (int)$r['total_cents'], 'status' => (int)$r['is_test'] === 1 ? 'Prueba' : (string)$r['status'], 'href' => $k['path'] . '/' . (int)$r['id']];
    };
    $cols = 'id, doc_type, doc_code, status, doc_day, cust_name, total_cents, is_test';
    $out = ['recent' => [], 'drafts' => [], 'soon' => [], 'overdue' => []];
    foreach ($db->query("SELECT $cols FROM doc_headers WHERE status <> 'borrador' ORDER BY id DESC LIMIT 8")->fetchAll() as $r) {
        $out['recent'][] = $row($r);
    }
    foreach ($db->query("SELECT $cols FROM doc_headers WHERE status = 'borrador' ORDER BY updated_at DESC, id DESC LIMIT 8")->fetchAll() as $r) {
        $out['drafts'][] = $row($r);
    }
    $base = "SELECT u.due_day, u.amount_cents, h.id, h.doc_code, h.cust_name FROM doc_dues u JOIN doc_headers h ON h.id = u.doc_id
        LEFT JOIN erp_invoices i ON i.inv_number = h.doc_code LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id
        WHERE u.paid = 0 AND h.status = 'emitida' AND h.is_test = 0 AND COALESCE(s.paid, 0) = 0 AND ";
    foreach (['soon' => [$base . 'u.due_day >= ? AND u.due_day <= ? ORDER BY u.due_day, h.id LIMIT 8', [$today, $in7]],
              'overdue' => [$base . 'u.due_day < ? ORDER BY u.due_day, h.id LIMIT 8', [$today]]] as $k => [$sql, $args]) {
        $st = $db->prepare($sql);
        $st->execute($args);
        foreach ($st->fetchAll() as $r) {
            $out[$k][] = ['code' => (string)$r['doc_code'], 'cust' => (string)$r['cust_name'], 'day' => (int)$r['due_day'], 'total' => (int)$r['amount_cents'], 'href' => '/admin/facturas/' . (int)$r['id']];
        }
    }
    return $out;
}

/** Estado del sistema para la tarjeta de avisos: copia, sincronización con NCS, correo, doble verificación y modo de prueba. */
function dash_system(): array
{
    $last = setting('last_backup_at');
    $days = $last !== '' ? (int)floor((time() - (int)strtotime($last)) / 86400) : null;
    $sync = (int)db()->query("SELECT COALESCE(MAX(synced_at), 0) FROM erp_customers WHERE origin = 'ncs'")->fetchColumn();
    return [
        'backup' => $last !== '' ? (int)strtotime($last) : 0, 'backup_days' => $days,
        'sync' => $sync, 'mail' => mail_smtp_config() !== null || filter_var(trim(setting('mail_from')), FILTER_VALIDATE_EMAIL) !== false, 'mail_err' => mail_last_error(),
        '2fa' => erp_2fa_required(), 'test' => docp_bool('test_mode'),
    ];
}

/* ---------- Gráficas en SVG (sin librerías) ---------- */

/** Barras por mes con el año anterior como línea. $m: [['label','cur','prev','title']]. */
function dash_svg_months(array $m, string $aria): string
{
    $w = 480;
    $h = 230;
    $padL = 6;
    $padR = 6;
    $top = 16;
    $bottom = 26;
    $vals = [0];
    foreach ($m as $x) {
        $vals[] = (int)$x['cur'];
        $vals[] = (int)$x['prev'];
    }
    $max = max($vals);
    $min = min($vals);
    $range = max($max - $min, 1);
    $plot = $h - $top - $bottom;
    $y0 = $top + $max / $range * $plot;
    $sc = $plot / $range;
    $n = max(1, count($m));
    $slot = ($w - $padL - $padR) / $n;
    $bw = $slot * 0.62;
    $o = '<svg class="db-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($aria) . '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">';
    $o .= '<line class="db-zero" x1="' . $padL . '" y1="' . sprintf('%.1f', $y0) . '" x2="' . ($w - $padR) . '" y2="' . sprintf('%.1f', $y0) . '"/>';
    $pts = [];
    foreach ($m as $i => $x) {
        $v = (int)$x['cur'];
        $bx = $padL + $i * $slot + ($slot - $bw) / 2;
        $bh = max(abs($v) * $sc, $v !== 0 ? 1.5 : 0);
        $by = $v >= 0 ? $y0 - $bh : $y0;
        $o .= '<rect class="db-bar' . ($v < 0 ? ' neg' : '') . '" x="' . sprintf('%.1f', $bx) . '" y="' . sprintf('%.1f', $by) . '" width="' . sprintf('%.1f', $bw) . '" height="' . sprintf('%.1f', $bh) . '" rx="2"><title>'
            . e($x['title'] . ': ' . erp_money($v) . ' (año anterior: ' . erp_money((int)$x['prev']) . ')') . '</title></rect>';
        $pts[] = sprintf('%.1f,%.1f', $bx + $bw / 2, $y0 - (int)$x['prev'] * $sc);
        if ($i % 2 === 1 || $i === $n - 1) {
            $o .= '<text class="db-ax" x="' . sprintf('%.1f', $bx + $bw / 2) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e($x['label']) . '</text>';
        }
    }
    $o .= '<polyline class="db-line" points="' . implode(' ', $pts) . '"/>';
    foreach ($pts as $p) {
        [$px, $py] = explode(',', $p);
        $o .= '<circle class="db-dot" cx="' . $px . '" cy="' . $py . '" r="2.6"/>';
    }
    return $o . '</svg>';
}

/** Barras horizontales. $rows: [['label','value','sub'?]]. */
function dash_svg_hbars(array $rows, string $aria): string
{
    $w = 440;
    $labelW = 138;
    $valW = 96;
    $rowH = 30;
    $h = count($rows) * $rowH + 6;
    $max = max(1, ...array_map(fn($r) => (int)$r['value'], $rows));
    $barMax = $w - $labelW - $valW - 6;
    $o = '<svg class="db-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($aria) . '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">';
    foreach ($rows as $i => $r) {
        $y = 3 + $i * $rowH;
        $bw = max(2, (int)$r['value'] / $max * $barMax);
        $label = mb_strimwidth((string)$r['label'], 0, 22, '…');
        $o .= '<text class="db-lb" x="0" y="' . ($y + 12) . '">' . e($label) . '</text>';
        if (!empty($r['sub'])) {
            $o .= '<text class="db-sb" x="0" y="' . ($y + 24) . '">' . e((string)$r['sub']) . '</text>';
        }
        $o .= '<rect class="db-bar" x="' . $labelW . '" y="' . ($y + 4) . '" width="' . sprintf('%.1f', $bw) . '" height="' . ($rowH - 12) . '" rx="3"><title>' . e((string)$r['label'] . ': ' . erp_money((int)$r['value'])) . '</title></rect>';
        $o .= '<text class="db-val" x="' . sprintf('%.1f', $labelW + $bw + 6) . '" y="' . ($y + 16) . '">' . e(erp_money((int)$r['value'])) . '</text>';
    }
    return $o . '</svg>';
}

/** Anillo con leyenda. $rows: [['label','value']] (los 7 mayores y «Otras»). */
function dash_svg_donut(array $rows, string $aria): string
{
    $rows = array_values($rows);
    if (count($rows) > 8) {
        $rest = array_sum(array_map(fn($r) => (int)$r['value'], array_slice($rows, 7)));
        $rows = array_merge(array_slice($rows, 0, 7), [['label' => 'Otras', 'value' => $rest]]);
    }
    $total = max(1, array_sum(array_map(fn($r) => (int)$r['value'], $rows)));
    $w = 440;
    $h = max(170, count($rows) * 22 + 20);
    $cx = 85;
    $cy = $h / 2;
    $R = 72;
    $r0 = 44;
    $o = '<svg class="db-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($aria) . '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">';
    $a0 = -M_PI / 2;
    foreach ($rows as $i => $r) {
        $frac = (int)$r['value'] / $total;
        $a1 = $a0 + $frac * 2 * M_PI;
        if ($frac >= 0.9999) {
            $o .= '<circle class="db-s' . ($i % 8) . '" cx="' . $cx . '" cy="' . $cy . '" r="' . $R . '"/><circle class="db-hole" cx="' . $cx . '" cy="' . $cy . '" r="' . $r0 . '"/>';
        } else {
            $large = $frac > 0.5 ? 1 : 0;
            $p = fn(float $rad, float $a) => sprintf('%.2f %.2f', $cx + $rad * cos($a), $cy + $rad * sin($a));
            $o .= '<path class="db-s' . ($i % 8) . '" d="M' . $p($R, $a0) . ' A' . $R . ' ' . $R . ' 0 ' . $large . ' 1 ' . $p($R, $a1) . ' L' . $p($r0, $a1) . ' A' . $r0 . ' ' . $r0 . ' 0 ' . $large . ' 0 ' . $p($r0, $a0) . ' Z"><title>'
                . e($r['label'] . ': ' . erp_money((int)$r['value'])) . '</title></path>';
        }
        $ly = 14 + $i * 22;
        $o .= '<rect class="db-s' . ($i % 8) . '" x="185" y="' . ($ly - 9) . '" width="11" height="11" rx="2"/><text class="db-lb" x="202" y="' . $ly . '">' . e(mb_strimwidth((string)$r['label'], 0, 20, '…')) . '</text>'
            . '<text class="db-sb" x="' . ($w - 2) . '" y="' . $ly . '" text-anchor="end">' . e(number_format($frac * 100, 1, ',', '.')) . ' %</text>';
        $a0 = $a1;
    }
    return $o . '</svg>';
}
