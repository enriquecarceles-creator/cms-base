<div class="head"><h1>Mi cuenta</h1><span class="muted"><?= e($me['username']) ?></span></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<div class="cards2">
  <form method="post" class="form" autocomplete="off">
    <h2>Correo de recuperación</h2>
    <?= csrf_field() ?><input type="hidden" name="action" value="email">
    <label>Correo electrónico <input type="email" name="email" value="<?= e($me['email']) ?>" placeholder="tu@correo.com"><small>Se usa para «¿Olvidaste tu contraseña?» en el acceso.</small></label>
    <div class="buttons"><button class="btn" type="submit">Guardar correo</button></div>
  </form>
  <form method="post" class="form" autocomplete="off">
    <h2>Cambiar contraseña</h2>
    <?= csrf_field() ?>
    <label>Contraseña actual <input type="password" name="current" required autocomplete="current-password"></label>
    <label>Nueva (mínimo 10 caracteres) <input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
    <label>Repite la nueva <input type="password" name="new2" required minlength="10" autocomplete="new-password"></label>
    <div class="buttons"><button class="btn" type="submit">Cambiar contraseña</button></div>
  </form>
</div>

<h2 id="sitios">Sitios conocidos</h2>
<div class="form">
  <p class="muted" style="margin:0">Si entras desde una red o un navegador que no estén en esta lista, te avisamos por correo para que puedas reaccionar si no has sido tú. Se guardan los últimos <?= (int)AUTH_KEEP_SITES ?>.
    <?= $me['email'] === '' ? '<strong>No tienes correo guardado, así que no recibirás avisos:</strong> añádelo arriba.' : '' ?></p>
  <?php if ($sites): ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th scope="col">Navegador</th><th scope="col">Red</th><th scope="col">Último acceso</th></tr></thead>
      <tbody>
      <?php foreach ($sites as $s): $now = $s['ip_net'] === $siteNow['ip_net'] && $s['browser'] === $siteNow['browser']; ?>
        <tr><td><?= e($s['browser']) ?><?= $now ? ' <small class="muted">· este</small>' : '' ?></td><td><?= e($s['ip_net'] !== '' ? $s['ip_net'] : '—') ?></td><td class="t-date"><?= e(date('d/m/Y H:i', (int)$s['last_seen'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php else: ?>
    <p class="muted" style="margin:0">Todavía no hay ninguno: se registran al iniciar sesión.</p>
  <?php endif; ?>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="forget_sites">
    <button class="btn ghost" type="submit">Olvidar sitios conocidos</button>
    <small class="muted"> Útil si pierdes un móvil u ordenador: borra todos menos este (el que estás usando ahora); si alguien entra desde otro, recibirás el aviso.</small>
  </form>
</div>

<h2 id="doble">Doble verificación</h2>
<div class="form">
  <?php if ($tf['codes']): ?>
    <div class="flash ok" style="margin:0">
      <strong>Códigos de recuperación.</strong> Guárdalos ahora en un sitio seguro (gestor de contraseñas o papel): <strong>no se volverán a mostrar</strong>. Cada uno vale una sola vez y sirve si pierdes el móvil.
      <ul class="codes"><?php foreach ($tf['codes'] as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($tf['enabled']): ?>
    <p style="margin:0"><strong>Activada.</strong> Al entrar, después de la contraseña, se pide el código de tu app. Te quedan <strong><?= (int)$tf['left'] ?></strong> código<?= $tf['left'] === 1 ? '' : 's' ?> de recuperación.<?= $tf['left'] <= 2 ? ' <strong>Pide códigos nuevos pronto.</strong>' : '' ?></p>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>Tu contraseña actual <input type="password" name="current" required autocomplete="current-password"></label>
      <label>Código de la app (o de recuperación) <input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="20" spellcheck="false"></label>
      <div class="buttons">
        <button class="btn ghost" type="submit" name="action" value="totp_newcodes">Códigos de recuperación nuevos</button>
        <button class="btn ghost" type="submit" name="action" value="totp_disable" onclick="return confirm('¿Desactivar la doble verificación?')">Desactivar</button>
      </div>
    </form>
  <?php elseif ($tf['setup'] !== ''): ?>
    <p style="margin:0"><strong>Paso 1.</strong> En tu app de autenticación (Google Authenticator, Microsoft Authenticator, Aegis…) añade una cuenta nueva con «Introducir clave de configuración» y escribe esta clave (tipo «basada en tiempo»):</p>
    <p class="totp-key"><code><?= e($tf['pretty']) ?></code></p>
    <p class="muted" style="margin:0">Desde el móvil también puedes <a href="<?= e($tf['uri']) ?>">abrir este enlace</a> para que la app la añada sola.</p>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?><input type="hidden" name="action" value="totp_enable">
      <label><strong>Paso 2.</strong> Escribe el código de 6 dígitos que muestra la app <input name="code" required inputmode="numeric" autocomplete="one-time-code" maxlength="10" spellcheck="false" placeholder="123456"></label>
      <div class="buttons"><button class="btn" type="submit">Comprobar y activar</button>
        <button class="btn ghost" type="submit" name="action" value="totp_cancel" formnovalidate>Cancelar</button></div>
    </form>
  <?php else: ?>
    <p style="margin:0">Opcional. Añade un segundo paso al entrar: además de la contraseña, un código de 6 dígitos que cambia cada 30 segundos en una app de tu móvil. Así, aunque alguien conozca tu contraseña, no puede entrar.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="totp_start"><button class="btn" type="submit">Activar</button></form>
  <?php endif; ?>
</div>
