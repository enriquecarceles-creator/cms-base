<?php
declare(strict_types=1);

/** Uniones SQL para sacar la familia (y subfamilia) de cada artículo. */
const PCAT_JOIN = 'JOIN categories c ON c.id = p.category_id LEFT JOIN categories pc ON pc.id = c.parent_id';
const PCAT_FIELDS = "CASE WHEN pc.id IS NULL THEN c.name ELSE pc.name || ' › ' || c.name END AS cat_name,
    c.slug AS cat_slug, c.parent_id AS cat_parent_id, pc.name AS parent_name, pc.slug AS parent_slug,
    COALESCE(pc.name, c.name) AS family_name, COALESCE(pc.slug, c.slug) AS family_slug";
const PCAT_ORDER = 'COALESCE(pc.sort, c.sort), COALESCE(pc.name, c.name), CASE WHEN pc.id IS NULL THEN 0 ELSE 1 END, c.sort, c.name, p.sort, p.id';

/** Familias de un nivel (raíz si $parent es null), con foto de portada y nº de artículos (incluidas sus subfamilias). */
function categories_with_cover(?int $parent = null): array
{
    $ids = '(SELECT id FROM categories WHERE id = c.id OR parent_id = c.id)';
    $st = db()->prepare(
        "SELECT c.*,
            COALESCE(NULLIF(c.cover, ''),
              (SELECT image FROM products p WHERE p.category_id IN $ids AND p.visible = 1
                AND p.image IS NOT NULL AND p.image <> '' ORDER BY p.sort, p.id LIMIT 1)) AS cover,
            (SELECT COUNT(*) FROM products p WHERE p.category_id IN $ids AND p.visible = 1) AS n,
            (SELECT ref FROM products p WHERE p.category_id IN $ids AND p.visible = 1 ORDER BY p.sort, p.id LIMIT 1) AS first_ref,
            (SELECT dimensions FROM products p WHERE p.category_id IN $ids AND p.visible = 1 ORDER BY p.sort, p.id LIMIT 1) AS first_dims
         FROM categories c WHERE " . ($parent === null ? 'c.parent_id IS NULL' : 'c.parent_id = ?') . ' ORDER BY c.sort, c.name'
    );
    $st->execute($parent === null ? [] : [$parent]);
    return $st->fetchAll();
}

function page_home(): void
{
    render('home', [
        'slides' => db()->query('SELECT * FROM slides WHERE visible = 1 ORDER BY sort, id')->fetchAll(),
        'cats' => categories_with_cover(),
        'total' => (int)db()->query('SELECT COUNT(*) FROM products WHERE visible = 1')->fetchColumn(),
        'nreps' => (int)db()->query('SELECT COUNT(*) FROM reps')->fetchColumn(),
        'title' => site_name() . (setting('tagline') !== '' ? ' · ' . setting('tagline') : ''),
        'desc' => setting('home_desc') !== '' ? setting('home_desc') : mb_substr(trim(setting('home_text') . ' ' . setting('home_lead')), 0, 155),
    ]);
}

function page_catalog(?string $slug): void
{
    if ($slug === 'completo' && feature('pdf')) {
        page_print();
        return;
    }
    $cats = categories_with_cover();
    $current = null;
    $parent = null;
    $subs = [];
    if ($slug !== null) {
        $st = db()->prepare('SELECT * FROM categories WHERE slug = ?');
        $st->execute([$slug]);
        $current = $st->fetch() ?: null;
        if (!$current) {
            not_found();
            return;
        }
        if ($current['parent_id']) {
            $st = db()->prepare('SELECT * FROM categories WHERE id = ?');
            $st->execute([$current['parent_id']]);
            $parent = $st->fetch() ?: null;
        } else {
            $subs = categories_with_cover((int)$current['id']);
        }
    }
    $q = trim((string)($_GET['q'] ?? ''));
    $sql = 'SELECT p.*, ' . PCAT_FIELDS . ' FROM products p ' . PCAT_JOIN . ' WHERE p.visible = 1';
    $args = [];
    if ($current) {
        if ($subs && $q === '') {
            // Familia con subfamilias: se muestran las subfamilias y solo los artículos sueltos de la familia.
            $sql .= ' AND p.category_id = ?';
        } elseif ($subs) {
            $sql .= ' AND (p.category_id = ? OR c.parent_id = ?)';
            $args[] = $current['id'];
        } else {
            $sql .= ' AND p.category_id = ?';
        }
        $args[] = $current['id'];
    }
    if ($q !== '') {
        $sql .= ' AND (p.name LIKE ? OR p.ref LIKE ? OR p.description LIKE ? OR p.dimensions LIKE ?
            OR p.id IN (SELECT pf.product_id FROM product_fields pf JOIN custom_fields cf ON cf.id = pf.field_id WHERE cf.is_public = 1 AND pf.value LIKE ?))';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like, $like);
    }
    $sql .= ' ORDER BY ' . PCAT_ORDER;
    $st = db()->prepare($sql);
    $st->execute($args);
    $groups = [];
    foreach ($st->fetchAll() as $p) {
        $groups[$p['family_slug']]['name'] = $p['family_name'];
        $groups[$p['family_slug']]['items'][] = $p;
    }
    $title = ($current ? ($parent ? $parent['name'] . ' · ' : '') . $current['name'] . ' · ' : '') . 'Catálogo · ' . site_name();
    render('catalog', [
        'cats' => $cats, 'current' => $current, 'parent' => $parent, 'subs' => $q === '' ? $subs : [],
        'groups' => $groups, 'q' => $q,
        'title' => $title,
        'desc' => 'Catálogo de ' . ($current ? mb_strtolower($current['name']) : 'productos') . ' de ' . site_name() . '.',
        'noindex' => $q !== '',
    ]);
}

