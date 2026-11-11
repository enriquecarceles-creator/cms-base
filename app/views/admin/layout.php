<?php
/*
 * Esqueleto del panel: cabecera (marca, buscador rápido, «Ver web», menú de usuario),
 * menú lateral (admin_menu(): respeta roles y módulos) y migas de pan.
 * En el móvil el menú se pliega con una casilla + <label> (sin JavaScript).
 * Variables opcionales de cada pantalla: $crumb (último paso de las migas) y $back (filtros de la lista).
 */
$seg = admin_segment();
$menu = admin_menu();
$me = current_user();

// Página actual: [clave de sección, índice de página]. Primero la dirección completa
// («importar/carpetas»), después solo el primer tramo («importar», «articulo»…).
$curSec = null;
$curPage = null;
$two = implode('/', array_slice(explode('/', admin_segment(true)), 0, 2));
foreach ([$two, $seg] as $want) {
    foreach ($menu as $sk => [$sl, $si, $pages]) {
        foreach ($pages as $pi => $pg) {
            if (in_array($want, $pg[2], true)) {
                $curSec = $sk;
                $curPage = $pi;
                break 3;
            }
        }
    }
}

// Migas de pan: Inicio › Sección › Página › paso propio de la pantalla
$crumbs = [];
if ($seg !== '') {
    $crumbs[] = ['Inicio', '/admin'];
    if ($curSec !== null) {
        [$sl, , $pages] = $menu[$curSec];
        if (!(count($pages) === 1 && $pages[0][0] === $sl)) { // «Inicio» y «Clientes» son enlaces sueltos; el resto, grupos
            $crumbs[] = [$sl, $pages[0][1]];
        }
        $pg = $pages[$curPage];
        $href = $pg[1] === '/admin/articulos' && isset($back) && is_array($back) ? admin_list_url($back) : $pg[1];
        $crumbs[] = [$pg[0], $href];
    } elseif ($seg === 'clave') {
        $crumbs[] = ['Mi cuenta', '/admin/clave'];
    }
    if (!empty($crumb)) {
        $crumbs[] = [(string)$crumb, null];
    }
    $crumbs[count($crumbs) - 1][1] = null; // el último paso es la página actual
}
$logo = brand_logo();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<?php if (!empty($refresh)): ?><meta http-equiv="refresh" content="1"><?php endif; ?>
<title><?= e($title ?? 'Panel') ?></title>
<link rel="stylesheet" href="<?= e(asset('admin.css')) ?>">
</head>
<body class="adm">
<a class="skip" href="#contenido">Saltar al contenido</a>
<input type="checkbox" id="nav-t" class="nav-t" aria-label="Mostrar u ocultar el menú">
<header class="bar">
  <label for="nav-t" class="nav-btn" title="Menú"><?= admin_icon('menu') ?><span>Menú</span></label>
  <a class="brand" href="<?= e(url('/admin')) ?>">
    <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" height="28"><?php else: ?><span class="brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(site_name(), 0, 1))) ?></span><?php endif; ?>
    <span class="brand-n"><?= e(site_name()) ?></span><span class="brand-p">panel</span>
  </a>
  <?php if ($me['role'] !== 'erp'): ?><form class="qsearch" method="get" action="<?= e(url('/admin/articulos')) ?>" role="search">
    <label for="qs" class="sr-only">Buscar artículos</label>
    <input id="qs" type="search" name="q" placeholder="Buscar artículo por nombre o referencia" value="<?= e($seg === 'articulos' ? (string)($_GET['q'] ?? '') : '') ?>">
    <button type="submit" title="Buscar"><?= admin_icon('search') ?><span class="sr-only">Buscar</span></button>
  </form><?php endif; ?>
  <a class="bar-link" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= admin_icon('external') ?><span>Ver web</span></a>
  <details class="umenu">
    <summary><?= admin_icon('user') ?><span><?= e($me['username']) ?></span></summary>
    <div class="umenu-box">
      <p class="muted"><?= e(['admin' => 'Administrador', 'erp' => 'ERP (clientes y ventas)'][$me['role']] ?? 'Gestión de catálogo') ?></p>
      <a href="<?= e(url('/admin/clave')) ?>">Mi cuenta</a>
      <form method="post" action="<?= e(url('/admin/logout')) ?>"><?= csrf_field() ?><button class="link" type="submit">Salir</button></form>
    </div>
  </details>
</header>
<div class="shell">
  <nav class="side" aria-label="Menú del panel">
    <?php foreach ($menu as $sk => [$sl, $icon, $pages]): ?>
      <?php if (count($pages) === 1 && $pages[0][0] === $sl): $pg = $pages[0]; ?>
        <a class="side-top" href="<?= e(url($pg[1])) ?>"<?= $curSec === $sk ? ' aria-current="page"' : '' ?>><?= admin_icon($icon) ?><span><?= e($sl) ?></span></a>
      <?php else: ?>
        <details class="side-group<?= $curSec === $sk ? ' on' : '' ?>"<?= $curSec === $sk ? ' open' : '' ?>>
          <summary class="side-h"><?= admin_icon($icon) ?><span><?= e($sl) ?></span><?= admin_icon('chevron') ?></summary>
          <ul>
            <?php foreach ($pages as $pi => $pg): ?>
              <li><a href="<?= e(url($pg[1])) ?>"<?= $curSec === $sk && $curPage === $pi ? ' aria-current="page"' : '' ?>><?= e($pg[0]) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </details>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>
  <main class="page" id="contenido">
    <?php if ($crumbs): ?>
      <nav class="crumbs" aria-label="Migas de pan"><ol>
        <?php foreach ($crumbs as [$cl, $ch]): ?>
          <li><?php if ($ch !== null): ?><a href="<?= e(url($ch)) ?>"><?= e($cl) ?></a><?php else: ?><span aria-current="page"><?= e($cl) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
      </ol></nav>
    <?php endif; ?>
    <?php if (is_file(ROOT . '/instalar.php') && !is_local_request()): ?>
      <p class="flash err" role="alert">⚠️ Borra el archivo <code>instalar.php</code> de la carpeta de la web (por FTP). Ya no hace falta.</p>
    <?php endif; ?>
    <?php foreach ($flashes as [$type, $msg]): ?>
      <p class="flash <?= $type === 'err' ? 'err' : 'ok' ?>" role="status"><?= e($msg) ?></p>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
</div>
</body>
</html>
