<div class="head"><h1><?= e(term('families')) ?></h1></div>
<p class="hint"><?= e(tg('Las', 'Los')) ?> <?= e(term('families', true)) ?> son los grupos del catálogo (por ejemplo «Sillas», «Mesas»…) y cada <?= e(tg('una', 'uno')) ?> puede tener <strong><?= e(term('subs', true)) ?></strong> (por ejemplo Sillas › Plegables). Solo se puede borrar <?= e(tg('una', 'un')) ?> <?= e(term('family', true)) ?> si no tiene artículos ni <?= e(term('subs', true)) ?>.</p>

<?php
function cat_parent_select(array $roots, int $self, ?int $current): string
{
    $o = '<option value="0"' . (!$current ? ' selected' : '') . '>— ' . e(tg('Ninguna', 'Ninguno')) . ' (' . e(term('family', true)) . ' principal)</option>';
    foreach ($roots as $r) {
        if ((int)$r['id'] === $self) { continue; }
        $o .= '<option value="' . (int)$r['id'] . '"' . ($current === (int)$r['id'] ? ' selected' : '') . '>' . e($r['name']) . '</option>';
    }
    return '<select name="parent_id">' . $o . '</select>';
}
?>
<div class="table">
  <?php foreach ($rows as $r): $sub = (bool)$r['parent_id']; ?>
    <form method="post" class="row edit" style="<?= $sub ? 'margin-left:22px' : '' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <label class="grow"><?= $sub ? '↳ ' . e(term('sub')) : 'Nombre' ?> <input name="name" value="<?= e($r['name']) ?>" required maxlength="80"></label>
      <?php if ($r['nkids'] > 0): ?>
        <input type="hidden" name="parent_id" value="0">
      <?php else: ?>
        <label>Dentro de <?= cat_parent_select($roots, (int)$r['id'], $r['parent_id'] ? (int)$r['parent_id'] : null) ?></label>
      <?php endif; ?>
      <label class="narrow-in">Orden <input type="number" name="sort" value="<?= (int)$r['sort'] ?>"></label>
      <span class="muted"><?= (int)$r['n'] ?> art.<?= $r['nkids'] > 0 ? ' · ' . (int)$r['nkids'] . ' sub.' : '' ?></span>
      <a class="btn small ghost" href="<?= e(url('/admin/portada/' . (int)$r['id'])) ?>">Foto de portada</a>
      <button class="btn small" name="action" value="save" type="submit">Guardar</button>
      <button class="btn small danger-btn" name="action" value="delete" type="submit" onclick="return confirm('¿Eliminar «<?= e(addslashes($r['name'])) ?>»?')">Borrar</button>
    </form>
  <?php endforeach; ?>
</div>

<h2>Añadir <?= e(term('family', true)) ?> o <?= e(term('sub', true)) ?></h2>
<form method="post" class="row edit">
  <?= csrf_field() ?>
  <label class="grow">Nombre <input name="name" required maxlength="80" placeholder="Ej.: Latas decorativas"></label>
  <label>Dentro de <?= cat_parent_select($roots, 0, null) ?></label>
  <label class="narrow-in">Orden <input type="number" name="sort" value="<?= count($rows) * 10 + 10 ?>"></label>
  <button class="btn small" name="action" value="add" type="submit">Añadir</button>
</form>
