<section class="wrap block">
  <p class="crumbs"><a href="<?= e(url('/catalogo')) ?>">Catálogo</a> › <?php if ($p['parent_slug']): ?><a href="<?= e(url('/catalogo/' . $p['parent_slug'])) ?>"><?= e($p['parent_name']) ?></a> › <?php endif; ?><a href="<?= e(url('/catalogo/' . $p['cat_slug'])) ?>"><?= e($p['parent_slug'] ? mb_substr($p['cat_name'], mb_strlen($p['parent_name']) + 3) : $p['cat_name']) ?></a></p>
  <div class="detail">
    <div class="gallery">
      <div class="ph big">
        <?php if ($gallery): ?>
          <img id="mainimg" src="<?= e(img_url($gallery[0]['file'])) ?>" alt="<?= e($p['name']) ?>" width="1400" height="1400" fetchpriority="high">
        <?php else: ?><?= placeholder_svg() ?><?php endif; ?>
      </div>
      <?php if (count($gallery) > 1): ?>
        <div class="thumbs">
          <?php foreach ($gallery as $i => $g): ?>
            <a href="<?= e(img_url($g['file'])) ?>"<?= $i === 0 ? ' class="on"' : '' ?>><img src="<?= e(img_url($g['file'], true)) ?>" alt="<?= e($p['name']) ?> (foto <?= $i + 1 ?>)" loading="lazy" width="72" height="72"></a>
          <?php endforeach; ?>
        </div>
        <script>
        (function(){var m=document.getElementById('mainimg'),t=document.querySelectorAll('.thumbs a');
        t.forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();m.src=a.href;
        t.forEach(function(x){x.classList.remove('on')});a.classList.add('on');});});})();
        </script>
      <?php endif; ?>
    </div>
    <div class="info">
      <?php $sk = feature('sketch') ? sketch_svg((string)$p['dimensions'], ($p['shape'] ?? '') === 'ranura', (string)($p['shape'] ?? '')) : null; ?>
      <div class="info-head<?= $sk ? ' has-sketch' : '' ?>">
      <div>
      <h1><?= e($p['name']) ?></h1>
      <dl class="specs">
        <?php if ($p['ref'] !== ''): ?><dt>Referencia</dt><dd><?= e($p['ref']) ?></dd><?php endif; ?>
        <?php if ($p['dimensions'] !== ''): ?><dt>Medidas</dt><dd><?= e($p['dimensions']) ?></dd><?php endif; ?>
        <dt>Familia</dt><dd><?= e($p['cat_name']) ?></dd>
        <?php foreach (product_public_fields((int)$p['id']) as [$cf, $cv]): if ($cf['kind'] === 'textarea') continue; ?>
          <dt><?= e($cf['name']) ?></dt>
          <dd<?= $cf['kind'] === 'price' ? ' class="price"' : '' ?>><?php if ($cf['kind'] === 'url'): ?><a href="<?= e($cv) ?>" target="_blank" rel="noopener">Ver ↗</a><?php else: ?><?= e(field_format($cf, $cv)) ?><?php endif; ?></dd>
        <?php endforeach; ?>
      </dl>
      </div>
      <?php if ($sk): ?><figure class="sketch-fig"><?= $sk ?><figcaption>Alto × ancho</figcaption></figure><?php endif; ?>
      </div>
      <?php if ($p['description'] !== ''): ?><div class="desc"><?= nl2br(e($p['description'])) ?></div><?php endif; ?>
      <?php foreach (product_public_fields((int)$p['id']) as [$cf, $cv]): if ($cf['kind'] !== 'textarea') continue; ?>
        <div class="desc"><h2 class="desc-h"><?= e($cf['name']) ?></h2><?= nl2br(e($cv)) ?></div>
      <?php endforeach; ?>
      <p>
        <?php $subject = rawurlencode('Consulta: ' . $p['name'] . ($p['ref'] !== '' ? ' (Ref. ' . $p['ref'] . ')' : '')); ?>
        <?php if (setting('email') !== ''): ?><a class="btn" href="mailto:<?= e(setting('email')) ?>?subject=<?= $subject ?>">Pedir información</a><?php endif; ?>
        <?php if (setting('whatsapp') !== ''): ?><a class="btn ghost" href="https://wa.me/<?= e(preg_replace('/\D/', '', setting('whatsapp'))) ?>?text=<?= $subject ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
        <a class="btn ghost" href="<?= e(url('/contacto')) ?>"><?= feature('reps') && $hasReps ? 'Ver representantes' : 'Contacto' ?></a>
      </p>
    </div>
  </div>
  <?php if ($gallery): ?>
  <div id="lb" class="lb" hidden role="dialog" aria-modal="true" aria-label="Foto ampliada">
    <button type="button" class="lb-x" aria-label="Cerrar">×</button>
    <button type="button" class="lb-n lb-prev" aria-label="Anterior">‹</button>
    <div class="lb-st"><img id="lbimg" alt="<?= e($p['name']) ?>"></div>
    <button type="button" class="lb-n lb-next" aria-label="Siguiente">›</button>
  </div>
  <script>
  (function(){
    var files=<?= json_encode(array_map(fn($g) => img_url($g['file']), $gallery)) ?>,i=0,
        lb=document.getElementById('lb'),im=document.getElementById('lbimg'),main=document.getElementById('mainimg');
    function show(n){i=(n+files.length)%files.length;im.src=files[i];lb.classList.remove('z');im.style.transformOrigin='';}
    function open(){var c=files.indexOf(main.getAttribute('src'));show(c<0?0:c);lb.hidden=false;document.body.style.overflow='hidden';}
    function close(){lb.hidden=true;document.body.style.overflow='';}
    main.style.cursor='zoom-in';main.addEventListener('click',open);
    lb.querySelector('.lb-x').onclick=close;
    lb.querySelector('.lb-prev').onclick=function(e){e.stopPropagation();show(i-1)};
    lb.querySelector('.lb-next').onclick=function(e){e.stopPropagation();show(i+1)};
    if(files.length<2){lb.querySelector('.lb-prev').hidden=lb.querySelector('.lb-next').hidden=true}
    im.addEventListener('click',function(e){e.stopPropagation();var r=im.getBoundingClientRect();
      im.style.transformOrigin=((e.clientX-r.left)/r.width*100)+'% '+((e.clientY-r.top)/r.height*100)+'%';lb.classList.toggle('z')});
    lb.addEventListener('click',function(e){if(e.target===lb||e.target.classList.contains('lb-st'))close()});
    document.addEventListener('keydown',function(e){if(lb.hidden)return;
      if(e.key==='Escape')close();else if(e.key==='ArrowLeft')show(i-1);else if(e.key==='ArrowRight')show(i+1)});
  })();
  </script>
  <?php endif; ?>

  <?php if ($related): ?>
    <h2>Más de <?= e(mb_strtolower($p['family_name'])) ?></h2>
    <div class="grid">
      <?php foreach ($related as $r): ?><?= product_card($r) ?><?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
