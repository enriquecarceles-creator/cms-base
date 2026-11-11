<?php
/** Ventas → Totales por mes: ventas, devoluciones y neto por mes, con barras en CSS. */
include __DIR__ . '/sales_head.php';
if ($totals['invoices'] === 0) { return; }
$maxNet = 1;
foreach ($rows as $r) {
    $maxNet = max($maxNet, abs((int)$r['sales']), abs((int)$r['ret']));
}
$tot = ['n' => 0, 'sales' => 0, 'ret' => 0, 'net' => 0];
foreach ($rows as $r) {
    $tot['n'] += (int)$r['n'];
    $tot['sales'] += (int)$r['sales'];
    $tot['ret'] += (int)$r['ret'];
    $tot['net'] += (int)$r['net'];
}
?>
<?php if (!$rows): ?><p class="empty">No hay ventas con ese filtro.</p><?php else: ?>
<p class="muted" style="margin-top:0">Las facturas anuladas no cuentan. <strong>Ventas</strong> = facturas con importe positivo; <strong>devoluciones</strong> = importes negativos; <strong>neto</strong> = la suma de las dos.</p>
<div class="tbl-wrap"><table class="tbl list">
  <thead><tr><th scope="col">Mes</th><th scope="col">Facturas</th><th scope="col">Ventas</th><th scope="col">Devoluciones</th><th scope="col">Neto</th><th scope="col"><span class="sr-only">Gráfico</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $pos = (int)$r['sales']; $neg = (int)$r['ret']; ?>
    <tr>
      <td><a href="<?= e(url(erp_sales_url('facturas', array_diff_key($f, ['pag' => 1]), ['anio' => (int)$r['inv_year'], 'mes' => (int)$r['inv_month']]))) ?>"><?= e(erp_month_label((int)$r['inv_year'], (int)$r['inv_month'], true)) ?></a></td>
      <td><?= e($n((int)$r['n'])) ?></td>
      <td><?= e(erp_money($pos)) ?></td>
      <td><?= $neg !== 0 ? '<span class="down">' . e(erp_money($neg)) . '</span>' : '—' ?></td>
      <td><strong><?= e(erp_money((int)$r['net'])) ?></strong></td>
      <td class="mbar" aria-hidden="true"><span class="mb-pos" style="width:<?= round(100 * $pos / $maxNet, 1) ?>%"></span><?php if ($neg !== 0): ?><span class="mb-neg" style="width:<?= round(100 * abs($neg) / $maxNet, 1) ?>%"></span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr><th scope="row">Total</th><td><?= e($n($tot['n'])) ?></td><td><?= e(erp_money($tot['sales'])) ?></td><td><?= e(erp_money($tot['ret'])) ?></td><td><strong><?= e(erp_money($tot['net'])) ?></strong></td><td></td></tr></tfoot>
</table></div>
<?php endif; ?>
