<?php
/** Datos → Importar (asistente). Paso 1: $cards [clave => [título, texto, hayNCS]]. Paso 2: $que, $what [título, texto], $ncs. */
?>
<?php if ($step === 1): ?>
<div class="head"><h1>Importar</h1></div>
<p class="hint"><strong>Paso 1.</strong> ¿Qué quieres traer? Después te enseñaré solo los orígenes que sirven para eso. Nada se guarda hasta que revises el resumen y lo confirmes.</p>
<div class="home-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px">
  <?php foreach ($cards as $k => [$title, $text, $hasNcs]): ?>
    <a class="stat" href="<?= e(url('/admin/importar?que=' . $k)) ?>">
      <strong><?= e($title) ?></strong>
      <span><?= e($text) ?></span>
      <?php if ($hasNcs): ?><small class="tag tag-ok">NCS recomendado: hay archivos suyos en data/importar</small><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="head"><h1>Importar <?= e(mb_strtolower($what[0])) ?></h1><a class="btn ghost" href="<?= e(url('/admin/importar')) ?>">‹ Cambiar lo que quiero traer</a></div>
<p class="hint"><strong>Paso 2.</strong> ¿De dónde? <?= e($what[1]) ?></p>
<?php if ($que === 'fotos'): ?>
  <div class="home-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
    <a class="stat" href="<?= e(url('/admin/importar/carpetas')) ?>"><strong>Fotos por carpeta de artículo</strong><span>Carpetas <code>ARTICULO_&lt;código&gt;</code> (por ejemplo las de NCS Facturación), desde un ZIP o desde <code>data/importar/</code>.</span><?php if ($ncs): ?><small class="tag tag-ok">NCS recomendado</small><?php endif; ?></a>
    <a class="stat" href="<?= e(url('/admin/ftp')) ?>"><strong>Fotos subidas por FTP</strong><span>Enlaza con sus artículos las fotos que has dejado en la carpeta de subida y repara las fotos rotas.</span></a>
  </div>
<?php else: ?>
  <div class="home-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
    <a class="stat" href="<?= e(url('/admin/erp')) ?>"><strong>NCS Facturación (sincronización manual)</strong><span>Sube <code>FCUENTAS.MDB</code> y <code>FHISTORI.MDB</code> (o déjalos por FTP en <code>data/importar/</code>) y revisa lo que cambia antes de aplicarlo.</span><?php if ($ncs): ?><small class="tag tag-ok">Recomendado: hay archivos de NCS en data/importar</small><?php endif; ?></a>
  </div>
  <p class="muted">Por ahora este es el único origen para <?= e(mb_strtolower($what[0])) ?>. Otros orígenes (hoja de cálculo y bases de datos) llegarán más adelante.</p>
<?php endif; ?>
<?php endif; ?>
