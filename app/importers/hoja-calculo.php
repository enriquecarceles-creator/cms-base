<?php
declare(strict_types=1);

/*
 * Origen: hoja de cálculo genérica (CSV exportado de Excel, Google Sheets, Shopify, PrestaShop,
 * un ERP, otro programa…). Reconoce las columnas por su título, en español o en inglés.
 * Las fotos pueden ser direcciones (https://…) o nombres de archivo que vengan dentro de un ZIP.
 */

/** Títulos de columna que se reconocen (sin importar mayúsculas, acentos ni signos). */
const SHEET_COLUMNS = [
    'name' => ['nombre', 'name', 'title', 'titulo', 'producto', 'product', 'articulo', 'nombre del producto', 'product name', 'denominacion'],
    'family' => ['familia', 'categoria', 'category', 'categorias', 'categories', 'type', 'product type', 'tipo', 'grupo', 'seccion', 'product category', 'categoria por defecto', 'default category'],
    'sub' => ['subfamilia', 'subcategoria', 'subcategory', 'subgrupo'],
    'ref' => ['referencia', 'ref', 'sku', 'codigo', 'code', 'variant sku', 'reference', 'cod', 'codigo articulo', 'item code'],
    'dims' => ['medidas', 'dimensiones', 'dimensions', 'tamano', 'size', 'medida'],
    'desc' => ['descripcion', 'description', 'body html', 'body', 'detalle', 'texto', 'descripcion larga', 'long description'],
    'short' => ['descripcion corta', 'short description', 'resumen', 'summary'],
    'visible' => ['visible', 'publicado', 'published', 'status', 'estado', 'activo', 'active'],
    'sort' => ['orden', 'order', 'position', 'posicion'],
    'group' => ['handle', 'id producto', 'product id', 'agrupar'],
];

/** SHEET_COLUMNS más los nombres del catálogo configurados (p. ej. «Categoría»), si no estaban ya. */
function sheet_columns(): array
{
    $cols = SHEET_COLUMNS;
    foreach (['family' => 'family', 'sub' => 'sub'] as $k => $t) {
        $alias = import_norm(term($t));
        if (!in_array($alias, array_map('import_norm', $cols[$k]), true)) {
            $cols[$k][] = $alias;
        }
    }
    return $cols;
}

/** Columnas de fotos: «Fotos», «Imagen 2», «Image Src», «URL imagen»… (puede haber varias). */
function sheet_image_columns(array $head): array
{
    $out = [];
    foreach ($head as $i => $h) {
        $n = import_norm($h);
        if (preg_match('/^(fotos?|imagen(es)?|images?|img|picture|pictures|photo|photos)( (src|url|urls|\d+))?$|^(url|urls) (imagen(es)?|images?|fotos?)$|^image src$|^variant image$/', $n)) {
            $out[] = $i;
        }
    }
    return $out;
}

