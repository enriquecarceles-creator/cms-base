<?php
/*
 * Lista de artículos: filtros (GET), acciones en bloque (POST con CSRF), orden por columna y paginación.
 * Las casillas de cada fila pertenecen al formulario «bulk» con el atributo form="bulk"
 * (así no se anidan formularios con el botón Visible/Oculto de cada fila).
 */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$editQs = $back !== '' ? '?back=' . rawurlencode($back) : '';
$base = array_diff_key($filters, ['pag' => 1]);
$sortUrl = function (string $col) use ($base): string {
    $q = $base;
    $desc = ($base['orden'] ?? '') === $col && ($base['dir'] ?? 'asc') === 'asc';
    $q['orden'] = $col;
    unset($q['dir']);
    if ($desc) {
        $q['dir'] = 'desc';
    }
    return admin_list_url($q);
};
$th = function (string $col, string $label, string $class = '') use ($filters, $sortUrl): string {
    $on = ($filters['orden'] ?? '') === $col;
    $arrow = $on ? (($filters['dir'] ?? 'asc') === 'desc' ? ' ↓' : ' ↑') : '';
    $sort = $on ? (($filters['dir'] ?? 'asc') === 'desc' ? 'descending' : 'ascending') : 'none';
    return '<th scope="col" class="' . e($class) . '" aria-sort="' . $sort . '"><a href="' . e(url($sortUrl($col))) . '">' . e($label) . '<span class="arr">' . $arrow . '</span></a></th>';
};
$pageUrl = fn(int $p) => admin_list_url($p > 1 ? $base + ['pag' => $p] : $base);
$from = $total ? ($page - 1) * ADMIN_PER_PAGE + 1 : 0;
$to = min($total, $page * ADMIN_PER_PAGE);
$hasFilters = (bool)array_diff_key($filters, ['orden' => 1, 'dir' => 1, 'pag' => 1]);
$sel = fn(string $k, string $v) => (string)($filters[$k] ?? '') === $v ? ' selected' : '';
?>
<div class="head">
  <h1>Artículos <small>(<?= $n($total) ?>)</small></h1>
  <a class="btn" href="<?= e(url('/admin/articulo/nuevo' . (isset($filters['c']) ? '?c=' . (int)$filters['c'] : ''))) ?>">+ Nuevo artículo</a>
</div>

