<?php
/**
 * Libro registro de facturas expedidas (IVA): fecha, número, cliente, NIF, base, tipo, cuota y total por tipo de IVA.
 * Las rectificativas y devoluciones salen en negativo (sus importes ya lo son). Es una ayuda: se revisa con el asesor antes de presentarlo.
 */
return [
    'id' => 'libro-iva', 'title' => 'Libro de IVA (expedidas)', 'section' => 'Fiscal', 'order' => 10, 'perm' => 'erp', 'history' => 'ventas',
    'help' => 'Facturas expedidas por tipo de IVA, con las rectificativas en negativo.',
    'notice' => 'Revisar con el asesor antes de presentarlo.',
    'filters' => [
        ['name' => 'fecha', 'label' => 'Fecha de factura', 'type' => 'date'],
        ['name' => 'numero', 'label' => 'Número', 'type' => 'code'],
        ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'code', 'lookup' => 'cliente'],
        ['name' => 'serie', 'label' => 'Serie (empieza por)', 'type' => 'text'],
        ['name' => 'origen', 'label' => 'Origen', 'type' => 'select', 'options' => ['ncs' => 'Importadas de NCS', 'cms' => 'Emitidas en el CMS']],
    ],
    'variants' => ['detalle' => 'Por tipo de IVA (una fila por factura y tipo)', 'resumen' => 'Una fila por factura'],
    'groups' => ['mes' => ['label' => 'Mes', 'field' => '_mes'], 'tipo' => ['label' => 'Tipo de IVA', 'field' => '_tipo'], 'cliente' => ['label' => 'Cliente', 'field' => '_cliente'], 'serie' => ['label' => 'Serie', 'field' => '_serie']],
    'orders' => ['fecha' => ['label' => 'Fecha y número', 'by' => ['fecha', 'num']], 'numero' => ['label' => 'Número', 'by' => ['num']], 'cliente' => ['label' => 'Cliente', 'by' => ['cliente', 'fecha']]],
    'options' => [
        ['name' => 'anuladas', 'label' => 'Incluir anuladas', 'default' => 0],
        ['name' => 'contado', 'label' => 'Incluir cliente de contado', 'default' => 1],
        ['name' => 'pruebas', 'label' => 'Incluir documentos de prueba', 'default' => 0],
    ],
    'columns' => function (string $v): array {
        $c = [['key' => 'fecha', 'label' => 'Fecha', 'type' => 'date'], ['key' => 'num', 'label' => 'Número', 'w' => 16], ['key' => 'cliente', 'label' => 'Cliente', 'w' => 30], ['key' => 'nif', 'label' => 'NIF', 'w' => 14], ['key' => 'clase', 'label' => 'Clase', 'w' => 14]];
        if ($v === 'detalle') {
            return [...$c, ['key' => 'base', 'label' => 'Base', 'type' => 'money', 'sum' => true], ['key' => 'tipo', 'label' => 'Tipo %', 'type' => 'pct'], ['key' => 'cuota', 'label' => 'Cuota', 'type' => 'money', 'sum' => true],
                ['key' => 'rec', 'label' => 'Recargo', 'type' => 'money', 'sum' => true], ['key' => 'ret', 'label' => 'Retención', 'type' => 'money', 'sum' => true], ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true]];
        }
        return [...$c, ['key' => 'base', 'label' => 'Base', 'type' => 'money', 'sum' => true], ['key' => 'cuota', 'label' => 'Cuota', 'type' => 'money', 'sum' => true], ['key' => 'rec', 'label' => 'Recargo', 'type' => 'money', 'sum' => true],
            ['key' => 'ret', 'label' => 'Retención', 'type' => 'money', 'sum' => true], ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true]];
    },
    'rows' => function (array $sel): array {
        $f = $sel['f'];
        $w = ' WHERE 1 = 1';
        $a = [];
        if (empty($sel['opt']['anuladas'])) {
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
        $retail = erp_retail_code();
        if (empty($sel['opt']['contado']) && $retail !== '') {
            $w .= ' AND i.cust_code <> ?';
            $a[] = $retail;
        }
        $st = db()->prepare("SELECT i.*, COALESCE(c.name, '') AS cname, COALESCE(c.trade_name, '') AS ctrade, COALESCE(c.nif, '') AS cnif FROM erp_invoices i LEFT JOIN erp_customers c ON c.cust_code = i.cust_code"
            . $w . ' ORDER BY i.inv_day, i.id LIMIT ' . (REP_MAX_ROWS + 1));
        $st->execute($a);
        $invoices = $st->fetchAll();
        if (!empty($sel['opt']['pruebas'])) { // facturas de prueba del CMS (no están en erp_invoices)
            $w2 = " WHERE h.is_test = 1 AND h.doc_type IN ('factura', 'simplificada', 'rectificativa') AND h.status = 'emitida'";
            $a2 = [];
            rep_cond_date($w2, $a2, 'h.doc_day', $f['fecha']);
            rep_cond_code($w2, $a2, 'h.doc_code', $f['numero']);
            rep_cond_code($w2, $a2, 'h.cust_code', $f['cliente']);
            $st = db()->prepare("SELECT h.doc_code AS inv_number, h.doc_day AS inv_day, h.cust_code, h.cust_name AS cname, '' AS ctrade, h.cust_nif AS cnif, h.base_cents, h.vat_cents, h.total_cents, h.vat_json, 0 AS annulled, '' AS rectifies FROM doc_headers h" . $w2);
            $st->execute($a2);
            foreach ($st->fetchAll() as $r) {
                $invoices[] = $r;
            }
        }
        $out = [];
        foreach ($invoices as $i) {
            $code = (string)$i['cust_code'];
            $name = $code === $retail && $retail !== '' ? 'Cliente de contado' : rep_cust_label($code, (string)$i['cname'], (string)$i['ctrade']);
            $clase = (int)$i['annulled'] === 1 ? 'Anulada' : (((int)$i['total_cents'] < 0 || (string)$i['rectifies'] !== '') ? 'Rectificativa' : 'Factura');
            $common = ['fecha' => (int)$i['inv_day'], 'num' => (string)$i['inv_number'], 'cliente' => $name, 'nif' => (string)$i['cnif'], 'clase' => $clase,
                '_mes' => rep_month_key((int)$i['inv_day']), '_cliente' => ($code !== '' ? $code . ' · ' : '') . $name, '_serie' => erp_serie((string)$i['inv_number'])];
            $entries = erp_invoice_vat($i);
            if ($sel['v'] === 'resumen') {
                $out[] = $common + ['base' => (int)$i['base_cents'], 'cuota' => (int)$i['vat_cents'], 'rec' => array_sum(array_map(fn($e) => (int)($e['rec'] ?? 0), $entries)), 'ret' => -array_sum(array_map(fn($e) => (int)($e['ret'] ?? 0), $entries)),
                    'total' => (int)$i['total_cents'], 'tipo' => null, '_tipo' => ''];
                continue;
            }
            if (!$entries) {
                $out[] = $common + ['base' => 0, 'tipo' => null, 'cuota' => 0, 'rec' => 0, 'ret' => 0, 'total' => (int)$i['total_cents'], '_tipo' => 'Sin IVA'];
                continue;
            }
            foreach ($entries as $e) {
                $pct = isset($e['pct']) ? (float)$e['pct'] : null;
                $out[] = $common + ['base' => (int)$e['base'], 'tipo' => $pct, 'cuota' => (int)$e['cuota'], 'rec' => (int)($e['rec'] ?? 0), 'ret' => -(int)($e['ret'] ?? 0),
                    'total' => (int)$e['base'] + (int)$e['cuota'] + (int)($e['rec'] ?? 0) - (int)($e['ret'] ?? 0), '_tipo' => $pct === null ? 'Sin tipo' : rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',') . ' %'];
            }
        }
        return $out;
    },
];
