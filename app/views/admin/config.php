<div class="head"><h1>Configuración</h1></div>
<p class="hint">Qué partes de la web se usan y cómo se comporta. Los datos de la empresa (nombre, logotipo, color, contacto, textos de la portada) están en <a href="<?= e(url('/admin/empresa')) ?>">Empresa</a>.</p>
<form method="post" class="form mods">
  <?= csrf_field() ?>

  <h2>Módulos</h2>
  <fieldset>
    <legend>Partes de la web que se usan</legend>
    <?php foreach (FEATURES as $k => [$name, $help]): ?>
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

  <h2>Textos fijos de la web</h2>
  <?php foreach (CONFIG_TEXTS as $k => [$label, $default, $help]): ?>
    <label><?= e($label) ?> <input name="<?= e($k) ?>" value="<?= e($v[$k]) ?>" placeholder="<?= e($default) ?>" maxlength="200">
      <small><?= e(($help !== '' ? $help . ' ' : '') . 'Vacío = «' . $default . '».') ?></small></label>
  <?php endforeach; ?>

  <div class="buttons"><button class="btn" type="submit">Guardar</button></div>
</form>
