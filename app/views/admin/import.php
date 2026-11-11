<div class="head"><h1>Importar artículos</h1></div>

<?php $status = $job['status'] ?? ''; ?>

<?php if (!$job): ?>
  <p class="hint">Trae artículos, familias, textos y fotos desde otra web o aplicación. Primero se <strong>analiza</strong> y verás un resumen para elegir qué importar.
  Nada se sobrescribe: solo se crean artículos nuevos, se añaden fotos y se rellenan los campos que estén vacíos. Se puede repetir sin duplicar.
  Para poner al día precios y stock desde un programa de gestión, usa «Base de datos» y elige «Actualizar precios y stock» en el paso de columnas.</p>
  <?php if ($ftpZip): ?><p class="flash ok">Hay un ZIP de fotos subido por FTP: <code><?= e(basename($ftpZip)) ?></code>. Se usará si no subes otro.</p><?php endif; ?>
  <?php foreach ($sources as $id => $src): ?>
    <form method="post" enctype="multipart/form-data" class="form imp-src">
      <?= csrf_field() ?>
      <input type="hidden" name="source" value="<?= e($id) ?>">
      <h2 style="margin:0"><?= e($src['label']) ?><?= !empty($src['recommended']) ? ' <small class="muted">· recomendado</small>' : '' ?></h2>
      <?php if ($src['help'] !== ''): ?><small><?= e($src['help']) ?></small><?php endif; ?>
      <?php if (!empty($src['template'])): ?><small><a href="<?= e(url('/admin/importar?plantilla=' . rawurlencode($id))) ?>">Descargar plantilla de ejemplo (CSV)</a></small><?php endif; ?>
      <?php foreach ($src['fields'] as $f): $type = $f['type'] ?? 'text'; ?>
        <label><?= e($f['label']) ?>
          <?php if ($type === 'file'): ?>
            <input type="file" name="<?= e($f['name']) ?>"<?= !empty($f['accept']) ? ' accept="' . e($f['accept']) . '"' : '' ?><?= !empty($f['required']) ? ' required' : '' ?>>
          <?php elseif ($type === 'select'): ?>
            <select name="<?= e($f['name']) ?>"><?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= e($ov) ?>"><?= e($ol) ?></option><?php endforeach; ?></select>
          <?php else: ?>
            <input type="<?= $type === 'url' ? 'url' : 'text' ?>" name="<?= e($f['name']) ?>" placeholder="<?= e($f['placeholder'] ?? '') ?>"<?= !empty($f['required']) ? ' required' : '' ?>>
          <?php endif; ?>
          <?php if (!empty($f['help'])): ?><small><?= e($f['help']) ?></small><?php endif; ?>
        </label>
      <?php endforeach; ?>
      <div class="buttons"><button class="btn" name="action" value="analyze" type="submit">Analizar</button></div>
    </form>
  <?php endforeach; ?>

<?php elseif ($status === 'map'): $src = $sources[$job['source']] ?? null; ?>
  <?php foreach ($job['warnings'] ?? [] as $w): ?><p class="flash err"><?= e($w) ?></p><?php endforeach; ?>
  <?php if ($src && !empty($src['map_form'])): ?>
    <?= ($src['map_form'])($job) ?>
  <?php endif; ?>
  <form method="post" class="inline"><?= csrf_field() ?><button class="btn ghost" name="action" value="reset" type="submit">Descartar</button></form>

<?php elseif ($status === 'plan' && ($job['mode'] ?? '') === 'update'):
    $u = $job['upd']; $fa = fields_all(); $n = count($job['items']);
    $fmt = fn(int $fid, string $v) => $v === '' ? '—' : (isset($fa[$fid]) ? field_format($fa[$fid], $v) : $v);
