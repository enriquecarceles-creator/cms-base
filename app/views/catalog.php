<?php if (!$current && $q === ''): ?>
<?php $staff = staff_logged_in(); ?>
<section class="wrap block cat-layout">
<?= catalog_nav(null) ?>
<div class="cat-main catindex">
  <div class="catindex-head">
    <div>
      <p class="kicker">Catálogo</p>
      <h1><?= e(setting('catalog_title', tg('Nuestras', 'Nuestros') . ' ' . term('families', true))) ?></h1>
    </div>
    <form class="search" method="get" action="<?= e(url('/catalogo')) ?>" role="search">
      <input type="search" name="q" value="" placeholder="Nombre o referencia" aria-label="Buscar">
      <button class="btn small" type="submit">Buscar</button>
    </form>
  </div>
  <?php if (setting('catalog_intro') !== ''): ?><p class="cat-intro"><?= nl2br(e(setting('catalog_intro'))) ?></p><?php endif; ?>
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
    <nav class="chips" aria-label="<?= e(term('families')) ?>">
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

  <?php if ($filters): ?>
    <form class="filters" method="get" action="<?= e(url('/catalogo' . ($current ? '/' . $current['slug'] : ''))) ?>">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
      <?php foreach ($filters as $fi): $fname = $fi['f']['name']; ?>
        <?php if ($fi['kind'] === 'bool'): ?>
          <label class="check"><input type="checkbox" name="<?= e($fi['name']) ?>" value="1"<?= $fi['value'] === '1' ? ' checked' : '' ?>> <span><?= e($fname) ?></span></label>
        <?php elseif ($fi['kind'] === 'price'): ?>
          <div class="fl-range"><span><?= e($fname) ?><?= $fi['f']['unit'] !== '' ? ' (' . e($fi['f']['unit']) . ')' : '' ?></span>
            <input type="number" name="<?= e($fi['name']) ?>_min" value="<?= e($fi['min']) ?>" min="0" step="any" placeholder="Desde" aria-label="<?= e($fname) ?>: desde">
            <input type="number" name="<?= e($fi['name']) ?>_max" value="<?= e($fi['max']) ?>" min="0" step="any" placeholder="Hasta" aria-label="<?= e($fname) ?>: hasta"></div>
        <?php else: ?>
          <label><span><?= e($fname) ?></span>
            <select name="<?= e($fi['name']) ?>"><option value="">Todos</option>
              <?php foreach ($fi['options'] as $k => $label): ?><option value="<?= e((string)$k) ?>"<?= (string)$k === $fi['value'] ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select></label>
        <?php endif; ?>
      <?php endforeach; ?>
      <button class="btn small" type="submit">Aplicar</button>
      <?php if ($fqs): ?><a class="fl-clear" href="<?= e(url('/catalogo' . ($current ? '/' . $current['slug'] : '')) . ($q !== '' ? '?' . http_build_query(['q' => $q]) : '')) ?>">Quitar filtros</a><?php endif; ?>
    </form>
  <?php endif; ?>

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
    <p class="empty"><?= $fqs ? 'Ningún artículo cumple esos filtros.' : ($q !== '' ? 'No hay artículos que coincidan con «' . e($q) . '».' : 'Todavía no hay artículos en ' . tg('esta', 'este') . ' ' . e(term('family', true)) . '.') ?></p>
  <?php endif; ?>

  <?php foreach ($groups as $slug => $g): ?>
    <h2 id="<?= e($slug) ?>"><?= $current && !$q ? '' : e($g['name']) ?></h2>
    <div class="grid">
      <?php foreach ($g['items'] as $p): ?><?= product_card($p) ?><?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if ($pages > 1):
      $base = '/catalogo' . ($current ? '/' . $current['slug'] : '');
      $pg = fn(int $n) => url($base) . (($q !== '' || $fqs || $n > 1) ? '?' . http_build_query(array_filter(['q' => $q] + $fqs + ['pag' => $n > 1 ? $n : null], fn($v) => $v !== null && $v !== '')) : ''); ?>
    <nav class="cat-pager" aria-label="Páginas del catálogo">
      <?php if ($page > 1): ?><a href="<?= e($pg($page - 1)) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
      <?php for ($n = 1; $n <= $pages; $n++): if ($n === $page): ?><span class="cur" aria-current="page"><?= $n ?></span><?php elseif ($n <= 2 || $n > $pages - 2 || abs($n - $page) <= 2): ?><a href="<?= e($pg($n)) ?>"><?= $n ?></a><?php elseif ($n === 3 || $n === $pages - 2): ?><span>…</span><?php endif; endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= e($pg($page + 1)) ?>" rel="next">Siguiente ›</a><?php endif; ?>
    </nav>
  <?php endif; ?>

</div>
</section>
