<?php
/** Ventas → Artículos más vendidos: por unidades o por importe, con enlace al catálogo y a sus tallas y colores. */
include __DIR__ . '/sales_head.php';
if ($totals['invoices'] === 0) { return; }
$por = ($f['por'] ?? '') === 'importe' ? 'importe' : 'unidades';
$base = array_diff_key($f, ['pag' => 1]);
$pageUrl = fn(int $p) => erp_sales_url('articulos', $base, $p > 1 ? ['pag' => (string)$p] : []);
$first = ($page - 1) * ERP_PER_PAGE;
?>
<p class="cf-year" style="margin:0 0 10px">Ordenar por:
  <a class="btn small <?= $por === 'unidades' ? '' : 'ghost' ?>" href="<?= e(url(erp_sales_url('articulos', array_diff_key($base, ['por' => 1])))) ?>">Unidades</a>
  <a class="btn small <?= $por === 'importe' ? '' : 'ghost' ?>" href="<?= e(url(erp_sales_url('articulos', $base, ['por' => 'importe']))) ?>">Importe</a>
  <span class="muted"><?= e($n($total)) ?> artículos vendidos · las devoluciones restan</span></p>
<?php if (!$rows): ?><p class="empty">No hay ventas con ese filtro.</p><?php else: ?>
<div class="tbl-wrap"><table class="tbl list">
  <thead><tr><th scope="col">#</th><th scope="col">Artículo</th><th scope="col"><?= e(term('family')) ?></th><th scope="col">Unidades</th><th scope="col">Devueltas</th><th scope="col">Importe</th><th scope="col">Facturas</th><th scope="col"><span class="sr-only">Tallas y colores</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $r): $code = (string)$r['article_code']; $inf = $map[mb_strtolower($code)] ?? null; ?>
    <tr>
      <td class="muted"><?= (int)($first + $i + 1) ?></td>
      <td><?php if ($inf): ?><a href="<?= e(url('/admin/articulo/' . (int)$inf['id'])) ?>"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?> <small class="muted"><?= e($r['title']) ?></small></td>
      <td><small><?= $inf ? e($inf['fam']) : '<span class="muted">fuera del catálogo</span>' ?></small></td>
      <td><strong><?= e(erp_qty((int)$r['qty'])) ?></strong></td>
      <td><?= (int)$r['ret_qty'] !== 0 ? '<span class="down">' . e(erp_qty((int)$r['ret_qty'])) . '</span>' : '—' ?></td>
      <td><?= e(erp_money((int)$r['amount'])) ?></td>
      <td><?= e($n((int)$r['n_inv'])) ?></td>
      <td><a href="<?= e(url(erp_sales_url('tallas', array_intersect_key($f, ['anio' => 1, 'mes' => 1, 'ambito' => 1]), ['art' => $code]))) ?>">Tallas y colores</a></td>
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
