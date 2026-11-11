<?php if (!$current && $q === ''): ?>
<?php $staff = staff_logged_in(); ?>
<section class="wrap block cat-layout">
<?= catalog_nav(null) ?>
<div class="cat-main catindex">
  <div class="catindex-head">
    <div>
      <p class="kicker">Catálogo</p>
      <h1><?= e(setting('catalog_title', 'Nuestras familias')) ?></h1>
    </div>
    <form class="search" method="get" action="<?= e(url('/catalogo')) ?>" role="search">
      <input type="search" name="q" value="" placeholder="Nombre o referencia" aria-label="Buscar">
      <button class="btn small" type="submit">Buscar</button>
    </form>
  </div>
  <div class="catgrid">
    <?php foreach ($cats as $c): ?>
      <div class="cg-w">
      <?php if ($staff): ?><a class="cg-edit" href="<?= e(url('/admin/portada/' . (int)$c['id'])) ?>">Cambiar foto</a><?php endif; ?>
      <a class="cg" href="<?= e(url('/catalogo/' . $c['slug'])) ?>">
        <span class="cg-img"><?php if (!empty($c['cover'])): ?><?= fit_img($c['cover'], '', 80, 4 / 3) ?><?php else: ?><?= placeholder_svg() ?><?php endif; ?></span>
        <span class="cg-meta"><span class="cg-name"><?= e($c['name']) ?></span><span class="cg-n"><?= (int)$c['n'] ?> artículo<?= (int)$c['n'] === 1 ? '' : 's' ?> <i aria-hidden="true">→</i></span></span>
      </a>
      </div>
    <?php endforeach; ?>
  </div>
</div>
</section>
<?php return; endif; ?>
<section class="wrap block cat-layout">
<?= catalog_nav($current ?? null) ?>
<div class="cat-main">
  <div class="sec-head" style="margin-top:8px">
    <p class="kicker"><?php if ($parent): ?><a href="<?= e(url('/catalogo')) ?>" style="text-decoration:none">Catálogo</a> › <a href="<?= e(url('/catalogo/' . $parent['slug'])) ?>" style="text-decoration:none"><?= e($parent['name']) ?></a><?php else: ?>Catálogo<?php endif; ?></p>
    <h1><?= $current ? e($current['name']) : 'Todos los artículos' ?></h1>
  </div>

  <div class="toolbar">
    <nav class="chips" aria-label="Familias">
      <a href="<?= e(url('/catalogo')) ?>"<?= !$current ? ' class="on"' : '' ?>>Todo</a>
      <?php foreach ($cats as $c): ?>
        <a href="<?= e(url('/catalogo/' . $c['slug'])) ?>"<?= $current && ($current['id'] === $c['id'] || $current['parent_id'] === $c['id']) ? ' class="on"' : '' ?>><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </nav>
    <form class="search" method="get" action="<?= e(url('/catalogo' . ($current ? '/' . $current['slug'] : ''))) ?>" role="search">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre o referencia" aria-label="Buscar">
      <button class="btn small" type="submit">Buscar</button>
    </form>
  </div>

  <?php if (count($subs) >= 2 && feature('lineup')): ?>
    <?php
      $lu = lineup_items($subs);
      $sumw = 0;
      foreach ($lu as $x) { $sumw += ($x['mm'] > 0 ? $x['mm'] : 120) * $x['box']['ratio']; }
    ?>
    <div class="lineup" role="list" style="--sumw:<?= e((string)round($sumw, 2)) ?>;--n:<?= count($lu) ?>">
      <?php foreach ($lu as $c): $bx = $c['box']; ?>
        <a class="lu" role="listitem" href="<?= e(url('/catalogo/' . $c['slug'])) ?>" style="--mm:<?= e((string)($c['mm'] > 0 ? $c['mm'] : 120)) ?>;--r:<?= e((string)$bx['ratio']) ?>">
          <span class="lu-pic"><?php if (!empty($c['cover'])): ?><img src="<?= e(img_url($c['cover'])) ?>" alt="<?= e($c['lu_label']) ?>" loading="lazy" width="700" height="700" style="width:<?= round(100 / $bx['w'], 3) ?>%;height:<?= round(100 / $bx['h'], 3) ?>%;left:-<?= round($bx['l'] / $bx['w'] * 100, 3) ?>%;top:-<?= round($bx['t'] / $bx['h'] * 100, 3) ?>%"><?php else: ?><?= placeholder_svg() ?><?php endif; ?></span>
          <span class="lu-name"><?= e($c['lu_label']) ?></span>
          <?php if ($c['lu_dims'] !== ''): ?><span class="lu-dims"><?= e($c['lu_dims']) ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($staff2 = staff_logged_in())): ?><p class="cg-staff"><span>Cambiar foto:</span> <?php foreach ($lu as $c): ?><a href="<?= e(url('/admin/portada/' . (int)$c['id'])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?></p><?php endif; ?>
  <?php elseif ($subs): ?>
    <div class="grid cats"><?php foreach ($subs as $c): ?><?= category_card($c) ?><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if (!$groups && !$subs): ?>
    <p class="empty"><?= $q !== '' ? 'No hay artículos que coincidan con «' . e($q) . '».' : 'Todavía no hay artículos en esta familia.' ?></p>
  <?php endif; ?>

  <?php foreach ($groups as $slug => $g): ?>
    <h2 id="<?= e($slug) ?>"><?= $current && !$q ? '' : e($g['name']) ?></h2>
    <div class="grid">
      <?php foreach ($g['items'] as $p): ?><?= product_card($p) ?><?php endforeach; ?>
    </div>
  <?php endforeach; ?>

</div>
</section>
