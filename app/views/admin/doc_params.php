<?php
/**
 * Configuración → Parámetros de documentos: una sola pantalla compacta en bloques plegables.
 * Variables: $tariffs, $methods, $series (activas), $allSeries, $counters, $year, $lineFields, $retail, $errors, $old (POST anterior o null).
 */
$o = fn(string $k, $default) => $old !== null && array_key_exists($k, $old) ? $old[$k] : $default;
$chk = fn(string $k) => $old !== null ? !empty($old[$k]) : docp_bool($k);
$open = $errors ? ' open' : ''; // con errores se abren todos los bloques
?>
<div class="head"><h1>Parámetros de documentos</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Valores que se proponen al crear pedidos, albaranes y facturas; se pueden cambiar en cada documento. Cada bloque se abre y se cierra con su título.</p>
<form method="post" action="<?= e(url('/admin/configuracion/parametros')) ?>" class="cfgf">
  <?= csrf_field() ?>

  <details class="blk" open><summary>Valores por defecto</summary>
    <div class="g3">
      <label>Tarifa
        <select name="tariff"><?php foreach ($tariffs as $t): ?><option value="<?= (int)$t['num'] ?>"<?= (int)$o('tariff', docp_int('tariff')) === (int)$t['num'] ? ' selected' : '' ?>><?= (int)$t['num'] ?> · <?= e($t['name']) ?></option><?php endforeach; ?></select></label>
      <label>Forma de pago
        <select name="pay_method"><option value="">— ninguna —</option><?php foreach ($methods as $m): ?><option value="<?= e($m['code']) ?>"<?= (string)$o('pay_method', docp('pay_method')) === (string)$m['code'] ? ' selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name'] !== '' ? $m['name'] : 'sin nombre') ?></option><?php endforeach; ?></select></label>
      <label>Cliente de contado (código)
        <input name="retail" value="<?= e((string)$o('retail', $retail)) ?>" maxlength="40" placeholder="vacío = ninguno">
        <small>El de los tickets de mostrador. Es el mismo ajuste de la sincronización con NCS.</small></label>
    </div>
    <div class="g4">
      <?php foreach (DOC_SERIES_TYPES as $type => $label): ?>
        <label>Serie de <?= e(mb_strtolower($label)) ?>
          <select name="series[<?= e($type) ?>]"><option value="">—</option>
            <?php foreach ($series as $id => $s): if ($s['doc_type'] !== $type) { continue; } ?>
              <option value="<?= (int)$id ?>"<?= ($old !== null ? (int)(($old['series'][$type] ?? 0)) === (int)$id : (int)$s['is_default'] === 1) ? ' selected' : '' ?>><?= e($s['letter']) ?> · <?= e($s['descr']) ?></option>
            <?php endforeach; ?>
          </select></label>
      <?php endforeach; ?>
    </div>
    <div class="g3">
      <label>Decimales de unidades <input type="number" name="dec_qty" min="0" max="4" value="<?= e((string)$o('dec_qty', docp('dec_qty'))) ?>"></label>
      <label>Decimales de precios <input type="number" name="dec_price" min="0" max="6" value="<?= e((string)$o('dec_price', docp('dec_price'))) ?>"></label>
      <label>Decimales de importes <input type="number" name="dec_amount" min="0" max="4" value="<?= e((string)$o('dec_amount', docp('dec_amount'))) ?>"><small>Con euros, 2.</small></label>
    </div>
  </details>

  <details class="blk" id="campos"<?= $open ?>><summary>Campos de las líneas <span class="muted">· Talla, Color, Dto., Lote…</span></summary>
    <p class="hint">Qué columnas llevan las líneas de los documentos. <strong>Si desactivas un campo, los documentos que ya lo tenían conservan sus valores</strong> (y se siguen imprimiendo); solo deja de salir en los nuevos. «Formulario» y «Impresión» deciden dónde sale, y las tres últimas columnas, en qué documentos.</p>
    <div class="tbl-wrap"><table class="tbl lf-tbl">
      <thead><tr><th scope="col">Campo</th><th scope="col">Tipo</th><th scope="col" title="Interruptor general">Activo</th><th scope="col">Formulario</th><th scope="col">Impresión</th><?php foreach (DOC_GROUP_LABELS as $gl): ?><th scope="col"><?= e($gl) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($lineFields as $k => $f):
          $docsSel = $old !== null ? array_map('strval', (array)($old['lf'][$k]['docs'] ?? [])) : $f['docs'];
          $chkf = fn(string $key) => $old !== null ? !empty($old['lf'][$k][$key]) : $f[$key] === 1;
      ?>
        <tr>
          <td><?php if ($f['builtin']): ?><strong><?= e($f['name']) ?></strong> <small class="muted">de serie</small>
            <?php else: ?><input name="lf[<?= e($k) ?>][name]" value="<?= e((string)($old['lf'][$k]['name'] ?? $f['name'])) ?>" maxlength="30" aria-label="Nombre del campo"><?php endif; ?></td>
          <td><?php if ($f['builtin']): ?><?= e(DOC_LINE_TYPES[$f['type']]) ?>
            <?php else: ?><select name="lf[<?= e($k) ?>][type]" aria-label="Tipo"><?php foreach (DOC_LINE_TYPES as $tk => $tl): ?><option value="<?= e($tk) ?>"<?= (string)($old['lf'][$k]['type'] ?? $f['type']) === $tk ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select>
              <input name="lf[<?= e($k) ?>][options]" value="<?= e((string)($old['lf'][$k]['options'] ?? implode(', ', $f['options']))) ?>" maxlength="400" placeholder="Opciones, separadas por comas (solo en Lista)" aria-label="Opciones de la lista"><?php endif; ?></td>
          <td><input type="checkbox" name="lf[<?= e($k) ?>][on]" value="1"<?= $chkf('on') ? ' checked' : '' ?> aria-label="Activar <?= e($f['name']) ?>"></td>
          <td><input type="checkbox" name="lf[<?= e($k) ?>][form]" value="1"<?= $chkf('form') ? ' checked' : '' ?> aria-label="<?= e($f['name']) ?> en el formulario"></td>
          <td><input type="checkbox" name="lf[<?= e($k) ?>][print]" value="1"<?= $chkf('print') ? ' checked' : '' ?> aria-label="<?= e($f['name']) ?> en la impresión"></td>
          <?php foreach (DOC_GROUP_LABELS as $gk => $gl): ?><td><input type="checkbox" name="lf[<?= e($k) ?>][docs][]" value="<?= e($gk) ?>"<?= in_array($gk, $docsSel, true) ? ' checked' : '' ?> aria-label="<?= e($f['name']) ?> en <?= e(mb_strtolower($gl)) ?>"></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php $customN = count(array_filter($lineFields, fn($f) => !$f['builtin'])); ?>
    <?php if ($customN < DOC_LINE_MAX_CUSTOM): ?>
    <div class="g3 lf-new">
      <label>Añadir campo: nombre <input name="lfnew[name]" value="<?= e((string)($old['lfnew']['name'] ?? '')) ?>" maxlength="30" placeholder="Ej.: Lote"></label>
      <label>Tipo <select name="lfnew[type]"><?php foreach (DOC_LINE_TYPES as $tk => $tl): ?><option value="<?= e($tk) ?>"<?= (string)($old['lfnew']['type'] ?? 'text') === $tk ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select></label>
      <label>Opciones (solo para «Lista») <input name="lfnew[options]" value="<?= e((string)($old['lfnew']['options'] ?? '')) ?>" maxlength="400" placeholder="Ej.: A, B, C"></label>
    </div>
    <small>El campo nuevo se crea activo, en el formulario, en la impresión y en los tres documentos. Hasta <?= (int)DOC_LINE_MAX_CUSTOM ?> campos propios; no se borran (se desactivan) para no perder los valores de los documentos antiguos.</small>
    <?php else: ?><small>Ya tienes el máximo de campos propios (<?= (int)DOC_LINE_MAX_CUSTOM ?>). Desactiva alguno si no lo usas.</small><?php endif; ?>
  </details>

  <details class="blk" id="series"<?= $open ?>><summary>Series de numeración</summary>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th scope="col">Documento</th><th scope="col">Letra</th><th scope="col">Formato</th><th scope="col">Siguiente número (<?= (int)$year ?>)</th><th scope="col">Activa</th></tr></thead>
      <tbody>
      <?php foreach ($allSeries as $id => $s):
          $yr = (int)$s['with_year'] === 1 ? $year : 0;
          $c = $counters[$id][$yr] ?? null;
          $curUsed = $c !== null && $c['last'] >= $c['start'];
          $next = $c === null ? 1 : $c['last'] + 1;
          $active = $old !== null && isset($old['s'][$id]) ? !empty($old['s'][$id]['active']) : (int)$s['is_active'] === 1;
          $startShown = $old !== null && isset($old['s'][$id]) ? (string)($old['s'][$id]['start'] ?? $next) : (string)$next;
      ?>
        <tr>
          <td><?= e(DOC_SERIES_TYPES[$s['doc_type']] ?? $s['doc_type']) ?><?= $s['descr'] !== '' && $s['descr'] !== (DOC_SERIES_TYPES_PL[$s['doc_type']] ?? '') ? ' <small class="muted">· ' . e($s['descr']) . '</small>' : '' ?></td>
          <td><strong><?= e($s['letter']) ?></strong></td>
          <td><code><?= e(series_format($s, $next, $year)) ?></code></td>
          <td><input class="num" type="number" name="s[<?= (int)$id ?>][start]" value="<?= e($startShown) ?>" min="<?= $curUsed ? (int)$next : 1 ?>" max="<?= (int)series_max_number((int)$s['digits']) ?>" aria-label="Siguiente número de la serie <?= e($s['letter']) ?>"<?= $curUsed ? ' title="Esta serie ya tiene documentos emitidos este año: el siguiente número no se puede bajar ni cambiar"' : '' ?>><?= $curUsed ? ' <small class="muted">en uso</small>' : '' ?></td>
          <td><input type="checkbox" name="s[<?= (int)$id ?>][active]" value="1"<?= $active ? ' checked' : '' ?> aria-label="Serie <?= e($s['letter']) ?> activa"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <small>El siguiente número de una serie con documentos emitidos no se puede bajar ni dejar huecos. Para crear series nuevas, cambiar letra, dígitos o año, ve a <a href="<?= e(url('/admin/configuracion/series')) ?>">Series</a>.</small>
  </details>

  <details class="blk"<?= $open ?>><summary>Pedir siempre y avisos</summary>
    <div class="g2">
      <div>
        <strong class="lg">Pedir siempre al añadir una línea</strong>
        <label class="check"><input type="checkbox" name="ask_discount" value="1"<?= $chk('ask_discount') ? ' checked' : '' ?>> <span>Descuento</span></label>
        <label class="check"><input type="checkbox" name="ask_price" value="1"<?= $chk('ask_price') ? ' checked' : '' ?>> <span>Precio</span></label>
        <label class="check"><input type="checkbox" name="ask_tariff" value="1"<?= $chk('ask_tariff') ? ' checked' : '' ?>> <span>Tarifa</span></label>
        <label class="check"><input type="checkbox" name="ask_title" value="1"<?= $chk('ask_title') ? ' checked' : '' ?>> <span>Título libre (cambiar la descripción de la línea)</span></label>
      </div>
      <div>
        <strong class="lg">Avisos</strong>
        <label class="check"><input type="checkbox" name="warn_stock_min" value="1"<?= $chk('warn_stock_min') ? ' checked' : '' ?>> <span>Avisar si el stock queda por debajo del mínimo</span></label>
        <label class="check"><input type="checkbox" name="warn_blocked" value="1"<?= $chk('warn_blocked') ? ' checked' : '' ?>> <span>Avisar si el artículo está bloqueado</span></label>
      </div>
    </div>
  </details>

  <details class="blk"<?= $open ?>><summary>Documento de prueba y tiques</summary>
    <div class="g2">
      <div>
        <label class="check"><input type="checkbox" name="test_mode" value="1"<?= $chk('test_mode') ? ' checked' : '' ?>> <span><strong>Las facturas que se emitan son de prueba</strong> (activado hasta que lo quites)</span></label>
        <label>Texto de la marca <input name="test_text" value="<?= e((string)$o('test_text', docs_test_text())) ?>" maxlength="120"></label>
        <small>Mientras esté activado, «Emitir» crea una factura <strong>de prueba</strong>: numeración aparte (PRUEBA-00001), con la marca en la hoja, sin gastar números de la serie, fuera del registro encadenado, del libro del SII, de Ventas e Informes. Al quitarlo se ofrece borrar las facturas de prueba, y la primera factura real será la 1 de su serie.</small>
      </div>
      <div>
        <label class="check"><input type="checkbox" name="retail_trade" value="1"<?= $chk('retail_trade') ? ' checked' : '' ?>> <span>Comercio minorista: tiques de hasta 3.000 € (si no, hasta 400 €), IVA incluido</span></label>
        <small>Por encima del tope hay que hacer factura completa. Si dudas, consúltalo con tu gestor.</small>
      </div>
    </div>
  </details>

  <div class="savebar"><button class="btn" type="submit">Guardar</button><span class="muted">IVA, formas de pago, tarifas y el diseño de impresión, en sus propias pantallas.</span></div>
</form>
