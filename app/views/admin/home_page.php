<?php
/*
 * Web → Página de inicio: bloques de la portada (activar y ordenar con número o flechas, sin JavaScript)
 * y los ajustes de cada bloque. Las flechas son botones del mismo formulario: guardan todo y mueven el bloque.
 */
$byId = [];
foreach ($blocks as $b) {
    $byId[$b['id']] = $b;
}
$text = function (string $k, string $tag = 'input') use ($v): string {
    [$label, $def] = HOME_TEXTS[$k];
    $ph = $def !== '' ? ' placeholder="' . e($def) . '"' : '';
    $field = $tag === 'textarea'
        ? '<textarea name="' . e($k) . '" rows="4"' . $ph . '>' . e($v[$k]) . '</textarea>'
        : '<input name="' . e($k) . '" value="' . e($v[$k]) . '"' . $ph . ($k === 'home_desc' ? ' maxlength="160"' : '') . '>';
    return '<label>' . e($label) . ' ' . $field . ($def !== '' ? '<small>Vacío = «' . e($def) . '».</small>' : '') . '</label>';
};
$sec = function (string $id) use ($byId): string {
    $on = !empty($byId[$id]['on']);
    return '<legend>' . e(home_block_name($id)) . ' <small class="muted">· ' . ($on ? 'activo' : 'desactivado') . '</small></legend>';
};
$last = count($blocks) - 1;
?>
<div class="head"><h1>Página de inicio</h1><a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Ver la portada ↗</a></div>
<p class="hint">Qué sale en la portada y en qué orden. Los datos de la empresa (nombre, logotipo, teléfono…) están en <a href="<?= e(url('/admin/configuracion/empresa')) ?>">Empresa</a>.</p>

