<?php
declare(strict_types=1);

/*
 * Origen: leer la web publicada (WordPress / WooCommerce / Divi).
 * Recorre la página del catálogo, cada familia y cada ficha de producto, y copia nombre,
 * referencia, medidas, descripción y fotos. Es la alternativa cuando no puedes exportar el CSV.
 */

/* ---------- Lectura de HTML ---------- */

function import_xpath(string $html): DOMXPath
{
    $d = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return new DOMXPath($d);
}

function import_text(?DOMNode $n): string
{
    return $n ? trim((string)preg_replace('/\s+/u', ' ', $n->textContent)) : '';
}

function import_block_text(DOMNode $n): string
{
    $html = (string)$n->ownerDocument->saveHTML($n);
    $html = (string)preg_replace('#<(br|/p|/li|/div|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string)preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = (string)preg_replace('/\s*\n\s*/u', "\n", $t);
    return trim($t);
}

function import_links(DOMXPath $x, string $pageUrl, array $allowed): array
{
    $out = [];
    foreach ($x->query('//a[@href]') as $a) {
        $u = import_abs($a->getAttribute('href'), $pageUrl);
        if (!preg_match('#^https?://#i', $u)) {
            continue;
        }
        $host = strtolower((string)parse_url($u, PHP_URL_HOST));
        if (!in_array($host, $allowed, true)) {
            continue;
        }
        $u = preg_replace('/\?.*$/', '', $u);
        $out[strlen($u) > 8 ? rtrim($u, '/') : $u] = true;
    }
    return array_keys($out);
}

function import_is_category_link(string $u): bool
{
    $path = '/' . trim((string)parse_url($u, PHP_URL_PATH), '/');
    if (preg_match('#^/(catalogo|tienda|shop|productos?|products?|contacto|contact|blog|wp-.*|feed|xmlrpc\.php|carrito|cart|checkout|mi-cuenta|my-account|tienda|politica.*|aviso.*|terminos.*|.*cookies.*)$#i', $path) || $path === '/') {
        return false;
    }
    if (preg_match('#\.(jpe?g|png|gif|webp|pdf|zip|xml|css|js)$#i', $path)) {
        return false;
    }
    return substr_count(trim($path, '/'), '/') === 0;
}

/** Fotos dentro de un nodo (o de toda la página). Devuelve [original => [urls, preview]]. */
function import_img_candidates(DOMXPath $x, ?DOMNode $ctx, string $pageUrl, bool $onlyUploads = false): array
{
    $found = [];
    $add = function (string $raw) use (&$found, $pageUrl, $onlyUploads): void {
        $u = (string)preg_replace('/[?#].*$/', '', import_abs($raw, $pageUrl));
        if (!preg_match('#^https?://#i', $u)) {
            return;
        }
        $path = (string)parse_url($u, PHP_URL_PATH);
        if (!preg_match('#\.(?:jpe?g|png|webp|gif)$#i', $path)) {
            return;
        }
        if ($onlyUploads && !str_contains($path, '/wp-content/uploads/')) {
            return;
        }
        $base = basename($path);
        if (preg_match('#(^|[-_.])(logo|watermark|favicon|placeholder|sprite|icon)([-_.\d]|$)|^cropped-#i', $base)) {
            return;
        }
        $orig = import_original($u);
        $w = preg_match('/-(\d{2,4})x\d{2,4}\.\w+$/', $u, $m) ? (int)$m[1] : 99999;
        $found[$orig]['seen'][$u] = $w;
        $found[$orig]['preview'] ??= $u;
    };

    $rel = $ctx ? './/' : '//';
    foreach ($x->query($rel . '*[self::img or self::source or self::a]', $ctx) as $n) {
        foreach (['src', 'data-src', 'data-lazy-src', 'data-large_image', 'data-orig-file', 'data-full-url', 'data-zoom-image'] as $attr) {
            if ($n->hasAttribute($attr)) {
                $add($n->getAttribute($attr));
            }
        }
        foreach (['srcset', 'data-srcset', 'data-lazy-srcset'] as $attr) {
            if ($n->hasAttribute($attr)) {
                foreach (explode(',', $n->getAttribute($attr)) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $add(preg_split('/\s+/', $part)[0]);
                    }
                }
            }
        }
        if ($n->nodeName === 'a' && $n->hasAttribute('href')) {
            $add($n->getAttribute('href'));
        }
    }

    $out = [];
    foreach ($found as $orig => $f) {
        arsort($f['seen']);
        $urls = array_values(array_unique([$orig, ...array_keys($f['seen'])]));
        $out[$orig] = ['urls' => $urls, 'preview' => $f['preview']];
    }
    return $out;
}

