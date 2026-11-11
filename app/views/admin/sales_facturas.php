<?php
/** Ventas → Facturas: lista paginada con filtros y fila de totales. */
include __DIR__ . '/sales_head.php';
if ($totals['invoices'] === 0) { return; }
$sortKey = $f['orden'] ?? 'fecha';
$asc = ($f['dir'] ?? '') === 'asc';
$base = array_diff_key($f, ['pag' => 1]);
$th = function (string $key, string $label) use ($base, $sortKey, $asc) {
    $cur = $sortKey === $key;
    $dir = $cur && !$asc ? 'asc' : '';
    $href = erp_sales_url('facturas', array_diff_key($base, ['orden' => 1, 'dir' => 1]), ['orden' => $key === 'fecha' ? '' : $key, 'dir' => $dir]);
    return '<th scope="col"' . ($cur ? ' aria-sort="' . ($asc ? 'ascending' : 'descending') . '"' : '') . '><a href="' . e(url($href)) . '">' . e($label) . ($cur ? ($asc ? ' ↑' : ' ↓') : '') . '</a></th>';
};
$pageUrl = fn(int $p) => erp_sales_url('facturas', $base, $p > 1 ? ['pag' => (string)$p] : []);
?>
<form class="filters lf" method="get" action="<?= e(url('/admin/ventas')) ?>">
  <?php foreach (array_intersect_key($f, ['anio' => 1, 'mes' => 1, 'ambito' => 1, 'orden' => 1, 'dir' => 1]) as $k => $val): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$val) ?>"><?php endforeach; ?>
  <label class="lf-q"><span class="sr-only">Buscar</span><input type="search" name="q" value="<?= e($f['q'] ?? '') ?>" placeholder="Número de factura, código o nombre de cliente"></label>
  <label><span class="sr-only">Estado</span><select name="ver"><option value="">Todas</option><option value="dev"<?= ($f['ver'] ?? '') === 'dev' ? ' selected' : '' ?>>Devoluciones</option><option value="anul"<?= ($f['ver'] ?? '') === 'anul' ? ' selected' : '' ?>>Anuladas</option></select></label>
  <button class="btn small" type="submit">Buscar</button>
  <?php if (($f['q'] ?? '') !== '' || ($f['ver'] ?? '') !== ''): ?><a class="lf-clear" href="<?= e(url(erp_sales_url('facturas', array_diff_key($f, ['q' => 1, 'ver' => 1, 'pag' => 1])))) ?>">Quitar búsqueda</a><?php endif; ?>
</form>

<section class="stats cf-cards" aria-label="Totales del filtro">
  <div class="stat"><span class="stat-l">Facturas</span><strong class="stat-n"><?= e($n((int)$sum['n'])) ?></strong><span class="stat-s">del filtro</span></div>
  <div class="stat"><span class="stat-l">Base imponible</span><strong class="stat-n stat-date"><?= e(erp_money((int)$sum['base'])) ?></strong><span class="stat-s">sin anuladas</span></div>
  <div class="stat"><span class="stat-l">IVA</span><strong class="stat-n stat-date"><?= e(erp_money((int)$sum['vat'])) ?></strong></div>
  <div class="stat"><span class="stat-l">Total</span><strong class="stat-n stat-date"><?= e(erp_money((int)$sum['tot'])) ?></strong><span class="stat-s">las devoluciones restan</span></div>
</section>

<?php if (!$rows): ?><p class="empty">No hay facturas con ese filtro.</p><?php else: ?>
<div class="tbl-wrap"><table class="tbl list">
  <thead><tr><?= $th('numero', 'Factura') ?><?= $th('fecha', 'Fecha') ?><?= $th('cliente', 'Cliente') ?><th scope="col">Base</th><th scope="col">IVA</th><?= $th('total', 'Total') ?></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $isR = $retail !== '' && $r['cust_code'] === $retail; $nm = $r['ctrade'] !== null && $r['ctrade'] !== '' ? $r['ctrade'] : (string)$r['cname']; ?>
    <tr<?= (int)$r['annulled'] === 1 ? ' class="off"' : '' ?>>
      <td><a href="<?= e(url('/admin/ventas/' . rawurlencode($r['inv_number']))) ?>"><?= e($r['inv_number']) ?></a><?= (int)$r['annulled'] === 1 ? ' <small class="review-flag">anulada</small>' : '' ?><?= (int)$r['total_cents'] < 0 ? ' <small class="review-flag">devolución</small>' : '' ?></td>
      <td class="t-date"><?= e(erp_day((int)$r['inv_day'])) ?></td>
      <td><?php if ($isR): ?><span class="muted">Cliente de contado</span><?php else: ?><a href="<?= e(url('/admin/clientes/' . rawurlencode($r['cust_code']))) ?>"><?= e($nm !== '' ? $nm : $r['cust_code']) ?></a><?php endif; ?></td>
      <td><?= e(erp_money((int)$r['base_cents'])) ?></td>
      <td><?= e(erp_money((int)$r['vat_cents'])) ?></td>
      <td><strong><?= e(erp_money((int)$r['total_cents'])) ?></strong></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<nav class="pager" aria-label="Páginas"><span class="muted">Página <?= $page ?> de <?= $pages ?></span>
  <?php if ($pages > 1): ?><span class="pager-l">
    <?php if ($page > 1): ?><a href="<?= e(url($pageUrl($page - 1))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
    <?php if ($page < $pages): ?><a href="<?= e(url($pageUrl($page + 1))) ?>" rel="next">Siguiente ›</a><?php endif; ?>
  </span><?php endif; ?></nav>
<?php endif; ?>
