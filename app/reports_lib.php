<?php
declare(strict_types=1);

/*
 * Piezas compartidas por varios informes de app/reports/*.php: listados de pedidos / albaranes / facturas y ventas por cliente,
 * artículo y familia. Un informe nuevo puede reutilizarlas o definirse entero en su archivo.
 */

/* ---------- Pedidos, albaranes y facturas ---------- */

function rep_docs_define(string $kind): array
{
    $isInv = $kind === 'factura';
    $label = ['pedido' => 'Pedidos', 'albaran' => 'Albaranes', 'factura' => 'Facturas'][$kind];
    $statusOpts = [];
    if (!$isInv) {
        foreach (DOCS_STATUS[$kind] as $k => $l) {
            if ($k !== 'borrador') {
                $statusOpts[$k] = $l;
            }
        }
    }
    $filters = [
        ['name' => 'fecha', 'label' => 'Fecha', 'type' => 'date'],
        ['name' => 'numero', 'label' => $isInv ? 'Número' : 'Número (solo la cifra)', 'type' => 'code'],
        ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'code', 'lookup' => 'cliente'],
    ];
    if ($isInv) {
        $filters[] = ['name' => 'serie', 'label' => 'Serie (empieza por)', 'type' => 'text'];
        $filters[] = ['name' => 'origen', 'label' => 'Origen', 'type' => 'select', 'options' => ['ncs' => 'Importadas de NCS', 'cms' => 'Emitidas en el CMS']];
        $filters[] = ['name' => 'estado', 'label' => 'Estado', 'type' => 'select', 'options' => ['pendiente' => 'Pendientes de cobro', 'cobrada' => 'Cobradas', 'anulada' => 'Anuladas']];
    } else {
        $filters[] = ['name' => 'serie', 'label' => 'Serie', 'type' => 'select', 'options' => fn() => rep_series_options([$kind])];
        $filters[] = ['name' => 'estado', 'label' => 'Estado', 'type' => 'select', 'options' => $statusOpts];
    }
    $filters[] = ['name' => 'pago', 'label' => 'Forma de pago', 'type' => 'select', 'options' => 'rep_pay_options'];
    $options = $isInv
        ? [['name' => 'anuladas', 'label' => 'Incluir anuladas', 'default' => 0], ['name' => 'contado', 'label' => 'Incluir cliente de contado', 'default' => 1], ['name' => 'pruebas', 'label' => 'Incluir documentos de prueba', 'default' => 0]]
        : [['name' => 'borradores', 'label' => 'Incluir borradores', 'default' => 0], ['name' => 'anulados', 'label' => 'Incluir anulados', 'default' => 0]];
    return [
        'id' => strtolower($label), 'title' => $label, 'section' => 'Documentos', 'order' => ['pedido' => 10, 'albaran' => 20, 'factura' => 30][$kind], 'perm' => 'erp', 'history' => 'ventas',
        'help' => 'Listado' . ($isInv ? '' : ' con su estado (' . implode(', ', array_map('mb_strtolower', array_values($statusOpts))) . ')') . ': simple o detallando líneas, agrupable por cliente.',
        'filters' => $filters,
        'variants' => ['simple' => $isInv ? 'Facturas / IVA' : $label . ' / importes', 'pago' => $label . ' / forma de pago', 'lineas' => 'Detallando líneas'],
        'groups' => ['cliente' => ['label' => 'Cliente', 'field' => '_cliente'], 'mes' => ['label' => 'Mes', 'field' => '_mes'], 'pago' => ['label' => 'Forma de pago', 'field' => '_pago'],
            'serie' => ['label' => 'Serie', 'field' => '_serie'], 'estado' => ['label' => 'Estado', 'field' => '_estado'], 'familia' => ['label' => 'Familia (solo detallando líneas)', 'field' => '_familia']],
        'orders' => ['fecha' => ['label' => 'Fecha', 'by' => ['fecha', 'num']], 'numero' => ['label' => 'Número', 'by' => ['num']], 'cliente' => ['label' => 'Cliente', 'by' => ['cliente', 'fecha']], 'total' => ['label' => 'Total', 'by' => ['total']]],
        'options' => $options,
        'columns' => function (string $v) use ($isInv): array {
            $base = [['key' => 'num', 'label' => 'Número', 'w' => 16], ['key' => 'fecha', 'label' => 'Fecha', 'type' => 'date'], ['key' => 'cli_cod', 'label' => 'Cód. cliente', 'w' => 12], ['key' => 'cliente', 'label' => 'Cliente', 'w' => 30]];
            if ($v === 'lineas') {
                return [...$base, ['key' => 'ref', 'label' => 'Referencia', 'w' => 16], ['key' => 'desc', 'label' => 'Descripción', 'w' => 34], ['key' => 'talla', 'label' => 'Talla', 'w' => 8], ['key' => 'color', 'label' => 'Color', 'w' => 12],
                    ['key' => 'uds', 'label' => 'Uds.', 'type' => 'qty', 'sum' => true], ['key' => 'precio', 'label' => 'Precio', 'type' => 'money'], ['key' => 'dto', 'label' => 'Dto. %', 'type' => 'pct'], ['key' => 'importe', 'label' => 'Importe', 'type' => 'money', 'sum' => true]];
            }
            if ($v === 'pago') {
                return [...$base, ['key' => 'estado', 'label' => 'Estado', 'w' => 20], ['key' => 'pago', 'label' => 'Forma de pago', 'w' => 22], ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true]]
                    + ($isInv ? [] : []);
            }
            return [...$base, ['key' => 'nif', 'label' => 'NIF', 'w' => 14], ['key' => 'estado', 'label' => 'Estado', 'w' => 20], ['key' => 'base', 'label' => 'Base', 'type' => 'money', 'sum' => true],
                ['key' => 'iva', 'label' => 'IVA', 'type' => 'money', 'sum' => true], ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true]];
        },
        'rows' => $isInv ? 'rep_docs_rows_invoices' : fn(array $sel) => rep_docs_rows_cms($kind, $sel),
    ];
}

