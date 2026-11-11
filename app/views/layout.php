<?php
$title = $title ?? site_name();
$desc = $desc ?? '';
$phone = setting('phone');
$path = $_SERVER['REQUEST_URI'] ?? '';
$isCat = str_contains($path, '/catalogo') || str_contains($path, '/producto');
$isContact = str_contains($path, '/contacto');
?><!doctype html>
<html lang="<?= e(setting('lang', 'es')) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?></title>
<?php if ($desc !== ''): ?><meta name="description" content="<?= e($desc) ?>"><?php endif; ?>
<?php if (!empty($noindex) || setting('noindex') === '1'): ?><meta name="robots" content="noindex,follow"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($title) ?>">
<?php if ($desc !== ''): ?><meta property="og:description" content="<?= e($desc) ?>"><?php endif; ?>
<?php if (!empty($ogImage)): ?><meta property="og:image" content="<?= e($ogImage) ?>"><?php endif; ?>
<meta name="theme-color" content="#ffffff">
<?php if ($fav = brand_favicon()): ?><link rel="icon" href="<?= e($fav) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
<?php if ($bc = brand_color_css()): ?><style><?= $bc ?></style><?php endif; ?>
</head>
<body>
<header class="site-header">
  <div class="wrap bar">
    <a class="brand<?= brand_logo() ? ' stack' : '' ?>" href="<?= e(url('/')) ?>"><?php if ($lg = brand_logo()): ?><img class="logo" src="<?= e($lg) ?>" alt="" ><?php endif; ?><span class="brand-text"><?= e(mb_strtoupper(site_name())) ?><?php if (setting('brand_sub') !== ''): ?><small><?= e(setting('brand_sub')) ?></small><?php endif; ?></span></a>
    <nav aria-label="Principal">
      <a href="<?= e(url('/')) ?>"<?= $path === url('/') || $path === url('') ? ' aria-current="page"' : '' ?>>Inicio</a>
      <a href="<?= e(url('/catalogo')) ?>"<?= $isCat ? ' aria-current="page"' : '' ?>>Catálogo</a>
      <a href="<?= e(url('/contacto')) ?>"<?= $isContact ? ' aria-current="page"' : '' ?>>Contacto</a>
    </nav>
    <?php if ($phone !== ''): ?><a class="tel" href="<?= e(tel_href($phone)) ?>"><svg class="ico" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1A17 17 0 0 1 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.5.6 3.6.1.3 0 .7-.2 1z"/></svg><?= e($phone) ?></a><?php endif; ?>
  </div>
</header>

<main>
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap foot">
    <div>
      <strong><?= e(setting('company_name')) ?></strong><br>
      <?= nl2br(e(setting('address'))) ?>
      <?php if (setting('tax_id') !== ''): ?><br>CIF/NIF: <?= e(setting('tax_id')) ?><?php endif; ?>
    </div>
    <div>
      <?php if ($phone !== ''): ?>Tel. <a href="<?= e(tel_href($phone)) ?>"><?= e($phone) ?></a><br><?php endif; ?>
      <?php if (setting('whatsapp') !== ''): ?><a href="https://wa.me/<?= e(preg_replace('/\D/', '', setting('whatsapp'))) ?>" target="_blank" rel="noopener">WhatsApp</a><br><?php endif; ?>
      <?php if (setting('email') !== ''): ?><a href="mailto:<?= e(setting('email')) ?>"><?= e(setting('email')) ?></a><?php endif; ?>
    </div>
    <div class="muted"><?= e(setting('tagline')) ?><?= setting('tagline') !== '' ? '<br>' : '' ?>© <?= date('Y') ?> <?= e(site_name()) ?>
      <?php $soc = array_filter(['Facebook' => setting('facebook'), 'Instagram' => setting('instagram'), 'LinkedIn' => setting('linkedin')]); ?>
      <?php if ($soc): ?><br><?php foreach ($soc as $n => $u): ?><a href="<?= e($u) ?>" target="_blank" rel="noopener"><?= e($n) ?></a> <?php endforeach; ?><?php endif; ?>
    </div>
  </div>
  <?php if ($fp = pages_visible()): ?><nav class="wrap foot-pages" aria-label="Información legal"><?php foreach ($fp as $pg): ?><a href="<?= e(url('/' . $pg['slug'])) ?>"><?= e($pg['title']) ?></a><?php endforeach; ?></nav><?php endif; ?>
  <?php if ($bn = brand_banner()): ?><div class="wrap foot-eu"><img src="<?= e($bn) ?>" alt="<?= e(setting('footer_banner_alt')) ?>" height="103" loading="lazy"></div><?php endif; ?>
</footer>
</body>
</html>
