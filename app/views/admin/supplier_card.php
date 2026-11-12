<?php
/**
 * Ficha de proveedor compacta (la misma estructura que la de cliente): consulta (datos como texto) y edición (?editar=1 o alta).
 * Bloques General · Contacto · Comercial · Documentos · Notas internas. Variables: $c (guardado), $f (lo que se pinta), $edit, $isNew, $errors,
 * $methods, $series, $quick, $docs, $docType, $notes, $nDocs, $nb, $self, $nifWarn, $nifDups.
 */
$code = (string)($c['sup_code'] ?? '');
$title1 = ($c['name'] ?? '') !== '' ? $c['name'] : (($c['trade_name'] ?? '') !== '' ? $c['trade_name'] : ($isNew ? 'Nuevo proveedor' : 'Proveedor ' . $code));
$ended = (int)($c['ended_day'] ?? 0) > 0;
$blocked = (int)($c['blocked'] ?? 0) === 1;
$off = $blocked || $ended;
$canCode = $edit && $nDocs === 0;
$txt = fn(string $k) => (string)($f[$k] ?? '');
$or = fn(string $s) => $s !== '' ? e($s) : '<span class="muted">—</span>';
$fld = function (string $k, string $label, int $span, array $o = []) use ($edit, $f, $or): string {
    $v = (string)($f[$k] ?? '');
    $canEdit = $edit && ($o['can'] ?? true);
    $h = '<div class="cfld s' . $span . (!empty($o['bold']) ? ' bold' : '') . '"><label class="cl"' . ($canEdit ? ' for="f-' . e($k) . '"' : '') . '>' . e($label) . '</label>';
    if ($canEdit) {
        $h .= '<input id="f-' . e($k) . '" name="' . e($k) . '" value="' . e($v) . '" maxlength="' . (int)($o['max'] ?? 255) . '"' . (!empty($o['type']) ? ' type="' . e($o['type']) . '"' : '') . (!empty($o['mode']) ? ' inputmode="' . e($o['mode']) . '"' : '') . (!empty($o['ph']) ? ' placeholder="' . e($o['ph']) . '"' : '') . '>';
    } else {
        $h .= '<span class="cv">' . ($v !== '' ? (!empty($o['link']) ? '<a href="' . e(sprintf($o['link'], rawurlencode($v))) . '">' . e($v) . '</a>' : e($v)) : '<span class="muted">—</span>') . '</span>';
    }
    return $h . '</div>';
};
$self_ = url($self);
$f['sup_code'] = $f['sup_code'] ?? $code;
?>
<?php if (!purch_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Compras está desactivada</span></p><?php endif; ?>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>

<form method="post" action="<?= e($self_) ?>" id="cform" autocomplete="off" class="cfc<?= $edit ? ' is-edit' : '' ?>">
<?php if ($edit): ?><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isNew ? 'create' : 'save' ?>"><input type="hidden" name="stamp" value="<?= (int)($c['rev'] ?? 0) ?>"><?php endif; ?>

<header class="cc-head">
  <div class="cc-id">
    <p class="cc-back"><a href="<?= e(url('/admin/proveedores')) ?>">← Proveedores</a></p>
    <h1><span class="cc-code"><?= e($isNew ? ($f['sup_code'] ?? '') : $code) ?></span> <?= e($title1) ?></h1>
    <?php if (($c['trade_name'] ?? '') !== '' && $c['trade_name'] !== $c['name']): ?><p class="cc-trade"><?= e($c['trade_name']) ?></p><?php endif; ?>
    <?php if (!$isNew): ?><p class="cc-meta"><?php if ($c['nif'] !== ''): ?>NIF <strong><?= e($c['nif']) ?></strong> · <?php endif; ?>
      <?php if ($ended): ?><span class="tag tag-off">De baja</span><?php elseif ($blocked): ?><span class="tag tag-off">Bloqueado</span><?php else: ?><span class="tag tag-ok">Activo</span><?php endif; ?></p><?php endif; ?>
  </div>
  <div class="cc-actions">
    <?php if ($edit): ?>
      <button class="btn small" type="submit">Guardar</button>
      <a class="btn small ghost" href="<?= e($isNew ? url('/admin/proveedores') : $self_) ?>">Cancelar</a>
    <?php else: ?>
      <a class="btn small" href="<?= e(url($self . '?editar=1')) ?>">Modificar</a>
      <?php if (!$off): ?>
      <details class="cc-menu"><summary class="btn small ghost">Nuevo documento ▾</summary>
        <div class="cc-pop">
          <a href="<?= e(url('/admin/compras/pedidos/nuevo?proveedor=' . rawurlencode($code))) ?>">Pedido a proveedor</a>
          <a href="<?= e(url('/admin/compras/albaranes/nuevo?proveedor=' . rawurlencode($code))) ?>">Albarán de compra</a>
          <a href="<?= e(url('/admin/compras/facturas/nuevo?proveedor=' . rawurlencode($code))) ?>">Factura recibida</a>
        </div></details>
      <?php endif; ?>
      <?php if ($nb['prev']): ?><a class="btn small ghost" href="<?= e(url('/admin/proveedores/' . rawurlencode($nb['prev']['sup_code']))) ?>" rel="prev" title="<?= e($nb['prev']['name']) ?>">‹ Anterior</a><?php endif; ?>
      <?php if ($nb['next']): ?><a class="btn small ghost" href="<?= e(url('/admin/proveedores/' . rawurlencode($nb['next']['sup_code']))) ?>" rel="next" title="<?= e($nb['next']['name']) ?>">Siguiente ›</a><?php endif; ?>
      <button class="btn small ghost" type="submit" form="cform-block"><?= $blocked ? 'Desbloquear' : 'Bloquear' ?></button>
      <?php if ($nDocs === 0): ?>
      <details class="cc-menu"><summary class="btn small ghost danger">Borrar</summary>
        <div class="cc-pop"><label class="check"><input type="checkbox" name="confirm" value="1" form="cform-del"> <span>Confirmo que quiero borrar este proveedor</span></label>
          <button class="btn small danger" type="submit" form="cform-del">Borrar proveedor</button></div></details>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</header>

<?php if (!$isNew): ?>
<section class="cc-quick" aria-label="Datos rápidos">
  <div><span class="cl">Comprado en <?= (int)$quick['year'] ?></span><strong><?= e(erp_money($quick['bought'])) ?></strong></div>
  <div><span class="cl">Pendiente de pago</span><strong class="<?= $quick['pending'] > 0 ? 'warn' : '' ?>"><?= e(erp_money($quick['pending'])) ?></strong><?= $quick['overdue'] > 0 ? ' <small class="muted">· vencido ' . e(erp_money($quick['overdue'])) . '</small>' : '' ?></div>
  <div><span class="cl">Último documento</span><strong><?php if ($quick['last']): ?><a href="<?= e(url($quick['last']['url'])) ?>"><?= e($quick['last']['label'] . ' ' . $quick['last']['code']) ?></a> <small class="muted"><?= e(erp_day((int)$quick['last']['day'])) ?></small><?php else: ?><span class="muted">—</span><?php endif; ?></strong></div>
  <div><span class="cl">Documentos</span><strong><?= e(number_format($quick['count'], 0, ',', '.')) ?></strong></div>
</section>
<nav class="cc-nav" aria-label="Secciones de la ficha"><a href="#general">General</a><a href="#contacto">Contacto</a><a href="#comercial">Comercial</a><a href="#documentos">Documentos</a><a href="#notas">Notas internas<?= $notes ? ' (' . count($notes) . ')' : '' ?></a></nav>
<?php if (!$edit && $nifWarn !== ''): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span><?= e($nifWarn) ?></span></p><?php endif; ?>
<?php if (!$edit && $nifDups): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span>Ese NIF también lo tienen: <?= e(implode(', ', $nifDups)) ?>.</span></p><?php endif; ?>
<?php endif; ?>

<section class="cc-card" id="general"><h2>General</h2>
  <div class="cgrid">
    <?= $fld('sup_code', 'Código', 2, ['can' => $canCode, 'max' => 40, 'bold' => 1]) ?>
    <?= $fld('name', 'Nombre o razón social', 7, ['max' => 255, 'bold' => 1]) ?>
    <?= $fld('nif', 'NIF', 3, ['max' => 40, 'bold' => 1]) ?>
    <?= $fld('trade_name', 'Nombre comercial', 4, ['max' => 255]) ?>
    <?= $fld('address', 'Dirección', 8, ['max' => 255]) ?>
    <?= $fld('postcode', 'C. postal', 2, ['max' => 20]) ?>
    <?= $fld('city', 'Población', 4, ['max' => 255]) ?>
    <?= $fld('province', 'Provincia', 3, ['max' => 255]) ?>
    <?= $fld('country', 'País', 3, ['max' => 255]) ?>
  </div>
  <?php if ($edit && !$isNew && !$canCode): ?><p class="cc-note"><small>El código no se puede cambiar: el proveedor ya tiene documentos.</small></p><?php endif; ?>
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
    <div class="cfld s3"><label class="cl" for="f-pay_code">Forma de pago</label>
      <?php if ($edit): ?><select id="f-pay_code" name="pay_code"><option value="">—</option><?php foreach ($methods as $m): ?><option value="<?= e($m['code']) ?>"<?= $txt('pay_code') === (string)$m['code'] ? ' selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name'] !== '' ? $m['name'] : 'sin nombre') ?></option><?php endforeach; ?></select>
      <?php else: ?><span class="cv"><?= $or($txt('pay_code') !== '' ? erp_pay_label($txt('pay_code')) : '') ?></span><?php endif; ?></div>
    <?= $fld('pay_days', 'Día de pago', 2, ['max' => 20, 'ph' => 'Ej.: 5 o 5,25']) ?>
    <div class="cfld s2"><label class="cl" for="f-disc">Descuento %</label>
      <?php if ($edit): ?><input id="f-disc" name="disc" value="<?= e((string)($f['disc_txt'] ?? '')) ?>" maxlength="6" inputmode="decimal">
      <?php else: ?><span class="cv"><?= (int)($f['disc_bp'] ?? 0) > 0 ? e(rtrim(rtrim(number_format($f['disc_bp'] / 100, 2, ',', ''), '0'), ',')) . ' %' : '<span class="muted">—</span>' ?></span><?php endif; ?></div>
    <div class="cfld s3"><label class="cl" for="f-def_series">Serie por defecto</label>
      <?php $seriesTxt = ''; foreach ($series as $s) { if ((int)$s['id'] === (int)($f['def_series'] ?? 0)) { $seriesTxt = $s['letter'] . ' · ' . $s['descr']; } } ?>
      <?php if ($edit): ?><select id="f-def_series" name="def_series"><option value="0">— la de cada tipo —</option><?php foreach ($series as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)($f['def_series'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['letter']) ?> · <?= e($s['descr']) ?></option><?php endforeach; ?></select>
      <?php else: ?><span class="cv"><?= $or($seriesTxt) ?></span><?php endif; ?></div>
    <div class="cfld s12"><label class="cl" for="f-doc_notes">Notas para documentos <span class="muted">(se proponen en las observaciones de sus documentos nuevos)</span></label>
      <?php if ($edit): ?><textarea id="f-doc_notes" name="doc_notes" rows="2" maxlength="1000"><?= e($txt('doc_notes')) ?></textarea>
      <?php else: ?><span class="cv"><?= trim($txt('doc_notes')) !== '' ? nl2br(e($txt('doc_notes'))) : '<span class="muted">—</span>' ?></span><?php endif; ?></div>
    <?php if ($edit): ?>
    <div class="cfld s12"><span class="cl">Estado</span>
      <label class="check"><input type="checkbox" name="blocked" value="1"<?= (int)($f['blocked'] ?? 0) === 1 ? ' checked' : '' ?>> <span>Bloqueado (no se puede elegir en documentos nuevos)</span></label>
      <label class="check"><input type="checkbox" name="ended" value="1"<?= (int)($f['ended_day'] ?? 0) > 0 ? ' checked' : '' ?>> <span>De baja</span></label></div>
    <?php endif; ?>
  </div>
</section>
</form>
<?php if (!$edit && !$isNew): ?>
  <form method="post" action="<?= e($self_) ?>" id="cform-block"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $blocked ? 'unblock' : 'block' ?>"></form>
  <?php if ($nDocs === 0): ?><form method="post" action="<?= e($self_) ?>" id="cform-del"><?= csrf_field() ?><input type="hidden" name="action" value="delete"></form><?php endif; ?>
<?php endif; ?>

<?php if (!$isNew): ?>
<section class="cc-card" id="documentos"><h2>Documentos <small class="muted">(<?= e(number_format($nDocs, 0, ',', '.')) ?>)</small></h2>
  <nav class="cc-filter" aria-label="Filtrar por tipo">
    <?php foreach (['' => 'Todos', 'pedido' => 'Pedidos', 'albaran' => 'Albaranes', 'factura' => 'Facturas recibidas'] as $tk => $tl): ?>
      <a href="<?= e(url($self . ($tk !== '' ? '?tipo=' . $tk : '') . '#documentos')) ?>"<?= $docType === $tk ? ' aria-current="true"' : '' ?>><?= e($tl) ?></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$docs): ?><p class="empty" style="margin:6px 0 0">No hay documentos<?= $docType !== '' ? ' de este tipo' : '' ?>.</p><?php else: ?>
  <div class="tbl-wrap"><table class="tbl list cc-tbl">
    <thead><tr><th scope="col">Fecha</th><th scope="col">Tipo</th><th scope="col">Registro</th><th scope="col">Nº del proveedor</th><th scope="col" class="t-num">Total</th><th scope="col">Estado</th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr><td class="t-date"><?= e(erp_day((int)$d['day'])) ?></td><td><?= e($d['label']) ?></td><td><a href="<?= e(url($d['url'])) ?>"><?= e($d['code']) ?></a></td><td><?= e($d['sup_ref']) ?></td><td class="t-num"><?= e(erp_money($d['total'])) ?></td><td><?= e($d['status']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="cc-card" id="notas"><h2>Notas internas</h2>
  <p class="muted" style="margin:0 0 6px"><small>Solo las ven los usuarios con acceso a Compras.</small></p>
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
<p class="cc-foot muted"><small><?= (int)($c['updated_at'] ?? 0) > 0 ? 'Última modificación: ' . e(date('d/m/Y H:i', (int)$c['updated_at'])) . ($c['updated_by'] !== '' ? ' por ' . e($c['updated_by']) : '') : 'Sin modificaciones desde su alta.' ?>
  <?= (int)($c['created_day'] ?? 0) > 0 ? ' · Alta: ' . e(erp_day((int)$c['created_day'])) : '' ?></small></p>
<?php endif; ?>