/** Campos de grupo comunes de una fila de documento. */
function rep_docs_common(array $r, string $day, string $cust, string $custName, string $pay, string $serie, string $estado): array
{
    return $r + ['_cliente' => ($cust !== '' ? $cust . ' · ' : '') . $custName, '_mes' => rep_month_key((int)$day), '_pago' => $pay, '_serie' => $serie, '_estado' => $estado];
}

/** Pedidos y albaranes (doc_headers). */
function rep_docs_rows_cms(string $type, array $sel): array
{
    $f = $sel['f'];
    $o = $sel['opt'];
    $w = ' WHERE h.doc_type = ?';
    $a = [$type];
    if (empty($o['borradores'])) {
        $w .= " AND h.status <> 'borrador'";
    }
    if (empty($o['anulados'])) {
        $w .= " AND h.status <> 'anulado'";
    }
    rep_cond_date($w, $a, 'h.doc_day', $f['fecha']);
    foreach (['d' => '>=', 'h' => '<='] as $k => $op) {
        if ($f['numero'][$k] !== '' && ctype_digit($f['numero'][$k])) {
            $w .= ' AND h.doc_no ' . $op . ' ?';
            $a[] = (int)$f['numero'][$k];
        }
    }
    rep_cond_code($w, $a, 'h.cust_code', $f['cliente']);
    if ($f['serie'] !== '') {
        $w .= ' AND h.series_id IN (SELECT id FROM doc_series WHERE letter = ?)';
        $a[] = $f['serie'];
    }
    if ($f['estado'] !== '') {
        $w .= ' AND h.status = ?';
        $a[] = $f['estado'];
    }
    if ($f['pago'] !== '') {
        $w .= ' AND h.pay_code = ?';
        $a[] = $f['pago'];
    }
    $lines = $sel['v'] === 'lineas';
    $db = db();
    $statusLabels = DOCS_STATUS[$type];
    $map = $lines ? erp_family_map() : [];
    $sql = 'SELECT h.id, h.doc_code, h.doc_day, h.status, h.cust_code, h.cust_name, h.cust_nif, h.pay_code, h.base_cents, h.vat_cents, h.rec_cents, h.total_cents, COALESCE(s.letter, \'\') AS letter'
        . ($lines ? ', l.ref, l.title, l.size_txt, l.color_txt, l.qty_u, l.price_u, l.disc_bp, l.amount_u' : '')
        . ' FROM doc_headers h LEFT JOIN doc_series s ON s.id = h.series_id' . ($lines ? ' JOIN doc_lines l ON l.doc_id = h.id AND l.kind = 0' : '') . $w
        . ' ORDER BY h.doc_day, h.id' . ($lines ? ', l.line_no' : '') . ' LIMIT ' . (REP_MAX_ROWS + 1);
    $st = $db->prepare($sql);
    $st->execute($a);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $draft = $r['status'] === 'borrador';
        $est = (string)($statusLabels[$r['status']] ?? $r['status']);
        $name = (string)$r['cust_name'];
        $row = ['num' => $draft ? '(borrador)' : (string)$r['doc_code'], 'fecha' => (int)$r['doc_day'], 'cli_cod' => (string)$r['cust_code'], 'cliente' => $name, 'nif' => (string)$r['cust_nif'],
            'estado' => $est, 'pago' => erp_pay_label((string)$r['pay_code']), 'base' => (int)$r['base_cents'], 'iva' => (int)$r['vat_cents'] + (int)$r['rec_cents'], 'total' => (int)$r['total_cents'], '_familia' => ''];
        if ($lines) {
            $row += ['ref' => (string)$r['ref'], 'desc' => (string)$r['title'], 'talla' => (string)$r['size_txt'], 'color' => (string)$r['color_txt'], 'uds' => (int)$r['qty_u'] / 10000,
                'precio' => (int)round((int)$r['price_u'] / 10000), 'dto' => (int)$r['disc_bp'] / 100, 'importe' => (int)round((int)$r['amount_u'] / 100)];
            $row['_familia'] = (string)($map[mb_strtolower((string)$r['ref'])]['fam'] ?? '');
        }
        $out[] = rep_docs_common($row, (string)$r['doc_day'], (string)$r['cust_code'], $name, $row['pago'], (string)$r['letter'], $est);
    }
    return $out;
}

