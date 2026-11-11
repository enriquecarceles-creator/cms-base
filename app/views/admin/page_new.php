<?php /* Nueva página: plantillas legales (si faltan) o en blanco. */ ?>
<div class="head"><h1>Nueva página</h1><a href="<?= e(url('/admin/paginas')) ?>">← Volver a Páginas</a></div>
<p class="hint"><?= $first ? 'Esta es tu primera página. ' : '' ?>Puedes empezar con una <strong>plantilla legal</strong>, ya rellena con los datos de <a href="<?= e(url('/admin/empresa')) ?>">Empresa</a>, o crear una página en blanco.</p>

<p class="alert warn"><?= admin_icon('info') ?><span><strong>Las plantillas son un borrador general, no asesoramiento legal.</strong> Se crean <strong>ocultas</strong> y con un aviso al principio del texto: revísalas (idealmente con un profesional), completa lo que ponga <code>[completar]</code> y borra el aviso antes de publicarlas.</span></p>

<div class="tpl-grid">
  <?php foreach ($tpls as $key => $t): ?>
    <form method="post" action="<?= e(url('/admin/paginas')) ?>" class="tpl">
      <?= csrf_field() ?><input type="hidden" name="action" value="template"><input type="hidden" name="tpl" value="<?= e($key) ?>">
      <h2><?= e($t[0]) ?></h2>
      <p class="muted">Dirección: <code>/<?= e($t[2]) ?></code></p>
      <button class="btn" type="submit">Crear desde la plantilla</button>
    </form>
  <?php endforeach; ?>
  <div class="tpl">
    <h2>En blanco</h2>
    <p class="muted">Una página vacía para escribir lo que quieras.</p>
    <a class="btn ghost" href="<?= e(url('/admin/paginas/nueva?en-blanco=1')) ?>">Crear en blanco</a>
  </div>
</div>
