<?php
$img = function (string $name, string $label, string $cur, string $help) { ?>
  <fieldset>
    <legend><?= e($label) ?></legend>
    <?php if ($cur !== ''): ?><img src="<?= e(img_url($cur)) ?>" alt="" style="max-width:200px;max-height:110px;object-fit:contain;background:#f3f1ec;border-radius:8px;padding:6px"><label class="check"><input type="checkbox" name="<?= e($name) ?>_remove" value="1"> Quitar la imagen actual</label><?php endif; ?>
    <input type="file" name="<?= e($name) ?>" accept="image/*">
    <small><?= e($help) ?></small>
  </fieldset>
<?php };
$cards = array_pad($v['home_cards'], 4, ['', '']);
?>
<div class="head"><h1>Empresa</h1></div>
<p class="hint">Todo lo que identifica a la empresa en la web. Aquí se cambia para usar este mismo sistema con otra empresa.</p>
<form method="post" class="form" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <h2>Identidad</h2>
  <div class="two">
    <label>Razón social <input name="company_name" value="<?= e($v['company_name']) ?>" required placeholder="Ej.: Mi Empresa S.L."></label>
    <label>Nombre comercial <input name="trade_name" value="<?= e($v['trade_name']) ?>" placeholder="El que sale en la cabecera"></label>
  </div>
  <div class="two">
    <label>Subtítulo bajo el nombre <input name="brand_sub" value="<?= e($v['brand_sub']) ?>" placeholder="Ej.: Fabricantes desde 1960"></label>
    <label>CIF / NIF <input name="tax_id" value="<?= e($v['tax_id']) ?>"></label>
  </div>
  <label>Lema <input name="tagline" value="<?= e($v['tagline']) ?>" placeholder="Frase corta (portada y pie)"></label>
  <div class="two">
    <?php $img('logo', 'Logotipo', $v['logo'], 'PNG con fondo transparente, mejor cuadrado o apaisado. Sale en la cabecera, el catálogo en PDF y la pestaña del navegador.'); ?>
    <label>Tamaño del logotipo en la cabecera (px) <input type="number" name="logo_height" min="24" max="110" value="<?= e($v['logo_height'] !== '' ? $v['logo_height'] : '64') ?>"><small>Altura entre 24 y 110. En móvil se limita a 52.</small></label>
    <label>Color de marca <input type="color" name="brand_color" value="<?= e($v['brand_color'] !== '' ? $v['brand_color'] : '#daaa00') ?>" style="height:44px;padding:4px"><small>Acento de la web (líneas, detalles). Si no lo cambias se usa el dorado por defecto.</small></label>
  </div>

  <h2>Contacto</h2>
  <label>Dirección (una línea por renglón)
    <textarea name="address" rows="3"><?= e($v['address']) ?></textarea>
    <small>También sirve para el mapa de «Cómo llegar».</small>
  </label>
  <div class="two">
    <label>Teléfono <input name="phone" value="<?= e($v['phone']) ?>"></label>
    <label>WhatsApp (con prefijo) <input name="whatsapp" value="<?= e($v['whatsapp']) ?>" placeholder="34600000000"></label>
  </div>
  <label>Correo electrónico <input type="email" name="email" value="<?= e($v['email']) ?>"></label>
  <div class="two">
    <label>Facebook (enlace) <input name="facebook" value="<?= e($v['facebook']) ?>" placeholder="https://"></label>
    <label>Instagram (enlace) <input name="instagram" value="<?= e($v['instagram']) ?>" placeholder="https://"></label>
  </div>
  <label>LinkedIn (enlace) <input name="linkedin" value="<?= e($v['linkedin']) ?>" placeholder="https://"></label>

  <h2>Página de inicio</h2>
  <?php $img('hero', 'Imagen de fondo de la portada (opcional)', $v['hero_image'], 'Foto ancha, mejor oscura. Sin imagen se usa un fondo oscuro con tus fotos de ' . term('families', true) . '.'); ?>
  <label>Frase de bienvenida <input name="home_lead" value="<?= e($v['home_lead']) ?>" placeholder="Qué hace la empresa, en una frase"></label>
  <label>«Quiénes somos»: título <input name="home_title" value="<?= e($v['home_title']) ?>"></label>
  <label>«Quiénes somos»: texto <textarea name="home_text" rows="4"><?= e($v['home_text']) ?></textarea></label>
  <fieldset>
    <legend>Cuatro tarjetas junto al texto (opcionales)</legend>
    <?php foreach ($cards as $n => $c): ?>
      <div class="two">
        <label>Título <input name="card_t[]" value="<?= e($c[0]) ?>" maxlength="60"></label>
        <label>Descripción <input name="card_d[]" value="<?= e($c[1] ?? '') ?>" maxlength="120"></label>
      </div>
    <?php endforeach; ?>
  </fieldset>
  <div class="two">
    <label>Título de la lista de <?= e(term('families', true)) ?> <input name="home_chips_title" value="<?= e($v['home_chips_title']) ?>" placeholder="Nuestros productos"></label>
    <label>Descripción para Google (inicio) <input name="home_desc" value="<?= e($v['home_desc']) ?>" maxlength="160"></label>
  </div>

  <h2>Pie de página</h2>
  <?php $img('banner', 'Banner de logotipos (ayudas, certificaciones…)', $v['footer_banner'], 'Opcional. Imagen apaisada que sale centrada bajo el pie.'); ?>
  <label>Texto alternativo del banner <input name="footer_banner_alt" value="<?= e($v['footer_banner_alt']) ?>" placeholder="Describe lo que muestra"></label>

  <div class="buttons"><button class="btn" type="submit">Guardar</button></div>
</form>

<h2>Copia de seguridad</h2>
<form method="post" action="<?= e(url('/admin/copia')) ?>" class="form">
  <?= csrf_field() ?>
  <p class="muted" style="margin:0">Descarga un ZIP con la base de datos (artículos, <?= e(term('families', true)) ?>, usuarios, textos) y todas las fotos. Hazlo antes de actualizar la web.</p>
  <div class="buttons"><button class="btn ghost" type="submit">Descargar copia de seguridad</button></div>
</form>
<?php if (ALLOW_RESET): ?>
<h2>Empezar de cero</h2>
<form method="post" action="<?= e(url('/admin/reiniciar')) ?>" class="form" onsubmit="return confirm('Se borrarán artículos, <?= e(addslashes(term('families', true))) ?>, fotos, slider y representantes. ¿Continuar?')">
  <?= csrf_field() ?>
  <p class="muted" style="margin:0">Vacía el catálogo (artículos, <?= e(term('families', true)) ?>, fotos, slider y representantes). Se conservan los usuarios y los datos de esta página. Antes se guarda una copia en <code>data/backups/</code>.</p>
  <label>Escribe el nombre comercial («<?= e(site_name()) ?>») para confirmar <input name="confirm" autocomplete="off" required></label>
  <label>Tu contraseña <input type="password" name="password" autocomplete="current-password" required></label>
  <div class="buttons"><button class="btn danger-btn" type="submit">Vaciar el catálogo</button></div>
</form>
<?php endif; ?>
