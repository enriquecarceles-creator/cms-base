<?php
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
$has = fn(string ...$k) => (bool)array_filter($k, fn($x) => str_contains($path, '/' . $x));
// Secciones y pestañas: [etiqueta, ruta, claves que la marcan, solo admin]
$sections = [
  'articulos' => ['Artículos', '/admin', [
      ['Listado', '/admin', null, false],
      [term('families'), '/admin/categorias', ['categorias', 'portada'], false],
      ['Fotos', '/admin/fotos', ['fotos'], false],
  ]],
  'importar' => ['Importar', '/admin/importar', [
      ['Importar artículos', '/admin/importar', ['importar'], false],
      ['Fotos por FTP', '/admin/ftp', ['ftp'], false],
  ]],
  'web' => ['Web', '/admin/empresa', [
      ['Empresa', '/admin/empresa', ['empresa'], true],
      ['Configuración', '/admin/configuracion', ['configuracion'], true],
      ['Campos', '/admin/campos', ['campos'], true],
      ...(feature('slider') ? [['Slider', '/admin/slider', ['slider'], true]] : []),
      ...(feature('reps') ? [['Representantes', '/admin/representantes', ['representantes'], true]] : []),
  ]],
  'usuarios' => [is_admin() ? 'Usuarios' : 'Mi cuenta', is_admin() ? '/admin/usuarios' : '/admin/clave', [
      ['Usuarios', '/admin/usuarios', ['usuarios'], true],
      ['Mi cuenta', '/admin/clave', ['clave'], false],
  ]],
];
$cur = 'articulos';
if ($has('importar', 'ftp')) { $cur = 'importar'; }
elseif ($has('slider', 'representantes', 'empresa', 'configuracion', 'campos')) { $cur = 'web'; }
elseif ($has('usuarios', 'clave')) { $cur = 'usuarios'; }
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
<body>
<header class="top">
  <div class="inner">
    <a class="logo" href="<?= e(url('/admin')) ?>"><?= e(site_name()) ?> <span>panel</span></a>
    <nav class="main" aria-label="Secciones">
      <?php foreach ($sections as $key => [$label, $href, $tabs]): ?>
        <?php if (!is_admin() && !array_filter($tabs, fn($t) => !$t[3])) { continue; } ?>
        <?php if (!is_admin() && $key === 'web') { continue; } ?>
        <a href="<?= e(url($href)) ?>"<?= $cur === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <span class="right">
      <a href="<?= e(url('/')) ?>" target="_blank" rel="noopener">Ver web ↗</a>
      <form method="post" action="<?= e(url('/admin/logout')) ?>" class="inline"><?= csrf_field() ?><button class="link" type="submit">Salir</button></form>
    </span>
  </div>
</header>
<nav class="tabs" aria-label="<?= e($sections[$cur][0]) ?>">
  <div class="inner">
    <?php foreach ($sections[$cur][2] as [$tl, $th, $keys, $adm]): ?>
      <?php if ($adm && !is_admin()) { continue; } ?>
      <?php
        if ($keys === null) { // Listado: /admin, /admin/articulos, /admin/articulo/...
            $on = (bool)preg_match('#/admin(/articulos?(/.*)?)?/?$#', $path);
        } else { $on = $has(...$keys); }
      ?>
      <a href="<?= e(url($th)) ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= e($tl) ?></a>
    <?php endforeach; ?>
  </div>
</nav>
<main class="page">
  <?php if (is_file(ROOT . '/instalar.php')): ?>
    <p class="flash err" role="alert">⚠️ Borra el archivo <code>instalar.php</code> de la carpeta de la web (por FTP). Ya no hace falta.</p>
  <?php endif; ?>
  <?php foreach ($flashes as [$type, $msg]): ?>
    <p class="flash <?= $type === 'err' ? 'err' : 'ok' ?>" role="status"><?= e($msg) ?></p>
  <?php endforeach; ?>
  <?= $content ?>
</main>
</body>
</html>
