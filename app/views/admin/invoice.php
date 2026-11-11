<?php
/** Factura con sus líneas. */
$neg = (int)$inv['total_cents'] < 0;
?>
<div class="head"><h1>Factura <?= e($inv['inv_number']) ?></h1><a href="<?= e(url($cust ? '/admin/clientes/' . rawurlencode($cust['cust_code']) : '/admin/clientes')) ?>">← <?= $cust ? e($cust['name'] !== '' ? $cust['name'] : $cust['cust_code']) : 'Clientes' ?></a></div>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>
<p class="muted" style="margin-top:0">
  <?= e(erp_day((int)$inv['inv_day'])) ?>
  <?= (int)$inv['annulled'] === 1 ? ' · <span class="review-flag">anulada</span>' : '' ?>
  <?= $neg ? ' · importe negativo (devolución)' : '' ?>
  <?= $inv['rectifies'] !== '' ? ' · rectifica a ' . e($inv['rectifies']) : '' ?>
  <?= $inv['seller'] !== '' ? ' · vendedor ' . e($inv['seller']) : '' ?>
  <?= $inv['pay_code'] !== '' ? ' · forma de pago (código) ' . e($inv['pay_code']) : '' ?></p>

<div class="tbl-wrap"><table class="tbl list">
  <thead><tr><th scope="col">Artículo</th><th scope="col">Unidades</th><th scope="col">Precio</th><th scope="col">Dto.</th><th scope="col">Importe</th></tr></thead>
  <tbody>
  <?php if (!$lines): ?><tr><td colspan="5" class="muted">Esta factura no tiene líneas.</td></tr><?php endif; ?>
  <?php foreach ($lines as $l): $code = (string)$l['article_code']; ?>
    <tr<?= (int)$l['qty_milli'] < 0 ? ' class="off"' : '' ?>>
      <td><?php if (isset($links[$code])): ?><a href="<?= e(url('/admin/articulo/' . (int)$links[$code])) ?>"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?> <span class="muted"><?= e($l['title']) ?></span></td>
      <td><?= e(erp_qty((int)$l['qty_milli'])) ?></td>
      <td><?= e(erp_money((int)$l['price_cents'])) ?></td>
      <td><?= (int)$l['disc_bp'] > 0 ? e(rtrim(rtrim(number_format($l['disc_bp'] / 100, 2, ',', ''), '0'), ',')) . ' %' : '' ?></td>
      <td><?= e(erp_money((int)$l['amount_cents'])) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th scope="row" colspan="4">Base imponible</th><td><?= e(erp_money((int)$inv['base_cents'])) ?></td></tr>
    <tr><th scope="row" colspan="4">IVA</th><td><?= e(erp_money((int)$inv['vat_cents'])) ?></td></tr>
    <tr><th scope="row" colspan="4">Total</th><td><strong><?= e(erp_money((int)$inv['total_cents'])) ?></strong></td></tr>
  </tfoot>
</table></div>
