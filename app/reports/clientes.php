<?php
/**
 * Informe de clientes: ficha resumida, datos comerciales (tarifa, forma de pago, descuento) y saldos y vencimientos pendientes.
 * Contiene datos personales: solo con acceso a Clientes y Ventas; el archivo no se guarda en el servidor.
 */
return [
    'id' => 'clientes', 'title' => 'Clientes', 'section' => 'Clientes', 'order' => 10, 'perm' => 'erp', 'history' => 'clientes',
    'help' => 'Ficha resumida, datos comerciales y saldos pendientes de cobro. Contiene datos personales.',
    'filters' => [
        ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'code', 'lookup' => 'cliente'],
        ['name' => 'nombre', 'label' => 'Nombre', 'type' => 'text'],
        ['name' => 'nif', 'label' => 'NIF', 'type' => 'text', 'personal' => true],
        ['name' => 'poblacion', 'label' => 'Población', 'type' => 'text'],
        ['name' => 'pago', 'label' => 'Forma de pago', 'type' => 'select', 'options' => 'rep_pay_options'],
        ['name' => 'tarifa', 'label' => 'Tarifa', 'type' => 'select', 'options' => function () {
            $o = [];
            foreach (tariffs_all() as $t) {
                $o[(string)$t['num']] = (string)$t['name'];
            }
            return $o;
        }],
    ],
    'variants' => ['resumen' => 'Ficha resumida', 'comercial' => 'Datos comerciales', 'saldos' => 'Saldos y vencimientos pendientes'],
    'groups' => [
        'pago' => ['label' => 'Forma de pago', 'field' => '_pago'],
        'tarifa' => ['label' => 'Tarifa', 'field' => '_tarifa'],
        'poblacion' => ['label' => 'Población', 'field' => '_poblacion'],
    ],
    'orders' => ['codigo' => ['label' => 'Código', 'by' => ['codigo']], 'nombre' => ['label' => 'Nombre', 'by' => ['nombre']], 'nif' => ['label' => 'NIF', 'by' => ['nif']], 'saldo' => ['label' => 'Saldo pendiente (mayor primero)', 'by' => ['_ns']]],
    'options' => [
        ['name' => 'bloqueados', 'label' => 'Incluir bloqueados y de baja', 'default' => 0],
        ['name' => 'contado', 'label' => 'Incluir cliente de contado', 'default' => 0],
    ],
    'columns' => function (string $v): array {
        $id = [['key' => 'codigo', 'label' => 'Código', 'w' => 12], ['key' => 'nombre', 'label' => 'Nombre', 'w' => 32]];
        if ($v === 'comercial') {
            return [...$id, ['key' => 'nif', 'label' => 'NIF', 'w' => 14], ['key' => 'tarifa', 'label' => 'Tarifa', 'w' => 16], ['key' => 'pago', 'label' => 'Forma de pago', 'w' => 22],
                ['key' => 'dto', 'label' => 'Descuento', 'type' => 'pct'], ['key' => 'dias', 'label' => 'Días de pago', 'w' => 12], ['key' => 'estado', 'label' => 'Estado', 'w' => 12]];
        }
        if ($v === 'saldos') {
            return [...$id, ['key' => 'n_pend', 'label' => 'Facturas pendientes', 'type' => 'int', 'sum' => true], ['key' => 'pendiente', 'label' => 'Pendiente de cobro', 'type' => 'money', 'sum' => true],
                ['key' => 'vencido', 'label' => 'Vencido', 'type' => 'money', 'sum' => true], ['key' => 'proximo', 'label' => 'Próximo vencimiento', 'type' => 'date']];
        }
        return [...$id, ['key' => 'nif', 'label' => 'NIF', 'w' => 14], ['key' => 'poblacion', 'label' => 'Población', 'w' => 20], ['key' => 'provincia', 'label' => 'Provincia', 'w' => 16],
            ['key' => 'telefono', 'label' => 'Teléfono', 'w' => 14], ['key' => 'email', 'label' => 'Correo', 'w' => 28]];
    },
    'rows' => function (array $sel): array {
        $f = $sel['f'];
        $w = ' WHERE 1 = 1';
        $a = [];
        rep_cond_code($w, $a, 'c.cust_code', $f['cliente']);
        rep_cond_like($w, $a, ['c.name', 'c.trade_name'], $f['nombre']);
        rep_cond_like($w, $a, ['c.nif'], $f['nif']);
        rep_cond_like($w, $a, ['c.city'], $f['poblacion']);
        if ($f['pago'] !== '') {
            $w .= ' AND c.pay_code = ?';
            $a[] = $f['pago'];
        }
        if ($f['tarifa'] !== '') {
            $w .= ' AND c.tariff = ?';
            $a[] = (int)$f['tarifa'];
        }
        if (empty($sel['opt']['bloqueados'])) {
            $w .= ' AND c.blocked = 0 AND c.ended_day = 0';
        }
        $retail = erp_retail_code();
        if (empty($sel['opt']['contado']) && $retail !== '') {
            $w .= ' AND c.cust_code <> ?';
            $a[] = $retail;
        }
        $st = db()->prepare('SELECT c.* FROM erp_customers c' . $w . ' ORDER BY c.cust_code LIMIT ' . (REP_MAX_ROWS + 1));
        $st->execute($a);
        $custs = $st->fetchAll();
        $tariffs = [];
        foreach (tariffs_all() as $t) {
            $tariffs[(int)$t['num']] = (string)$t['name'];
        }
        $pend = [];
        $dues = [];
        if ($sel['v'] === 'saldos') {
            $db = db();
            foreach ($db->query('SELECT i.cust_code, COUNT(*) AS n, COALESCE(SUM(i.total_cents), 0) AS t FROM erp_invoices i LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id
                WHERE i.annulled = 0 AND i.total_cents > 0 AND COALESCE(s.paid, 0) = 0 GROUP BY i.cust_code')->fetchAll() as $r) {
                $pend[(string)$r['cust_code']] = ['n' => (int)$r['n'], 't' => (int)$r['t']];
            }
            $st2 = $db->prepare("SELECT h.cust_code, MIN(u.due_day) AS next_day, COALESCE(SUM(CASE WHEN u.due_day < ? THEN u.amount_cents ELSE 0 END), 0) AS overdue
                FROM doc_dues u JOIN doc_headers h ON h.id = u.doc_id LEFT JOIN erp_invoices i ON i.inv_number = h.doc_code LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id
                WHERE u.paid = 0 AND h.status = 'emitida' AND h.is_test = 0 AND COALESCE(s.paid, 0) = 0 GROUP BY h.cust_code");
            $st2->execute([(int)date('Ymd')]);
            foreach ($st2->fetchAll() as $r) {
                $dues[(string)$r['cust_code']] = ['next' => (int)$r['next_day'], 'over' => (int)$r['overdue']];
            }
        }
        $out = [];
        foreach ($custs as $c) {
            $code = (string)$c['cust_code'];
            $name = rep_cust_label($code, (string)$c['name'], (string)$c['trade_name']);
            $tar = (int)$c['tariff'] > 0 ? ($tariffs[(int)$c['tariff']] ?? 'Tarifa ' . (int)$c['tariff']) : '';
            $pago = erp_pay_label((string)$c['pay_code']);
            $p = $pend[$code] ?? ['n' => 0, 't' => 0];
            $d = $dues[$code] ?? ['next' => 0, 'over' => 0];
            if ($sel['v'] === 'saldos' && $p['t'] === 0 && $d['over'] === 0 && $d['next'] === 0) {
                continue; // en «saldos» solo salen los clientes con algo pendiente
            }
            $out[] = ['codigo' => $code, 'nombre' => $name, 'nif' => (string)$c['nif'], 'poblacion' => (string)$c['city'], 'provincia' => (string)$c['province'],
                'telefono' => (string)$c['phone'], 'email' => (string)$c['email'], 'tarifa' => $tar, 'pago' => $pago, 'dto' => (int)$c['disc_bp'] / 100, 'dias' => (string)$c['pay_days'],
                'estado' => (int)$c['blocked'] === 1 ? 'Bloqueado' : ((int)$c['ended_day'] > 0 ? 'De baja' : 'Activo'),
                'n_pend' => $p['n'], 'pendiente' => $p['t'], 'vencido' => $d['over'], 'proximo' => $d['next'], '_ns' => -$p['t'],
                '_pago' => $pago, '_tarifa' => $tar, '_poblacion' => (string)$c['city']];
        }
        return $out;
    },
];
