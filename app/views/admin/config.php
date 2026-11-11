<div class="head"><h1>Configuración</h1></div>
<p class="hint">Qué partes de la web se usan y cómo se comporta. Los datos de la empresa (nombre, logotipo, color, contacto, textos de la portada) están en <a href="<?= e(url('/admin/empresa')) ?>">Empresa</a>.</p>
<form method="post" class="form mods">
  <?= csrf_field() ?>

  <h2>Módulos</h2>
  <fieldset>
    <legend>Partes de la web que se usan</legend>
    <?php foreach (features_help() as $k => [$name, $help]): ?>
      <label class="check"><input type="checkbox" name="mods[]" value="<?= e($k) ?>"<?= in_array($k, $mods, true) ? ' checked' : '' ?>> <span><b><?= e($name) ?></b> — <?= e($help) ?></span></label>
    <?php endforeach; ?>
    <small>Desactivar un módulo solo lo oculta: no se borra nada y se puede volver a activar.</small>
  </fieldset>

  <h2>País y buscadores</h2>
  <div class="two">
    <label>Prefijo telefónico <input name="phone_prefix" value="<?= e($prefix) ?>" inputmode="numeric" maxlength="4" placeholder="34">
      <small>Se añade a los teléfonos para que se pueda llamar desde el móvil (si el número no empieza ya por +). Vacío = sin prefijo.</small></label>
    <label>Zona horaria
      <select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option<?= $z === $tz ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select>
    </label>
  </div>
  <label class="check"><input type="checkbox" name="noindex" value="1"<?= $noindex ? ' checked' : '' ?>> <span><b>Ocultar la web a Google</b> — para webs en pruebas. Desactívalo cuando la publiques.</span></label>

  <h2>Nombres del catálogo</h2>
  <p class="muted" style="margin:0">Cómo se llaman los grupos del catálogo en toda la web y en el panel (por ejemplo Categoría / Subcategoría, Línea / Serie…). Las direcciones de las páginas no cambian. Vacío = el nombre de siempre.</p>
  <div class="two">
    <?php foreach (['family' => 'Primer nivel, singular', 'families' => 'Primer nivel, plural', 'sub' => 'Segundo nivel, singular', 'subs' => 'Segundo nivel, plural'] as $k => $label): ?>
      <label><?= e($label) ?> <input name="term_<?= e($k) ?>" value="<?= e($v['term_' . $k]) ?>" placeholder="<?= e(TERM_DEFAULTS[$k]) ?>" maxlength="40"></label>
    <?php endforeach; ?>
  </div>
  <label>Género de esos nombres
    <select name="term_gender"><option value="f"<?= $v['term_gender'] === 'f' ? ' selected' : '' ?>>Femenino (la familia, todas las familias)</option><option value="m"<?= $v['term_gender'] === 'm' ? ' selected' : '' ?>>Masculino (el grupo, todos los grupos)</option></select>
    <small>Para que frases como «Todas las…» o «… creada» concuerden.</small></label>

  <h2>Textos fijos de la web</h2>
  <?php foreach (CONFIG_TEXTS as $k => [$label, $default, $help]): $default = config_text_default($k); ?>
    <label><?= e($label) ?> <input name="<?= e($k) ?>" value="<?= e($v[$k]) ?>" placeholder="<?= e($default) ?>" maxlength="200">
      <small><?= e(($help !== '' ? $help . ' ' : '') . 'Vacío = «' . $default . '».') ?></small></label>
  <?php endforeach; ?>

  <div class="buttons"><button class="btn" type="submit">Guardar</button></div>
</form>
