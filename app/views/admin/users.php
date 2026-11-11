<div class="head"><h1>Usuarios y seguridad</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p><small><strong>Administrador</strong>: acceso a todo (usuarios, empresa, representantes, slider, artículos…). <strong>Gestión de catálogo</strong>: artículos, <?= e(term('families', true)) ?>, fotos e importar, sin acceso a lo demás.</small></p>
<table class="tbl">
  <thead><tr><th>Usuario</th><th>Rol</th><th>Correo (recuperación)</th><th>Contraseña</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['username']) ?><?= (int)$u['id'] === $me ? ' <small>(tú)</small>' : '' ?>
        <br><small class="muted">Último acceso: <?= (int)$u['last_login_at'] > 0 ? e(date('d/m/Y H:i', (int)$u['last_login_at'])) : 'ninguno' ?></small>
        <?php if ((int)$u['totp_enabled'] === 1): ?>
          <br><small class="muted">Doble verificación activada</small>
          <?php if ((int)$u['id'] !== $me): ?><form method="post" class="inline" onsubmit="return confirm('¿Quitar la doble verificación a este usuario? Podrá entrar solo con la contraseña hasta que la vuelva a activar.')"><?= csrf_field() ?><input type="hidden" name="action" value="totp_off"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link danger" type="submit">Quitar</button></form><?php endif; ?>
        <?php endif; ?>
        <?php if ((int)$u['locked_until'] > time()): ?>
          <br><small class="review-flag">Bloqueado por intentos fallidos hasta las <?= e(date('H:i', (int)$u['locked_until'])) ?></small>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="unlock"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link" type="submit">Desbloquear</button></form>
        <?php endif; ?>
      </td>
      <td>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <select name="role" onchange="this.form.submit()"><?php foreach (USER_ROLES as $k => $label): ?><option value="<?= e($k) ?>"<?= $u['role'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
          <noscript><button class="link" type="submit">Cambiar</button></noscript>
        </form>
      </td>
      <td>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="email"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="email" name="email" value="<?= e($u['email']) ?>" placeholder="sin correo">
          <button class="link" type="submit">Guardar</button>
        </form>
      </td>
      <td>
        <form method="post" class="inline" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="password" name="password" minlength="10" placeholder="mín. 10 caracteres" autocomplete="new-password" required>
          <button class="link" type="submit">Cambiar</button>
        </form>
        <?php if ($u['email'] !== ''): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="sendreset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link" type="submit">Enviar enlace por correo</button></form><?php endif; ?>
      </td>
      <td>
        <?php if ((int)$u['id'] !== $me): ?>
        <form method="post" class="inline" onsubmit="return confirm('¿Borrar este usuario?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="link danger" type="submit">Borrar</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<h2>Añadir usuario</h2>
<form method="post" class="form" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label>Usuario <input type="text" name="username" value="<?= e($form['username']) ?>" required minlength="3" autocomplete="off"></label>
  <label>Correo (para recuperar la contraseña) <input type="email" name="email" value="<?= e($form['email']) ?>" autocomplete="off"></label>
  <label>Rol
    <select name="role"><?php foreach (USER_ROLES as $k => $label): ?><option value="<?= e($k) ?>"<?= $form['role'] === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
  </label>
  <label>Contraseña (mínimo 10 caracteres) <input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
  <div class="buttons"><button class="btn" type="submit">Crear usuario</button></div>
</form>

<h2 id="erp">Doble verificación para Clientes y Ventas</h2>
<form method="post" action="<?= e(url('/admin/usuarios')) ?>" class="form">
  <?= csrf_field() ?><input type="hidden" name="action" value="erp_2fa">
  <label class="check"><input type="checkbox" name="erp_2fa" value="1"<?= $erp2fa ? ' checked' : '' ?>> <span>Exigir doble verificación para Clientes y Ventas</span></label>
  <p class="muted" style="margin:0">Con esto activado, solo entran en Clientes y Ventas los usuarios (rol «ERP» y administradores) que tengan activada la doble verificación en <a href="<?= e(url('/admin/clave#doble')) ?>">Mi cuenta</a>. Son datos personales de clientes: <strong>en la web publicada debe estar activado</strong>.</p>
  <label>Tu contraseña (solo para desactivarla) <input type="password" name="erp_pass" autocomplete="current-password"><small>Si quitas la marca, escribe aquí tu contraseña. Se avisará por correo a los destinatarios de avisos del sistema (<a href="<?= e(url('/admin/correo')) ?>">Correo</a>) y en Inicio saldrá un aviso mientras esté desactivada.</small></label>
  <div class="buttons"><button class="btn" type="submit">Guardar</button></div>
</form>
