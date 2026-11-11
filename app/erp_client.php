<?php
declare(strict_types=1);

/*
 * Ficha de cliente con aspecto de ERP (/admin/clientes/<código>): cabecera con etiquetas y acciones, tarjetas de cifras,
 * lateral de contacto y cuatro pestañas por enlaces (?pestana=resumen|facturas|articulos|notas). Cada pestaña lanza solo
 * sus consultas y todas son AGRUPADAS: nunca una consulta por factura ni por línea (el cliente de contado tiene miles).
 * Solo roles erp/admin y con la doble verificación exigida (erp_guard() ya se comprobó en admin_clients()).
 */

const ERP_CLIENT_TABS = ['resumen' => 'Resumen', 'facturas' => 'Facturas', 'articulos' => 'Artículos comprados', 'notas' => 'Notas internas'];
const ERP_NOTE_MAX = 2000;
const ERP_MONTHS_ES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const ERP_ART_SORTS = ['importe' => 'SUM(l.amount_cents)', 'unidades' => 'SUM(l.qty_milli)', 'ultima' => 'MAX(i.inv_day)'];

/** Catálogo por referencia (una consulta por petición): [referencia en minúsculas => ['id', 'fam_id', 'fam']]. */
function erp_family_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $q = db()->query('SELECT p.id, p.ref, COALESCE(pc.id, c.id) AS fam_id, COALESCE(pc.name, c.name) AS fam_name
            FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN categories pc ON pc.id = c.parent_id');
        foreach ($q->fetchAll() as $r) {
            if ((string)$r['ref'] !== '') {
                $map[mb_strtolower((string)$r['ref'])] = ['id' => (int)$r['id'], 'fam_id' => (int)$r['fam_id'], 'fam' => (string)$r['fam_name']];
            }
        }
    }
    return $map;
}

function erp_month_label(int $y, int $m, bool $long = false): string
{
    return ERP_MONTHS_ES[$m - 1] . ' ' . ($long ? (string)$y : sprintf('%02d', $y % 100));
}

/** Gráfico de barras en SVG hecho en PHP (sin librerías). $m: lista de ['label', 'title', 'v' (céntimos)]. Devuelve el <svg>. */
function erp_svg_months(array $m, string $aria): string
{
    $w = 720;
    $h = 230;
    $padX = 6;
    $top = 14;
    $bottom = 30;
    $vals = array_map(fn($x) => (int)$x['v'], $m);
    $max = max(0, ...$vals);
    $min = min(0, ...$vals);
    $range = max($max - $min, 1);
    $plot = $h - $top - $bottom;
    $y0 = $top + $max / $range * $plot;
    $scale = $plot / $range;
    $n = max(1, count($m));
    $slot = ($w - 2 * $padX) / $n;
    $bw = $slot * 0.68;
    $o = '<svg class="cf-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($aria) . '" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg">';
    $o .= '<line class="cf-zero" x1="' . $padX . '" y1="' . sprintf('%.2f', $y0) . '" x2="' . ($w - $padX) . '" y2="' . sprintf('%.2f', $y0) . '"/>';
    foreach ($m as $i => $x) {
        $v = (int)$x['v'];
        $bx = $padX + $i * $slot + ($slot - $bw) / 2;
        $bh = max(abs($v) * $scale, $v !== 0 ? 1.5 : 0);
        $by = $v >= 0 ? $y0 - $bh : $y0;
        $o .= '<rect class="cf-bar ' . ($v > 0 ? 'pos' : ($v < 0 ? 'neg' : 'nil')) . '" x="' . sprintf('%.2f', $bx) . '" y="' . sprintf('%.2f', $by) . '" width="' . sprintf('%.2f', $bw) . '" height="' . sprintf('%.2f', $bh) . '" rx="2"><title>' . e($x['title']) . '</title></rect>';
        if ($i % 3 === 0 || $i === count($m) - 1) {
            $o .= '<text class="cf-ax" x="' . sprintf('%.2f', $bx + $bw / 2) . '" y="' . ($h - 10) . '" text-anchor="middle">' . e($x['label']) . '</text>';
        }
    }
    return $o . '</svg>';
}

/** Celda de texto de un CSV: neutraliza fórmulas (=, +, -, @) para que Excel no las ejecute. */
function erp_csv_text(string $s): string
{
    return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
}

