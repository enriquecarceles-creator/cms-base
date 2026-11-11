<?php
/** Ventas → Tallas y colores más vendidos, por artículo o por familia (unidades netas, con % y barra). */
include __DIR__ . '/sales_head.php';
if ($totals['invoices'] === 0) { return; }
$art = (string)($f['art'] ?? '');
$fam = (int)($f['fam'] ?? 0);
$keepP = array_intersect_key($f, ['anio' => 1, 'mes' => 1, 'ambito' => 1]);
$totSizes = array_sum($sizes);
$totColors = array_sum($colors);
$maxS = max(1, ...array_values(array_map('abs', $sizes ?: [0])));
$maxC = max(1, ...array_values(array_map('abs', $colors ?: [0])));
?>
<form class="filters lf" method="get" action="<?= e(url('/admin/informes/tallas')) ?>">
  <?php foreach ($keepP as $k => $val): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$val) ?>"><?php endforeach; ?>
  <label><span class="sr-only"><?= e(term('family')) ?></span><select name="fam"><option value="">Todas las <?= e(term('families')) ?></option>
    <?php foreach ($fams as $fa): ?><option value="<?= (int)$fa['id'] ?>"<?= $fam === (int)$fa['id'] ? ' selected' : '' ?>><?= e($fa['name']) ?></option><?php endforeach; ?></select></label>
  <label class="lf-q"><span class="sr-only">Artículo</span><input type="search" name="art" value="<?= e($art) ?>" placeholder="o el código de un artículo"></label>
  <button class="btn small" type="submit">Ver</button>
  <?php if ($art !== '' || $fam > 0): ?><a class="lf-clear" href="<?= e(url(erp_sales_url('tallas', $keepP))) ?>">Ver todo</a><?php endif; ?>
</form>
<p class="muted" style="margin-top:0">
  <?php if ($art !== ''): ?>Artículo <strong><?= e($art) ?></strong><?= $artTitle !== '' ? ' · ' . e($artTitle) : '' ?>
  <?php elseif ($fam > 0): foreach ($fams as $fa) { if ((int)$fa['id'] === $fam) { echo e(term('family', true)) . ' <strong>' . e($fa['name']) . '</strong>'; } } ?>
  <?php else: ?>Todo el catálogo vendido<?php endif; ?>
  · <?= e(erp_qty((int)$units)) ?> unidades netas (las devoluciones restan)<?= (int)$noSize !== 0 ? ' · ' . e(erp_qty((int)$noSize)) . ' sin talla' : '' ?>.
</p>
<?php if (!$sizes && !$colors): ?><p class="empty">No hay ventas con ese filtro.</p><?php else: ?>
<div class="cf-two">
  <section class="cf-card">
    <h2>Tallas</h2>
    <?php if (!$sizes): ?><p class="empty">Sin tallas.</p><?php else: ?>
      <ul class="rank"><?php foreach ($sizes as $label => $q): ?>
        <li><span class="rk-l"><?= e((string)$label) ?></span><span class="rk-b" aria-hidden="true"><span style="width:<?= round(100 * max(0, $q) / $maxS, 1) ?>%"></span></span><span class="rk-v"><?= e(erp_qty((int)$q)) ?> <small class="muted"><?= $totSizes > 0 ? e(number_format(100 * $q / $totSizes, 1, ',', '.')) . ' %' : '' ?></small></span></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
  <section class="cf-card">
    <h2>Colores <small class="muted">(los 30 más vendidos)</small></h2>
    <?php if (!$colors): ?><p class="empty">Sin colores.</p><?php else: ?>
      <ul class="rank"><?php foreach ($colors as $label => $q): ?>
        <li><span class="rk-l"><?= e((string)$label) ?></span><span class="rk-b" aria-hidden="true"><span style="width:<?= round(100 * max(0, $q) / $maxC, 1) ?>%"></span></span><span class="rk-v"><?= e(erp_qty((int)$q)) ?> <small class="muted"><?= $totColors > 0 ? e(number_format(100 * $q / $totColors, 1, ',', '.')) . ' %' : '' ?></small></span></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>
<p class="muted">Las tallas y colores salen con su texto real si se sincronizó con FALMACEN.MDB; si no, como «nº».</p>
<?php endif; ?>
