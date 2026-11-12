<?php
/** Configuración → General (pantalla compacta): región y dirección del panel. Variables: $prefix, $tz. */
?>
<div class="head"><h1>General</h1></div>
<p class="hint">Lo que afecta a toda la aplicación. Los módulos y los ajustes de la web están ahora en su pantalla: <a href="<?= e(url('/admin/pagina-catalogo')) ?>">Página del catálogo</a> (nombres del catálogo, catálogo en PDF, boceto y fila a escala), <a href="<?= e(url('/admin/representantes')) ?>">Representantes</a>, <a href="<?= e(url('/admin/slider')) ?>">Slider</a>, <a href="<?= e(url('/admin/paginas')) ?>">Páginas</a> (mapa en Contacto) y <a href="<?= e(url('/admin/pagina-inicio')) ?>">Página de inicio</a> (ocultar la web a Google). Los datos de la empresa están en <a href="<?= e(url('/admin/configuracion/empresa')) ?>">Empresa</a>, el correo en <a href="<?= e(url('/admin/configuracion/correo')) ?>">Correo</a> y la doble verificación en <a href="<?= e(url('/admin/configuracion/usuarios#erp')) ?>">Usuarios y seguridad</a>.</p>
<form method="post" class="cfgf">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Región</legend>
    <div class="g3">
      <label>Prefijo telefónico <input name="phone_prefix" value="<?= e($prefix) ?>" inputmode="numeric" maxlength="4" placeholder="34"><small>Para poder llamar desde el móvil. Vacío = sin prefijo.</small></label>
      <label>Zona horaria
        <select name="timezone"><?php foreach (timezone_identifiers_list() as $z): ?><option<?= $z === $tz ? ' selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select></label>
    </div>
    <small>Afectan a toda la aplicación: teléfonos, fechas de facturas, vencimientos y correos.</small>
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
