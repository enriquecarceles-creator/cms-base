<div class="head">
  <h1><?= $isNew ? 'Nuevo artículo' : 'Editar artículo' ?></h1>
  <a href="<?= e(url('/admin' . ($f['category_id'] ? '?c=' . (int)$f['category_id'] : ''))) ?>">← Volver a la lista</a>
</div>

<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>

<form method="post" enctype="multipart/form-data" class="form">
  <?= csrf_field() ?>
  <div class="two">
    <label>Familia
      <select name="category_id" required>
        <?php foreach ($cats as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>"<?= (int)$f['category_id'] === (int)$cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Referencia <input name="ref" value="<?= e($f['ref']) ?>" maxlength="40" placeholder="Ej.: 08"></label>
  </div>
  <label>Nombre <input name="name" value="<?= e($f['name']) ?>" required maxlength="120" placeholder="Ej.: Silla plegable"></label>
  <?php if (feature('sketch')): ?><label>Forma del boceto (ficha del artículo)
    <select name="shape"><?php foreach (['' => 'Automática (cilíndrica)', 'cilindrica' => 'Cilíndrica (bote, lata)', 'ranura' => 'Cilíndrica con ranura arriba', 'rectangular' => 'Rectangular (caja)', 'conica' => 'Cónica (más ancha arriba)'] as $k => $t): ?><option value="<?= e($k) ?>"<?= ($f['shape'] ?? '') === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <small>Se dibuja con «alto x ancho» de las medidas.</small>
  </label><?php endif; ?>
  <div class="two">
    <label>Medidas <input name="dimensions" value="<?= e($f['dimensions']) ?>" maxlength="80" placeholder="Ej.: 440 x 215 mm"></label>
    <label>Orden <input type="number" name="sort" value="<?= (int)$f['sort'] ?>" step="1"><small>Menor número = sale antes dentro de su familia.</small></label>
  </div>
  <label>Descripción (opcional)
    <textarea name="description" rows="5" placeholder="Materiales, usos, colores disponibles, cantidades mínimas…"><?= e($f['description']) ?></textarea>
  </label>

  <?php $cfs = fields_all(); $cvals = !empty($f['id']) ? product_field_values((int)$f['id']) : []; ?>
  <?php if ($cfs): ?>
  <fieldset class="cfields">
    <legend>Características</legend>
    <div class="two">
      <?php foreach ($cfs as $cf): ?><?= field_input($cf, $cvals[$cf['id']] ?? '') ?><?php endforeach; ?>
    </div>
    <?php if (is_admin()): ?><small>Los campos se crean y cambian en <a href="<?= e(url('/admin/campos')) ?>">Web → Campos</a>. Los marcados «interno» no se ven en la web.</small><?php endif; ?>
  </fieldset>
  <?php elseif (is_admin()): ?>
  <p class="muted">¿Necesitas precio, tallas, color…? Créalos en <a href="<?= e(url('/admin/campos')) ?>">Web → Campos</a>.</p>
  <?php endif; ?>

  <fieldset>
    <legend>Fotos</legend>
    <?php if ($gallery): ?>
      <div class="gal">
        <?php foreach ($gallery as $i => $g): ?>
          <div class="gitem">
            <img src="<?= e(img_url($g['file'], true)) ?>" alt="Foto <?= $i + 1 ?>" width="120" height="120" loading="lazy">
            <label class="check"><input type="radio" name="cover" value="<?= (int)$g['id'] ?>"<?= $i === 0 ? ' checked' : '' ?>> Portada</label>
            <label class="check"><input type="radio" name="famcover" value="<?= e($g['file']) ?>"> Mostrar en la familia</label>
            <label class="check"><input type="checkbox" name="remove[]" value="<?= (int)$g['id'] ?>"> Quitar</label>
          </div>
        <?php endforeach; ?>
      </div>
      <small>La portada es la foto que sale en el catálogo. «Mostrar en la familia» pone esa foto como la de su subfamilia en la página de la familia (cuando la familia tiene subfamilias). Marca «Quitar» en las que sobren y pulsa Guardar.</small>
    <?php endif; ?>
    <label><?= $gallery ? 'Añadir más fotos' : 'Subir fotos' ?>
      <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp">
    </label>
    <small>Puedes elegir varias a la vez. JPG, PNG o WebP, hasta 8 MB cada una. Se ajustan y comprimen solas para que la web cargue rápido. Con fondo transparente (PNG) se ven mejor.</small>
  </fieldset>

  <label class="check"><input type="checkbox" name="visible" value="1"<?= $f['visible'] ? ' checked' : '' ?>> Visible en la web</label>

  <div class="buttons">
    <button class="btn" type="submit"><?= $isNew ? 'Crear artículo' : 'Guardar cambios' ?></button>
    <?php if (!$isNew): ?>
      <a class="btn ghost" href="<?= e(url('/producto/' . $p['slug'])) ?>" target="_blank" rel="noopener">Ver en la web ↗</a>
    <?php endif; ?>
  </div>
</form>

<?php if (!$isNew): ?>
  <form method="post" class="danger" onsubmit="return confirm('¿Eliminar «<?= e(addslashes($p['name'])) ?>» definitivamente? No se puede deshacer.')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button class="btn danger-btn" type="submit">Eliminar artículo</button>
  </form>
<?php endif; ?>
