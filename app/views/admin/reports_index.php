<?php
/** Índice de informes agrupados por sección. Variables: $by (sección => lista de definiciones). */
?>
<div class="head"><h1>Informes</h1></div>
<p class="hint">Elige un informe, ajusta la selección (vacío = todos) y mira el resultado en pantalla o descárgalo en Excel, CSV o PDF. Se recuerda la última selección de cada informe. Los archivos no se guardan en el servidor.</p>
<?php if (!$by): ?><p class="empty">Tu usuario no tiene informes disponibles.</p><?php endif; ?>
<?php foreach ($by as $section => $list): ?>
  <h2><?= e($section) ?></h2>
  <ul class="rep-index">
    <?php foreach ($list as $d): ?>
      <li><a href="<?= e(url('/admin/listados/' . $d['id'])) ?>"><strong><?= e($d['title']) ?></strong><span><?= e($d['help']) ?></span></a></li>
    <?php endforeach; ?>
  </ul>
<?php endforeach; ?>
