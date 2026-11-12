<?php
/**
 * Ficha de cliente tipo ERP: cabecera (nombre, etiquetas, acciones), tarjetas, lateral de contacto y cuatro pestañas
 * por enlaces. Variables: $c, $self, $tab, $stats, $nNotes, $isRetail, $nb, y las de cada pestaña.
 */
$n = fn(int $v) => number_format($v, 0, ',', '.');
$title1 = $c['trade_name'] !== '' ? $c['trade_name'] : ($c['name'] !== '' ? $c['name'] : 'Cliente ' . $c['cust_code']);
$legal = $c['trade_name'] !== '' && $c['name'] !== '' && $c['name'] !== $c['trade_name'] ? $c['name'] : '';
$tabUrl = fn(string $t, array $extra = []) => url($self . '?' . http_build_query(array_merge(['pestana' => $t], $extra)));
$years = $stats['years'];
$pct = $stats['diff'];
$addr = trim(implode(', ', array_filter([$c['address'], trim($c['postcode'] . ' ' . $c['city']), $c['province'], $c['country']], 'strlen')));
$ended = (int)$c['ended_day'] > 0;
?>
<?php if (!erp_2fa_required()): ?><p class="alert warn"><?= admin_icon('alert') ?><span>La doble verificación para Clientes y Ventas está desactivada</span></p><?php endif; ?>

<header class="cf-head">
  <div class="cf-title">
    <p class="cf-back"><a href="<?= e(url('/admin/clientes')) ?>">← Clientes</a></p>
    <h1><?= e($title1) ?></h1>
    <p class="muted cf-sub"><?= $legal !== '' ? e($legal) . ' · ' : '' ?>Código <strong><?= e($c['cust_code']) ?></strong><?= $c['nif'] !== '' ? ' · NIF <strong>' . e($c['nif']) . '</strong>' : '' ?></p>
    <p class="cf-tags">
      <?php if ($ended): ?><span class="tag tag-off">De baja</span><?php elseif ((int)$c['blocked'] === 1): ?><span class="tag tag-off">Bloqueado</span><?php else: ?><span class="tag tag-ok">Activo</span><?php endif; ?>
      <?php if (erp_customer_is_cms($c)): ?><span class="tag">Origen: CMS</span><?php endif; ?>
      <?php if ($isRetail): ?><span class="tag">Contado</span><?php endif; ?>
      <?php if ((int)$c['tariff'] > 0): ?><span class="tag">Tarifa <?= (int)$c['tariff'] ?></span><?php endif; ?>
      <?php if ($c['pay_code'] !== ''): ?><span class="tag"><?= e(erp_pay_label($c["pay_code"])) ?></span><?php endif; ?>
    </p>
  </div>
  <div class="cf-actions">
    <?php if (erp_customer_is_cms($c)): ?><a class="btn small" href="<?= e(url('/admin/clientes/' . rawurlencode((string)$c['cust_code']) . '/modificar')) ?>">Modificar</a><?php endif; ?>
    <?php if ($c['phone'] !== ''): ?><a class="btn small ghost" href="tel:<?= e(preg_replace('/[^\d+]/', '', $c['phone'])) ?>">Llamar</a><?php endif; ?>
    <?php if ($c['email'] !== ''): ?><a class="btn small ghost" href="mailto:<?= e($c['email']) ?>">Correo</a><?php endif; ?>
    <?php if ($c['nif'] !== ''): ?>
      <span class="cf-copy"><input id="nif" class="cf-nif" readonly value="<?= e($c['nif']) ?>" aria-label="NIF" onclick="this.select()"><button class="btn small ghost js-only" type="button" data-copy="nif" hidden>Copiar NIF</button></span>
    <?php endif; ?>
    <button class="btn small ghost js-only" type="button" onclick="window.print()" hidden>Imprimir ficha</button>
    <form method="post" action="<?= e(url($self)) ?>" class="cf-export"><?= csrf_field() ?><input type="hidden" name="action" value="export">
      <label class="check"><input type="checkbox" name="lineas" value="1"> <span>con líneas</span></label>
      <button class="btn small ghost" type="submit">Exportar facturas (CSV)</button></form>
  </div>
</header>

