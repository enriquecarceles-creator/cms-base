<?php
/**
 * Vencimientos de pago: cuotas de las facturas recibidas CERRADAS (doc_dues): pendientes de pago, vencidas y pagadas entre fechas.
 * Una cuota se marca como pagada en la propia factura recibida (sección «Pagos de los vencimientos»).
 */
return [
    'id' => 'pagos', 'title' => 'Vencimientos de pago', 'section' => 'Compras', 'order' => 40, 'perm' => 'purch', 'history' => 'compras',
    'help' => 'Cuotas de las facturas recibidas: pendientes de pago, vencidas y pagadas entre fechas.',
    'filters' => [
        ['name' => 'fecha', 'label' => 'Fecha de vencimiento', 'type' => 'date'],
        ['name' => 'proveedor', 'label' => 'Proveedor', 'type' => 'code', 'lookup' => 'proveedor'],
        ['name' => 'pago', 'label' => 'Forma de pago', 'type' => 'select', 'options' => 'rep_pay_options'],
    ],
    'variants' => ['pendientes' => 'Pendientes de pago', 'vencidos' => 'Vencidos (pendientes con fecha pasada)', 'pagados' => 'Pagados'],
    'groups' => ['proveedor' => ['label' => 'Proveedor', 'field' => '_proveedor'], 'mes' => ['label' => 'Mes de vencimiento', 'field' => '_mes'], 'pago' => ['label' => 'Forma de pago', 'field' => '_pago']],
    'orders' => ['venc' => ['label' => 'Vencimiento', 'by' => ['venc', 'num']], 'proveedor' => ['label' => 'Proveedor', 'by' => ['nombre', 'venc']], 'importe' => ['label' => 'Importe (mayor primero)', 'by' => ['_ni']]],
    'options' => [],
    'columns' => function (string $v): array {
        $c = [['key' => 'num', 'label' => 'Registro', 'w' => 16], ['key' => 'sup_ref', 'label' => 'Nº proveedor', 'w' => 18], ['key' => 'cuota', 'label' => 'Cuota', 'w' => 8], ['key' => 'nombre', 'label' => 'Proveedor', 'w' => 30],
            ['key' => 'venc', 'label' => 'Vencimiento', 'type' => 'date'], ['key' => 'importe', 'label' => 'Importe', 'type' => 'money', 'sum' => true]];
        return $v === 'pagados'
            ? [...$c, ['key' => 'pagado', 'label' => 'Pagado el', 'type' => 'date'], ['key' => 'pago', 'label' => 'Forma de pago', 'w' => 22]]
            : [...$c, ['key' => 'dias', 'label' => 'Días de retraso', 'type' => 'int'], ['key' => 'pago', 'label' => 'Forma de pago', 'w' => 22]];
    },
    'rows' => function (array $sel): array {
        $f = $sel['f'];
        $today = (int)date('Ymd');
        $w = " WHERE h.status = 'emitida' AND h.doc_type IN ('factura_c', 'rectificativa_c')";
        $a = [];
        rep_cond_date($w, $a, 'u.due_day', $f['fecha']);
        rep_cond_code($w, $a, 'h.cust_code', $f['proveedor']);
        if ($f['pago'] !== '') {
            $w .= ' AND h.pay_code = ?';
            $a[] = $f['pago'];
        }
        if ($sel['v'] === 'pagados') {
            $w .= ' AND u.paid = 1';
        } else {
            $w .= ' AND u.paid = 0';
            if ($sel['v'] === 'vencidos') {
                $w .= ' AND u.due_day < ?';
                $a[] = $today;
            }
        }
        $st = db()->prepare('SELECT h.doc_code, h.sup_ref, h.cust_code, h.cust_name, h.pay_code, u.due_no, u.due_day, u.amount_cents, u.paid_day,
                (SELECT COUNT(*) FROM doc_dues x WHERE x.doc_id = u.doc_id) AS n_dues
            FROM doc_dues u JOIN doc_headers h ON h.id = u.doc_id' . $w . ' ORDER BY u.due_day, h.id, u.due_no LIMIT ' . (REP_MAX_ROWS + 1));
        $st->execute($a);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $due = (int)$r['due_day'];
            $days = $sel['v'] !== 'pagados' && $due > 0 && $due < $today ? (int)floor((strtotime((string)$today) - strtotime((string)$due)) / 86400) : 0;
            $pago = erp_pay_label((string)$r['pay_code']);
            $name = (string)$r['cust_name'];
            $out[] = ['num' => (string)$r['doc_code'], 'sup_ref' => (string)$r['sup_ref'], 'cuota' => (int)$r['n_dues'] > 1 ? (int)$r['due_no'] . '/' . (int)$r['n_dues'] : '1/1', 'nombre' => $name,
                'venc' => $due, 'importe' => (int)$r['amount_cents'], 'dias' => $days, 'pagado' => (int)$r['paid_day'], 'pago' => $pago,
                '_proveedor' => ($r['cust_code'] !== '' ? $r['cust_code'] . ' · ' : '') . $name, '_mes' => rep_month_key($due), '_pago' => $pago, '_ni' => -(int)$r['amount_cents']];
        }
        return $out;
    },
];
