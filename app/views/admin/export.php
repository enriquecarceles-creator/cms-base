<?php
/**
 * Datos → Exportar. Variables: $canArticles, $canErp, $families, $years, $retail, $internalFields, $publicFields, $isAdmin, $need2fa, $totals.
 */
$meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
?>
<div class="head"><h1>Exportar</h1></div>
<p class="hint">Descarga datos en un archivo CSV que Excel abre bien en español (columnas separadas por «;», decimales con coma, acentos correctos). Cada exportación queda anotada en el <a href="<?= e(url('/admin/historial')) ?>">Historial</a>.</p>

<?php if ($canArticles): ?>
<h2 id="articulos">Artículos</h2>
<form method="post" action="<?= e(url('/admin/exportar')) ?>" class="form">
  <?= csrf_field() ?><input type="hidden" name="what" value="articulos">
  <p class="muted" style="margin:0">Referencia, nombre, <?= e(term('family', true)) ?>, <?= e(term('sub', true)) ?>, si está visible y los campos públicos<?= $publicFields ? ' (' . e(implode(', ', array_map(fn($f) => (string)$f['name'], $publicFields))) . ')' : '' ?>.</p>
  <div class="two">
    <label><?= e(term('family')) ?>
      <select name="familia"><option value="">Todas</option><?php foreach ($families as $f): ?><option value="<?= (int)$f['id'] ?>"><?= e($f['name']) ?></option><?php endforeach; ?></select></label>
    <label>Qué artículos
      <select name="visible"><option value="">Todos</option><option value="1">Solo los visibles</option><option value="0">Solo los ocultos</option></select></label>
  </div>
  <?php if ($isAdmin && $internalFields): ?>
    <label class="check"><input type="checkbox" name="internos" value="1"> <span>Incluir también los campos internos (<?= e(implode(', ', array_map(fn($f) => (string)$f['name'], $internalFields))) ?>). No son para publicar.</span></label>
  <?php endif; ?>
  <div class="buttons"><button class="btn" type="submit">Descargar CSV de artículos</button></div>
</form>
<?php endif; ?>

<?php if ($canErp): ?>
<?php if ($need2fa): ?>
  <p class="alert warn"><?= admin_icon('alert') ?><span><strong>Clientes y ventas son datos personales.</strong> Para exportarlos hace falta activar antes la <a href="<?= e(url('/admin/clave#doble')) ?>">doble verificación en Mi cuenta</a>.</span></p>
<?php endif; ?>
<h2 id="clientes">Clientes</h2>
<form method="post" action="<?= e(url('/admin/exportar')) ?>" class="form">
  <?= csrf_field() ?><input type="hidden" name="what" value="clientes">
  <p class="muted" style="margin:0"><?= (int)$totals['customers'] ?> clientes: contacto, dirección, forma de pago, tarifa y estado. No incluye las notas internas. <strong>Son datos personales: guarda el archivo con cuidado.</strong></p>
  <?php if ($retail !== ''): ?><label class="check"><input type="checkbox" name="contado" value="1"> <span>Incluir también el cliente de contado (el de los tickets de mostrador)</span></label><?php endif; ?>
  <div class="buttons"><button class="btn" type="submit"<?= $totals['customers'] === 0 ? ' disabled' : '' ?>>Descargar CSV de clientes</button></div>
</form>

<h2 id="ventas">Ventas</h2>
<form method="post" action="<?= e(url('/admin/exportar')) ?>" class="form">
  <?= csrf_field() ?><input type="hidden" name="what" value="ventas">
  <p class="muted" style="margin:0"><?= (int)$totals['invoices'] ?> facturas. Una fila por factura (base, IVA, total, estado, forma de pago y si está cobrada) o, con líneas, una fila por cada línea. Las anuladas salen marcadas.</p>
  <div class="two">
    <label>Año <select name="anio"><option value="">Todos</option><?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"><?= (int)$y ?></option><?php endforeach; ?></select></label>
    <label>Mes <select name="mes"><option value="">Todos</option><?php foreach ($meses as $i => $m): ?><option value="<?= $i + 1 ?>"><?= e($m) ?></option><?php endforeach; ?></select></label>
  </div>
  <?php if ($retail !== ''): ?>
    <label>Ámbito <select name="ambito"><option value="">Mostrador y clientes</option><option value="mostrador">Solo mostrador</option><option value="clientes">Solo clientes</option></select></label>
  <?php endif; ?>
  <label class="check"><input type="checkbox" name="lineas" value="1"> <span>Incluir las líneas de cada factura (el archivo tendrá muchas más filas)</span></label>
  <div class="buttons"><button class="btn" type="submit"<?= $totals['invoices'] === 0 ? ' disabled' : '' ?>>Descargar CSV de ventas</button></div>
</form>
<?php endif; ?>
