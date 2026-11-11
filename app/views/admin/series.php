<?php
/**
 * Configuración → Series. Variables: $rows (series con 'used'), $counters [id => [yr => [start, last]]], $year, $ncs (letras de NCS), $errors, $old (POST anterior o null).
 */
$old = $old ?? null;
$post = fn(int $id, string $k, $default) => $old !== null && isset($old['s'][$id]) ? ($old['s'][$id][$k] ?? '') : $default;
?>
<div class="head"><h1>Series de documentos</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Cada tipo de documento (factura, rectificativa, albarán, pedido) puede tener varias series; una es la <strong>por defecto</strong>. Los números son correlativos por serie y año, sin huecos. Ejemplo: <code class="series-prev"><?= e(series_format(['letter' => 'F', 'digits' => 5, 'with_year' => 1], 1, $year)) ?></code>.</p>

<form method="post" action="<?= e(url('/admin/configuracion/series')) ?>" class="cfgf" autocomplete="off" id="series-form">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">

  <?php foreach (DOC_SERIES_TYPES_PL as $type => $plural): $list = array_filter($rows, fn($r) => $r['doc_type'] === $type); ?>
    <fieldset>
      <legend><?= e($plural) ?></legend>
      <?php if (!$list): ?>
        <p class="muted" style="margin:0">No hay series de este tipo. Crea una abajo.</p>
      <?php else: ?>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th scope="col">Letra</th><th scope="col">Descripción</th><th scope="col">Dígitos</th><th scope="col">Con año</th><th scope="col">Siguiente (<?= (int)$year ?>)</th><th scope="col">Ejemplo</th><th scope="col">Por defecto</th><th scope="col">Activa</th><th scope="col"></th></tr></thead>
          <tbody>
          <?php foreach ($list as $id => $s):
              $yr = (int)$s['with_year'] === 1 ? $year : 0;
              $c = $counters[$id][$yr] ?? null;
              $curUsed = $c !== null && $c['last'] >= $c['start'];
              $next = $c === null ? 1 : $c['last'] + 1;
              $letter = $s['used'] ? $s['letter'] : (string)$post($id, 'letter', $s['letter']);
              $digits = $s['used'] ? (int)$s['digits'] : (int)$post($id, 'digits', $s['digits']);
              $withYear = $s['used'] ? (int)$s['with_year'] : ($old !== null && isset($old['s'][$id]) ? (!empty($old['s'][$id]['with_year']) ? 1 : 0) : (int)$s['with_year']);
              $active = $old !== null && isset($old['s'][$id]) ? !empty($old['s'][$id]['active']) : (int)$s['is_active'] === 1;
              $clash = isset($ncs[strtoupper($s['letter'])]);
          ?>
            <tr data-year="<?= (int)$year ?>">
              <td><?php if ($s['used']): ?><strong><?= e($s['letter']) ?></strong><?php else: ?><input class="letter" name="s[<?= $id ?>][letter]" value="<?= e($letter) ?>" maxlength="3" required aria-label="Letra"><?php endif; ?></td>
              <td><input name="s[<?= $id ?>][descr]" value="<?= e((string)$post($id, 'descr', $s['descr'])) ?>" maxlength="60" aria-label="Descripción"></td>
              <td><?php if ($s['used']): ?><?= (int)$s['digits'] ?><?php else: ?><input class="num" type="number" name="s[<?= $id ?>][digits]" value="<?= $digits ?>" min="<?= SERIES_DIGITS_MIN ?>" max="<?= SERIES_DIGITS_MAX ?>" aria-label="Dígitos"><?php endif; ?></td>
              <td><?php if ($s['used']): ?><?= (int)$s['with_year'] === 1 ? 'Sí' : 'No' ?><?php else: ?><input type="checkbox" name="s[<?= $id ?>][with_year]" value="1"<?= $withYear ? ' checked' : '' ?> aria-label="Con año"><?php endif; ?></td>
              <td>
                <?php if ($curUsed): ?>
                  <span title="Ya hay números usados este año: no puede bajar ni dejar huecos"><?= (int)$next ?> <small class="muted">🔒</small></span>
                <?php else: ?>
                  <input class="num" type="number" min="1" name="s[<?= $id ?>][start]" value="<?= e((string)($old !== null && isset($old['s'][$id]['start']) && $old['s'][$id]['start'] !== '' ? $old['s'][$id]['start'] : ($c === null ? '' : $c['start']))) ?>" placeholder="<?= (int)$next ?>" aria-label="Siguiente número">
                <?php endif; ?>
              </td>
              <td><code class="series-prev"><?= e(series_format(['letter' => $letter !== '' ? $letter : '?', 'digits' => max(1, $digits), 'with_year' => $withYear], $curUsed ? $next : ($c === null ? 1 : $c['start']), $year)) ?></code></td>
              <td><input type="radio" name="default[<?= e($type) ?>]" value="<?= $id ?>"<?= (int)$s['is_default'] === 1 ? ' checked' : '' ?> aria-label="Por defecto"></td>
              <td><input type="checkbox" name="s[<?= $id ?>][active]" value="1"<?= $active ? ' checked' : '' ?> aria-label="Activa"></td>
              <td>
                <?php if (!$s['used']): ?><button class="link danger" type="submit" name="action" value="delete" formaction="<?= e(url('/admin/configuracion/series')) ?>" onclick="document.getElementById('del-id').value=<?= $id ?>;return confirm('¿Borrar la serie <?= e($s['letter']) ?>? No se ha usado nunca.')">Borrar</button>
                <?php else: ?><small class="muted" title="Una serie usada no se borra; desactívala si ya no la quieres">en uso</small><?php endif; ?>
              </td>
            </tr>
            <?php if ($clash): ?><tr><td colspan="9"><p class="cfg-warn" style="margin:0">⚠ La letra «<?= e($s['letter']) ?>» también la usan facturas importadas de NCS<?= (int)$s['with_year'] === 1 ? ': los números llevan el año y no se repiten, pero conviene usar otra letra para no confundirlas.' : ' y esta serie no lleva año.' ?></p></td></tr><?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </fieldset>
  <?php endforeach; ?>
  <input type="hidden" name="id" id="del-id" value="">

  <fieldset>
    <legend>Nueva serie</legend>
    <div class="g4">
      <label>Tipo
        <select name="new[type]"><option value="">—</option><?php foreach (DOC_SERIES_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= ($old['new']['type'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label>Letra (1-3) <input name="new[letter]" value="<?= e((string)($old['new']['letter'] ?? '')) ?>" maxlength="3" style="text-transform:uppercase"></label>
      <label>Dígitos <input type="number" name="new[digits]" value="<?= e((string)($old['new']['digits'] ?? 5)) ?>" min="<?= SERIES_DIGITS_MIN ?>" max="<?= SERIES_DIGITS_MAX ?>"></label>
      <label>Primer número <input type="number" name="new[start]" value="<?= e((string)($old['new']['start'] ?? '')) ?>" min="1" placeholder="1"></label>
    </div>
    <div class="g2">
      <label>Descripción <input name="new[descr]" value="<?= e((string)($old['new']['descr'] ?? '')) ?>" maxlength="60" placeholder="Ej.: Facturas de la tienda"></label>
      <label class="check" style="align-self:end"><input type="checkbox" name="new[with_year]" value="1"<?= $old === null || !empty($old['new']['with_year']) ? ' checked' : '' ?>> <span>La numeración lleva el año (<code>/AA</code>) y empieza de nuevo cada año</span></label>
    </div>
    <small>Se crea al pulsar Guardar. Déjala vacía si solo quieres cambiar las que ya hay.</small>
  </fieldset>

  <fieldset>
    <legend>Primer número de otro año</legend>
    <div class="g3">
      <label>Serie
        <select name="yr_series"><option value="">—</option><?php foreach ($rows as $id => $s): if ((int)$s['with_year'] !== 1) { continue; } ?><option value="<?= $id ?>"<?= (int)($old['yr_series'] ?? 0) === $id ? ' selected' : '' ?>><?= e($s['letter']) ?> · <?= e($s['descr']) ?></option><?php endforeach; ?></select></label>
      <label>Año <input type="number" name="yr_year" value="<?= e((string)($old['yr_year'] ?? '')) ?>" min="2000" max="2100" placeholder="<?= (int)$year + 1 ?>"></label>
      <label>Primer número <input type="number" name="yr_start" value="<?= e((string)($old['yr_start'] ?? '')) ?>" min="1" placeholder="1"></label>
    </div>
    <small>Solo mientras ese año no tenga números usados. Sirve, por ejemplo, para continuar una numeración que ya llevabas en otro programa.</small>
  </fieldset>

  <?php $any = false; foreach ($rows as $id => $s): foreach ($counters[$id] ?? [] as $yr => $c): $any = true; endforeach; endforeach; ?>
  <?php if ($any): ?>
  <fieldset>
    <legend>Contadores por año</legend>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th scope="col">Serie</th><th scope="col">Año</th><th scope="col">Primer número</th><th scope="col">Último usado</th><th scope="col">Siguiente</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $id => $s): foreach ($counters[$id] ?? [] as $yr => $c): $used = $c['last'] >= $c['start']; ?>
        <tr><td><?= e($s['letter']) ?></td><td><?= $yr === 0 ? 'sin año' : (int)$yr ?></td><td><?= (int)$c['start'] ?></td><td><?= $used ? (int)$c['last'] : '—' ?></td><td><?= (int)$c['last'] + 1 ?></td></tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table></div>
  </fieldset>
  <?php endif; ?>

  <div class="savebar"><button class="btn" type="submit" onclick="document.getElementById('del-id').value=''">Guardar</button><span class="muted">Una serie ya usada no se borra: se desactiva. Su letra, dígitos y año quedan bloqueados.</span></div>
</form>

<script>
/* Vista previa en vivo (opcional: sin JavaScript el ejemplo se actualiza al guardar) */
document.querySelectorAll('#series-form tbody tr[data-year]').forEach(function (tr) {
  var letter = tr.querySelector('.letter'), digits = tr.querySelector('input[name$="[digits]"]'), yr = tr.querySelector('input[name$="[with_year]"]'), prev = tr.querySelector('code.series-prev');
  var next = tr.querySelector('input[name$="[start]"]');
  if (!prev || !letter) return;
  function upd() {
    var n = parseInt((next && next.value) || next && next.placeholder || '1', 10) || 1, d = Math.max(1, parseInt(digits.value || '5', 10) || 5);
    var s = String(n); while (s.length < d) s = '0' + s;
    prev.textContent = (letter.value || '?').toUpperCase() + s + (yr && yr.checked ? '/' + String(tr.getAttribute('data-year')).slice(-2) : '');
  }
  [letter, digits, yr, next].forEach(function (el) { if (el) el.addEventListener('input', upd); });
});
</script>
