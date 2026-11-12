<?php
/**
 * Inicio del panel = cuadro de mandos. Variables: $stats (catálogo), $pf, $lastImport, $alerts, $recent, $sales (¿se enseñan importes?), $salesNote,
 * $catalog (¿bloque de catálogo?), $dash (cifras), $lists, $period, $sys (estado del sistema, solo admin).
 */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$brand = preg_match('/^#[0-9a-fA-F]{6}$/', (string)setting('brand_color')) ? setting('brand_color') : '#3b6fb6';
/** Variación frente al año anterior: «▲ 12,3 %» (verde) o «▼ 4,0 %» (rojo). */
$delta = function (int $cur, int $prev) {
    $p = dash_delta($cur, $prev);
    if ($p === null) {
        return '<span class="db-sub">sin año anterior con qué comparar</span>';
    }
    return '<span class="db-sub"><span class="' . ($p >= 0 ? 'up' : 'down') . '">' . ($p >= 0 ? '▲' : '▼') . ' ' . e(number_format(abs($p), 1, ',', '.')) . ' %</span> frente al año anterior</span>';
};
$stateTxt = fn(string $s) => ['pendiente' => 'Pendiente', 'parcial' => 'Parcial', 'servido' => 'Servido', 'facturado' => 'Facturado', 'anulado' => 'Anulado', 'emitida' => 'Emitida'][$s] ?? $s;
$docList = function (array $rows, string $empty, bool $dueMode = false) use ($stateTxt): string {
    if (!$rows) {
        return '<p class="db-empty">' . e($empty) . '</p>';
    }
    $h = '<ul class="db-list">';
    foreach ($rows as $r) {
        $h .= '<li><a href="' . e(url($r['href'])) . '"><strong>' . e(($dueMode ? '' : $r['label'] . ' ') . $r['code']) . '</strong></a>'
            . '<span class="db-who">' . e($r['cust']) . '</span><span class="db-day">' . e(erp_day((int)$r['day'])) . '</span>'
            . '<span class="db-amt">' . e(erp_money((int)$r['total'])) . '</span>'
            . (!$dueMode ? '<span class="db-st' . ($r['status'] === 'Prueba' ? ' test' : '') . '">' . e($stateTxt((string)$r['status'])) . '</span>' : '') . '</li>';
    }
    return $h . '</ul>';
};
?>
<div class="dash" style="--db-accent:<?= e($brand) ?>">
<div class="head"><h1>Inicio</h1></div>

<?php if ($alerts): ?>
  <ul class="alerts">
    <?php foreach ($alerts as [$tone, $msg, $href, $cta]): ?>
      <li class="alert <?= e($tone) ?>"><?= admin_icon($tone === 'err' ? 'alert' : 'info') ?><span><?= e($msg) ?></span><?php if ($href): ?> <a class="btn small ghost" href="<?= e(url($href)) ?>"><?= e($cta) ?></a><?php endif; ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>

<?php if ($salesNote !== ''): ?><p class="alert warn keep"><?= admin_icon('info') ?><span><?= e($salesNote) ?></span></p><?php endif; ?>

<?php if ($purchNote !== ''): ?><p class="alert warn keep"><?= admin_icon('info') ?><span><?= e($purchNote) ?></span></p><?php endif; ?>

<?php if ($purch): ?>
<section class="db-kpis" aria-label="Compras">
  <div class="db-card"><span class="db-l">Compras del mes</span><strong class="db-n"><?= e(erp_money($pd['month'])) ?></strong><?= $delta($pd['month'], $pd['month_prev']) ?><span class="db-s">sin IVA</span></div>
  <div class="db-card"><span class="db-l">Compras del año</span><strong class="db-n"><?= e(erp_money($pd['year'])) ?></strong><?= $delta($pd['year'], $pd['year_prev']) ?><span class="db-s">sin IVA</span></div>
  <a class="db-card" href="<?= e(url('/admin/listados/pagos')) ?>"><span class="db-l">Pagos pendientes</span><strong class="db-n"><?= e(erp_money($pd['pending'])) ?></strong><span class="db-s"><?= e($n($pd['pending_n'])) ?> factura<?= $pd['pending_n'] === 1 ? '' : 's' ?> recibida<?= $pd['pending_n'] === 1 ? '' : 's' ?></span></a>
  <a class="db-card<?= $pd['overdue'] > 0 ? ' bad' : '' ?>" href="<?= e(url('/admin/listados/pagos?v=vencidos&salida=pantalla')) ?>"><span class="db-l">Pagos vencidos</span><strong class="db-n"><?= e(erp_money($pd['overdue'])) ?></strong><span class="db-s"><?= $pd['overdue_n'] > 0 ? e($n($pd['overdue_n'])) . ' factura' . ($pd['overdue_n'] === 1 ? '' : 's') . ' con cuotas vencidas' : 'nada vencido' ?></span></a>
