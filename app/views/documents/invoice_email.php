<?php
/**
 * Correo HTML de la factura (B3b). ESTILOS EN LÍNEA y tablas: los programas de correo ignoran <style> y no admiten
 * posicionamiento moderno. Variables: las de erp_invoice_doc_vars() + $message (texto), $linkUrl, $expires, $maxLines.
 */
$annulled = (int)$inv['annulled'] === 1;
$docx = $docx ?? null; // factura emitida en el CMS (doc_headers): título según el tipo, menciones legales y sin el pie de «copia de NCS»
if ($docx) {
    $d['title'] = ['factura' => 'FACTURA', 'simplificada' => 'FACTURA SIMPLIFICADA', 'rectificativa' => 'FACTURA RECTIFICATIVA'][$docx['doc_type']] ?? $d['title'];
    $d['stamp'] = 'SUSTITUIDA'; // una factura del CMS solo deja de contar si se rectifica por sustitución
    if ($d['footer'] === DOC_DEFAULTS['footer']) {
        $d['footer'] = '';
    }
}
$fmtPct = fn($p) => $p === null ? '—' : rtrim(rtrim(number_format((float)$p, 1, ',', ''), '0'), ',') . ' %';
$sizeTxt = fn(array $l) => $l['size_txt'] !== '' ? $l['size_txt'] : ((int)$l['size_no'] > 0 ? 'nº ' . $l['size_no'] : '');
$colorTxt = fn(array $l) => $l['color_txt'] !== '' ? $l['color_txt'] : ((int)$l['color_no'] > 0 ? 'nº ' . $l['color_no'] : '');
$cols = $d['cols'];
$color = $d['color'];
$ff = 'Arial,Helvetica,sans-serif';
$custName = $cust ? ($cust['trade_name'] !== '' ? $cust['trade_name'] : $cust['name']) : '';
$custAddr = $cust ? array_filter([$cust['address'], trim($cust['postcode'] . ' ' . $cust['city']), $cust['province']], 'strlen') : [];
$shown = array_slice($lines, 0, $maxLines);
$more = count($lines) - count($shown);
$sumRec = array_sum(array_column($vat, 'rec'));
$sumRet = array_sum(array_column($vat, 'ret'));
$right = ['qty', 'price', 'disc', 'amount', 'iva'];
$logo = $co['logo'] ? (preg_match('#^https?://#', (string)$co['logo']) ? $co['logo'] : site_origin() . $co['logo']) : '';
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?></title></head>
<body style="margin:0;padding:0;background:#f3f2ee;font-family:<?= $ff ?>;color:#1c1b19">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f2ee"><tr><td align="center" style="padding:18px 8px">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #d9d6cf;border-radius:6px">
<tr><td style="padding:22px 24px 8px;font-size:15px;line-height:1.5;font-family:<?= $ff ?>">
  <?= nl2br(e($message)) ?>
</td></tr>
<tr><td align="center" style="padding:12px 24px 18px">
  <a href="<?= e($linkUrl) ?>" style="display:inline-block;background:<?= e($color) ?>;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;padding:12px 22px;border-radius:6px;font-family:<?= $ff ?>">Ver la factura en línea</a>
  <div style="margin-top:8px;font-size:12px;color:#6b665d;font-family:<?= $ff ?>">El enlace es personal y caduca el <?= e(date('d/m/Y', $expires)) ?>.</div>
