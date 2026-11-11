<?php
/** Configuración → General (pantalla compacta). Variables: $v, $mods, $prefix, $tz, $noindex. */
?>
<div class="head"><h1>General</h1></div>
<p class="hint">Qué partes de la web se usan y cómo se comporta. Los datos de la empresa están en <a href="<?= e(url('/admin/configuracion/empresa')) ?>">Empresa</a>, el envío de correo en <a href="<?= e(url('/admin/configuracion/correo')) ?>">Correo</a> y la doble verificación de Clientes y Ventas en <a href="<?= e(url('/admin/configuracion/usuarios#erp')) ?>">Usuarios y seguridad</a>.</p>
<form method="post" class="cfgf">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Módulos (partes de la web que se usan)</legend>
    <div class="checks-grid">
      <?php foreach (features_help() as $k => [$name, $help]): ?>
        <label class="check"><input type="checkbox" name="mods[]" value="<?= e($k) ?>"<?= in_array($k, $mods, true) ? ' checked' : '' ?>> <span><b><?= e($name) ?></b> — <?= e($help) ?></span></label>
      <?php endforeach; ?>
    </div>
    <small>Desactivar un módulo solo lo oculta: no se borra nada y se puede volver a activar.</small>
  </fieldset>

  <fieldset>
    <legend>País y buscadores</legend>
    <div class="g3">
      <label>Prefijo telefónico <input name="phone_prefix" value="<?= e($prefix) ?>" inputmode="numeric" maxlength="4" placeholder="34"><small>Para poder llamar desde el móvil. Vacío = sin prefijo.</small></label>
      <label>Zona horaria
        <select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option<?= $z === $tz ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label>
    </div>
    <label class="check"><input type="checkbox" name="noindex" value="1"<?= $noindex ? ' checked' : '' ?>> <span><b>Ocultar la web a Google</b> — para webs en pruebas. Desactívalo cuando la publiques.</span></label>
  </fieldset>

  <fieldset>
    <legend>Nombres del catálogo</legend>
    <p class="hint" style="margin-top:0">Cómo se llaman los grupos del catálogo en toda la web y en el panel (por ejemplo Categoría / Subcategoría). Las direcciones no cambian. Vacío = el nombre de siempre.</p>
    <div class="g4">
      <?php foreach (['family' => 'Primer nivel (singular)', 'families' => 'Primer nivel (plural)', 'sub' => 'Segundo nivel (singular)', 'subs' => 'Segundo nivel (plural)'] as $k => $label): ?>
        <label><?= e($label) ?> <input name="term_<?= e($k) ?>" value="<?= e($v['term_' . $k]) ?>" placeholder="<?= e(TERM_DEFAULTS[$k]) ?>" maxlength="40"></label>
      <?php endforeach; ?>
    </div>
    <div class="g2">
      <label>Género de esos nombres
        <select name="term_gender"><option value="f"<?= $v['term_gender'] === 'f' ? ' selected' : '' ?>>Femenino (la familia, todas las familias)</option><option value="m"<?= $v['term_gender'] === 'm' ? ' selected' : '' ?>>Masculino (el grupo, todos los grupos)</option></select>
        <small>Para que frases como «Todas las…» o «… creada» concuerden.</small></label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Textos fijos de la web</legend>
    <div class="g2">
      <?php foreach (CONFIG_TEXTS as $k => [$label, $default, $help]): $default = config_text_default($k); ?>
        <label><?= e($label) ?> <input name="<?= e($k) ?>" value="<?= e($v[$k]) ?>" placeholder="<?= e($default) ?>" maxlength="200">
          <small><?= e(($help !== '' ? $help . ' ' : '') . 'Vacío = «' . $default . '».') ?></small></label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <div class="savebar"><button class="btn" type="submit">Guardar</button></div>
</form>

<form method="post" action="<?= e(url('/admin/configuracion/general')) ?>" class="cfgf" id="panel">
  <?= csrf_field() ?><input type="hidden" name="action" value="panel_path">
  <fieldset>
    <legend>Dirección del panel</legend>
    <p class="hint" style="margin-top:0">Ahora el panel está en: <strong><code><?= e(site_origin() . url('/admin')) ?></code></strong>. Puedes cambiar <code>/admin</code> por otra dirección (por ejemplo <code>gestion-x7k</code>) para que los robots no la encuentren. Es un complemento, <strong>no sustituye a una buena contraseña</strong>. Al cambiarla, <code>/admin</code> dará «página no encontrada».</p>
    <div class="g2">
      <label>Nueva dirección <input name="panel_path" maxlength="40" placeholder="admin" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocomplete="off"><small>Minúsculas, números y guiones, de 3 a 40 caracteres. Vacío (o «admin») = la de siempre.</small></label>
      <label class="check" style="align-self:end"><input type="checkbox" name="ack" value="1"> <span>Entiendo que la dirección actual dejará de funcionar y que anotaré la nueva en cuanto la vea.</span></label>
    </div>
    <p class="alert warn" style="margin:6px 0"><?= admin_icon('info') ?><span><strong>Si te quedas fuera:</strong> por FTP, borra el archivo <code>data/panel.txt</code> y el panel vuelve a <code>/admin</code>.</span></p>
    <div class="buttons"><button class="btn small" type="submit">Cambiar la dirección del panel</button></div>
  </fieldset>
</form>