<nav class="cf-pn" aria-label="Cliente anterior y siguiente">
  <?php if ($nb['prev']): ?><a href="<?= e(url('/admin/clientes/' . rawurlencode($nb['prev']['cust_code']))) ?>" rel="prev">‹ <?= e($nb['prev']['name'] !== '' ? $nb['prev']['name'] : $nb['prev']['cust_code']) ?></a><?php else: ?><span></span><?php endif; ?>
  <?php if ($nb['next']): ?><a href="<?= e(url('/admin/clientes/' . rawurlencode($nb['next']['cust_code']))) ?>" rel="next"><?= e($nb['next']['name'] !== '' ? $nb['next']['name'] : $nb['next']['cust_code']) ?> ›</a><?php endif; ?>
</nav>

<?php if ($stats['stale']): ?>
  <p class="alert warn"><?= admin_icon('alert') ?><span><strong>Más de 6 meses sin comprar.</strong> Su última compra fue el <?= e(erp_day($stats['last'])) ?>.</span></p>
<?php endif; ?>

<form method="get" action="<?= e(url($self)) ?>" class="cf-year">
  <input type="hidden" name="pestana" value="<?= e($tab) ?>">
  <label>Año de referencia
    <select name="anio"><?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= $y === $stats['year'] ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?></select></label>
  <button class="btn small ghost" type="submit">Ver</button>
</form>
<section class="stats cf-cards" aria-label="Cifras del cliente">
  <div class="stat"><span class="stat-l">Facturado en <?= (int)$stats['year'] ?></span><strong class="stat-n stat-date"><?= e(erp_money($stats['cur'])) ?></strong>
    <span class="stat-s"><?php if ($pct === null): ?>sin año anterior con qué comparar<?php else: ?><span class="<?= $pct >= 0 ? 'up' : 'down' ?>"><?= $pct >= 0 ? '▲' : '▼' ?> <?= e(number_format(abs($pct), 1, ',', '.')) ?> %</span> frente a <?= (int)$stats['year'] - 1 ?><?php endif; ?></span></div>
  <div class="stat"><span class="stat-l">Facturado en <?= (int)$stats['year'] - 1 ?></span><strong class="stat-n stat-date"><?= e(erp_money($stats['prev'])) ?></strong><span class="stat-s">año anterior</span></div>
  <div class="stat"><span class="stat-l">Facturas</span><strong class="stat-n"><?= e($n($stats['n'])) ?></strong><span class="stat-s">sin anuladas</span></div>
  <div class="stat"><span class="stat-l">Importe medio</span><strong class="stat-n stat-date"><?= e(erp_money($stats['avg'])) ?></strong><span class="stat-s">por factura, sin devoluciones</span></div>
  <div class="stat"><span class="stat-l">Primera compra</span><strong class="stat-n stat-date"><?= e(erp_day($stats['first'])) ?></strong></div>
  <div class="stat<?= $stats['stale'] ? ' warn' : '' ?>"><span class="stat-l">Última compra</span><strong class="stat-n stat-date"><?= e(erp_day($stats['last'])) ?></strong></div>
</section>

<div class="cf-layout">
  <div class="cf-main">
    <nav class="cf-tabs" aria-label="Secciones de la ficha">
      <?php foreach (ERP_CLIENT_TABS as $k => $label): ?>
        <a href="<?= e($tabUrl($k, $stats['year'] !== (int)date('Y') ? ['anio' => $stats['year']] : [])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><?= $k === 'notas' && $nNotes > 0 ? ' (' . (int)$nNotes . ')' : '' ?></a>
      <?php endforeach; ?>
    </nav>

