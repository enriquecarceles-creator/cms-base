<?php
/** Inicio del panel: cifras, avisos, accesos rápidos y últimos artículos modificados. */
$n = fn(int $v) => number_format($v, 0, ',', '.');
// «42 familias · 3 subfamilias» (singular si es 1)
$famText = $n($stats['families']) . ' ' . term($stats['families'] === 1 ? 'family' : 'families', true)
    . ' · ' . $n($stats['subs']) . ' ' . term($stats['subs'] === 1 ? 'sub' : 'subs', true);
$cards = [
    ['Visibles', $stats['visible'], '/admin/articulos?visible=1', 'en la web', ''],
    ['Ocultos', $stats['hidden'], '/admin/articulos?visible=0', 'no salen en la web', ''],
    [term('families'), $stats['families'], '/admin/categorias', $famText, ''],
    ['Sin foto', $stats['nophoto'], '/admin/articulos?foto=0', 'artículos sin ninguna foto', $stats['nophoto'] ? 'warn' : ''],
];
if ($stats['noprice'] !== null) {
    $cards[] = ['Sin precio', $stats['noprice'], '/admin/articulos?precio=0', 'sin «' . $pf['name'] . '»', $stats['noprice'] ? 'warn' : ''];
}
?>
<div class="head"><h1>Inicio</h1></div>

<section class="stats" aria-label="Cifras del catálogo">
  <?php foreach ($cards as [$label, $value, $href, $sub, $tone]): ?>
    <a class="stat<?= $tone !== '' ? ' ' . $tone : '' ?>" href="<?= e(url($href)) ?>">
      <span class="stat-l"><?= e($label) ?></span>
      <strong class="stat-n"><?= e($n((int)$value)) ?></strong>
      <span class="stat-s"><?= e($sub) ?></span>
    </a>
  <?php endforeach; ?>
  <a class="stat" href="<?= e(url('/admin/importar')) ?>">
    <span class="stat-l">Última importación</span>
    <?php if ($lastImport): ?>
      <strong class="stat-n stat-date"><?= e(date('d/m/Y', (int)strtotime($lastImport['at']))) ?></strong>
      <span class="stat-s"><?= e(trim(($lastImport['source'] ?: $lastImport['origin']) . ' · ' . implode(', ', array_filter([
          $lastImport['created'] ? $n((int)$lastImport['created']) . ' nuevos' : '',
          $lastImport['updated'] ? $n((int)$lastImport['updated']) . ' actualizados' : '',
          $lastImport['photos'] ? $n((int)$lastImport['photos']) . ' fotos' : '',
      ])), ' ·')) ?></span>
    <?php else: ?>
      <strong class="stat-n stat-date">—</strong>
      <span class="stat-s">todavía ninguna</span>
    <?php endif; ?>
  </a>
</section>

<?php if ($alerts): ?>
  <h2>Avisos</h2>
  <ul class="alerts">
    <?php foreach ($alerts as [$tone, $msg, $href, $cta]): ?>
      <li class="alert <?= e($tone) ?>"><?= admin_icon($tone === 'err' ? 'alert' : 'info') ?><span><?= e($msg) ?></span><?php if ($href): ?> <a class="btn small ghost" href="<?= e(url($href)) ?>"><?= e($cta) ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<h2>Accesos rápidos</h2>
<nav class="quick" aria-label="Accesos rápidos">
  <a href="<?= e(url('/admin/articulo/nuevo')) ?>"><?= admin_icon('plus') ?> Nuevo artículo</a>
  <a href="<?= e(url('/admin/importar')) ?>"><?= admin_icon('import') ?> Importar</a>
  <a href="<?= e(url('/admin/categorias')) ?>"><?= admin_icon('folder') ?> <?= e(term('families')) ?></a>
  <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= admin_icon('external') ?> Ver web</a>
  <?php if (is_admin()): ?>
    <form method="post" action="<?= e(url('/admin/configuracion/copia')) ?>" class="inline"><?= csrf_field() ?><button type="submit"><?= admin_icon('download') ?> Descargar copia</button></form>
  <?php endif; ?>
</nav>
<?php if (is_admin()): ?><p class="muted">Última copia descargada: <?= $lastBackup !== '' ? e(date('d/m/Y H:i', (int)strtotime($lastBackup))) : 'nunca' ?>.</p><?php endif; ?>

<h2>Últimos artículos modificados</h2>
<?php if (!$recent): ?>
  <p class="empty">Todavía no hay artículos. <a href="<?= e(url('/admin/articulo/nuevo')) ?>">Crea el primero</a> o <a href="<?= e(url('/admin/importar')) ?>">impórtalos</a>.</p>
<?php else: ?>
  <table class="tbl recent">
    <thead><tr><th scope="col"><span class="sr-only">Foto</span></th><th scope="col">Artículo</th><th scope="col"><?= e(term('family')) ?></th><th scope="col">Modificado</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr<?= $r['visible'] ? '' : ' class="off"' ?>>
        <td class="t-thumb"><?php if ($r['image']): ?><img src="<?= e(img_url($r['image'], true)) ?>" alt="" loading="lazy" width="40" height="40"><?php else: ?><span class="nophoto">sin foto</span><?php endif; ?></td>
        <td><a href="<?= e(url('/admin/articulo/' . $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a><?php if ($r['ref'] !== ''): ?><br><small class="muted">Ref. <?= e($r['ref']) ?></small><?php endif; ?><?= $r['visible'] ? '' : ' <small class="muted">· oculto</small>' ?></td>
        <td><?= e($r['cat_name']) ?></td>
        <td class="t-date"><?= e(date('d/m/Y H:i', (int)strtotime($r['updated_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><a href="<?= e(url('/admin/articulos')) ?>">Ver todos los artículos →</a></p>
<?php endif; ?>