function page_product(string $slug): void
{
    $st = db()->prepare('SELECT p.*, ' . PCAT_FIELDS . ' FROM products p ' . PCAT_JOIN . ' WHERE p.slug = ? AND p.visible = 1');
    $st->execute([$slug]);
    $p = $st->fetch();
    if (!$p) {
        not_found();
        return;
    }
    $famId = $p['cat_parent_id'] ?: $p['category_id'];
    $rel = db()->prepare('SELECT * FROM products WHERE (category_id = ? OR category_id IN (SELECT id FROM categories WHERE parent_id = ?))
        AND visible = 1 AND id <> ? ORDER BY CASE WHEN category_id = ? THEN 0 ELSE 1 END, sort, id LIMIT 4');
    $rel->execute([$famId, $famId, $p['id'], $p['category_id']]);
    $desc = $p['description'] !== ''
        ? mb_substr(preg_replace('/\s+/', ' ', $p['description']), 0, 155)
        : $p['name'] . ' · ' . $p['cat_name'] . ' de ' . site_name() . '.';
    render('product', [
        'p' => $p, 'related' => $rel->fetchAll(), 'gallery' => gallery_for((int)$p['id']),
        'title' => $p['name'] . ($p['ref'] !== '' ? ' Ref. ' . $p['ref'] : '') . ' · ' . site_name(),
        'desc' => $desc,
        'ogImage' => $p['image'] ? site_origin() . img_url($p['image']) : null,
        'hasReps' => (int)db()->query('SELECT COUNT(*) FROM reps')->fetchColumn() > 0,
    ]);
}

function page_contact(): void
{
    $groups = [];
    foreach ((feature('reps') ? db()->query('SELECT * FROM reps ORDER BY name, zone')->fetchAll() : []) as $r) {
        $key = $r['name'] . '|' . $r['phone'] . '|' . $r['address'];
        $groups[$key] ??= ['name' => $r['name'], 'phone' => $r['phone'], 'address' => $r['address'], 'zones' => []];
        $groups[$key]['zones'][] = $r['zone'];
    }
    foreach ($groups as &$g) {
        sort($g['zones'], SORT_LOCALE_STRING);
    }
    unset($g);
    usort($groups, fn($a, $b) => strcmp($a['zones'][0], $b['zones'][0]));
    render('contact', [
        'reps' => $groups,
        'title' => 'Contacto' . ($groups ? ' y representantes' : '') . ' · ' . site_name(),
        'desc' => 'Datos de contacto de ' . site_name() . ($groups ? ' y representantes por zonas' : '') . '.',
    ]);
}

function page_print(): void
{
    $cats = db()->query('SELECT id, name, slug, parent_id FROM categories ORDER BY sort, name')->fetchAll();
    $tree = [];
    foreach ($cats as $c) {
        if (!$c['parent_id']) {
            $tree[(int)$c['id']] = ['c' => $c, 'kids' => []];
        }
    }
    foreach ($cats as $c) {
        if ($c['parent_id'] && isset($tree[(int)$c['parent_id']])) {
            $tree[(int)$c['parent_id']]['kids'][] = $c;
        }
    }
    $filtered = isset($_GET['go']);
    $sel = array_map('intval', (array)($_GET['f'] ?? []));
    $opt = [
        'ref' => !$filtered || !empty($_GET['ref']),
        'dims' => !$filtered || !empty($_GET['dims']),
        'cover' => !$filtered || !empty($_GET['cover']),
    ];
    $cols = (int)($_GET['cols'] ?? 3);
    $cols = in_array($cols, [2, 3, 4], true) ? $cols : 3;
    $rows = db()->query('SELECT p.*, ' . PCAT_FIELDS . ', c.id AS cid FROM products p ' . PCAT_JOIN . ' WHERE p.visible = 1 ORDER BY ' . PCAT_ORDER)->fetchAll();
    $groups = [];
    $total = 0;
    foreach ($rows as $p) {
        if ($filtered && $sel && !in_array((int)$p['cid'], $sel, true)) {
            continue;
        }
        $fs = $p['family_slug'];
        $groups[$fs]['name'] = $p['family_name'];
        $groups[$fs]['subs'][$p['cat_slug']]['name'] = $p['parent_name'] !== null ? $p['cat_name'] : '';
        $groups[$fs]['subs'][$p['cat_slug']]['items'][] = $p;
        $total++;
    }
    render('print', [
        'groups' => $groups, 'tree' => $tree, 'sel' => $sel, 'opt' => $opt, 'cols' => $cols, 'total' => $total,
        'filtered' => $filtered, 'title' => 'Catálogo en PDF · ' . site_name(), 'noindex' => true,
    ], null);
}

function page_sitemap(): void
{
    $o = site_origin();
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    $urls = [url('/'), url('/catalogo'), url('/contacto')];
    foreach (db()->query('SELECT slug FROM categories')->fetchAll() as $c) {
        $urls[] = url('/catalogo/' . $c['slug']);
    }
    foreach (db()->query('SELECT slug FROM products WHERE visible = 1')->fetchAll() as $p) {
        $urls[] = url('/producto/' . $p['slug']);
    }
    foreach ($urls as $u) {
        echo '<url><loc>' . e($o . $u) . '</loc></url>';
    }
    echo '</urlset>';
}
