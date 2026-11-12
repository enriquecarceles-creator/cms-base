<?php
/**
 * Vencimientos de las facturas emitidas en el CMS (tabla doc_dues): pendientes, vencidos y cobrados entre fechas.
 * Las facturas importadas de NCS no traen vencimientos (solo si están cobradas o no): no salen aquí.
 * Una cuota está cobrada si lo está ella o si la factura entera está marcada como cobrada.
 */
return [
    'id' => 'vencimientos', 'title' => 'Vencimientos', 'section' => 'Ventas', 'order' => 40, 'perm' => 'erp', 'history' => 'ventas',
    'help' => 'Cuotas de las facturas del CMS: pendientes, vencidas y cobradas entre fechas. Las facturas importadas de NCS no traen vencimientos.',
    'filters' => [
        ['name' => 'fecha', 'label' => 'Fecha de vencimiento', 'type' => 'date'],
        ['name' => 'cliente', 'label' => 'Cliente', 'type' => 'code', 'lookup' => 'cliente'],
        ['name' => 'pago', 'label' => 'Forma de pago', 'type' => 'select', 'options' => 'rep_pay_options'],
    ],
    'variants' => ['pendientes' => 'Pendientes de cobro', 'vencidos' => 'Vencidos (pendientes con fecha pasada)', 'cobrados' => 'Cobrados'],
    'groups' => ['cliente' => ['label' => 'Cliente', 'field' => '_cliente'], 'mes' => ['label' => 'Mes de vencimiento', 'field' => '_mes'], 'pago' => ['label' => 'Forma de pago', 'field' => '_pago']],
    'orders' => ['venc' => ['label' => 'Vencimiento', 'by' => ['venc', 'num']], 'cliente' => ['label' => 'Cliente', 'by' => ['cliente', 'venc']], 'importe' => ['label' => 'Importe (mayor primero)', 'by' => ['_ni']]],
    'options' => [['name' => 'pruebas', 'label' => 'Incluir documentos de prueba', 'default' => 0]],
    'columns' => function (string $v): array {
        $c = [['key' => 'num', 'label' => 'Factura', 'w' => 16], ['key' => 'cuota', 'label' => 'Cuota', 'w' => 8], ['key' => 'cli_cod', 'label' => 'Cód. cliente', 'w' => 12], ['key' => 'cliente', 'label' => 'Cliente', 'w' => 30],
            ['key' => 'venc', 'label' => 'Vencimiento', 'type' => 'date'], ['key' => 'importe', 'label' => 'Importe', 'type' => 'money', 'sum' => true]];
        if ($v === 'cobrados') {
            return [...$c, ['key' => 'cobro', 'label' => 'Cobrado el', 'type' => 'date'], ['key' => 'pago', 'label' => 'Forma de pago', 'w' => 22]];
        }
        return [...$c, ['key' => 'dias', 'label' => 'Días de retraso', 'type' => 'int'], ['key' => 'pago', 'label' => 'Forma de pago', 'w' => 22]];
    },
    'rows' => function (array $sel): array {
        $f = $sel['f'];
        $today = (int)date('Ymd');
        $w = " WHERE h.status = 'emitida'" . (empty($sel['opt']['pruebas']) ? ' AND h.is_test = 0' : '');
        $a = [];
        rep_cond_date($w, $a, 'u.due_day', $f['fecha']);
        rep_cond_code($w, $a, 'h.cust_code', $f['cliente']);
        if ($f['pago'] !== '') {
            $w .= ' AND h.pay_code = ?';
            $a[] = $f['pago'];
        }
        if ($sel['v'] === 'cobrados') {
            $w .= ' AND (u.paid = 1 OR COALESCE(s.paid, 0) = 1)';
        } else {
            $w .= ' AND u.paid = 0 AND COALESCE(s.paid, 0) = 0';
            if ($sel['v'] === 'vencidos') {
                $w .= ' AND u.due_day < ?';
                $a[] = $today;
            }
        }
        $st = db()->prepare('SELECT h.doc_code, h.cust_code, h.cust_name, h.pay_code, u.due_no, u.due_day, u.amount_cents, u.paid, u.paid_day, COALESCE(s.paid_day, 0) AS inv_paid_day,
                (SELECT COUNT(*) FROM doc_dues x WHERE x.doc_id = u.doc_id) AS n_dues
            FROM doc_dues u JOIN doc_headers h ON h.id = u.doc_id LEFT JOIN erp_invoices i ON i.inv_number = h.doc_code LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id' . $w
            . ' ORDER BY u.due_day, h.id, u.due_no LIMIT ' . (REP_MAX_ROWS + 1));
        $st->execute($a);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $due = (int)$r['due_day'];
            $days = 0;
            if ($sel['v'] !== 'cobrados' && $due > 0 && $due < $today) {
                $days = (int)floor((strtotime((string)$today) - strtotime((string)$due)) / 86400);
            }
            $pago = erp_pay_label((string)$r['pay_code']);
            $name = (string)$r['cust_name'];
            $out[] = ['num' => (string)$r['doc_code'], 'cuota' => (int)$r['n_dues'] > 1 ? (int)$r['due_no'] . '/' . (int)$r['n_dues'] : '1/1', 'cli_cod' => (string)$r['cust_code'], 'cliente' => $name,
                'venc' => $due, 'importe' => (int)$r['amount_cents'], 'dias' => $days, 'cobro' => (int)$r['paid'] === 1 ? (int)$r['paid_day'] : (int)$r['inv_paid_day'], 'pago' => $pago,
                '_cliente' => ($r['cust_code'] !== '' ? $r['cust_code'] . ' · ' : '') . $name, '_mes' => rep_month_key($due), '_pago' => $pago, '_ni' => -(int)$r['amount_cents']];
        }
        return $out;
    },
];
