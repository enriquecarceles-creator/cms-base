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
