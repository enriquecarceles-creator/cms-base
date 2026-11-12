<?php
/** Configuración → Parámetros de documentos. Variables: $tariffs, $methods, $series, $retail, $errors, $old (POST anterior o null). */
$o = fn(string $k, $default) => $old !== null && array_key_exists($k, $old) ? $old[$k] : $default;
$chk = fn(string $k) => $old !== null ? !empty($old[$k]) : docp_bool($k);
?>
<div class="head"><h1>Parámetros de documentos</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Valores que se proponen al crear pedidos, albaranes y facturas. Se pueden cambiar en cada documento.</p>
<form method="post" action="<?= e(url('/admin/configuracion/parametros')) ?>" class="cfgf">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Valores por defecto</legend>
    <div class="g3">
      <label>Tarifa
        <select name="tariff"><?php foreach ($tariffs as $t): ?><option value="<?= (int)$t['num'] ?>"<?= (int)$o('tariff', docp_int('tariff')) === (int)$t['num'] ? ' selected' : '' ?>><?= (int)$t['num'] ?> · <?= e($t['name']) ?></option><?php endforeach; ?></select></label>
      <label>Forma de pago
        <select name="pay_method"><option value="">— ninguna —</option><?php foreach ($methods as $m): ?><option value="<?= e($m['code']) ?>"<?= (string)$o('pay_method', docp('pay_method')) === (string)$m['code'] ? ' selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name'] !== '' ? $m['name'] : 'sin nombre') ?></option><?php endforeach; ?></select></label>
      <label>Cliente de contado (código)
        <input name="retail" value="<?= e((string)$o('retail', $retail)) ?>" maxlength="40" placeholder="vacío = ninguno">
        <small>El cliente de los tickets de mostrador. Es el mismo ajuste que usa la sincronización con NCS.</small></label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Serie por defecto de cada tipo</legend>
    <div class="g4">
      <?php foreach (DOC_SERIES_TYPES as $type => $label): ?>
        <label><?= e($label) ?>
          <select name="series[<?= e($type) ?>]"><option value="">—</option>
            <?php foreach ($series as $id => $s): if ($s['doc_type'] !== $type) { continue; } ?>
              <option value="<?= (int)$id ?>"<?= ($old !== null ? (int)(($old['series'][$type] ?? 0)) === (int)$id : (int)$s['is_default'] === 1) ? ' selected' : '' ?>><?= e($s['letter']) ?> · <?= e($s['descr']) ?></option>
            <?php endforeach; ?>
          </select></label>
      <?php endforeach; ?>
    </div>
    <small>Las series se crean y editan en <a href="<?= e(url('/admin/configuracion/series')) ?>">Series</a>.</small>
  </fieldset>

  <fieldset>
    <legend>Decimales</legend>
    <div class="g3">
      <label>Unidades <input type="number" name="dec_qty" min="0" max="4" value="<?= e((string)$o('dec_qty', docp('dec_qty'))) ?>"></label>
      <label>Precios <input type="number" name="dec_price" min="0" max="6" value="<?= e((string)$o('dec_price', docp('dec_price'))) ?>"></label>
      <label>Importes <input type="number" name="dec_amount" min="0" max="4" value="<?= e((string)$o('dec_amount', docp('dec_amount'))) ?>"><small>Con euros, 2.</small></label>
    </div>
  </fieldset>

  <div class="g2">
    <fieldset>
      <legend>Pedir siempre al añadir una línea</legend>
      <label class="check"><input type="checkbox" name="ask_discount" value="1"<?= $chk('ask_discount') ? ' checked' : '' ?>> <span>Descuento</span></label>
      <label class="check"><input type="checkbox" name="ask_price" value="1"<?= $chk('ask_price') ? ' checked' : '' ?>> <span>Precio</span></label>
      <label class="check"><input type="checkbox" name="ask_tariff" value="1"<?= $chk('ask_tariff') ? ' checked' : '' ?>> <span>Tarifa</span></label>
      <label class="check"><input type="checkbox" name="ask_title" value="1"<?= $chk('ask_title') ? ' checked' : '' ?>> <span>Título libre (cambiar la descripción de la línea)</span></label>
    </fieldset>
    <fieldset>
      <legend>Avisos</legend>
      <label class="check"><input type="checkbox" name="warn_stock_min" value="1"<?= $chk('warn_stock_min') ? ' checked' : '' ?>> <span>Avisar si el stock queda por debajo del mínimo</span></label>
      <label class="check"><input type="checkbox" name="warn_blocked" value="1"<?= $chk('warn_blocked') ? ' checked' : '' ?>> <span>Avisar si el artículo está bloqueado</span></label>
    </fieldset>
    <fieldset>
      <legend>Documento de prueba</legend>
      <label class="check"><input type="checkbox" name="test_mode" value="1"<?= $chk('test_mode') ? ' checked' : '' ?>> <span><strong>Las facturas que se emitan son de prueba</strong> (activado hasta que lo quites)</span></label>
      <label>Texto de la marca <input name="test_text" value="<?= e((string)$o('test_text', docs_test_text())) ?>" maxlength="120"></label>
      <small>Mientras esté activado, «Emitir» crea una factura <strong>de prueba</strong>: numeración aparte (PRUEBA-00001), con la marca en la hoja, <strong>sin gastar números de la serie</strong>, fuera del registro encadenado, del libro del SII, de Ventas e Informes. Al quitarlo se ofrece borrar las facturas de prueba, y la primera factura real será la 1 de su serie (por ejemplo W00001/26).</small>
    </fieldset>
    <fieldset>
      <legend>Facturas simplificadas (tiques)</legend>
      <label class="check"><input type="checkbox" name="retail_trade" value="1"<?= $chk('retail_trade') ? ' checked' : '' ?>> <span>Comercio minorista: tiques de hasta 3.000 € (si no, hasta 400 €), IVA incluido</span></label>
      <small>Por encima del tope hay que hacer factura completa. Si dudas, consúltalo con tu gestor.</small>
    </fieldset>
  </div>

  <div class="savebar"><button class="btn" type="submit">Guardar</button><span class="muted">IVA, formas de pago y tarifas, en sus propias pantallas.</span></div>
</form>
