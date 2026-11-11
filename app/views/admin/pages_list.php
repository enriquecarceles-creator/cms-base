<?php /* Web → Páginas: lista. Ocultar/mostrar y borrar son botones de formulario (con CSRF), sin JavaScript. */ ?>
<div class="head">
  <h1>Páginas <small>(<?= count($rows) ?>)</small></h1>
  <a class="btn" href="<?= e(url('/admin/paginas/nueva')) ?>">+ Nueva página</a>
</div>
<p class="hint">Páginas propias de la web (aviso legal, política de privacidad, «Sobre nosotros»…). Cada una tiene su dirección, por ejemplo <code><?= e(site_origin()) ?><?= e(url('/aviso-legal')) ?></code>. Las visibles salen en el pie de la web y en el mapa del sitio para Google.</p>

<?php if (!$rows): ?>
  <p class="empty">Todavía no hay páginas. <a href="<?= e(url('/admin/paginas/nueva')) ?>">Crea la primera</a>: puedes empezar con una plantilla de aviso legal, privacidad o cookies.</p>
<?php else: ?>
<div class="tbl-wrap">
<table class="tbl list">
  <thead><tr><th scope="col">Título</th><th scope="col">Dirección</th><th scope="col">Estado</th><th scope="col" class="t-num">Orden</th><th scope="col">Modificada</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $review = str_contains((string)$r['body'], PAGE_REVIEW_MARK); ?>
    <tr<?= $r['visible'] ? '' : ' class="off"' ?>>
      <td class="t-name"><a href="<?= e(url('/admin/paginas/' . (int)$r['id'])) ?>"><strong><?= e($r['title']) ?></strong></a>
        <?php if ($review): ?><br><small class="review-flag">Contiene el aviso «<?= e(PAGE_REVIEW_MARK) ?>»: revísala antes de publicar</small><?php endif; ?></td>
      <td><a href="<?= e(url('/' . $r['slug'])) ?>" target="_blank" rel="noopener">/<?= e($r['slug']) ?> ↗</a></td>
      <td>
        <form method="post" action="<?= e(url('/admin/paginas')) ?>" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="pill <?= $r['visible'] ? 'on' : '' ?>" type="submit" title="Mostrar u ocultar en la web"><?= $r['visible'] ? 'Visible' : 'Oculta' ?></button>
        </form>
      </td>
      <td class="t-num"><?= (int)$r['sort'] ?></td>
      <td class="t-date"><?= e(date('d/m/Y', (int)strtotime((string)$r['updated_at']))) ?></td>
      <td class="blk-move">
        <a class="btn small ghost" href="<?= e(url('/admin/paginas/' . (int)$r['id'])) ?>">Editar</a>
        <form method="post" action="<?= e(url('/admin/paginas')) ?>" class="inline">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <button class="btn small danger-btn" type="submit">Borrar</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
