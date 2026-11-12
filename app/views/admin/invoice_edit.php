<?php
/** Modificar factura: solo los datos propios del CMS. Los datos fiscales de NCS, bloqueados. */
$custName = $cust ? ($cust['trade_name'] !== '' ? $cust['trade_name'] : $cust['name']) : '';
$paid = (int)$state['paid'] === 1;
?>
<div class="head"><h1>Modificar factura <?= e($inv['inv_number']) ?></h1><a href="<?= e(url($self)) ?>">← Volver a la factura</a></div>

<?php if (!$editable): ?>
  <p class="alert warn"><?= admin_icon('alert') ?><span><strong><?= e((string)$inv['origin'] === 'cms' ? ERP_CMS_LOCK_MSG : ERP_NCS_LOCK_MSG) ?></strong> Aquí solo puedes cambiar los datos propios del CMS (cobro, etiquetas y correo de envío); las notas se escriben en la pestaña «Notas internas».</span></p>
<?php else: ?>
  <p class="alert"><?= admin_icon('info') ?><span>Factura propia del CMS (origen CMS): sus datos fiscales podrán editarse aquí.</span></p>
<?php endif; ?>

<div class="cards2">
  <fieldset class="if-locked" disabled>
    <legend>Datos de la factura <?= $editable ? '' : '(bloqueados: vienen de NCS)' ?></legend>
    <dl class="kv kv-1">
      <div><dt>Número</dt><dd><?= e($inv['inv_number']) ?></dd></div>
      <div><dt>Fecha</dt><dd><?= e(erp_day((int)$inv['inv_day'])) ?></dd></div>
      <div><dt>Cliente</dt><dd><?= e($isRetail ? 'Cliente de contado' : ($custName !== '' ? $custName : (string)$inv['cust_code'])) ?></dd></div>
      <div><dt>Forma de pago</dt><dd><?= e($payLabel !== '' ? $payLabel : '—') ?></dd></div>
      <div><dt>Base · IVA · Total</dt><dd><?= e(erp_money((int)$inv['base_cents'])) ?> · <?= e(erp_money((int)$inv['vat_cents'])) ?> · <strong><?= e(erp_money((int)$inv['total_cents'])) ?></strong></dd></div>
      <div><dt>Líneas</dt><dd><?= count($lines) ?></dd></div>
    </dl>
  </fieldset>

  <form method="post" action="<?= e(url($self . '/modificar')) ?>" class="form" autocomplete="off">
    <h2>Datos propios del CMS</h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <label class="check"><input type="checkbox" name="paid" value="1"<?= $paid ? ' checked' : '' ?>> <span>Factura cobrada</span></label>
    <label>Fecha de cobro <input type="date" name="paid_day" value="<?= e(erp_day_to_input((int)$state['paid_day'])) ?>"><small>Si marcas «cobrada» y la dejas vacía, se usa hoy.</small></label>
    <label>Etiquetas <input name="tags" value="<?= e((string)$state['tags']) ?>" maxlength="250" placeholder="p. ej. reclamar, urgente"><small>Separadas por comas (máximo 10).</small></label>
    <label>Correo de envío de esta factura <input type="email" name="email_to" value="<?= e((string)$state['email_to']) ?>" maxlength="250" placeholder="<?= e($cust && $cust['email'] !== '' && !$isRetail ? $cust['email'] : 'el del cliente') ?>"><small>Vacío = el correo del cliente.</small></label>
    <div class="buttons"><button class="btn" type="submit">Guardar cambios</button> <a class="btn ghost" href="<?= e(url($self)) ?>">Cancelar</a></div>
  </form>
</div>
