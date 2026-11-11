<?php
/*
 * Web → Menú: dos listas (menú de arriba y pie). Cada elemento: mostrar, nombre opcional y orden
 * (número o flechas ↑ ↓, que son botones del formulario y funcionan sin JavaScript).
 */
if (!empty($confirmReset)): ?>
<div class="head"><h1>Restaurar el menú</h1></div>
<div class="confirm">
  <p>Se va a <strong>restaurar el menú por defecto</strong>: arriba Inicio, Catálogo y Contacto; en el pie, las páginas visibles. Se pierden los nombres y el orden que hayas elegido.</p>
  <form method="post" action="<?= e(url('/admin/menu')) ?>" class="form">
    <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="confirm" value="1">
    <div class="buttons"><button class="btn" type="submit">Sí, restaurar</button><a class="btn ghost" href="<?= e(url('/admin/menu')) ?>">Cancelar</a></div>
  </form>
</div>
<?php return; endif; ?>
<div class="head"><h1>Menú</h1><a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Ver la web ↗</a></div>
<p class="hint">Qué enlaces salen en el menú de arriba y en el pie de la web, en qué orden y con qué nombre. Las páginas nuevas se crean en <a href="<?= e(url('/admin/paginas')) ?>">Páginas</a>.</p>

<form method="post" action="<?= e(url('/admin/menu')) ?>" class="form hp">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <?php /* Botón por defecto: Intro en un campo guarda; no mueve ningún elemento */ ?>
  <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Guardar</button>

  <?php foreach (MENU_PLACES as $place => $placeName): $rows = $menus[$place]; $last = count($rows) - 1;
      $onCount = count(array_filter($rows, fn($r) => $r['on'] && $r['visible'])); ?>
    <h2 id="m-<?= e($place) ?>"><?= e($placeName) ?></h2>
    <?php if ($place === 'top' && $onCount > 6): ?><p class="alert warn"><?= admin_icon('info') ?><span>Hay <?= (int)$onCount ?> elementos en el menú de arriba. Con más de 6, en el móvil se apilan en varias líneas.</span></p><?php endif; ?>
    <div class="tbl-wrap">
    <table class="tbl blocks">
      <thead><tr><th scope="col">Mostrar</th><th scope="col">Enlace</th><th scope="col">Nombre en el menú</th><th scope="col">Orden</th><th scope="col"><span class="sr-only">Mover</span></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): $k = $r['k']; $hidden = $r['page'] && !$r['visible']; ?>
        <tr<?= ($r['on'] && !$hidden) ? '' : ' class="off"' ?>>
          <td class="t-check"><input type="checkbox" id="on-<?= e($place . '-' . $k) ?>" name="on[<?= e($place) ?>][<?= e($k) ?>]" value="1"<?= $r['on'] ? ' checked' : '' ?>></td>
          <td>
            <label for="on-<?= e($place . '-' . $k) ?>"><strong><?= e($r['name']) ?></strong></label>
            <small class="muted"> — <?= e($r['page'] ? 'página ' . $r['path'] : ($k === 'home' ? 'portada' : $r['path'])) ?></small>
            <?php if ($hidden): ?><br><small class="review-flag">Página oculta: no saldrá en el menú hasta que la publiques</small><?php endif; ?>
          </td>
          <td><input name="label[<?= e($place) ?>][<?= e($k) ?>]" value="<?= e($r['label']) ?>" maxlength="40" placeholder="<?= e($r['name']) ?>" aria-label="Nombre de «<?= e($r['name']) ?>» en el menú"></td>
          <td><input class="blk-ord" type="number" name="ord[<?= e($place) ?>][<?= e($k) ?>]" value="<?= ($i + 1) * 10 ?>" step="1" aria-label="Orden de «<?= e($r['name']) ?>»"></td>
          <td class="blk-move">
            <button class="btn small ghost" type="submit" name="move" value="<?= e($place . ':' . $k . ':up') ?>"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Subir «<?= e($r['name']) ?>»" title="Subir">↑</button>
            <button class="btn small ghost" type="submit" name="move" value="<?= e($place . ':' . $k . ':down') ?>"<?= $i === $last ? ' disabled' : '' ?> aria-label="Bajar «<?= e($r['name']) ?>»" title="Bajar">↓</button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endforeach; ?>

  <div class="savebar">
    <button class="btn" type="submit">Guardar</button>
    <a class="btn ghost" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Ver la web ↗</a>
    <button class="btn ghost" type="submit" name="action" value="reset" formnovalidate>Restaurar el menú por defecto…</button>
  </div>
</form>
