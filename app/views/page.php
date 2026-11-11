<?php
/* Página propia (/<slug>). $html lo genera md_render(): solo etiquetas de una lista cerrada, nunca HTML del usuario. */
?>
<?php if ($draft): ?><p class="page-draft" role="status"><strong>Borrador:</strong> esta página está oculta y solo la ves tú porque tienes sesión en el panel.
  <a href="<?= e(url('/admin/paginas/' . (int)$p['id'])) ?>">Editar</a></p><?php endif; ?>
<?php if (!empty($p['hero']) && ($hero = img_url($p['hero']))): ?><div class="page-hero" style="background-image:url(<?= e($hero) ?>)" role="img" aria-label="<?= e($p['title']) ?>"></div><?php endif; ?>
<article class="wrap block page-doc">
  <h1><?= e($p['title']) ?></h1>
  <div class="doc"><?= $html ?></div>
</article>
