<?php
/** Cabecera común de Ventas: título, pestañas y filtros (año, mes, ámbito, origen). Variables: $tab, $f, $years, $retail. */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$mesesLargos = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$keep = array_diff_key($f, ['anio' => 1, 'mes' => 1, 'ambito' => 1, 'origen' => 1, 'pag' => 1]); // lo que se conserva al filtrar
$tabKeep = fn(string $t) => array_intersect_key($f, ['anio' => 1, 'mes' => 1, 'ambito' => 1, 'origen' => 1]);
$hasCms = (int)db()->query("SELECT COUNT(*) FROM erp_invoices WHERE origin = 'cms'")->fetchColumn() > 0;
?>
<div class="head"><h1><?= $tab === 'facturas' ? 'Facturas' : 'Informes de ventas' ?></h1>
  <span class="if-grp">
    <?php if ($tab === 'facturas'): ?>
      <a class="btn" href="<?= e(url('/admin/facturas/nuevo?tipo=factura')) ?>">+ Nueva factura</a>
      <a class="btn ghost" href="<?= e(url('/admin/facturas/nuevo?tipo=simplificada')) ?>">+ Nuevo tique</a>
    <?php endif; ?>
    <a class="btn ghost" href="<?= e(url('/admin/erp')) ?>">Sincronizar con NCS</a>
  </span>
</div>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>
<?php if ($tab === 'facturas'): ?><nav class="cf-tabs" aria-label="Facturas">
  <a href="<?= e(url('/admin/ventas')) ?>" aria-current="page">Todas las facturas (NCS y CMS)</a>
  <a href="<?= e(url('/admin/facturas')) ?>">Borradores y facturas del CMS</a>
</nav><?php endif; ?>
<?php if ($tab !== 'facturas'): ?><nav class="cf-tabs" aria-label="Informes">
  <?php foreach (ERP_REPORT_TABS as $k => $label): ?><a href="<?= e(url(erp_sales_url($k, $tabKeep($k)))) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
</nav><?php endif; ?>
<?php if ($totals['invoices'] === 0): ?>
  <p class="empty">Todavía no hay facturas. Ve a <a href="<?= e(url('/admin/erp')) ?>">Sincronizar con NCS</a> para traer las de NCS, o crea una con «Nueva factura».</p>
<?php return; endif; ?>
<form class="filters lf" method="get" action="<?= e(url(erp_sales_url($tab, []))) ?>">
  <label><span class="sr-only">Año</span><select name="anio"><option value="">Todos los años</option><?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= (int)($f['anio'] ?? 0) === $y ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select></label>
  <label><span class="sr-only">Mes</span><select name="mes"><option value="">Todos los meses</option><?php foreach ($mesesLargos as $i => $m): ?><option value="<?= $i + 1 ?>"<?= (int)($f['mes'] ?? 0) === $i + 1 ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></label>
  <?php if ($retail !== ''): ?>
    <label><span class="sr-only">Ámbito</span><select name="ambito"><option value="">Mostrador y clientes</option><option value="mostrador"<?= ($f['ambito'] ?? '') === 'mostrador' ? ' selected' : '' ?>>Solo mostrador</option><option value="clientes"<?= ($f['ambito'] ?? '') === 'clientes' ? ' selected' : '' ?>>Solo clientes</option></select></label>
  <?php endif; ?>
  <?php if ($hasCms || ($f['origen'] ?? '') !== ''): ?>
    <label><span class="sr-only">Origen</span><select name="origen"><option value="">NCS y CMS</option><option value="ncs"<?= ($f['origen'] ?? '') === 'ncs' ? ' selected' : '' ?>>Solo NCS</option><option value="cms"<?= ($f['origen'] ?? '') === 'cms' ? ' selected' : '' ?>>Solo CMS</option></select></label>
  <?php endif; ?>
  <?php foreach ($keep as $k => $val): if ($k === 'orden' || $k === 'dir' || $k === 'q' || $k === 'ver' || $k === 'art' || $k === 'fam' || $k === 'por'): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$val) ?>"><?php endif; endforeach; ?>
  <button class="btn small" type="submit">Filtrar</button>
  <?php if (array_intersect_key($f, ['anio' => 1, 'mes' => 1, 'ambito' => 1, 'origen' => 1])): ?><a class="lf-clear" href="<?= e(url(erp_sales_url($tab, $keep))) ?>">Quitar filtros</a><?php endif; ?>
</form>
