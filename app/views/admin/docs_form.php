<?php
/**
 * Formulario de pedido / albarán (motor de documentos, app/docs_engine.php). Funciona sin JavaScript: «Recalcular» deja que el
 * servidor complete artículos, precios y totales. Con JavaScript: sugerencias de artículos y clientes, importe de cada línea al
 * momento y aviso de cambios sin guardar.
 * Dos pasos: al crear solo se ve la cabecera (cliente con buscador, fecha, serie, forma de pago) y «Grabar cabecera» crea un BORRADOR sin número;
 * después se añaden las líneas (artículo, concepto o texto libre). El número se asigna al confirmar o emitir.
 * Variables: $kind, $k, $doc, $saved, $isNew, $isDraft, $lines, $meta, $extra, $extraText, $tot, $errors, $warnings, $locked, $self, $series, $stamp,
 * $custResults, $custQuery, $rates, $methods, $tariffs, $events, $related, $isRetail.
 */
$ro = $locked !== '';
$askPrice = docp_bool('ask_price');
$askDisc = docp_bool('ask_discount');
$askTitle = docp_bool('ask_title');
$askTariff = docp_bool('ask_tariff');
$decQ = docs_dec('dec_qty');
$decP = docs_dec('dec_price');
$num = fn(int $v, int $scale, int $dec) => number_format($v / $scale, $dec, ',', '');
$fmtPct = fn($p) => rtrim(rtrim(number_format((float)$p, 2, ',', ''), '0'), ',') . ' %';
$hasDone = (bool)array_filter($lines, fn($l) => (int)($l['done_u'] ?? 0) > 0);
$hasSrc = (bool)array_filter($lines, fn($l) => (int)($l['src_line_id'] ?? 0) > 0);
$inv = docs_is_invoice($kind);
$blankMeta = ['prod' => null, 'locked' => false, 'opts' => ['sizes' => [], 'colors' => []]];
$rows = [];
foreach ($lines as $i => $l) { // líneas guardadas (artículo, concepto o texto libre)
    $rows[] = ['l' => $l, 'm' => $meta[$i] ?? $blankMeta, 'text' => (int)($l['kind'] ?? 0) === 1];
}
if (!$ro) { // filas vacías al final: de artículo y de texto
    for ($j = 0; $j < $extra; $j++) {
        $rows[] = ['l' => null, 'm' => $blankMeta, 'text' => false];
    }
    for ($j = 0; $j < $extraText; $j++) {
        $rows[] = ['l' => null, 'm' => $blankMeta, 'text' => true];
    }
}
$ncols = 11 + ($kind === 'pedido' && $hasDone ? 1 : 0); // columnas de la rejilla (para las filas de texto)
$textOf = fn(?array $l) => $l === null ? '' : (string)$l['title'] . ((string)($l['detail'] ?? '') !== '' ? "\n" . $l['detail'] : '');
$rowsOf = fn(string $t) => max(1, min(12, substr_count($t, "\n") + 1));
$doneLabel = $inv ? 'Emitir' : 'Confirmar';
$issued = $inv && $saved['status'] === 'emitida';
$statusCls = $doc['status'] === 'anulado' ? ' tag-off' : (in_array($doc['status'], ['servido', 'facturado', 'emitida'], true) ? ' tag-ok' : '');
$custLink = $doc['cust_code'] !== '' && !$isRetail ? url('/admin/clientes/' . rawurlencode((string)$doc['cust_code'])) : '';
$issueProblems = $issueProblems ?? [];
$orig = $orig ?? null;
$isTest = $inv && (docs_is_test($saved) || ($isDraft && docp_bool('test_mode')));
?>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>