function erp_csv_num(int $cents): string
{
    return number_format($cents / 100, 2, ',', '');
}

/** Descarga en CSV (para Excel en español: «;», coma decimal, UTF-8 con BOM) de las facturas del cliente, con o sin líneas. */
function erp_client_export(array $c, bool $withLines): void
{
    @set_time_limit(300);
    $db = db();
    $name = 'facturas-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)$c['cust_code']) . '-' . date('Y-m-d') . ($withLines ? '-con-lineas' : '') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    $put = fn(array $row) => fputcsv($out, $row, ';', '"', '\\');
    if ($withLines) {
        $put(['Factura', 'Fecha', 'Estado', 'Línea', 'Artículo', 'Descripción', 'Talla', 'Color', 'Unidades', 'Precio', 'Dto. %', 'Importe']);
        $st = $db->prepare('SELECT i.inv_number, i.inv_day, i.annulled, l.line_no, l.article_code, l.title, l.size_txt, l.size_no, l.color_txt, l.color_no, l.qty_milli, l.price_cents, l.disc_bp, l.amount_cents
            FROM erp_invoices i LEFT JOIN erp_invoice_lines l ON l.invoice_id = i.id WHERE i.cust_code = ? ORDER BY i.inv_day, i.id, l.line_no');
        $st->execute([$c['cust_code']]);
        while ($r = $st->fetch()) {
            $put([erp_csv_text((string)$r['inv_number']), erp_day((int)$r['inv_day']), (int)$r['annulled'] === 1 ? 'anulada' : '', (string)$r['line_no'],
                erp_csv_text((string)$r['article_code']), erp_csv_text((string)$r['title']),
                erp_csv_text((string)($r['size_txt'] !== '' ? $r['size_txt'] : ((int)$r['size_no'] > 0 ? 'nº ' . $r['size_no'] : ''))),
                erp_csv_text((string)($r['color_txt'] !== '' ? $r['color_txt'] : ((int)$r['color_no'] > 0 ? 'nº ' . $r['color_no'] : ''))),
                $r['qty_milli'] === null ? '' : erp_qty((int)$r['qty_milli']), $r['price_cents'] === null ? '' : erp_csv_num((int)$r['price_cents']),
                $r['disc_bp'] === null ? '' : erp_csv_num((int)$r['disc_bp']), $r['amount_cents'] === null ? '' : erp_csv_num((int)$r['amount_cents'])]);
        }
    } else {
        $put(['Factura', 'Fecha', 'Base', 'IVA', 'Total', 'Estado', 'Vendedor', 'Forma de pago (código)']);
        $st = $db->prepare('SELECT inv_number, inv_day, base_cents, vat_cents, total_cents, annulled, seller, pay_code FROM erp_invoices WHERE cust_code = ? ORDER BY inv_day, id');
        $st->execute([$c['cust_code']]);
        while ($r = $st->fetch()) {
            $put([erp_csv_text((string)$r['inv_number']), erp_day((int)$r['inv_day']), erp_csv_num((int)$r['base_cents']), erp_csv_num((int)$r['vat_cents']), erp_csv_num((int)$r['total_cents']),
                (int)$r['annulled'] === 1 ? 'anulada' : ((int)$r['total_cents'] < 0 ? 'devolución' : ''), erp_csv_text((string)$r['seller']), erp_csv_text((string)$r['pay_code'])]);
        }
    }
    fclose($out);
    exit;
}

/** Cliente anterior y siguiente por orden de nombre (el de contado queda fuera del recorrido, salvo estando en él). */
function erp_client_neighbours(array $c): array
{
    $db = db();
    $retail = erp_retail_code();
    $skip = $retail !== '' && $retail !== $c['cust_code'];
    $res = [];
    foreach (['prev' => ['<', 'DESC'], 'next' => ['>', 'ASC']] as $k => [$op, $dir]) {
        $st = $db->prepare('SELECT cust_code, name FROM erp_customers WHERE (name ' . $op . ' ? OR (name = ? AND id ' . $op . ' ?))' . ($skip ? ' AND cust_code <> ?' : '')
            . ' ORDER BY name ' . $dir . ', id ' . $dir . ' LIMIT 1');
        $st->execute(array_merge([$c['name'], $c['name'], (int)$c['id']], $skip ? [$retail] : []));
        $res[$k] = $st->fetch() ?: null;
    }
    return $res;
}

/** Ficha de un cliente. */
function admin_client(string $code): void
{
    $db = db();
    $st = $db->prepare('SELECT * FROM erp_customers WHERE cust_code = ?');
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c) {
        not_found();
        return;
    }
    $self = '/admin/clientes/' . rawurlencode($code);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'export') {
            erp_client_export($c, !empty($_POST['lineas']));
        }
        if ($act === 'note_add') {
            $note = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['note'] ?? ''))), 0, ERP_NOTE_MAX);
            if ($note === '') {
                flash('Escribe la nota.', 'err');
            } else {
                $u = current_user();
                $db->prepare('INSERT INTO erp_notes (cust_code, note, user_id, username, created_at) VALUES (?,?,?,?,?)')
                    ->execute([$code, $note, (int)$_SESSION['uid'], mb_substr((string)$u['username'], 0, 100), time()]);
                flash('Nota guardada.');
            }
            redirect($self . '?pestana=notas');
        }
        if ($act === 'note_del') {
            $st = $db->prepare('SELECT id, user_id FROM erp_notes WHERE id = ? AND cust_code = ?');
            $st->execute([(int)($_POST['id'] ?? 0), $code]);
            $n = $st->fetch();
            if ($n && (is_admin() || (int)$n['user_id'] === (int)$_SESSION['uid'])) {
                $db->prepare('DELETE FROM erp_notes WHERE id = ?')->execute([(int)$n['id']]);
                flash('Nota borrada.');
            } else {
                flash('No puedes borrar esa nota: solo su autor o un administrador.', 'err');
            }
            redirect($self . '?pestana=notas');
        }
        redirect($self);
    }

    $tab = (string)($_GET['pestana'] ?? 'resumen');
    $tab = isset(ERP_CLIENT_TABS[$tab]) ? $tab : 'resumen';
    $year = (int)($_GET['anio'] ?? 0);
    $year = $year >= 2000 && $year <= 2100 ? $year : (int)date('Y');
    $retailCode = erp_retail_code();

    // Cifras: una consulta por años y otra con primera/última compra
    $st = $db->prepare('SELECT inv_year, COUNT(*) AS n, SUM(total_cents) AS tot,
            SUM(CASE WHEN total_cents > 0 THEN total_cents ELSE 0 END) AS pos, SUM(CASE WHEN total_cents > 0 THEN 1 ELSE 0 END) AS npos
        FROM erp_invoices WHERE cust_code = ? AND annulled = 0 GROUP BY inv_year');
    $st->execute([$code]);
    $byYear = [];
    foreach ($st->fetchAll() as $r) {
        $byYear[(int)$r['inv_year']] = ['n' => (int)$r['n'], 'tot' => (int)$r['tot'], 'pos' => (int)$r['pos'], 'npos' => (int)$r['npos']];
    }
    $nInv = array_sum(array_column($byYear, 'n'));
    $pos = array_sum(array_column($byYear, 'pos'));
    $nPos = array_sum(array_column($byYear, 'npos'));
    $st = $db->prepare('SELECT MIN(inv_day) AS first_day, MAX(inv_day) AS last_day FROM erp_invoices WHERE cust_code = ? AND annulled = 0');
    $st->execute([$code]);
    $fl = $st->fetch();
    $first = (int)($fl['first_day'] ?? 0);
    $last = (int)($fl['last_day'] ?? 0);
    $cur = $byYear[$year]['tot'] ?? 0;
    $prev = $byYear[$year - 1]['tot'] ?? 0;
    $stats = [
        'year' => $year, 'cur' => $cur, 'prev' => $prev,
        'diff' => $prev !== 0 ? round(($cur - $prev) / abs($prev) * 100, 1) : null,
        'n' => $nInv, 'avg' => $nPos > 0 ? intdiv($pos, $nPos) : 0, 'first' => $first, 'last' => $last,
        'stale' => $last > 0 && $last < (int)(new DateTime('-6 months'))->format('Ymd'),
        'years' => array_values(array_unique(array_merge(array_keys($byYear), [(int)date('Y'), (int)date('Y') - 1, $year]))),
    ];
    rsort($stats['years']);
    $st = $db->prepare('SELECT COUNT(*) FROM erp_notes WHERE cust_code = ?');
    $st->execute([$code]);
    $nNotes = (int)$st->fetchColumn();

    $v = [
        'c' => $c, 'self' => $self, 'tab' => $tab, 'stats' => $stats, 'nNotes' => $nNotes, 'isRetail' => $retailCode === $code,
        'nb' => erp_client_neighbours($c), 'crumb' => $c['trade_name'] !== '' ? $c['trade_name'] : ($c['name'] !== '' ? $c['name'] : $c['cust_code']),
        'title' => 'Cliente',
    ];

    if ($tab === 'resumen') {
        // 24 meses que terminan en el mes actual (o, si hace más de 24 meses que no compra, en su última compra)
        $ey = (int)date('Y');
        $em = (int)date('n');
        $startOfWindow = (int)((new DateTime('first day of this month'))->modify('-23 months')->format('Ymd'));
        $note = '';
        if ($last > 0 && $last < $startOfWindow) {
            $ey = intdiv($last, 10000);
            $em = intdiv($last, 100) % 100;
            $note = 'Este cliente no compra desde ' . erp_month_label($ey, $em, true) . ': se enseñan los 24 meses hasta su última compra.';
        }
        $months = [];
        for ($i = 23; $i >= 0; $i--) {
            $t = $ey * 12 + ($em - 1) - $i;
            $months[] = ['y' => intdiv($t, 12), 'm' => $t % 12 + 1, 'v' => 0];
        }
        $lo = $months[0]['y'] * 10000 + $months[0]['m'] * 100;
        $hi = $months[23]['y'] * 10000 + $months[23]['m'] * 100 + 31;
        $st = $db->prepare('SELECT inv_year, inv_month, SUM(total_cents) AS tot FROM erp_invoices WHERE cust_code = ? AND annulled = 0 AND inv_day >= ? AND inv_day <= ? GROUP BY inv_year, inv_month');
        $st->execute([$code, $lo, $hi]);
        $tot = [];
        foreach ($st->fetchAll() as $r) {
            $tot[(int)$r['inv_year'] * 100 + (int)$r['inv_month']] = (int)$r['tot'];
        }
        $series = [];
        foreach ($months as $mo) {
            $val = $tot[$mo['y'] * 100 + $mo['m']] ?? 0;
            $series[] = ['label' => erp_month_label($mo['y'], $mo['m']), 'title' => erp_month_label($mo['y'], $mo['m'], true) . ': ' . erp_money($val), 'v' => $val];
        }
        // Artículos (una consulta agrupada) y, con el mapa del catálogo, familias
        $st = $db->prepare('SELECT l.article_code, MAX(l.title) AS title, SUM(l.qty_milli) AS qty, SUM(l.amount_cents) AS amount
            FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id WHERE i.cust_code = ? AND i.annulled = 0 GROUP BY l.article_code');
        $st->execute([$code]);
        $arts = $st->fetchAll();
        usort($arts, fn($a, $b) => (int)$b['amount'] <=> (int)$a['amount']);
        $map = erp_family_map();
        $fams = [];
        foreach ($arts as $a) {
            $f = $map[mb_strtolower((string)$a['article_code'])]['fam'] ?? 'Sin familia en el catálogo';
            $fams[$f]['amount'] = ($fams[$f]['amount'] ?? 0) + (int)$a['amount'];
            $fams[$f]['qty'] = ($fams[$f]['qty'] ?? 0) + (int)$a['qty'];
        }
        uasort($fams, fn($a, $b) => $b['amount'] <=> $a['amount']);
        $v += ['series' => $series, 'chartNote' => $note, 'topArts' => array_slice($arts, 0, 5), 'topFams' => array_slice($fams, 0, 5, true), 'map' => $map,
            'svg' => erp_svg_months($series, 'Compras por mes de los últimos 24 meses')];
    } elseif ($tab === 'facturas') {
        $st = $db->prepare('SELECT COUNT(*) FROM erp_invoices WHERE cust_code = ?');
        $st->execute([$code]);
        $total = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($total / ERP_PER_PAGE));
        $page = min($pages, max(1, (int)($_GET['pag'] ?? 1)));
        $st = $db->prepare('SELECT id, inv_number, inv_day, base_cents, vat_cents, total_cents, annulled, seller FROM erp_invoices WHERE cust_code = ? ORDER BY inv_day DESC, id DESC LIMIT ' . ERP_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * ERP_PER_PAGE) : ''));
        $st->execute([$code]);
        $invoices = $st->fetchAll();
        $lines = [];
        if ($invoices) { // las líneas de las facturas de la página, en UNA consulta
            $ids = array_map(fn($i) => (int)$i['id'], $invoices);
            $st = $db->prepare('SELECT invoice_id, article_code, title, qty_milli, price_cents, disc_bp, amount_cents, size_no, size_txt, color_no, color_txt FROM erp_invoice_lines WHERE invoice_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY invoice_id, line_no, id');
            $st->execute($ids);
            foreach ($st->fetchAll() as $l) {
                $lines[(int)$l['invoice_id']][] = $l;
            }
        }
        $v += ['invoices' => $invoices, 'lines' => $lines, 'page' => $page, 'pages' => $pages, 'total' => $total, 'map' => erp_family_map()];
    } elseif ($tab === 'articulos') {
        $sort = (string)($_GET['orden'] ?? 'importe');
        $sort = isset(ERP_ART_SORTS[$sort]) ? $sort : 'importe';
        $asc = ($_GET['dir'] ?? '') === 'asc';
        $st = $db->prepare('SELECT COUNT(*) FROM (SELECT l.article_code FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id WHERE i.cust_code = ? AND i.annulled = 0 GROUP BY l.article_code) t');
        $st->execute([$code]);
        $total = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($total / ERP_PER_PAGE));
        $page = min($pages, max(1, (int)($_GET['pag'] ?? 1)));
        $st = $db->prepare('SELECT l.article_code, MAX(l.title) AS title, SUM(l.qty_milli) AS qty, SUM(l.amount_cents) AS amount, COUNT(DISTINCT i.id) AS n_inv, MAX(i.inv_day) AS last_day
            FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id WHERE i.cust_code = ? AND i.annulled = 0
            GROUP BY l.article_code ORDER BY ' . ERP_ART_SORTS[$sort] . ($asc ? ' ASC' : ' DESC') . ', l.article_code LIMIT ' . ERP_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * ERP_PER_PAGE) : ''));
        $st->execute([$code]);
        $rows = $st->fetchAll();
        $sizes = [];
        $colors = [];
        if ($rows) { // tallas y colores de los artículos de la página, en UNA consulta agrupada
            $codes = array_column($rows, 'article_code');
            $st = $db->prepare('SELECT l.article_code, l.size_no, l.size_txt, l.color_no, l.color_txt, SUM(l.qty_milli) AS q
                FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id
                WHERE i.cust_code = ? AND i.annulled = 0 AND l.article_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')
                GROUP BY l.article_code, l.size_no, l.size_txt, l.color_no, l.color_txt');
            $st->execute(array_merge([$code], $codes));
            foreach ($st->fetchAll() as $r) {
                $s = $r['size_txt'] !== '' ? (string)$r['size_txt'] : ((int)$r['size_no'] > 0 ? 'nº ' . $r['size_no'] : '');
                $co = $r['color_txt'] !== '' ? (string)$r['color_txt'] : ((int)$r['color_no'] > 0 ? 'nº ' . $r['color_no'] : '');
                if ($s !== '') {
                    $sizes[$r['article_code']][$s] = ($sizes[$r['article_code']][$s] ?? 0) + (int)$r['q'];
                }
                if ($co !== '') {
                    $colors[$r['article_code']][$co] = ($colors[$r['article_code']][$co] ?? 0) + (int)$r['q'];
                }
            }
        }
        $v += ['rows' => $rows, 'sizes' => $sizes, 'colors' => $colors, 'page' => $page, 'pages' => $pages, 'total' => $total, 'sort' => $sort, 'asc' => $asc, 'map' => erp_family_map()];
    } else { // notas
        $st = $db->prepare('SELECT id, note, user_id, username, created_at FROM erp_notes WHERE cust_code = ? ORDER BY created_at DESC, id DESC LIMIT 200');
        $st->execute([$code]);
        $v += ['notes' => $st->fetchAll()];
    }
    admin_render('client', $v);
}
