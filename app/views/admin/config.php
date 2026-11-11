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

  <h2 id="erp">Clientes y Ventas</h2>
  <label class="check"><input type="checkbox" name="erp_2fa" value="1"<?= $erp2fa ? ' checked' : '' ?>> <span>Exigir doble verificación para Clientes y Ventas</span></label>
  <p class="muted" style="margin:0">Con esto activado, solo entran en Clientes y Ventas los usuarios (rol «ERP» y administradores) que tengan activada la doble verificación en <a href="<?= e(url('/admin/clave#doble')) ?>">Mi cuenta</a>. Son datos personales de clientes: <strong>en la web publicada debe estar activado</strong>.</p>
  <label>Tu contraseña (solo para desactivarla) <input type="password" name="erp_pass" autocomplete="current-password"><small>Si quitas la marca, escribe aquí tu contraseña. Se avisará por correo a todos los administradores y en Inicio saldrá un aviso mientras esté desactivada.</small></label>

  <div class="buttons"><button class="btn" type="submit">Guardar</button></div>
</form>

<h2 id="panel">Dirección del panel</h2>
<form method="post" action="<?= e(url('/admin/configuracion')) ?>" class="form">
  <?= csrf_field() ?><input type="hidden" name="action" value="panel_path">
  <p style="margin:0">Ahora el panel está en: <strong><code><?= e(site_origin() . url('/admin')) ?></code></strong></p>
  <p class="muted" style="margin:0">Puedes cambiar <code>/admin</code> por otra dirección (por ejemplo <code>gestion-x7k</code>) para que los robots no la encuentren. Es un complemento, <strong>no sustituye a una buena contraseña</strong>. Al cambiarla, <code>/admin</code> dará «página no encontrada».</p>
  <label>Nueva dirección <input name="panel_path" maxlength="40" placeholder="admin" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocomplete="off"><small>Minúsculas, números y guiones, de 3 a 40 caracteres. Vacío (o «admin») = la de siempre.</small></label>
  <label class="check"><input type="checkbox" name="ack" value="1"> <span>Entiendo que la dirección actual dejará de funcionar y que anotaré la nueva en cuanto la vea.</span></label>
  <p class="alert warn"><?= admin_icon('info') ?><span><strong>Si te quedas fuera:</strong> por FTP, borra el archivo <code>data/panel.txt</code> y el panel vuelve a <code>/admin</code>.</span></p>
  <div class="buttons"><button class="btn" type="submit">Cambiar la dirección del panel</button></div>
</form>
