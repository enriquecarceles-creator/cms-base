<?php
/**
 * Libro registro de facturas recibidas (IVA): fecha, registro, nº del proveedor, proveedor, NIF, base, tipo, cuota, recargo y total por tipo de IVA.
 * Solo las facturas recibidas CERRADAS (las rectificativas recibidas salen en negativo). Es una ayuda: se revisa con el asesor antes de presentarlo.
 */
return [
    'id' => 'libro-iva-recibidas', 'title' => 'Libro de IVA (recibidas)', 'section' => 'Fiscal', 'order' => 20, 'perm' => 'purch', 'history' => 'compras',
    'help' => 'Facturas recibidas cerradas por tipo de IVA, con las rectificativas recibidas en negativo.',
    'notice' => 'Revisar con el asesor antes de presentarlo.',
    'filters' => [
        ['name' => 'fecha', 'label' => 'Fecha de factura', 'type' => 'date'],
        ['name' => 'registro', 'label' => 'Nº de registro', 'type' => 'code'],
        ['name' => 'proveedor', 'label' => 'Proveedor', 'type' => 'code', 'lookup' => 'proveedor'],
        ['name' => 'nif', 'label' => 'NIF', 'type' => 'text', 'personal' => true],
    ],
    'variants' => ['detalle' => 'Por tipo de IVA (una fila por factura y tipo)', 'resumen' => 'Una fila por factura'],
    'groups' => ['mes' => ['label' => 'Mes', 'field' => '_mes'], 'tipo' => ['label' => 'Tipo de IVA', 'field' => '_tipo'], 'proveedor' => ['label' => 'Proveedor', 'field' => '_proveedor']],
    'orders' => ['fecha' => ['label' => 'Fecha y registro', 'by' => ['fecha', 'num']], 'registro' => ['label' => 'Nº de registro', 'by' => ['num']], 'proveedor' => ['label' => 'Proveedor', 'by' => ['nombre', 'fecha']]],
    'options' => [],
    'columns' => function (string $v): array {
        $c = [['key' => 'fecha', 'label' => 'Fecha', 'type' => 'date'], ['key' => 'num', 'label' => 'Registro', 'w' => 16], ['key' => 'sup_ref', 'label' => 'Nº proveedor', 'w' => 18], ['key' => 'nombre', 'label' => 'Proveedor', 'w' => 30],
            ['key' => 'nif', 'label' => 'NIF', 'w' => 14], ['key' => 'clase', 'label' => 'Clase', 'w' => 14]];
        if ($v === 'detalle') {
            return [...$c, ['key' => 'base', 'label' => 'Base', 'type' => 'money', 'sum' => true], ['key' => 'tipo', 'label' => 'Tipo %', 'type' => 'pct'], ['key' => 'cuota', 'label' => 'Cuota', 'type' => 'money', 'sum' => true],
                ['key' => 'rec', 'label' => 'Recargo', 'type' => 'money', 'sum' => true], ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true]];
        }
        return [...$c, ['key' => 'base', 'label' => 'Base', 'type' => 'money', 'sum' => true], ['key' => 'cuota', 'label' => 'Cuota', 'type' => 'money', 'sum' => true], ['key' => 'rec', 'label' => 'Recargo', 'type' => 'money', 'sum' => true],
            ['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true]];
    },
    'rows' => function (array $sel): array {
        $f = $sel['f'];
        $w = " WHERE h.doc_type IN ('factura_c', 'rectificativa_c') AND h.status = 'emitida'";
        $a = [];
        rep_cond_date($w, $a, 'h.doc_day', $f['fecha']);
        rep_cond_code($w, $a, 'h.doc_code', $f['registro']);
        rep_cond_code($w, $a, 'h.cust_code', $f['proveedor']);
        rep_cond_like($w, $a, ['h.cust_nif'], $f['nif']);
        $st = db()->prepare('SELECT h.* FROM doc_headers h' . $w . ' ORDER BY h.doc_day, h.id LIMIT ' . (REP_MAX_ROWS + 1));
        $st->execute($a);
        $out = [];
        foreach ($st->fetchAll() as $h) {
            $entries = json_decode((string)$h['vat_json'], true);
            $entries = is_array($entries) ? $entries : [];
            $name = (string)$h['cust_name'];
            $clase = $h['doc_type'] === 'rectificativa_c' ? 'Rectificativa' : 'Factura';
            $common = ['fecha' => (int)$h['doc_day'], 'num' => (string)$h['doc_code'], 'sup_ref' => (string)$h['sup_ref'], 'nombre' => $name, 'nif' => (string)$h['cust_nif'], 'clase' => $clase,
                '_mes' => rep_month_key((int)$h['doc_day']), '_proveedor' => ((string)$h['cust_code'] !== '' ? $h['cust_code'] . ' · ' : '') . $name];
            if ($sel['v'] === 'resumen') {
                $out[] = $common + ['base' => (int)$h['base_cents'], 'cuota' => (int)$h['vat_cents'], 'rec' => (int)$h['rec_cents'], 'total' => (int)$h['total_cents'], 'tipo' => null, '_tipo' => ''];
                continue;
            }
            if (!$entries) {
                $out[] = $common + ['base' => (int)$h['base_cents'], 'tipo' => null, 'cuota' => (int)$h['vat_cents'], 'rec' => (int)$h['rec_cents'], 'total' => (int)$h['total_cents'], '_tipo' => 'Sin IVA'];
                continue;
            }
            foreach ($entries as $e) {
                $pct = isset($e['pct']) ? (float)$e['pct'] : null;
                $out[] = $common + ['base' => (int)$e['base'], 'tipo' => $pct, 'cuota' => (int)$e['cuota'], 'rec' => (int)($e['rec'] ?? 0), 'total' => (int)$e['base'] + (int)$e['cuota'] + (int)($e['rec'] ?? 0),
                    '_tipo' => $pct === null ? 'Sin tipo' : rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',') . ' %'];
            }
        }
        return $out;
    },
];