<div class="if-bar" role="toolbar" aria-label="Acciones">
  <div class="if-grp">
    <?php if (!$ro && $isNew): ?>
      <button class="btn small" type="submit" form="docform" name="action" value="header" title="Crea el documento como borrador (sin número); después se añaden las líneas">Grabar cabecera</button>
    <?php elseif (!$ro): ?>
      <button class="btn small" type="submit" form="docform" name="action" value="save"><?= $isDraft ? 'Guardar borrador' : 'Guardar' ?></button>
      <button class="btn small ghost" type="submit" form="docform" name="action" value="recalc">Recalcular</button>
      <button class="btn small ghost" type="submit" form="docform" name="action" value="more" title="Añade filas para más artículos o conceptos">Añadir artículo</button>
      <button class="btn small ghost" type="submit" form="docform" name="action" value="more_text" title="Añade una línea de texto libre (sin unidades ni precio)">Añadir texto</button>
      <button class="btn small ghost" type="submit" form="docform" name="action" value="reprice" title="Pone en todas las líneas con artículo el precio de la tarifa elegida">Aplicar tarifa</button>
    <?php endif; ?>
  </div>
  <div class="if-grp">
    <?php if (!$isNew): ?>
      <?php if ($isDraft && !$ro): ?>
        <button class="btn small" type="submit" form="f-done"<?= $issueProblems ? ' disabled title="Antes hay que completar lo que falta (ver arriba)"' : ' title="Toma el número de la serie' . ($inv ? '. Después ya no se podrá modificar ni borrar.' : ' y deja el documento listo para usarse.') . '"' ?>><?= $doneLabel ?></button>
        <button class="btn small ghost df-annul" type="submit" form="f-delete">Borrar borrador</button>
      <?php endif; ?>
      <a class="btn small ghost" href="<?= e(url($self . '/ver')) ?>" target="_blank" rel="noopener"><?= $isDraft ? 'Vista previa' : 'Imprimir' ?></a>
      <?php if ($issued): ?>
        <?php if (!docs_is_test($saved)): ?><a class="btn small ghost" href="<?= e(url('/admin/ventas/' . erp_inv_seg((string)$saved['doc_code']))) ?>" title="Cobro, envío por email, notas e historial">Cobro y envío</a><?php endif; ?>
        <a class="btn small ghost" href="#rectificar">Rectificar</a>
      <?php endif; ?>
      <?php if ($kind === 'pedido' && in_array($saved['status'], ['pendiente', 'parcial'], true) && !$ro): ?>
        <button class="btn small" type="submit" form="f-serve" title="Crea un albarán con las unidades pendientes. Si solo sirves una parte, baja las unidades en el albarán: el resto sigue pendiente.">Convertir en albarán</button>
      <?php endif; ?>
      <?php if ($kind === 'albaran' && $doc['status'] === 'pendiente' && (int)$saved['billed_by'] === 0 && !$ro): ?>
        <button class="btn small" type="submit" form="f-bill" title="Crea un borrador de factura con este albarán">Facturar</button>
      <?php endif; ?>
      <?php if (!$inv): ?>
        <button class="btn small ghost" type="submit" form="f-dup" title="Crea un borrador nuevo con las mismas líneas">Duplicar</button>
        <?php if (!$isDraft && $doc['status'] !== 'anulado'): ?><a class="btn small ghost" href="<?= e(url($self . '/enviar')) ?>">Enviar por email</a><?php endif; ?>
      <?php endif; ?>
      <?php if (!$inv && !$isDraft && $doc['status'] !== 'anulado' && !$ro): ?>
        <button class="btn small ghost df-annul" type="submit" form="f-annul">Anular</button>
      <?php endif; ?>
      <?php if ($custLink !== ''): ?><a class="btn small ghost" href="<?= e($custLink) ?>">Ver cliente</a><?php endif; ?>
    <?php endif; ?>
    <?php if (!$isNew): ?>
      <?php if ($nav[0]): ?><a class="btn small ghost" href="<?= e(url($k['path'] . '/' . $nav[0])) ?>" rel="prev" title="Documento anterior">‹ Anterior</a><?php endif; ?>
      <?php if ($nav[1]): ?><a class="btn small ghost" href="<?= e(url($k['path'] . '/' . $nav[1])) ?>" rel="next" title="Documento siguiente">Siguiente ›</a><?php endif; ?>
    <?php endif; ?>
    <a class="btn small ghost" href="<?= e(url($k['path'])) ?>">Volver</a>
  </div>
</div>

<div class="if-title">
  <h1><?= $isNew ? e($k['new']) : ($isDraft ? 'Borrador de ' . e(mb_strtolower($k['label'])) : e($k['label'] . ' ' . $doc['doc_code'])) ?></h1>
  <?php if (!$isNew): ?>
  <p class="cf-tags">
    <span class="tag<?= $statusCls ?>"><?= e(docs_status_label($saved)) ?></span>
    <span class="tag">Origen: CMS</span>
    <?php if ($saved['ref_text'] !== ''): ?><span class="tag tag-label"><?= e($saved['ref_text']) ?></span><?php endif; ?>
  </p>
  <?php endif; ?>
</div>

