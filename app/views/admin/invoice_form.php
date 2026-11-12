<?php
/**
 * Formulario de factura (ERP): barra fija, etiquetas de estado, cabecera en recuadros, rejilla de líneas y pie con pestañas.
 * Variables: $inv, $lines, $cust, $isRetail, $vat, $lineRate, $serie, $payLabel, $map, $nb, $state, $sent, $self, $editable, $tab…
 */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$fmtPct = fn($p) => $p === null ? '—' : rtrim(rtrim(number_format((float)$p, 1, ',', ''), '0'), ',') . ' %';
$sizeTxt = fn(array $l) => $l['size_txt'] !== '' ? $l['size_txt'] : ((int)$l['size_no'] > 0 ? 'nº ' . $l['size_no'] : '');
$colorTxt = fn(array $l) => $l['color_txt'] !== '' ? $l['color_txt'] : ((int)$l['color_no'] > 0 ? 'nº ' . $l['color_no'] : '');
$annulled = (int)$inv['annulled'] === 1;
$paid = (int)$state['paid'] === 1;
$tags = erp_tags_list((string)$state['tags']);
$tabUrl = fn(string $t) => url($self . '?pestana=' . $t);
$sumRec = array_sum(array_column($vat, 'rec'));
$sumRet = array_sum(array_column($vat, 'ret'));
$custName = $cust ? ($cust['trade_name'] !== '' ? $cust['trade_name'] : $cust['name']) : '';
$custAddr = $cust ? array_filter([$cust['address'], trim($cust['postcode'] . ' ' . $cust['city']), $cust['province']], 'strlen') : [];
?>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>

<div class="if-bar" role="toolbar" aria-label="Acciones de la factura">
  <div class="if-grp">
    <?php if ($nb['prev'] !== null): ?><a class="btn small ghost" href="<?= e(url('/admin/ventas/' . erp_inv_seg((string)$nb['prev']))) ?>" rel="prev">‹ Anterior</a><?php else: ?><span class="btn small ghost dis">‹ Anterior</span><?php endif; ?>
    <?php if ($nb['next'] !== null): ?><a class="btn small ghost" href="<?= e(url('/admin/ventas/' . erp_inv_seg((string)$nb['next']))) ?>" rel="next">Siguiente ›</a><?php else: ?><span class="btn small ghost dis">Siguiente ›</span><?php endif; ?>
  </div>
  <div class="if-grp">
    <a class="btn small" href="<?= e(url($self . '/ver')) ?>" target="_blank" rel="noopener">Imprimir</a>
    <a class="btn small ghost" href="<?= e(url($self . '/modificar')) ?>">Modificar</a>
    <a class="btn small ghost" href="<?= e(url($self . '/enviar')) ?>">Enviar por email</a>
    <?php if (!empty($docx)): ?><a class="btn small ghost" href="<?= e(url('/admin/facturas/' . (int)$docx['id'])) ?>" title="Documento del CMS: rectificar, ver albaranes y registro">Documento / Rectificar</a><?php endif; ?>
    <?php if ($cust && $cust['cust_code'] !== ''): ?><a class="btn small ghost" href="<?= e(url('/admin/clientes/' . rawurlencode($cust['cust_code']))) ?>">Ver cliente</a><?php endif; ?>
    <a class="btn small ghost" href="<?= e(url('/admin/ventas')) ?>">Volver</a>
  </div>
</div>

<div class="if-title">
  <h1>Factura <?= e($inv['inv_number']) ?></h1>
  <p class="cf-tags">
    <span class="tag">Origen: <?= e(strtoupper((string)$inv['origin'])) ?></span>
    <?php if ($annulled): ?><span class="tag tag-off"><?= (string)$inv['origin'] === 'cms' ? 'Sustituida' : 'Anulada' ?></span><?php endif; ?>
    <?php if ((int)$inv['total_cents'] < 0): ?><span class="tag tag-off">Devolución</span><?php endif; ?>
    <?php if ((int)$sent['n'] > 0): ?><span class="tag tag-ok" title="<?= (int)$sent['n'] ?> envío(s)">Enviada <?= e(date('d/m/Y', (int)$sent['last'])) ?></span><?php endif; ?>
    <?php if ($paid): ?><span class="tag tag-ok">Cobrada <?= e(erp_day((int)$state['paid_day'])) ?></span><?php else: ?><span class="tag">Pendiente de cobro</span><?php endif; ?>
    <?php foreach ($tags as $t): ?><span class="tag tag-label"><?= e($t) ?></span><?php endforeach; ?>
  </p>
