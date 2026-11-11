<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/pages.php';

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if (BASE !== '' && str_starts_with($path, BASE)) {
    $path = substr($path, strlen(BASE));
}
$segs = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
$first = $segs[0] ?? '';
// Dirección del panel configurable (data/panel.txt). Con otra dirección, /admin da «no encontrada» como cualquier
// dirección que no existe (sin redirigir) y el panel vive en la nueva, que por dentro sigue siendo «admin».
$ap = admin_path();
if ($ap !== 'admin') {
    if ($first === 'admin') {
        $first = '#';
    } elseif ($first === $ap) {
        $first = 'admin';
        $segs[0] = 'admin';
    }
}

try {
    // Web recién subida: sin administrador todavía, se abre el asistente de instalación.
    if ($first !== 'admin' && is_file(__DIR__ . '/instalar.php') && !is_installed()) {
        header('Location: ' . url('/instalar.php'));
        exit;
    }
    switch ($first) {
        case '':
            page_home();
            break;
        case 'catalogo':
            page_catalog($segs[1] ?? null);
            break;
        case 'producto':
            page_product($segs[1] ?? '');
            break;
        case 'contacto':
            page_contact();
            break;
        case 'sitemap.xml':
            page_sitemap();
            break;
        case 'robots.txt':
            header('Content-Type: text/plain; charset=utf-8');
            if (setting('noindex') === '1') {
                echo "User-agent: *\nDisallow: /\n";
            } else {
                // Con una dirección de panel propia no se menciona (sería hacerla pública)
                echo "User-agent: *\n" . ($ap === 'admin' ? 'Disallow: ' . url('/admin') . "\n" : "Disallow:\n") . "Sitemap: " . site_origin() . url('/sitemap.xml') . "\n";
            }
            break;
        case 'admin':
            if (($segs[1] ?? '') === 'instalar.php') {
                if (is_file(__DIR__ . '/instalar.php')) {
                    require __DIR__ . '/instalar.php';
                } else {
                    not_found();
                }
                break;
            }
            require __DIR__ . '/app/admin.php';
            admin_dispatch($segs);
            break;
        default:
            // Páginas propias (Web → Páginas): /<dirección>
            if (count($segs) !== 1 || !page_custom($segs[0])) {
                not_found();
            }
    }
} catch (Throwable $ex) {
    error_log((string)$ex);
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font:16px system-ui;padding:2rem">Ha ocurrido un error. Inténtalo de nuevo en unos minutos.</p>';
}
