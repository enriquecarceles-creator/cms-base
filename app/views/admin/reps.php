<div class="head"><h1>Representantes</h1></div>
<p class="hint">Cada línea es una zona. Si varias zonas comparten representante, la web las agrupa sola en una única ficha (mismo nombre, dirección y teléfono).</p>

<div class="table">
  <?php foreach ($rows as $r): ?>
    <form method="post" class="rep-edit">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <label>Zona <input name="zone" value="<?= e($r['zone']) ?>" required maxlength="60"></label>
      <label>Nombre <input name="name" value="<?= e($r['name']) ?>" required maxlength="100"></label>
      <label>Teléfono <input name="phone" value="<?= e($r['phone']) ?>" maxlength="40"></label>
      <label class="wide">Dirección <textarea name="address" rows="2" maxlength="250"><?= e($r['address']) ?></textarea></label>
      <div class="buttons">
        <button class="btn small" name="action" value="save" type="submit">Guardar</button>
        <button class="btn small danger-btn" name="action" value="delete" type="submit" onclick="return confirm('¿Eliminar a este representante de la zona <?= e(addslashes($r['zone'])) ?>?')">Borrar</button>
      </div>
    </form>
  <?php endforeach; ?>
</div>

<h2>Añadir zona / representante</h2>
<form method="post" class="rep-edit">
  <?= csrf_field() ?>
  <label>Zona <input name="zone" required maxlength="60"></label>
  <label>Nombre <input name="name" required maxlength="100"></label>
  <label>Teléfono <input name="phone" maxlength="40"></label>
  <label class="wide">Dirección <textarea name="address" rows="2" maxlength="250"></textarea></label>
  <div class="buttons"><button class="btn small" name="action" value="add" type="submit">Añadir</button></div>
</form>
