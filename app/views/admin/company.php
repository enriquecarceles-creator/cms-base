<?php
/** Configuración → Empresa (pantalla compacta). Variables: $v (ajustes y logotipo/banner). */
$img = function (string $name, string $label, string $cur, string $help) { ?>
  <div>
    <label><?= e($label) ?>
      <?php if ($cur !== ''): ?><span style="display:block"><img src="<?= e(img_url($cur)) ?>" alt="" style="max-width:180px;max-height:80px;object-fit:contain;background:#f3f1ec;border-radius:8px;padding:5px"></span><span class="check" style="display:flex;gap:6px;margin:3px 0"><input type="checkbox" name="<?= e($name) ?>_remove" value="1" style="width:auto;height:auto"> <span style="color:var(--ink)">Quitar la imagen actual</span></span><?php endif; ?>
      <input type="file" name="<?= e($name) ?>" accept="image/*" style="height:auto;padding:4px 6px">
    </label>
    <small><?= e($help) ?></small>
  </div>
<?php };
$months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
?>
<div class="head"><h1>Empresa</h1></div>
<p class="hint">Todo lo que identifica a la empresa: en la web, en los documentos y en los correos. Los textos e imágenes de la portada están en <a href="<?= e(url('/admin/pagina-inicio')) ?>">Página de inicio</a>, y los del catálogo en <a href="<?= e(url('/admin/pagina-catalogo')) ?>">Página del catálogo</a>.</p>
<form method="post" class="cfgf" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Identidad</legend>
    <div class="g3">
      <label>Razón social <input name="company_name" value="<?= e($v['company_name']) ?>" required placeholder="Ej.: Mi Empresa S.L."></label>
      <label>Nombre comercial <input name="trade_name" value="<?= e($v['trade_name']) ?>" placeholder="El de la cabecera"></label>
      <label>CIF / NIF <input name="tax_id" value="<?= e($v['tax_id']) ?>"></label>
    </div>
    <div class="g2">
      <label>Subtítulo bajo el nombre <input name="brand_sub" value="<?= e($v['brand_sub']) ?>" placeholder="Ej.: Fabricantes desde 1960"></label>
      <label>Lema <input name="tagline" value="<?= e($v['tagline']) ?>" placeholder="Frase corta (portada y pie)"></label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Logotipo y color</legend>
    <div class="g3">
      <?php $img('logo', 'Logotipo', $v['logo'], 'PNG con fondo transparente. Sale en la cabecera, el catálogo en PDF, los documentos y la pestaña.'); ?>
      <label>Altura del logotipo (px) <input type="number" name="logo_height" min="24" max="110" value="<?= e($v['logo_height'] !== '' ? $v['logo_height'] : '64') ?>"><small>Entre 24 y 110. En móvil se limita a 52.</small></label>
      <label>Color de marca <input type="color" name="brand_color" value="<?= e($v['brand_color'] !== '' ? $v['brand_color'] : '#daaa00') ?>"><small>Acento de la web y de los documentos.</small></label>
    </div>
    <div class="g2">
      <?php $img('banner', 'Banner de logotipos del pie (ayudas, certificaciones…)', $v['footer_banner'], 'Opcional. Imagen apaisada, centrada bajo el pie de todas las páginas.'); ?>
      <label>Texto alternativo del banner <input name="footer_banner_alt" value="<?= e($v['footer_banner_alt']) ?>" placeholder="Describe lo que muestra"></label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Contacto</legend>
    <div class="g2">
      <label>Dirección (un renglón por línea)<textarea name="address" rows="3"><?= e($v['address']) ?></textarea><small>También sirve para el mapa de «Cómo llegar» y para los documentos.</small></label>
      <div>
        <div class="g2">
          <label>Teléfono <input name="phone" value="<?= e($v['phone']) ?>"></label>
          <label>WhatsApp (con prefijo) <input name="whatsapp" value="<?= e($v['whatsapp']) ?>" placeholder="34600000000"></label>
        </div>
        <label>Correo electrónico <input type="email" name="email" value="<?= e($v['email']) ?>"></label>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Redes sociales</legend>
    <div class="g3">
      <label>Facebook (enlace) <input name="facebook" value="<?= e($v['facebook']) ?>" placeholder="https://"></label>
      <label>Instagram (enlace) <input name="instagram" value="<?= e($v['instagram']) ?>" placeholder="https://"></label>
      <label>LinkedIn (enlace) <input name="linkedin" value="<?= e($v['linkedin']) ?>" placeholder="https://"></label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Ejercicio</legend>
    <div class="g3">
      <label>Mes de inicio del ejercicio
        <select name="fiscal_start_month"><?php foreach ($months as $i => $m): ?><option value="<?= $i + 1 ?>"<?= (int)$v['fiscal_start_month'] === $i + 1 ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select>
        <small>Casi siempre enero. Lo usarán los informes y el cierre de periodos.</small></label>
    </div>
  </fieldset>

  <div class="savebar"><button class="btn" type="submit">Guardar</button></div>
</form>
