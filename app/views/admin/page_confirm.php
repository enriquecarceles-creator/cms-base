<?php /* Confirmar el borrado de una página (sin JavaScript). */ $files = page_files($p); ?>
<div class="head"><h1>Borrar «<?= e($p['title']) ?>»</h1></div>
<div class="confirm danger-box">
  <p>Se va a borrar la página <strong><?= e($p['title']) ?></strong> (<code>/<?= e($p['slug']) ?></code>). <strong>Esto no se puede deshacer.</strong></p>
  <?php if ($files): ?><p class="muted">Sus <?= count($files) ?> foto<?= count($files) === 1 ? '' : 's' ?> se borrarán de <code>uploads/</code> salvo que las use otra cosa de la web.</p><?php endif; ?>
  <?php if (is_admin()): ?><p class="muted">Si dudas, <a href="<?= e(url('/admin/copia')) ?>">descarga una copia de seguridad</a> antes.</p><?php endif; ?>
  <form method="post" action="<?= e(url('/admin/paginas')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="confirm" value="1">
    <div class="buttons">
      <button class="btn danger-solid" type="submit">Sí, borrar la página</button>
      <a class="btn ghost" href="<?= e(url('/admin/paginas')) ?>">Cancelar</a>
    </div>
  </form>
</div>
