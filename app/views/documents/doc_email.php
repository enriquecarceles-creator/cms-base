<?php
/**
 * Correo HTML de un pedido o albarán. ESTILOS EN LÍNEA y tablas (los programas de correo ignoran <style>).
 * Variables: $doc, $lines, $vat, $k, $d, $co, $payLabel, $maxLines, $message, $title.
 */
$ff = 'Arial,Helvetica,sans-serif';
$color = $d['color'];
$shown = array_slice($lines, 0, $maxLines);
$more = count($lines) - count($shown);
$custAddr = array_filter([(string)$doc['cust_address'], trim($doc['cust_postcode'] . ' ' . $doc['cust_city']), (string)$doc['cust_province']], 'strlen');
$sumRec = array_sum(array_column($vat, 'rec'));
$logo = $co['logo'] ? (preg_match('#^https?://#', (string)$co['logo']) ? $co['logo'] : site_origin() . $co['logo']) : '';
$td = 'padding:5px 6px;border-bottom:1px solid #e6e3dc;font-size:12px;font-family:' . $ff;
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($title) ?></title></head>
<body style="margin:0;padding:0;background:#f3f2ee;font-family:<?= $ff ?>;color:#1c1b19">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f2ee"><tr><td align="center" style="padding:18px 8px">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #d9d6cf;border-radius:6px">
<tr><td style="padding:22px 24px 14px;font-size:15px;line-height:1.5;font-family:<?= $ff ?>"><?= nl2br(e($message)) ?></td></tr>
<tr><td style="padding:0 24px"><div style="border-top:2px solid <?= e($color) ?>"></div></td></tr>
<tr><td style="padding:16px 24px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td valign="top" style="font-size:13px;line-height:1.45;font-family:<?= $ff ?>">
      <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="max-height:<?= (int)$d['logo_h'] ?>px;max-width:200px;display:block;margin:0 0 6px"><?php endif; ?>
      <strong style="font-size:15px"><?= e($co['legal'] !== '' ? $co['legal'] : site_name()) ?></strong><br>
      <?php if ($co['tax_id'] !== ''): ?>CIF/NIF: <?= e($co['tax_id']) ?><br><?php endif; ?>
      <?php if ($co['address'] !== ''): ?><?= nl2br(e($co['address'])) ?><br><?php endif; ?>
      <?= e(trim($co['phone'] . ($co['phone'] !== '' && $co['email'] !== '' ? ' · ' : '') . $co['email'])) ?>
    </td>
    <td valign="top" align="right" style="font-size:13px;line-height:1.5;font-family:<?= $ff ?>">
      <div style="font-size:18px;font-weight:bold;letter-spacing:2px;color:<?= e($color) ?>"><?= e($k['print']) ?></div>
      <strong><?= e($doc['doc_code']) ?></strong><br><?= e(erp_day((int)$doc['doc_day'])) ?><br>
      <?php if ($payLabel !== ''): ?><span style="color:#6b665d"><?= e($payLabel) ?></span><?php endif; ?>
    </td>
  </tr></table>
</td></tr>
<tr><td style="padding:12px 24px 0">
  <div style="border:1px solid #d9d6cf;border-radius:4px;padding:8px 12px;font-size:13px;line-height:1.45;font-family:<?= $ff ?>">
    <strong><?= e($doc['cust_name']) ?></strong><?= $doc['cust_nif'] !== '' ? ' · ' . e($doc['cust_nif']) : '' ?><br>
    <?= e(implode(' · ', $custAddr)) ?>
    <?php if ($doc['ref_text'] !== ''): ?><br><span style="color:#6b665d">Referencia: <?= e($doc['ref_text']) ?></span><?php endif; ?>
  </div>
</td></tr>
<tr><td style="padding:12px 24px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;table-layout:fixed;overflow-wrap:anywhere;word-break:break-word">
    <tr style="background:<?= e($color) ?>;color:#ffffff"><th align="left" style="<?= $td ?>;color:#fff">Artículo</th><th align="right" width="11%" style="<?= $td ?>;color:#fff">Uds.</th><th align="right" width="15%" style="<?= $td ?>;color:#fff">Precio</th><th align="right" width="9%" style="<?= $td ?>;color:#fff">Dto.</th><th align="right" width="17%" style="<?= $td ?>;color:#fff">Importe</th></tr>
    <?php foreach ($shown as $l): if ((int)($l['kind'] ?? 0) === 1): ?>
      <tr><td colspan="5" style="<?= $td ?>"><?= doc_line_html($l) ?></td></tr>
    <?php continue; endif; $det = trim($l['size_txt'] . ($l['size_txt'] !== '' && $l['color_txt'] !== '' ? ' · ' : '') . $l['color_txt']); ?>
      <tr>
        <td style="<?= $td ?>"><?= e($l['ref']) ?> <?= doc_line_html($l) ?><?= $det !== '' ? '<br><span style="color:#6b665d">' . e($det) . '</span>' : '' ?></td>
        <td align="right" style="<?= $td ?>"><?= e(docs_qty_txt((int)$l['qty_u'])) ?></td>
        <td align="right" style="<?= $td ?>"><?= e(docs_price_txt((int)$l['price_u'])) ?></td>
        <td align="right" style="<?= $td ?>"><?= (int)$l['disc_bp'] > 0 ? e(rtrim(rtrim(number_format($l['disc_bp'] / 100, 2, ',', ''), '0'), ',')) . ' %' : '' ?></td>
        <td align="right" style="<?= $td ?>"><?= e(docs_amount_txt((int)$l['amount_u'])) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($more > 0): ?><p style="font-size:12px;color:#6b665d;font-family:<?= $ff ?>">…y <?= (int)$more ?> línea<?= $more === 1 ? '' : 's' ?> más. Si necesitas el detalle completo, pídelo.</p><?php endif; ?>
</td></tr>
<tr><td style="padding:14px 24px 0">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="right">
  <table role="presentation" width="260" cellpadding="0" cellspacing="0" style="border:1.5px solid <?= e($color) ?>;border-radius:4px;font-size:13px;font-family:<?= $ff ?>">
    <tr><td style="padding:6px 12px 0">Base imponible</td><td align="right" style="padding:6px 12px 0"><?= e(erp_money(array_sum(array_column($vat, 'base')))) ?></td></tr>
    <tr><td style="padding:2px 12px">IVA</td><td align="right" style="padding:2px 12px"><?= e(erp_money(array_sum(array_column($vat, 'cuota')))) ?></td></tr>
    <?php if ($sumRec !== 0): ?><tr><td style="padding:2px 12px">Recargo de equivalencia</td><td align="right" style="padding:2px 12px"><?= e(erp_money($sumRec)) ?></td></tr><?php endif; ?>
    <tr><td style="padding:6px 12px 8px;border-top:1.5px solid <?= e($color) ?>;font-size:17px;font-weight:bold">TOTAL</td><td align="right" style="padding:6px 12px 8px;border-top:1.5px solid <?= e($color) ?>;font-size:17px;font-weight:bold"><?= e(erp_money((int)$doc['total_cents'])) ?></td></tr>
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
<?php if (trim((string)$doc['notes']) !== ''): ?><tr><td style="padding:12px 24px 0;font-size:12px;color:#444;font-family:<?= $ff ?>"><strong>Observaciones:</strong> <?= nl2br(e($doc['notes'])) ?></td></tr><?php endif; ?>
<tr><td style="padding:14px"></td></tr>
</table>
</td></tr></table>
</body></html>
