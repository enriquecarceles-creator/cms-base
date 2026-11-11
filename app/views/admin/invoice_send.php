<?php
/**
 * Enviar la factura por email. Variables: $inv, $cust, $isRetail, $state, $self, $f (formulario), $errors, $annulled,
 * $recentSend, $transport, $mailError, $https.
 */
$d = doc_settings();
?>
<div class="head"><h1>Enviar la factura <?= e($inv['inv_number']) ?></h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>

<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>
<?php if ($annulled): ?><p class="alert warn"><?= admin_icon('alert') ?><span><strong>Esta factura está <?= e($d['stamp']) ?>.</strong> Si la envías, el correo y el enlace llevarán el sello «<?= e($d['stamp']) ?>» bien visible. Tendrás que confirmarlo abajo.</span></p><?php endif; ?>
<?php if ($transport !== 'smtp'): ?><p class="alert warn"><?= admin_icon('info') ?><span>El correo se envía con el servicio del hosting (<code>mail()</code>), que muchas veces acaba en spam. Para que llegue mejor, configura SMTP en <a href="<?= e(url('/admin/correo')) ?>">Correo</a>.</span></p><?php endif; ?>
<?php if ($mailError): ?><p class="alert warn"><?= admin_icon('alert') ?><span>El último correo de la web falló (<?= e(date('d/m/Y H:i', $mailError['at'])) ?>): <?= e($mailError['error']) ?> Revisa <a href="<?= e(url('/admin/correo')) ?>">Correo</a> antes de enviar.</span></p><?php endif; ?>
<?php if (!$https): ?><p class="alert warn"><?= admin_icon('alert') ?><span>Estás en la web sin HTTPS: el enlace del correo también sería sin cifrar. Entra por <code>https://</code> antes de enviar facturas.</span></p><?php endif; ?>

<p class="hint">El correo lleva la factura dentro y un <strong>enlace personal</strong> para verla, imprimirla o guardarla en PDF. No lleva archivos adjuntos. Quien tenga el enlace ve esta factura (solo esa) sin contraseña hasta que caduque o la anules en su Historial.</p>

<dl class="if-kv" style="max-width:520px">
  <div><dt>Factura</dt><dd><?= e($inv['inv_number']) ?></dd></div>
  <div><dt>Fecha</dt><dd><?= e(erp_day((int)$inv['inv_day'])) ?></dd></div>
  <div><dt>Total</dt><dd><?= e(erp_money((int)$inv['total_cents'])) ?></dd></div>
  <div><dt>Cliente</dt><dd><?= $isRetail || !$cust ? 'Cliente de contado' : e($cust['trade_name'] !== '' ? $cust['trade_name'] : $cust['name']) ?></dd></div>
</dl>

<form method="post" action="<?= e(url($self . '/enviar')) ?>" class="form" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="send">
  <div class="two">
    <label>Enviar a <input type="email" name="to" value="<?= e($f['to']) ?>" maxlength="200" required placeholder="correo del destinatario">
      <small><?= $f['to'] === '' ? 'Esta factura no tiene correo de envío ni el cliente tiene correo: escríbelo aquí.' : 'Un solo destinatario. Se propone el «correo de envío» de la factura o, si no, el del cliente.' ?></small></label>
    <label>El enlace es válido (días) <input type="number" name="days" value="<?= e($f['days']) ?>" min="<?= (int)ERP_LINK_DAYS_MIN ?>" max="<?= (int)ERP_LINK_DAYS_MAX ?>" required list="dias">
      <small>De <?= (int)ERP_LINK_DAYS_MIN ?> a <?= (int)ERP_LINK_DAYS_MAX ?> días. Pasado ese tiempo el enlace deja de abrirse.</small></label>
    <datalist id="dias"><option value="7"><option value="30"><option value="90"><option value="365"></datalist>
  </div>
  <label>Asunto <input name="subject" value="<?= e($f['subject']) ?>" maxlength="150" required></label>
  <label>Mensaje <textarea name="message" rows="9" maxlength="2000" required><?= e($f['message']) ?></textarea>
    <small>Se añade debajo el enlace y la factura completa. No pongas aquí datos que no quieras que lea quien reciba el correo.</small></label>

  <label class="check"><input type="checkbox" name="copy" value="1"<?= $f['copy'] ? ' checked' : '' ?>> <span>Enviarme una copia (en copia oculta, a mi correo de «Mi cuenta»)</span></label>
  <label class="check"><input type="checkbox" name="revoke_old" value="1"<?= $f['revoke_old'] ? ' checked' : '' ?>> <span>Anular los enlaces anteriores de esta factura al enviar el nuevo</span></label>
  <?php if ($annulled): ?>
    <label class="check"><input type="checkbox" name="confirm_annulled" value="1"<?= $f['confirm_annulled'] ? ' checked' : '' ?>> <span><strong>Confirmo que esta factura está <?= e($d['stamp']) ?></strong> y quiero enviarla igualmente, con el sello visible.</span></label>
  <?php endif; ?>
  <?php if ($recentSend || $f['confirm_dup']): ?>
    <label class="check"><input type="checkbox" name="confirm_dup" value="1"<?= $f['confirm_dup'] ? ' checked' : '' ?>> <span>Esta factura se acaba de enviar. Marca para enviarla otra vez.</span></label>
  <?php endif; ?>

  <div class="buttons"><button class="btn" type="submit">Enviar factura</button> <a class="btn ghost" href="<?= e(url($self)) ?>">Cancelar</a></div>
</form>