function sheet_analyze(array $in): array
{
    [$head, $rows] = import_read_csv((string)$in['files']['csv']);
    $col = import_map_columns($head, sheet_columns());
    $imgCols = sheet_image_columns($head);
    // Columnas que corresponden a campos del catálogo (Precio, Tallas, Color…)
    $fieldCols = [];
    $used = array_merge(array_values($col), $imgCols);
    foreach ($head as $i => $h) {
        if (!in_array($i, $used, true) && ($f = field_for_column($h)) && !in_array($f['id'], $fieldCols, true)) {
            $fieldCols[$i] = $f['id'];
        }
    }
    if (!isset($col['name'])) {
        throw new RuntimeException('No encuentro la columna con el nombre del artículo. Llámala «Nombre». Columnas del archivo: '
            . mb_substr(implode(' | ', $head), 0, 200));
    }
    $warnings = [];
    $foundTxt = [];
    foreach (['family' => term('family', true), 'sub' => term('sub', true), 'ref' => 'referencia', 'dims' => 'medidas', 'desc' => 'descripción', 'visible' => 'visible'] as $k => $label) {
        if (isset($col[$k])) {
            $foundTxt[] = $label . ' = «' . $head[$col[$k]] . '»';
        }
    }
    if ($imgCols) {
        $foundTxt[] = 'fotos = «' . implode('», «', array_map(fn($i) => $head[$i], $imgCols)) . '»';
    }
    foreach ($fieldCols as $i => $fid) {
        $foundTxt[] = mb_strtolower(fields_all()[$fid]['name']) . ' = «' . $head[$i] . '»';
    }

    // Fotos dentro de un ZIP (subido aquí o por FTP a data/importar/)
    $zipMap = [];
    $zip = import_zip_input($in['files']['zip'] ?? null);
    if ($zip) {
        $zipMap = import_extract_zip($zip);
    }

    $get = fn(array $r, string $k): string => isset($col[$k], $r[$col[$k]]) ? trim((string)$r[$col[$k]]) : '';
    $idx = import_existing_index();
    $items = [];
    $order = [];
    $hosts = [];
    $missingFiles = [];
    $lastKey = null;
    foreach ($rows as $n => $r) {
        $name = trim(html_entity_decode(strip_tags($get($r, 'name')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $group = $get($r, 'group');
        // Varias filas por artículo (Shopify: una fila por foto o variante con el mismo «Handle»)
        $key = $group !== '' ? 'g:' . mb_strtolower($group) : ($name !== '' ? 'n:' . $n : null);
        if ($key === null) {
            continue;
        }
        if (!isset($items[$key])) {
            if ($name === '') {
                continue;
            }
            $path = [];
            foreach (preg_split('/\s*(?:>|›|\/|\|)\s*/u', $get($r, 'family')) as $p) {
                if (trim($p) !== '') {
                    $path[] = trim($p);
                }
            }
            if ($get($r, 'sub') !== '') {
                $path = array_slice($path, 0, 1);
                $path[] = $get($r, 'sub');
            }
            $path = array_slice($path, 0, 2); // familia › subfamilia (dos niveles)
            $desc = import_csv_clean_text($get($r, 'desc'));
            if ($desc === '') {
                $desc = import_csv_clean_text($get($r, 'short'));
            }
            $dims = $get($r, 'dims');
            $dims = $dims !== '' ? (import_dims($dims) ?: $dims) : (import_dims($desc));
            $items[$key] = [
                'name' => $name, 'path' => $path, 'ref' => $get($r, 'ref'), 'dims' => $dims, 'desc' => $desc,
                'visible' => import_bool($get($r, 'visible')), 'images' => [], 'seen' => [], 'fields' => [],
            ];
            foreach ($fieldCols as $ci => $fid) {
                $items[$key]['fields'][$fid] = trim((string)($r[$ci] ?? ''));
            }
            $order[$key] = $get($r, 'sort') !== '' ? (int)$get($r, 'sort') : 100000 + $n;
        }
        $it = &$items[$key];
        foreach ($imgCols as $ci) {
            foreach (preg_split('/\s*[|;\n]\s*|\s*,\s*(?=https?:)|\s*,\s+/u', trim((string)($r[$ci] ?? ''))) as $v) {
                $v = trim($v, " \t\"'");
                if ($v === '' || isset($it['seen'][mb_strtolower($v)])) {
                    continue;
                }
                $it['seen'][mb_strtolower($v)] = true;
                if (preg_match('#^https?://#i', $v)) {
                    $h = strtolower((string)parse_url($v, PHP_URL_HOST));
                    if ($h === '') {
                        continue;
                    }
                    $hosts[$h] = true;
                    $it['images'][] = ['key' => $v, 'urls' => [$v]];
                } else {
                    $b = mb_strtolower(basename(str_replace('\\', '/', $v)));
                    if (isset($zipMap[$b])) {
                        $it['images'][] = ['key' => 'zip:' . $b, 'file' => $zipMap[$b], 'label' => basename($v)];
                    } else {
                        $missingFiles[$b] = basename($v);
                    }
                }
            }
        }
        unset($it);
    }
    if (!$items) {
        throw new RuntimeException('No hay ninguna fila con nombre de artículo.');
    }
    asort($order);
    $plan = [];
    $noFamily = 0;
    foreach (array_keys($order) as $k) {
        $it = $items[$k];
        $item = import_plan_item(import_existing_find($idx, $it['name'], $it['ref']), $it['name'], $it['path'], $it['ref'], $it['dims'], $it['desc'], $it['images'], $it['visible'], $it['fields']);
        if (!$item['pid'] && !$item['cat_id'] && !$item['cat_path']) {
            $noFamily++;
        }
        $plan[] = $item;
    }
    if ($foundTxt) {
        $warnings[] = 'Columnas reconocidas: ' . implode('; ', $foundTxt) . '.';
    }
    if (!isset($col['family'])) {
        $warnings[] = 'No hay columna «' . term('family') . '»: los artículos nuevos no se pueden crear (solo se completan los que ya existen). Añade la columna y vuelve a analizar.';
    } elseif ($noFamily) {
        $warnings[] = $noFamily . ' artículo(s) sin ' . term('family', true) . ': no se importarán.';
    }
    if ($missingFiles) {
        $warnings[] = count($missingFiles) . ' foto(s) no están en el ZIP' . ($zipMap ? '' : ' (no has subido ningún ZIP)') . ': '
            . mb_strimwidth(implode(', ', array_values($missingFiles)), 0, 300, '…');
    }
    $allowed = [];
    foreach (array_keys($hosts) as $h) {
        $allowed = array_merge($allowed, import_hosts('https://' . $h));
    }
    return [
        'origin' => $in['names']['csv'] ?: 'hoja de cálculo',
        'allowed' => array_values(array_unique($allowed)),
        'items' => $plan,
        'warnings' => $warnings,
    ];
}

/** Plantilla de ejemplo para rellenar en Excel / Google Sheets. */
function sheet_template(): string
{
    $rows = [
        ['Nombre', term('family'), term('sub'), 'Referencia', 'Medidas', 'Descripción', 'Fotos', 'Visible', 'Orden'],
        ['Silla plegable', 'Sillas', 'Plegables', 'S-001', '80 x 45 cm', 'Silla de acero con asiento de madera.', 'silla-plegable-1.jpg | silla-plegable-2.jpg', 'sí', '10'],
        ['Mesa redonda', 'Mesas', '', 'M-010', '75 x 120 cm', 'Mesa de comedor para 6 personas.', 'https://mi-web-antigua.com/fotos/mesa.jpg', 'sí', '20'],
    ];
    foreach (fields_all() as $f) {
        $rows[0][] = $f['name'];
        $rows[1][] = $f['kind'] === 'price' ? '49,90' : '';
        $rows[2][] = '';
    }
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF");
    foreach ($rows as $r) {
        fputcsv($fh, $r, ';', '"', '');
    }
    rewind($fh);
    $out = (string)stream_get_contents($fh);
    fclose($fh);
    return $out;
}

return [
    'id' => 'hoja-calculo',
    'label' => 'Hoja de cálculo u otra aplicación (CSV)',
    'order' => 20,
    'help' => 'Excel, Google Sheets, Shopify, PrestaShop, un ERP… Guarda la hoja como CSV. Columnas que se reconocen: Nombre (obligatoria), ' . term('family') . ', ' . term('sub') . ', Referencia, Medidas, Descripción, Fotos, Visible y Orden (también en inglés: Name, Category, SKU, Description, Image Src…), y una columna por cada campo del catálogo con su mismo nombre (Precio, Tallas, Color…). En «' . term('family') . '» puedes escribir «Sillas > Plegables».',
    'template' => 'sheet_template',
    'fields' => [
        ['name' => 'csv', 'type' => 'file', 'label' => 'Archivo CSV', 'accept' => '.csv,.txt,text/csv', 'required' => true],
        ['name' => 'zip', 'type' => 'file', 'label' => 'Opcional: ZIP con las fotos', 'accept' => '.zip,application/zip',
            'help' => 'Si la columna Fotos tiene nombres de archivo (silla-1.jpg), súbelos en un ZIP. Si el ZIP pesa mucho, cópialo por FTP a la carpeta data/importar/ y deja este campo vacío.'],
    ],
    'analyze' => 'sheet_analyze',
];
