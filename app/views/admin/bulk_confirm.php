<?php
/** Confirmación de una acción en bloque sobre artículos (siempre para «todos los del filtro» y para borrar). */
$fmt = fn(int $v) => number_format($v, 0, ',', '.');
$what = $fmt($n) . ' artículo' . ($n === 1 ? '' : 's');
$titles = [
    'hide' => 'Ocultar ' . $what,
    'show' => 'Mostrar ' . $what,
    'move' => 'Mover ' . $what . ' a «' . $targetName . '»',
    'delete' => 'Borrar ' . $what,
];
$listUrl = admin_list_url($back);
?>
<div class="head"><h1><?= e($titles[$op]) ?></h1></div>

<div class="confirm<?= $op === 'delete' ? ' danger-box' : '' ?>">
  <p>Se van a <?= ['hide' => 'ocultar', 'show' => 'mostrar', 'move' => 'mover', 'delete' => 'borrar'][$op] ?> <strong><?= e($what) ?></strong>
    <?= $scope === 'all' ? '(todos los que cumplen el filtro de la lista)' : '(los que has marcado)' ?>.</p>
  <?php if ($sample): ?>
    <p class="muted">Por ejemplo: <?= e(implode(', ', array_map(fn($r) => $r['name'] . ($r['ref'] !== '' ? ' (' . $r['ref'] . ')' : ''), $sample))) ?><?= $n > count($sample) ? '…' : '' ?></p>
  <?php endif; ?>

  <?php if ($op === 'delete'): ?>
    <p><strong>Esto no se puede deshacer.</strong> Se borran los artículos, sus datos y sus fotos de <code>uploads/</code> (salvo las fotos que use otro artículo, una <?= e(term('family', true)) ?> o el slider).</p>
    <p class="alert warn"><?= admin_icon('info') ?><span>Te recomendamos <strong>descargar una copia de seguridad antes</strong>.</span>
      <?php if (is_admin()): ?>
        <button class="btn small ghost" type="submit" form="copia">Descargar copia ahora</button>
      <?php else: ?>
        <span>Pídesela a un administrador.</span>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/admin/articulos')) ?>" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="bulk">
    <input type="hidden" name="op" value="<?= e($op) ?>">
    <input type="hidden" name="scope" value="<?= e($scope) ?>">
    <input type="hidden" name="target" value="<?= (int)$target ?>">
    <input type="hidden" name="back" value="<?= e(http_build_query($back)) ?>">
    <input type="hidden" name="expected" value="<?= (int)$n ?>">
    <input type="hidden" name="confirm" value="1">
    <?php foreach ($ids as $id): ?><input type="hidden" name="ids[]" value="<?= (int)$id ?>"><?php endforeach; ?>
    <?php if ($needType): ?>
      <label>Para confirmar, escribe el número de artículos que se van a borrar (<?= (int)$n ?>)
        <input name="typed" inputmode="numeric" autocomplete="off" required pattern="[0-9]*" style="max-width:200px"></label>
    <?php endif; ?>
    <div class="buttons">
      <button class="btn<?= $op === 'delete' ? ' danger-solid' : '' ?>" type="submit"><?= e($op === 'delete' ? 'Sí, borrar ' . $what : 'Sí, ' . mb_strtolower($titles[$op])) ?></button>
      <a class="btn ghost" href="<?= e(url($listUrl)) ?>">Cancelar</a>
    </div>
  </form>
  <?php if ($op === 'delete' && is_admin()): ?>
    <form id="copia" method="post" action="<?= e(url('/admin/configuracion/copia')) ?>" hidden><?= csrf_field() ?></form>
  <?php endif; ?>
</div>
