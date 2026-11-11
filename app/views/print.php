<?php
$pdfIcon = '<svg class="pdf-ico" viewBox="0 0 32 32" width="22" height="22" aria-hidden="true"><path d="M7 2h13l7 7v21H7z" fill="#e2231a"/><path d="M20 2l7 7h-7z" fill="#f59a95"/><text x="16" y="24" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="8.5" fill="#fff">PDF</text></svg>';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="print-doc cols-<?= (int)$cols ?>">
<div class="noprint pf">
  <div class="pf-top">
    <a href="<?= e(url('/catalogo')) ?>">← Volver al catálogo</a>
    <button class="btn pf-pdf" type="button" onclick="printWhenReady()"><?= $pdfIcon ?> Descargar PDF</button>
  </div>
  <h1>Catálogo en PDF</h1>
  <p class="muted">Elige qué familias incluir y pulsa «Aplicar». Después, «Descargar PDF» y elige «Guardar como PDF» en la ventana de impresión (activa «Gráficos de fondo» para conservar el color).</p>
  <form method="get" class="pf-form">
    <input type="hidden" name="go" value="1">
    <div class="pf-fams">
      <?php foreach ($tree as $t): $c = $t['c']; ?>
        <fieldset>
          <label class="pf-root"><input type="checkbox" name="f[]" value="<?= (int)$c['id'] ?>"<?= (!$filtered || in_array((int)$c['id'], $sel, true)) ? ' checked' : '' ?> data-root><?= e($c['name']) ?></label>
          <?php foreach ($t['kids'] as $k): ?>
            <label class="pf-sub"><input type="checkbox" name="f[]" value="<?= (int)$k['id'] ?>"<?= (!$filtered || in_array((int)$k['id'], $sel, true)) ? ' checked' : '' ?>><?= e($k['name']) ?></label>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>
    </div>
    <div class="pf-opts">
      <label><input type="checkbox" name="cover" value="1"<?= $opt['cover'] ? ' checked' : '' ?>> Fotos</label>
      <label><input type="checkbox" name="ref" value="1"<?= $opt['ref'] ? ' checked' : '' ?>> Referencia</label>
      <label><input type="checkbox" name="dims" value="1"<?= $opt['dims'] ? ' checked' : '' ?>> Medidas</label>
      <label>Columnas <select name="cols"><?php foreach ([2, 3, 4] as $n): ?><option<?= $n === $cols ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></label>
      <button class="btn small" type="submit">Aplicar</button>
      <a class="pf-all" href="<?= e(url('/catalogo/completo')) ?>">Todo</a>
      <span class="muted"><?= (int)$total ?> artículos</span>
    </div>
  </form>
</div>
<script>
function printWhenReady(){var im=[].slice.call(document.images).filter(function(i){return !i.complete});if(!im.length){window.print();return}var n=im.length,t=setTimeout(function(){window.print()},8000);im.forEach(function(i){i.loading='eager';var d=function(){if(--n<=0){clearTimeout(t);window.print()}};i.addEventListener('load',d);i.addEventListener('error',d)})}
window.addEventListener('beforeprint',function(){document.images.length&&[].forEach.call(document.images,function(i){i.loading='eager'})});
document.querySelectorAll('[data-root]').forEach(function(r){r.addEventListener('change',function(){r.closest('fieldset').querySelectorAll('.pf-sub input').forEach(function(i){i.checked=r.checked})})});
</script>

<div class="cover">
  <?php if ($lg = brand_logo()): ?><img class="cover-logo" src="<?= e($lg) ?>" alt="<?= e(site_name()) ?>" width="140"><?php endif; ?>
  <p><?= e(setting('company_name')) ?></p>
  <p class="muted"><?= e(setting('tagline')) ?></p>
  <p class="muted"><?= e(str_replace("\n", ' · ', setting('address'))) ?><br>Tel. <?= e(setting('phone')) ?> · <?= e(setting('email')) ?></p>
</div>

<?php foreach ($groups as $g): ?>
  <section class="print-cat">
    <h2><?= e($g['name']) ?></h2>
    <?php foreach ($g['subs'] as $sub): ?>
      <?php if ($sub['name'] !== ''): ?><h3><?= e($sub['name']) ?></h3><?php endif; ?>
      <div class="print-grid">
        <?php foreach ($sub['items'] as $p): ?>
          <figure>
            <?php if ($opt['cover']): ?><span class="ph">
              <?php if ($p['image']): ?><?= str_replace(' loading="lazy"', ' loading="eager"', fit_img($p['image'], $p['name'])) ?><?php else: ?><?= placeholder_svg() ?><?php endif; ?>
            </span><?php endif; ?>
            <figcaption>
              <strong><?= e($p['name']) ?></strong>
              <?php if ($opt['ref'] && $p['ref'] !== ''): ?><span class="pr-ref">Ref. <?= e($p['ref']) ?></span><?php endif; ?>
              <?php if ($opt['dims'] && $p['dimensions'] !== ''): ?><span><?= e($p['dimensions']) ?></span><?php endif; ?>
            </figcaption>
          </figure>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>
<?php if (!$groups): ?><p class="empty">No hay artículos con ese filtro.</p><?php endif; ?>
</body>
</html>
