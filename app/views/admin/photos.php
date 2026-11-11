<div class="head"><h1>Mejorar fotos</h1></div>
<p class="hint">Recorta el sobrante de cada foto, centra el artículo sobre <strong>fondo blanco</strong> y lo deja del mismo tamaño visual (lienzo cuadrado). Antes de tocar una foto se guarda su original, así que se puede <strong>deshacer</strong>. Las fotos con fondo irregular (el producto sobre una mesa, por ejemplo) no se tocan y se listan abajo.</p>

<ul class="summary">
  <li><b><?= (int)$stats['total'] ?></b> fotos en total</li>
  <li><b><?= (int)$stats['ok'] ?></b> mejoradas por esta herramienta</li>
  <?php if (!empty($stats['manual'])): ?><li><b><?= (int)$stats['manual'] ?></b> ya retocadas por ti (no se tocan)</li><?php endif; ?>
  <li><b><?= (int)$stats['pending'] ?></b> pendientes de procesar</li>
  <li><b><?= (int)$stats['skip'] ?></b> sin tocar (fondo no apto)</li>
</ul>

<?php if ($running): ?>
  <p class="flash ok">Mejorando fotos… la página se actualiza sola. No la cierres.</p>
  <?php $done = $stats['ok'] + $stats['skip']; ?>
  <div class="bar-progress"><span style="width:<?= $stats['total'] ? (int)round($done * 100 / $stats['total']) : 0 ?>%"></span></div>
  <form method="post"><?= csrf_field() ?><button class="btn small ghost" name="action" value="stop" type="submit">Parar</button></form>
<?php else: ?>
  <?php if ($stats['pending'] > 0): ?>
  <div class="flash ok" style="margin-bottom:14px">
    <strong>¿Ya retocaste tus fotos en Photoroom u otra herramienta?</strong> Entonces «pendientes» solo significa que esta herramienta no las ha procesado, y <strong>no debes pulsar «Mejorar todas»</strong>. Pulsa este botón para marcarlas como retocadas y que esa cifra quede a 0.
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><button class="btn" name="action" value="mark_done" type="submit" onclick="return confirm('¿Marcar las <?= (int)$stats['pending'] ?> fotos pendientes como ya retocadas?')">Marcar las <?= (int)$stats['pending'] ?> pendientes como ya retocadas</button></form>
  </div>
  <?php endif; ?>
  <form method="post" class="buttons">
    <?= csrf_field() ?>
    <button class="btn ghost" name="action" value="trial" type="submit"<?= $stats['pending'] ? '' : ' disabled' ?>>1. Probar con 6 fotos</button>
    <button class="btn" name="action" value="all" type="submit"<?= $stats['pending'] ? '' : ' disabled' ?>>2. Mejorar todas las pendientes</button>
    <button class="btn danger-btn" name="action" value="undo" type="submit" onclick="return confirm('¿Devolver todas las fotos mejoradas a su original?')">Deshacer todo</button>
    <?php if ($stats['skip']): ?><button class="btn ghost" name="action" value="retry" type="submit">Reintentar las no aptas</button><?php endif; ?>
  </form>
  <form method="post" class="form" style="margin-top:16px">
    <?= csrf_field() ?>
    <strong>Fondo de las fotos</strong>
    <label class="check"><input type="radio" name="bg" value="white"<?= $bgmode === 'white' ? ' checked' : '' ?>> Blanco</label>
    <label class="check"><input type="radio" name="bg" value="transparent"<?= $bgmode === 'transparent' ? ' checked' : '' ?>> Transparente (el artículo se ve directamente sobre el color de la tarjeta de la web)</label>
    <div class="buttons">
      <button class="btn small" name="action" value="bg" type="submit">Guardar fondo</button>
      <button class="btn small ghost" name="action" value="redo" type="submit" onclick="return confirm('Se rehacen todas las fotos desde su original con el fondo elegido. ¿Continuar?')">Rehacer todas con este fondo</button>
    </div>
  </form>
  <form method="post" class="form" style="margin-top:16px">
    <?= csrf_field() ?>
    <label class="check"><input type="checkbox" name="auto" value="1"<?= $auto ? ' checked' : '' ?>> Mejorar automáticamente las fotos que suba a partir de ahora</label>
    <div class="buttons"><button class="btn small" name="action" value="auto" type="submit">Guardar</button></div>
  </form>
<?php endif; ?>

<?php if ($trial): ?>
  <h2>Resultado de la prueba</h2>
  <p class="muted">Izquierda: original. Derecha: mejorada.</p>
  <?php foreach ($trial as $t): $ok = $t['enh'] === 'ok'; ?>
    <div class="imp" style="flex-direction:row;gap:12px;align-items:center;flex-wrap:wrap">
      <?php if ($ok && is_file(UPLOAD_DIR . '/orig/' . $t['file'])): ?>
        <img src="<?= e(url('/uploads/orig/' . $t['file'])) ?>" alt="" width="150" height="150" style="object-fit:contain;background:#eee;border-radius:8px">
        <img src="<?= e(img_url($t['file'], true)) ?>?v=<?= time() ?>" alt="" width="150" height="150" style="object-fit:contain;background:#fff;border:1px solid #ddd;border-radius:8px">
      <?php else: ?>
        <img src="<?= e(img_url($t['file'], true)) ?>" alt="" width="150" height="150" style="object-fit:contain;background:#eee;border-radius:8px">
      <?php endif; ?>
      <span><b><?= e($t['name']) ?></b><br><span class="muted"><?= $ok ? 'Mejorada' : 'No tocada: ' . e(substr($t['enh'], 5)) ?></span></span>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php if ($stats['skip'] > 0): ?>
  <form method="post" style="margin:16px 0">
    <?= csrf_field() ?>
    <button class="btn" name="action" value="zip_skipped" type="submit">Descargar las <?= (int)$stats['skip'] ?> de fondo irregular (ZIP para Photoroom)</button>
  </form>
<?php endif; ?>
<?php if ($skipped): ?>
  <h2>Fotos que no se han tocado</h2>
  <ul class="errlist">
    <?php foreach ($skipped as $s): ?><li><b><?= e($s['name']) ?></b> — <?= e(substr($s['enh'], 5)) ?></li><?php endforeach; ?>
  </ul>
  <p class="muted">Estas se pueden retocar fuera (por ejemplo en Photoroom): descarga el ZIP, retócalas, y súbelas por FTP a <code>uploads</code> sin cambiar el nombre; luego en Fotos FTP pulsa «Sustituir automáticamente».</p>
<?php endif; ?>
