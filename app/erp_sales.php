<?php
declare(strict_types=1);

/*
 * Ventas (/admin/ventas = facturas) e Informes (/admin/informes/<pestaña>): facturas con filtros, totales por mes, artículos más vendidos y tallas y colores más vendidos
 * (por artículo o por familia). Solo roles erp/admin con la doble verificación exigida (erp_guard()).
 * Todas las consultas son agrupadas; la familia se suma en PHP con erp_family_map() (products.ref no tiene índice y
 * SQL Server limita a 2.100 los parámetros de una lista IN). Las devoluciones (importe o unidades negativas) restan y se
 * ven aparte; las facturas anuladas no cuentan nunca en los totales.
 */

const ERP_SALES_TABS = ['facturas' => 'Facturas', 'meses' => 'Totales por mes', 'articulos' => 'Artículos más vendidos', 'tallas' => 'Tallas y colores'];
/** Informes: las tres pestañas que no son la lista de facturas (/admin/informes/<pestaña>). */
const ERP_REPORT_TABS = ['meses' => 'Totales por mes', 'articulos' => 'Artículos más vendidos', 'tallas' => 'Tallas y colores'];
const ERP_INV_SORTS = ['fecha' => 'i.inv_day', 'numero' => 'i.inv_number', 'total' => 'i.total_cents', 'cliente' => 'c.name'];

/** Filtros comunes (lista cerrada). */
function erp_sales_filters(array $src): array
{
    $retail = erp_retail_code();
    $ambito = (string)($src['ambito'] ?? '');
    $sort = (string)($src['orden'] ?? 'fecha');
    $f = [
        'anio' => (int)($src['anio'] ?? 0) >= 2000 && (int)$src['anio'] <= 2100 ? (int)$src['anio'] : 0,
        'mes' => (int)($src['mes'] ?? 0) >= 1 && (int)$src['mes'] <= 12 ? (int)$src['mes'] : 0,
        'ambito' => $retail !== '' && in_array($ambito, ['mostrador', 'clientes'], true) ? $ambito : '',
        'q' => mb_substr(trim((string)($src['q'] ?? '')), 0, 100),
        'ver' => in_array($src['ver'] ?? '', ['dev', 'anul'], true) ? (string)$src['ver'] : '',
        'orden' => isset(ERP_INV_SORTS[$sort]) && $sort !== 'fecha' ? $sort : '',
        'dir' => ($src['dir'] ?? '') === 'asc' ? 'asc' : '',
        'pag' => max(1, (int)($src['pag'] ?? 1)) > 1 ? (string)max(1, (int)$src['pag']) : '',
        'art' => mb_substr(trim((string)($src['art'] ?? '')), 0, 60),
        'fam' => (int)($src['fam'] ?? 0) > 0 ? (int)$src['fam'] : 0,
        'por' => ($src['por'] ?? '') === 'importe' ? 'importe' : '',
        'origen' => in_array($src['origen'] ?? '', ['ncs', 'cms'], true) ? (string)$src['origen'] : '',
    ];
    return array_filter($f, fn($v) => $v !== '' && $v !== 0);
}

