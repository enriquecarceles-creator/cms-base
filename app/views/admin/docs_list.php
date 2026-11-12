<?php
/** Ventas → Pedidos / Albaranes: lista con filtros. Variables: $kind, $k, $rows, $f, $sum, $page, $pages, $years, $retail. */
$pageUrl = fn(int $p) => docs_list_url($kind, $f, $p > 1 ? ['pag' => (string)$p] : []);
?>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>
<div class="head">
  <h1><?= e($k['plural']) ?></h1>
  <a class="btn" href="<?= e(url($k['path'] . '/nuevo')) ?>">+ <?= e($k['new']) ?></a>
</div>
<?php if ($kind === 'albaran'): ?><p class="hint">Los albaranes se crean desde un pedido (botón «Servir») o directamente aquí. Facturarlos llegará con las facturas propias.</p><?php endif; ?>

<form class="filters lf" method="get" action="<?= e(url($k['path'])) ?>">
  <label class="lf-q"><span class="sr-only">Buscar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Número, cliente o referencia"></label>
  <label><span class="sr-only">Estado</span><select name="estado"><option value="">Todos los estados</option>
    <?php foreach (DOCS_STATUS[$kind] as $s => $label): ?><option value="<?= e($s) ?>"<?= $f['estado'] === $s ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label><span class="sr-only">Año</span><select name="anio"><option value="">Todos los años</option>
    <?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= $f['anio'] === (string)$y ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select></label>
  <button class="btn small" type="submit">Buscar</button>
  <?php if ($f['q'] !== '' || $f['estado'] !== '' || $f['anio'] !== ''): ?><a class="lf-clear" href="<?= e(url($k['path'])) ?>">Quitar filtros</a><?php endif; ?>
</form>

<?php if (!$rows): ?>
  <p class="empty"><?= $f['q'] !== '' || $f['estado'] !== '' || $f['anio'] !== '' ? 'No hay ' . e(mb_strtolower($k['plural'])) . ' con ese filtro.' : 'Todavía no hay ' . e(mb_strtolower($k['plural'])) . '.' ?></p>
<?php else: ?>
<p class="muted"><?= (int)$sum['n'] ?> <?= e(mb_strtolower((int)$sum['n'] === 1 ? $k['label'] : $k['plural'])) ?> · total <?= e(erp_money((int)$sum['tot'])) ?> <small>(sin anulados)</small></p>
<div class="tbl-wrap"><table class="tbl list">
  <thead><tr><th scope="col">Número</th><th scope="col">Fecha</th><th scope="col">Cliente</th><th scope="col">Referencia</th><th scope="col">Estado</th><th scope="col">Total</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $off = $r['status'] === 'anulado'; ?>
    <tr<?= $off ? ' class="off"' : '' ?>>
      <td><a href="<?= e(url($k['path'] . '/' . (int)$r['id'])) ?>"><?= e($r['doc_code']) ?></a></td>
      <td class="t-date"><?= e(erp_day((int)$r['doc_day'])) ?></td>
      <td><?= $retail !== '' && $r['cust_code'] === $retail ? '<span class="muted">Cliente de contado</span>' : e($r['cust_name'] !== '' ? $r['cust_name'] : $r['cust_code']) ?><?= $r['cust_code'] === '' ? ' <small class="muted">(sin ficha)</small>' : '' ?></td>
      <td><?= e($r['ref_text']) ?></td>
      <td><span class="tag<?= $off ? ' tag-off' : (in_array($r['status'], ['servido', 'facturado'], true) ? ' tag-ok' : '') ?>"><?= e(docs_status_label($r)) ?></span></td>
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