<?php if ($tab === 'resumen'): ?>
    <section class="cf-card">
      <h2>Compras por mes · últimos 24 meses</h2>
      <?php if ($chartNote !== ''): ?><p class="muted" style="margin-top:0"><?= e($chartNote) ?></p><?php endif; ?>
      <?php if (array_sum(array_map('abs', array_column($series, 'v'))) === 0): ?>
        <p class="empty">Sin compras en este periodo.</p>
      <?php else: ?>
        <?= $svg ?>
        <details class="cf-data"><summary>Ver los importes en una tabla</summary>
          <div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">Mes</th><th scope="col">Importe</th></tr></thead><tbody>
            <?php foreach ($series as $s): ?><tr><td><?= e(explode(':', $s['title'])[0]) ?></td><td><?= e(erp_money((int)$s['v'])) ?></td></tr><?php endforeach; ?>
          </tbody></table></div></details>
      <?php endif; ?>
    </section>
    <div class="cf-two">
      <section class="cf-card">
        <h2>Top 5 artículos</h2>
        <?php if (!$topArts): ?><p class="empty">Todavía sin compras.</p><?php else: ?>
          <ol class="cf-top">
            <?php foreach ($topArts as $a): $code = (string)$a['article_code']; $inf = $map[mb_strtolower($code)] ?? null; ?>
              <li><span><?php if ($inf): ?><a href="<?= e(url('/admin/articulo/' . (int)$inf['id'])) ?>"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?> <small class="muted"><?= e($a['title']) ?></small></span><strong><?= e(erp_money((int)$a['amount'])) ?></strong></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </section>
      <section class="cf-card">
        <h2>Top 5 <?= e(term('families')) ?></h2>
        <?php if (!$topFams): ?><p class="empty">Todavía sin compras.</p><?php else: ?>
          <ol class="cf-top">
            <?php foreach ($topFams as $f => $d): ?>
              <li><span><?= e($f) ?> <small class="muted"><?= e(erp_qty((int)$d['qty'])) ?> uds.</small></span><strong><?= e(erp_money((int)$d['amount'])) ?></strong></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
      </section>
    </div>

<?php elseif ($tab === 'facturas'): ?>
    <section class="cf-card">
      <h2>Facturas <small class="muted">(<?= e($n($total)) ?>)</small></h2>
      <?php if (!$invoices): ?><p class="empty">Este cliente no tiene facturas.</p><?php else: ?>
        <p class="muted" style="margin-top:0">Pulsa una factura para ver sus líneas.</p>
        <div class="cf-invs">
          <div class="cf-inv-h" aria-hidden="true"><span>Factura</span><span>Fecha</span><span>Base</span><span>IVA</span><span>Total</span></div>
          <?php foreach ($invoices as $i): $ls = $lines[(int)$i['id']] ?? []; ?>
            <details class="cf-inv<?= (int)$i['annulled'] === 1 ? ' off' : '' ?>">
              <summary><span class="cf-n"><?= e($i['inv_number']) ?><?= (int)$i['annulled'] === 1 ? ' <small class="review-flag">anulada</small>' : '' ?><?= (int)$i['total_cents'] < 0 ? ' <small class="review-flag">devolución</small>' : '' ?></span>
                <span><?= e(erp_day((int)$i['inv_day'])) ?></span><span><?= e(erp_money((int)$i['base_cents'])) ?></span><span><?= e(erp_money((int)$i['vat_cents'])) ?></span><strong><?= e(erp_money((int)$i['total_cents'])) ?></strong></summary>
              <div class="cf-lines">
                <?php if (!$ls): ?><p class="muted">Esta factura no tiene líneas.</p><?php else: ?>
                <div class="tbl-wrap"><table class="tbl"><thead><tr><th scope="col">Artículo</th><th scope="col">Talla</th><th scope="col">Color</th><th scope="col">Uds.</th><th scope="col">Precio</th><th scope="col">Importe</th></tr></thead><tbody>
                  <?php foreach ($ls as $l): $code = (string)$l['article_code']; $inf = $map[mb_strtolower($code)] ?? null; ?>
                    <tr><td><?php if ($inf): ?><a href="<?= e(url('/admin/articulo/' . (int)$inf['id'])) ?>"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?> <small class="muted"><?= e($l['title']) ?></small></td>
                      <td><?= e($l['size_txt'] !== '' ? $l['size_txt'] : ((int)$l['size_no'] > 0 ? 'nº ' . $l['size_no'] : '')) ?></td>
                      <td><?= e($l['color_txt'] !== '' ? $l['color_txt'] : ((int)$l['color_no'] > 0 ? 'nº ' . $l['color_no'] : '')) ?></td>
                      <td><?= e(erp_qty((int)$l['qty_milli'])) ?></td><td><?= e(erp_money((int)$l['price_cents'])) ?></td><td><?= e(erp_money((int)$l['amount_cents'])) ?></td></tr>
                  <?php endforeach; ?>
                </tbody></table></div>
                <?php endif; ?>
                <p class="muted" style="margin:6px 0 0"><a href="<?= e(url('/admin/ventas/' . erp_inv_seg($i['inv_number']))) ?>">Abrir la factura completa →</a></p>
              </div>
            </details>
          <?php endforeach; ?>
        </div>
        <?php if ($pages > 1): ?>
        <nav class="pager" aria-label="Páginas"><span class="muted">Página <?= $page ?> de <?= $pages ?></span><span class="pager-l">
          <?php if ($page > 1): ?><a href="<?= e($tabUrl('facturas', $page > 2 ? ['pag' => $page - 1] : [])) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
          <?php if ($page < $pages): ?><a href="<?= e($tabUrl('facturas', ['pag' => $page + 1])) ?>" rel="next">Siguiente ›</a><?php endif; ?>
        </span></nav>
        <?php endif; ?>
      <?php endif; ?>
    </section>

