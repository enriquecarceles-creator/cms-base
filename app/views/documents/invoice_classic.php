<?php
/**
 * Plantilla «clásica» de la factura como documento. Variables: $inv, $lines, $cust, $isRetail, $vat, $lineRate, $serie,
 * $payLabel, $map, $nb, $d (diseño: color, font_css, logo_h, margin_mm, cols, title, stamp, footer), $co (empresa).
 * Para otro diseño, copiar este archivo como invoice_<nombre>.php y poner doc_template = <nombre>.
 */
$public = !empty($public); // enlace público enviado por correo: sin nada del panel
$annulled = (int)$inv['annulled'] === 1;
$docx = $docx ?? null; // factura emitida en el CMS (doc_headers): título según el tipo, menciones legales y sin el pie de «copia de NCS»
if ($docx) {
    $d['title'] = ['factura' => 'FACTURA', 'simplificada' => 'FACTURA SIMPLIFICADA', 'rectificativa' => 'FACTURA RECTIFICATIVA'][$docx['doc_type']] ?? $d['title'];
    $d['stamp'] = 'SUSTITUIDA'; // una factura del CMS solo deja de contar si se rectifica por sustitución
    if ($d['footer'] === DOC_DEFAULTS['footer']) {
        $d['footer'] = '';
    }
}
$credit = (int)$inv['total_cents'] < 0;
$fmtPct = fn($p) => $p === null ? '—' : rtrim(rtrim(number_format((float)$p, 1, ',', ''), '0'), ',') . ' %';
$sizeTxt = fn(array $l) => $l['size_txt'] !== '' ? $l['size_txt'] : ((int)$l['size_no'] > 0 ? 'nº ' . $l['size_no'] : '');
$colorTxt = fn(array $l) => $l['color_txt'] !== '' ? $l['color_txt'] : ((int)$l['color_no'] > 0 ? 'nº ' . $l['color_no'] : '');
$cols = $d['cols'];
$custName = $cust ? ($cust['trade_name'] !== '' ? $cust['trade_name'] : $cust['name']) : '';
$custAddr = $cust ? array_filter([$cust['address'], trim($cust['postcode'] . ' ' . $cust['city']), $cust['province']], 'strlen') : [];
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
.toolbar .dis{opacity:.4;pointer-events:none}
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
.cust{margin:7mm 0 6mm;display:grid;grid-template-columns:1fr auto;gap:10px;border:1px solid var(--line);border-radius:4px;padding:8px 12px}
.cust h3{margin:0 0 2px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.cust strong{font-size:14px}
.cust p{margin:1px 0}
.lines{width:100%;table-layout:fixed;border-collapse:collapse;font-size:12px}
.lines th,.lines td{overflow-wrap:anywhere;word-break:break-word}
.lines td.desc{white-space:pre-wrap}
.sheet{overflow-wrap:anywhere}
.lines th{background:#f3f2ee;border-top:1.5px solid var(--doc-color);border-bottom:1.5px solid var(--doc-color);padding:5px 6px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
.lines td{padding:5px 6px;border-bottom:1px solid #ecebe6;vertical-align:top}
.lines .num{text-align:right;white-space:nowrap}
.lines .off td{color:var(--muted)}
.lines small{color:var(--muted)}
.note-incl{margin:4px 0 0;color:var(--muted);font-size:11px}
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
.legal{margin-top:9mm;padding-top:4mm;border-top:1px solid var(--line);color:var(--muted);font-size:11px;text-align:center}
.stamp{position:absolute;top:42%;left:50%;transform:translate(-50%,-50%) rotate(-28deg);border:8px solid rgba(190,30,30,.28);color:rgba(190,30,30,.28);font-size:84px;font-weight:800;letter-spacing:.12em;padding:0 26px;pointer-events:none;white-space:nowrap}
@page{size:A4;margin:<?= (int)$d['margin_mm'] ?>mm}
@media print{
  body{background:#fff}
  .toolbar{display:none}
  .page{padding:0;overflow:visible}
  .sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none}
  .lines thead{display:table-header-group}
  .lines tr{break-inside:avoid}
  .toolbar-hide{display:none}
  *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
@media (max-width:640px){.head,.foot,.cust{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="toolbar">
  <?php if ($public): ?>
  <div class="grp"><strong><?= e($co['legal'] !== '' ? $co['legal'] : site_name()) ?></strong></div>
  <div class="grp"><button class="js-only" type="button" onclick="window.print()" hidden>Imprimir / Guardar PDF</button></div>
  <?php else: ?>
  <div class="grp">
    <a href="<?= e(url('/admin/ventas/' . erp_inv_seg((string)$inv['inv_number']))) ?>">← Formulario</a>
    <?php if ($nb['prev'] !== null): ?><a href="<?= e(url('/admin/ventas/' . erp_inv_seg((string)$nb['prev']) . '/ver')) ?>" rel="prev">‹ Anterior</a><?php else: ?><span class="dis"><a>‹ Anterior</a></span><?php endif; ?>
    <?php if ($nb['next'] !== null): ?><a href="<?= e(url('/admin/ventas/' . erp_inv_seg((string)$nb['next']) . '/ver')) ?>" rel="next">Siguiente ›</a><?php else: ?><span class="dis"><a>Siguiente ›</a></span><?php endif; ?>
  </div>
  <div class="grp">
    <?php if ($cust && $cust['cust_code'] !== ''): ?><a href="<?= e(url('/admin/clientes/' . rawurlencode($cust['cust_code']))) ?>">Ver cliente</a><?php endif; ?>
    <a href="<?= e(url('/admin/ventas')) ?>">Ventas</a>
    <button class="js-only" type="button" onclick="window.print()" hidden>Imprimir / Guardar PDF</button>
  </div>
  <?php endif; ?>
</div>
<script>document.querySelectorAll('.js-only').forEach(function (b) { b.hidden = false; });</script>
<div class="page"><article class="sheet">
  <?php if ($annulled): ?><div class="stamp" aria-hidden="true"><?= e($d['stamp']) ?></div><?php endif; ?>
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
      <h2><?= e($d['title']) ?></h2>
      <dl>
        <dt>Número</dt><dd><?= e($inv['inv_number']) ?></dd>
        <?php if ($serie !== ''): ?><dt>Serie</dt><dd><?= e($serie) ?></dd><?php endif; ?>
        <dt>Fecha</dt><dd><?= e(erp_day((int)$inv['inv_day'])) ?></dd>
        <?php if ($docx && (int)$docx['op_day'] > 0 && (int)$docx['op_day'] !== (int)$inv['inv_day']): ?><dt>Fecha de operación</dt><dd><?= e(erp_day((int)$docx['op_day'])) ?></dd><?php endif; ?>
        <?php if ($payLabel !== ''): ?><dt>Forma de pago</dt><dd><?= e($payLabel) ?></dd><?php endif; ?>
        <?php if ($inv['rectifies'] !== ''): ?><dt>Rectifica a</dt><dd><?= e($inv['rectifies']) ?></dd><?php endif; ?>
      </dl>
    </div>
  </header>

  <section class="cust" aria-label="Cliente">
    <div>
      <h3>Cliente</h3>
      <?php if ($isRetail || !$cust): ?>
        <strong>Cliente de contado</strong>
      <?php else: ?>
        <strong><?= e($custName !== '' ? $custName : 'Cliente ' . $cust['cust_code']) ?></strong>
        <?php if ($cust['trade_name'] !== '' && $cust['name'] !== '' && $cust['name'] !== $cust['trade_name']): ?><p><?= e($cust['name']) ?></p><?php endif; ?>
        <?php if ($cust['nif'] !== ''): ?><p>NIF: <?= e($cust['nif']) ?></p><?php endif; ?>
        <?php foreach ($custAddr as $line): ?><p><?= e($line) ?></p><?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php if ($cust && !$isRetail && !$public && $cust['cust_code'] !== ''): ?>
      <div style="text-align:right"><p>Código <strong><?= e($cust['cust_code']) ?></strong></p><p class="toolbar-hide"><a href="<?= e(url('/admin/clientes/' . rawurlencode($cust['cust_code']))) ?>">Ver ficha del cliente</a></p></div>
    <?php endif; ?>
  </section>

  <table class="lines">
    <?= doc_colgroup($cols) ?>
    <thead><tr>
      <?php foreach ($cols as $c): ?><th<?= in_array($c, ['qty', 'price', 'disc', 'amount', 'iva'], true) ? ' class="num"' : '' ?>><?= e(DOC_COLUMNS[$c]) ?></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php if (!$lines): ?><tr><td colspan="<?= count($cols) ?>" class="muted">Esta factura no tiene líneas.</td></tr><?php endif; ?>
    <?php foreach ($lines as $l): $code = (string)$l['article_code']; $inf = $map[mb_strtolower($code)] ?? null; $isTxt = (int)($l['kind'] ?? 0) === 1; ?>
      <tr<?= (int)$l['qty_milli'] < 0 ? ' class="off"' : '' ?>>
        <?php if ($isTxt): ?><td colspan="<?= count($cols) ?>" class="desc"><?= doc_line_html($l) ?></td><?php else: foreach ($cols as $c): ?>
          <?php if ($c === 'code'): ?><td><?php if ($inf && !$public): ?><a href="<?= e(url('/admin/articulo/' . (int)$inf['id'])) ?>" style="color:inherit"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?></td>
          <?php elseif ($c === 'desc'): ?><td class="desc"><?= doc_line_html($l) ?></td>
          <?php elseif ($c === 'size'): ?><td><?= e($sizeTxt($l)) ?></td>
          <?php elseif ($c === 'color'): ?><td><?= e($colorTxt($l)) ?></td>
          <?php elseif ($c === 'qty'): ?><td class="num"><?= e(erp_qty((int)$l['qty_milli'])) ?></td>
          <?php elseif ($c === 'price'): ?><td class="num"><?= e(erp_money((int)$l['price_cents'])) ?></td>
          <?php elseif ($c === 'disc'): ?><td class="num"><?= (int)$l['disc_bp'] > 0 ? e($fmtPct($l['disc_bp'] / 100)) : '' ?></td>
          <?php elseif ($c === 'amount'): ?><td class="num"><?= e(erp_money((int)$l['amount_cents'])) ?></td>
          <?php elseif ($c === 'iva'): ?><td class="num"><?= $lineRate !== null ? e($fmtPct($lineRate)) : '—' ?></td>
          <?php endif; ?>
        <?php endforeach; endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ((int)$inv['iva_incl'] === 1): ?><p class="note-incl">Importes de las líneas con IVA incluido.</p><?php endif; ?>
  <?php if ($docx): // menciones legales de las facturas emitidas en el CMS
      $origDoc = (int)$docx['rect_of'] > 0 ? (function (int $id) { $st = db()->prepare('SELECT doc_code, doc_day FROM doc_headers WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; })((int)$docx['rect_of']) : null;
      $mentions = [];
      if ($docx['doc_type'] === 'rectificativa') {
          $mentions[] = 'Factura rectificativa ' . ($docx['rect_mode'] === 'sustitucion' ? 'por sustitución' : 'por diferencias') . ' de la factura ' . ($origDoc ? $origDoc['doc_code'] . ' del ' . erp_day((int)$origDoc['doc_day']) : $inv['rectifies'])
              . '. Motivo: ' . ($docx['rect_text'] !== '' ? $docx['rect_text'] : $docx['rect_reason']) . '.';
      }
      if ((int)$docx['cash_crit'] === 1) {
          $mentions[] = 'Régimen especial del criterio de caja.';
      }
      if (trim((string)$docx['vat_note']) !== '') {
          $mentions[] = (string)$docx['vat_note'];
      }
      if ($mentions): ?>
    <div class="note-incl" style="margin-top:8px"><?php foreach ($mentions as $mt): ?><p style="margin:2px 0"><?= e($mt) ?></p><?php endforeach; ?></div>
  <?php endif; endif; ?>

  <section class="foot">
    <div>
      <?php if ($vat): ?>
      <table class="vat">
        <thead><tr><th>Tipo de IVA</th><th>Base</th><th>Cuota</th><?php if (array_sum(array_column($vat, 'rec')) !== 0): ?><th>Recargo</th><?php endif; ?><?php if (array_sum(array_column($vat, 'ret')) !== 0): ?><th>Retención</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($vat as $r): ?>
          <tr><td><?= e($fmtPct($r['pct'])) ?></td><td><?= e(erp_money((int)$r['base'])) ?></td><td><?= e(erp_money((int)$r['cuota'])) ?></td>
            <?php if (array_sum(array_column($vat, 'rec')) !== 0): ?><td><?= e(erp_money((int)$r['rec'])) ?></td><?php endif; ?>
            <?php if (array_sum(array_column($vat, 'ret')) !== 0): ?><td><?= e(erp_money(-(int)$r['ret'])) ?></td><?php endif; ?></tr>
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
      <div class="row"><span>Base imponible</span><span><?= e(erp_money(array_sum(array_column($vat, 'base')))) ?></span></div>
      <div class="row"><span>IVA</span><span><?= e(erp_money(array_sum(array_column($vat, 'cuota')))) ?></span></div>
      <?php if (array_sum(array_column($vat, 'rec')) !== 0): ?><div class="row"><span>Recargo de equivalencia</span><span><?= e(erp_money(array_sum(array_column($vat, 'rec')))) ?></span></div><?php endif; ?>
      <?php if (array_sum(array_column($vat, 'ret')) !== 0): ?><div class="row"><span>Retención</span><span><?= e(erp_money(-array_sum(array_column($vat, 'ret')))) ?></span></div><?php endif; ?>
      <div class="row big"><span>TOTAL</span><span><?= e(erp_money((int)$inv['total_cents'])) ?></span></div>
    </div>
  </section>

  <?php if ($d['footer'] !== ''): ?><p class="legal"><?= e($d['footer']) ?></p><?php endif; ?>
</article></div>
</body>
</html>
