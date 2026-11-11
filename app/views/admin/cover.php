<div class="head"><h1>Portada de «<?= e($cat['name']) ?>»</h1><a href="<?= e(url('/admin/categorias')) ?>">← Volver a Familias</a></div>
<p class="hint">Elige entre las fotos ya subidas la que saldrá en la tarjeta de esta familia (en el inicio y en el catálogo).</p>
<form method="post" enctype="multipart/form-data" class="form" style="margin-bottom:22px">
  <?= csrf_field() ?><input type="hidden" name="what" value="upload">
  <strong>Subir una foto nueva para esta familia</strong>
  <small>Solo cambia la caja de la familia en el catálogo y el inicio. No modifica el slider ni nada más.</small>
  <input type="file" name="photo" accept="image/*" required>
  <div class="buttons"><button class="btn small" type="submit">Subir y usar como foto</button></div>
</form>
<?php if ($cat['banner']): ?>
<form method="post" class="form" style="margin-bottom:22px">
  <?= csrf_field() ?><input type="hidden" name="what" value="upload"><input type="hidden" name="banner_remove" value="1">
  <strong>Imagen ancha bajo el título</strong>
  <img src="<?= e(img_url($cat['banner'])) ?>" alt="" style="max-width:320px;border-radius:8px">
  <div class="buttons"><button class="btn small" type="submit">Quitar esta imagen</button></div>
</form>
<?php endif; ?>
<h2 style="margin-top:0">Foto de la tarjeta</h2>
<?php if (!$photos): ?>
  <p class="empty">Todavía no hay fotos en esta familia.</p>
<?php else: ?>
<form method="post">
  <?= csrf_field() ?>
  <label class="check" style="margin-bottom:12px"><input type="radio" name="file" value=""<?= $cat['cover'] === '' ? ' checked' : '' ?>> Automática (la primera foto de la familia)</label>
  <div class="gal">
    <?php foreach ($photos as $p): ?>
      <label class="gitem">
        <img src="<?= e(img_url($p['file'], true)) ?>" alt="" loading="lazy" width="130" height="130">
        <span class="check"><input type="radio" name="file" value="<?= e($p['file']) ?>"<?= $cat['cover'] === $p['file'] ? ' checked' : '' ?>> <small><?= e(mb_strimwidth($p['name'], 0, 22, '…')) ?></small></span>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="buttons" style="margin-top:14px"><button class="btn" type="submit">Guardar portada</button></div>
</form>
<?php endif; ?>
