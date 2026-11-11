<div class="head"><h1>Usuarios</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p><small><strong>Administrador</strong>: acceso a todo (usuarios, empresa, representantes, slider, artículos…). <strong>Gestión de catálogo</strong>: artículos, familias, fotos e importar, sin acceso a lo demás.</small></p>
<table class="tbl">
  <thead><tr><th>Usuario</th><th>Rol</th><th>Correo (recuperación)</th><th>Contraseña</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr>
      <td><?= e($u['username']) ?><?= (int)$u['id'] === $me ? ' <small>(tú)</small>' : '' ?></td>
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