</div>

<div class="if-head">
  <section class="if-box" aria-label="Documento">
    <h2>Documento</h2>
    <dl class="if-kv">
      <div><dt>Número</dt><dd><?= e($inv['inv_number']) ?></dd></div>
      <div><dt>Serie</dt><dd><?= e($serie !== '' ? $serie : '—') ?></dd></div>
      <div><dt>Fecha</dt><dd><?= e(erp_day((int)$inv['inv_day'])) ?></dd></div>
      <div><dt>Forma de pago</dt><dd><?= e($payLabel !== '' ? $payLabel : '—') ?></dd></div>
      <?php if ($inv['rectifies'] !== ''): ?><div><dt>Rectifica a</dt><dd><?= e($inv['rectifies']) ?></dd></div><?php endif; ?>
      <?php if ($inv['seller'] !== ''): ?><div><dt>Vendedor</dt><dd><?= e($inv['seller']) ?></dd></div><?php endif; ?>
    </dl>
  </section>
  <section class="if-box" aria-label="Cliente">
    <h2>Cliente <?php if ($cust && !$isRetail && $cust['cust_code'] !== ''): ?><a class="if-link" href="<?= e(url('/admin/clientes/' . rawurlencode($cust['cust_code']))) ?>">Ver ficha →</a><?php endif; ?></h2>
    <?php if ($isRetail || !$cust): ?>
      <p class="if-retail">Cliente de contado</p>
    <?php else: ?>
      <dl class="if-kv">
        <div><dt>Código</dt><dd><?= e($cust['cust_code']) ?></dd></div>
        <div><dt>Nombre</dt><dd><?= e($custName !== '' ? $custName : '—') ?></dd></div>
        <div><dt>NIF</dt><dd><?= e($cust['nif'] !== '' ? $cust['nif'] : '—') ?></dd></div>
        <div class="wide"><dt>Dirección</dt><dd><?= $custAddr ? e(implode(', ', $custAddr)) : '—' ?></dd></div>
      </dl>
    <?php endif; ?>
  </section>
</div>

<div class="if-grid-wrap">
  <table class="if-grid">
    <thead><tr><th class="c">Nº</th><th>Código</th><th>Descripción</th><th>Talla</th><th>Color</th><th class="r">Uds.</th><th class="r">Precio</th><th class="r">Dto.</th><th class="r">Importe</th><th class="r">IVA</th></tr></thead>
    <tbody>
    <?php if (!$lines): ?><tr><td colspan="10" class="muted">Esta factura no tiene líneas.</td></tr><?php endif; ?>
    <?php foreach ($lines as $i => $l): $code = (string)$l['article_code']; $inf = $map[mb_strtolower($code)] ?? null; ?>
      <tr<?= (int)$l['qty_milli'] < 0 ? ' class="neg"' : '' ?>>
        <td class="c muted"><?= (int)($l['line_no'] > 0 ? $l['line_no'] : $i + 1) ?></td>
        <td><?php if ($inf): ?><a href="<?= e(url('/admin/articulo/' . (int)$inf['id'])) ?>"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?></td>
        <td><?= e($l['title']) ?></td>
        <td><?= e($sizeTxt($l)) ?></td>
        <td><?= e($colorTxt($l)) ?></td>
        <td class="r"><?= e(erp_qty((int)$l['qty_milli'])) ?></td>
        <td class="r"><?= e(erp_money((int)$l['price_cents'])) ?></td>
        <td class="r"><?= (int)$l['disc_bp'] > 0 ? e($fmtPct($l['disc_bp'] / 100)) : '' ?></td>
        <td class="r"><?= e(erp_money((int)$l['amount_cents'])) ?></td>
        <td class="r"><?= $lineRate !== null ? e($fmtPct($lineRate)) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ((int)$inv['iva_incl'] === 1): ?><p class="muted" style="margin:4px 0 0"><small>Importes de las líneas con IVA incluido.</small></p><?php endif; ?>