<form method="post" enctype="multipart/form-data" class="form hp">
  <?= csrf_field() ?>
  <?php /* Botón por defecto: al pulsar Intro en un campo se guarda, no se mueve ningún bloque */ ?>
  <button type="submit" class="sr-only" tabindex="-1" aria-hidden="true">Guardar</button>

  <h2 id="bloques">Bloques</h2>
  <p class="muted" style="margin:0">Marca los bloques que quieres en la portada y ordénalos: con los números (el más bajo sale primero) o con las flechas.</p>
  <div class="tbl-wrap">
  <table class="tbl blocks">
    <thead><tr><th scope="col">Activo</th><th scope="col">Bloque</th><th scope="col">Orden</th><th scope="col"><span class="sr-only">Mover</span></th></tr></thead>
    <tbody>
    <?php foreach ($blocks as $i => $b): $id = $b['id']; $name = home_block_name($id); ?>
      <tr<?= $b['on'] ? '' : ' class="off"' ?>>
        <td class="t-check"><input type="checkbox" id="on-<?= e($id) ?>" name="on[<?= e($id) ?>]" value="1"<?= $b['on'] ? ' checked' : '' ?>></td>
        <td>
          <label for="on-<?= e($id) ?>"><strong><?= e($name) ?></strong></label>
          <small class="muted"> — <?= e($id === 'families' ? 'Botones con ' . tg('las', 'los') . ' ' . term('families', true) . ' del catálogo.' : HOME_BLOCKS[$id][1]) ?></small>
          <?php if ($id === 'slider'): ?>
            <br><label class="check blk-opt"><input type="checkbox" name="slider_replace" value="1"<?= $b['replace'] ? ' checked' : '' ?>> <span>Si tiene imágenes, sustituye a la imagen de cabecera y a la bienvenida</span></label>
            <a class="blk-link" href="<?= e(url('/admin/slider')) ?>">Gestionar las imágenes del slider (<?= (int)$nSlides ?> visibles) →</a>
          <?php else: ?>
            <a class="blk-link" href="#b-<?= e($id) ?>">Ajustes ↓</a>
          <?php endif; ?>
        </td>
        <td><input class="blk-ord" type="number" name="ord[<?= e($id) ?>]" value="<?= ($i + 1) * 10 ?>" step="1" aria-label="Orden de «<?= e($name) ?>»"></td>
        <td class="blk-move">
          <button class="btn small ghost" type="submit" name="move" value="<?= e($id) ?>:up"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Subir «<?= e($name) ?>»" title="Subir">↑</button>
          <button class="btn small ghost" type="submit" name="move" value="<?= e($id) ?>:down"<?= $i === $last ? ' disabled' : '' ?> aria-label="Bajar «<?= e($name) ?>»" title="Bajar">↓</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <h2>Ajustes de cada bloque</h2>

  <fieldset id="b-hero"><?= $sec('hero') ?>
    <?php if ($v['hero_image'] !== ''): ?><img src="<?= e(img_url($v['hero_image'])) ?>" alt="" style="max-width:320px;max-height:140px;object-fit:cover;border-radius:8px"><label class="check"><input type="checkbox" name="hero_remove" value="1"> <span>Quitar la imagen actual</span></label><?php endif; ?>
    <label>Imagen <input type="file" name="hero" accept="image/*"></label>
    <small>Foto ancha, mejor oscura. Si va justo antes de la bienvenida, el texto se pone encima. Sin imagen, la bienvenida usa un fondo con tus fotos de <?= e(term('families', true)) ?>.</small>
  </fieldset>

  <fieldset id="b-welcome"><?= $sec('welcome') ?>
    <div class="two"><?= $text('home_hello') ?><?= $text('home_lead') ?></div>
    <small>Sale «<?= e(setting('home_hello', 'Bienvenido a')) ?> <?= e(site_name()) ?>», el lema de Empresa y los botones «Ver el catálogo» y «Contacto».</small>
  </fieldset>

  <fieldset id="b-about"><?= $sec('about') ?>
    <?= $text('home_title') ?><?= $text('home_text', 'textarea') ?>
  </fieldset>

  <fieldset id="b-cards"><?= $sec('cards') ?>
    <?php foreach ($cards as $c): ?>
      <div class="two">
        <label>Título <input name="card_t[]" value="<?= e($c[0]) ?>" maxlength="60"></label>
        <label>Descripción <input name="card_d[]" value="<?= e($c[1] ?? '') ?>" maxlength="120"></label>
      </div>
    <?php endforeach; ?>
    <small>Hasta cuatro. Si van justo después de «Quiénes somos», salen a su lado.</small>
  </fieldset>

  <fieldset id="b-families"><?= $sec('families') ?>
    <?= $text('home_chips_title') ?>
    <label class="check"><input type="radio" name="fam_mode" value="all"<?= $pick ? '' : ' checked' ?>> <span><?= e(tg('Todas las', 'Todos los')) ?> <?= e(term('families', true)) ?></span></label>
    <label class="check"><input type="radio" name="fam_mode" value="some"<?= $pick ? ' checked' : '' ?>> <span>Solo <?= e(tg('las', 'los')) ?> que marque:</span></label>
    <div class="fam-pick">
      <?php foreach ($roots as $r): ?><label class="check"><input type="checkbox" name="fam[]" value="<?= (int)$r['id'] ?>"<?= in_array((int)$r['id'], $pick, true) ? ' checked' : '' ?>> <span><?= e($r['name']) ?></span></label><?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset id="b-news"><?= $sec('news') ?>
    <div class="two"><?= $text('home_news_title') ?>
      <label>Cuántos artículos <input type="number" name="home_news_count" min="1" max="24" value="<?= e($v['home_news_count']) ?>"><small>Los últimos que se han añadido (visibles).</small></label></div>
  </fieldset>

  <fieldset id="b-cta"><?= $sec('cta') ?>
    <div class="two"><?= $text('cta_title') ?><?= $text('cta_text') ?></div>
  </fieldset>

  <fieldset id="b-seo"><legend>Google</legend>
    <?= $text('home_desc') ?>
  </fieldset>

  <div class="savebar">
    <button class="btn" type="submit">Guardar</button>
    <a class="btn ghost" href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Ver la portada ↗</a>
  </div>
</form>
