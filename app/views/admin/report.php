<?php
/**
 * Pantalla de un informe: selección (filtros, tipo de listado, agrupar, ordenar, casillas), botones de salida y resultado paginado.
 * Variables: $def, $sel, $ran, $res (null si aún no se ha pedido), $page, $pages, $canZip, $selText.
 */
$self = url('/admin/listados/' . $def['id']);
$cols = $res['cols'] ?? [];
// Dirección de la página siguiente/anterior: la misma selección con otra página
$qs = $_GET;
$qs['salida'] = 'pantalla';
unset($qs['go']);
if (!isset($_GET['salida']) && !isset($_GET['go'])) { // vino de «lo recordado»: se reconstruye desde la selección
    $qs = array_merge(rep_prefs_load($def['id']), ['salida' => 'pantalla']);
}
$pageUrl = fn(int $p) => $self . '?' . http_build_query(array_merge($qs, ['pag' => $p]));
$lookupUrl = $self . '/buscar';
?>
<div class="head"><h1><?= e($def['title']) ?></h1></div>
<?php if ($def['help'] !== ''): ?><p class="hint"><?= e($def['help']) ?></p><?php endif; ?>
<?php if ($def['notice'] !== ''): ?><p class="alert warn"><?= admin_icon('alert') ?><span><strong><?= e($def['notice']) ?></strong></span></p><?php endif; ?>

<form method="get" action="<?= e($self) ?>" class="rep-form" id="repform">
  <fieldset class="rep-box">
    <legend>Selección</legend>
    <div class="rep-grid">
    <?php foreach ($def['filters'] as $flt): $n = $flt['name']; $type = $flt['type'] ?? 'text'; $val = $sel['f'][$n] ?? ''; ?>
      <?php if ($type === 'date'): ?>
        <div class="rep-f"><span class="rep-l"><?= e($flt['label']) ?></span>
          <label>Desde <input type="date" name="<?= e($n) ?>_d" value="<?= e(rep_date_out((int)$val['d'])) ?>"></label>
          <label>Hasta <input type="date" name="<?= e($n) ?>_h" value="<?= e(rep_date_out((int)$val['h'])) ?>"></label></div>
      <?php elseif ($type === 'code'): ?>
        <div class="rep-f"><span class="rep-l"><?= e($flt['label']) ?></span>
          <label>Desde <span class="rep-pick"><input name="<?= e($n) ?>_d" value="<?= e($val['d']) ?>" maxlength="60" autocomplete="off"<?= !empty($flt['lookup']) ? ' data-lookup="' . e($flt['lookup']) . '"' : '' ?>><?php if (!empty($flt['lookup'])): ?><button type="button" class="rep-dots" data-for="<?= e($n) ?>_d" title="Buscar" aria-label="Buscar <?= e(mb_strtolower($flt['label'])) ?>" hidden>…</button><?php endif; ?></span></label>
          <label>Hasta <span class="rep-pick"><input name="<?= e($n) ?>_h" value="<?= e($val['h']) ?>" maxlength="60" autocomplete="off"<?= !empty($flt['lookup']) ? ' data-lookup="' . e($flt['lookup']) . '"' : '' ?>><?php if (!empty($flt['lookup'])): ?><button type="button" class="rep-dots" data-for="<?= e($n) ?>_h" title="Buscar" aria-label="Buscar <?= e(mb_strtolower($flt['label'])) ?>" hidden>…</button><?php endif; ?></span></label></div>
      <?php elseif ($type === 'select'): ?>
        <div class="rep-f"><label><span class="rep-l"><?= e($flt['label']) ?></span>
          <select name="<?= e($n) ?>"><option value="">Todos</option><?php foreach (rep_filter_options($flt) as $ov => $ol): ?><option value="<?= e((string)$ov) ?>"<?= (string)$ov === (string)$val ? ' selected' : '' ?>><?= e((string)$ol) ?></option><?php endforeach; ?></select></label></div>
      <?php else: ?>
        <div class="rep-f"><label><span class="rep-l"><?= e($flt['label']) ?></span><input name="<?= e($n) ?>" value="<?= e((string)$val) ?>" maxlength="80" placeholder="Contiene…"></label></div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
  </fieldset>
  <fieldset class="rep-box">
    <legend>Listado</legend>
    <div class="rep-grid">
      <?php if (count($def['variants']) > 1): ?>
      <div class="rep-f"><label><span class="rep-l">Tipo de listado</span>
        <select name="v"><?php foreach ($def['variants'] as $vk => $vl): ?><option value="<?= e((string)$vk) ?>"<?= (string)$vk === $sel['v'] ? ' selected' : '' ?>><?= e($vl) ?></option><?php endforeach; ?></select></label></div>
      <?php endif; ?>
      <?php if ($def['groups']): ?>
      <div class="rep-f"><label><span class="rep-l">Agrupar por</span>
        <select name="g"><option value="">Ninguno</option><?php foreach ($def['groups'] as $gk => $g): ?><option value="<?= e((string)$gk) ?>"<?= (string)$gk === $sel['g'] ? ' selected' : '' ?>><?= e($g['label']) ?></option><?php endforeach; ?></select></label></div>
      <?php endif; ?>
      <?php if ($def['orders']): ?>
      <div class="rep-f"><label><span class="rep-l">Ordenado por</span>
        <select name="o"><?php foreach ($def['orders'] as $ok => $o): ?><option value="<?= e((string)$ok) ?>"<?= (string)$ok === $sel['o'] ? ' selected' : '' ?>><?= e($o['label']) ?></option><?php endforeach; ?></select></label>
        <label class="check"><input type="checkbox" name="dir" value="desc"<?= $sel['dir'] === 'desc' ? ' checked' : '' ?>> <span>De mayor a menor</span></label></div>
      <?php endif; ?>
    </div>
    <?php if ($def['options']): ?>
    <div class="rep-opts"><?php foreach ($def['options'] as $op): ?>
      <label class="check"><input type="checkbox" name="x_<?= e($op['name']) ?>" value="1"<?= !empty($sel['opt'][$op['name']]) ? ' checked' : '' ?>> <span><?= e($op['label']) ?></span></label>
    <?php endforeach; ?></div>
    <?php endif; ?>
  </fieldset>
  <div class="rep-btns buttons">
    <button class="btn" type="submit" name="salida" value="pantalla">Ver en pantalla</button>
    <button class="btn ghost" type="submit" name="salida" value="imprimir" formtarget="_blank">Imprimir / PDF</button>
    <?php if ($canZip): ?><button class="btn ghost" type="submit" name="salida" value="xlsx">Excel</button><?php endif; ?>
    <button class="btn ghost" type="submit" name="salida" value="csv">CSV</button>
    <a class="rep-reset" href="<?= e($self . '?reset=1') ?>" title="Olvidar la selección guardada">Restablecer</a>
  </div>
  <?php if (!$canZip): ?><p class="muted"><small>Este servidor no tiene la extensión «zip»: el Excel no está disponible, usa CSV (Excel lo abre bien).</small></p><?php endif; ?>