</td></tr>
<?php if ($annulled): ?>
<tr><td style="padding:0 24px 12px"><div style="border:3px solid #be1e1e;color:#be1e1e;text-align:center;font-weight:bold;font-size:22px;letter-spacing:3px;padding:8px;border-radius:4px;font-family:<?= $ff ?>"><?= e($d['stamp']) ?></div></td></tr>
<?php endif; ?>
<tr><td style="padding:0 24px"><div style="border-top:2px solid <?= e($color) ?>"></div></td></tr>
<tr><td style="padding:16px 24px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td valign="top" style="font-size:13px;line-height:1.45;font-family:<?= $ff ?>">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="<?= e($co['legal'] !== '' ? $co['legal'] : site_name()) ?>" style="max-height:<?= (int)$d['logo_h'] ?>px;max-width:200px;display:block;margin:0 0 6px"><?php endif; ?>
      <strong style="font-size:15px"><?= e($co['legal'] !== '' ? $co['legal'] : site_name()) ?></strong><br>
      <?php if ($co['tax_id'] !== ''): ?>CIF/NIF: <?= e($co['tax_id']) ?><br><?php endif; ?>
      <?php if ($co['address'] !== ''): ?><?= nl2br(e($co['address'])) ?><br><?php endif; ?>
      <?php if ($co['phone'] !== '' || $co['email'] !== ''): ?><?= e(trim($co['phone'] . ($co['phone'] !== '' && $co['email'] !== '' ? ' · ' : '') . $co['email'])) ?><?php endif; ?>
    </td>
    <td valign="top" align="right" style="font-size:13px;line-height:1.5;font-family:<?= $ff ?>">
      <div style="font-size:18px;font-weight:bold;letter-spacing:2px;color:<?= e($color) ?>"><?= e($d['title']) ?></div>
      <strong><?= e($inv['inv_number']) ?></strong><br>
      <?= e(erp_day((int)$inv['inv_day'])) ?><br>
      <?php if ($payLabel !== ''): ?><span style="color:#6b665d"><?= e($payLabel) ?></span><?php endif; ?>
    </td>
  </tr></table>
</td></tr>
<tr><td style="padding:14px 24px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #d9d6cf;border-radius:4px"><tr><td style="padding:8px 12px;font-size:13px;line-height:1.45;font-family:<?= $ff ?>">
    <span style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#6b665d">Cliente</span><br>
    <?php if ($isRetail || !$cust): ?><strong>Cliente de contado</strong>
    <?php else: ?>
      <strong><?= e($custName !== '' ? $custName : 'Cliente ' . $cust['cust_code']) ?></strong><br>
      <?php if ($cust['nif'] !== ''): ?>NIF: <?= e($cust['nif']) ?><br><?php endif; ?>
      <?= e(implode(', ', $custAddr)) ?>
    <?php endif; ?>
  </td></tr></table>
</td></tr>
<tr><td style="padding:14px 24px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;table-layout:fixed;overflow-wrap:anywhere;word-break:break-word;font-size:12px;font-family:<?= $ff ?>">
    <tr>
      <?php foreach ($cols as $c): ?><th<?= isset(DOC_COL_WIDTHS[$c]) ? ' width="' . DOC_COL_WIDTHS[$c] . '%"' : '' ?> align="<?= in_array($c, $right, true) ? 'right' : 'left' ?>" style="background:#f3f2ee;border-top:2px solid <?= e($color) ?>;border-bottom:2px solid <?= e($color) ?>;padding:5px 6px;font-size:11px;text-transform:uppercase;white-space:nowrap"><?= e(DOC_COLUMNS[$c]) ?></th><?php endforeach; ?>
    </tr>
    <?php if (!$lines): ?><tr><td colspan="<?= count($cols) ?>" style="padding:8px 6px;color:#6b665d">Esta factura no tiene líneas.</td></tr><?php endif; ?>
    <?php foreach ($shown as $l): $isTxt = (int)($l['kind'] ?? 0) === 1; ?>
      <tr>
        <?php if ($isTxt): ?><td colspan="<?= count($cols) ?>" align="left" style="padding:5px 6px;border-bottom:1px solid #ecebe6;vertical-align:top"><?= doc_line_html($l) ?></td><?php else: foreach ($cols as $c): $al = in_array($c, $right, true) ? 'right' : 'left'; $st = 'padding:5px 6px;border-bottom:1px solid #ecebe6;vertical-align:top;' . ($al === 'right' ? 'white-space:nowrap;' : ''); ?>
          <?php if ($c === 'code'): ?><td align="left" style="<?= $st ?>"><?= e($l['article_code']) ?></td>
          <?php elseif ($c === 'desc'): ?><td align="left" style="<?= $st ?>"><?= doc_line_html($l) ?></td>
          <?php elseif ($c === 'size'): ?><td align="left" style="<?= $st ?>"><?= e($sizeTxt($l)) ?></td>
          <?php elseif ($c === 'color'): ?><td align="left" style="<?= $st ?>"><?= e($colorTxt($l)) ?></td>
          <?php elseif ($c === 'qty'): ?><td align="right" style="<?= $st ?>"><?= e(erp_qty((int)$l['qty_milli'])) ?></td>
          <?php elseif ($c === 'price'): ?><td align="right" style="<?= $st ?>"><?= e(erp_money((int)$l['price_cents'])) ?></td>
          <?php elseif ($c === 'disc'): ?><td align="right" style="<?= $st ?>"><?= (int)$l['disc_bp'] > 0 ? e($fmtPct($l['disc_bp'] / 100)) : '' ?></td>
          <?php elseif ($c === 'amount'): ?><td align="right" style="<?= $st ?>"><?= e(erp_money((int)$l['amount_cents'])) ?></td>
          <?php elseif ($c === 'iva'): ?><td align="right" style="<?= $st ?>"><?= $lineRate !== null ? e($fmtPct($lineRate)) : '—' ?></td>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($more > 0): ?><p style="margin:8px 0 0;font-size:12px;color:#6b665d;font-family:<?= $ff ?>">…y <?= (int)$more ?> línea<?= $more === 1 ? '' : 's' ?> más. Puedes verlas todas en <a href="<?= e($linkUrl) ?>" style="color:<?= e($color) ?>">el enlace</a>.</p><?php endif; ?>
  <?php if ((int)$inv['iva_incl'] === 1): ?><p style="margin:6px 0 0;font-size:11px;color:#6b665d;font-family:<?= $ff ?>">Importes de las líneas con IVA incluido.</p><?php endif; ?>
