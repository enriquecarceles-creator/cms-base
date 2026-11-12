<?php
/**
 * Ficha de cliente compacta, estilo ERP. Modo consulta (datos como texto) y modo edición (?editar=1, mismo diseño con campos).
 * Bloques apilados General · Contacto · Comercial · Documentos · Notas internas (sin JavaScript; el menú de arriba son anclas).
 * Variables: $c (guardado), $f (lo que se pinta: guardado o lo escrito en un guardado fallido), $edit, $own (cliente de NCS: solo se editan los
 * campos propios del CMS), $errors, $self, $tariffs, $methods, $series, $quick, $docs, $docType, $notes, $nDocs, $nb, $isRetail, $nifWarn, $nifDups.
 */
$code = (string)$c['cust_code'];
$title1 = $c['name'] !== '' ? $c['name'] : ($c['trade_name'] !== '' ? $c['trade_name'] : 'Cliente ' . $code);
$ended = (int)$c['ended_day'] > 0;
$blocked = (int)$c['blocked'] === 1;
$off = $blocked || $ended;
$canCode = $edit && !$own && $nDocs === 0;
$txt = fn(string $k) => (string)($f[$k] ?? '');
$or = fn(string $s) => $s !== '' ? e($s) : '<span class="muted">—</span>';
/** Un dato: texto en consulta, campo en edición. $span = columnas de 12 que ocupa; $editable = false para lo que no se puede cambiar en un cliente de NCS. */
$fld = function (string $k, string $label, int $span, array $o = []) use ($edit, $own, $f, $or): string {
    $v = (string)($f[$k] ?? '');
    $ownField = in_array($k, ERP_CUST_OWN, true);
    $canEdit = $edit && (!$own || $ownField) && ($o['can'] ?? true);
    $h = '<div class="cfld s' . $span . '"><label class="cl"' . ($canEdit ? ' for="f-' . e($k) . '"' : '') . '>' . e($label) . '</label>';
    if ($canEdit) {
        $h .= '<input id="f-' . e($k) . '" name="' . e($k) . '" value="' . e($v) . '" maxlength="' . (int)($o['max'] ?? 255) . '"' . (!empty($o['type']) ? ' type="' . e($o['type']) . '"' : '') . (!empty($o['mode']) ? ' inputmode="' . e($o['mode']) . '"' : '') . (!empty($o['ph']) ? ' placeholder="' . e($o['ph']) . '"' : '') . '>';
    } else {
        $h .= '<span class="cv">' . ($v !== '' ? (!empty($o['link']) ? '<a href="' . e(sprintf($o['link'], rawurlencode($v))) . '">' . e($v) . '</a>' : e($v)) : '<span class="muted">—</span>') . '</span>';
    }
    return $h . '</div>';
};
$pay = $txt('pay_code');
$tariffTxt = (int)$f['tariff'] > 0 ? (string)(int)$f['tariff'] : '';
foreach ($tariffs as $t) {
    if ((int)$t['num'] === (int)$f['tariff']) {
        $tariffTxt = (int)$t['num'] . ' · ' . $t['name'];
    }
}
$seriesTxt = '';
foreach ($series as $s) {
    if ((int)$s['id'] === (int)$f['def_series']) {
        $seriesTxt = $s['letter'] . ' · ' . $s['descr'];
    }
}
$self_ = url($self);
?>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>

<form method="post" action="<?= e($self_) ?>" id="cform" autocomplete="off" class="cfc<?= $edit ? ' is-edit' : '' ?>">
<?php if ($edit): ?><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="stamp" value="<?= (int)$c['rev'] ?>"><?php endif; ?>

