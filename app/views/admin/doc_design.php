<?php
/** Configuración → Diseño de documentos. Variables: $errors, $old (POST anterior o null), $links (documento real de cada tipo para la vista previa). */
$o = fn(string $k, $default) => $old !== null && array_key_exists($k, $old) ? $old[$k] : $default;
$general = (string)$o('model', isset(DOC_MODELS[setting('doc_model', 'classic')]) ? setting('doc_model', 'classic') : 'classic');
$savedColor = (string)setting('doc_color', '');
$colorOn = $old !== null ? !empty($old['color_on']) : preg_match('/^#[0-9a-fA-F]{6}$/', $savedColor) === 1;
$colorVal = (string)$o('color', $colorOn ? $savedColor : (setting('brand_color', '') !== '' ? setting('brand_color') : '#3b6fb6'));
$font = (string)$o('font', setting('doc_font', 'sans'));
?>
<div class="head"><h1>Diseño de documentos</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Cómo se ven e imprimen los pedidos, albaranes y facturas. Los tres modelos salen bien en A4 (Ctrl+P), parten las descripciones largas y respetan los campos de las líneas, los vencimientos y la marca de prueba.</p>
<form method="post" action="<?= e(url('/admin/configuracion/diseno')) ?>" class="cfgf" autocomplete="off">
  <?= csrf_field() ?>

  <details class="blk" open><summary>Modelo para todos los documentos</summary>
    <div class="m-thumbs">
      <?php foreach (DOC_MODELS as $mk => $ml): ?>
        <label class="m-card"><strong><?= e($ml) ?></strong>
          <?= doc_model_thumb($mk) ?>
          <small><?= e(DOC_MODEL_DESC[$mk]) ?></small>
          <input type="radio" name="model" value="<?= e($mk) ?>"<?= $general === $mk ? ' checked' : '' ?>> Usar este
        </label>
      <?php endforeach; ?>
    </div>
  </details>

  <details class="blk" open><summary>Un modelo distinto por tipo de documento</summary>
    <div class="g3">
      <?php foreach (DOC_GROUP_LABELS as $g => $gl): $cur = (string)(($old['model_g'][$g] ?? null) ?? setting('doc_model_' . $g, '')); ?>
        <label><?= e($gl) ?>
          <select name="model_g[<?= e($g) ?>]"><option value="">Igual que el general</option>
            <?php foreach (DOC_MODELS as $mk => $ml): ?><option value="<?= e($mk) ?>"<?= $cur === $mk ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?>
          </select></label>
      <?php endforeach; ?>
    </div>
    <small>Las facturas simplificadas y rectificativas usan el modelo de «Factura».</small>
  </details>

  <details class="blk" open><summary>Vista previa con un documento real</summary>
    <?php $any = false; foreach (DOC_GROUP_LABELS as $g => $gl): if (($links[$g] ?? '') === '') { continue; } $any = true; ?>
      <p style="margin:4px 0"><strong><?= e($gl) ?>:</strong>
        <?php foreach (DOC_MODELS as $mk => $ml): ?><a class="btn small ghost" href="<?= e(url($links[$g]) . '?modelo=' . $mk) ?>" target="_blank" rel="noopener"><?= e($ml) ?></a> <?php endforeach; ?></p>
    <?php endforeach; ?>
    <?php if (!$any): ?><p class="muted" style="margin:0">Todavía no hay ningún pedido, albarán ni factura del CMS para enseñar. Crea uno y vuelve aquí. También puedes ver cualquier documento con <code>?modelo=moderno</code> o <code>?modelo=compact</code> al final de su dirección de impresión.</p><?php endif; ?>
    <small>La vista previa no cambia nada: solo enseña el documento con ese modelo.</small>
  </details>

  <details class="blk"><summary>Color, letra, logotipo y márgenes</summary>
    <div class="g4">
      <label class="check"><input type="checkbox" name="color_on" value="1"<?= $colorOn ? ' checked' : '' ?>> <span>Color propio (si no, el de la marca)</span></label>
      <label>Color <input type="color" name="color" value="<?= e($colorVal) ?>"></label>
      <label>Letra <select name="font"><?php foreach (['sans' => 'Sin remates', 'serif' => 'Con remates', 'mono' => 'Máquina de escribir'] as $fk => $fl): ?><option value="<?= e($fk) ?>"<?= $font === $fk ? ' selected' : '' ?>><?= e($fl) ?></option><?php endforeach; ?></select></label>
      <label>Alto del logotipo (px) <input type="number" name="logo_h" min="0" max="200" value="<?= e((string)$o('logo_h', (string)setting('doc_logo_h', '0'))) ?>"><small>0 = el de Empresa</small></label>
    </div>
    <div class="g2">
      <label>Márgenes (mm) <input type="number" name="margin_mm" min="5" max="30" value="<?= e((string)$o('margin_mm', (string)setting('doc_margin_mm', '15'))) ?>"></label>
      <label>Pie de las facturas <input name="footer" value="<?= e((string)$o('footer', (string)setting('doc_footer', DOC_DEFAULTS['footer']))) ?>" maxlength="400"><small>Las facturas del CMS no llevan el pie por defecto de NCS.</small></label>
    </div>
  </details>

  <div class="savebar"><button class="btn" type="submit">Guardar</button><span class="muted">Las columnas y los campos de las líneas se eligen en <a href="<?= e(url('/admin/configuracion/parametros#campos')) ?>">Parámetros</a>.</span></div>
</form>
