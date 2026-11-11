<div class="box">
  <h1>Verificación en dos pasos</h1>
  <?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
  <p class="muted">Escribe el código de 6 dígitos que muestra tu app de autenticación. Si no tienes el móvil, usa uno de tus códigos de recuperación.</p>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label>Código <input name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="20" spellcheck="false" placeholder="123456"></label>
    <button class="btn" type="submit">Entrar</button>
  </form>
  <p class="alt"><a href="<?= e(url('/admin/login')) ?>">← Volver al acceso</a></p>
</div>