<?php elseif ($tab === 'articulos'): ?>
    <?php
      $sortUrl = function (string $k, string $label) use ($sort, $asc, $tabUrl) {
          $dirNext = $sort === $k && !$asc ? 'asc' : 'desc';
          return '<a href="' . e($tabUrl('articulos', ['orden' => $k, 'dir' => $dirNext])) . '">' . e($label) . ($sort === $k ? ($asc ? ' ↑' : ' ↓') : '') . '</a>';
      };
      $top = function (array $list) { arsort($list); $o = []; foreach (array_slice($list, 0, 3, true) as $k => $q) { $o[] = $k . ' ×' . erp_qty($q); } return implode(' · ', $o); };
    ?>
    <section class="cf-card">
      <h2>Artículos comprados <small class="muted">(<?= e($n($total)) ?>)</small></h2>
      <?php if (!$rows): ?><p class="empty">Todavía no hay compras de este cliente.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl list">
        <thead><tr><th scope="col">Artículo</th><th scope="col"><?= $sortUrl('unidades', 'Unidades') ?></th><th scope="col"><?= $sortUrl('importe', 'Importe') ?></th><th scope="col"><?= $sortUrl('ultima', 'Última vez') ?></th><th scope="col">Tallas y colores</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $code = (string)$r['article_code']; $inf = $map[mb_strtolower($code)] ?? null; ?>
          <tr>
            <td><?php if ($inf): ?><a href="<?= e(url('/admin/articulo/' . (int)$inf['id'])) ?>"><?= e($code) ?></a><?php else: ?><?= e($code) ?><?php endif; ?> <small class="muted"><?= e($r['title']) ?></small><?= $inf ? '<br><small class="muted">' . e($inf['fam']) . '</small>' : '' ?></td>
            <td><?= e(erp_qty((int)$r['qty'])) ?></td>
            <td><?= e(erp_money((int)$r['amount'])) ?></td>
            <td class="t-date"><?= e(erp_day((int)$r['last_day'])) ?></td>
            <td><small><?= isset($sizes[$code]) ? 'Tallas: ' . e($top($sizes[$code])) : '' ?><?= isset($sizes[$code], $colors[$code]) ? '<br>' : '' ?><?= isset($colors[$code]) ? 'Colores: ' . e($top($colors[$code])) : '' ?></small></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Páginas"><span class="muted">Página <?= $page ?> de <?= $pages ?></span><span class="pager-l">
        <?php $ex = ['orden' => $sort] + ($asc ? ['dir' => 'asc'] : []); ?>
        <?php if ($page > 1): ?><a href="<?= e($tabUrl('articulos', $ex + ($page > 2 ? ['pag' => $page - 1] : []))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
        <?php if ($page < $pages): ?><a href="<?= e($tabUrl('articulos', $ex + ['pag' => $page + 1])) ?>" rel="next">Siguiente ›</a><?php endif; ?>
      </span></nav>
      <?php endif; ?>
      <p class="muted">Las devoluciones restan. Las tallas y colores salen con su texto real si se sincronizó con FALMACEN.MDB; si no, como «nº».</p>
      <?php endif; ?>
    </section>

<?php else: ?>
    <section class="cf-card">
      <h2>Notas internas</h2>
      <p class="muted" style="margin-top:0">Solo las ven los usuarios con acceso a Clientes y Ventas. No se envían a NCS ni se recuperan sincronizando.</p>
      <form method="post" action="<?= e(url($self)) ?>" class="form cf-note-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="note_add">
        <label>Nueva nota <textarea name="note" rows="3" maxlength="<?= (int)ERP_NOTE_MAX ?>" required></textarea><small>Máximo <?= (int)ERP_NOTE_MAX ?> caracteres.</small></label>
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
    </section>