<?php if ($isTest): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span><strong><?= e(docs_test_text()) ?>.</strong> <?= $issued ? 'Esta factura es de prueba: no ha gastado números de la serie ni está en el registro, el SII ni Ventas.' : 'Mientras el modo de prueba esté activado, «Emitir» crea una factura de prueba (numeración PRUEBA-…, sin validez fiscal). Se quita en Configuración → Parámetros.' ?></span></p><?php endif; ?>
<?php if ($locked !== '' && !$issued): ?><p class="flash err"><?= e($locked) ?></p><?php endif; ?>
<?php if ($issued): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span><strong>Factura emitida el <?= e(date('d/m/Y H:i', (int)$saved['issued_at'])) ?><?= $saved['issued_by'] !== '' ? ' por ' . e($saved['issued_by']) : '' ?>.</strong> No se puede modificar, anular ni borrar: para corregirla, haz una rectificativa. Clave SII: <?= e($saved['sii_type']) ?>.</span></p><?php endif; ?>
<?php if ($isDraft && !$isNew && $issueProblems): ?><div class="alert warn keep df-warn"><?= admin_icon('alert') ?><div><strong>Para <?= $inv ? 'emitir esta factura' : 'confirmar este ' . e(mb_strtolower($k['label'])) ?> falta:</strong><ul><?php foreach ($issueProblems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
<?php if ($orig): ?><p class="hint">Rectifica la factura <a href="<?= e(url('/admin/facturas/' . (int)$orig['id'])) ?>"><?= e($orig['doc_code']) ?></a> del <?= e(erp_day((int)$orig['doc_day'])) ?> · <?= e(DOCS_RECT_MODES[$saved['rect_mode']] ?? '') ?> · <?= e(DOCS_RECT_REASONS[$saved['rect_reason']] ?? $saved['rect_reason']) ?></p><?php endif; ?>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<?php if ($warnings): ?><div class="alert warn keep df-warn"><?= admin_icon('alert') ?><ul><?php foreach ($warnings as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" action="<?= e(url($self)) ?>" id="docform" class="df" autocomplete="off">
  <?= csrf_field() ?>
  <button type="submit" name="action" value="recalc" class="df-default" tabindex="-1" aria-hidden="true">Recalcular</button><?php /* Intro en un campo = recalcular, nunca guardar */ ?>
  <input type="hidden" name="stamp" value="<?= (int)$stamp ?>">
  <input type="hidden" name="extra" value="<?= (int)$extra ?>">
  <input type="hidden" name="extra_t" value="<?= (int)$extraText ?>">
  <input type="hidden" name="h[cust_prev]" value="<?= e((string)$doc['cust_code']) ?>">
  <fieldset class="df-fs"<?= $ro ? ' disabled' : '' ?>>
  <legend class="sr-only">Datos del documento</legend>

  <div class="if-head">
    <section class="if-box" aria-label="Documento">
      <h2>Documento</h2>
      <div class="df-g">
        <label>Serie
          <?php if ($isNew): ?>
            <select name="h[series_id]"><?php foreach ($series as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$s['id'] === (int)$doc['series_id'] ? ' selected' : '' ?>><?= e($s['letter']) ?> · <?= e($s['descr']) ?></option><?php endforeach; ?></select>
          <?php elseif ($isDraft): $sr = series_get((int)$doc['series_id']); ?>
            <input value="<?= e(($sr['letter'] ?? '?') . ' · se numera al ' . ($inv ? 'emitir' : 'confirmar')) ?>" readonly>
          <?php else: ?>
            <input value="<?= e($doc['doc_code']) ?>" readonly>
          <?php endif; ?>
        </label>
        <label>Fecha <input type="date" name="h[day]" value="<?= e(erp_day_to_input((int)$doc['doc_day'])) ?>" required></label>
        <label>Forma de pago
          <select name="h[pay_code]"><option value="">—</option><?php foreach ($methods as $m): ?><option value="<?= e($m['code']) ?>"<?= (string)$m['code'] === (string)$doc['pay_code'] ? ' selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name'] !== '' ? $m['name'] : 'sin nombre') ?></option><?php endforeach; ?></select></label>
        <label>Tarifa
          <?php if ($askTariff || $isNew): ?>
            <select name="h[tariff]"><?php foreach ($tariffs as $t): ?><option value="<?= (int)$t['num'] ?>"<?= (int)$t['num'] === (int)$doc['tariff'] ? ' selected' : '' ?>><?= (int)$t['num'] ?> · <?= e($t['name']) ?></option><?php endforeach; ?></select>
          <?php else: ?>
            <input type="hidden" name="h[tariff]" value="<?= (int)$doc['tariff'] ?>"><input value="<?= (int)$doc['tariff'] ?>" readonly title="La tarifa la fija el cliente (Configuración → Parámetros: «Pedir siempre tarifa» desactivado)">
          <?php endif; ?></label>
        <label class="df-wide">Referencia <input name="h[ref_text]" value="<?= e($doc['ref_text']) ?>" maxlength="100" placeholder="Su pedido, obra, persona de contacto…"></label>
        <?php if ($inv): ?>
          <label>Fecha de operación <input type="date" name="h[op_day]" value="<?= e(erp_day_to_input((int)$doc['op_day'])) ?>"><small>Solo si no es la de la factura.</small></label>
          <label class="df-wide">Mención legal <input name="h[vat_note]" value="<?= e((string)$doc['vat_note']) ?>" maxlength="200" placeholder="Ej.: Operación exenta (art. 20 LIVA)"><small>Obligatoria si hay líneas con IVA 0 % (exención, inversión del sujeto pasivo…).</small></label>
          <?php if ($kind === 'rectificativa'): ?><label class="df-wide">Qué se corrige <input name="h[rect_text]" value="<?= e((string)$doc['rect_text']) ?>" maxlength="200" required></label><?php endif; ?>
        <?php endif; ?>
        <label class="check"><input type="checkbox" name="h[vat_incl]" value="1"<?= (int)$doc['vat_incl'] === 1 ? ' checked' : '' ?>> <span>Precios con IVA incluido</span></label>
        <label class="check"><input type="checkbox" name="h[with_re]" value="1"<?= (int)$doc['with_re'] === 1 ? ' checked' : '' ?>> <span>Recargo de equivalencia</span></label>
      </div>
    </section>
    <section class="if-box" aria-label="Cliente">
      <h2>Cliente <?php if ($custLink !== ''): ?><a class="if-link" href="<?= e($custLink) ?>">Ver ficha →</a><?php endif; ?></h2>
      <?php if (!$hasSrc && !$ro): ?>
      <div class="df-find">
        <label>Buscar cliente <small>(por código, nombre o NIF)</small>
          <input type="search" id="cust-q" name="cust_q" value="<?= e($custQuery) ?>" placeholder="Escribe al menos 2 letras o números" autocomplete="off" aria-controls="cust-res">
        </label>
        <noscript><button class="btn small" type="submit" name="action" value="search">Buscar</button></noscript>
        <ul id="cust-res" class="df-res" aria-live="polite"<?= $custResults === null ? ' hidden' : '' ?>>
          <?php foreach ((array)$custResults as $r): $bl = (int)$r['blocked'] === 1 ? 'bloqueado' : ((int)$r['ended_day'] > 0 ? 'de baja' : ''); ?>
            <li><button type="submit" name="action" value="pick:<?= e($r['cust_code']) ?>"<?= $bl !== '' ? ' disabled' : '' ?>><?= e($r['cust_code']) ?> · <?= e($r['name'] !== '' ? $r['name'] : $r['trade_name']) ?><?= $r['nif'] !== '' ? ' · ' . e($r['nif']) : '' ?><?= $r['city'] !== '' ? ' · ' . e($r['city']) : '' ?><?= $bl !== '' ? ' — ' . e($bl) . ' (no se puede elegir)' : '' ?></button></li>
          <?php endforeach; ?>
          <?php if ($custResults !== null && !$custResults): ?><li class="muted">Sin resultados<?= mb_strlen($custQuery) < 2 ? ' (escribe al menos 2 caracteres)' : '' ?>.</li><?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>
      <div class="df-g">
        <label>Código <input name="h[cust_code]" value="<?= e((string)$doc['cust_code']) ?>" maxlength="40"<?= $hasSrc ? ' readonly title="Las líneas vienen de un pedido: el cliente no se cambia"' : '' ?>>
          <small><?= $kind === 'simplificada' ? 'Opcional en un tique.' : 'Vacío = cliente sin ficha.' ?></small></label>
        <label class="df-wide">Nombre <input name="h[cust_name]" value="<?= e($isRetail && $doc['cust_name'] === '' ? 'Cliente de contado' : $doc['cust_name']) ?>" maxlength="255"></label>
        <label>NIF <input name="h[cust_nif]" value="<?= e($doc['cust_nif']) ?>" maxlength="40"></label>
        <label class="df-wide">Dirección <input name="h[cust_address]" value="<?= e($doc['cust_address']) ?>" maxlength="255"></label>
        <label>C. P. <input name="h[cust_postcode]" value="<?= e($doc['cust_postcode']) ?>" maxlength="20"></label>
        <label>Población <input name="h[cust_city]" value="<?= e($doc['cust_city']) ?>" maxlength="120"></label>
        <label>Provincia <input name="h[cust_province]" value="<?= e($doc['cust_province']) ?>" maxlength="120"></label>
        <label>Correo <input type="email" name="h[cust_email]" value="<?= e($doc['cust_email']) ?>" maxlength="190"></label>
      </div>
      <p class="muted df-note"><small>Al elegir un cliente se rellenan solos sus datos, su tarifa y su forma de pago; se pueden cambiar en este documento. Los clientes bloqueados o de baja no se pueden elegir.</small></p>
    </section>
  </div>

  <?php if (!$isNew): ?>
  <div class="if-grid-wrap">
    <table class="if-grid df-grid">
      <thead><tr>
        <th class="c">Nº</th><th>Ref.</th><th>Descripción</th><th>Talla</th><th>Color</th><th class="r">Uds.</th>
        <?php if ($kind === 'pedido' && $hasDone): ?><th class="r" title="Unidades ya servidas en albaranes">Serv.</th><?php endif; ?>
        <th class="r">Precio</th><th class="r">Dto.%</th><th>IVA</th><th class="r">Importe</th><th class="c" title="Quitar la línea al guardar">Quitar</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $row):
          $l = $row['l'];
          $m = $row['m'];
          $nm = 'l[' . $i . ']';
          $hasProd = $m['prod'] !== null;
          $lk = $m['locked'];
          $txt = $textOf($l);
      ?>
        <?php if ($row['text']): ?>
        <tr class="df-text<?= $l === null ? ' df-blank' : '' ?>">
          <td class="c muted"><?= $i + 1 ?><input type="hidden" name="<?= $nm ?>[id]" value="<?= (int)($l['id'] ?? 0) ?>"><input type="hidden" name="<?= $nm ?>[kind]" value="1"></td>
          <td colspan="<?= $ncols - 2 ?>"><textarea class="df-title df-txt" name="<?= $nm ?>[title]" rows="<?= $rowsOf($txt) ?>" placeholder="Texto libre: sin unidades ni precio" aria-label="Texto de la línea <?= $i + 1 ?>"><?= e($txt) ?></textarea></td>
          <td class="c"><?php if ($l !== null): ?><input type="checkbox" name="<?= $nm ?>[del]" value="1" aria-label="Quitar la línea <?= $i + 1 ?>"><?php endif; ?></td>
        </tr>
        <?php else: ?>
        <tr<?= $l === null ? ' class="df-blank"' : '' ?>>
          <td class="c muted"><?= $i + 1 ?>
            <input type="hidden" name="<?= $nm ?>[id]" value="<?= (int)($l['id'] ?? 0) ?>">
            <input type="hidden" name="<?= $nm ?>[kind]" value="0">
            <input type="hidden" name="<?= $nm ?>[oref]" value="<?= e((string)($l['ref'] ?? '')) ?>"></td>
          <td><input class="df-ref" name="<?= $nm ?>[ref]" value="<?= e((string)($l['ref'] ?? '')) ?>" maxlength="100" list="dl-art" data-search="art" aria-label="Referencia de la línea <?= $i + 1 ?>"<?= $lk ? ' readonly title="Línea servida o venida de un pedido: el artículo no se cambia"' : '' ?>>
            <?php if ($hasProd && !empty($m['prod']['id'])): ?><a class="df-plink" href="<?= e(url('/admin/articulo/' . (int)$m['prod']['id'])) ?>" target="_blank" rel="noopener" title="Abrir el artículo">↗</a><?php endif; ?></td>
          <td><textarea class="df-title" name="<?= $nm ?>[title]" rows="<?= $rowsOf($txt) ?>" aria-label="Descripción de la línea <?= $i + 1 ?>"<?= $hasProd && !$askTitle ? ' title="La primera línea es el nombre del artículo (se pone solo); escribe debajo lo que quieras añadir"' : '' ?>><?= e($txt) ?></textarea></td>
          <td><input class="df-s" name="<?= $nm ?>[size]" value="<?= e((string)($l['size_txt'] ?? '')) ?>" maxlength="40" list="dl-s<?= $i ?>" aria-label="Talla de la línea <?= $i + 1 ?>"<?= $lk ? ' readonly' : '' ?>>
            <?php if ($m['opts']['sizes']): ?><datalist id="dl-s<?= $i ?>"><?php foreach ($m['opts']['sizes'] as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist><?php endif; ?></td>
          <td><input class="df-c" name="<?= $nm ?>[color]" value="<?= e((string)($l['color_txt'] ?? '')) ?>" maxlength="60" list="dl-c<?= $i ?>" aria-label="Color de la línea <?= $i + 1 ?>"<?= $lk ? ' readonly' : '' ?>>
            <?php if ($m['opts']['colors']): ?><datalist id="dl-c<?= $i ?>"><?php foreach ($m['opts']['colors'] as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist><?php endif; ?></td>
          <td class="r"><input class="df-n" name="<?= $nm ?>[qty]" value="<?= $l !== null ? e($num((int)$l['qty_u'], DOCS_QTY, $decQ)) : '' ?>" inputmode="decimal" aria-label="Unidades de la línea <?= $i + 1 ?>" data-q<?= $inv && $l !== null && (int)($l['src_line_id'] ?? 0) > 0 ? ' readonly title="Unidades del albarán"' : '' ?>></td>
          <?php if ($kind === 'pedido' && $hasDone): ?><td class="r muted"><?= $l !== null && (int)$l['done_u'] > 0 ? e(docs_qty_txt((int)$l['done_u'])) : '' ?></td><?php endif; ?>
          <td class="r"><input class="df-n" name="<?= $nm ?>[price]" value="<?= $l !== null ? e($num((int)$l['price_u'], DOCS_PRICE, $decP)) : '' ?>" inputmode="decimal" aria-label="Precio de la línea <?= $i + 1 ?>" data-p<?= $hasProd && !$askPrice ? ' readonly title="El precio sale de la tarifa (Configuración → Parámetros: «Pedir siempre precio» desactivado)"' : '' ?>></td>
          <td class="r"><input class="df-d" name="<?= $nm ?>[disc]" value="<?= $l !== null && (int)$l['disc_bp'] > 0 ? e($num((int)$l['disc_bp'], 100, 2)) : '' ?>" inputmode="decimal" aria-label="Descuento de la línea <?= $i + 1 ?>" data-d<?= !$askDisc ? ' readonly title="El descuento es el del cliente (Configuración → Parámetros: «Pedir siempre descuento» desactivado)"' : '' ?>></td>
          <td><select name="<?= $nm ?>[vat]" aria-label="IVA de la línea <?= $i + 1 ?>">
            <?php $cur = (int)($l['vat_id'] ?? docs_vat_default()); if ($l !== null && !isset($rates[$cur])): ?><option value="<?= $cur ?>" selected><?= e($fmtPct((int)$l['vat_bp'] / 100)) ?> (desactivado)</option><?php endif; ?>
            <?php foreach ($rates as $rid => $r): ?><option value="<?= (int)$rid ?>"<?= $rid === $cur ? ' selected' : '' ?>><?= e(vat_format_pct((int)$r['pct_bp'])) ?> %</option><?php endforeach; ?>
          </select></td>
          <td class="r df-amt" data-amt><?= $l !== null ? e(docs_amount_txt((int)$l['amount_u'])) : '' ?></td>
          <td class="c"><?php if ($l !== null): ?><input type="checkbox" name="<?= $nm ?>[del]" value="1" aria-label="Quitar la línea <?= $i + 1 ?>"<?= (int)($l['done_u'] ?? 0) > 0 ? ' disabled title="Ya servida"' : '' ?>><?php endif; ?></td>
        </tr>
        <?php endif; ?>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <datalist id="dl-art"></datalist>
  <?php if (!$ro): ?><p class="muted df-note"><small>Escribe la referencia y pulsa <strong>Recalcular</strong> (o Intro): se completan la descripción, el precio de la tarifa y el IVA. Sin referencia es un <strong>concepto</strong> (por ejemplo «Portes»: descripción, unidades y precio). «Añadir texto» pone una línea de <strong>texto libre</strong> sin unidades ni precio. La descripción admite varias líneas. <?= (int)$doc['vat_incl'] === 1 ? 'Importes con IVA incluido.' : 'Importes sin IVA.' ?></small></p><?php endif; ?>
  <div class="if-foot">
    <div class="if-tabs-wrap">
      <label class="df-notes">Observaciones (salen en el documento impreso)
        <textarea name="h[notes]" rows="3" maxlength="2000"><?= e((string)$doc['notes']) ?></textarea></label>

      <h2 class="df-h">Desglose de IVA</h2>
      <?php if (!$tot['vat']): ?><p class="empty">Sin líneas.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th scope="col">IVA</th><th scope="col">Base</th><th scope="col">Cuota</th><?php if ($tot['rec'] !== 0): ?><th scope="col">Recargo</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($tot['vat'] as $r): ?>
          <tr><td><?= e($fmtPct($r['pct'])) ?><?= $r['rec_pct'] > 0 ? ' <small class="muted">+ ' . e($fmtPct($r['rec_pct'])) . ' R.E.</small>' : '' ?></td><td><?= e(erp_money((int)$r['base'])) ?></td><td><?= e(erp_money((int)$r['cuota'])) ?></td><?php if ($tot['rec'] !== 0): ?><td><?= e(erp_money((int)$r['rec'])) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <aside class="if-totals" aria-label="Totales">
      <div class="ir"><span>Base imponible</span><span><?= e(erp_money($tot['base'])) ?></span></div>
      <div class="ir"><span>IVA</span><span><?= e(erp_money($tot['vat_cents'])) ?></span></div>
      <?php if ($tot['rec'] !== 0): ?><div class="ir"><span>Recargo de equivalencia</span><span><?= e(erp_money($tot['rec'])) ?></span></div><?php endif; ?>
      <div class="ir big"><span>TOTAL</span><span><?= e(erp_money($tot['total'])) ?></span></div>
      <p class="muted df-stale" hidden><small>Has cambiado líneas: pulsa «Recalcular» para actualizar los totales.</small></p>
      <?php if (!$ro): ?><div class="buttons" style="margin-top:10px"><button class="btn" type="submit" name="action" value="save">Guardar</button></div><?php endif; ?>
    </aside>
  </div>
  <?php else: ?>
  <p class="hint"><strong>Primer paso:</strong> elige el cliente, la fecha, la serie y la forma de pago y pulsa <strong>Grabar cabecera</strong>. Se crea un <strong>borrador</strong> sin número y a continuación añades las líneas (artículos, conceptos o texto). El número (<?= e($k['label']) ?>) se asigna solo al <?= $inv ? 'emitir' : 'confirmar' ?>.</p>
  <div class="buttons"><button class="btn" type="submit" name="action" value="header">Grabar cabecera</button></div>
  <?php endif; ?>
  </fieldset>
</form>

<?php if (!$isNew): ?>
  <form id="f-annul" method="post" action="<?= e(url($self)) ?>" onsubmit="return confirm('¿Anular <?= e($k['label'] . ' ' . $doc['doc_code']) ?>? Conserva su número, pero ya no se podrá modificar.')"><?= csrf_field() ?><input type="hidden" name="action" value="annul"></form>
  <?php if (!$inv && !$isNew): ?><form id="f-dup" method="post" action="<?= e(url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"></form><?php endif; ?>
  <?php if ($kind === 'pedido'): ?><form id="f-serve" method="post" action="<?= e(url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="serve"></form><?php endif; ?>
  <?php if ($kind === 'albaran'): ?><form id="f-bill" method="post" action="<?= e(url($self)) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="bill"></form><?php endif; ?>
  <?php if ($isDraft): ?>
    <form id="f-done" method="post" action="<?= e(url($self)) ?>" onsubmit="return confirm('<?= $inv ? '¿Emitir la factura? Tomará el siguiente número de la serie y desde ese momento NO se podrá modificar ni borrar (solo corregir con una rectificativa).' : '¿Confirmar? Toma el siguiente número de la serie y el borrador pasa a ser un ' . e(mb_strtolower($k['label'])) . ' con número.' ?>')"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $inv ? 'issue' : 'confirm' ?>"><input type="hidden" name="stamp" value="<?= (int)$stamp ?>"></form>
    <form id="f-delete" method="post" action="<?= e(url($self)) ?>" onsubmit="return confirm('¿Borrar este borrador?<?= $inv ? ' Si venía de albaranes, quedarán otra vez pendientes de facturar.' : '' ?>')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"></form>
    <p class="muted"><small>Guarda los cambios antes de <?= $inv ? 'emitir' : 'confirmar' ?>: «<?= $doneLabel ?>» usa lo último guardado.</small></p>
  <?php endif; ?>
  <?php if ($issued): ?>
    <section class="if-box df-rect" id="rectificar">
      <h2>Rectificar esta factura</h2>
      <form method="post" action="<?= e(url($self)) ?>" class="df-g">
        <?= csrf_field() ?><input type="hidden" name="action" value="rectify">
        <label class="df-wide">Cómo
          <select name="mode"><?php foreach (DOCS_RECT_MODES as $mk => $ml): ?><option value="<?= e($mk) ?>"><?= e($ml) ?></option><?php endforeach; ?></select></label>
        <?php if ($kind !== 'simplificada'): ?>
          <label class="df-wide">Motivo
            <select name="reason" required><option value="">— elige —</option><?php foreach (DOCS_RECT_REASONS as $rk => $rl): if ($rk === 'R5') { continue; } ?><option value="<?= e($rk) ?>"><?= e($rl) ?></option><?php endforeach; ?></select></label>
        <?php else: ?>
          <input type="hidden" name="reason" value="R5"><p class="muted df-wide" style="margin:0">Motivo: R5 (rectificación de una factura simplificada).</p>
        <?php endif; ?>
        <label class="df-wide">Qué se corrige <input name="text" maxlength="200" required placeholder="Ej.: devolución de 1 par; precio mal aplicado"></label>
        <div class="buttons df-wide"><button class="btn" type="submit">Crear borrador de rectificativa</button></div>
      </form>
      <p class="muted" style="margin:6px 0 0"><small><strong>Por diferencias</strong>: el borrador lleva todas las líneas en negativo; quita las que no cambian y ajusta las demás. <strong>Por sustitución</strong>: lleva las líneas de la factura para corregirlas; al emitirla, la original queda «sustituida» y deja de contar en los informes. Confirma el motivo con tu asesoría.</small></p>
    </section>
  <?php endif; ?>

  <div class="df-side">
    <section>
      <h2 class="df-h">Documentos relacionados</h2>
      <?php if (!$related): ?><p class="muted"><?= $kind === 'pedido' ? 'Todavía no se ha servido nada.' : ($kind === 'albaran' ? 'Este albarán no viene de ningún pedido ni se ha facturado.' : 'Ninguno.') ?></p><?php else: ?>
        <ul class="if-hist">
          <?php foreach ($related as $r): $rk = DOCS_KINDS[$r['doc_type']] ?? null; if (!$rk) { continue; } ?>
            <li><span class="when"><?= e(erp_day((int)$r['doc_day'])) ?></span><a href="<?= e(url($rk['path'] . '/' . (int)$r['id'])) ?>"><?= e($rk['label'] . ' ' . ($r['status'] === 'borrador' ? '(borrador)' : $r['doc_code'])) ?></a> <span class="muted">· <?= e(docs_status_label($r)) ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
    <section>
      <h2 class="df-h">Historial</h2>
      <ul class="if-hist">
        <?php foreach ($events as $ev): ?>
          <li><span class="when"><?= e(date('d/m/Y H:i', (int)$ev['created_at'])) ?></span><strong><?= e(DOCS_EVENT_LABELS[$ev['kind']] ?? $ev['kind']) ?></strong><?= $ev['detail'] !== '' ? ' — ' . e($ev['detail']) : '' ?><span class="muted"><?= $ev['username'] !== '' ? ' · ' . e($ev['username']) : '' ?></span></li>
        <?php endforeach; ?>
        <?php if (!$events): ?><li class="muted">Sin movimientos.</li><?php endif; ?>
      </ul>
    </section>
  </div>
<?php endif; ?>

<?php if (!$ro): ?>
<script>
(function () {
  var form = document.getElementById('docform');
  if (!form) { return; }
  var base = <?= json_encode(url($k['path'] . '/buscar'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var decA = <?= (int)docs_dec('dec_amount') ?>;
  var timers = {};
  function field(name) { return form.querySelector('[name="' + name + '"]'); }
  function grow(el) { el.style.height = 'auto'; el.style.height = (el.scrollHeight + 2) + 'px'; }
  form.querySelectorAll('textarea.df-title').forEach(grow);

  // Buscador de cliente: por código, nombre o NIF mientras escribes; al elegir se rellenan sus datos, tarifa y forma de pago
  var cq = document.getElementById('cust-q'), cres = document.getElementById('cust-res');
  function pick(it) {
    var set = function (n, v) { var f = field(n); if (f) { f.value = v || ''; } };
    set('h[cust_code]', it.v); set('h[cust_name]', it.name); set('h[cust_nif]', it.nif); set('h[cust_address]', it.address);
    set('h[cust_postcode]', it.postcode); set('h[cust_city]', it.city); set('h[cust_province]', it.province); set('h[cust_email]', it.email);
    set('h[cust_prev]', it.v); // así el servidor respeta lo que se cambie a mano después
    var t = field('h[tariff]');
    if (t && it.tariff > 0 && (t.tagName !== 'SELECT' || t.querySelector('option[value="' + it.tariff + '"]'))) { t.value = String(it.tariff); }
    var p = field('h[pay_code]');
    if (p && it.pay && p.querySelector('option[value="' + it.pay.replace(/"/g, '') + '"]')) { p.value = it.pay; }
    cres.hidden = true; cres.textContent = ''; cq.value = it.v + ' · ' + (it.name || '');
    var st = document.querySelector('.df-stale'); if (st) { st.hidden = false; }
  }
  if (cq && cres) {
    cq.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); } });
    cq.addEventListener('input', function () {
      clearTimeout(timers.cli);
      var q = cq.value.trim();
      if (q.length < 2) { cres.hidden = true; cres.textContent = ''; return; }
      timers.cli = setTimeout(function () {
        fetch(base + '?t=cli&q=' + encodeURIComponent(q), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (list) {
            cres.textContent = '';
            if (!list.length) { var e = document.createElement('li'); e.className = 'muted'; e.textContent = 'Sin resultados.'; cres.appendChild(e); }
            list.forEach(function (it) {
              var li = document.createElement('li'), b = document.createElement('button');
              b.type = 'button';
              b.textContent = it.v + ' · ' + (it.name || '') + (it.nif ? ' · ' + it.nif : '') + (it.city ? ' · ' + it.city : '') + (it.blocked ? ' — ' + it.blocked + ' (no se puede elegir)' : '');
              if (it.blocked) { b.disabled = true; } else { b.addEventListener('click', function () { pick(it); }); }
              li.appendChild(b); cres.appendChild(li);
            });
            cres.hidden = false;
          }).catch(function () {});
      }, 200);
    });
  }

  // Sugerencias de artículos (la lista se rellena al escribir) e importe de la línea al momento
  form.addEventListener('input', function (ev) {
    var el = ev.target, t = el.getAttribute('data-search');
    if (el.matches && el.matches('textarea.df-title')) { grow(el); }
    if (t === 'art') {
      clearTimeout(timers[t]);
      var q = el.value.trim();
      if (q.length < 2) { return; }
      timers[t] = setTimeout(function () {
        fetch(base + '?t=art&q=' + encodeURIComponent(q), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
          .then(function (r) { return r.ok ? r.json() : []; })
          .then(function (list) {
            var dl = document.getElementById('dl-art');
            dl.textContent = '';
            list.forEach(function (it) { var o = document.createElement('option'); o.value = it.v; o.label = it.l; o.textContent = it.l; dl.appendChild(o); });
          }).catch(function () {});
      }, 200);
    }
    var tr = el.closest('tr');
    if (tr && (el.hasAttribute('data-q') || el.hasAttribute('data-p') || el.hasAttribute('data-d'))) {
      var n = function (s) { s = (s || '').replace(/[^\d,.\-]/g, ''); if (s.indexOf(',') > -1 && s.indexOf('.') > -1) { s = s.lastIndexOf(',') > s.lastIndexOf('.') ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, ''); } else { s = s.replace(',', '.'); } var v = parseFloat(s); return isNaN(v) ? null : v; };
      var q2 = n(tr.querySelector('[data-q]').value), p = n(tr.querySelector('[data-p]').value), d = n(tr.querySelector('[data-d]').value) || 0;
      var cell = tr.querySelector('[data-amt]');
      if (q2 !== null && p !== null) {
        var a = q2 * p * (1 - d / 100);
        cell.textContent = a.toLocaleString('es-ES', {minimumFractionDigits: decA, maximumFractionDigits: decA}) + ' €';
      }
      var st = document.querySelector('.df-stale'); if (st) { st.hidden = false; }
    }
  });
  // Aviso de cambios sin guardar (no al pulsar un botón del formulario)
  var start = new URLSearchParams(new FormData(form)).toString(), sending = false;
  document.addEventListener('submit', function () { sending = true; });
  window.addEventListener('beforeunload', function (ev) {
    if (!sending && new URLSearchParams(new FormData(form)).toString() !== start) { ev.preventDefault(); ev.returnValue = ''; }
  });
})();
</script>
<?php endif; ?>
