<div class="box">
  <h1>Recuperar contraseña</h1>
  <?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
  <?php if ($sent): ?>
    <p class="flash ok">Si existe esa cuenta y tiene un correo guardado, te hemos enviado un enlace para elegir una contraseña nueva. Caduca en 1 hora.</p>
    <p class="alt"><a href="<?= e(url('/admin/login')) ?>">← Volver al acceso</a></p>
  <?php else: ?>
    <p class="muted">Escribe tu usuario o tu correo y te enviaremos un enlace.</p>
    <form method="post">
      <?= csrf_field() ?>
      <label>Usuario o correo <input name="who" required autofocus autocomplete="username"></label>
      <button class="btn" type="submit">Enviar enlace</button>
    </form>
    <p class="alt"><a href="<?= e(url('/admin/login')) ?>">← Volver al acceso</a></p>
  <?php endif; ?>
</div>