<header class="cc-head">
  <div class="cc-id">
    <p class="cc-back"><a href="<?= e(url('/admin/clientes')) ?>">← Clientes</a></p>
    <h1><span class="cc-code"><?= e($code) ?></span> <?= e($title1) ?></h1>
    <?php if ($c['trade_name'] !== '' && $c['trade_name'] !== $c['name']): ?><p class="cc-trade"><?= e($c['trade_name']) ?></p><?php endif; ?>
    <p class="cc-meta"><?php if ($c['nif'] !== ''): ?>NIF <strong><?= e($c['nif']) ?></strong> · <?php endif; ?>
      <?php if ($ended): ?><span class="tag tag-off">De baja</span><?php elseif ($blocked): ?><span class="tag tag-off">Bloqueado</span><?php else: ?><span class="tag tag-ok">Activo</span><?php endif; ?>
      <?php if (!$own): ?><span class="tag">Origen: CMS</span><?php else: ?><span class="tag">Origen: NCS</span><?php endif; ?>
      <?php if ($isRetail): ?><span class="tag">Contado</span><?php endif; ?></p>
  </div>
  <div class="cc-actions">
    <?php if ($edit): ?>
      <button class="btn small" type="submit">Guardar</button>
      <a class="btn small ghost" href="<?= e($self_) ?>">Cancelar</a>
    <?php else: ?>
      <a class="btn small" href="<?= e(url($self . '?editar=1')) ?>">Modificar</a>
      <?php if (!$off): ?>
      <details class="cc-menu"><summary class="btn small ghost">Nuevo documento ▾</summary>
        <div class="cc-pop">
          <a href="<?= e(url('/admin/pedidos/nuevo?cliente=' . rawurlencode($code))) ?>">Pedido</a>
          <a href="<?= e(url('/admin/albaranes/nuevo?cliente=' . rawurlencode($code))) ?>">Albarán</a>
          <a href="<?= e(url('/admin/facturas/nuevo?cliente=' . rawurlencode($code))) ?>">Factura</a>
        </div></details>
      <?php endif; ?>
      <?php if ($nb['prev']): ?><a class="btn small ghost" href="<?= e(url('/admin/clientes/' . rawurlencode($nb['prev']['cust_code']))) ?>" rel="prev" title="<?= e($nb['prev']['name']) ?>">‹ Anterior</a><?php endif; ?>
      <?php if ($nb['next']): ?><a class="btn small ghost" href="<?= e(url('/admin/clientes/' . rawurlencode($nb['next']['cust_code']))) ?>" rel="next" title="<?= e($nb['next']['name']) ?>">Siguiente ›</a><?php endif; ?>
      <?php if (!$own): ?>
        <button class="btn small ghost" type="submit" form="cform-block"><?= $blocked ? 'Desbloquear' : 'Bloquear' ?></button>
        <?php if ($nDocs === 0): ?>
        <details class="cc-menu"><summary class="btn small ghost danger">Borrar</summary>
          <div class="cc-pop"><label class="check"><input type="checkbox" name="confirm" value="1" form="cform-del"> <span>Confirmo que quiero borrar este cliente</span></label>
            <button class="btn small danger" type="submit" form="cform-del">Borrar cliente</button></div></details>
        <?php endif; ?>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</header>

<section class="cc-quick" aria-label="Datos rápidos">
  <div><span class="cl">Facturado en <?= (int)$quick['year'] ?></span><strong><?= e(erp_money($quick['billed'])) ?></strong></div>
  <div><span class="cl">Pendiente de cobro</span><strong class="<?= $quick['pending'] > 0 ? 'warn' : '' ?>"><?= e(erp_money($quick['pending'])) ?></strong></div>
  <div><span class="cl">Último documento</span><strong><?php if ($quick['last']): ?><a href="<?= e(url($quick['last']['url'])) ?>"><?= e($quick['last']['label'] . ' ' . $quick['last']['code']) ?></a> <small class="muted"><?= e(erp_day((int)$quick['last']['day'])) ?></small><?php else: ?><span class="muted">—</span><?php endif; ?></strong></div>
  <div><span class="cl">Documentos</span><strong><?= e(number_format($quick['count'], 0, ',', '.')) ?></strong></div>
</section>

<nav class="cc-nav" aria-label="Secciones de la ficha"><a href="#general">General</a><a href="#contacto">Contacto</a><a href="#comercial">Comercial</a><a href="#documentos">Documentos</a><a href="#notas">Notas internas<?= $notes ? ' (' . count($notes) . ')' : '' ?></a>
  <span class="grow"></span><a href="<?= e(url($self . '?pestana=resumen')) ?>">Análisis de compras ›</a></nav>

<?php if ($edit && $own): ?><p class="alert warn keep"><?= admin_icon('info') ?><span>Este cliente viene de NCS: sus datos se cambian en NCS y llegan al sincronizar. Aquí solo se editan el día de pago, la serie por defecto y las notas para documentos.</span></p><?php endif; ?>
<?php if (!$edit && $nifWarn !== ''): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span><?= e($nifWarn) ?></span></p><?php endif; ?>
<?php if (!$edit && $nifDups): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span>Ese NIF también lo tienen: <?= e(implode(', ', $nifDups)) ?>.</span></p><?php endif; ?>

