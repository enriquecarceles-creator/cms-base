<div class="box">
  <h1>Panel de <?= e(site_name()) ?></h1>
  <?php foreach (($flashes ?? []) as [$type, $msg]): ?><p class="flash <?= $type === 'err' ? 'err' : 'ok' ?>"><?= e($msg) ?></p><?php endforeach; ?>
  <?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>Usuario <input name="username" required autocomplete="username" autofocus></label>
    <label>Contraseña <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit">Entrar</button>
  </form>
  <p class="alt"><a href="<?= e(url('/admin/olvido')) ?>">¿Olvidaste tu contraseña?</a></p>
</div>