</section>
<section class="db-grid" aria-label="Compras y ventas por mes">
  <article class="db-panel wide"><h2><?= $pd['with_sales'] ? 'Ventas y compras por mes' : 'Compras por mes' ?> <small>(12 meses, sin IVA)</small></h2>
    <?= dash_svg_sales_purch($pd['months'], (bool)$pd['with_sales'], $pd['with_sales'] ? 'Ventas y compras de los últimos 12 meses' : 'Compras de los últimos 12 meses') ?>
    <table class="db-data sr-only"><caption>Ventas y compras por mes</caption><tbody><?php foreach ($pd['months'] as $mo): ?><tr><th><?= e($mo['title']) ?></th><td><?= e(erp_money($mo['sales'])) ?></td><td><?= e(erp_money($mo['purch'])) ?></td></tr><?php endforeach; ?></tbody></table></article>
  <article class="db-panel<?= $pd['overdue'] > 0 ? ' bad' : '' ?>"><h2>Próximos pagos</h2><?= $docList($pd['next'], 'No hay pagos pendientes.', true) ?></article>
</section>
<?php endif; ?>

<?php if ($sales): ?>
<section class="db-kpis" aria-label="Indicadores">
  <div class="db-card"><span class="db-l">Ventas del mes</span><strong class="db-n"><?= e(erp_money($dash['month'])) ?></strong><?= $delta($dash['month'], $dash['month_prev']) ?><span class="db-s">sin IVA</span></div>
  <div class="db-card"><span class="db-l">Ventas del año</span><strong class="db-n"><?= e(erp_money($dash['year'])) ?></strong><?= $delta($dash['year'], $dash['year_prev']) ?><span class="db-s">sin IVA</span></div>
  <div class="db-card"><span class="db-l">Facturas del mes</span><strong class="db-n"><?= e($n($dash['invoices_month'])) ?></strong><span class="db-s">emitidas en el mes</span></div>
  <a class="db-card" href="<?= e(url('/admin/ventas')) ?>"><span class="db-l">Pendiente de cobro</span><strong class="db-n"><?= e(erp_money($dash['pending'])) ?></strong><span class="db-s"><?= e($n($dash['pending_n'])) ?> factura<?= $dash['pending_n'] === 1 ? '' : 's' ?> (sin el cliente de contado)</span></a>
  <div class="db-card<?= $dash['overdue'] > 0 ? ' bad' : '' ?>"><span class="db-l">Vencido</span><strong class="db-n"><?= e(erp_money($dash['overdue'])) ?></strong><span class="db-s"><?= $dash['overdue_n'] > 0 ? e($n($dash['overdue_n'])) . ' factura' . ($dash['overdue_n'] === 1 ? '' : 's') . ' con cuotas vencidas' : 'nada vencido' ?></span></div>
  <a class="db-card" href="<?= e(url('/admin/pedidos')) ?>"><span class="db-l">Pedidos por facturar</span><strong class="db-n"><?= e($n($dash['orders_n'])) ?></strong><span class="db-s"><?= e(erp_money($dash['orders'])) ?></span></a>
  <a class="db-card" href="<?= e(url('/admin/albaranes')) ?>"><span class="db-l">Albaranes por facturar</span><strong class="db-n"><?= e($n($dash['notes_n'])) ?></strong><span class="db-s"><?= e(erp_money($dash['notes'])) ?></span></a>
  <a class="db-card" href="<?= e(url('/admin/clientes')) ?>"><span class="db-l">Clientes activos</span><strong class="db-n"><?= e($n($dash['customers'])) ?></strong><span class="db-s"><?= e($n($dash['customers_new'])) ?> nuevo<?= $dash['customers_new'] === 1 ? '' : 's' ?> este mes</span></a>
  <?php if ($dash['test'] !== null): ?>
    <div class="db-card test"><span class="db-l">Facturas de prueba <span class="db-tag">Prueba</span></span><strong class="db-n"><?= e($n($dash['test']['n'])) ?></strong><span class="db-s"><?= e(erp_money($dash['test']['total'])) ?> · no cuentan en las cifras</span></div>
  <?php endif; ?>
</section>