</form>

<?php if (!$ran): ?>
  <p class="empty">Elige la selección y pulsa «Ver en pantalla». Vacío = todos. Se recuerda la última selección de cada informe.</p>
<?php else: ?>
  <p class="muted rep-sel"><?= e($selText) ?></p>
  <?php if (!empty($res['capped'])): ?><p class="alert warn"><?= admin_icon('alert') ?><span>Hay demasiadas filas: se muestran solo las primeras <?= (int)REP_MAX_ROWS ?>. Acota la selección.</span></p><?php endif; ?>
  <?php $total = null; foreach ($res['lines'] as $l) { if ($l['t'] === 'total') { $total = $l; } } ?>
  <?php if ($res['rows'] === 0): ?>
    <p class="empty">Ningún dato con esa selección.</p>
  <?php else: ?>
  <p class="muted"><?= (int)$res['rows'] ?> fila<?= $res['rows'] === 1 ? '' : 's' ?><?= $pages > 1 ? ' · página ' . (int)$page . ' de ' . (int)$pages : '' ?></p>
  <div class="tbl-wrap"><table class="tbl rep-tbl">
    <thead><tr><?php foreach ($cols as $c): ?><th scope="col"<?= in_array($c['type'] ?? 'text', ['money', 'qty', 'int', 'pct'], true) ? ' class="r"' : '' ?>><?= e($c['label']) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($res['lines'] as $ln): ?>
      <?php if ($ln['t'] === 'gh'): ?>
        <tr class="rep-gh"><th colspan="<?= count($cols) ?>"><?= e($ln['label']) ?></th></tr>
      <?php elseif ($ln['t'] === 'row'): ?>
        <tr><?php foreach ($cols as $c): $isNum = in_array($c['type'] ?? 'text', ['money', 'qty', 'int', 'pct'], true); ?><td<?= $isNum ? ' class="r"' : '' ?>><?= e(rep_cell($c, $ln['r'][$c['key']] ?? null)) ?></td><?php endforeach; ?></tr>
      <?php else: ?>
        <tr class="rep-<?= e($ln['t']) ?>"><?php foreach ($cols as $i => $c): $isNum = in_array($c['type'] ?? 'text', ['money', 'qty', 'int', 'pct'], true); ?>
          <td<?= $isNum ? ' class="r"' : '' ?>><?php if ($i === 0): ?><?= e($ln['label']) ?> <small class="muted">(<?= (int)$ln['n'] ?>)</small><?php elseif (!empty($c['sum'])): ?><?= e(rep_cell($c, $ln['sum'][$c['key']] ?? 0)) ?><?php endif; ?></td><?php endforeach; ?></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <nav class="pager" aria-label="Páginas">
    <?php if ($page > 1): ?><a href="<?= e($pageUrl($page - 1)) ?>">‹ Anterior</a><?php endif; ?>
    <span>Página <?= (int)$page ?> de <?= (int)$pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= e($pageUrl($page + 1)) ?>">Siguiente ›</a><?php endif; ?>
  </nav>
  <p class="muted"><small>El total general está en la última página. En Excel, CSV e impresión sale todo.</small></p>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