/** Facturas: las de erp_invoices (NCS + emitidas en el CMS) y, si se pide, las de prueba (doc_headers). */
function rep_docs_rows_invoices(array $sel): array
{
    $f = $sel['f'];
    $o = $sel['opt'];
    $w = ' WHERE 1 = 1';
    $a = [];
    if (empty($o['anuladas']) && $f['estado'] !== 'anulada') {
        $w .= ' AND i.annulled = 0';
    }
    rep_cond_date($w, $a, 'i.inv_day', $f['fecha']);
    rep_cond_code($w, $a, 'i.inv_number', $f['numero']);
    rep_cond_code($w, $a, 'i.cust_code', $f['cliente']);
    if ($f['serie'] !== '') {
        $w .= " AND UPPER(i.inv_number) LIKE ? ESCAPE '!'";
        $a[] = strtr(mb_strtoupper($f['serie']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    if ($f['origen'] !== '') {
        $w .= ' AND i.origin = ?';
        $a[] = $f['origen'];
    }
    if ($f['estado'] === 'anulada') {
        $w .= ' AND i.annulled = 1';
    } elseif ($f['estado'] === 'cobrada') {
        $w .= ' AND COALESCE(s.paid, 0) = 1 AND i.annulled = 0';
    } elseif ($f['estado'] === 'pendiente') {
        $w .= ' AND COALESCE(s.paid, 0) = 0 AND i.annulled = 0';
    }
    if ($f['pago'] !== '') {
        $w .= ' AND i.pay_code = ?';
        $a[] = $f['pago'];
    }
    $retail = erp_retail_code();
    if (empty($o['contado']) && $retail !== '') {
        $w .= ' AND i.cust_code <> ?';
        $a[] = $retail;
    }
    $lines = $sel['v'] === 'lineas';
    $map = $lines ? erp_family_map() : [];
    $sql = 'SELECT i.id, i.inv_number, i.inv_day, i.cust_code, i.pay_code, i.base_cents, i.vat_cents, i.total_cents, i.annulled, COALESCE(s.paid, 0) AS paid, COALESCE(c.name, \'\') AS cname, COALESCE(c.trade_name, \'\') AS ctrade, COALESCE(c.nif, \'\') AS cnif'
        . ($lines ? ', l.article_code, l.title, l.size_txt, l.color_txt, l.qty_milli, l.price_cents, l.disc_bp, l.amount_cents' : '')
        . ' FROM erp_invoices i LEFT JOIN erp_customers c ON c.cust_code = i.cust_code LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id'
        . ($lines ? ' JOIN erp_invoice_lines l ON l.invoice_id = i.id AND l.kind = 0' : '') . $w
        . ' ORDER BY i.inv_day, i.id' . ($lines ? ', l.line_no' : '') . ' LIMIT ' . (REP_MAX_ROWS + 1);
    $st = db()->prepare($sql);
    $st->execute($a);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $name = (string)$r['cust_code'] === $retail && $retail !== '' ? 'Cliente de contado' : rep_cust_label((string)$r['cust_code'], (string)$r['cname'], (string)$r['ctrade']);
        $est = (int)$r['annulled'] === 1 ? 'Anulada' : ((int)$r['paid'] === 1 ? 'Cobrada' : 'Pendiente de cobro');
        $row = ['num' => (string)$r['inv_number'], 'fecha' => (int)$r['inv_day'], 'cli_cod' => (string)$r['cust_code'], 'cliente' => $name, 'nif' => (string)$r['cnif'], 'estado' => $est,
            'pago' => erp_pay_label((string)$r['pay_code']), 'base' => (int)$r['base_cents'], 'iva' => (int)$r['vat_cents'], 'total' => (int)$r['total_cents'], '_familia' => ''];
        if ($lines) {
            $row += ['ref' => (string)$r['article_code'], 'desc' => (string)$r['title'], 'talla' => (string)$r['size_txt'], 'color' => (string)$r['color_txt'], 'uds' => (int)$r['qty_milli'] / 1000,
                'precio' => (int)$r['price_cents'], 'dto' => (int)$r['disc_bp'] / 100, 'importe' => (int)$r['amount_cents']];
            $row['_familia'] = (string)($map[mb_strtolower((string)$r['article_code'])]['fam'] ?? '');
        }
        $out[] = rep_docs_common($row, (string)$r['inv_day'], (string)$r['cust_code'], $name, $row['pago'], erp_serie((string)$r['inv_number']), $est);
    }
    if (!empty($o['pruebas'])) { // facturas de prueba del CMS: no están en erp_invoices
        $w2 = ' WHERE h.is_test = 1 AND h.doc_type IN (\'factura\', \'simplificada\', \'rectificativa\') AND h.status = \'emitida\'';
        $a2 = [];
        rep_cond_date($w2, $a2, 'h.doc_day', $f['fecha']);
        rep_cond_code($w2, $a2, 'h.doc_code', $f['numero']);
        rep_cond_code($w2, $a2, 'h.cust_code', $f['cliente']);
        if ($f['pago'] !== '') {
            $w2 .= ' AND h.pay_code = ?';
            $a2[] = $f['pago'];
        }
        $st = db()->prepare('SELECT h.doc_code, h.doc_day, h.cust_code, h.cust_name, h.cust_nif, h.pay_code, h.base_cents, h.vat_cents, h.rec_cents, h.total_cents FROM doc_headers h' . $w2 . ' ORDER BY h.doc_day, h.id');
        $st->execute($a2);
        foreach ($st->fetchAll() as $r) {
            if ($lines) {
                continue; // las líneas de prueba no se detallan
            }
            $name = (string)$r['cust_name'];
            $row = ['num' => (string)$r['doc_code'], 'fecha' => (int)$r['doc_day'], 'cli_cod' => (string)$r['cust_code'], 'cliente' => $name, 'nif' => (string)$r['cust_nif'], 'estado' => 'Prueba',
                'pago' => erp_pay_label((string)$r['pay_code']), 'base' => (int)$r['base_cents'], 'iva' => (int)$r['vat_cents'] + (int)$r['rec_cents'], 'total' => (int)$r['total_cents'], '_familia' => ''];
            $out[] = rep_docs_common($row, (string)$r['doc_day'], (string)$r['cust_code'], $name, $row['pago'], '', 'Prueba');
        }
    }
    return $out;
}

/* ---------- Ventas por cliente, artículo y familia ---------- */

/**
 * $dim = 'cliente' | 'articulo' | 'familia'. Unidades e importe entre fechas, de las facturas no anuladas (NCS y emitidas en el CMS);
 * las devoluciones restan. «Importe» = el de las líneas de la factura (con IVA si la factura lo incluye en sus líneas).
 */
function rep_sales_define(string $dim): array
{
    $titles = ['cliente' => 'Ventas por cliente', 'articulo' => 'Ventas por artículo', 'familia' => 'Ventas por familia'];
    $detail = ['cliente' => 'Cliente y artículo', 'articulo' => 'Artículo y cliente', 'familia' => 'Familia y artículo'];
    return [
        'id' => 'ventas-' . $dim, 'title' => $dim === 'familia' ? 'Ventas por ' . mb_strtolower(term('family')) : $titles[$dim], 'section' => 'Ventas', 'order' => ['cliente' => 10, 'articulo' => 20, 'familia' => 30][$dim],
        'perm' => 'erp', 'history' => 'ventas',
        'help' => 'Unidades e importe entre fechas. Las devoluciones restan y las anuladas no cuentan. El importe es el de las líneas de las facturas.',
        'filters' => [
            ['name' => 'fecha', 'label' => 'Fecha de factura', 'type' => 'date'],
            ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'code', 'lookup' => 'cliente'],
            ['name' => 'articulo', 'label' => 'Artículo', 'type' => 'code', 'lookup' => 'articulo'],
            ['name' => 'familia', 'label' => term('family'), 'type' => 'select', 'options' => 'rep_families'],
            ['name' => 'serie', 'label' => 'Serie (empieza por)', 'type' => 'text'],
            ['name' => 'pago', 'label' => 'Forma de pago', 'type' => 'select', 'options' => 'rep_pay_options'],
        ],
        'variants' => ['resumen' => ucfirst($titles[$dim] === 'Ventas por familia' ? 'Una fila por ' . mb_strtolower(term('family')) : 'Una fila por ' . $dim), 'detalle' => $detail[$dim]],
        'groups' => array_filter(['cliente' => ['label' => 'Cliente', 'field' => '_cliente'], 'mes' => ['label' => 'Mes', 'field' => '_mes'], 'familia' => ['label' => term('family'), 'field' => '_familia']], fn($g, $k) => $k !== $dim, ARRAY_FILTER_USE_BOTH),
        'orders' => ['importe' => ['label' => 'Importe (mayor primero)', 'by' => ['_ni']], 'unidades' => ['label' => 'Unidades (mayor primero)', 'by' => ['_nq']], 'nombre' => ['label' => 'Nombre', 'by' => ['_nombre']]],
        'options' => [['name' => 'contado', 'label' => 'Incluir cliente de contado', 'default' => 1]],
        'columns' => function (string $v) use ($dim): array {
            $dims = rep_sales_dims($dim, $v);
            $cols = [];
            foreach ($dims as $d) {
                if ($d === 'cust') {
                    $cols[] = ['key' => 'cod_cli', 'label' => 'Cód. cliente', 'w' => 12];
                    $cols[] = ['key' => 'cliente', 'label' => 'Cliente', 'w' => 32];
                } elseif ($d === 'art') {
                    $cols[] = ['key' => 'ref', 'label' => 'Artículo', 'w' => 16];
                    $cols[] = ['key' => 'desc', 'label' => 'Descripción', 'w' => 34];
                } elseif ($d === 'fam') {
                    $cols[] = ['key' => 'familia', 'label' => term('family'), 'w' => 28];
                }
            }
            return [...$cols, ['key' => 'uds', 'label' => 'Unidades', 'type' => 'qty', 'sum' => true], ['key' => 'importe', 'label' => 'Importe', 'type' => 'money', 'sum' => true]];
        },
        'rows' => fn(array $sel) => rep_sales_rows($dim, $sel),
    ];
}

/** Dimensiones de una fila: por informe y tipo de listado. */
function rep_sales_dims(string $dim, string $variant): array
{
    $main = ['cliente' => 'cust', 'articulo' => 'art', 'familia' => 'fam'][$dim];
    if ($variant !== 'detalle') {
        return [$main];
    }
    return [$main, ['cliente' => 'art', 'articulo' => 'cust', 'familia' => 'art'][$dim]];
}

function rep_sales_rows(string $dim, array $sel): array
{
    $f = $sel['f'];
    $w = ' WHERE i.annulled = 0 AND l.kind = 0';
    $a = [];
    rep_cond_date($w, $a, 'i.inv_day', $f['fecha']);
    rep_cond_code($w, $a, 'i.cust_code', $f['cliente']);
    rep_cond_code($w, $a, 'l.article_code', $f['articulo']);
    if ($f['serie'] !== '') {
        $w .= " AND UPPER(i.inv_number) LIKE ? ESCAPE '!'";
        $a[] = strtr(mb_strtoupper($f['serie']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    if ($f['pago'] !== '') {
        $w .= ' AND i.pay_code = ?';
        $a[] = $f['pago'];
    }
    $retail = erp_retail_code();
    if (empty($sel['opt']['contado']) && $retail !== '') {
        $w .= ' AND i.cust_code <> ?';
        $a[] = $retail;
    }
    $st = db()->prepare('SELECT i.cust_code, l.article_code, i.inv_year, i.inv_month, MAX(l.title) AS title, SUM(l.qty_milli) AS q, SUM(l.amount_cents) AS a
        FROM erp_invoice_lines l JOIN erp_invoices i ON i.id = l.invoice_id' . $w . ' GROUP BY i.cust_code, l.article_code, i.inv_year, i.inv_month');
    $st->execute($a);
    $map = erp_family_map();
    $custs = [];
    foreach (db()->query('SELECT cust_code, name, trade_name FROM erp_customers')->fetchAll() as $c) {
        $custs[(string)$c['cust_code']] = rep_cust_label((string)$c['cust_code'], (string)$c['name'], (string)$c['trade_name']);
    }
    $dims = rep_sales_dims($dim, $sel['v']);
    $g = $sel['g'];
    $keyDims = $dims;
    foreach ([['mes', 'ym'], ['cliente', 'cust'], ['familia', 'fam']] as [$gk, $gd]) {
        if ($g === $gk && !in_array($gd, $keyDims, true)) {
            $keyDims[] = $gd;
        }
    }
    $fam = (int)($f['familia'] ?? 0);
    $agg = [];
    foreach ($st->fetchAll() as $r) {
        $code = (string)$r['article_code'];
        $m = $map[mb_strtolower($code)] ?? null;
        if ($fam > 0 && ($m['fam_id'] ?? 0) !== $fam) {
            continue;
        }
        $cc = (string)$r['cust_code'];
        $vals = ['cust' => $cc, 'art' => $code, 'fam' => (string)($m['fam'] ?? ''), 'ym' => sprintf('%04d-%02d', (int)$r['inv_year'], (int)$r['inv_month'])];
        $key = implode("\x1f", array_map(fn($d) => $vals[$d], $keyDims));
        if (!isset($agg[$key])) {
            $agg[$key] = ['v' => $vals, 'q' => 0, 'a' => 0, 'title' => ''];
        }
        $agg[$key]['q'] += (int)$r['q'];
        $agg[$key]['a'] += (int)$r['a'];
        if ($agg[$key]['title'] === '' || in_array('art', $keyDims, true)) {
            $agg[$key]['title'] = (string)$r['title'];
        }
    }
    $out = [];
    foreach ($agg as $x) {
        $v = $x['v'];
        $cname = $custs[$v['cust']] ?? ($v['cust'] === $retail && $retail !== '' ? 'Cliente de contado' : $v['cust']);
        $row = ['cod_cli' => $v['cust'], 'cliente' => $v['cust'] === $retail && $retail !== '' ? 'Cliente de contado' : $cname, 'ref' => $v['art'], 'desc' => $x['title'], 'familia' => $v['fam'],
            'uds' => $x['q'] / 1000, 'importe' => $x['a'], '_ni' => -$x['a'], '_nq' => -$x['q'],
            '_cliente' => $v['cust'] . ' · ' . $cname, '_mes' => $v['ym'], '_familia' => $v['fam']];
        $row['_nombre'] = $dims[0] === 'cust' ? $row['cliente'] : ($dims[0] === 'art' ? $v['art'] : $v['fam']);
        $out[] = $row;
    }
    return $out;
}

/* ---------- Compras: por proveedor, artículo y familia ---------- */

/** Importe SIN IVA de un grupo de líneas (céntimos): si el documento lleva el IVA incluido, se le quita. */
function rep_purch_net(int $cents, int $vatIncl, int $vatBp, int $recBp, int $withRe): int
{
    return $vatIncl === 1 ? (int)round($cents * 10000 / (10000 + $vatBp + ($withRe === 1 ? $recBp : 0))) : $cents;
}

/**
 * $dim = 'proveedor' | 'articulo' | 'familia'. Unidades e importe (sin IVA) de las facturas recibidas CERRADAS (las rectificativas restan),
 * entre fechas. Las compras no tocan el stock.
 */
function rep_purch_define(string $dim): array
{
    $titles = ['proveedor' => 'Compras por proveedor', 'articulo' => 'Compras por artículo', 'familia' => 'Compras por ' . mb_strtolower(term('family'))];
    $detail = ['proveedor' => 'Proveedor y artículo', 'articulo' => 'Artículo y proveedor', 'familia' => 'Familia y artículo'];
    return [
        'id' => 'compras-' . $dim, 'title' => $titles[$dim], 'section' => 'Compras', 'order' => ['proveedor' => 10, 'articulo' => 20, 'familia' => 30][$dim],
        'perm' => 'purch', 'history' => 'compras',
        'help' => 'Unidades e importe sin IVA de las facturas recibidas cerradas entre fechas. Las rectificativas recibidas restan.',
        'filters' => [
            ['name' => 'fecha', 'label' => 'Fecha de factura', 'type' => 'date'],
            ['name' => 'proveedor', 'label' => 'Proveedor', 'type' => 'code', 'lookup' => 'proveedor'],
            ['name' => 'articulo', 'label' => 'Artículo', 'type' => 'code', 'lookup' => 'articulo'],
            ['name' => 'familia', 'label' => term('family'), 'type' => 'select', 'options' => 'rep_families'],
            ['name' => 'pago', 'label' => 'Forma de pago', 'type' => 'select', 'options' => 'rep_pay_options'],
        ],
        'variants' => ['resumen' => 'Una fila por ' . $dim, 'detalle' => $detail[$dim]],
        'groups' => array_filter(['proveedor' => ['label' => 'Proveedor', 'field' => '_proveedor'], 'mes' => ['label' => 'Mes', 'field' => '_mes'], 'familia' => ['label' => term('family'), 'field' => '_familia']], fn($g, $k) => $k !== $dim, ARRAY_FILTER_USE_BOTH),
        'orders' => ['importe' => ['label' => 'Importe (mayor primero)', 'by' => ['_ni']], 'unidades' => ['label' => 'Unidades (mayor primero)', 'by' => ['_nq']], 'nombre' => ['label' => 'Nombre', 'by' => ['_nombre']]],
        'options' => [],
        'columns' => function (string $v) use ($dim): array {
            $cols = [];
            foreach (rep_purch_dims($dim, $v) as $d) {
                if ($d === 'sup') {
                    $cols[] = ['key' => 'cod', 'label' => 'Cód. proveedor', 'w' => 14];
                    $cols[] = ['key' => 'nombre', 'label' => 'Proveedor', 'w' => 32];
                } elseif ($d === 'art') {
                    $cols[] = ['key' => 'ref', 'label' => 'Artículo', 'w' => 16];
                    $cols[] = ['key' => 'desc', 'label' => 'Descripción', 'w' => 34];
                } else {
                    $cols[] = ['key' => 'familia', 'label' => term('family'), 'w' => 28];
                }
            }
            return [...$cols, ['key' => 'uds', 'label' => 'Unidades', 'type' => 'qty', 'sum' => true], ['key' => 'importe', 'label' => 'Importe sin IVA', 'type' => 'money', 'sum' => true]];
        },
        'rows' => fn(array $sel) => rep_purch_rows($dim, $sel),
    ];
}

function rep_purch_dims(string $dim, string $variant): array
{
    $main = ['proveedor' => 'sup', 'articulo' => 'art', 'familia' => 'fam'][$dim];
    return $variant !== 'detalle' ? [$main] : [$main, ['proveedor' => 'art', 'articulo' => 'sup', 'familia' => 'art'][$dim]];
}

function rep_purch_rows(string $dim, array $sel): array
{
    $f = $sel['f'];
    $w = " WHERE h.doc_type IN ('factura_c', 'rectificativa_c') AND h.status = 'emitida' AND l.kind = 0";
    $a = [];
    rep_cond_date($w, $a, 'h.doc_day', $f['fecha']);
    rep_cond_code($w, $a, 'h.cust_code', $f['proveedor']);
    rep_cond_code($w, $a, 'l.ref', $f['articulo']);
    if ($f['pago'] !== '') {
        $w .= ' AND h.pay_code = ?';
        $a[] = $f['pago'];
    }
    $st = db()->prepare('SELECT h.cust_code, MAX(h.cust_name) AS sname, l.ref, MAX(l.title) AS title, h.doc_day, h.vat_incl, h.with_re, l.vat_bp, l.rec_bp, SUM(l.qty_u) AS q, SUM(l.amount_u) AS a
        FROM doc_lines l JOIN doc_headers h ON h.id = l.doc_id' . $w . ' GROUP BY h.cust_code, l.ref, h.doc_day, h.vat_incl, h.with_re, l.vat_bp, l.rec_bp');
    $st->execute($a);
    $map = erp_family_map();
    $dims = rep_purch_dims($dim, $sel['v']);
    $keyDims = $dims;
    foreach ([['mes', 'ym'], ['proveedor', 'sup'], ['familia', 'fam']] as [$gk, $gd]) {
        if ($sel['g'] === $gk && !in_array($gd, $keyDims, true)) {
            $keyDims[] = $gd;
        }
    }
    $fam = (int)($f['familia'] ?? 0);
    $agg = [];
    foreach ($st->fetchAll() as $r) {
        $m = $map[mb_strtolower((string)$r['ref'])] ?? null;
        if ($fam > 0 && ($m['fam_id'] ?? 0) !== $fam) {
            continue;
        }
        $vals = ['sup' => (string)$r['cust_code'] . "\x1e" . (string)$r['sname'], 'art' => (string)$r['ref'], 'fam' => (string)($m['fam'] ?? ''), 'ym' => rep_month_key((int)$r['doc_day'])];
        $key = implode("\x1f", array_map(fn($d) => $vals[$d], $keyDims));
        $net = rep_purch_net((int)round((int)$r['a'] / 100), (int)$r['vat_incl'], (int)$r['vat_bp'], (int)$r['rec_bp'], (int)$r['with_re']);
        $agg[$key] ??= ['v' => $vals, 'q' => 0, 'a' => 0, 'title' => (string)$r['title']];
        $agg[$key]['q'] += (int)$r['q'];
        $agg[$key]['a'] += $net;
    }
    $out = [];
    foreach ($agg as $x) {
        [$scode, $sname] = array_pad(explode("\x1e", $x['v']['sup'], 2), 2, '');
        $sname = $sname !== '' ? $sname : $scode;
        $row = ['cod' => $scode, 'nombre' => $sname, 'ref' => $x['v']['art'], 'desc' => $x['title'], 'familia' => $x['v']['fam'], 'uds' => $x['q'] / DOCS_QTY, 'importe' => $x['a'],
            '_ni' => -$x['a'], '_nq' => -$x['q'], '_proveedor' => $scode . ' · ' . $sname, '_mes' => $x['v']['ym'], '_familia' => $x['v']['fam']];
        $row['_nombre'] = $dims[0] === 'sup' ? $sname : ($dims[0] === 'art' ? $x['v']['art'] : $x['v']['fam']);
        $out[] = $row;
    }
    return $out;
}