<form method="get" action="<?= e(url('/admin')) ?>" class="db-period">
  <label>Periodo de las gráficas
    <select name="p"><?php foreach (DASH_PERIODS as $k => $l): ?><option value="<?= e($k) ?>"<?= $period['key'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label>Desde <input type="date" name="desde" value="<?= e(substr((string)$period['from'], 0, 4) . '-' . substr((string)$period['from'], 4, 2) . '-' . substr((string)$period['from'], 6, 2)) ?>"></label>
  <label>Hasta <input type="date" name="hasta" value="<?= e(substr((string)$period['to'], 0, 4) . '-' . substr((string)$period['to'], 4, 2) . '-' . substr((string)$period['to'], 6, 2)) ?>"></label>
  <button class="btn small" type="submit">Ver</button>
  <span class="db-sub">Mostrando <strong><?= e($period['label']) ?></strong> · <?= e(erp_money($dash['period_total'])) ?> sin IVA. Las fechas solo se usan con «Fechas a medida».</span>
</form>

<section class="db-grid" aria-label="Gráficas">
  <article class="db-panel wide"><h2>Ventas por mes <small>(12 meses, sin IVA; la línea es el año anterior)</small></h2>
    <?= dash_svg_months($dash['months'], 'Ventas de los últimos 12 meses, con el año anterior como línea') ?>
    <table class="db-data sr-only"><caption>Ventas por mes</caption><tbody><?php foreach ($dash['months'] as $mo): ?><tr><th><?= e($mo['title']) ?></th><td><?= e(erp_money($mo['cur'])) ?></td><td><?= e(erp_money($mo['prev'])) ?></td></tr><?php endforeach; ?></tbody></table></article>
  <article class="db-panel"><h2>Mejores clientes <small>(<?= e($period['label']) ?>)</small></h2>
    <?= $dash['top_clients'] ? dash_svg_hbars($dash['top_clients'], 'Los 10 mejores clientes del periodo') : '<p class="db-empty">Sin ventas en este periodo.</p>' ?></article>
  <article class="db-panel"><h2>Artículos más vendidos <small>(importe y unidades)</small></h2>
    <?= $dash['top_articles'] ? dash_svg_hbars($dash['top_articles'], 'Los 10 artículos más vendidos del periodo') : '<p class="db-empty">Sin ventas en este periodo.</p>' ?></article>
  <article class="db-panel wide"><h2>Ventas por <?= e(term('family', true)) ?> <small>(<?= e($period['label']) ?>)</small></h2>
    <?= $dash['families'] ? dash_svg_donut($dash['families'], 'Ventas por familia en el periodo') : '<p class="db-empty">Sin ventas en este periodo.</p>' ?></article>
</section>

<section class="db-grid" aria-label="Listas rápidas">
  <article class="db-panel"><h2>Últimos documentos</h2><?= $docList($lists['recent'], 'Todavía no hay documentos.') ?></article>
  <article class="db-panel"><h2>Borradores sin terminar</h2><?= $docList($lists['drafts'], 'No hay borradores.') ?></article>
  <article class="db-panel"><h2>Próximos vencimientos <small>(7 días)</small></h2><?= $docList($lists['soon'], 'Nada vence en los próximos 7 días.', true) ?></article>
  <article class="db-panel<?= $lists['overdue'] ? ' bad' : '' ?>"><h2>Vencidos</h2><?= $docList($lists['overdue'], 'No hay vencimientos vencidos.', true) ?></article>
</section>
<?php endif; ?>

<section class="db-grid" aria-label="Sistema y catálogo">
<?php if ($sys): ?>
  <article class="db-panel"><h2>Sistema</h2>
    <ul class="db-sys">
      <li class="<?= $sys['backup_days'] === null || $sys['backup_days'] > 7 ? 'warn' : 'ok' ?>"><span>Última copia de seguridad</span><strong><?= $sys['backup'] > 0 ? e(date('d/m/Y H:i', $sys['backup'])) : 'nunca' ?></strong><?php if ($sys['backup_days'] === null || $sys['backup_days'] > 7): ?> <a href="<?= e(url('/admin/configuracion/copia')) ?>"><?= $sys['backup_days'] === null ? 'Descargar' : 'Hace ' . (int)$sys['backup_days'] . ' días: descargar' ?></a><?php endif; ?></li>
      <li><span>Último traspaso o sincronización con NCS</span><strong><?= $sys['sync'] > 0 ? e(date('d/m/Y H:i', $sys['sync'])) : 'nunca' ?></strong></li>
      <li class="<?= $sys['mail'] && !$sys['mail_err'] ? 'ok' : 'warn' ?>"><span>Correo</span><strong><?= $sys['mail'] ? ($sys['mail_err'] ? 'con errores' : 'configurado') : 'sin configurar' ?></strong> <a href="<?= e(url('/admin/configuracion/correo')) ?>">Revisar</a></li>
      <li class="<?= $sys['2fa'] ? 'ok' : 'warn' ?>"><span>Doble verificación (Clientes y Ventas)</span><strong><?= $sys['2fa'] ? 'activa' : 'desactivada' ?></strong> <a href="<?= e(url('/admin/configuracion/usuarios#erp')) ?>">Cambiar</a></li>
      <li class="<?= $sys['test'] ? 'warn' : 'ok' ?>"><span>Modo de prueba de facturas</span><strong><?= $sys['test'] ? 'activo: las facturas que emitas son de prueba' : 'desactivado' ?></strong> <a href="<?= e(url('/admin/configuracion/parametros')) ?>">Parámetros</a></li>
    </ul></article>
<?php endif; ?>
<?php if ($catalog): ?>
  <article class="db-panel"><h2>Catálogo</h2>
    <ul class="db-sys">
      <li><span>Artículos visibles</span><strong><a href="<?= e(url('/admin/articulos?visible=1')) ?>"><?= e($n($stats['visible'])) ?></a></strong></li>
      <li><span>Ocultos</span><strong><a href="<?= e(url('/admin/articulos?visible=0')) ?>"><?= e($n($stats['hidden'])) ?></a></strong></li>
      <li class="<?= $stats['nophoto'] ? 'warn' : '' ?>"><span>Sin foto</span><strong><a href="<?= e(url('/admin/articulos?foto=0')) ?>"><?= e($n($stats['nophoto'])) ?></a></strong></li>
      <?php if ($stats['noprice'] !== null): ?><li class="<?= $stats['noprice'] ? 'warn' : '' ?>"><span>Sin precio</span><strong><a href="<?= e(url('/admin/articulos?precio=0')) ?>"><?= e($n($stats['noprice'])) ?></a></strong></li><?php endif; ?>
      <li><span><?= e(term('families')) ?></span><strong><a href="<?= e(url('/admin/categorias')) ?>"><?= e($n($stats['families'])) ?></a></strong></li>
      <li><span>Última importación</span><strong><?= $lastImport ? e(date('d/m/Y', (int)strtotime($lastImport['at']))) : 'ninguna' ?></strong></li>
    </ul>
    <p class="db-more"><a href="<?= e(url('/admin/articulos')) ?>">Ir al Catálogo →</a></p></article>
<?php endif; ?>
</section>

<?php if ($catalog): ?>
<nav class="quick" aria-label="Accesos rápidos">
  <a href="<?= e(url('/admin/articulo/nuevo')) ?>"><?= admin_icon('plus') ?> Nuevo artículo</a>
  <a href="<?= e(url('/admin/importar')) ?>"><?= admin_icon('import') ?> Importar</a>
  <a href="<?= e(url('/admin/categorias')) ?>"><?= admin_icon('folder') ?> <?= e(term('families')) ?></a>
  <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= admin_icon('external') ?> Ver web</a>
  <?php if (is_admin()): ?>
    <form method="post" action="<?= e(url('/admin/configuracion/copia')) ?>" class="inline"><?= csrf_field() ?><button type="submit"><?= admin_icon('download') ?> Descargar copia</button></form>
  <?php endif; ?>
</nav>

<h2>Últimos artículos modificados</h2>
<?php if (!$recent): ?>
  <p class="empty">Todavía no hay artículos. <a href="<?= e(url('/admin/articulo/nuevo')) ?>">Crea el primero</a> o <a href="<?= e(url('/admin/importar')) ?>">impórtalos</a>.</p>
<?php else: ?>
  <table class="tbl recent">
    <thead><tr><th scope="col"><span class="sr-only">Foto</span></th><th scope="col">Artículo</th><th scope="col"><?= e(term('family')) ?></th><th scope="col">Modificado</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $r): ?>
      <tr<?= $r['visible'] ? '' : ' class="off"' ?>>
        <td class="t-thumb"><?php if ($r['image']): ?><img src="<?= e(img_url($r['image'], true)) ?>" alt="" loading="lazy" width="40" height="40"><?php else: ?><span class="nophoto">sin foto</span><?php endif; ?></td>
        <td><a href="<?= e(url('/admin/articulo/' . $r['id'])) ?>"><strong><?= e($r['name']) ?></strong></a><?php if ($r['ref'] !== ''): ?><br><small class="muted">Ref. <?= e($r['ref']) ?></small><?php endif; ?><?= $r['visible'] ? '' : ' <small class="muted">· oculto</small>' ?></td>
        <td><?= e($r['cat_name']) ?></td>
        <td class="t-date"><?= e(date('d/m/Y H:i', (int)strtotime($r['updated_at']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p><a href="<?= e(url('/admin/articulos')) ?>">Ver todos los artículos →</a></p>
<?php endif; ?>
<?php endif; ?>
</div>