<ul id="rep-pop" class="rep-pop" role="listbox" hidden></ul>
<script>
(function () {
  var form = document.getElementById('repform'), pop = document.getElementById('rep-pop');
  if (!form || !pop) { return; }
  var base = <?= json_encode($lookupUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var cur = null, items = [], act = -1, timer = null, seq = 0;
  form.querySelectorAll('.rep-dots').forEach(function (b) { b.hidden = false; });
  function close() { pop.hidden = true; pop.textContent = ''; items = []; act = -1; cur = null; }
  function mark(i) { var l = pop.querySelectorAll('li[role=option]'); l.forEach(function (x, k) { x.classList.toggle('on', k === i); }); act = i; if (l[i]) { l[i].scrollIntoView({block: 'nearest'}); } }
  function choose(it) { if (cur) { cur.value = it.v; cur.focus(); } close(); }
  function show(inp, list) {
    pop.textContent = ''; items = list; cur = inp;
    if (!list.length) { var e0 = document.createElement('li'); e0.className = 'muted'; e0.textContent = 'Sin resultados.'; pop.appendChild(e0); }
    list.forEach(function (it) {
      var li = document.createElement('li'); li.setAttribute('role', 'option');
      var a = document.createElement('strong'); a.textContent = it.v; var b = document.createElement('span'); b.textContent = ' ' + it.l;
      li.appendChild(a); li.appendChild(b);
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(it); });
      pop.appendChild(li);
    });
    var r = inp.getBoundingClientRect();
    pop.style.left = Math.max(8, Math.min(r.left + window.scrollX, window.scrollX + document.documentElement.clientWidth - 328)) + 'px';
    pop.style.top = (r.bottom + window.scrollY + 2) + 'px';
    pop.hidden = false;
    if (list.length) { mark(0); }
  }
  function search(inp) {
    var q = inp.value.trim(), my = ++seq;
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch(base + '?l=' + encodeURIComponent(inp.getAttribute('data-lookup')) + '&q=' + encodeURIComponent(q), {credentials: 'same-origin', headers: {'Accept': 'application/json'}})
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (list) { if (my === seq) { show(inp, list); } }).catch(function () {});
    }, 250);
  }
  form.addEventListener('input', function (ev) {
    var el = ev.target;
    if (el.getAttribute && el.getAttribute('data-lookup')) { if (el.value.trim().length < 1) { close(); } else { search(el); } }
  });
  form.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('.rep-dots') : null;
    if (b) { var inp = form.querySelector('[name="' + b.getAttribute('data-for') + '"]'); if (inp) { inp.focus(); search(inp); } }
  });
  form.addEventListener('keydown', function (ev) {
    if (pop.hidden || ev.target !== cur) { return; }
    if (ev.key === 'ArrowDown') { ev.preventDefault(); if (items.length) { mark((act + 1) % items.length); } }
    else if (ev.key === 'ArrowUp') { ev.preventDefault(); if (items.length) { mark((act - 1 + items.length) % items.length); } }
    else if (ev.key === 'Enter' && act > -1 && items[act]) { ev.preventDefault(); choose(items[act]); }
    else if (ev.key === 'Escape') { ev.preventDefault(); close(); }
  });
  form.addEventListener('focusout', function (ev) { if (ev.target === cur) { setTimeout(function () { if (document.activeElement !== cur) { close(); } }, 0); } });
})();
</script>