?>
  <p class="hint">Actualizar desde <strong><?= e($job['origin']) ?></strong>. Cada artículo se busca por su referencia. Solo se cambian los campos de abajo; el nombre, la descripción, la familia y las fotos no se tocan. No se crea ni se borra ningún artículo.</p>
  <?php foreach ($job['warnings'] as $w): ?><p class="flash err"><?= e($w) ?></p><?php endforeach; ?>
  <ul class="summary">
    <li><b><?= $n ?></b> artículo<?= $n === 1 ? '' : 's' ?> cambia<?= $n === 1 ? '' : 'n' ?></li>
    <?php foreach ($u['fields'] as $fid): ?><li><?= e($fa[$fid]['name'] ?? ('Campo ' . $fid)) ?>: <b><?= (int)($u['counts'][$fid] ?? 0) ?></b> cambian</li><?php endforeach; ?>
    <?php if ($u['visible']): ?><li>Visibilidad (<?= e($u['visible_col']) ?>): <b><?= (int)$u['counts']['visible'] ?></b> cambian</li><?php endif; ?>
    <li><b><?= (int)$u['same'] ?></b> ya tienen esos valores</li>
    <li<?= $u['not_found'] ? ' class="bad"' : '' ?>><b><?= (int)$u['not_found'] ?></b> códigos de la base de datos no existen en la web (no se crean)</li>
    <li<?= $u['missing'] ? ' class="bad"' : '' ?>><b><?= (int)$u['missing'] ?></b> artículos de la web no están en la base de datos (no se tocan)</li>
  </ul>
  <?php if ($u['not_found']): ?><p class="muted"><b>Códigos que no están en la web<?= $u['not_found'] > 20 ? ' (primeros 20 de ' . (int)$u['not_found'] . ')' : '' ?>:</b> <?= e(implode(', ', $u['not_found_codes'])) ?></p><?php endif; ?>
  <?php if ($u['missing']): ?><p class="muted"><b>Referencias de la web que no están en la base de datos<?= $u['missing'] > 20 ? ' (primeras 20 de ' . (int)$u['missing'] . ')' : '' ?>:</b> <?= e(implode(', ', $u['missing_codes'])) ?></p><?php endif; ?>

  <?php if ($n): ?>
    <h2>Cambios<?= $n > 100 ? ' (100 de ' . $n . ')' : '' ?></h2>
    <div class="dbprev"><table>
      <tr><th>Ref.</th><th>Nombre</th><?php foreach ($u['fields'] as $fid): ?><th><?= e($fa[$fid]['name'] ?? '') ?></th><?php endforeach; ?><?php if ($u['visible']): ?><th>Visible</th><?php endif; ?></tr>
      <?php foreach (array_slice($job['items'], 0, 100) as $it): ?>
        <tr><td><?= e($it['ref']) ?></td><td><?= e($it['name']) ?></td>
          <?php foreach ($u['fields'] as $fid): ?><td><?= array_key_exists($fid, $it['fields']) ? e($fmt($fid, (string)$it['old'][$fid])) . ' → <b>' . e($fmt($fid, (string)$it['fields'][$fid])) . '</b>' : '<span class="muted">igual</span>' ?></td><?php endforeach; ?>
          <?php if ($u['visible']): ?><td><?= $it['visible'] === null ? '<span class="muted">igual</span>' : ($it['old_visible'] ? 'Sí' : 'No') . ' → <b>' . ($it['visible'] ? 'Sí' : 'No') . '</b>' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </table></div>
  <?php endif; ?>
  <form method="post" style="margin-top:14px">
    <?= csrf_field() ?><input type="hidden" name="all" value="1">
    <div class="buttons">
      <?php if ($n): ?><button class="btn" name="action" value="start" type="submit">Aplicar los cambios a <?= $n ?> artículo<?= $n === 1 ? '' : 's' ?></button><?php endif; ?>
      <button class="btn ghost" name="action" value="reset" type="submit"><?= $n ? 'Descartar' : 'Volver' ?></button>
    </div>
  </form>

