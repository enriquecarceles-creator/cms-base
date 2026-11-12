<?php
/**
 * Versión de impresión A4 de un informe (también para «Guardar como PDF» del navegador): cabecera con logotipo, filtros usados, fecha,
 * «Página x de y» (márgenes de página de CSS: Chrome y Edge) y totales. Variables: $def, $sel, $res, $co, $when, $selText.
 */
$cols = $res['cols'];
$land = count($cols) > 7; // muchas columnas: horizontal
$isNum = fn($c) => in_array($c['type'] ?? 'text', ['money', 'qty', 'int', 'pct'], true);
$label = preg_replace('/[^\p{L}\p{N} \/.\-~()]/u', '', (string)$def['title']) ?? '';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($def['title']) ?> · <?= e(site_name()) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#d9d9d6;color:#1c1b19;font:12px/1.4 system-ui,"Segoe UI",sans-serif}
.toolbar{position:sticky;top:0;z-index:5;display:flex;gap:8px;align-items:center;padding:10px 16px;background:#fff;border-bottom:1px solid #d9d6cf;font:14px/1.2 system-ui,sans-serif}
.toolbar a,.toolbar button{color:#1c1b19;text-decoration:none;border:1px solid #d9d6cf;background:#fff;border-radius:999px;padding:7px 14px;font:inherit;cursor:pointer}
.toolbar .js-only{background:#1c1b19;color:#fff;border-color:#1c1b19}
.page{padding:16px;overflow-x:auto}
.sheet{width:<?= $land ? '297mm' : '210mm' ?>;min-height:<?= $land ? '210mm' : '297mm' ?>;margin:0 auto;background:#fff;padding:12mm;box-shadow:0 2px 14px rgba(0,0,0,.18)}
.head{display:flex;justify-content:space-between;gap:10mm;align-items:flex-start;border-bottom:2px solid #1c1b19;padding-bottom:4mm;margin-bottom:4mm}
.head img{max-height:16mm;max-width:50mm;display:block;margin-bottom:2mm}
.head h1{margin:0;font-size:18px}
.head .co{font-size:11px;color:#555}
.head .co strong{color:#1c1b19;font-size:13px}
.sel{margin:0 0 3mm;color:#444}
.notice{margin:0 0 3mm;padding:2mm 3mm;border:1.5px solid #b45309;color:#92400e;font-weight:700}
table{width:100%;border-collapse:collapse;font-size:<?= count($cols) > 9 ? '9.5' : '11' ?>px}
thead{display:table-header-group}
th{background:#ecebe6;border-top:1.5px solid #1c1b19;border-bottom:1.5px solid #1c1b19;padding:4px 5px;text-align:left;text-transform:uppercase;font-size:.85em;letter-spacing:.03em}
td{padding:3px 5px;border-bottom:1px solid #e3e1db;vertical-align:top;overflow-wrap:anywhere}
tr{break-inside:avoid}
.r{text-align:right;white-space:nowrap}
.gh th{background:#f5f4f0;border-top:1px solid #999;text-transform:none;font-size:1em}
.sub td{font-weight:700;background:#faf9f6;border-top:1px solid #999}
.total td{font-weight:800;font-size:1.1em;border-top:2px solid #1c1b19;border-bottom:2px solid #1c1b19;background:#f0efea}
.foot{margin-top:4mm;color:#777;font-size:10px}
@page{size:A4 <?= $land ? 'landscape' : 'portrait' ?>;margin:12mm;@top-left{content:"<?= e($label) ?>";font:9px sans-serif;color:#6b665d}@bottom-right{content:"Página " counter(page) " de " counter(pages);font:9px sans-serif;color:#6b665d}@bottom-left{content:"<?= e($when) ?>";font:9px sans-serif;color:#6b665d}}
@media print{body{background:#fff}.toolbar{display:none}.page{padding:0;overflow:visible}.sheet{width:auto;min-height:0;margin:0;padding:0;box-shadow:none}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style>
</head>
<body>
<div class="toolbar">
  <a id="close" href="<?= e(url('/admin/listados/' . $def['id'])) ?>">Cerrar</a>
  <button class="js-only" type="button" onclick="window.print()" hidden>Imprimir / Guardar como PDF</button>
</div>
<script>document.querySelectorAll('.js-only').forEach(function (b) { b.hidden = false; });
(function(){var c=document.getElementById('close');if(c&&window.opener){c.addEventListener('click',function(e){e.preventDefault();window.close();});}})();</script>
<div class="page"><article class="sheet">
  <header class="head">
    <div class="co">
      <?php if (!empty($co['logo'])): ?><img src="<?= e($co['logo']) ?>" alt=""><?php endif; ?>
      <strong><?= e($co['legal'] !== '' ? $co['legal'] : site_name()) ?></strong>
      <?php if ($co['tax_id'] !== ''): ?><br>CIF/NIF: <?= e($co['tax_id']) ?><?php endif; ?>
    </div>
    <div style="text-align:right"><h1><?= e($def['title']) ?></h1><div class="co">Generado el <?= e($when) ?></div></div>
  </header>
  <p class="sel"><?= e($selText) ?></p>
  <?php if ($def['notice'] !== ''): ?><p class="notice"><?= e($def['notice']) ?></p><?php endif; ?>
  <?php if (!empty($res['capped'])): ?><p class="notice">Se muestran solo las primeras <?= (int)REP_MAX_ROWS ?> filas.</p><?php endif; ?>
  <table>
    <thead><tr><?php foreach ($cols as $c): ?><th<?= $isNum($c) ? ' class="r"' : '' ?>><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($res['lines'] as $ln): ?>
      <?php if ($ln['t'] === 'gh'): ?><tr class="gh"><th colspan="<?= count($cols) ?>"><?= e($ln['label']) ?></th></tr>
      <?php elseif ($ln['t'] === 'row'): ?><tr><?php foreach ($cols as $c): ?><td<?= $isNum($c) ? ' class="r"' : '' ?>><?= e(rep_cell($c, $ln['r'][$c['key']] ?? null)) ?></td><?php endforeach; ?></tr>
      <?php else: ?><tr class="<?= e($ln['t']) ?>"><?php foreach ($cols as $i => $c): ?><td<?= $isNum($c) ? ' class="r"' : '' ?>><?php if ($i === 0): ?><?= e($ln['label']) ?> (<?= (int)$ln['n'] ?>)<?php elseif (!empty($c['sum'])): ?><?= e(rep_cell($c, $ln['sum'][$c['key']] ?? 0)) ?><?php endif; ?></td><?php endforeach; ?></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="foot"><?= (int)$res['rows'] ?> fila<?= $res['rows'] === 1 ? '' : 's' ?>. <?= e(site_name()) ?>.</p>
</article></div>
</body>
</html>
