<section class="wrap block">
  <h1>Contacto</h1>
  <div class="contact-main">
    <div>
      <h2><?= e(setting('company_name')) ?></h2>
      <p><?= nl2br(e(setting('address'))) ?><?= setting('tax_id') !== '' ? '<br>CIF/NIF: ' . e(setting('tax_id')) : '' ?></p>
      <?php if (setting('phone') !== ''): ?><p>Teléfono: <a href="<?= e(tel_href(setting('phone'))) ?>"><?= e(setting('phone')) ?></a></p><?php endif; ?>
      <?php if (setting('email') !== ''): ?><p><a class="btn" href="mailto:<?= e(setting('email')) ?>">Enviar un correo</a></p><?php endif; ?>
    </div>
  </div>

  <?php
    $addr = trim(preg_replace('/\s*\n\s*/', ', ', preg_replace('/\n?\s*Apdo\.?.*$/mi', '', setting('address'))), ', ');
    $q = rawurlencode($addr);
  ?>
  <?php if ($addr !== '' && feature('map')): ?>
  <h2>Cómo llegar</h2>
  <div class="howto">
    <div class="howto-map">
      <iframe title="Mapa de <?= e(site_name()) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"
        src="https://www.google.com/maps?q=<?= $q ?>&amp;output=embed&amp;z=15"></iframe>
    </div>
    <div class="howto-links">
      <p><?= e(str_replace("\n", ', ', $addr)) ?></p>
      <a class="btn" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= $q ?>" target="_blank" rel="noopener">Cómo llegar (Google Maps)</a>
      <a class="btn ghost" href="https://waze.com/ul?q=<?= $q ?>&amp;navigate=yes" target="_blank" rel="noopener">Abrir en Waze</a>
      <a class="btn ghost" href="https://maps.apple.com/?daddr=<?= $q ?>" target="_blank" rel="noopener">Apple Maps</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($reps): ?>
    <h2><?= e(setting('reps_title', 'Representantes por zonas')) ?></h2>
    <div class="grid reps">
      <?php foreach ($reps as $r): ?>
        <article class="rep">
          <h3><?= e($r['name']) ?></h3>
          <p class="zones"><?= e(implode(' · ', $r['zones'])) ?></p>
          <?php if ($r['address'] !== ''): ?><p><?= nl2br(e($r['address'])) ?></p><?php endif; ?>
          <?php if ($r['phone'] !== ''): ?><p>Tel. <a href="<?= e(tel_href($r['phone'])) ?>"><?= e($r['phone']) ?></a></p><?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
