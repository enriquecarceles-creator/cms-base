<?php
/** Datos → Perfiles → un perfil. Variables: $p (id, name, source_type, target_module, is_system, mapping, rules), $confirmDelete. */
$labels = ['table' => 'Tabla de artículos', 'ref' => 'Referencia', 'name' => 'Nombre', 'family' => 'Familia', 'sub' => 'Subfamilia', 'dims' => 'Medidas', 'desc' => 'Descripción',
    'short' => 'Descripción corta', 'visible' => 'Visible', 'visible_inv' => 'Visible invertido (0 = visible)', 'sort' => 'Orden', 'img' => 'Foto', 'id' => 'Identificador',
    'folders' => 'Fotos por carpeta de artículo', 'caps' => 'Arreglar mayúsculas', 'fam_table' => 'Tabla de familias', 'fam_id' => 'Familias: identificador', 'fam_name' => 'Familias: nombre',
    'img_table' => 'Tabla de fotos'];
$show = function ($v): string {
    if (is_array($v)) {
        return implode(', ', array_map(fn($x) => is_array($x) ? implode(' / ', array_map('strval', $x)) : (string)$x, $v));
    }
    return (string)$v;
};
$fieldsMap = is_array($p['mapping']['fields'] ?? null) ? $p['mapping']['fields'] : [];
$mapRows = array_diff_key($p['mapping'], ['fields' => 1]);
$ruleFields = is_array($p['rules']['fields'] ?? null) ? $p['rules']['fields'] : [];
?>
<div class="head"><h1><?= e($p['name']) ?></h1><a class="btn ghost" href="<?= e(url('/admin/perfiles')) ?>">‹ Todos los perfiles</a></div>
<p class="muted"><?= $p['is_system'] === 1 ? '<span class="tag">Sistema</span> Viene con el programa: no se puede cambiar ni borrar. Si lo necesitas distinto, duplícalo.' : '<span class="tag tag-label">Propio</span>' ?>
  · Origen: <?= e(PROFILE_SOURCE_LABELS[$p['source_type']] ?? $p['source_type']) ?> · Para: <?= e(PROFILE_MODULE_LABELS[$p['target_module']] ?? $p['target_module']) ?></p>

<?php if ($confirmDelete): ?>
  <form method="post" action="<?= e(url('/admin/perfiles')) ?>" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <p class="alert warn"><?= admin_icon('alert') ?><span>Vas a borrar el perfil <strong><?= e($p['name']) ?></strong>. Los artículos ya importados no cambian; solo se pierde el emparejamiento guardado.</span></p>
    <label class="check"><input type="checkbox" name="confirm" value="1"> <span>Sí, borrar este perfil</span></label>
    <div class="buttons"><button class="btn danger-btn" type="submit">Borrar el perfil</button> <a class="btn ghost" href="<?= e(url('/admin/perfiles/' . (int)$p['id'])) ?>">Cancelar</a></div>
  </form>
<?php else: ?>
  <div class="buttons" style="margin-bottom:14px">
    <form method="post" action="<?= e(url('/admin/perfiles')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn ghost" type="submit">Duplicar</button></form>
    <?php if ($p['is_system'] === 0): ?><a class="btn ghost" href="<?= e(url('/admin/perfiles/' . (int)$p['id'] . '?borrar=1')) ?>">Borrar</a><?php endif; ?>
  </div>
  <?php if ($p['is_system'] === 0): ?>
    <form method="post" action="<?= e(url('/admin/perfiles')) ?>" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
      <label>Nombre del perfil <input name="name" value="<?= e($p['name']) ?>" maxlength="120" required></label>
      <div class="buttons"><button class="btn" type="submit">Cambiar el nombre</button></div>
    </form>
  <?php endif; ?>

  <h2>Qué columna es cada cosa</h2>
  <p class="muted" style="margin:0">Solo lectura. Para cambiar el emparejamiento, importa con este perfil, ajusta las columnas y pulsa «Guardar como perfil».</p>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th scope="col">Dato</th><th scope="col">Columna o ajuste</th></tr></thead>
    <tbody>
    <?php foreach ($mapRows as $k => $v): if ($show($v) === '') { continue; } ?>
      <tr><td><?= e($labels[$k] ?? $k) ?></td><td><code><?= e($show($v)) ?></code></td></tr>
    <?php endforeach; ?>
    <?php foreach ($fieldsMap as $slug => $col): $def = $ruleFields[$slug] ?? null; ?>
      <tr><td>Campo «<?= e(is_array($def) ? (string)$def[0] : (string)$slug) ?>»<?= is_array($def) && !(int)($def[3] ?? 1) ? ' <small class="muted">(interno)</small>' : '' ?></td><td><code><?= e($show($col)) ?></code></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>

  <?php if (!empty($p['rules']['require_tables']) || !empty($p['rules']['exclude_columns'])): ?>
    <h2>Reglas</h2>
    <ul>
      <?php if (!empty($p['rules']['require_tables'])): ?><li>Se propone solo si la base de datos tiene las tablas: <code><?= e($show($p['rules']['require_tables'])) ?></code>.</li><?php endif; ?>
      <?php if (!empty($p['rules']['exclude_columns'])): ?><li>Columnas que <strong>nunca se leen</strong>: <code><?= e($show($p['rules']['exclude_columns'])) ?></code>.</li><?php endif; ?>
      <?php if ($ruleFields): ?><li>Crea estos campos del catálogo si no existen: <?= e(implode(', ', array_map(fn($d) => (string)$d[0] . (!(int)($d[3] ?? 1) ? ' (interno)' : ''), $ruleFields))) ?>.</li><?php endif; ?>
    </ul>
  <?php endif; ?>
<?php endif; ?>
