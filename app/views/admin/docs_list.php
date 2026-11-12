<?php
/**
 * Ventas → Pedidos / Albaranes / Facturas del CMS: lista con filtros.
 * Variables: $kind, $k, $rows, $f, $sum, $page, $pages, $years, $retail, $statuses, $invoices, $register (solo facturas).
 */
$pageUrl = fn(int $p) => docs_list_url($kind, $f, $p > 1 ? ['pag' => (string)$p] : []);
$purchase = !empty($purchase);
$party = $purchase ? 'Proveedor' : 'Cliente';
$canBill = docs_base($kind) === 'albaran' && (bool)array_filter($rows, fn($r) => $r['status'] === 'pendiente' && (int)$r['billed_by'] === 0);
$code = fn(array $r) => $r['status'] === 'borrador' ? 'Borrador' : $r['doc_code'];
$filtered = $f['q'] !== '' || $f['estado'] !== '' || $f['anio'] !== '' || ($f['tipo'] ?? '') !== '' || $f['cli'] !== '' || $f['desde'] !== '' || $f['hasta'] !== '';
?>
<?php if ($purchase ? !purch_2fa_required() : !erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para <?= $purchase ? 'Compras' : 'Clientes y Ventas' ?> está desactivada</span></p><?php endif; ?>
<div class="head">
  <h1><?= $invoices && !$purchase ? 'Facturas' : e($k['plural']) ?></h1>
  <span class="if-grp">
    <?php if ($purchase): ?>
      <a class="btn" href="<?= e(url($k['path'] . '/nuevo')) ?>">+ <?= e($k['new']) ?></a>
    <?php elseif ($invoices): ?>
      <a class="btn" href="<?= e(url('/admin/facturas/nuevo?tipo=factura')) ?>">+ Nueva factura</a>
      <a class="btn ghost" href="<?= e(url('/admin/facturas/nuevo?tipo=simplificada')) ?>">+ Nuevo tique</a>
    <?php else: ?>
      <a class="btn" href="<?= e(url($k['path'] . '/nuevo')) ?>">+ <?= e($k['new']) ?></a>
    <?php endif; ?>
  </span>
</div>
<?php if ($purchase && $invoices): ?>
  <p class="hint">Las facturas recibidas se crean como <strong>borrador</strong> (se pueden cambiar y borrar) y al pulsar <strong>Cerrar</strong> toman su <strong>número de registro</strong> interno (correlativo por año). Cerrada, ya no se modifica: se corrige con una rectificativa recibida. Para registrar albaranes de compra, márcalos en <a href="<?= e(url('/admin/compras/albaranes?estado=pendiente')) ?>">Albaranes de compra</a>.</p>
<?php elseif ($purchase && $kind === 'albaran_c'): ?>
  <p class="hint">Los albaranes de compra se crean desde un pedido (botón «Convertir en albarán») o directamente aquí. Las compras <strong>no mueven el stock</strong>. Para pasarlos a factura recibida, marca los de un mismo proveedor y pulsa «Pasar a factura recibida».</p>
<?php elseif ($invoices): ?>
  <nav class="cf-tabs" aria-label="Facturas">
    <a href="<?= e(url('/admin/ventas')) ?>">Todas las facturas (NCS y CMS)</a>
    <a href="<?= e(url('/admin/facturas')) ?>" aria-current="page">Borradores y facturas del CMS</a>
  </nav>
  <p class="hint">Las facturas se crean como <strong>borrador</strong> (se pueden cambiar y borrar) y al pulsar <strong>Emitir</strong> toman el número de su serie: desde ese momento no se modifican ni se borran, se corrigen con una rectificativa. Para facturar albaranes, márcalos en <a href="<?= e(url('/admin/albaranes?estado=pendiente')) ?>">Albaranes</a>.
    Las emitidas aparecen también en «Todas las facturas», Informes y la ficha del cliente.</p>
  <?php if ($testCount > 0): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span>Hay <strong><?= (int)$testCount ?></strong> factura<?= $testCount === 1 ? '' : 's' ?> de prueba (PRUEBA-…). No cuentan en Ventas, el registro ni el SII. <?php if (is_admin()): ?><a href="<?= e(url('/admin/facturas/pruebas')) ?>">Borrarlas…</a><?php endif; ?></span></p><?php endif; ?>
  <?php if ($register): ?>
    <?php if ($register['problems']): ?>
      <div class="alert warn keep df-warn"><?= admin_icon('alert') ?><div><strong>El registro de facturas no cuadra:</strong><ul><?php foreach ($register['problems'] as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>Alguien ha cambiado facturas emitidas fuera del panel (o se ha restaurado una copia incompleta). Avisa a tu asesoría.</div></div>
    <?php elseif ($register['n'] > 0): ?>
      <p class="muted"><small>Registro de facturas emitidas: <?= (int)$register['n'] ?> comprobada<?= $register['n'] === 1 ? '' : 's' ?>, la cadena de huellas cuadra.</small></p>
    <?php endif; ?>
  <?php endif; ?>
<?php elseif ($kind === 'albaran'): ?>
  <p class="hint">Los albaranes se crean desde un pedido (botón «Convertir en albarán») o directamente aquí. Para facturarlos, marca los de un mismo cliente y pulsa «Facturar los marcados».</p>
<?php endif; ?>

<form class="filters lf" method="get" action="<?= e(url($k['path'])) ?>">
  <label class="lf-q"><span class="sr-only">Buscar</span><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= $purchase ? 'Registro, nº del proveedor, proveedor o referencia' : 'Número, cliente o referencia' ?>"></label>
  <?php if ($invoices): ?>
    <label><span class="sr-only">Tipo</span><select name="tipo"><option value="">Todos los tipos</option>
      <?php foreach ($purchase ? ['factura_c', 'rectificativa_c'] : DOC_INVOICE_TYPES as $t): ?><option value="<?= e($t) ?>"<?= ($f['tipo'] ?? '') === $t ? ' selected' : '' ?>><?= e(docs_kind($t)['plural']) ?></option><?php endforeach; ?></select></label>
  <?php endif; ?>
  <label><span class="sr-only">Estado</span><select name="estado"><option value="">Todos los estados</option>
    <?php foreach ($statuses as $s => $label): ?><option value="<?= e($s) ?>"<?= $f['estado'] === $s ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label><span class="sr-only">Año</span><select name="anio"><option value="">Todos los años</option>
    <?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= $f['anio'] === (string)$y ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select></label>
  <label><span class="sr-only"><?= $party ?></span><input type="search" name="cli" value="<?= e($f['cli']) ?>" placeholder="<?= $party ?> (código o nombre)" maxlength="60"></label>
  <label>Desde <input type="date" name="desde" value="<?= e($f['desde']) ?>"></label>
  <label>Hasta <input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></label>
  <button class="btn small" type="submit">Buscar</button>
  <?php if ($filtered): ?><a class="lf-clear" href="<?= e(url($k['path'])) ?>">Quitar filtros</a><?php endif; ?>
</form>

<?php if (!$rows): ?>
  <p class="empty"><?= $filtered ? 'No hay nada con ese filtro.' : ($invoices ? ($purchase ? 'Todavía no hay facturas recibidas.' : 'Todavía no hay facturas del CMS.') : 'Todavía no hay ' . e(mb_strtolower($k['plural'])) . '.') ?></p>
<?php else: ?>
<p class="muted"><?= (int)$sum['n'] ?> documento<?= (int)$sum['n'] === 1 ? '' : 's' ?> · total <?= e(erp_money((int)$sum['tot'])) ?> <small>(sin anulados ni borradores)</small></p>
<?php if ($canBill): ?><form method="post" action="<?= e(url($k['path'])) ?>" id="f-billmany"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e(http_build_query(array_filter($f))) ?>"><?php endif; ?>
<div class="tbl-wrap"><table class="tbl list">
  <thead><tr><?php if ($canBill): ?><th scope="col"><span class="sr-only">Marcar</span></th><?php endif; ?><th scope="col"><?= $purchase && $invoices ? 'Registro' : 'Número' ?></th><?php if ($invoices): ?><th scope="col">Tipo</th><?php endif; ?><th scope="col">Fecha</th><?php if ($purchase && $invoices): ?><th scope="col">Nº del proveedor</th><?php endif; ?><th scope="col"><?= $party ?></th><th scope="col">Referencia</th><th scope="col">Estado</th><th scope="col">Total</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $off = $r['status'] === 'anulado'; $path = (DOCS_KINDS[$r['doc_type']] ?? DOCS_KINDS_PURCHASE[$r['doc_type']] ?? $k)['path']; ?>
    <tr<?= $off ? ' class="off"' : '' ?>>
      <?php if ($canBill): ?><td><?php if ($r['status'] === 'pendiente' && (int)$r['billed_by'] === 0): ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Marcar <?= e($r['doc_code']) ?>"><?php endif; ?></td><?php endif; ?>
      <td><a href="<?= e(url($path . '/' . (int)$r['id'])) ?>"><?= e($code($r)) ?></a><?= (int)($r['is_test'] ?? 0) === 1 ? ' <span class="tag tag-off">Prueba</span>' : '' ?><?= docs_base($kind) === 'albaran' && (int)$r['billed_by'] > 0 && $r['status'] === 'pendiente' ? ' <small class="muted">en borrador de factura</small>' : '' ?></td>
      <?php if ($invoices): ?><td><?= e((DOCS_KINDS[$r['doc_type']] ?? DOCS_KINDS_PURCHASE[$r['doc_type']] ?? ['label' => $r['doc_type']])['label']) ?></td><?php endif; ?>
      <td class="t-date"><?= e(erp_day((int)$r['doc_day'])) ?></td>
      <?php if ($purchase && $invoices): ?><td><?= e($r['sup_ref']) ?></td><?php endif; ?>
      <td><?= $retail !== '' && $r['cust_code'] === $retail ? '<span class="muted">Cliente de contado</span>' : e($r['cust_name'] !== '' ? $r['cust_name'] : ($r['cust_code'] !== '' ? $r['cust_code'] : '—')) ?><?= $r['cust_code'] === '' && $r['cust_name'] !== '' ? ' <small class="muted">(sin ficha)</small>' : '' ?></td>
      <td><?= e($r['ref_text']) ?></td>
      <td><span class="tag<?= $off ? ' tag-off' : (in_array($r['status'], ['servido', 'facturado'], true) ? ' tag-ok' : ($r['status'] === 'borrador' ? ' tag-draft' : '')) ?>"><?= e(docs_status_label($r)) ?></span><?php $ps = ($payState ?? [])[(int)$r['id']] ?? ''; if ($ps !== ''): ?> <span class="tag <?= $ps === 'pagada' ? 'tag-ok' : ($ps === 'vencida' ? 'tag-off' : 'tag-draft') ?>"><?= $ps === 'pagada' ? 'Pagada' : ($ps === 'vencida' ? 'Vencida' : 'Pendiente de pago') ?></span><?php endif; ?></td>
      <td><strong><?= e(erp_money((int)$r['total_cents'])) ?></strong></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if ($canBill): ?><div class="buttons"><button class="btn" type="submit"><?= $purchase ? 'Pasar a factura recibida' : 'Facturar los marcados' ?></button> <small class="muted">Del mismo <?= $purchase ? 'proveedor' : 'cliente' ?>. Se crea un borrador de factura<?= $purchase ? ' recibida' : '' ?>.</small></div></form><?php endif; ?>
<nav class="pager" aria-label="Páginas"><span class="muted">Página <?= $page ?> de <?= $pages ?></span>
  <?php if ($pages > 1): ?><span class="pager-l">
    <?php if ($page > 1): ?><a href="<?= e(url($pageUrl($page - 1))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
    <?php if ($page < $pages): ?><a href="<?= e(url($pageUrl($page + 1))) ?>" rel="next">Siguiente ›</a><?php endif; ?>
  </span><?php endif; ?></nav>
<?php endif; ?>
