<?php
declare(strict_types=1);

/*
 * Origen: CSV de productos de WooCommerce (WordPress → Productos → Exportar), en español o en inglés.
 * Opcional: XML de medios (Herramientas → Exportar → Medios) para traer fotos adjuntas que el CSV no lista.
 */

/** Etiqueta de subcategoría tipo «Ref.01. Grande 104X120 mm» → ref, nombre y medidas. Si no sigue ese formato, se deja igual. */
function woo_parse_sub(string $label): array
{
    $ref = '';
    if (preg_match('/^\s*Ref\.?\s*([0-9A-Za-z]+)\.?\s*/iu', $label, $m)) {
        $ref = $m[1];
        $label = substr($label, strlen($m[0]));
    }
    $dims = import_dims($label);
    $name = $dims !== '' ? preg_replace('/\d+(?:[.,]\d+)?\s*[x×X]\s*\d+.*$/u', '', $label) : $label;
    $name = trim(preg_replace('/\s+/u', ' ', (string)$name), " .\t");
    return ['ref' => $ref, 'name' => $name, 'dims' => $dims];
}

/** Limpia el nombre: quita «Ref.XX» y «Medidas…» del título y arregla los nombres escritos TODO EN MAYÚSCULAS. */
function woo_clean_name(string $name): string
{
    $n = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $n = preg_replace('/\bMedidas?\.?\s*\d+.*$/iu', '', $n);
    $n = preg_replace('/\bRef\.?\s*\d*[A-Za-z]*\d*\.?(?=\s|$|\p{Lu})/u', '', $n);
    $n = trim((string)preg_replace('/\s+/u', ' ', (string)$n));
    $parts = array_values(array_filter(array_map(fn($p) => trim($p, " \t."), preg_split('/\.\s+/u', $n))));
    if (count($parts) > 1 && mb_stripos($parts[0], end($parts)) !== false) {
        array_pop($parts); // «Paragüero Apilable. Apilable»
    }
    $n = implode('. ', $parts);
    if ($n !== '' && $n === mb_strtoupper($n) && preg_match('/\p{L}{3}/u', $n)) {
        $n = import_csv_title_case(mb_strtolower($n));
    }
    return trim($n, " .\t");
}

/** Fotos de la biblioteca de medios (exportación XML de WordPress): [ID de artículo => [urls]]. */
function woo_xml_media(string $file): array
{
    $out = [];
    $reader = new XMLReader();
    if (!@$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
        throw new RuntimeException('No se pudo leer el archivo XML.');
    }
    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'item') {
            continue;
        }
        $node = @simplexml_load_string($reader->readOuterXml(), 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if (!$node) {
            continue;
        }
        $wp = $node->children('http://wordpress.org/export/1.2/');
        if ((string)$wp->post_type !== 'attachment') {
            continue;
        }
        $url = trim((string)$wp->attachment_url);
        $parent = (string)$wp->post_parent;
        if ($parent === '' || $parent === '0' || !preg_match('#^https?://.+\.(jpe?g|png|webp|gif)$#i', $url) || stripos($url, 'placeholder') !== false) {
            continue;
        }
        $out[$parent][] = $url;
    }
    $reader->close();
    return $out;
}