/** Fotos de la ficha de un producto: solo la galería, sin «relacionados» ni cabecera/pie. */
function import_product_gallery(DOMXPath $x, string $pageUrl): array
{
    foreach (["//*[contains(@class,'woocommerce-product-gallery')]", "//*[contains(@class,'et_pb_wc_images')]", "//*[contains(@class,'product-images')]"] as $q) {
        $n = $x->query($q);
        if ($n && $n->length) {
            $imgs = import_img_candidates($x, $n->item(0), $pageUrl);
            if ($imgs) {
                return $imgs;
            }
        }
    }
    return import_page_images($x, $pageUrl, "//*[contains(@class,'related') or contains(@class,'upsells') or contains(@class,'cross-sells')]");
}

/** Fotos de una página entera (sin cabecera, pie, menús ni zonas indicadas). */
function import_page_images(DOMXPath $x, string $pageUrl, string $extraExclude = ''): array
{
    $all = import_img_candidates($x, null, $pageUrl, true);
    $q = '//header | //footer | //nav' . ($extraExclude !== '' ? ' | ' . $extraExclude : '');
    foreach ($x->query($q) as $node) {
        foreach (array_keys(import_img_candidates($x, $node, $pageUrl, true)) as $k) {
            unset($all[$k]);
        }
    }
    return $all;
}

function import_clean_name(string $title): string
{
    $t = (string)preg_replace('/\s*\bMedidas?\b.*$/iu', '', $title);
    $t = (string)preg_replace('/\s*\bRef\.?\s*[A-Za-z0-9\-]+\b\.?/iu', '', $t);
    $t = trim($t, " \t.,;:-");
    return $t !== '' ? $t : $title;
}

/** Lee una ficha de producto de WooCommerce / Divi. */
function import_parse_product(string $html, string $url): array
{
    $x = import_xpath($html);
    $title = '';
    foreach (["//*[contains(@class,'product_title')]", '//h1'] as $q) {
        $n = $x->query($q);
        if ($n && $n->length) {
            $title = import_text($n->item(0));
            if ($title !== '') {
                break;
            }
        }
    }
    if ($title === '') {
        $title = trim(explode('|', import_text($x->query('//title')->item(0)))[0]);
    }

    $desc = '';
    foreach ([
        "//*[contains(@class,'woocommerce-product-details__short-description')]",
        "//*[@id='tab-description']",
        "//*[contains(@class,'woocommerce-Tabs-panel--description')]",
        "//*[contains(@class,'et_pb_wc_description')]",
    ] as $q) {
        $n = $x->query($q);
        if ($n && $n->length) {
            $t = trim((string)preg_replace('/^Descripci[oó]n\s*/iu', '', import_block_text($n->item(0))));
            if ($t !== '' && mb_strtolower($t) !== mb_strtolower($title)) {
                $desc = $t;
                break;
            }
        }
    }

    $extras = [];
    $dims = '';
    $rows = $x->query("//table[contains(@class,'woocommerce-product-attributes') or contains(@class,'shop_attributes')]//tr");
    foreach ($rows ?: [] as $tr) {
        $label = import_text($x->query('.//th', $tr)->item(0));
        $val = import_text($x->query('.//td', $tr)->item(0));
        if ($label === '' || $val === '') {
            continue;
        }
        if ($dims === '' && preg_match('/dimensi|medida|tama[ñn]o/iu', $label)) {
            $dims = import_dims($val);
            if ($dims !== '') {
                continue;
            }
        }
        $extras[] = $label . ': ' . $val;
    }
    if ($dims === '') {
        $dims = import_dims($title);
    }
    $ref = preg_match('/\bRef\.?\s*([A-Za-z0-9\-]+)/iu', $title, $m) ? $m[1] : '';

    return [
        'title' => $title,
        'name' => import_clean_name($title),
        'ref' => $ref,
        'dims' => $dims,
        'desc' => trim($desc . ($extras ? "\n" . implode("\n", $extras) : '')),
        'images' => import_product_gallery($x, $url),
    ];
}

