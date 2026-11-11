<div class="head"><h1>Slider de la portada</h1></div>
<p class="hint">Pase de imágenes grandes en la cabecera de la portada, con efecto y texto opcional. Si no hay ninguna diapositiva visible, la portada muestra el titular normal. Mejor imágenes anchas (por ejemplo 1920 × 800 px) y no demasiado claras, para que se lea el texto.</p>

<form method="post" class="form">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <div class="two">
    <label>Efecto
      <select name="effect">
        <option value="fade"<?= $effect === 'fade' ? ' selected' : '' ?>>Fundido</option>
        <option value="slide"<?= $effect === 'slide' ? ' selected' : '' ?>>Deslizar</option>
        <option value="zoom"<?= $effect === 'zoom' ? ' selected' : '' ?>>Fundido con zoom lento</option>
      </select>
    </label>
    <label>Segundos entre diapositivas <input type="number" name="seconds" min="3" max="15" value="<?= (int)$seconds ?>"></label>
  </div>
  <div class="buttons"><button class="btn small" type="submit">Guardar ajustes</button></div>
</form>

<h2>Añadir diapositiva</h2>
<form method="post" enctype="multipart/form-data" class="form">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <label>Imagen <input type="file" name="image" accept="image/*" required></label>
  <label>Título (opcional) <input name="title" maxlength="120"></label>
  <label>Texto (opcional) <textarea name="text" rows="2" maxlength="300"></textarea></label>
  <div class="two">
    <label>Texto del botón (opcional) <input name="btn" maxlength="40" placeholder="Ver novedades"></label>
    <label>Enlace del botón <input name="link" maxlength="300" placeholder="/catalogo/nombre-de-<?= e(slugify(term('family'))) ?>"></label>
  </div>
  <div class="buttons"><button class="btn" type="submit">Añadir</button></div>
</form>

<h2>Diapositivas (<?= count($slides) ?>)</h2>
<?php if (!$slides): ?><p class="empty">Todavía no hay ninguna.</p><?php endif; ?>
<?php foreach ($slides as $s): ?>
  <form method="post" enctype="multipart/form-data" class="imp" style="<?= $s['visible'] ? '' : 'opacity:.6' ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
    <div style="display:flex;gap:14px;flex-wrap:wrap">
      <img src="<?= e(img_url($s['file'], true)) ?>" alt="" width="160" height="100" style="object-fit:cover;border-radius:8px;width:160px;height:100px">
      <div style="flex:1 1 280px;display:flex;flex-direction:column;gap:8px">
        <input name="title" value="<?= e($s['title']) ?>" placeholder="Título" maxlength="120">
        <textarea name="text" rows="2" placeholder="Texto" maxlength="300"><?= e($s['text']) ?></textarea>
        <div class="two">
          <input name="btn" value="<?= e($s['btn']) ?>" placeholder="Texto del botón" maxlength="40">
          <input name="link" value="<?= e($s['link']) ?>" placeholder="/catalogo/nombre-de-<?= e(slugify(term('family'))) ?>" maxlength="300">
        </div>
        <label class="check"><span>Orden</span> <input type="number" name="sort" value="<?= (int)$s['sort'] ?>" style="width:90px"></label>
        <label class="check"><span>Cambiar imagen</span> <input type="file" name="image" accept="image/*"></label>
      </div>
    </div>
    <div class="buttons">
      <button class="btn small" name="action" value="save" type="submit">Guardar</button>
      <button class="btn small ghost" name="action" value="toggle" type="submit"><?= $s['visible'] ? 'Ocultar' : 'Mostrar' ?></button>
      <button class="btn small danger-btn" name="action" value="delete" type="submit" onclick="return confirm('¿Eliminar esta diapositiva?')">Borrar</button>
    </div>
  </form>
<?php endforeach; ?>
