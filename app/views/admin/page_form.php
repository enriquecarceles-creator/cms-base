<?php
/*
 * Crear / editar una página propia. El texto se escribe en Markdown limitado (ver app/cms_pages.php).
 * Sin JavaScript es un cuadro de texto normal con la chuleta del formato debajo; con JavaScript aparece
 * además una barra de botones que inserta la sintaxis.
 */
$review = str_contains((string)$f['body'], PAGE_REVIEW_MARK);
?>
<div class="head">
  <h1><?= $isNew ? 'Nueva página' : 'Editar página' ?></h1>
  <a href="<?= e(url('/admin/paginas')) ?>">← Volver a Páginas</a>
</div>

<?php foreach ($errors as $er): ?><p class="flash err" role="alert"><?= e($er) ?></p><?php endforeach; ?>
<?php if ($lostFiles): ?><p class="flash err" role="alert">No se ha guardado nada, tampoco <?= $lostFiles === 1 ? 'la foto que habías elegido' : 'las ' . (int)$lostFiles . ' fotos que habías elegido' ?>: el navegador no las recuerda. Corrige lo de arriba y <strong>vuelve a elegirlas</strong> antes de guardar.</p><?php endif; ?>
<?php if ($review): ?><p class="alert err"><?= admin_icon('alert') ?><span>Este texto todavía contiene el aviso <strong><?= e(PAGE_REVIEW_MARK) ?></strong>. Revísalo, completa lo que ponga <code>[completar]</code> y <strong>borra el párrafo del aviso antes de publicar</strong>.</span></p><?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form pform">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">

  <label>Título <input name="title" value="<?= e($f['title']) ?>" required maxlength="150" placeholder="Ej.: Aviso legal"></label>
  <label>Dirección en la web
    <span class="slug-row"><span class="muted"><?= e(site_origin()) ?><?= e(url('/')) ?></span><input name="slug" value="<?= e($f['slug']) ?>" maxlength="80" placeholder="se crea del título" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocomplete="off"></span>
    <small>Solo minúsculas, números y guiones. No puede coincidir con una ruta de la web (<?= e(implode(', ', array_slice(PAGE_RESERVED, 0, 7))) ?>…).</small></label>

  <div class="md-field">
    <label for="md-body">Texto</label>
    <div class="md-bar" hidden role="toolbar" aria-label="Formato">
      <button type="button" data-md="h2" title="Título">Título</button>
      <button type="button" data-md="h3" title="Subtítulo">Subtítulo</button>
      <button type="button" data-md="bold" title="Negrita"><strong>N</strong></button>
      <button type="button" data-md="italic" title="Cursiva"><em>C</em></button>
      <button type="button" data-md="ul" title="Lista con viñetas">• Lista</button>
      <button type="button" data-md="ol" title="Lista numerada">1. Lista</button>
      <button type="button" data-md="link" title="Enlace">Enlace</button>
      <button type="button" data-md="image" title="Elegir una foto para subirla">Imagen</button>
    </div>
    <textarea id="md-body" name="body" rows="20" maxlength="<?= PAGE_BODY_MAX ?>" placeholder="Escribe aquí el texto de la página…"><?= e($f['body']) ?></textarea>
    <details class="md-help">
      <summary>Cómo dar formato al texto</summary>
      <p class="muted">Se escribe con unas marcas sencillas. No hace falta saber HTML (y no se admite: si escribes <code>&lt;b&gt;</code> se verá tal cual).</p>
      <table class="tbl md-cheat"><tbody>
        <tr><td><code>## Título</code></td><td>Título de sección (<code>###</code> para un subtítulo)</td></tr>
        <tr><td><code>**negrita**</code> · <code>*cursiva*</code></td><td>Texto <strong>destacado</strong> o <em>inclinado</em></td></tr>
        <tr><td><code>- elemento</code></td><td>Lista con viñetas (un elemento por línea)</td></tr>
        <tr><td><code>1. elemento</code></td><td>Lista numerada</td></tr>
        <tr><td><code>&gt; texto</code></td><td>Cita o aviso destacado</td></tr>
        <tr><td><code>[texto](https://…)</code></td><td>Enlace. También <code>mailto:correo@…</code>, <code>tel:+34…</code> o <code>/catalogo</code> (dentro de esta web)</td></tr>
        <tr><td><code>![descripción](/uploads/…)</code></td><td>Imagen: elige el archivo en «Añadir fotos» y se inserta al final del texto; muévela donde quieras</td></tr>
      </tbody></table>
      <p class="muted">Una línea en blanco separa párrafos.</p>
    </details>
  </div>

  <label>Añadir fotos al texto <input type="file" name="images[]" id="md-images" multiple accept="image/jpeg,image/png,image/webp">
    <small>Se suben al guardar y se añaden al final del texto como <code>![Describe la imagen](…)</code>: cambia la descripción y mueve la línea a donde quieras que salga.</small></label>

  <fieldset>
    <legend>Imagen de cabecera (opcional)</legend>
    <?php if (!empty($f['hero'])): ?><img src="<?= e(img_url((string)$f['hero'])) ?>" alt="" style="max-width:320px;max-height:140px;object-fit:cover;border-radius:8px"><label class="check"><input type="checkbox" name="hero_remove" value="1"> <span>Quitar la imagen actual</span></label><?php endif; ?>
    <input type="file" name="hero" accept="image/*">
    <small>Franja ancha encima del título.</small>
  </fieldset>

  <fieldset>
    <legend>Google (opcional)</legend>
    <label>Título para Google <input name="seo_title" value="<?= e($f['seo_title']) ?>" maxlength="160" placeholder="Vacío = el título de la página"></label>
    <label>Descripción para Google <textarea name="seo_desc" rows="2" maxlength="255" placeholder="Vacío = el principio del texto"><?= e($f['seo_desc']) ?></textarea></label>
  </fieldset>

  <div class="two">
    <label class="check"><input type="checkbox" name="visible" value="1"<?= $f['visible'] ? ' checked' : '' ?>> <span><b>Visible en la web</b> (si no, es un borrador que solo ves tú)</span></label>
    <label>Orden <input type="number" name="sort" value="<?= (int)$f['sort'] ?>" step="1"><small>El más bajo sale primero en el pie.</small></label>
  </div>

  <div class="savebar">
    <button class="btn" type="submit"><?= $isNew ? 'Crear página' : 'Guardar cambios' ?></button>
    <?php if (!$isNew): ?><a class="btn ghost" href="<?= e(url('/' . $f['slug'])) ?>" target="_blank" rel="noopener">Ver la página ↗</a><?php endif; ?>
    <a class="savebar-back" href="<?= e(url('/admin/paginas')) ?>">Volver a Páginas</a>
  </div>
</form>

<?php if (!$isNew): ?>
  <form method="post" action="<?= e(url('/admin/paginas')) ?>" class="danger">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
    <button class="btn danger-btn" type="submit">Borrar página…</button>
  </form>
<?php endif; ?>

<script>
// Opcional: barra de formato. Sin JavaScript el cuadro de texto funciona igual (con la chuleta de arriba).
(function () {
  var ta = document.getElementById('md-body'), bar = document.querySelector('.md-bar');
  if (!ta || !bar) return;
  bar.hidden = false;
  function wrap(before, after, ph) {
    var s = ta.selectionStart, e = ta.selectionEnd, sel = ta.value.slice(s, e) || ph;
    ta.value = ta.value.slice(0, s) + before + sel + after + ta.value.slice(e);
    ta.focus(); ta.setSelectionRange(s + before.length, s + before.length + sel.length);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function lines(prefix, numbered) {
    var s = ta.selectionStart, e = ta.selectionEnd, a = ta.value.lastIndexOf('\n', s - 1) + 1, b = ta.value.indexOf('\n', e); if (b < 0) b = ta.value.length;
    var n = 0, txt = ta.value.slice(a, b).split('\n').map(function (l) { return (numbered ? (++n) + '. ' : prefix) + l.replace(/^(#{1,3}|[-*]|\d+[.)])\s+/, ''); }).join('\n');
    ta.value = ta.value.slice(0, a) + txt + ta.value.slice(b);
    ta.focus(); ta.setSelectionRange(a, a + txt.length);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  bar.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-md]'); if (!b) return;
    var k = b.getAttribute('data-md');
    if (k === 'bold') wrap('**', '**', 'texto');
    else if (k === 'italic') wrap('*', '*', 'texto');
    else if (k === 'h2') lines('## ');
    else if (k === 'h3') lines('### ');
    else if (k === 'ul') lines('- ');
    else if (k === 'ol') lines('', true);
    else if (k === 'link') { var s = ta.selectionStart, sel = ta.value.slice(s, ta.selectionEnd) || 'texto'; wrap('[', '](https://)', sel); var p = ta.value.indexOf('](https://)', s) + 2; ta.setSelectionRange(p + 8, p + 8); }
    else if (k === 'image') document.getElementById('md-images').click();
  });
})();
</script>