<?php endif; ?>
  </div>

  <aside class="cf-aside" aria-label="Contacto y dirección">
    <section class="cf-card">
      <h2>Contacto</h2>
      <dl class="kv kv-1">
        <?php if ($c['contact'] !== ''): ?><div><dt>Persona de contacto</dt><dd><?= e($c['contact']) ?></dd></div><?php endif; ?>
        <?php foreach ([['Teléfono', $c['phone']], ['Teléfono 2', $c['phone2']]] as [$k, $val]): if ($val === '') continue; ?>
          <div><dt><?= e($k) ?></dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $val)) ?>"><?= e($val) ?></a></dd></div>
        <?php endforeach; ?>
        <?php if ($c['email'] !== ''): ?><div><dt>Email</dt><dd><a href="mailto:<?= e($c['email']) ?>"><?= e($c['email']) ?></a></dd></div><?php endif; ?>
        <?php if ($c['phone'] === '' && $c['phone2'] === '' && $c['email'] === '' && $c['contact'] === ''): ?><div><dd class="muted">Sin datos de contacto.</dd></div><?php endif; ?>
      </dl>
    </section>
    <section class="cf-card">
      <h2>Dirección</h2>
      <?php if ($addr === ''): ?><p class="muted" style="margin:0">Sin dirección.</p><?php else: ?>
        <address class="cf-addr"><?= e($c['address']) ?><br><?= e(trim($c['postcode'] . ' ' . $c['city'])) ?><?= $c['province'] !== '' ? '<br>' . e($c['province']) : '' ?><?= $c['country'] !== '' ? '<br>' . e($c['country']) : '' ?></address>
        <p style="margin:8px 0 0"><a href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(rawurlencode($addr)) ?>" target="_blank" rel="noopener noreferrer">Ver en el mapa ↗</a></p>
        <p class="muted" style="margin:4px 0 0"><small>Abre Google Maps en otra pestaña; la dirección solo se envía si pulsas el enlace.</small></p>
      <?php endif; ?>
    </section>
    <section class="cf-card">
      <h2>Datos comerciales</h2>
      <dl class="kv kv-1">
        <div><dt>Tarifa</dt><dd><?= (int)$c['tariff'] > 0 ? (int)$c['tariff'] : '—' ?></dd></div>
        <div><dt>Forma de pago</dt><dd><?= e($c['pay_code'] !== '' ? erp_pay_label($c['pay_code']) : '—') ?></dd></div>
        <div><dt>Día de pago</dt><dd><?= e((string)($c['pay_days'] ?? '') !== '' ? str_replace(',', ' y ', (string)$c['pay_days']) : '—') ?></dd></div>
        <div><dt>Descuento</dt><dd><?= (int)$c['disc_bp'] > 0 ? e(rtrim(rtrim(number_format($c['disc_bp'] / 100, 2, ',', ''), '0'), ',')) . ' %' : '—' ?></dd></div>
        <div><dt>Alta en NCS</dt><dd><?= e(erp_day((int)$c['created_day'])) ?></dd></div>
        <?php if ($ended): ?><div><dt>Baja</dt><dd><?= e(erp_day((int)$c['ended_day'])) ?></dd></div><?php endif; ?>
      </dl>
      <form method="post" action="<?= e(url('/admin/clientes/' . rawurlencode((string)$c['cust_code']))) ?>" class="cf-paydays">
        <?= csrf_field() ?><input type="hidden" name="action" value="pay_days">
        <label><small class="muted">Día de pago (5, o 5,25)</small>
          <input name="pay_days" value="<?= e((string)($c['pay_days'] ?? '')) ?>" maxlength="20" placeholder="sin día fijo" style="width:9em"></label>
        <button class="btn small ghost" type="submit">Guardar</button>
      </form>
    </section>
  </aside>
</div>
<script>
// Opcional: copiar el NIF e imprimir (sin JavaScript los botones no aparecen; el NIF se selecciona con un clic).
document.querySelectorAll('.js-only').forEach(function (b) { b.hidden = false; });
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var i = document.getElementById(b.getAttribute('data-copy')); if (!i) return;
    i.select();
    var done = function () { var t = b.textContent; b.textContent = '¡Copiado!'; setTimeout(function () { b.textContent = t; }, 1500); };
    if (navigator.clipboard) { navigator.clipboard.writeText(i.value).then(done); } else { document.execCommand('copy'); done(); }
  });
});
</script>