<?php elseif ($status === 'plan'): ?>
  <p class="hint">Esto es lo que se ha encontrado en <strong><?= e($job['origin']) ?></strong><?= !empty($job['source_label']) ? ' (' . e($job['source_label']) . ')' : '' ?>. Desmarca lo que no quieras importar y pulsa <strong>Importar</strong>.</p>

  <?php foreach ($job['warnings'] as $w): ?><p class="flash <?= str_starts_with($w, 'Columnas reconocidas') ? 'ok' : 'err' ?>"><?= e($w) ?></p><?php endforeach; ?>

  <?php if (count($job['items']) > 250):
      $st = ['new' => 0, 'upd' => 0, 'skip' => 0, 'img' => 0, 'hidden' => 0];
      foreach ($job['items'] as $it) {
          if ($it['pid']) { $st['upd']++; } elseif ($it['cat_id'] || !empty($it['cat_path'])) { $st['new']++; } else { $st['skip']++; }
          $st['img'] += count($it['images']);
          if (isset($it['visible']) && !$it['visible']) { $st['hidden']++; }
      }
      $fams = [];
      foreach ($job['items'] as $it) { if (!$it['pid'] && !empty($it['cat_name'])) { $fams[$it['cat_name']] = ($fams[$it['cat_name']] ?? 0) + 1; } }
      ksort($fams, SORT_NATURAL | SORT_FLAG_CASE);
  ?>
    <ul class="summary">
      <li><b><?= $st['new'] ?></b> artículos nuevos<?= $st['hidden'] ? ' (' . $st['hidden'] . ' ocultos, como en el origen)' : '' ?></li>
      <li><b><?= $st['upd'] ?></b> ya existen (solo se rellenan sus huecos)</li>
      <li><b><?= $st['img'] ?></b> fotos</li>
      <?php if ($st['skip']): ?><li class="bad"><b><?= $st['skip'] ?></b> sin familia (no se importan)</li><?php endif; ?>
    </ul>
    <?php if ($fams): ?><p class="muted"><b>Familias:</b> <?= e(implode(' · ', array_map(fn($k, $v) => $k . ' (' . $v . ')', array_keys($fams), $fams))) ?></p><?php endif; ?>
    <h2>Muestra (100 de <?= count($job['items']) ?>)</h2>
    <div class="dbprev"><table>
      <tr><th>Nombre</th><th>Familia</th><th>Ref.</th><th>Medidas</th><?php foreach (fields_all() as $cf): ?><th><?= e($cf['name']) ?></th><?php endforeach; ?><th>Fotos</th><th>Descripción</th></tr>
      <?php foreach (array_slice($job['items'], 0, 100) as $it): ?>
        <tr<?= isset($it['visible']) && !$it['visible'] ? ' style="opacity:.55"' : '' ?>><td><?= e($it['name']) ?><?= $it['pid'] ? ' <i>(ya existe)</i>' : '' ?></td><td><?= e($it['cat_name']) ?></td><td><?= e($it['ref']) ?></td><td><?= e($it['dims']) ?></td><?php foreach (fields_all() as $cf): ?><td><?= isset($it['fields'][$cf['id']]) ? e(field_format($cf, (string)$it['fields'][$cf['id']])) : '' ?></td><?php endforeach; ?><td><?= count($it['images']) ?></td><td><?= e(mb_strimwidth(str_replace("\n", ' · ', $it['desc']), 0, 80, '…')) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
    <form method="post" style="margin-top:14px">
      <?= csrf_field() ?><input type="hidden" name="all" value="1">
      <label class="check"><input type="checkbox" name="texts" value="1" checked> En los artículos que ya existen, rellenar los campos vacíos</label>
      <label class="check"><input type="checkbox" name="append" value="1"> Añadir fotos aunque el artículo ya tenga fotos</label>
      <div class="buttons" style="margin-top:14px">
        <button class="btn" name="action" value="start" type="submit">Importar los <?= $st['new'] + $st['upd'] ?> artículos</button>
        <button class="btn ghost" name="action" value="reset" type="submit">Descartar</button>
      </div>
      <p class="muted">Es una importación grande: se hace por tandas y puede tardar. No cierres la página. Después puedes ocultar o borrar artículos desde el listado.</p>
    </form>
  <?php else: ?>
  <form method="post">
    <?= csrf_field() ?>
    <?php foreach ($job['items'] as $i => $it):
        $canImport = $it['pid'] || $it['cat_id'] || !empty($it['cat_path']);
        $hasTexts = $it['pid'] && ($it['ref'] !== '' || $it['dims'] !== '' || $it['desc'] !== '' || !empty($it['fields']));
        $skipPhotos = $it['pid'] && $it['have_images'] > 0;
    ?>
      <fieldset class="imp">
        <label class="check imp-title">
          <input type="checkbox" name="items[<?= $i ?>][on]" value="1"<?= $canImport ? ' checked' : ' disabled' ?>>
          <span><?= e($it['name']) ?>
            <?php if ($it['pid']): ?><em>· ya existe en el panel</em>
            <?php elseif ($it['cat_id'] || !empty($it['cat_path'])): ?><em>· artículo nuevo<?= $it['cat_name'] !== '' ? ' en «' . e($it['cat_name']) . '»' : '' ?><?= isset($it['visible']) && !$it['visible'] ? ' (oculto, como en la web antigua)' : '' ?></em>
            <?php else: ?><em>· no se encuentra su familia: no se puede importar</em><?php endif; ?>
          </span>
        </label>

        <?php if ($it['ref'] !== '' || $it['dims'] !== '' || $it['desc'] !== '' || !empty($it['fields'])): ?>
          <div class="imp-texts">
            <?php if ($it['ref'] !== ''): ?><div><b>Ref.:</b> <?= e($it['ref']) ?><?= $it['pid'] && $it['cur_ref'] !== '' ? ' <i>(ya tiene: ' . e($it['cur_ref']) . ')</i>' : '' ?></div><?php endif; ?>
            <?php if ($it['dims'] !== ''): ?><div><b>Medidas:</b> <?= e($it['dims']) ?><?= $it['pid'] && $it['cur_dims'] !== '' ? ' <i>(ya tiene: ' . e($it['cur_dims']) . ')</i>' : '' ?></div><?php endif; ?>
            <?php if ($it['desc'] !== ''): ?><div><b>Descripción:</b> <?= nl2br(e(mb_strimwidth($it['desc'], 0, 220, '…'))) ?><?= $it['pid'] && $it['cur_desc'] !== '' ? ' <i>(ya tiene)</i>' : '' ?></div><?php endif; ?>
            <?php foreach ($it['fields'] ?? [] as $fid => $fv): $cf = fields_all()[$fid] ?? null; if (!$cf) continue; ?><div><b><?= e($cf['name']) ?>:</b> <?= e(field_format($cf, (string)$fv)) ?></div><?php endforeach; ?>
            <?php if ($hasTexts): ?>
              <label class="check"><input type="checkbox" name="items[<?= $i ?>][texts]" value="1" checked> Rellenar los campos que estén vacíos con estos textos</label>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($it['images']): ?>
          <?php if ($skipPhotos): ?><p class="muted">Ya tiene <?= (int)$it['have_images'] ?> foto<?= $it['have_images'] === 1 ? '' : 's' ?>: se omiten estas, salvo que marques abajo «añadir aunque ya tengan fotos».</p><?php endif; ?>
          <div class="gal">
            <?php foreach ($it['images'] as $k => $im): ?>
              <label class="gitem">
                <img src="<?= e(!empty($im['file']) ? url('/admin/importar?foto=' . rawurlencode($im['file'])) : $im['preview']) ?>" alt="<?= e($im['label'] ?? basename((string)$im['key'])) ?>" title="<?= e($im['label'] ?? basename((string)$im['key'])) ?>" loading="lazy" width="120" height="120" referrerpolicy="no-referrer">
                <span class="check"><input type="checkbox" name="items[<?= $i ?>][img][]" value="<?= $k ?>" checked> Importar</span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="muted">No se han encontrado fotos.</p>
        <?php endif; ?>
      </fieldset>
    <?php endforeach; ?>

    <?php if ($job['unassigned']): ?>
      <p class="muted"><b>Fotos sin asignar a ningún artículo (no se importan):</b> <?= e(implode(', ', $job['unassigned'])) ?>. Puedes subirlas a mano desde cada artículo.</p>
    <?php endif; ?>

    <label class="check"><input type="checkbox" name="append" value="1"> Añadir fotos aunque el artículo ya tenga fotos</label>
    <div class="buttons" style="margin-top:14px">
      <button class="btn" name="action" value="start" type="submit">Importar lo marcado</button>
      <button class="btn ghost" name="action" value="reset" type="submit">Descartar</button>
    </div>
  </form>
  <?php endif; ?>

