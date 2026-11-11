<div class="head"><h1>Campos del catálogo</h1></div>
<p class="hint">Datos propios de cada artículo además del nombre, la referencia, las medidas y la descripción: precio, tallas, color, material… Aparecen en el formulario de cada artículo y, si son públicos, en su ficha de la web (y el primer precio público, también en el catálogo).</p>

<?php if ($fields): ?>
  <?php foreach ($fields as $f): ?>
    <form method="post" class="form cf-row">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <div class="cf-grid">
        <label>Nombre <input name="name" value="<?= e($f['name']) ?>" required maxlength="80"></label>
        <label>Tipo
          <select name="kind"><?php foreach (FIELD_KINDS as $k => [$kl]): ?><option value="<?= e($k) ?>"<?= $f['kind'] === $k ? ' selected' : '' ?>><?= e($kl) ?></option><?php endforeach; ?></select>
        </label>
        <label>Unidad <input name="unit" value="<?= e($f['unit']) ?>" maxlength="20" placeholder="€, kg, cm…"></label>
        <label>Orden <input type="number" name="sort" value="<?= (int)$f['sort'] ?>"></label>
      </div>
      <?php if (in_array($f['kind'], ['select', 'multi'], true)): ?>
        <label>Opciones <textarea name="choices" rows="2" placeholder="Una por línea o separadas por comas"><?= e($f['choices']) ?></textarea></label>
      <?php else: ?><input type="hidden" name="choices" value="<?= e($f['choices']) ?>"><?php endif; ?>
      <div class="cf-foot">
        <label class="check"><input type="checkbox" name="is_public" value="1"<?= $f['is_public'] ? ' checked' : '' ?>> <span>Se ve en la web</span></label>
        <small class="muted"><?= (int)($counts[$f['id']] ?? 0) ?> artículo(s) con valor</small>
        <span class="grow"></span>
        <button class="btn small" name="action" value="save" type="submit">Guardar</button>
        <button class="btn small ghost" name="action" value="delete" type="submit" formnovalidate
          onclick="return confirm('¿Borrar el campo «<?= e(addslashes($f['name'])) ?>» y sus valores en todos los artículos?')">Borrar</button>
      </div>
    </form>
  <?php endforeach; ?>
<?php else: ?>
  <p class="muted">Todavía no hay campos.</p>
<?php endif; ?>

<?php if ($presets): ?>
  <h2>Añadir uno habitual</h2>
  <form method="post" class="chips">
    <?= csrf_field() ?><input type="hidden" name="action" value="preset">
    <?php foreach ($presets as $slug => [$n, $k, $u, $pub]): ?>
      <button class="btn small ghost" name="preset" value="<?= e($slug) ?>" type="submit" title="<?= e(FIELD_KINDS[$k][0] . ($pub ? '' : ' · interno')) ?>">+ <?= e($n) ?></button>
    <?php endforeach; ?>
  </form>
<?php endif; ?>

<h2>Crear otro campo</h2>
<form method="post" class="form">
  <?= csrf_field() ?><input type="hidden" name="action" value="add">
  <div class="cf-grid">
    <label>Nombre <input name="name" required maxlength="80" placeholder="Ej.: Composición"></label>
    <label>Tipo
      <select name="kind"><?php foreach (FIELD_KINDS as $k => [$kl, $kh]): ?><option value="<?= e($k) ?>" title="<?= e($kh) ?>"><?= e($kl) ?></option><?php endforeach; ?></select>
    </label>
    <label>Unidad <input name="unit" maxlength="20" placeholder="€, kg, cm…"></label>
  </div>
  <label>Opciones (solo para listas) <textarea name="choices" rows="2" placeholder="Ej.: 35, 36, 37, 38, 39, 40"></textarea></label>
  <label class="check"><input type="checkbox" name="is_public" value="1" checked> <span>Se ve en la web (desmárcalo para datos internos: stock, precio de coste…)</span></label>
  <div class="buttons"><button class="btn" type="submit">Crear campo</button></div>
  <small>Tipos: <?= e(implode(' · ', array_map(fn($k) => $k[0] . ': ' . mb_strtolower($k[1]), FIELD_KINDS))) ?></small>
</form>
