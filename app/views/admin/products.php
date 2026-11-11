<?php
$editQs = $back !== '' ? '?back=' . rawurlencode($back) : '';
$labels = ['visible' => ['0' => 'ocultos', '1' => 'visibles'], 'foto' => ['0' => 'sin foto', '1' => 'con foto'], 'precio' => ['0' => 'sin precio', '1' => 'con precio']];
$active = [];
foreach ($labels as $k => $l) {
    if (isset($filters[$k])) {
        $active[] = $l[$filters[$k]];
    }
}
?>
<div class="head">
  <h1>Artículos <small>(<?= count($rows) ?>)</small></h1>
  <a class="btn" href="<?= e(url('/admin/articulo/nuevo' . ($c ? '?c=' . $c : ''))) ?>">+ Nuevo artículo</a>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/articulos')) ?>">
  <?php foreach (['visible', 'foto', 'precio'] as $k): if (isset($filters[$k])): ?><input type="hidden" name="<?= $k ?>" value="<?= e($filters[$k]) ?>"><?php endif; endforeach; ?>
  <select name="c" onchange="this.form.submit()" aria-label="<?= e(term('family')) ?>">
    <option value="0"><?= e(tg('Todas las', 'Todos los')) ?> <?= e(term('families', true)) ?></option>
    <?php foreach ($cats as $cat): ?>
      <option value="<?= (int)$cat['id'] ?>"<?= $c === (int)$cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar nombre o referencia" aria-label="Buscar">
  <button class="btn small" type="submit">Filtrar</button>
</form>
<?php if ($active): ?><p class="muted">Mostrando solo: <b><?= e(implode(' · ', $active)) ?></b> · <a href="<?= e(url(admin_list_url(array_diff_key($filters, $labels)))) ?>">quitar este filtro</a></p><?php endif; ?>

<?php if (!$rows): ?>
  <p class="empty">No hay artículos con ese filtro.</p>
<?php else: ?>
<div class="table">
  <?php foreach ($rows as $r): ?>
    <div class="row<?= $r['visible'] ? '' : ' off' ?>">
      <a class="thumb" href="<?= e(url('/admin/articulo/' . $r['id'] . $editQs)) ?>">
        <?php if ($r['image']): ?><img src="<?= e(img_url($r['image'], true)) ?>" alt="" loading="lazy" width="56" height="56"><?php else: ?><span class="nophoto">sin foto</span><?php endif; ?>
      </a>
      <div class="main">
        <a href="<?= e(url('/admin/articulo/' . $r['id'] . $editQs)) ?>"><strong><?= e($r['name']) ?></strong></a>
        <small><?= e($r['cat_name']) ?><?= $r['ref'] !== '' ? ' · Ref. ' . e($r['ref']) : '' ?><?= $r['dimensions'] !== '' ? ' · ' . e($r['dimensions']) : '' ?></small>
      </div>
      <div class="actions">
        <form method="post" class="inline">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <button class="pill <?= $r['visible'] ? 'on' : '' ?>" type="submit" title="Mostrar u ocultar en la web"><?= $r['visible'] ? 'Visible' : 'Oculto' ?></button>
        </form>
        <a class="btn small ghost" href="<?= e(url('/admin/articulo/' . $r['id'] . $editQs)) ?>">Editar</a>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