<?php else:
    $tot = $ok = $dup = $err = $pend = 0; $created = 0; $errs = []; $itDone = 0; $itTot = count($job['items']);
    foreach ($job['items'] as $it) {
        if (!empty($it['created'])) { $created++; }
        if (!empty($it['done'])) { $itDone++; }
        if (!empty($it['error'])) { $errs[] = [$it['name'], '', $it['error']]; }
        foreach ($it['images'] as $im) {
            $tot++;
            match ($im['state']) { 'ok' => $ok++, 'dup' => $dup++, 'err' => $err++, default => $pend++ };
            if ($im['state'] === 'err') { $errs[] = [$it['name'], $im['key'], $im['msg']]; }
        }
    }
    $done = $tot - $pend;
?>
  <?php if ($status === 'running'): ?>
    <p class="flash ok">Importando… esta página se actualiza sola. No la cierres hasta que termine.</p>
    <div class="bar-progress"><span style="width:<?= (int)round(($itDone + $done) * 100 / max(1, $itTot + $tot)) ?>%"></span></div>
    <p><?= $itDone ?> de <?= $itTot ?> artículos<?= $tot ? ' · ' . $done . ' de ' . $tot . ' fotos' : '' ?>.</p>
    <form method="get"><button class="btn small ghost" type="submit">Continuar ahora</button></form>
  <?php elseif (($job['mode'] ?? '') === 'update'):
      $upd = 0; foreach ($job['items'] as $it) { if (!empty($it['updated'])) { $upd++; } }
      $fa = fields_all(); $u = $job['upd'] ?? [];
  ?>
    <p class="flash ok">Actualización terminada.</p>
    <ul class="summary">
      <li><b><?= $upd ?></b> artículo<?= $upd === 1 ? '' : 's' ?> actualizado<?= $upd === 1 ? '' : 's' ?></li>
      <?php foreach ($u['fields'] ?? [] as $fid): ?><li><?= e($fa[$fid]['name'] ?? '') ?>: <?= (int)($u['counts'][$fid] ?? 0) ?></li><?php endforeach; ?>
      <?php if (!empty($u['visible'])): ?><li>Visibilidad: <?= (int)($u['counts']['visible'] ?? 0) ?></li><?php endif; ?>
      <?php if ($errs): ?><li class="bad"><b><?= count($errs) ?></b> con error</li><?php endif; ?>
    </ul>
    <?php if ($errs): ?><ul class="errlist"><?php foreach ($errs as [$name, , $msg]): ?><li><b><?= e($name) ?></b>: <?= e($msg) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <div class="buttons">
      <a class="btn" href="<?= e(url('/admin')) ?>">Ver artículos</a>
      <form method="post" class="inline"><?= csrf_field() ?><button class="btn ghost" name="action" value="reset" type="submit">Nuevo análisis</button></form>
    </div>
  <?php else: ?>
    <p class="flash ok">Importación terminada.</p>
    <ul class="summary">
      <li><b><?= $ok ?></b> foto<?= $ok === 1 ? '' : 's' ?> importada<?= $ok === 1 ? '' : 's' ?></li>
      <li><b><?= $created ?></b> artículo<?= $created === 1 ? '' : 's' ?> nuevo<?= $created === 1 ? '' : 's' ?></li>
      <?php if ($dup): ?><li><?= $dup ?> ya estaban importadas</li><?php endif; ?>
      <?php if ($err): ?><li class="bad"><b><?= $err ?></b> con error</li><?php endif; ?>
    </ul>
    <?php if ($errs): ?>
      <h2>Fotos que no se han podido importar</h2>
      <ul class="errlist">
        <?php foreach ($errs as [$name, $key, $msg]): ?>
          <li><b><?= e($name) ?></b>: <?= e(basename(str_replace('zip:', '', $key))) ?> — <?= e($msg) ?></li>
        <?php endforeach; ?>
      </ul>
      <p class="muted">Puedes subir esas fotos a mano desde cada artículo.</p>
    <?php endif; ?>
    <div class="buttons">
      <a class="btn" href="<?= e(url('/admin')) ?>">Ver artículos</a>
      <form method="post" class="inline"><?= csrf_field() ?><button class="btn ghost" name="action" value="reset" type="submit">Nuevo análisis</button></form>
    </div>
  <?php endif; ?>
<?php endif; ?>
