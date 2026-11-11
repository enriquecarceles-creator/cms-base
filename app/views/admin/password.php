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