</td></tr>
<tr><td style="padding:14px 24px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="right">
  <table role="presentation" width="260" cellpadding="0" cellspacing="0" style="border:1.5px solid <?= e($color) ?>;border-radius:4px;font-size:13px;font-family:<?= $ff ?>">
    <tr><td style="padding:6px 12px 0">Base imponible</td><td align="right" style="padding:6px 12px 0"><?= e(erp_money(array_sum(array_column($vat, 'base')))) ?></td></tr>
    <tr><td style="padding:2px 12px">IVA</td><td align="right" style="padding:2px 12px"><?= e(erp_money(array_sum(array_column($vat, 'cuota')))) ?></td></tr>
    <?php if ($sumRec !== 0): ?><tr><td style="padding:2px 12px">Recargo de equivalencia</td><td align="right" style="padding:2px 12px"><?= e(erp_money($sumRec)) ?></td></tr><?php endif; ?>
    <?php if ($sumRet !== 0): ?><tr><td style="padding:2px 12px">Retención</td><td align="right" style="padding:2px 12px"><?= e(erp_money(-$sumRet)) ?></td></tr><?php endif; ?>
    <tr><td style="padding:6px 12px 8px;border-top:1.5px solid <?= e($color) ?>;font-size:17px;font-weight:bold">TOTAL</td><td align="right" style="padding:6px 12px 8px;border-top:1.5px solid <?= e($color) ?>;font-size:17px;font-weight:bold"><?= e(erp_money((int)$inv['total_cents'])) ?></td></tr>
  </table>
  </td></tr></table>
</td></tr>
<?php if (!empty($dues)): ?>
<tr><td style="padding:12px 24px 0">
  <table role="presentation" width="260" cellpadding="0" cellspacing="0" style="border:1px solid #d9d6cf;border-radius:4px;font-size:13px;font-family:<?= $ff ?>">
    <tr><td colspan="2" style="padding:6px 12px 2px;font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#6b665d">Vencimientos</td></tr>
    <?php foreach ($dues as $i => $du): ?><tr><td style="padding:2px 12px"><?= count($dues) > 1 ? ($i + 1) . '. ' : '' ?><?= e(erp_day((int)$du['day'])) ?></td><td align="right" style="padding:2px 12px;font-weight:bold"><?= e(erp_money((int)$du['cents'])) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="2" style="padding:0 0 4px"></td></tr>
  </table>
</td></tr>
<?php endif; ?>
<?php if ($d['footer'] !== ''): ?><tr><td style="padding:22px 24px 18px"><div style="border-top:1px solid #d9d6cf;padding-top:8px;font-size:11px;color:#6b665d;text-align:center;font-family:<?= $ff ?>"><?= e($d['footer']) ?></div></td></tr>
<?php else: ?><tr><td style="padding:12px"></td></tr><?php endif; ?>
</table>
</td></tr></table>
</body></html>