function woo_analyze(array $in): array
{
    $xmlMedia = $in['files']['xml'] ? woo_xml_media($in['files']['xml']) : [];
    [$head, $rows] = import_read_csv((string)$in['files']['csv'], 3000);
    $col = import_map_columns($head, [
        'id' => ['ID'],
        'name' => ['Nombre', 'Name'],
        'cats' => ['Categorías', 'Categories'],
        'images' => ['Imágenes', 'Images'],
        'desc' => ['Descripción', 'Description'],
        'short' => ['Descripción corta', 'Short description'],
        'sku' => ['SKU', 'Referencia'],
        'visibility' => ['Visibilidad en el catálogo', 'Visibility in catalog'],
        'published' => ['Publicado', 'Published'],
        'type' => ['Tipo', 'Type'],
        'length' => ['Longitud (cm)', 'Length (cm)'],
        'width' => ['Anchura (cm)', 'Width (cm)'],
        'height' => ['Altura (cm)', 'Height (cm)'],
        'price' => ['Precio normal', 'Regular price'],
        'sale' => ['Precio rebajado', 'Sale price'],
        'stock' => ['Inventario', 'Stock'],
    ]);
    // Campos del catálogo que se pueden rellenar desde el CSV
    $priceField = field_price_public();
    $stockField = null;
    foreach (fields_all() as $f) {
        if ($f['slug'] === 'stock') {
            $stockField = $f;
        }
    }
    foreach (['id' => 'ID', 'name' => 'Nombre', 'cats' => 'Categorías', 'images' => 'Imágenes'] as $k => $label) {
        if (!isset($col[$k])) {
            throw new RuntimeException('Este no parece el CSV de WooCommerce (falta la columna «' . $label . '»; el archivo empieza por: «'
                . mb_substr(implode(' | ', array_slice($head, 0, 4)), 0, 80) . '»). Usa Productos → Exportar en WordPress, o el origen «Hoja de cálculo».');
        }
    }
    $get = fn(array $r, string $k): string => isset($col[$k], $r[$col[$k]]) ? trim((string)$r[$col[$k]]) : '';
    $attr = [];
    foreach ($head as $i => $h) {
        if (preg_match('/^Nombre del atributo (\d+)$/iu', $h, $m) || preg_match('/^Attribute (\d+) name$/i', $h, $m)) {
            $attr[(int)$m[1]]['n'] = $i;
        } elseif (preg_match('/^Valor\(es\) del atributo (\d+)$/iu', $h, $m) || preg_match('/^Attribute (\d+) value\(s\)$/i', $h, $m)) {
            $attr[(int)$m[1]]['v'] = $i;
        }
    }

    // Servidor de las fotos: solo se descarga de los servidores que aparecen en el CSV (y nunca de redes privadas).
    $hosts = [];
    foreach ($rows as $r) {
        foreach (preg_split('/\s*,\s+(?=https?:)/', $get($r, 'images')) as $u) {
            if (preg_match('#^https?://([^/]+)#i', trim($u), $m)) {
                $hosts[strtolower($m[1])] = true;
            }
        }
    }
    if (!$hosts) {
        throw new RuntimeException('El CSV no contiene direcciones de fotos.');
    }
    $first = array_key_first($hosts);
    $allowed = [];
    foreach (array_keys($hosts) as $h) {
        $allowed = array_merge($allowed, import_hosts('https://' . $h));
    }
    $allowed = array_values(array_unique($allowed));

    $idx = import_existing_index();
    $items = [];
    $warnings = [];
    foreach ($rows as $r) {
        if (in_array(strtolower($get($r, 'type')), ['variation', 'variación'], true)) {
            continue; // variaciones de un producto variable: se importa solo el producto principal
        }
        $id = (int)$get($r, 'id');
        $rawName = $get($r, 'name');
        // Categorías: «Sillas, Sillas > Plegables» → nos quedamos con la más concreta.
        $cats = array_values(array_filter(array_map('trim', explode(',', $get($r, 'cats')))));
        usort($cats, fn($a, $b) => substr_count($b, '>') <=> substr_count($a, '>'));
        $path = array_values(array_filter(array_map(fn($s) => trim(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'), " .\t"), explode('>', $cats[0] ?? ''))));
        $noFamily = !$path || preg_match('/^(sin categor|uncategori)/iu', $path[0]);
        if ($noFamily) {
            $path = [];
        }
        $ref = $get($r, 'sku');
        $dims = '';
        if (count($path) > 1) {
            $sub = woo_parse_sub($path[1]);
            $path[1] = $sub['name'] !== '' ? $sub['name'] : $path[1];
            $ref = $ref !== '' ? $ref : $sub['ref'];
            $dims = $sub['dims'];
        }
        if ($ref === '' && preg_match('/\bRef\.?\s*([0-9A-Za-z]+)/u', $rawName, $m)) {
            $ref = $m[1];
        }
        $name = woo_clean_name($rawName);
        if ($name === '') {
            continue;
        }
        $long = import_csv_clean_text($get($r, 'desc'));
        $short = import_csv_clean_text($get($r, 'short'));
        $desc = $long !== '' ? $long : $short;
        if ($desc !== '' && mb_strlen($desc) < 120 && slugify($desc) === slugify($rawName)) {
            $desc = '';
        }
        if ($dims === '') {
            $dims = import_dims($rawName) ?: import_dims($desc);
        }
        if ($dims === '' && ($get($r, 'height') !== '' || $get($r, 'width') !== '')) {
            $dims = implode(' x ', array_filter([$get($r, 'height'), $get($r, 'width'), $get($r, 'length')], 'strlen')) . ' cm';
        }
        $extra = [];
        $fv = [];
        if ($priceField && $get($r, 'price') !== '') {
            $fv[$priceField['id']] = $get($r, 'price');
        }
        if ($stockField && $get($r, 'stock') !== '') {
            $fv[$stockField['id']] = $get($r, 'stock');
        }
        foreach ($attr as $a) {
            $an = isset($a['n']) ? trim((string)($r[$a['n']] ?? ''), ' .') : '';
            $av = isset($a['v']) ? trim((string)($r[$a['v']] ?? '')) : '';
            if ($an !== '' && $av !== '' && !preg_match('/^(medidas|dimensiones)/iu', $an)) {
                $af = field_for_column($an);
                if ($af) {
                    $fv[$af['id']] = in_array($af['kind'], ['multi', 'select'], true) ? str_replace(',', '|', $av) : $av; // atributo con campo propio
                } else {
                    $extra[] = $an . ': ' . rtrim($av, '. ');
                }
            }
        }
        if ($extra) {
            $desc = trim($desc . "\n\n" . implode("\n", $extra));
        }

        $images = [];
        $have = [];
        $urls = preg_split('/\s*,\s+(?=https?:)/', $get($r, 'images'));
        foreach ($xmlMedia[(string)$id] ?? [] as $u) {
            $urls[] = $u; // fotos adjuntas en el XML de medios que el CSV no lista
        }
        foreach ($urls as $u) {
            $u = trim($u);
            if ($u === '' || !preg_match('#^https?://#i', $u)) {
                continue;
            }
            $b = strtolower(basename((string)parse_url($u, PHP_URL_PATH)));
            if (in_array($b, $have, true)) {
                continue;
            }
            if (!in_array(strtolower((string)parse_url($u, PHP_URL_HOST)), $allowed, true)) {
                $warnings[$u] = 'Foto en otro servidor, se omite: ' . $u;
                continue;
            }
            $have[] = $b;
            $images[] = ['key' => $u, 'urls' => [$u]];
        }

        $visible = ($get($r, 'visibility') === 'hidden' || $get($r, 'published') === '-1' || $get($r, 'published') === '0') ? 0 : 1;
        $item = import_plan_item(import_existing_find($idx, $name, $ref), $name, $path, $ref, $dims, $desc, $images, $visible, $fv);
        // Duplicados en WooCommerce (mismo nombre y familia): se queda el de ID más alto.
        $key = ($noFamily ? 'x' . $id : implode('>', $path)) . '|' . slugify($name);
        if (!isset($items[$key]) || $id > $items[$key]['_id']) {
            $items[$key] = $item + ['_id' => $id];
        }
    }
    foreach ($items as &$it) {
        unset($it['_id']);
    }
    unset($it);

    return [
        'origin' => 'https://' . $first,
        'allowed' => $allowed,
        'items' => array_values($items),
        'warnings' => array_values($warnings),
    ];
}

return [
    'id' => 'woocommerce',
    'label' => 'WordPress / WooCommerce (CSV)',
    'order' => 10,
    'recommended' => true,
    'help' => 'En WordPress: Productos → Exportar → «Generar CSV». Crea las familias y subfamilias que falten y los artículos con sus fotos (se descargan de tu web actual, que debe seguir online).',
    'fields' => [
        ['name' => 'csv', 'type' => 'file', 'label' => 'Archivo CSV de productos', 'accept' => '.csv,text/csv', 'required' => true],
        ['name' => 'xml', 'type' => 'file', 'label' => 'Opcional: XML de medios', 'accept' => '.xml,text/xml',
            'help' => 'Herramientas → Exportar → «Medios». Añade las fotos que WordPress tiene adjuntas a cada artículo y el CSV no incluye.'],
    ],
    'analyze' => 'woo_analyze',
];