function erp_sales_url(string $tab, array $f, array $change = []): string
{
    $q = array_filter(array_merge($f, $change), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return ($tab !== 'facturas' ? '/admin/informes/' . $tab : '/admin/ventas') . ($q ? '?' . http_build_query($q) : '');
}

/** Condiciones por año, mes y ámbito sobre la tabla erp_invoices con alias i. */
function erp_sales_cond(array $f): array
{
    $w = '';
    $a = [];
    if (!empty($f['anio'])) {
        $w .= ' AND i.inv_year = ?';
        $a[] = $f['anio'];
    }
    if (!empty($f['mes'])) {
        $w .= ' AND i.inv_month = ?';
        $a[] = $f['mes'];
    }
    if (!empty($f['origen'])) { // facturas importadas de NCS o emitidas en el CMS
        $w .= ' AND i.origin = ?';
        $a[] = $f['origen'];
    }
    $retail = erp_retail_code();
    if (($f['ambito'] ?? '') === 'mostrador') {
        $w .= ' AND i.cust_code = ?';
        $a[] = $retail;
    } elseif (($f['ambito'] ?? '') === 'clientes') {
        $w .= ' AND i.cust_code <> ?';
        $a[] = $retail;
    }
    return [$w, $a];
}

function erp_sales_years(): array
{
    return array_map('intval', db()->query('SELECT DISTINCT inv_year FROM erp_invoices WHERE inv_year > 0 ORDER BY inv_year DESC')->fetchAll(PDO::FETCH_COLUMN));
}

/** Pantalla Ventas: despacha la pestaña. */
function admin_sales(string $tab): void
{
    if (!erp_guard()) {
        return;
    }
    $tab = isset(ERP_SALES_TABS[$tab]) ? $tab : 'facturas';
    if (function_exists('docs_mirror_sync')) {
        docs_mirror_sync(); // facturas del CMS que falten aquí (p. ej. tras restaurar una copia sin clientes y ventas)
    }
    $f = erp_sales_filters($_GET);
    $v = ['tab' => $tab, 'f' => $f, 'years' => erp_sales_years(), 'retail' => erp_retail_code(), 'totals' => erp_totals(), 'title' => $tab === 'facturas' ? 'Facturas' : 'Informes'];
    [$cond, $args] = erp_sales_cond($f);
    $db = db();

    if ($tab === 'facturas') {
        $w = $cond;
        $a = $args;
        if (($f['q'] ?? '') !== '') {
            $like = '%' . $f['q'] . '%';
            $w .= ' AND (i.inv_number LIKE ? OR i.cust_code LIKE ? OR c.name LIKE ? OR c.trade_name LIKE ? OR d.cust_name LIKE ?)';
            array_push($a, $like, $like, $like, $like, $like);
        }
        if (($f['ver'] ?? '') === 'dev') {
            $w .= ' AND i.total_cents < 0';
        } elseif (($f['ver'] ?? '') === 'anul') {
            $w .= ' AND i.annulled = 1';
        }
        $st = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN i.annulled = 0 THEN i.total_cents ELSE 0 END), 0) AS tot, COALESCE(SUM(CASE WHEN i.annulled = 0 THEN i.base_cents ELSE 0 END), 0) AS base,
                COALESCE(SUM(CASE WHEN i.annulled = 0 THEN i.vat_cents ELSE 0 END), 0) AS vat
            FROM erp_invoices i LEFT JOIN erp_customers c ON c.cust_code = i.cust_code LEFT JOIN doc_headers d ON d.doc_code = i.inv_number WHERE 1=1' . $w);
        $st->execute($a);
        $sum = $st->fetch();
        $pages = max(1, (int)ceil((int)$sum['n'] / ERP_PER_PAGE));
        $page = min($pages, (int)($f['pag'] ?? 1));
        $sortKey = $f['orden'] ?? 'fecha';
        $dir = ($f['dir'] ?? '') === 'asc' ? 'ASC' : 'DESC';
        $order = ERP_INV_SORTS[$sortKey] . ' ' . $dir . ($sortKey === 'fecha' ? ', i.id ' . $dir : ', i.inv_day DESC, i.id DESC');
        $st = $db->prepare('SELECT i.id, i.inv_number, i.inv_day, i.cust_code, i.base_cents, i.vat_cents, i.total_cents, i.annulled, i.origin, COALESCE(c.name, d.cust_name) AS cname, c.trade_name AS ctrade
            FROM erp_invoices i LEFT JOIN erp_customers c ON c.cust_code = i.cust_code LEFT JOIN doc_headers d ON d.doc_code = i.inv_number WHERE 1=1' . $w . ' ORDER BY ' . $order . ' LIMIT ' . ERP_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * ERP_PER_PAGE) : ''));
        $st->execute($a);
        $v += ['rows' => $st->fetchAll(), 'sum' => $sum, 'page' => $page, 'pages' => $pages];
    } elseif ($tab === 'meses') {
        $st = $db->prepare('SELECT i.inv_year, i.inv_month, COUNT(*) AS n, SUM(CASE WHEN i.total_cents > 0 THEN i.total_cents ELSE 0 END) AS sales,
                SUM(CASE WHEN i.total_cents < 0 THEN i.total_cents ELSE 0 END) AS ret, SUM(i.total_cents) AS net
            FROM erp_invoices i WHERE i.annulled = 0 AND i.inv_year > 0' . $cond . ' GROUP BY i.inv_year, i.inv_month ORDER BY i.inv_year DESC, i.inv_month DESC');
        $st->execute($args);
        $v += ['rows' => $st->fetchAll()];
    } elseif ($tab === 'articulos') {
        $by = ($f['por'] ?? '') === 'importe' ? 'SUM(l.amount_cents)' : 'SUM(l.qty_milli)';
        $base = 'FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id WHERE i.annulled = 0' . $cond;
        $st = $db->prepare('SELECT COUNT(*) FROM (SELECT l.article_code ' . $base . ' GROUP BY l.article_code) t');
        $st->execute($args);
        $total = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($total / ERP_PER_PAGE));
        $page = min($pages, (int)($f['pag'] ?? 1));
        $st = $db->prepare('SELECT l.article_code, MAX(l.title) AS title, SUM(l.qty_milli) AS qty, SUM(l.amount_cents) AS amount, COUNT(DISTINCT i.id) AS n_inv,
                SUM(CASE WHEN l.qty_milli < 0 THEN l.qty_milli ELSE 0 END) AS ret_qty ' . $base . ' GROUP BY l.article_code ORDER BY ' . $by . ' DESC, l.article_code LIMIT ' . ERP_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * ERP_PER_PAGE) : ''));
        $st->execute($args);
        $v += ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'map' => erp_family_map()];
    } else { // tallas y colores
        $map = erp_family_map();
        $fam = (int)($f['fam'] ?? 0);
        $art = (string)($f['art'] ?? '');
        $w = $cond;
        $a = $args;
        if ($art !== '') {
            $w .= ' AND l.article_code = ?';
            $a[] = $art;
        }
        $st = $db->prepare('SELECT l.article_code, l.size_no, l.size_txt, l.color_no, l.color_txt, MAX(l.title) AS title, SUM(l.qty_milli) AS q
            FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id WHERE i.annulled = 0' . $w . '
            GROUP BY l.article_code, l.size_no, l.size_txt, l.color_no, l.color_txt');
        $st->execute($a);
        $sizes = [];
        $colors = [];
        $noSize = 0;
        $units = 0;
        $title = '';
        foreach ($st->fetchAll() as $r) {
            if ($fam > 0 && (($map[mb_strtolower((string)$r['article_code'])]['fam_id'] ?? 0) !== $fam)) {
                continue;
            }
            $q = (int)$r['q'];
            $units += $q;
            $title = $title !== '' ? $title : (string)$r['title'];
            $sl = $r['size_txt'] !== '' ? (string)$r['size_txt'] : ((int)$r['size_no'] > 0 ? 'nº ' . $r['size_no'] : '');
            $cl = $r['color_txt'] !== '' ? (string)$r['color_txt'] : ((int)$r['color_no'] > 0 ? 'nº ' . $r['color_no'] : '');
            if ($sl === '') {
                $noSize += $q;
            } else {
                $sizes[$sl] = ($sizes[$sl] ?? 0) + $q;
            }
            if ($cl !== '') {
                $colors[$cl] = ($colors[$cl] ?? 0) + $q;
            }
        }
        uksort($sizes, 'strnatcasecmp');
        arsort($colors);
        $fams = $db->query('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY sort, name')->fetchAll();
        $v += ['sizes' => $sizes, 'colors' => array_slice($colors, 0, 30, true), 'noSize' => $noSize, 'units' => $units, 'artTitle' => $art !== '' ? $title : '', 'fams' => $fams, 'map' => $map];
    }
    admin_render('sales_' . $tab, $v);
}
