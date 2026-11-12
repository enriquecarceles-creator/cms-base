<?php
/** Borrar las facturas de prueba. Variables: $n, $codes, $on (¿sigue activado el modo de prueba?). */
?>
<div class="head"><h1>Facturas de prueba</h1></div>
<?php if ($n === 0): ?>
  <p class="empty">No hay facturas de prueba.</p>
  <p><a class="btn ghost" href="<?= e(url('/admin/facturas')) ?>">Volver a Facturas</a></p>
<?php else: ?>
  <?php if (!$on): ?><p class="hint">Has quitado la marca de prueba: <strong>las próximas facturas serán reales</strong>. Antes de empezar, te conviene borrar las <?= (int)$n ?> factura<?= $n === 1 ? '' : 's' ?> de prueba, para que no se mezclen con las reales.</p>
  <?php else: ?><p class="alert warn keep"><?= admin_icon('alert') ?><span>El modo de prueba sigue activado: se pueden borrar las de prueba y seguir probando.</span></p><?php endif; ?>
  <p>Hay <strong><?= (int)$n ?></strong> factura<?= $n === 1 ? '' : 's' ?> de prueba:
    <?= e(implode(', ', $codes)) ?><?= $n > count($codes) ? '…' : '' ?>.</p>
  <ul>
    <li>Se borran con sus líneas y su historial. <strong>No se pueden recuperar</strong> (haz antes una copia de seguridad si dudas).</li>
    <li>Los albaranes que se facturaron con ellas vuelven a estar <strong>pendientes de facturar</strong>.</li>
    <li>No afecta a ninguna factura real, ni al registro, ni a las series: las de prueba nunca gastaron números.</li>
  </ul>
  <form method="post" action="<?= e(url('/admin/facturas/pruebas')) ?>" class="form">
    <?= csrf_field() ?>
    <div class="buttons">
      <button class="btn" type="submit">Borrar las <?= (int)$n ?> factura<?= $n === 1 ? '' : 's' ?> de prueba</button>
      <a class="btn ghost" href="<?= e(url('/admin/facturas')) ?>">Conservarlas por ahora</a>
    </div>
  </form>
<?php endif; ?>
