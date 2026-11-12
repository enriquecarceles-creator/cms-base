<?php
/** Proveedores: buscador y lista. Datos personales y económicos: administradores y rol Compras, con la doble verificación de Compras. */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$qs = fn(array $x) => '/admin/proveedores' . ($x ? '?' . http_build_query($x) : '');
$base = array_filter(['q' => $q, 'ver' => $ver], 'strlen');
?>
<div class="head"><h1>Proveedores</h1><span class="buttons"><a class="btn" href="<?= e(url('/admin/proveedores/nuevo')) ?>">+ Nuevo proveedor</a></span></div>
<?php if (!purch_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Compras está desactivada</span></p><?php endif; ?>

<?php if ($all === 0): ?>
  <p class="empty">Todavía no hay proveedores. Crea el primero con «+ Nuevo proveedor».</p>
<?php else: ?>
<form class="filters lf" method="get" action="<?= e(url('/admin/proveedores')) ?>">
  <label class="lf-q"><span class="sr-only">Buscar</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por código, nombre, NIF o población"></label>
  <label><span class="sr-only">Mostrar</span><select name="ver"><option value="">Todos</option><option value="bloq"<?= $ver === 'bloq' ? ' selected' : '' ?>>Bloqueados o de baja</option></select></label>
  <button class="btn small" type="submit">Filtrar</button>
  <?php if ($base): ?><a class="lf-clear" href="<?= e(url('/admin/proveedores')) ?>">Quitar filtros</a><?php endif; ?>
</form>
<p class="muted"><?= e($n($total)) ?> proveedor<?= $total === 1 ? '' : 'es' ?></p>
<?php if (!$rows): ?>
  <p class="empty">No hay proveedores con ese filtro.</p>
<?php else: ?>
<div class="tbl-wrap"><table class="tbl list cl-tbl">
  <thead><tr><th scope="col">Código</th><th scope="col">Nombre</th><th scope="col">NIF</th><th scope="col">Población</th><th scope="col">Teléfono</th><th scope="col">Estado</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $off = (int)$r['blocked'] === 1 || (int)$r['ended_day'] > 0; $cu = url('/admin/proveedores/' . rawurlencode($r['sup_code'])); ?>
    <tr<?= $off ? ' class="off"' : '' ?>>
      <td><a href="<?= e($cu) ?>"><?= e($r['sup_code']) ?></a></td>
      <td><strong><a href="<?= e($cu) ?>"><?= e($r['name'] !== '' ? $r['name'] : '(sin nombre)') ?></a></strong><?= $r['trade_name'] !== '' && $r['trade_name'] !== $r['name'] ? ' <small class="muted">' . e($r['trade_name']) . '</small>' : '' ?></td>
      <td><?= e($r['nif']) ?></td>
      <td><?= e($r['city']) ?></td>
      <td><?= e($r['phone']) ?></td>
      <td><?php if ((int)$r['ended_day'] > 0): ?><span class="tag tag-off">De baja</span><?php elseif ((int)$r['blocked'] === 1): ?><span class="tag tag-off">Bloqueado</span><?php else: ?><span class="tag tag-ok">Activo</span><?php endif; ?></td>
      <td class="cl-act"><a class="btn small ghost" href="<?= e($cu) ?>">Ver</a> <a class="btn small ghost" href="<?= e($cu . '?editar=1') ?>">Modificar</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($pages > 1): ?>
<nav class="pager" aria-label="Páginas"><span class="muted">Página <?= (int)$page ?> de <?= (int)$pages ?></span>
  <span class="pager-l">
    <?php if ($page > 1): ?><a href="<?= e(url($qs(array_merge($base, ['pag' => $page - 1])))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
    <?php if ($page < $pages): ?><a href="<?= e(url($qs(array_merge($base, ['pag' => $page + 1])))) ?>" rel="next">Siguiente ›</a><?php endif; ?>
  </span></nav>
<?php endif; ?>
<?php endif; endif; ?>