<div class="if-foot">
  <div class="if-tabs-wrap">
    <nav class="cf-tabs" aria-label="Pie de la factura">
      <?php foreach (ERP_FORM_TABS as $k => $label): ?><a href="<?= e($tabUrl($k)) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $k === 'notas' && $nNotes > 0 ? ' (' . (int)$nNotes . ')' : '' ?></a><?php endforeach; ?>
    </nav>

    <?php if ($tab === 'iva'): ?>
      <?php if (!$vat): ?><p class="empty">Sin desglose de IVA.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th scope="col">Tipo de IVA</th><th scope="col">Base</th><th scope="col">Cuota</th><?php if ($sumRec !== 0): ?><th scope="col">Recargo</th><?php endif; ?><?php if ($sumRet !== 0): ?><th scope="col">Retención</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($vat as $r): ?>
          <tr><td><?= e($fmtPct($r['pct'])) ?></td><td><?= e(erp_money((int)$r['base'])) ?></td><td><?= e(erp_money((int)$r['cuota'])) ?></td>
            <?php if ($sumRec !== 0): ?><td><?= e(erp_money((int)$r['rec'])) ?></td><?php endif; ?>
            <?php if ($sumRet !== 0): ?><td><?= e(erp_money(-(int)$r['ret'])) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>

    <?php elseif ($tab === 'notas'): ?>
      <p class="muted" style="margin-top:0">Solo las ven los usuarios con acceso a Clientes y Ventas. No se envían a NCS ni se recuperan sincronizando.</p>
      <form method="post" action="<?= e(url($self)) ?>" class="form cf-note-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="note_add">
        <label>Nueva nota <textarea name="note" rows="3" maxlength="<?= (int)ERP_INVOICE_NOTE_MAX ?>" required></textarea><small>Máximo <?= (int)ERP_INVOICE_NOTE_MAX ?> caracteres.</small></label>
        <div class="buttons"><button class="btn" type="submit">Guardar nota</button></div>
      </form>
      <?php if (!$notes): ?><p class="empty">Todavía no hay notas.</p><?php else: ?>
        <ul class="cf-notes">
          <?php foreach ($notes as $nt): ?>
            <li>
              <p class="cf-note-t"><?= nl2br(e($nt['note'])) ?></p>
              <div class="muted cf-note-m"><?= e(date('d/m/Y H:i', (int)$nt['created_at'])) ?> · <?= e($nt['username'] !== '' ? $nt['username'] : 'usuario') ?>
                <?php if (is_admin() || (int)$nt['user_id'] === (int)$_SESSION['uid']): ?>
                  <form method="post" action="<?= e(url($self)) ?>" class="inline" onsubmit="return confirm('¿Borrar esta nota?')"><?= csrf_field() ?><input type="hidden" name="action" value="note_del"><input type="hidden" name="id" value="<?= (int)$nt['id'] ?>"><button class="link danger" type="submit">Borrar</button></form>
                <?php endif; ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

    <?php else: ?>
      <?php if (!empty($links)): ?>
        <h3 style="margin:0 0 6px">Enlaces enviados</h3>
        <p class="muted" style="margin:0 0 8px"><small>Quien tenga un enlace vigente ve esta factura sin contraseña. Si se envió al correo equivocado, anúlalo. Algunos programas de correo abren los enlaces solos para comprobarlos, así que «primera apertura» puede ser anterior a que lo lea la persona.</small></p>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th scope="col">Enviado</th><th scope="col">A</th><th scope="col">Caduca</th><th scope="col">Estado</th><th scope="col">Visitas</th><th scope="col">Primera apertura</th><th scope="col">Última</th><th scope="col"></th></tr></thead>
          <tbody>
          <?php foreach ($links as $lk): $ls = erp_link_state($lk); ?>
            <tr>
              <td><?= e(date('d/m/Y H:i', (int)$lk['created_at'])) ?><br><small class="muted"><?= e($lk['created_by']) ?></small></td>
              <td><?= e($lk['email_to']) ?></td>
              <td><?= e(date('d/m/Y', (int)$lk['expires_at'])) ?></td>
              <td><?= $ls === 'vigente' ? '<span class="tag tag-ok">Vigente</span>' : ($ls === 'anulado' ? '<span class="tag tag-off">Anulado ' . e(date('d/m/Y', (int)$lk['revoked_at'])) . '</span>' : '<span class="tag">Caducado</span>') ?></td>
              <td><?= (int)$lk['views'] ?></td>
              <td><?= (int)$lk['first_view_at'] > 0 ? e(date('d/m/Y H:i', (int)$lk['first_view_at'])) : '—' ?></td>
              <td><?= (int)$lk['last_view_at'] > 0 ? e(date('d/m/Y H:i', (int)$lk['last_view_at'])) : '—' ?></td>
              <td><?php if ($ls === 'vigente'): ?>
                <form method="post" action="<?= e(url($self)) ?>" class="inline" onsubmit="return confirm('¿Anular este enlace? Quien lo tenga ya no podrá abrir la factura.')"><?= csrf_field() ?><input type="hidden" name="action" value="link_revoke"><input type="hidden" name="id" value="<?= (int)$lk['id'] ?>"><button class="link danger" type="submit">Anular enlace</button></form>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <h3 style="margin:14px 0 6px">Movimientos</h3>
      <?php endif; ?>
      <ul class="if-hist">
        <?php foreach ($events as $ev): ?>
          <li><span class="when"><?= e(date('d/m/Y H:i', (int)$ev['created_at'])) ?></span><strong><?= e(ERP_EVENT_LABELS[$ev['kind']] ?? $ev['kind']) ?></strong><?= $ev['detail'] !== '' ? ' — ' . e($ev['detail']) : '' ?><span class="muted"><?= $ev['username'] !== '' ? ' · ' . e($ev['username']) : '' ?></span></li>
        <?php endforeach; ?>
        <?php if ((int)$inv['synced_at'] > 0): ?><li><span class="when"><?= e(date('d/m/Y H:i', (int)$inv['synced_at'])) ?></span><strong>Sincronizada con NCS</strong> — última vez que se leyó de NCS<span class="muted"> · origen <?= e(strtoupper((string)$inv['origin'])) ?></span></li><?php endif; ?>
        <?php if (!$events): ?><li class="muted">Todavía no hay movimientos propios en esta factura.</li><?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>

  <aside class="if-totals" aria-label="Totales">
    <div class="ir"><span>Base imponible</span><span><?= e(erp_money(array_sum(array_column($vat, 'base')))) ?></span></div>
    <div class="ir"><span>IVA</span><span><?= e(erp_money(array_sum(array_column($vat, 'cuota')))) ?></span></div>
    <?php if ($sumRec !== 0): ?><div class="ir"><span>Recargo de equivalencia</span><span><?= e(erp_money($sumRec)) ?></span></div><?php endif; ?>
    <?php if ($sumRet !== 0): ?><div class="ir"><span>Retención</span><span><?= e(erp_money(-$sumRet)) ?></span></div><?php endif; ?>
    <div class="ir big"><span>TOTAL</span><span><?= e(erp_money((int)$inv['total_cents'])) ?></span></div>
    <?php if ($paid): ?><p class="muted" style="margin:8px 0 0">Cobrada el <?= e(erp_day((int)$state['paid_day'])) ?></p><?php endif; ?>
  </aside>
</div>
