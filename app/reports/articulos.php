<?php
/**
 * Informe de artículos: listado por familia, con precios por tarifa y con stock. El stock es un campo interno del catálogo:
 * solo lo ven administradores y catálogo (el rol ERP no ve ni la columna ni el tipo de listado «con stock»).
 */
return [
    'id' => 'articulos', 'title' => 'Artículos', 'section' => 'Artículos', 'order' => 10, 'perm' => 'all', 'history' => 'articulos',
    'help' => 'Listado por familia, con precios por tarifa y (con permiso) con stock.',
    'filters' => [
        ['name' => 'ref', 'label' => 'Referencia', 'type' => 'code', 'lookup' => 'articulo'],
        ['name' => 'nombre', 'label' => 'Descripción', 'type' => 'text'],
        ['name' => 'familia', 'label' => term('family'), 'type' => 'select', 'options' => 'rep_families'],
    ],
    'variants' => function_exists('report_can_stock') && report_can_stock()
        ? ['lista' => 'Listado por ' . mb_strtolower(term('family')), 'tarifas' => 'Con precios por tarifa', 'stock' => 'Con stock']
        : ['lista' => 'Listado por ' . mb_strtolower(term('family')), 'tarifas' => 'Con precios por tarifa'],
    'groups' => ['familia' => ['label' => term('family'), 'field' => '_familia']],
    'orders' => ['ref' => ['label' => 'Referencia', 'by' => ['ref']], 'nombre' => ['label' => 'Descripción', 'by' => ['nombre']], 'familia' => ['label' => term('family'), 'by' => ['familia', 'ref']]],
    'options' => [['name' => 'ocultos', 'label' => 'Incluir bloqueados (ocultos en la web)', 'default' => 0]],
    'columns' => function (string $v, array $sel = []): array {
        $base = [['key' => 'ref', 'label' => 'Referencia', 'w' => 16], ['key' => 'nombre', 'label' => 'Descripción', 'w' => 36], ['key' => 'familia', 'label' => term('family'), 'w' => 24]];
        if ($v === 'tarifas') {
            foreach (tariffs_all() as $t) {
                if ((int)$t['is_active'] === 1) {
                    $base[] = ['key' => 't' . (int)$t['num'], 'label' => (string)$t['name'], 'type' => 'money'];
                }
            }
            return $base;
        }
        if ($v === 'stock' && report_can_stock()) {
            return [...$base, ['key' => 'precio', 'label' => 'Precio', 'type' => 'money'], ['key' => 'stock', 'label' => 'Stock', 'type' => 'qty', 'sum' => true], ['key' => 'minimo', 'label' => 'Stock mínimo', 'type' => 'qty']];
        }
        return [...$base, ['key' => 'precio', 'label' => 'Precio', 'type' => 'money']];
    },
    'rows' => function (array $sel): array {
        $f = $sel['f'];
        $stockOk = $sel['v'] === 'stock' && report_can_stock();
        $w = " WHERE p.ref <> ''";
        $a = [];
        rep_cond_code($w, $a, 'p.ref', $f['ref']);
        rep_cond_like($w, $a, ['p.name'], $f['nombre']);
        if (empty($sel['opt']['ocultos'])) {
            $w .= ' AND p.visible = 1';
        }
        if ($f['familia'] !== '') {
            $w .= ' AND COALESCE(pc.id, c.id) = ?';
            $a[] = (int)$f['familia'];
        }
        $st = db()->prepare('SELECT p.id, p.ref, p.name, COALESCE(pc.name, c.name) AS fam FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN categories pc ON pc.id = c.parent_id' . $w . ' ORDER BY p.ref LIMIT ' . (REP_MAX_ROWS + 1));
        $st->execute($a);
        $prods = $st->fetchAll();
        $ids = array_map(fn($r) => (int)$r['id'], $prods);
        $num = function (?string $v): ?int { // texto de precio → céntimos
            $n = ($v !== null && $v !== '') ? docs_parse_num($v) : null;
            return $n === null ? null : (int)round($n * 100);
        };
        $prices = [];
        if ($sel['v'] === 'tarifas') {
            foreach (tariffs_all() as $t) {
                if ((int)$t['is_active'] === 1 && ($pf = docs_tariff_field((int)$t['num']))) {
                    $prices['t' . (int)$t['num']] = field_values_for((int)$pf['id'], $ids);
                }
            }
        } else {
            $pf = field_price_public() ?? (docs_price_fields()[0] ?? null);
            $prices['precio'] = $pf ? field_values_for((int)$pf['id'], $ids) : [];
        }
        $stock = [];
        $min = [];
        if ($stockOk) {
            $sf = docs_field_named(['stock', 'existencias'], ['number', 'price', 'text']);
            $mf = docs_field_named(['stock minimo', 'stock min', 'minimo'], ['number', 'price', 'text']);
            $stock = $sf ? field_values_for((int)$sf['id'], $ids) : [];
            $min = $mf ? field_values_for((int)$mf['id'], $ids) : [];
        }
        $out = [];
        foreach ($prods as $p) {
            $id = (int)$p['id'];
            $row = ['ref' => (string)$p['ref'], 'nombre' => (string)$p['name'], 'familia' => (string)$p['fam'], '_familia' => (string)$p['fam']];
            foreach ($prices as $k => $vals) {
                $row[$k] = $num($vals[$id] ?? null);
            }
            if ($stockOk) {
                $s = isset($stock[$id]) && $stock[$id] !== '' ? docs_parse_num($stock[$id]) : null;
                $m = isset($min[$id]) && $min[$id] !== '' ? docs_parse_num($min[$id]) : null;
                $row['stock'] = $s;
                $row['minimo'] = $m;
            }
            $out[] = $row;
        }
        return $out;
    },
];
