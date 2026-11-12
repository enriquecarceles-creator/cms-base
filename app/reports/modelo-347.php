<?php
/**
 * Operaciones con terceros (modelo 347): suma, por NIF y por trimestre, las ventas (facturas de clientes, NCS y CMS, no anuladas) y las compras
 * (facturas recibidas cerradas) y deja las de MÁS de 3.005,06 € al año. Importes con IVA incluido, como se declaran. Lleva datos de clientes y
 * de proveedores: solo administradores (hace falta ver Ventas y Compras). Es una ayuda: se revisa con el asesor antes de presentarlo.
 */
const REP_347_LIMIT = 300506; // céntimos

return [
    'id' => 'modelo-347', 'title' => 'Operaciones con terceros (347)', 'section' => 'Fiscal', 'order' => 30, 'perm' => 'admin', 'guards' => ['erp', 'purch'], 'history' => 'ventas',
    'help' => 'Ventas y compras por NIF y trimestre; solo las que pasan de 3.005,06 € al año. Solo administradores.',
    'notice' => 'Revisar con el asesor antes de presentarlo.',
    'filters' => [
        ['name' => 'anio', 'label' => 'Año', 'type' => 'select', 'options' => function () {
            $o = [];
            for ($y = (int)date('Y'); $y >= (int)date('Y') - 6; $y--) {
                $o[(string)$y] = (string)$y;
            }
            return $o;
        }],
        ['name' => 'nif', 'label' => 'NIF', 'type' => 'text', 'personal' => true],
    ],
    'variants' => ['clave' => 'Ventas y compras por separado (como en el modelo)', 'suma' => 'Suma de ventas y compras de cada NIF'],
    'groups' => [],
    'orders' => ['total' => ['label' => 'Total (mayor primero)', 'by' => ['_nt']], 'nif' => ['label' => 'NIF', 'by' => ['nif']], 'nombre' => ['label' => 'Nombre', 'by' => ['nombre']]],
    'options' => [],
    'columns' => function (string $v): array {
        $q = [['key' => 't1', 'label' => '1T', 'type' => 'money', 'sum' => true], ['key' => 't2', 'label' => '2T', 'type' => 'money', 'sum' => true], ['key' => 't3', 'label' => '3T', 'type' => 'money', 'sum' => true],
            ['key' => 't4', 'label' => '4T', 'type' => 'money', 'sum' => true]];
        if ($v === 'suma') {
            return [['key' => 'nif', 'label' => 'NIF', 'w' => 14], ['key' => 'nombre', 'label' => 'Nombre', 'w' => 34], ['key' => 'ventas', 'label' => 'Ventas', 'type' => 'money', 'sum' => true],
                ['key' => 'compras', 'label' => 'Compras', 'type' => 'money', 'sum' => true], ...$q, ['key' => 'total', 'label' => 'Total anual', 'type' => 'money', 'sum' => true]];
        }
        return [['key' => 'nif', 'label' => 'NIF', 'w' => 14], ['key' => 'nombre', 'label' => 'Nombre', 'w' => 34], ['key' => 'clave', 'label' => 'Operación', 'w' => 12], ...$q, ['key' => 'total', 'label' => 'Total anual', 'type' => 'money', 'sum' => true]];
    },
    'rows' => function (array $sel): array {
        $year = (int)($sel['f']['anio'] !== '' ? $sel['f']['anio'] : date('Y'));
        $from = $year * 10000 + 101;
        $to = $year * 10000 + 1231;
        $norm = fn(string $n) => strtoupper(preg_replace('/[\s.\-\/]/', '', $n) ?? '');
        $acc = []; // [nif][clave] => ['name', 'q' => [1..4]]
        $add = function (string $nif, string $name, string $clave, int $day, int $cents) use (&$acc, $norm) {
            $nif = $norm($nif);
            if ($nif === '') {
                return;
            }
            $q = (int)ceil((intdiv($day, 100) % 100) / 3);
            $acc[$nif][$clave] ??= ['name' => $name, 'q' => [1 => 0, 2 => 0, 3 => 0, 4 => 0]];
            $acc[$nif][$clave]['q'][$q] += $cents;
            if ($acc[$nif][$clave]['name'] === '') {
                $acc[$nif][$clave]['name'] = $name;
            }
        };
        $st = db()->prepare("SELECT i.inv_day, i.total_cents, COALESCE(c.nif, '') AS nif, COALESCE(NULLIF(c.name, ''), c.trade_name, '') AS name
            FROM erp_invoices i JOIN erp_customers c ON c.cust_code = i.cust_code WHERE i.annulled = 0 AND i.inv_day BETWEEN ? AND ? AND c.nif <> ''");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $add((string)$r['nif'], (string)$r['name'], 'A', (int)$r['inv_day'], (int)$r['total_cents']);
        }
        $st = db()->prepare("SELECT doc_day, total_cents, cust_nif, cust_name FROM doc_headers WHERE doc_type IN ('factura_c', 'rectificativa_c') AND status = 'emitida' AND doc_day BETWEEN ? AND ? AND cust_nif <> ''");
        $st->execute([$from, $to]);
        foreach ($st->fetchAll() as $r) {
            $add((string)$r['cust_nif'], (string)$r['cust_name'], 'B', (int)$r['doc_day'], (int)$r['total_cents']);
        }
        $out = [];
        $filter = $norm($sel['f']['nif']);
        foreach ($acc as $nif => $claves) {
            if ($filter !== '' && !str_contains($nif, $filter)) {
                continue;
            }
            if ($sel['v'] === 'suma') {
                $v = array_sum(array_map(fn($x) => array_sum($x['q']), array_intersect_key($claves, ['A' => 1])));
                $c = array_sum(array_map(fn($x) => array_sum($x['q']), array_intersect_key($claves, ['B' => 1])));
                $qs = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
                foreach ($claves as $x) {
                    foreach ($x['q'] as $k => $val) {
                        $qs[$k] += $val;
                    }
                }
                if (abs($v + $c) <= REP_347_LIMIT) {
                    continue;
                }
                $name = (string)(($claves['A']['name'] ?? '') !== '' ? $claves['A']['name'] : ($claves['B']['name'] ?? ''));
                $out[] = ['nif' => $nif, 'nombre' => $name, 'ventas' => $v, 'compras' => $c, 't1' => $qs[1], 't2' => $qs[2], 't3' => $qs[3], 't4' => $qs[4], 'total' => $v + $c, '_nt' => -abs($v + $c)];
                continue;
            }
            foreach ($claves as $clave => $x) {
                $tot = array_sum($x['q']);
                if (abs($tot) <= REP_347_LIMIT) { // el umbral es por tercero y tipo de operación
                    continue;
                }
                $out[] = ['nif' => $nif, 'nombre' => $x['name'], 'clave' => $clave === 'A' ? 'A · Ventas' : 'B · Compras', 't1' => $x['q'][1], 't2' => $x['q'][2], 't3' => $x['q'][3], 't4' => $x['q'][4],
                    'total' => $tot, '_nt' => -abs($tot)];
            }
        }
        return $out;
    },
];
