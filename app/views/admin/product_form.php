<?php
/*
 * Formulario de artículo por secciones: General, Características y Fotos.
 * - Sin JavaScript: las tres secciones se ven seguidas; el índice de arriba son enlaces a cada una.
 * - Con JavaScript: el índice funciona como pestañas, y avisa si sales con cambios sin guardar.
 * - La barra de guardar va al final del formulario (orden lógico con el tabulador) y se queda fija abajo (sticky).
 * Los nombres de los campos son los de siempre: el guardado no cambia.
 */
$cfs = fields_all();
$cvals = !empty($f['id']) ? product_field_values((int)$f['id']) : [];
$listUrl = url(admin_list_url($back ?: ($f['category_id'] ? ['c' => (int)$f['category_id']] : [])));
$open = $errors ? 'general' : ''; // los errores del servidor son de General (nombre, familia)
$nPhotos = count($gallery);
?>
<div class="head">
  <h1><?= $isNew ? 'Nuevo artículo' : 'Editar artículo' ?></h1>
  <a href="<?= e($listUrl) ?>">← Volver a la lista</a>
</div>

<?php foreach ($errors as $er): ?><p class="flash err" role="alert"><?= e($er) ?></p><?php endforeach; ?>
<?php if ($lostFiles): ?>
  <p class="flash err" role="alert">No se ha guardado nada, tampoco <?= $lostFiles === 1 ? 'la foto que habías elegido' : 'las ' . (int)$lostFiles . ' fotos que habías elegido' ?>: el navegador no las recuerda. Corrige lo de arriba y <strong>vuelve a elegir <?= $lostFiles === 1 ? 'la foto' : 'las fotos' ?></strong> en «Fotos» antes de guardar.</p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form pform" id="pform" data-open="<?= e($open) ?>">
  <?= csrf_field() ?>
  <?php if ($back): ?><input type="hidden" name="back" value="<?= e(http_build_query($back)) ?>"><?php endif; ?>

  <nav class="ptabs" aria-label="Secciones del artículo">
    <a href="#general">General</a>
    <a href="#caracteristicas">Características<?= $cfs ? ' <span class="muted">(' . count($cfs) . ')</span>' : '' ?></a>
    <a href="#fotos">Fotos <span class="muted">(<?= $nPhotos ?>)</span></a>
  </nav>

  <section class="psec" id="general" aria-labelledby="h-general">
    <h2 id="h-general">General</h2>
    <label>Nombre <input name="name" value="<?= e($f['name']) ?>" required maxlength="120" placeholder="Ej.: Silla plegable"></label>
    <div class="two">
      <label>Referencia <input name="ref" value="<?= e($f['ref']) ?>" maxlength="40" placeholder="Ej.: 08"></label>
      <label><?= e(term('family')) . "\n" ?>
        <select name="category_id" required>
          <?php foreach ($cats as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>"<?= (int)$f['category_id'] === (int)$cat['id'] ? ' selected' : '' ?>><?= e($cat['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <div class="two">
      <label>Medidas <input name="dimensions" value="<?= e($f['dimensions']) ?>" maxlength="80" placeholder="Ej.: 440 x 215 mm"></label>
      <label>Orden <input type="number" name="sort" value="<?= (int)$f['sort'] ?>" step="1"><small>Menor número = sale antes dentro de su <?= e(term('family', true)) ?>.</small></label>
    </div>
    <?php if (feature('sketch')): ?><label>Forma del boceto (ficha del artículo)
      <select name="shape"><?php foreach (['' => 'Automática (cilíndrica)', 'cilindrica' => 'Cilíndrica (bote, lata)', 'ranura' => 'Cilíndrica con ranura arriba', 'rectangular' => 'Rectangular (caja)', 'conica' => 'Cónica (más ancha arriba)'] as $k => $t): ?><option value="<?= e($k) ?>"<?= ($f['shape'] ?? '') === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
      <small>Se dibuja con «alto x ancho» de las medidas.</small>
    </label><?php endif; ?>
    <label>Descripción (opcional)
      <textarea name="description" rows="6" placeholder="Materiales, usos, colores disponibles, cantidades mínimas…"><?= e($f['description']) ?></textarea>
    </label>
    <label class="check"><input type="checkbox" name="visible" value="1"<?= $f['visible'] ? ' checked' : '' ?>> <span>Visible en la web</span></label>
  </section>

  <section class="psec" id="caracteristicas" aria-labelledby="h-caract">
    <h2 id="h-caract">Características</h2>
    <?php if ($cfs): ?>
      <div class="two cfields">
        <?php foreach ($cfs as $cf): ?><?= field_input($cf, $cvals[$cf['id']] ?? '') ?><?php endforeach; ?>
      </div>
      <?php if (is_admin()): ?><small class="muted">Los campos se crean y cambian en <a href="<?= e(url('/admin/campos')) ?>">Web → Campos</a>. Los marcados «interno» no se ven en la web.</small><?php endif; ?>
    <?php elseif (is_admin()): ?>
      <p class="muted">Todavía no hay campos. ¿Necesitas precio, tallas, color…? Créalos en <a href="<?= e(url('/admin/campos')) ?>">Web → Campos</a>.</p>
    <?php else: ?>
      <p class="muted">Todavía no hay campos.</p>
    <?php endif; ?>
  </section>

  <section class="psec" id="fotos" aria-labelledby="h-fotos">
    <h2 id="h-fotos">Fotos</h2>
    <?php if ($lostFiles): ?><p class="flash err">Vuelve a elegir <?= $lostFiles === 1 ? 'la foto' : 'las ' . (int)$lostFiles . ' fotos' ?> que habías elegido: el navegador no las recuerda tras un error.</p><?php endif; ?>
    <?php if ($gallery): ?>
      <div class="gal">
        <?php foreach ($gallery as $i => $g): ?>
          <div class="gitem">
            <img src="<?= e(img_url($g['file'], true)) ?>" alt="Foto <?= $i + 1 ?>" width="120" height="120" loading="lazy">
            <label class="check"><input type="radio" name="cover" value="<?= (int)$g['id'] ?>"<?= $i === 0 ? ' checked' : '' ?>> Portada</label>
            <label class="check"><input type="radio" name="famcover" value="<?= e($g['file']) ?>"> Mostrar en <?= e(tg('la', 'el')) ?> <?= e(term('family', true)) ?></label>
            <label class="check"><input type="checkbox" name="remove[]" value="<?= (int)$g['id'] ?>"> Quitar</label>
          </div>
        <?php endforeach; ?>
      </div>
      <small class="muted">La portada es la foto que sale en el catálogo. «Mostrar en <?= e(tg('la', 'el')) ?> <?= e(term('family', true)) ?>» pone esa foto como la de su <?= e(term('sub', true)) ?> en la página <?= e(tg('de la', 'del')) ?> <?= e(term('family', true)) ?> (cuando <?= e(tg('la', 'el')) ?> <?= e(term('family', true)) ?> tiene <?= e(term('subs', true)) ?>). Marca «Quitar» en las que sobren y pulsa Guardar.</small>
    <?php endif; ?>
    <label><?= $gallery ? 'Añadir más fotos' : 'Subir fotos' ?>
      <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/webp">
    </label>
    <small class="muted">Puedes elegir varias a la vez. JPG, PNG o WebP, hasta 8 MB cada una. Se ajustan y comprimen solas para que la web cargue rápido. Con fondo transparente (PNG) se ven mejor.</small>
  </section>

  <div class="savebar">
    <button class="btn" type="submit"><?= $isNew ? 'Crear artículo' : 'Guardar cambios' ?></button>
    <?php if (!$isNew): ?><a class="btn ghost" href="<?= e(url('/producto/' . $p['slug'])) ?>" target="_blank" rel="noopener">Ver en la web ↗</a><?php endif; ?>
    <a class="savebar-back" href="<?= e($listUrl) ?>">Volver a la lista</a>
    <span class="savebar-state" aria-live="polite"></span>
  </div>
</form>

<?php if (!$isNew): ?>
  <form method="post" class="danger" onsubmit="return confirm('¿Eliminar «<?= e(addslashes($p['name'])) ?>» definitivamente? No se puede deshacer.')">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <?php if ($back): ?><input type="hidden" name="back" value="<?= e(http_build_query($back)) ?>"><?php endif; ?>
    <button class="btn danger-btn" type="submit">Eliminar artículo</button>
  </form>
<?php endif; ?>

<script>
// Opcional: pestañas y aviso de cambios sin guardar. Sin JavaScript el formulario funciona igual.
(function () {
  var f = document.getElementById('pform');
  if (!f || !window.FormData) return;
  var secs = Array.prototype.slice.call(f.querySelectorAll('.psec'));
  var tabs = Array.prototype.slice.call(f.querySelectorAll('.ptabs a'));
  function show(id) {
    secs.forEach(function (s) { s.hidden = s.id !== id; });
    tabs.forEach(function (t) { t.setAttribute('aria-current', t.hash === '#' + id ? 'true' : 'false'); });
  }
  f.classList.add('tabbed');
  tabs.forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.preventDefault();
      show(t.hash.slice(1));
      if (history.replaceState) history.replaceState(null, '', t.hash);
    });
  });
  var start = f.getAttribute('data-open') || (location.hash && document.getElementById(location.hash.slice(1)) && location.hash.slice(1)) || secs[0].id;
  show(secs.some(function (s) { return s.id === start; }) ? start : secs[0].id);
  // Enlaces a #general, #caracteristicas o #fotos dentro de la misma página
  window.addEventListener('hashchange', function () {
    var id = location.hash.slice(1);
    if (secs.some(function (s) { return s.id === id; })) show(id);
  });
  // Si un campo obligatorio está en una pestaña oculta, se abre esa pestaña para poder corregirlo
  f.addEventListener('invalid', function (e) {
    var s = e.target.closest('.psec');
    if (s && s.hidden) show(s.id);
  }, true);

  // Que la barra fija nunca tape el campo que recibe el foco (además del margen del CSS)
  var bar = f.querySelector('.savebar');
  f.addEventListener('focusin', function (e) {
    if (!bar || bar.contains(e.target)) return;
    var r = e.target.getBoundingClientRect(), top = bar.getBoundingClientRect().top;
    if (r.bottom > top - 8) window.scrollBy(0, r.bottom - top + 16);
  });

  // Cambios sin guardar: se compara el estado del formulario con el del principio
  function state() {
    var out = [];
    new FormData(f).forEach(function (v, k) {
      out.push(k + '=' + (typeof v === 'string' ? v : (v && v.name ? 'archivo:' + v.name : '')));
    });
    return out.join('&');
  }
  var initial = state(), sending = false, label = f.querySelector('.savebar-state');
  function dirty() {
    var d = state() !== initial;
    f.classList.toggle('dirty', d);
    if (label) label.textContent = d ? 'Cambios sin guardar' : '';
    return d;
  }
  f.addEventListener('input', dirty);
  f.addEventListener('change', dirty);
  // Guardar (o eliminar, si se confirma) no debe avisar; si el envío se cancela, se sigue vigilando
  document.addEventListener('submit', function (e) { if (!e.defaultPrevented) sending = true; });
  window.addEventListener('beforeunload', function (e) {
    if (!sending && dirty()) { e.preventDefault(); e.returnValue = ''; }
  });
})();
</script>