/* ---------- Emparejar con lo que ya hay en el panel ---------- */

function import_match_category(string $oldSlug, array $cats): ?array
{
    $s = (string)preg_replace('/(-\d+)+$/', '', $oldSlug);
    $want = $s;
    foreach ($cats as $c) {
        if ($c['slug'] === $want || $c['slug'] === $s) {
            return $c;
        }
    }
    foreach ($cats as $c) {
        if (str_starts_with($c['slug'], $want) || str_starts_with($want, $c['slug'])) {
            return $c;
        }
    }
    return null;
}

function import_find_product(array $products, string $name, string $ref): ?array
{
    $n = slugify($name);
    $best = null;
    $bestScore = 0;
    foreach ($products as $p) {
        $ps = slugify($p['name']);
        $score = $ps === $n ? 100 : (str_starts_with($n, $ps . '-') ? 80 : 0);
        if ($score === 0) {
            continue;
        }
        $pr = ltrim(strtolower($p['ref']), '0');
        $or = ltrim(strtolower($ref), '0');
        if ($pr !== '' && $or !== '') {
            $score += $pr === $or ? 10 : -50;
        }
        if ($score > $bestScore) {
            $best = $p;
            $bestScore = $score;
        }
    }
    return $bestScore >= 60 ? $best : null;
}

function import_tokens(string $slug): array
{
    $out = [];
    foreach (explode('-', $slug) as $t) {
        if ($t === '' || preg_match('/\d/', $t) || in_array($t, ['ref', 'mm', 'cm', 'x'], true)) {
            continue;
        }
        $out[] = strlen($t) > 3 ? rtrim($t, 's') : $t;
    }
    return array_values(array_unique($out));
}

/** Decide a qué artículo pertenece una foto de galería según su nombre de archivo («ref-08-silla-roja.jpg»…). */
function import_match_image_name(string $url, array $candidates): ?array
{
    $slug = slugify(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME));
    if (preg_match('/(?:^|-)ref-0*([a-z0-9]+)(?:-|$)/', $slug, $m)) {
        foreach ($candidates as $p) {
            if ($p['ref'] !== '' && strtolower(ltrim($p['ref'], '0')) === strtolower($m[1])) {
                return $p;
            }
        }
    }
    $ft = import_tokens($slug);
    if (!$ft) {
        return null;
    }
    $best = null;
    $bestKey = [0, 0];
    foreach ($candidates as $p) {
        $pt = import_tokens(slugify($p['name']));
        if (!$pt) {
            continue;
        }
        $matched = count(array_intersect($pt, $ft));
        $key = [$matched / count($pt), $matched];
        if ($matched > 0 && $key > $bestKey) {
            $best = $p;
            $bestKey = $key;
        }
    }
    return $bestKey[0] >= 0.5 ? $best : null;
}

/* ---------- Paso 1: analizar la web antigua ---------- */