<section class="cc-card" id="general"><h2>General</h2>
  <div class="cgrid">
    <?= $fld('cust_code', 'Código', 2, ['can' => $canCode, 'max' => 40]) ?>
    <?= $fld('nif', 'NIF', 2, ['max' => 40]) ?>
    <?= $fld('name', 'Nombre o razón social', 4, ['max' => 255]) ?>
    <?= $fld('trade_name', 'Nombre comercial', 4, ['max' => 255]) ?>
    <?= $fld('address', 'Dirección', 6, ['max' => 255]) ?>
    <?= $fld('postcode', 'C. postal', 1, ['max' => 20]) ?>
    <?= $fld('city', 'Población', 3, ['max' => 255]) ?>
    <?= $fld('province', 'Provincia', 2, ['max' => 255]) ?>
    <?= $fld('country', 'País', 2, ['max' => 255]) ?>
  </div>
  <?php if ($edit && !$own && !$canCode): ?><p class="cc-note"><small>El código no se puede cambiar: el cliente ya tiene documentos.</small></p><?php endif; ?>
</section>

<section class="cc-card" id="contacto"><h2>Contacto</h2>
  <div class="cgrid">
    <?= $fld('phone', 'Teléfono', 3, ['max' => 40, 'mode' => 'tel', 'link' => 'tel:%s']) ?>
    <?= $fld('phone2', 'Teléfono 2', 3, ['max' => 40, 'mode' => 'tel', 'link' => 'tel:%s']) ?>
    <?= $fld('email', 'Correo', 3, ['max' => 190, 'type' => 'email', 'link' => 'mailto:%s']) ?>
    <?= $fld('contact', 'Persona de contacto', 3, ['max' => 255]) ?>
  </div>
</section>

<section class="cc-card" id="comercial"><h2>Comercial</h2>
  <div class="cgrid">
    <div class="cfld s2"><label class="cl" for="f-tariff">Tarifa</label>
      <?php if ($edit && !$own): ?><select id="f-tariff" name="tariff"><option value="0">—</option><?php foreach ($tariffs as $t): ?><option value="<?= (int)$t['num'] ?>"<?= (int)$f['tariff'] === (int)$t['num'] ? ' selected' : '' ?>><?= (int)$t['num'] ?> · <?= e($t['name']) ?></option><?php endforeach; ?></select>
      <?php else: ?><span class="cv"><?= $or($tariffTxt) ?></span><?php endif; ?></div>
    <div class="cfld s3"><label class="cl" for="f-pay_code">Forma de pago</label>
      <?php if ($edit && !$own): ?><select id="f-pay_code" name="pay_code"><option value="">—</option><?php foreach ($methods as $m): ?><option value="<?= e($m['code']) ?>"<?= (string)$f['pay_code'] === (string)$m['code'] ? ' selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name'] !== '' ? $m['name'] : 'sin nombre') ?></option><?php endforeach; ?></select>
      <?php else: ?><span class="cv"><?= $or($pay !== '' ? erp_pay_label($pay) : '') ?></span><?php endif; ?></div>
    <?= $fld('pay_days', 'Día de pago', 2, ['max' => 20, 'ph' => 'Ej.: 5 o 5,25']) ?>
    <div class="cfld s2"><label class="cl" for="f-disc">Descuento %</label>
      <?php if ($edit && !$own): ?><input id="f-disc" name="disc" value="<?= e((string)$f['disc_txt']) ?>" maxlength="6" inputmode="decimal">
      <?php else: ?><span class="cv"><?= (int)$f['disc_bp'] > 0 ? e(rtrim(rtrim(number_format($f['disc_bp'] / 100, 2, ',', ''), '0'), ',')) . ' %' : '<span class="muted">—</span>' ?></span><?php endif; ?></div>
    <div class="cfld s3"><label class="cl" for="f-def_series">Serie por defecto</label>
      <?php if ($edit): ?><select id="f-def_series" name="def_series"><option value="0">— la de cada tipo —</option><?php foreach ($series as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$f['def_series'] === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['letter']) ?> · <?= e($s['descr']) ?></option><?php endforeach; ?></select>
      <?php else: ?><span class="cv"><?= $or($seriesTxt) ?></span><?php endif; ?></div>
    <div class="cfld s12"><label class="cl" for="f-doc_notes">Notas para documentos <span class="muted">(se proponen en las observaciones de sus documentos nuevos)</span></label>
      <?php if ($edit): ?><textarea id="f-doc_notes" name="doc_notes" rows="2" maxlength="1000"><?= e($txt('doc_notes')) ?></textarea>
      <?php else: ?><span class="cv"><?= trim($txt('doc_notes')) !== '' ? nl2br(e($txt('doc_notes'))) : '<span class="muted">—</span>' ?></span><?php endif; ?></div>
    <?php if ($edit && !$own): ?>
    <div class="cfld s12"><span class="cl">Estado</span>
      <label class="check"><input type="checkbox" name="blocked" value="1"<?= (int)$f['blocked'] === 1 ? ' checked' : '' ?>> <span>Bloqueado (no se puede elegir en documentos nuevos)</span></label>
      <label class="check"><input type="checkbox" name="ended" value="1"<?= (int)$f['ended_day'] > 0 ? ' checked' : '' ?>> <span>De baja</span></label></div>
    <?php elseif ($ended): ?><div class="cfld s3"><span class="cl">Baja</span><span class="cv"><?= e(erp_day((int)$c['ended_day'])) ?></span></div><?php endif; ?>
  </div>
