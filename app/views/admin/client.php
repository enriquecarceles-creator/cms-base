<?php
/** Ficha de cliente: datos, sus facturas y lo que ha comprado. */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$self = '/admin/clientes/' . rawurlencode($c['cust_code']);
?>
<div class="head"><h1><?= e($c['name'] !== '' ? $c['name'] : 'Cliente ' . $c['cust_code']) ?></h1><a href="<?= e(url('/admin/clientes')) ?>">← Clientes</a></div>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>
<p class="muted" style="margin-top:0">Código <?= e($c['cust_code']) ?>
  <?= (int)$c['blocked'] === 1 ? ' · <span class="review-flag">bloqueado</span>' : '' ?><?= (int)$c['ended_day'] > 0 ? ' · <span class="review-flag">de baja desde ' . e(erp_day((int)$c['ended_day'])) . '</span>' : '' ?>
  <?= $retail ? ' · cliente de contado (tickets de mostrador)' : '' ?></p>

<section class="stats" aria-label="Resumen">
  <div class="stat"><span class="stat-l">Facturas</span><strong class="stat-n"><?= e($n((int)$sum['n'])) ?></strong></div>
  <div class="stat"><span class="stat-l">Total comprado</span><strong class="stat-n stat-date"><?= e(erp_money((int)$sum['tot'])) ?></strong></div>
  <div class="stat"><span class="stat-l">Primera compra</span><strong class="stat-n stat-date"><?= e(erp_day((int)$sum['first_day'])) ?></strong></div>
  <div class="stat"><span class="stat-l">Última compra</span><strong class="stat-n stat-date"><?= e(erp_day((int)$sum['last_day'])) ?></strong></div>
</section>

<h2>Datos</h2>
<dl class="kv">
  <?php $rows = [
      'Nombre comercial' => $c['trade_name'], 'NIF' => $c['nif'], 'Domicilio' => $c['address'],
      'Población' => trim($c['postcode'] . ' ' . $c['city']), 'Provincia' => $c['province'], 'País' => $c['country'],
      'Contacto' => $c['contact'], 'Forma de pago (código NCS)' => $c['pay_code'], 'Tarifa' => (int)$c['tariff'] > 0 ? (string)(int)$c['tariff'] : '',
      'Descuento' => (int)$c['disc_bp'] > 0 ? rtrim(rtrim(number_format($c['disc_bp'] / 100, 2, ',', ''), '0'), ',') . ' %' : '', 'Alta en NCS' => (int)$c['created_day'] > 0 ? erp_day((int)$c['created_day']) : '',
  ]; foreach ($rows as $k => $v): if ($v === '') continue; ?>
    <div><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd></div>
  <?php endforeach; ?>
  <?php foreach ([['Teléfono', $c['phone']], ['Teléfono 2', $c['phone2']]] as [$k, $v]): if ($v === '') continue; ?>
    <div><dt><?= e($k) ?></dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $v)) ?>"><?= e($v) ?></a></dd></div>
  <?php endforeach; ?>
  <?php if ($c['email'] !== ''): ?><div><dt>Email</dt><dd><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></dd></div><?php endif; ?>
</dl>

<h2>Lo que ha comprado</h2>
<?php if (!$bought): ?>
  <p class="empty">Todavía no hay compras de este cliente.</p>
<?php else: ?>
  <p class="muted" style="margin-top:0">Los 30 artículos de mayor importe (las devoluciones restan). <?= $retail ? 'Este es el cliente de contado: son las ventas de mostrador.' : '' ?></p>
  <div class="tbl-wrap"><table class="tbl list">
    <thead><tr><th scope="col">Artículo</th><th scope="col">Unidades</th><th scope="col">Importe</th><th scope="col">Líneas</th></tr></thead>
    <tbody>
    <?php foreach ($bought as $b): $code = (string)$b['article_code']; ?>
      <tr>
        <td><?php if (isset($links[$code])): ?><a href="<?= e(url('/admin/articulo/' . (int)$links[$code])) ?>"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?> <span class="muted"><?= e($b['title']) ?></span></td>
        <td><?= e(erp_qty((int)$b['qty'])) ?></td>
        <td><?= e(erp_money((int)$b['amount'])) ?></td>
        <td><?= e($n((int)$b['n'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>

<h2>Facturas</h2>
<?php if (!$invoices): ?>
  <p class="empty">Este cliente no tiene facturas.</p>
<?php else: ?>
  <div class="tbl-wrap"><table class="tbl list">
    <thead><tr><th scope="col">Factura</th><th scope="col">Fecha</th><th scope="col">Total</th></tr></thead>
    <tbody>
    <?php foreach ($invoices as $i): ?>
      <tr<?= (int)$i['annulled'] === 1 ? ' class="off"' : '' ?>>
        <td><a href="<?= e(url('/admin/ventas/' . rawurlencode($i['inv_number']))) ?>"><?= e($i['inv_number']) ?></a><?= (int)$i['annulled'] === 1 ? ' <small class="review-flag">anulada</small>' : '' ?></td>
        <td class="t-date"><?= e(erp_day((int)$i['inv_day'])) ?></td>
        <td><?= e(erp_money((int)$i['total_cents'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <nav class="pager" aria-label="Páginas">
    <span class="muted">Página <?= $page ?> de <?= $pages ?></span>
    <span class="pager-l">
      <?php if ($page > 1): ?><a href="<?= e(url($self . ($page > 2 ? '?pag=' . ($page - 1) : ''))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
      <?php if ($page < $pages): ?><a href="<?= e(url($self . '?pag=' . ($page + 1))) ?>" rel="next">Siguiente ›</a><?php endif; ?>
    </span>
  </nav>
  <?php endif; ?>
<?php endif; ?>
