<?php
/** Eliminar una forma de pago o una tarifa que están en uso: a cuál pasarlas. Variables: $what, $label, $usage, $isDefault, $targets (valor => texto), $back, $id. */
?>
<div class="head"><h1>Eliminar <?= e($what) ?></h1><a class="btn ghost" href="<?= e(url($back)) ?>">Volver</a></div>
<form method="post" action="<?= e(url($back)) ?>" class="cfgf" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete_move"><input type="hidden" name="id" value="<?= (int)$id ?>">
  <fieldset>
    <legend><?= e(ucfirst($what)) ?> «<?= e($label) ?>»</legend>
    <?php if ($usage !== ''): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span><strong>La usan <?= e($usage) ?>.</strong></span></p><?php endif; ?>
    <?php if ($isDefault): ?><p class="hint">Es la de por defecto (Parámetros): la de por defecto pasará a ser la que elijas.</p><?php endif; ?>
    <label>Pasarlos a
      <select name="to" required><?php foreach ($targets as $v => $t): ?><option value="<?= e((string)$v) ?>"><?= e($t) ?></option><?php endforeach; ?></select></label>
    <p class="hint" style="margin-bottom:0">Los clientes y los documentos sin emitir (borradores, pedidos y albaranes) pasan a la que elijas. <strong>Los documentos ya emitidos no cambian</strong>: las facturas emitidas e importadas guardan el nombre de la <?= e($what) ?> (y sus vencimientos ya calculados<?= $what === 'tarifa' ? ', y los precios de sus líneas' : '' ?>), así que una factura antigua nunca queda en blanco ni cambia.</p>
  </fieldset>
  <div class="savebar"><button class="btn danger" type="submit">Eliminar y pasarlos</button><a class="btn ghost" href="<?= e(url($back)) ?>">Cancelar</a></div>
</form>
