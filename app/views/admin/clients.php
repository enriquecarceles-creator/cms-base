<?php
/** Clientes: buscador, lista con facturas y total comprado. Datos personales: solo rol ERP y administradores. */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$base = array_diff_key($q, ['pag' => 1]);
$pageUrl = fn(int $p) => erp_clients_url($base, $p > 1 ? ['pag' => (string)$p] : []);
$sortKey = $q['orden'] ?? 'nombre';
$desc = ($q['dir'] ?? '') === 'desc';
$th = function (string $key, string $label) use ($base, $sortKey, $desc) {
    $isCur = $sortKey === $key;
    $nextDir = $isCur && !$desc ? 'desc' : '';
    $href = erp_clients_url(array_diff_key($base, ['orden' => 1, 'dir' => 1]), ['orden' => $key === 'nombre' ? '' : $key, 'dir' => $nextDir]);
    return '<th scope="col"' . ($isCur ? ' aria-sort="' . ($desc ? 'descending' : 'ascending') . '"' : '') . '><a href="' . e(url($href)) . '">' . e($label) . ($isCur ? ($desc ? ' ↓' : ' ↑') : '') . '</a></th>';
};
$hasFilters = (bool)array_diff_key($q, ['orden' => 1, 'dir' => 1, 'pag' => 1]);
?>
<div class="head"><h1>Clientes</h1><span class="buttons"><a class="btn" href="<?= e(url('/admin/clientes/nuevo')) ?>">+ Nuevo cliente</a> <a class="btn ghost" href="<?= e(url('/admin/erp')) ?>">Sincronizar con NCS</a></span></div>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>

<?php if ($totals['customers'] === 0): ?>
  <p class="empty">Todavía no hay clientes. Crea el primero con «+ Nuevo cliente» o ve a <a href="<?= e(url('/admin/erp')) ?>">Sincronizar con NCS</a> para traerlos.</p>
<?php else: ?>
<form class="filters lf" method="get" action="<?= e(url('/admin/clientes')) ?>">
  <label class="lf-q"><span class="sr-only">Buscar</span><input type="search" name="q" value="<?= e($q['q'] ?? '') ?>" placeholder="Buscar por código, nombre o NIF"></label>
  <label><span class="sr-only">Mostrar</span>
    <select name="ver"><option value="">Todos</option><option value="con"<?= ($q['ver'] ?? '') === 'con' ? ' selected' : '' ?>>Con facturas</option><option value="bloq"<?= ($q['ver'] ?? '') === 'bloq' ? ' selected' : '' ?>>Bloqueados o de baja</option></select></label>
  <?php if ($retail !== ''): ?><label class="check"><input type="checkbox" name="contado" value="1"<?= !empty($q['contado']) ? ' checked' : '' ?>> <span>Incluir cliente de contado</span></label><?php endif; ?>
  <?php foreach (['orden', 'dir'] as $k): if (isset($q[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)$q[$k]) ?>"><?php endif; endforeach; ?>
  <button class="btn small" type="submit">Filtrar</button>
  <?php if ($hasFilters): ?><a class="lf-clear" href="<?= e(url('/admin/clientes')) ?>">Quitar filtros</a><?php endif; ?>
</form>
<p class="muted"><?= e($n($total)) ?> cliente<?= $total === 1 ? '' : 's' ?><?= $hidden ? ' · el cliente de contado está oculto' : '' ?></p>

<?php if (!$rows): ?>
  <p class="empty">No hay clientes<?= $hasFilters ? ' con ese filtro' : '' ?>.</p>
<?php else: ?>
<div class="tbl-wrap"><table class="tbl list cl-tbl">
  <thead><tr><?= $th('codigo', 'Código') ?><?= $th('nombre', 'Nombre') ?><th scope="col">NIF</th><th scope="col">Población</th><th scope="col">Teléfono</th><th scope="col">Estado</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $off = (int)$r['blocked'] === 1 || (int)$r['ended_day'] > 0; $cu = url('/admin/clientes/' . rawurlencode($r['cust_code'])); ?>
    <tr<?= $off ? ' class="off"' : '' ?>>
      <td><a href="<?= e($cu) ?>"><?= e($r['cust_code']) ?></a></td>
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

<nav class="pager" aria-label="Páginas">
  <span class="muted">Página <?= $page ?> de <?= $pages ?></span>
  <?php if ($pages > 1): ?>
    <span class="pager-l">
      <?php if ($page > 1): ?><a href="<?= e(url($pageUrl($page - 1))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
      <?php if ($page < $pages): ?><a href="<?= e(url($pageUrl($page + 1))) ?>" rel="next">Siguiente ›</a><?php endif; ?>
    </span>
  <?php endif; ?>
</nav>
<?php endif; endif; ?>