</section>
</form>
<?php if (!$edit && !$own): ?>
  <form method="post" action="<?= e($self_) ?>" id="cform-block"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $blocked ? 'unblock' : 'block' ?>"></form>
  <?php if ($nDocs === 0): ?><form method="post" action="<?= e($self_) ?>" id="cform-del"><?= csrf_field() ?><input type="hidden" name="action" value="delete"></form><?php endif; ?>
<?php endif; ?>

<section class="cc-card" id="documentos"><h2>Documentos <small class="muted">(<?= e(number_format($nDocs, 0, ',', '.')) ?>)</small></h2>
  <nav class="cc-filter" aria-label="Filtrar por tipo">
    <?php foreach (['' => 'Todos', 'pedido' => 'Pedidos', 'albaran' => 'Albaranes', 'factura' => 'Facturas'] as $tk => $tl): ?>
      <a href="<?= e(url($self . ($tk !== '' ? '?tipo=' . $tk : '') . '#documentos')) ?>"<?= $docType === $tk ? ' aria-current="true"' : '' ?>><?= e($tl) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$docs): ?><p class="empty" style="margin:6px 0 0">No hay documentos<?= $docType !== '' ? ' de este tipo' : '' ?>.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl list cc-tbl">
    <thead><tr><th scope="col">Fecha</th><th scope="col">Tipo</th><th scope="col">Número</th><th scope="col" class="t-num">Total</th><th scope="col">Estado</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr><td class="t-date"><?= e(erp_day((int)$d['day'])) ?></td><td><?= e($d['label']) ?></td><td><a href="<?= e(url($d['url'])) ?>"><?= e($d['code']) ?></a></td><td class="t-num"><?= e(erp_money($d['total'])) ?></td><td><?= e($d['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if (count($docs) >= 100): ?><p class="muted" style="margin:6px 0 0"><small>Se enseñan los 100 más recientes. El historial completo de facturas está en <a href="<?= e(url($self . '?pestana=facturas')) ?>">Análisis de compras</a>.</small></p><?php endif; ?>
  <?php endif; ?>
</section>

<section class="cc-card" id="notas"><h2>Notas internas</h2>
  <p class="muted" style="margin:0 0 6px"><small>Solo las ven los usuarios con acceso a Clientes y Ventas. No se envían a NCS ni se recuperan sincronizando.</small></p>
  <form method="post" action="<?= e($self_) ?>" class="cc-noteform">
    <?= csrf_field() ?><input type="hidden" name="action" value="note_add">
    <textarea name="note" rows="2" maxlength="<?= (int)ERP_NOTE_MAX ?>" required aria-label="Nueva nota" placeholder="Nueva nota…"></textarea>
    <button class="btn small" type="submit">Guardar nota</button>
  </form>
  <?php if (!$notes): ?><p class="empty" style="margin:6px 0 0">Todavía no hay notas.</p><?php else: ?>
    <ul class="cf-notes">
      <?php foreach ($notes as $nt): ?>
        <li>
          <p class="cf-note-t"><?= nl2br(e($nt['note'])) ?></p>
          <div class="muted cf-note-m"><?= e(date('d/m/Y H:i', (int)$nt['created_at'])) ?> · <?= e($nt['username'] !== '' ? $nt['username'] : 'usuario') ?>
            <?php if (is_admin() || (int)$nt['user_id'] === (int)$_SESSION['uid']): ?>
              <form method="post" action="<?= e($self_) ?>" class="inline" onsubmit="return confirm('¿Borrar esta nota?')"><?= csrf_field() ?><input type="hidden" name="action" value="note_del"><input type="hidden" name="id" value="<?= (int)$nt['id'] ?>"><button class="link danger" type="submit">Borrar</button></form>
            <?php endif; ?></div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<p class="cc-foot muted"><small><?= (int)$c['updated_at'] > 0 ? 'Última modificación: ' . e(date('d/m/Y H:i', (int)$c['updated_at'])) . ($c['updated_by'] !== '' ? ' por ' . e($c['updated_by']) : '') : ($own ? 'Datos sincronizados desde NCS.' : 'Sin modificaciones desde su alta.') ?>
  <?= (int)$c['created_day'] > 0 ? ' · Alta: ' . e(erp_day((int)$c['created_day'])) : '' ?></small></p>
