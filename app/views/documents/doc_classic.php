<?php
/**
 * Plantilla «clásica» de pedidos y albaranes impresos (motor de documentos). Mismo aspecto que la factura (invoice_classic.php)
 * y mismos ajustes de diseño ($d: color, letra, logotipo, márgenes, columnas). Variables: $doc, $lines, $vat, $k (tipo),
 * $d, $co (empresa), $payLabel, $isRetail.
 */
$annulled = $doc['status'] === 'anulado';
$draft = $doc['status'] === 'borrador'; // factura aún sin emitir: vista previa sin número
$test = docs_is_invoice((string)$doc['doc_type']) && (docs_is_test($doc) || ($draft && docp_bool('test_mode'))); // documento de prueba: sin validez fiscal
$fmtPct = fn($p) => rtrim(rtrim(number_format((float)$p, 2, ',', ''), '0'), ',') . ' %';
$cols = $d['cols'];
$sumRec = array_sum(array_column($vat, 'rec'));
$custAddr = array_filter([(string)$doc['cust_address'], trim($doc['cust_postcode'] . ' ' . $doc['cust_city']), (string)$doc['cust_province']], 'strlen');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?></title>
<style>
:root{--doc-color:<?= e($d['color']) ?>;--doc-font:<?= $d['font_css'] ?>;--doc-margin:<?= (int)$d['margin_mm'] ?>mm;--logo-h:<?= (int)$d['logo_h'] ?>px;--ink:#1c1b19;--muted:#6b665d;--line:#d9d6cf}
*{box-sizing:border-box}
body{margin:0;background:#d9d9d6;color:var(--ink);font:13px/1.45 var(--doc-font)}
.toolbar{position:sticky;top:0;z-index:5;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;padding:10px 16px;background:#fff;border-bottom:1px solid var(--line);font:14px/1.2 system-ui,sans-serif}
.toolbar a,.toolbar button{color:var(--ink);text-decoration:none;border:1px solid var(--line);background:#fff;border-radius:999px;padding:7px 14px;font:inherit;cursor:pointer}
.toolbar .grp{display:flex;gap:8px;flex-wrap:wrap}
.toolbar .js-only{background:var(--ink);color:#fff;border-color:var(--ink)}
.page{overflow-x:auto;padding:16px}
.sheet{position:relative;width:210mm;min-height:297mm;margin:0 auto;background:#fff;padding:var(--doc-margin);box-shadow:0 2px 14px rgba(0,0,0,.18)}
.head{display:grid;grid-template-columns:1fr 62mm;gap:8mm;align-items:start}
.co img{max-height:var(--logo-h);max-width:60mm;display:block;margin:0 0 6px}
.co h1{margin:0;font-size:16px;line-height:1.2}
.co p{margin:2px 0;color:var(--muted)}
.box{border:1.5px solid var(--doc-color);border-radius:4px}
.box h2{margin:0;padding:5px 10px;background:var(--doc-color);color:#fff;font-size:15px;letter-spacing:.12em;text-align:center}
.box dl{margin:0;padding:6px 10px;display:grid;grid-template-columns:auto 1fr;gap:2px 10px}
.box dt{color:var(--muted)}
.box dd{margin:0;text-align:right;font-weight:600}
.cust{margin:7mm 0 6mm;border:1px solid var(--line);border-radius:4px;padding:8px 12px}
.cust h3{margin:0 0 2px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.cust strong{font-size:14px}
.cust p{margin:1px 0}
.lines{width:100%;table-layout:fixed;border-collapse:collapse;font-size:12px}
.lines th,.lines td{overflow-wrap:anywhere;word-break:break-word}
.lines td.desc{white-space:pre-wrap}
.sheet{overflow-wrap:anywhere}
.lines th{background:#f3f2ee;border-top:1.5px solid var(--doc-color);border-bottom:1.5px solid var(--doc-color);padding:5px 6px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.lines td{padding:5px 6px;border-bottom:1px solid #ecebe6;vertical-align:top}
.lines th{padding:5px 4px}
.lines .num{text-align:right;white-space:nowrap}
.note-incl{margin:4px 0 0;color:var(--muted);font-size:11px}
.obs{margin-top:6mm;border:1px solid var(--line);border-radius:4px;padding:8px 12px;white-space:pre-line}
.obs h3{margin:0 0 2px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.foot{display:grid;grid-template-columns:1fr 78mm;gap:8mm;margin-top:7mm;break-inside:avoid}
.vat{width:100%;border-collapse:collapse;font-size:12px}
.vat th{text-align:right;font-size:11px;color:var(--muted);text-transform:uppercase;border-bottom:1px solid var(--line);padding:4px 6px}
.vat th:first-child,.vat td:first-child{text-align:left}
.vat td{padding:4px 6px;text-align:right;border-bottom:1px solid #ecebe6}
.total{border:1.5px solid var(--doc-color);border-radius:4px;padding:8px 12px}
.total .row{display:flex;justify-content:space-between;gap:10px;padding:1px 0}
.total .big{margin-top:6px;padding-top:6px;border-top:1.5px solid var(--doc-color);font-size:18px;font-weight:700}
.pay{margin-top:6px;color:var(--muted)}
.dues{margin-top:8px;border:1px solid var(--line);border-radius:4px;padding:5px 10px 6px;break-inside:avoid}
.dues h3{margin:0 0 3px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.dues table{width:100%;border-collapse:collapse;font-size:12px}
.dues td{padding:2px 0}
.dues td:last-child{text-align:right;font-weight:600;white-space:nowrap}
.sign{margin-top:12mm;display:grid;grid-template-columns:1fr 1fr;gap:10mm;color:var(--muted);font-size:11px}
.sign div{border-top:1px solid var(--line);padding-top:4px}
.testmark{margin:0 0 4mm;padding:5px 10px;border:2px solid #be1e1e;color:#be1e1e;text-align:center;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.stamp{position:absolute;top:42%;left:50%;transform:translate(-50%,-50%) rotate(-28deg);border:8px solid rgba(190,30,30,.28);color:rgba(190,30,30,.28);font-size:84px;font-weight:800;letter-spacing:.12em;padding:0 26px;pointer-events:none;white-space:nowrap}
@page{size:A4;margin:<?= (int)$d['margin_mm'] ?>mm}
@media print{
  body{background:#fff}
  .toolbar{display:none}
  .page{padding:0;overflow:visible}
  .sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none}
  .lines thead{display:table-header-group}
  .lines tr{break-inside:avoid}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
@media (max-width:640px){.head,.foot{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="toolbar">
  <div class="grp"><a href="<?= e(url($k['path'] . '/' . (int)$doc['id'])) ?>">← Formulario</a><a href="<?= e(url($k['path'])) ?>"><?= e($k['plural']) ?></a></div>
  <div class="grp"><button class="js-only" type="button" onclick="window.print()" hidden>Imprimir / Guardar PDF</button></div>
</div>
<script>document.querySelectorAll('.js-only').forEach(function (b) { b.hidden = false; });</script>
<div class="page"><article class="sheet">
  <?php if ($annulled || $draft || $test): ?><div class="stamp" aria-hidden="true"><?= $draft ? 'BORRADOR' : ($annulled ? 'ANULADO' : 'PRUEBA') ?></div><?php endif; ?>
  <?php if ($test): ?><p class="testmark"><?= e(docs_test_text()) ?></p><?php endif; ?>
  <header class="head">
    <div class="co">
      <?php if ($co['logo']): ?><img src="<?= e($co['logo']) ?>" alt="<?= e($co['legal'] !== '' ? $co['legal'] : site_name()) ?>"><?php endif; ?>
      <h1><?= e($co['legal'] !== '' ? $co['legal'] : site_name()) ?></h1>
      <?php if ($co['trade'] !== '' && $co['trade'] !== $co['legal']): ?><p><?= e($co['trade']) ?></p><?php endif; ?>
      <?php if ($co['tax_id'] !== ''): ?><p>CIF/NIF: <?= e($co['tax_id']) ?></p><?php endif; ?>
      <?php if ($co['address'] !== ''): ?><p><?= nl2br(e($co['address'])) ?></p><?php endif; ?>
      <?php if ($co['phone'] !== '' || $co['email'] !== ''): ?><p><?= e(trim($co['phone'] . ($co['phone'] !== '' && $co['email'] !== '' ? ' · ' : '') . $co['email'])) ?></p><?php endif; ?>
    </div>
    <div class="box">
      <h2><?= e($k['print']) ?></h2>
      <dl>
        <dt>Número</dt><dd><?= $draft ? (docs_is_invoice((string)$doc['doc_type']) ? '(al emitir)' : '(al confirmar)') : e($doc['doc_code']) ?></dd>
        <dt>Fecha</dt><dd><?= e(erp_day((int)$doc['doc_day'])) ?></dd>
        <?php if ($payLabel !== ''): ?><dt>Forma de pago</dt><dd><?= e($payLabel) ?></dd><?php endif; ?>
        <?php if ($doc['ref_text'] !== ''): ?><dt>Referencia</dt><dd><?= e($doc['ref_text']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </header>

  <section class="cust" aria-label="Cliente">
    <h3>Cliente<?= $doc['cust_code'] !== '' && !$isRetail ? ' · ' . e($doc['cust_code']) : '' ?></h3>
    <strong><?= e($doc['cust_name'] !== '' ? $doc['cust_name'] : ($isRetail ? 'Cliente de contado' : '')) ?></strong>
    <?php if ($doc['cust_nif'] !== ''): ?><p>NIF: <?= e($doc['cust_nif']) ?></p><?php endif; ?>
    <?php foreach ($custAddr as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
  </section>

  <table class="lines">
    <?= doc_colgroup($cols) ?>
    <thead><tr>
      <?php foreach ($cols as $c): ?><th<?= in_array($c, ['qty', 'price', 'disc', 'amount', 'iva'], true) ? ' class="num"' : '' ?>><?= e(DOC_COLUMNS[$c]) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): $isTxt = (int)($l['kind'] ?? 0) === 1; ?>
      <tr<?= $isTxt ? ' class="txt"' : '' ?>>
        <?php if ($isTxt): ?><td colspan="<?= count($cols) ?>" class="desc"><?= doc_line_html($l) ?></td><?php else: foreach ($cols as $c): ?>
          <?php if ($c === 'code'): ?><td><?= e($l['ref']) ?></td>
          <?php elseif ($c === 'desc'): ?><td class="desc"><?= doc_line_html($l) ?></td>
          <?php elseif ($c === 'size'): ?><td><?= e($l['size_txt']) ?></td>
          <?php elseif ($c === 'color'): ?><td><?= e($l['color_txt']) ?></td>
          <?php elseif ($c === 'qty'): ?><td class="num"><?= e(docs_qty_txt((int)$l['qty_u'])) ?></td>
          <?php elseif ($c === 'price'): ?><td class="num"><?= e(docs_price_txt((int)$l['price_u'])) ?></td>
          <?php elseif ($c === 'disc'): ?><td class="num"><?= (int)$l['disc_bp'] > 0 ? e($fmtPct($l['disc_bp'] / 100)) : '' ?></td>
          <?php elseif ($c === 'amount'): ?><td class="num"><?= e(docs_amount_txt((int)$l['amount_u'])) ?></td>
          <?php elseif ($c === 'iva'): ?><td class="num"><?= e($fmtPct($l['vat_bp'] / 100)) ?></td>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ((int)$doc['vat_incl'] === 1): ?><p class="note-incl">Importes de las líneas con IVA incluido.</p><?php endif; ?>

  <?php if (trim((string)$doc['notes']) !== ''): ?><section class="obs"><h3>Observaciones</h3><?= e((string)$doc['notes']) ?></section><?php endif; ?>

  <section class="foot">
    <div>
      <?php if ($vat): ?>
      <table class="vat">
        <thead><tr><th>IVA</th><th>Base</th><th>Cuota</th><?php if ($sumRec !== 0): ?><th>Recargo</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($vat as $r): ?>
          <tr><td><?= e($fmtPct($r['pct'])) ?></td><td><?= e(erp_money((int)$r['base'])) ?></td><td><?= e(erp_money((int)$r['cuota'])) ?></td><?php if ($sumRec !== 0): ?><td><?= e(erp_money((int)$r['rec'])) ?></td><?php endif; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
      <?php if ($payLabel !== ''): ?><p class="pay">Forma de pago: <?= e($payLabel) ?></p><?php endif; ?>
      <?php if (!empty($dues)): ?>
        <section class="dues" aria-label="Vencimientos"><h3>Vencimientos</h3><table>
          <?php foreach ($dues as $i => $du): ?><tr><td><?= count($dues) > 1 ? ($i + 1) . '. ' : '' ?><?= e(erp_day((int)$du['day'])) ?></td><td><?= e(erp_money((int)$du['cents'])) ?></td></tr><?php endforeach; ?>
        </table></section>
      <?php endif; ?>
    </div>
    <div class="total">
      <div class="row"><span>Base imponible</span><span><?= e(erp_money((int)$doc['base_cents'])) ?></span></div>
      <div class="row"><span>IVA</span><span><?= e(erp_money((int)$doc['vat_cents'])) ?></span></div>
      <?php if ((int)$doc['rec_cents'] !== 0): ?><div class="row"><span>Recargo de equivalencia</span><span><?= e(erp_money((int)$doc['rec_cents'])) ?></span></div><?php endif; ?>
      <div class="row big"><span>TOTAL</span><span><?= e(erp_money((int)$doc['total_cents'])) ?></span></div>
    </div>
  </section>

  <?php if ($doc['doc_type'] === 'albaran'): ?>
    <div class="sign"><div>Entregado por</div><div>Recibí conforme (firma, nombre y fecha)</div></div>
  <?php endif; ?>
</article></div>
</body>
</html>
