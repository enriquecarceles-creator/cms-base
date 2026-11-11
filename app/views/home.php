<?php $hero = setting('hero_image'); ?>
<?php if (!empty($slides) && feature('slider')): $fx = setting('slider_effect', 'fade'); $secs = max(3, min(15, (int)setting('slider_seconds', '6'))); ?>
<h1 class="sr"><?= e(site_name()) ?><?= setting('tagline') !== '' ? ' · ' . e(setting('tagline')) : '' ?></h1>
<?php $plain = true; foreach ($slides as $sl0) { if ($sl0['title'] !== '' || $sl0['text'] !== '' || $sl0['btn'] !== '') { $plain = false; } } ?>
<section class="slider fx-<?= e($fx) ?><?= $plain ? ' plain' : '' ?>" data-secs="<?= $secs ?>" aria-roledescription="carousel" aria-label="Destacados">
  <?php foreach ($slides as $i => $sl):
      $href = $sl['link'] === '' ? '' : ($sl['link'][0] === '/' ? url($sl['link']) : $sl['link']); ?>
    <div class="slide<?= $i === 0 ? ' is-on' : '' ?><?= ($sl['title'] !== '' || $sl['text'] !== '' || ($href !== '' && $sl['btn'] !== '')) ? ' has-txt' : '' ?>" role="group" aria-roledescription="diapositiva" aria-label="<?= $i + 1 ?> de <?= count($slides) ?>">
      <img src="<?= e(img_url($sl['file'])) ?>" alt="<?= e($sl['title']) ?>" width="2000" height="800"<?= $i === 0 ? ' fetchpriority="high"' : ' loading="lazy"' ?>>
      <?php if ($sl['title'] !== '' || $sl['text'] !== '' || ($href !== '' && $sl['btn'] !== '')): ?>
        <div class="slide-txt wrap">
          <?php if ($sl['title'] !== ''): ?><h2><?= e($sl['title']) ?></h2><?php endif; ?>
          <?php if ($sl['text'] !== ''): ?><p><?= e($sl['text']) ?></p><?php endif; ?>
          <?php if ($href !== '' && $sl['btn'] !== ''): ?><a class="btn" href="<?= e($href) ?>"><?= e($sl['btn']) ?></a><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (count($slides) > 1): ?>
    <button class="sl-nav sl-prev" type="button" aria-label="Anterior">‹</button>
    <button class="sl-nav sl-next" type="button" aria-label="Siguiente">›</button>
    <div class="sl-dots"><?php foreach ($slides as $i => $sl): ?><button type="button" aria-label="Ir a la diapositiva <?= $i + 1 ?>"<?= $i === 0 ? ' class="on"' : '' ?>></button><?php endforeach; ?></div>
    <script>
    (function(){var s=document.currentScript.parentNode,L=s.querySelectorAll('.slide'),D=s.querySelectorAll('.sl-dots button'),i=0,t,
    secs=(+s.dataset.secs||6)*1000,reduce=window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function go(n,dir){n=(n+L.length)%L.length;if(n===i)return;s.dataset.dir=dir||'next';
      L[i].classList.remove('is-on');L[i].classList.add('was');var o=L[i];setTimeout(function(){o.classList.remove('was')},900);
      i=n;L[i].classList.add('is-on');D.forEach(function(d,k){d.classList.toggle('on',k===i)});}
    function play(){stop();if(!reduce)t=setInterval(function(){go(i+1)},secs)}function stop(){clearInterval(t)}
    s.querySelector('.sl-next').onclick=function(){go(i+1);play()};s.querySelector('.sl-prev').onclick=function(){go(i-1,'prev');play()};
    D.forEach(function(d,k){d.onclick=function(){go(k,k<i?'prev':'next');play()}});
    s.addEventListener('mouseenter',stop);s.addEventListener('mouseleave',play);s.addEventListener('focusin',stop);s.addEventListener('focusout',play);
    var x0=null;s.addEventListener('touchstart',function(e){x0=e.touches[0].clientX},{passive:true});
    s.addEventListener('touchend',function(e){if(x0===null)return;var dx=e.changedTouches[0].clientX-x0;x0=null;if(Math.abs(dx)>40){dx<0?go(i+1):go(i-1,'prev');play()}});
    play();})();
    </script>
  <?php endif; ?>
</section>
<?php else:
  $shots = array_slice(array_values(array_filter($cats, fn($c) => !empty($c['cover']))), 0, 3); ?>
<section class="welcome<?= $hero ? ' has-img' : '' ?>"<?= $hero ? ' style="background-image:url(' . e(img_url($hero)) . ')"' : '' ?>>
  <div class="wrap welcome-in">
    <div class="welcome-txt">
      <p class="eyebrow"><?= e(setting('tagline')) ?></p>
      <h1><?= e(setting('home_hello', 'Bienvenido a')) ?> <span><?= e(site_name()) ?></span></h1>
      <?php if (setting('home_lead') !== ''): ?><p class="lead"><?= e(setting('home_lead')) ?></p><?php endif; ?>
      <p class="welcome-btns"><a class="btn" href="<?= e(url('/catalogo')) ?>">Ver el catálogo</a>
         <a class="btn ghost" href="<?= e(url('/contacto')) ?>">Contacto</a></p>
    </div>
    <?php if (!$hero && $shots): ?>
    <div class="welcome-pics" aria-hidden="true">
      <?php foreach ($shots as $i => $c): ?>
        <a class="wp wp<?= $i + 1 ?>" href="<?= e(url('/catalogo/' . $c['slug'])) ?>" tabindex="-1"><?= fit_img($c['cover'], '', 90, 1.0, false) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>

<?php $cards = json_decode(setting('home_cards') ?: '[]', true) ?: []; $cards = array_values(array_filter($cards, fn($c) => trim((string)($c[0] ?? '')) !== '')); ?>
<?php if (setting('home_text') !== '' || setting('home_title') !== '' || $cards): ?>
<section class="wrap nosotros"<?= $cards ? '' : ' style="grid-template-columns:1fr"' ?>>
  <div class="nosotros-txt">
    <p class="eyebrow">Quiénes somos</p>
    <?php if (setting('home_title') !== ''): ?><h2><?= e(setting('home_title')) ?></h2><?php endif; ?>
    <p><?= nl2br(e(setting('home_text'))) ?></p>
  </div>
  <?php if ($cards): ?>
  <div class="nosotros-facts pillars">
    <?php foreach ($cards as $c): ?><div><strong><?= e($c[0]) ?></strong><span><?= e($c[1] ?? '') ?></span></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($cats): ?>
<section class="wrap chips-sec">
  <p class="eyebrow"><?= e(setting('home_chips_title') !== '' ? setting('home_chips_title') : 'Nuestros productos') ?></p>
  <p class="chips-row"><?php foreach ($cats as $c): ?><a class="chip-big" href="<?= e(url('/catalogo/' . $c['slug'])) ?>"><?= e($c['name']) ?></a><?php endforeach; ?></p>
</section>
<?php endif; ?>

<section class="cta">
  <div class="wrap cta-in">
    <div>
      <h2><?= e(setting('cta_title', '¿Hablamos?')) ?></h2>
      <p><?= e(setting('cta_text', 'Pídenos información, precios o presupuesto.')) ?></p>
    </div>
    <p>
      <a class="btn" href="<?= e(url('/contacto')) ?>">Contactar</a>
      <?php if (setting('phone') !== ''): ?><a class="btn ghost" href="<?= e(tel_href(setting('phone'))) ?>"><?= e(setting('phone')) ?></a><?php endif; ?>
    </p>
  </div>
</section>
