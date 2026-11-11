<?php
/* Pantalla de resultado al cambiar la dirección del panel (maqueta sin menú; se muestra SIN redirigir). */
$reset = $newPath === 'admin';
$full = site_origin() . url('/admin');
?>
<div class="box">
  <h1><?= $reset ? 'El panel vuelve a la dirección de siempre' : 'Dirección del panel cambiada' ?></h1>
  <p>A partir de ahora el panel está en:</p>
  <p class="bigurl"><code><?= e($full) ?></code></p>
  <p><strong>Anótala ahora, antes de salir de esta página.</strong></p>
  <?php if (!$reset): ?>
    <p>La dirección anterior (<code><?= e(site_origin() . url('/' . $oldPath)) ?></code>) <strong>ya no funciona</strong>: da «página no encontrada». Si alguien más usa el panel, avísale de la nueva.</p>
  <?php else: ?>
    <p>La dirección personalizada anterior (<code>/<?= e($oldPath) ?></code>) ya no funciona.</p>
  <?php endif; ?>
  <p><a class="btn" href="<?= e(url('/admin/configuracion')) ?>">Abrir el panel en la nueva dirección</a></p>
  <?php if (!$reset): ?>
    <h2>Si te quedas fuera</h2>
    <p>Entra por FTP en la carpeta <code>data/</code> de la web y <strong>borra el archivo <code>panel.txt</code></strong>. El panel vuelve a <code>/admin</code> en la siguiente visita. Ese archivo solo contiene la dirección (<code><?= e($newPath) ?></code>).</p>
  <?php endif; ?>
  <p class="muted">No recargues esta página: el navegador intentaría reenviar el formulario a la dirección anterior y verías «no encontrada».</p>
</div>