<form class="filters lf" method="get" action="<?= e(url('/admin/articulos')) ?>">
  <?php foreach (['orden', 'dir'] as $k): if (isset($filters[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)$filters[$k]) ?>"><?php endif; endforeach; ?>
  <label class="lf-q"><span class="sr-only">Buscar</span><input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre o referencia"></label>
  <label><span class="sr-only"><?= e(term('family')) ?></span>
    <select name="c">
      <option value="0"><?= e(tg('Todas las', 'Todos los')) ?> <?= e(term('families', true)) ?></option>
      <?php foreach ($cats as $cat): ?><option value="<?= (int)$cat['id'] ?>"<?= $c === (int)$cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
    </select></label>
  <label><span class="sr-only">Visible</span>
    <select name="visible"><option value="">Visibles y ocultos</option><option value="1"<?= $sel('visible', '1') ?>>Solo visibles</option><option value="0"<?= $sel('visible', '0') ?>>Solo ocultos</option></select></label>
  <label><span class="sr-only">Foto</span>
    <select name="foto"><option value="">Con y sin foto</option><option value="1"<?= $sel('foto', '1') ?>>Con foto</option><option value="0"<?= $sel('foto', '0') ?>>Sin foto</option></select></label>
  <?php if ($pf): ?>
  <label><span class="sr-only">Precio</span>
    <select name="precio"><option value="">Con y sin precio</option><option value="1"<?= $sel('precio', '1') ?>>Con precio</option><option value="0"<?= $sel('precio', '0') ?>>Sin precio</option></select></label>
  <?php endif; ?>
  <button class="btn small" type="submit">Filtrar</button>
  <?php if ($hasFilters): ?><a class="lf-clear" href="<?= e(url('/admin/articulos')) ?>">Quitar filtros</a><?php endif; ?>
</form>

<?php if (!$rows): ?>
  <p class="empty">No hay artículos<?= $hasFilters ? ' con ese filtro' : '' ?>.</p>
<?php else: ?>

<form id="bulk" class="bulk" method="post" action="<?= e(url('/admin/articulos')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk">
  <input type="hidden" name="back" value="<?= e($back) ?>">
  <fieldset class="bulk-scope"><legend class="sr-only">A qué artículos</legend>
    <label class="check"><input type="radio" name="scope" value="sel" checked> <span>Los marcados</span></label>
    <label class="check"><input type="radio" name="scope" value="all"> <span>Todos los <?= $n($total) ?> <?= $hasFilters ? 'del filtro' : 'artículos' ?></span></label>
  </fieldset>
  <label><span class="sr-only">Acción</span>
    <select name="op">
      <option value="">Acción…</option>
      <option value="hide">Ocultar</option>
      <option value="show">Mostrar</option>
      <option value="move">Mover a <?= e(term('family', true)) ?>…</option>
      <option value="delete">Borrar</option>
    </select></label>
  <label><span class="sr-only">Mover a</span>
    <select name="target"><option value="0">(para «Mover a»)</option><?php foreach ($cats as $cat): ?><option value="<?= (int)$cat['id'] ?>"><?= e($cat['name']) ?></option><?php endforeach; ?></select></label>
  <button class="btn small ghost" type="submit">Aplicar</button>
</form>

<div class="tbl-wrap">
<table class="tbl list">
  <thead>
    <tr>
      <th scope="col" class="t-check"><input type="checkbox" class="js-all" aria-label="Marcar todos los de esta página" hidden></th>
      <th scope="col" class="t-thumb"><span class="sr-only">Foto</span></th>
      <?= $th('ref', 'Ref.') ?>
      <?= $th('nombre', 'Nombre', 't-name') ?>
      <?= $th('familia', term('family')) ?>
      <?php if ($pf): ?><?= $th('precio', $pf['name'], 't-num') ?><?php endif; ?>
      <?php if ($sf): ?><?= $th('stock', $sf['name'], 't-num') ?><?php endif; ?>
      <?= $th('visible', 'Visible') ?>
      <?= $th('modificado', 'Modificado') ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r): $edit = url('/admin/articulo/' . $r['id'] . $editQs); ?>
    <tr<?= $r['visible'] ? '' : ' class="off"' ?>>
      <td class="t-check"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="bulk" aria-label="Marcar «<?= e($r['name']) ?>»"></td>
      <td class="t-thumb"><a href="<?= e($edit) ?>" tabindex="-1"><?php if ($r['image']): ?><img src="<?= e(img_url($r['image'], true)) ?>" alt="" loading="lazy" width="40" height="40"><?php else: ?><span class="nophoto">sin foto</span><?php endif; ?></a></td>
      <td class="t-ref"><?= e($r['ref']) ?></td>
      <td class="t-name"><a href="<?= e($edit) ?>"><strong><?= e($r['name']) ?></strong></a></td>
      <td><?= e($r['cat_name']) ?></td>
      <?php if ($pf): ?><td class="t-num"><?= ($r['price'] ?? '') !== '' ? e(field_format($pf, (string)$r['price'])) : '<span class="muted">—</span>' ?></td><?php endif; ?>
      <?php if ($sf): ?><td class="t-num"><?= ($r['stock'] ?? '') !== '' ? e(field_format($sf, (string)$r['stock'])) : '<span class="muted">—</span>' ?></td><?php endif; ?>
      <td>
        <form method="post" action="<?= e(url('/admin/articulos')) ?>" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <button class="pill <?= $r['visible'] ? 'on' : '' ?>" type="submit" title="Mostrar u ocultar en la web"><?= $r['visible'] ? 'Visible' : 'Oculto' ?></button>
        </form>
      </td>
      <td class="t-date"><?= e(date('d/m/Y', (int)strtotime((string)$r['updated_at']))) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<nav class="pager" aria-label="Páginas">
  <span class="muted">Mostrando <?= $n($from) ?>–<?= $n($to) ?> de <?= $n($total) ?></span>
  <?php if ($pages > 1): ?>
    <span class="pager-l">
      <?php if ($page > 1): ?><a href="<?= e(url($pageUrl($page - 1))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
      <?php
        $show = array_unique(array_filter([1, 2, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages - 1, $pages], fn($p) => $p >= 1 && $p <= $pages));
        sort($show);
        $prev = 0;
        foreach ($show as $p):
          if ($p - $prev > 1): ?><span class="gap">…</span><?php endif;
          if ($p === $page): ?><span class="cur" aria-current="page"><?= $p ?></span><?php else: ?><a href="<?= e(url($pageUrl($p))) ?>"><?= $p ?></a><?php endif;
          $prev = $p;
        endforeach;
      ?>
      <?php if ($page < $pages): ?><a href="<?= e(url($pageUrl($page + 1))) ?>" rel="next">Siguiente ›</a><?php endif; ?>
    </span>
  <?php endif; ?>
</nav>

<script>
// Opcional: «marcar todos los de esta página». Sin JavaScript la casilla no aparece y se marcan una a una.
(function () {
  var all = document.querySelector('.js-all');
  if (!all) return;
  all.hidden = false;
  all.addEventListener('change', function () {
    document.querySelectorAll('input[name="ids[]"][form="bulk"]').forEach(function (c) { c.checked = all.checked; });
  });
})();
</script>
<?php endif; ?>
