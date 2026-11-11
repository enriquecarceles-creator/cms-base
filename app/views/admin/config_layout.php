<?php
/*
 * Marco de Configuración: submenú propio a la izquierda y la pantalla a la derecha, dentro del esqueleto normal del panel.
 * Lo usan las pantallas que pasan 'cfg' => <clave> a admin_render() (ver admin_cfg_nav()). En el móvil el submenú queda encima.
 * Variables: las de la pantalla + $content (ya pintado) y $cfg (clave de la entrada activa).
 */
$cfgNav = admin_cfg_nav();
$cfgActive = (string)($cfg ?? '');
ob_start();
?>
<div class="cfg">
  <nav class="cfg-nav" aria-label="Secciones de Configuración">
    <?php foreach ($cfgNav as $group => $items): ?>
      <h3><?= e($group) ?></h3>
      <?php foreach ($items as [$key, $label, $href]): ?>
        <a href="<?= e(url($href)) ?>"<?= $key === $cfgActive ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="cfg-main"><?= $content ?></div>
</div>
<?php
$content = ob_get_clean();
require ROOT . '/app/views/admin/layout.php';
