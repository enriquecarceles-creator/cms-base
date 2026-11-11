<?php /* Web → Página del catálogo: título, presentación, artículos por página, orden y qué sale en las tarjetas. */ ?>
<div class="head"><h1>Página del catálogo</h1><a href="<?= e(url('/catalogo')) ?>" target="_blank" rel="noopener">Ver el catálogo ↗</a></div>
<p class="hint">Cómo se ve el catálogo en la web. Los nombres de los grupos (<?= e(term('family')) ?> / <?= e(term('sub')) ?>) se cambian en <a href="<?= e(url('/admin/configuracion')) ?>">Configuración</a>.</p>

<form method="post" class="form">
  <?= csrf_field() ?>
  <h2>Presentación</h2>
  <label>Título <input name="catalog_title" value="<?= e($title_v) ?>" maxlength="200" placeholder="<?= e(catalog_title_default()) ?>"><small>Vacío = «<?= e(catalog_title_default()) ?>».</small></label>
  <label>Texto de presentación (opcional) <textarea name="catalog_intro" rows="3" maxlength="2000"><?= e($intro) ?></textarea><small>Sale bajo el título, en la página principal del catálogo.</small></label>

  <h2>Listado</h2>
  <div class="two">
    <label>Artículos por página <input type="number" name="catalog_per_page" min="0" max="200" value="<?= (int)$opts['per_page'] ?>"><small>0 = todos en una sola página (como hasta ahora).</small></label>
    <label>Orden por defecto
      <select name="catalog_order"><?php foreach (CATALOG_ORDERS as $k => $label): if (!$hasPrice && str_starts_with($k, 'price')) continue; ?><option value="<?= e($k) ?>"<?= $opts['order'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      <small>Dentro de cada <?= e(term('family', true)) ?>.<?= $hasPrice ? '' : ' Para ordenar por precio hace falta un campo de precio público (Web → Campos).' ?></small></label>
  </div>

  <h2>Tarjetas de artículo</h2>
  <label class="check"><input type="checkbox" name="catalog_show_ref" value="1"<?= $opts['show_ref'] ? ' checked' : '' ?>> <span>Mostrar la referencia</span></label>
  <label class="check"><input type="checkbox" name="catalog_show_price" value="1"<?= $opts['show_price'] ? ' checked' : '' ?>> <span>Mostrar el precio<?= $hasPrice ? '' : ' (cuando haya un campo de precio público)' ?></span></label>

  <h2>Filtros</h2>
  <?php if (!$cand): ?>
    <p class="muted" style="margin:0">Todavía no hay campos públicos que sirvan como filtro. Se crean en <a href="<?= e(url('/admin/campos')) ?>">Web → Campos</a> (texto, listas, sí/no o precio).</p>
  <?php else: ?>
    <p class="hint" style="margin:0 0 8px">Marca hasta <?= (int)CATALOG_FILTER_MAX ?> campos para que los visitantes puedan filtrar el catálogo. Sin ninguno marcado, el catálogo queda como siempre. Los campos internos nunca se ofrecen.</p>
    <?php foreach ($cand as $fid => $cf): ?>
      <label class="check"><input type="checkbox" name="catalog_filters[]" value="<?= (int)$fid ?>"<?= isset($active[$fid]) ? ' checked' : '' ?>> <span><?= e($cf['name']) ?> <small>(<?= e(FIELD_KINDS[$cf['kind']][0]) ?>)</small></span></label>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="buttons"><button class="btn" type="submit">Guardar</button></div>
</form>
