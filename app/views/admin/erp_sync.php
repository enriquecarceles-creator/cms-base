<?php
/** Sincronizar con NCS: importar FCUENTAS.MDB y FHISTORI.MDB (clientes y facturas), con resumen previo y por tandas. */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$st = $job['status'] ?? '';
?>
<div class="head"><h1>Sincronizar con NCS</h1><a href="<?= e(url('/admin/clientes')) ?>">Ver clientes →</a></div>
<p class="hint">NCS Facturación sigue siendo el programa que factura: aquí solo se <strong>traen y se consultan</strong> los clientes y las facturas. Puedes sincronizar cuando quieras; lo que no ha cambiado no se toca y no se duplica nada. Nunca se borra nada de la web aunque ya no esté en NCS.</p>
<?php if (!empty($job['error'])): ?><p class="flash err"><?= e($job['error']) ?></p><?php endif; ?>

<section class="stats" aria-label="Lo que hay guardado">
  <div class="stat"><span class="stat-l">Clientes</span><strong class="stat-n"><?= e($n($totals['customers'])) ?></strong></div>
  <div class="stat"><span class="stat-l">Facturas</span><strong class="stat-n"><?= e($n($totals['invoices'])) ?></strong></div>
  <div class="stat"><span class="stat-l">Líneas</span><strong class="stat-n"><?= e($n($totals['lines'])) ?></strong></div>
  <div class="stat"><span class="stat-l">Última sincronización</span><strong class="stat-n stat-date"><?= $last ? e(date('d/m/Y H:i', (int)$last['at'])) : 'ninguna' ?></strong></div>
</section>

<?php if ($st === 'run'):
  $c = $job['counts'];
  $totC = $c['cust']['new'] + $c['cust']['changed'];
  $totI = $c['inv']['new'] + $c['inv']['changed'];
  $doneI = (int)($job['done_inv'] ?? 0);
  $pct = $totI > 0 ? (int)floor(100 * $doneI / $totI) : 100; ?>
  <h2>Sincronizando…</h2>
  <p>Clientes: <?= e($n((int)($job['done_cust'] ?? 0))) ?> de <?= e($n($totC)) ?> · Facturas: <?= e($n($doneI)) ?> de <?= e($n($totI)) ?> (<?= $pct ?> %)</p>
  <div class="bar-prog" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pct ?>%"></span></div>
  <p class="muted">No cierres esta página: se actualiza sola. Si la cierras, vuelve aquí y continúa donde se quedó.</p>
  <?php if (!empty($job['error'])): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn ghost" type="submit">Cancelar y empezar de nuevo</button></form>
  <?php endif; ?>

<?php elseif ($st === 'plan'):
  $c = $job['counts']; ?>
  <h2>Resumen previo</h2>
  <p class="muted" style="margin-top:0">Todavía no se ha cambiado nada. Esto es lo que pasará si pulsas «Sincronizar».</p>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th scope="col"></th><th scope="col">Nuevos</th><th scope="col">Cambiados</th><th scope="col">Sin cambios</th><th scope="col">Ya no están en NCS</th></tr></thead>
    <tbody>
      <tr><th scope="row">Clientes</th><td><?= e($n($c['cust']['new'])) ?></td><td><?= e($n($c['cust']['changed'])) ?></td><td><?= e($n($c['cust']['same'])) ?></td><td><?= e($n($c['cust']['gone'])) ?></td></tr>
      <tr><th scope="row">Facturas</th><td><?= e($n($c['inv']['new'])) ?></td><td><?= e($n($c['inv']['changed'])) ?></td><td><?= e($n($c['inv']['same'])) ?></td><td><?= e($n($c['inv']['gone'])) ?></td></tr>
    </tbody>
  </table></div>
  <p class="muted">Líneas de factura a escribir: <?= e($n((int)$c['lines'])) ?><?= $c['skipped'] ? ' · filas descartadas por no tener código o por repetirse: ' . e($n((int)$c['skipped'])) : '' ?>. Los que «ya no están en NCS» se conservan; no se borra nada.</p>

  <form method="post" class="form">
    <?= csrf_field() ?>
    <label>Cliente de contado (el de los tickets de mostrador)
      <input name="retail" value="<?= e($retail !== '' ? $retail : (string)($job['retail_suggest'] ?? '')) ?>" maxlength="40" placeholder="código, vacío = ninguno">
      <small><?= !empty($job['retail_suggest']) && $retail === '' ? 'Propuesto: es el cliente sin NIF con más facturas (' . e($n(ERP_RETAIL_MIN)) . ' o más). ' : '' ?>Se oculta en la lista de Clientes para que no tape a los demás. Se puede cambiar cuando quieras.</small></label>
    <div class="buttons">
      <button class="btn" type="submit" name="action" value="start">Sincronizar</button>
      <button class="btn ghost" type="submit" name="action" value="cancel">Cancelar</button>
    </div>
  </form>

<?php else: ?>
  <h2>Traer datos de NCS</h2>
  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="analyze">
    <label>FCUENTAS.MDB (clientes) <input type="file" name="cuentas" accept=".mdb,.MDB"><small><?= $ftp['cuentas'] ? 'Ya hay uno en <code>data/importar/</code>: se usará si no eliges otro.' : 'O déjalo por FTP en <code>data/importar/</code>.' ?></small></label>
    <label>FHISTORI.MDB (facturas y líneas) <input type="file" name="historico" accept=".mdb,.MDB"><small><?= $ftp['historico'] ? 'Ya hay uno en <code>data/importar/</code>: se usará si no eliges otro.' : 'Pesa mucho: si el servidor no deja subirlo, déjalo por FTP en <code>data/importar/</code>.' ?></small></label>
    <p class="muted" style="margin:0">Se lee solo lo necesario. <strong>No se importan</strong> datos bancarios (cuenta, banco, BIC, mandato, tarjeta), notas libres ni saldos internos de NCS. Los archivos de <code>data/importar/</code> se borran al terminar.</p>
    <div class="buttons"><button class="btn" type="submit">Analizar y ver el resumen</button></div>
  </form>

  <h2>Cliente de contado</h2>
  <form method="post" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="retail">
    <label>Código del cliente de los tickets de mostrador <input name="retail" value="<?= e($retail) ?>" maxlength="40" placeholder="vacío = ninguno"><small>Se oculta en la lista de Clientes. Lo propone el análisis, pero aquí se puede cambiar.</small></label>
    <div class="buttons"><button class="btn ghost" type="submit">Guardar</button></div>
  </form>
<?php endif; ?>