function import_analyze(string $input, string $catalogPath = ''): array
{
    $base = trim($input);
    if (!preg_match('#^https?://#i', $base)) {
        $base = 'https://' . $base;
    }
    $pu = parse_url($base);
    if (!$pu || empty($pu['host'])) {
        throw new RuntimeException('La dirección no es válida.');
    }
    $origin = $pu['scheme'] . '://' . $pu['host'] . (isset($pu['port']) ? ':' . $pu['port'] : '');
    $allowed = import_hosts($origin);
    @set_time_limit(180);

    $pages = 0;
    $fetch = function (string $u) use ($allowed, &$pages): string {
        if (++$pages > 80) {
            throw new RuntimeException('Se han visitado demasiadas páginas; se detiene por seguridad.');
        }
        return import_http($u, $allowed, 4194304)[0];
    };

    // Página que enlaza a todas las familias: la indicada, o la primera que funcione de las habituales.
    $tryPaths = $catalogPath !== '' ? ['/' . trim($catalogPath, '/')] : ['/catalogo', '/tienda', '/shop', '/productos', '/products', '/'];
    $catPages = [];
    $catalogUrl = '';
    $lastErr = null;
    foreach ($tryPaths as $tp) {
        $catalogUrl = $origin . $tp;
        try {
            $cx = import_xpath($fetch($catalogUrl));
        } catch (RuntimeException $e) {
            $lastErr = $e;
            continue;
        }
        $catPages = array_values(array_filter(import_links($cx, $catalogUrl, $allowed), 'import_is_category_link'));
        // Enlaces de categoría de WooCommerce (/categoria-producto/x, /product-category/x)
        foreach (import_links($cx, $catalogUrl, $allowed) as $u) {
            if (preg_match('#/(categoria-producto|product-category)/[^/]+/?$#', (string)parse_url($u, PHP_URL_PATH))) {
                $catPages[] = $u;
            }
        }
        $catPages = array_values(array_unique($catPages));
        if ($catPages) {
            break;
        }
    }
    if (!$catPages && $lastErr && str_contains($lastErr->getMessage(), 'red privada')) {
        throw $lastErr;
    }
    if (!$catPages) {
        throw new RuntimeException('No he encontrado familias de producto en ' . $origin . '. Indica la página que enlaza a todas las familias (por ejemplo /tienda).');
    }

    $dbcats = db()->query('SELECT id, name, slug FROM categories WHERE parent_id IS NULL')->fetchAll();
    $products = db()->query('SELECT p.id, p.category_id, p.ref, p.name, p.dimensions, p.description,
        (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id) AS n_img FROM products p')->fetchAll();

    $items = [];
    $unassigned = [];
    $warnings = [];
    $seenPages = [];
    $imageHosts = [];

    $item = function (?array $prod, string $name, ?array $cat) use (&$items): string {
        $key = $prod ? 'p' . $prod['id'] : 'n' . slugify($name);
        $items[$key] ??= [
            'pid' => $prod ? (int)$prod['id'] : null,
            'cat_id' => $prod ? (int)$prod['category_id'] : ($cat ? (int)$cat['id'] : null),
            'cat_name' => $prod ? '' : ($cat['name'] ?? ''),
            'name' => $prod ? $prod['name'] : $name,
            'ref' => '', 'dims' => '', 'desc' => '',
            'cur_ref' => $prod ? $prod['ref'] : '', 'cur_dims' => $prod ? $prod['dimensions'] : '',
            'cur_desc' => $prod ? $prod['description'] : '',
            'have_images' => $prod ? (int)$prod['n_img'] : 0,
            'images' => [], 'pages' => [],
        ];
        return $key;
    };
    $mergeImages = function (string $key, array $imgs) use (&$items, &$imageHosts): void {
        $have = array_column($items[$key]['images'], 'key');
        foreach ($imgs as $orig => $im) {
            if (in_array($orig, $have, true) || count($items[$key]['images']) >= IMPORT_MAX_IMAGES_PER_ITEM) {
                continue;
            }
            $items[$key]['images'][] = ['key' => $orig, 'urls' => $im['urls'], 'preview' => $im['preview']];
            foreach ($im['urls'] as $u) {
                $imageHosts[strtolower((string)parse_url($u, PHP_URL_HOST))] = true;
            }
        }
    };

    foreach ($catPages as $catUrl) {
        try {
            $html = $fetch($catUrl);
        } catch (RuntimeException $e) {
            $warnings[] = 'No se pudo leer ' . $catUrl . ': ' . $e->getMessage();
            continue;
        }
        $px = import_xpath($html);
        $oldSlug = basename((string)parse_url($catUrl, PHP_URL_PATH));
        $cat = import_match_category($oldSlug, $dbcats);

        $productLinks = array_values(array_filter(
            import_links($px, $catUrl, $allowed),
            fn($u) => (bool)preg_match('#/(producto|product)/[^/]+#', (string)parse_url($u, PHP_URL_PATH))
        ));

        if ($productLinks) {
            foreach ($productLinks as $pl) {
                if (isset($seenPages[$pl])) {
                    continue;
                }
                $seenPages[$pl] = true;
                try {
                    $info = import_parse_product($fetch($pl), $pl);
                } catch (RuntimeException $e) {
                    $warnings[] = 'No se pudo leer ' . $pl . ': ' . $e->getMessage();
                    continue;
                }
                $prod = import_find_product($products, $info['name'], $info['ref']);
                $key = $item($prod, $info['name'], $cat);
                $it = &$items[$key];
                $it['pages'][] = $pl;
                foreach (['ref', 'dims', 'desc'] as $f) {
                    if ($it[$f] === '' && $info[$f] !== '') {
                        $it[$f] = $info[$f];
                    }
                }
                unset($it);
                $mergeImages($key, $info['images']);
            }
        } else {
            // Familia tipo galería (fotos sin fichas de producto): las fotos se asignan por el nombre del archivo
            $cands = $cat ? array_values(array_filter($products, fn($p) => (int)$p['category_id'] === (int)$cat['id'])) : [];
            foreach (import_page_images($px, $catUrl) as $orig => $im) {
                $prod = $cands ? import_match_image_name($orig, $cands) : null;
                if ($prod) {
                    $key = $item($prod, $prod['name'], $cat);
                    $items[$key]['pages'][] = $catUrl;
                    $mergeImages($key, [$orig => $im]);
                } else {
                    $unassigned[] = basename($orig);
                }
            }
        }
    }

    foreach ($items as &$it) {
        $it['pages'] = array_values(array_unique($it['pages']));
    }
    unset($it);

    $allowedAll = $allowed;
    foreach (array_keys($imageHosts) as $h) {
        if ($h !== '') {
            $allowedAll[] = $h;
        }
    }

    return [
        'origin' => $origin,
        'allowed' => array_values(array_unique($allowedAll)),
        'items' => array_values($items),
        'unassigned' => array_values(array_unique($unassigned)),
        'warnings' => $warnings,
        'created' => time(),
    ];
}

function wpweb_analyze(array $in): array
{
    $plan = import_analyze((string)$in['post']['base'], (string)($in['post']['path'] ?? ''));
    return $plan;
}

return [
    'id' => 'wordpress-web',
    'label' => 'Leer una web publicada (WordPress)',
    'order' => 30,
    'help' => 'Analiza las páginas de tu web actual y copia fotos, descripciones y medidas. Úsalo si no puedes exportar el CSV. Más lento y menos exacto.',
    'fields' => [
        ['name' => 'base', 'type' => 'url', 'label' => 'Dirección de la web', 'placeholder' => 'https://tu-web-antigua.com', 'required' => true],
        ['name' => 'path', 'type' => 'text', 'label' => 'Opcional: página que enlaza a todas las familias', 'placeholder' => '/tienda',
            'help' => 'Si no la indicas, se prueba con /catalogo, /tienda, /shop y la portada.'],
    ],
    'analyze' => 'wpweb_analyze',
];
