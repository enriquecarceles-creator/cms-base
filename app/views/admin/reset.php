<div class="box">
  <h1>Nueva contraseña</h1>
  <?php if (!$valid): ?>
    <p class="flash err">Este enlace no es válido o ha caducado.</p>
    <p class="alt"><a href="<?= e(url('/admin/olvido')) ?>">Pedir un enlace nuevo</a></p>
  <?php else: ?>
    <?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <label>Nueva contraseña (mínimo 10 caracteres) <input type="password" name="new" required minlength="10" autocomplete="new-password" autofocus></label>
      <label>Repítela <input type="password" name="new2" required minlength="10" autocomplete="new-password"></label>
      <button class="btn" type="submit">Guardar contraseña</button>
    </form>
  <?php endif; ?>
</div>
