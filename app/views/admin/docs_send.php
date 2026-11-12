<?php
/** Enviar un pedido o albarán por email. Variables: $doc, $k, $self, $f, $errors, $recentSend, $transport. */
?>
<div class="head"><h1>Enviar <?= e(mb_strtolower($k['label'])) ?> <?= e($doc['doc_code']) ?></h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>
<?php if ($transport !== 'smtp'): ?><p class="alert warn"><?= admin_icon('info') ?><span>El correo se envía con el servicio del hosting (<code>mail()</code>), que muchas veces acaba en spam. Para que llegue mejor, configura SMTP en <a href="<?= e(url('/admin/configuracion/correo')) ?>">Correo</a>.</span></p><?php endif; ?>
<p class="hint">El correo lleva el documento <strong>dentro</strong> (sin archivos adjuntos ni enlaces). Queda anotado en su historial.</p>
<dl class="if-kv" style="max-width:520px">
  <div><dt><?= e($k['label']) ?></dt><dd><?= e($doc['doc_code']) ?></dd></div>
  <div><dt>Fecha</dt><dd><?= e(erp_day((int)$doc['doc_day'])) ?></dd></div>
  <div><dt>Total</dt><dd><?= e(erp_money((int)$doc['total_cents'])) ?></dd></div>
  <div><dt>Cliente</dt><dd><?= e($doc['cust_name']) ?></dd></div>
</dl>
<form method="post" action="<?= e(url($self . '/enviar')) ?>" class="form" autocomplete="off">
  <?= csrf_field() ?>
  <label>Enviar a <input type="email" name="to" value="<?= e($f['to']) ?>" maxlength="200" required placeholder="correo del destinatario">
    <small><?= $f['to'] === '' ? 'El documento y la ficha del cliente no tienen correo: escríbelo aquí.' : 'Un solo destinatario. Se propone el correo del documento o de la ficha del cliente.' ?></small></label>
  <label>Asunto <input name="subject" value="<?= e($f['subject']) ?>" maxlength="150" required></label>
  <label>Mensaje <textarea name="message" rows="8" maxlength="2000" required><?= e($f['message']) ?></textarea></label>
  <label class="check"><input type="checkbox" name="copy" value="1"<?= $f['copy'] ? ' checked' : '' ?>> <span>Enviarme una copia (en copia oculta, a mi correo de «Mi cuenta»)</span></label>
  <?php if ($recentSend || $f['confirm_dup']): ?>
    <label class="check"><input type="checkbox" name="confirm_dup" value="1"<?= $f['confirm_dup'] ? ' checked' : '' ?>> <span>Este documento se acaba de enviar. Marca para enviarlo otra vez.</span></label>
  <?php endif; ?>
  <div class="buttons"><button class="btn" type="submit">Enviar</button> <a class="btn ghost" href="<?= e(url($self)) ?>">Cancelar</a></div>
</form>
