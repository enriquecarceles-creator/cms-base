<?php
/** Configuración → Correo (pantalla compacta). Variables: $f, $errors, $hasPass, $defaultFrom, $log, $lastError, $myEmail. */
?>
<div class="head"><h1>Correo</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Cómo envía la web sus correos: recuperar contraseña, avisos de seguridad y facturas. Todos pasan por aquí.</p>

<?php if ($lastError): ?>
  <p class="alert warn"><?= admin_icon('alert') ?><span><strong>El último envío falló</strong> (<?= e(date('d/m/Y H:i', $lastError['at'])) ?>): <?= e($lastError['error']) ?> El aviso desaparece cuando un correo salga bien.</span></p>
<?php endif; ?>

<form method="post" class="cfgf" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <fieldset>
    <legend>Cómo se envía</legend>
    <label class="check"><input type="radio" name="transport" value="smtp"<?= $f['transport'] === 'smtp' ? ' checked' : '' ?>> <span><b>SMTP (recomendado)</b> — un buzón de tu dominio. Llega mejor a la bandeja de entrada y no a spam.</span></label>
    <label class="check"><input type="radio" name="transport" value="mail"<?= $f['transport'] === 'mail' ? ' checked' : '' ?>> <span><b>El correo del hosting</b> (<code>mail()</code>) — sin configurar nada, pero muchos hostings lo limitan y puede acabar en spam.</span></label>
  </fieldset>

  <fieldset>
    <legend>Servidor SMTP</legend>
    <p class="hint" style="margin-top:0">Te los da tu proveedor. En IONOS, por ejemplo: <code>smtp.ionos.es</code>, puerto 587, STARTTLS, y la dirección completa del buzón como usuario.</p>
    <div class="g4">
      <label class="wide" style="grid-column:span 2">Servidor <input name="host" value="<?= e($f['host']) ?>" placeholder="smtp.tudominio.com" maxlength="200" autocomplete="off"></label>
      <label>Puerto <input name="port" value="<?= e($f['port']) ?>" inputmode="numeric" maxlength="5" placeholder="587"></label>
      <label>Cifrado
        <select name="secure">
          <option value="tls"<?= $f['secure'] === 'tls' ? ' selected' : '' ?>>STARTTLS (587)</option>
          <option value="ssl"<?= $f['secure'] === 'ssl' ? ' selected' : '' ?>>SSL (465)</option>
          <option value="none"<?= $f['secure'] === 'none' ? ' selected' : '' ?>>Sin cifrado</option>
        </select></label>
    </div>
    <div class="g2">
      <label>Usuario <input name="user" value="<?= e($f['user']) ?>" maxlength="200" autocomplete="off" placeholder="buzon@tudominio.com"></label>
      <label>Contraseña <input type="password" name="pass" value="" maxlength="200" autocomplete="new-password" placeholder="<?= $hasPass ? '•••••••• (guardada)' : '' ?>">
        <small><?= $hasPass ? 'Vacía = se conserva la guardada. Nunca se vuelve a mostrar.' : 'Se guarda en <code>data/mail.php</code>, no en la base de datos, y nunca se vuelve a mostrar.' ?></small></label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Remitente y avisos</legend>
    <p class="cfg-warn" style="margin-top:0"><b>Usa un buzón del mismo dominio de la web</b> (por ejemplo <code>pedidos@tudominio.com</code>). Si el remitente es de otro dominio (Gmail, Hotmail…), muchos servidores rechazan el correo o lo mandan a spam.</p>
    <div class="g3">
      <label>Nombre del remitente <input name="from_name" value="<?= e($f['from_name']) ?>" maxlength="80" placeholder="<?= e(site_name()) ?>"></label>
      <label>Dirección del remitente <input type="email" name="from" value="<?= e($f['from']) ?>" maxlength="200" placeholder="<?= e($defaultFrom) ?>"><small>Vacío = <?= e($defaultFrom) ?>.</small></label>
      <label>Responder a (opcional) <input type="email" name="reply_to" value="<?= e($f['reply_to']) ?>" maxlength="200" placeholder="correo para las respuestas"></label>
    </div>
    <label>Quién recibe los avisos de seguridad <input name="notify" value="<?= e($f['notify']) ?>" maxlength="400" placeholder="vacío = todos los administradores con correo">
      <small>Hasta 5 direcciones separadas por comas (por ejemplo, el aviso de que alguien ha desactivado la doble verificación de Clientes y Ventas). Los correos personales —recuperar contraseña, acceso desde un sitio nuevo— siguen yendo a cada usuario.</small></label>
  </fieldset>

  <div class="savebar"><button class="btn" type="submit">Guardar</button></div>
</form>

<form method="post" class="cfgf" id="prueba">
  <?= csrf_field() ?><input type="hidden" name="action" value="test">
  <fieldset>
    <legend>Enviar correo de prueba</legend>
    <p class="hint" style="margin-top:0">Se envía con lo que tengas <strong>guardado</strong>, así que guarda antes de probar. Si falla, el motivo sale explicado aquí.</p>
    <div class="g3">
      <label>Enviar a <input type="email" name="test_to" value="<?= e($myEmail) ?>" maxlength="200" required placeholder="tu correo"></label>
      <div style="align-self:end"><button class="btn small" type="submit">Enviar correo de prueba</button></div>
    </div>
  </fieldset>
</form>

<h2 id="registro" style="font-size:1rem;margin:14px 0 6px">Últimos envíos</h2>
<?php if (!$log): ?>
  <p class="muted">Todavía no se ha enviado ningún correo.</p>
<?php else: ?>
  <p class="muted" style="margin:0 0 6px">Los últimos <?= (int)MAIL_LOG_KEEP ?> correos: fecha, destinatario, asunto y resultado. El texto de los mensajes no se guarda.</p>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th scope="col">Fecha</th><th scope="col">Para</th><th scope="col">Asunto</th><th scope="col">Resultado</th></tr></thead>
    <tbody>
    <?php foreach ($log as $r): ?>
      <tr>
        <td><?= e(date('d/m/Y H:i', (int)$r['sent_at'])) ?></td>
        <td><?= e($r['to_email']) ?></td>
        <td><?= e($r['subject']) ?></td>
        <td><?php if ((int)$r['ok'] === 1): ?>Enviado<?php else: ?><span class="review-flag">Falló</span><?= $r['error'] !== '' ? '<br><small class="muted">' . e($r['error']) . '</small>' : '' ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
