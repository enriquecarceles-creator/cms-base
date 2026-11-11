<?php
declare(strict_types=1);

/*
 * Campos libres del catálogo: cada empresa define los suyos (Precio, Tallas, Color, Material…)
 * en el instalador o en Catálogo → Características. Los valores de cada artículo van en la tabla product_fields.
 */

/** Tipos de campo: clave => [nombre, explicación]. */
const FIELD_KINDS = [
    'text' => ['Texto', 'Una línea: color, material, marca…'],
    'textarea' => ['Texto largo', 'Varias líneas: composición, instrucciones…'],
    'number' => ['Número', 'Peso, capacidad, unidades por caja… (con unidad: kg, l, uds.)'],
    'price' => ['Precio', 'Número con dos decimales y moneda.'],
    'select' => ['Lista (una opción)', 'Se elige una de las opciones escritas.'],
    'multi' => ['Lista (varias opciones)', 'Se marcan varias: tallas, colores disponibles…'],
    'bool' => ['Sí / No', 'Casilla: novedad, oferta, bajo pedido…'],
    'url' => ['Enlace', 'Ficha técnica, vídeo, manual en PDF…'],
];

/** Campos predefinidos que se ofrecen en el instalador: slug => [nombre, tipo, unidad, público, opciones]. */
const FIELD_PRESETS = [
    'precio' => ['Precio', 'price', '€', 1, ''],
    'tallas' => ['Tallas', 'text', '', 1, ''],
    'color' => ['Color', 'text', '', 1, ''],
    'material' => ['Material', 'text', '', 1, ''],
    'marca' => ['Marca', 'text', '', 1, ''],
    'peso' => ['Peso', 'number', 'kg', 1, ''],
    'garantia' => ['Garantía', 'text', '', 1, ''],
    'ficha-tecnica' => ['Ficha técnica', 'url', '', 1, ''],
    'novedad' => ['Novedad', 'bool', '', 1, ''],
    'stock' => ['Stock', 'number', 'uds.', 0, ''],
    'precio-coste' => ['Precio de coste', 'price', '€', 0, ''],
];

/** Todos los campos, en orden. */
function fields_all(bool $refresh = false): array
{
    static $all = null;
    if ($all === null || $refresh) {
        $all = [];
        try {
            foreach (db()->query('SELECT * FROM custom_fields ORDER BY sort, id')->fetchAll() as $f) {
                $f['id'] = (int)$f['id'];
                $f['is_public'] = (int)$f['is_public'];
                $all[$f['id']] = $f;
            }
        } catch (PDOException $e) {
            $all = [];
        }
    }
    return $all;
}

/** Opciones de un campo de lista (una por línea o separadas por comas). */
function field_choices(array $f): array
{
    $raw = (string)$f['choices'];
    $parts = str_contains($raw, "\n") ? explode("\n", $raw) : explode(',', $raw);
    return array_values(array_unique(array_filter(array_map('trim', $parts), 'strlen')));
}

/** Crea un campo. Devuelve su id. */
function field_create(string $name, string $kind = 'text', string $unit = '', int $public = 1, string $choices = ''): int
{
    $name = mb_substr(trim($name), 0, 80);
    if ($name === '') {
        throw new RuntimeException('Escribe el nombre del campo.');
    }
    $kind = isset(FIELD_KINDS[$kind]) ? $kind : 'text';
    $db = db();
    $slug = slugify($name);
    $ex = $db->prepare('SELECT COUNT(*) FROM custom_fields WHERE slug = ?');
    $ex->execute([$slug]);
    if ((int)$ex->fetchColumn() > 0) {
        throw new RuntimeException('Ya hay un campo llamado «' . $name . '».');
    }
    $sort = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM custom_fields')->fetchColumn();
    $db->prepare('INSERT INTO custom_fields (name, slug, kind, choices, unit, is_public, sort) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name, $slug, $kind, trim($choices), mb_substr(trim($unit), 0, 20), $public ? 1 : 0, $sort]);
    fields_all(true);
    return (int)$db->lastInsertId();
}

/** Valores de un artículo: [id de campo => valor]. */
function product_field_values(int $pid): array
{
    $st = db()->prepare('SELECT field_id, value FROM product_fields WHERE product_id = ?');
    $st->execute([$pid]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['field_id']] = (string)$r['value'];
    }
    return $out;
}

/** Normaliza un valor según el tipo del campo ('' = vacío). */
function field_clean(array $f, mixed $v): string
{
    if (is_array($v)) {
        $v = implode('|', array_map('strval', $v));
    }
    $v = trim((string)$v);
    switch ($f['kind']) {
        case 'number':
        case 'price':
            if ($v === '') {
                return '';
            }
            // «1.234,50 €», «1234.5», «12,5» → número
            $n = preg_replace('/[^\d,.\-]/', '', $v);
            if (str_contains($n, ',') && str_contains($n, '.')) {
                $n = strrpos($n, ',') > strrpos($n, '.') ? str_replace(['.', ','], ['', '.'], $n) : str_replace(',', '', $n);
            } else {
                $n = str_replace(',', '.', $n);
            }
            if (!is_numeric($n)) {
                return '';
            }
            return $f['kind'] === 'price' ? number_format((float)$n, 2, '.', '') : rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.');
        case 'bool':
            return in_array(mb_strtolower($v), ['', '0', 'no', 'false', 'falso', 'n'], true) ? '' : '1';
        case 'url':
            return preg_match('#^https?://#i', $v) ? mb_substr($v, 0, 1000) : '';
        case 'select':
        case 'multi':
            $parts = array_values(array_filter(array_map('trim', preg_split('/\s*[|;]\s*/', $v)), 'strlen'));
            return implode('|', $f['kind'] === 'select' ? array_slice($parts, 0, 1) : $parts);
        default:
            return mb_substr($v, 0, 5000);
    }
}

/** Guarda los valores de un artículo. $onlyEmpty: no tocar los que ya tienen valor (importador). */
function product_fields_save(int $pid, array $values, bool $onlyEmpty = false): void
{
    $fields = fields_all();
    if (!$fields) {
        return;
    }
    $db = db();
    $cur = product_field_values($pid);
    $del = $db->prepare('DELETE FROM product_fields WHERE product_id = ? AND field_id = ?');
    $ins = $db->prepare('INSERT INTO product_fields (product_id, field_id, value) VALUES (?,?,?)');
    foreach ($values as $fid => $v) {
        $fid = (int)$fid;
        if (!isset($fields[$fid])) {
            continue;
        }
        if ($onlyEmpty && ($cur[$fid] ?? '') !== '') {
            continue;
        }
        $v = field_clean($fields[$fid], $v);
        if (($cur[$fid] ?? '') === $v) {
            continue;
        }
        $del->execute([$pid, $fid]);
        if ($v !== '') {
            $ins->execute([$pid, $fid, $v]);
        }
    }
}

/** Texto para mostrar un valor. */
function field_format(array $f, string $v): string
{
    switch ($f['kind']) {
        case 'price':
            return number_format((float)$v, 2, ',', '.') . ($f['unit'] !== '' ? ' ' . $f['unit'] : ' €');
        case 'number':
            $dec = str_contains($v, '.') ? strlen(substr($v, strpos($v, '.') + 1)) : 0;
            return number_format((float)$v, $dec, ',', '.') . ($f['unit'] !== '' ? ' ' . $f['unit'] : '');
        case 'bool':
            return $v === '1' ? 'Sí' : 'No';
        case 'select':
        case 'multi':
            return implode(', ', explode('|', $v));
        default:
            return $v . ($f['unit'] !== '' ? ' ' . $f['unit'] : '');
    }
}

/** Campos públicos con valor de un artículo: [[campo, valor], …] */
function product_public_fields(int $pid): array
{
    $vals = product_field_values($pid);
    $out = [];
    foreach (fields_all() as $id => $f) {
        if ($f['is_public'] && ($vals[$id] ?? '') !== '' && !($f['kind'] === 'bool' && $vals[$id] !== '1')) {
            $out[] = [$f, $vals[$id]];
        }
    }
    return $out;
}

/** Primer campo público de tipo precio (para las tarjetas del catálogo), o null. */
function field_price_public(): ?array
{
    foreach (fields_all() as $f) {
        if ($f['kind'] === 'price' && $f['is_public']) {
            return $f;
        }
    }
    return null;
}

/** Precio público de cada artículo (una sola consulta por página): [id artículo => texto]. */
function price_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        $f = field_price_public();
        if ($f) {
            $st = db()->prepare('SELECT product_id, value FROM product_fields WHERE field_id = ?');
            $st->execute([$f['id']]);
            foreach ($st->fetchAll() as $r) {
                $map[(int)$r['product_id']] = field_format($f, (string)$r['value']);
            }
        }
    }
    return $map;
}

/** Valores de un campo para varios artículos: [id artículo => valor]. */
function field_values_for(int $fid, array $pids): array
{
    $pids = array_values(array_filter(array_map('intval', $pids)));
    if (!$pids) {
        return [];
    }
    $out = [];
    foreach (array_chunk($pids, 500) as $chunk) {
        $st = db()->prepare('SELECT product_id, value FROM product_fields WHERE field_id = ? AND product_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $st->execute([$fid, ...$chunk]);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['product_id']] = (string)$r['value'];
        }
    }
    return $out;
}

/** Control del formulario del artículo para un campo. */
function field_input(array $f, string $v): string
{
    $name = 'cf[' . $f['id'] . ']';
    $label = e($f['name']) . ($f['unit'] !== '' && $f['kind'] !== 'price' ? ' <small>(' . e($f['unit']) . ')</small>' : '') . ($f['is_public'] ? '' : ' <small class="muted">· interno</small>');
    switch ($f['kind']) {
        case 'textarea':
            return '<label><span class="lbl">' . $label . '</span> <textarea name="' . $name . '" rows="3">' . e($v) . '</textarea></label>';
        case 'number':
        case 'price':
            $shown = $v === '' ? '' : str_replace('.', ',', $v);
            return '<label><span class="lbl">' . $label . '</span> <input name="' . $name . '" value="' . e($shown) . '" inputmode="decimal"' . ($f['kind'] === 'price' ? ' placeholder="0,00 ' . e($f['unit'] ?: '€') . '"' : '') . '></label>';
        case 'bool':
            return '<label class="check"><input type="hidden" name="' . $name . '" value=""><input type="checkbox" name="' . $name . '" value="1"' . ($v === '1' ? ' checked' : '') . '> ' . $label . '</label>';
        case 'url':
            return '<label><span class="lbl">' . $label . '</span> <input type="url" name="' . $name . '" value="' . e($v) . '" placeholder="https://…"></label>';
        case 'select':
            $h = '<label><span class="lbl">' . $label . '</span> <select name="' . $name . '"><option value="">—</option>';
            $choices = field_choices($f);
            if ($v !== '' && !in_array($v, $choices, true)) {
                $choices[] = $v;
            }
            foreach ($choices as $c) {
                $h .= '<option' . ($c === $v ? ' selected' : '') . '>' . e($c) . '</option>';
            }
            return $h . '</select></label>';
        case 'multi':
            $sel = $v === '' ? [] : explode('|', $v);
            $choices = array_values(array_unique(array_merge(field_choices($f), $sel)));
            $h = '<fieldset class="multi"><legend>' . $label . '</legend><input type="hidden" name="' . $name . '[]" value="">';
            foreach ($choices as $c) {
                $h .= '<label class="check"><input type="checkbox" name="' . $name . '[]" value="' . e($c) . '"' . (in_array($c, $sel, true) ? ' checked' : '') . '> ' . e($c) . '</label>';
            }
            return $h . ($choices ? '' : '<small>Escribe las opciones en Catálogo → Características.</small>') . '</fieldset>';
        default:
            return '<label><span class="lbl">' . $label . '</span> <input name="' . $name . '" value="' . e($v) . '" maxlength="5000"></label>';
    }
}

/** Busca el campo que corresponde a un título de columna (por nombre o por alias habituales). */
function field_for_column(string $header): ?array
{
    $n = import_norm_simple($header);
    if ($n === '') {
        return null;
    }
    $aliases = [
        'price' => ['precio', 'pvp', 'price', 'precio venta', 'precio de venta', 'regular price', 'precio normal', 'variant price', 'importe'],
    ];
    foreach (fields_all() as $f) {
        if ($n === import_norm_simple($f['name']) || $n === import_norm_simple($f['slug'])) {
            return $f;
        }
    }
    foreach (fields_all() as $f) {
        if (isset($aliases[$f['kind']]) && $f['is_public'] && in_array($n, $aliases[$f['kind']], true)) {
            return $f;
        }
    }
    return null;
}

/** Igual que import_norm() del importador (aquí para no depender de él). */
function import_norm_simple(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return trim((string)preg_replace('/[^a-z0-9]+/', ' ', $s));
}

/* ---------- Filtros del catálogo público (Web → Página del catálogo) ---------- */

const CATALOG_FILTER_MAX = 4;
/** Tipos de campo que se pueden usar como filtro (texto largo, enlace y número libre no). */
const CATALOG_FILTER_KINDS = ['text', 'select', 'multi', 'bool', 'price'];

/** Campos que se pueden ofrecer como filtro: públicos y de un tipo válido, en el orden de Catálogo → Características. */
function catalog_filter_candidates(): array
{
    return array_filter(fields_all(), fn($f) => $f['is_public'] === 1 && in_array($f['kind'], CATALOG_FILTER_KINDS, true));
}

/** Filtros activados (ajuste catalog_filters = JSON con ids), solo los que siguen siendo válidos; máximo CATALOG_FILTER_MAX. */
function catalog_filter_fields(): array
{
    $ids = json_decode(setting('catalog_filters', '[]'), true);
    $cand = catalog_filter_candidates();
    $out = [];
    foreach (is_array($ids) ? $ids : [] as $id) {
        if (isset($cand[(int)$id]) && count($out) < CATALOG_FILTER_MAX) {
            $out[(int)$id] = $cand[(int)$id];
        }
    }
    return $out;
}

/** Valores sueltos de un valor guardado: «36, 37 ; 38» (texto) o «a|b» (listas) → ['36','37','38']. */
function filter_tokens(array $f, string $v): array
{
    $parts = $f['kind'] === 'text' ? preg_split('/\s*[,;|]\s*/u', $v) : explode('|', $v);
    return array_values(array_filter(array_map('trim', $parts), 'strlen'));
}

/**
 * Prepara los filtros del catálogo. $scopeSql = consulta de ids de los artículos que se están viendo (sin buscador ni filtros),
 * $get = $_GET. Devuelve ['filters' => lo que pinta la vista, 'where' => condiciones para añadir al SQL, 'args' => sus valores,
 * 'qs' => parámetros activos para los enlaces]. Una consulta por filtro (nunca una por artículo).
 */
function catalog_filters_build(string $scopeSql, array $scopeArgs, array $get): array
{
    $res = ['filters' => [], 'where' => '', 'args' => [], 'qs' => []];
    $budget = 1500; // parámetros máximos en listas «IN (…)» (SQL Server admite 2.100 por consulta)
    foreach (catalog_filter_fields() as $id => $f) {
        $name = 'f' . $id;
        $kind = $f['kind'];
        $item = ['f' => $f, 'name' => $name, 'kind' => $kind];
        if ($kind === 'bool') {
            $on = ($get[$name] ?? '') === '1';
            $item['value'] = $on ? '1' : '';
            if ($on) {
                $res['where'] .= " AND p.id IN (SELECT product_id FROM product_fields WHERE field_id = ? AND value = '1')";
                $res['args'][] = $id;
                $res['qs'][$name] = '1';
            }
        } elseif ($kind === 'price') {
            $lim = [];
            foreach (['min' => '>=', 'max' => '<='] as $k => $op) {
                $raw = $get[$name . '_' . $k] ?? '';
                $v = is_string($raw) ? field_clean(['kind' => 'price'], $raw) : '';
                $item[$k] = $v !== '' ? rtrim(rtrim($v, '0'), '.') : '';
                if ($v !== '') {
                    $lim[] = ["CASE WHEN value <> '' THEN CAST(value AS DECIMAL(14,4)) END " . $op . ' CAST(? AS DECIMAL(14,4))', $v];
                    $res['qs'][$name . '_' . $k] = $item[$k];
                }
            }
            if ($lim) {
                $res['where'] .= " AND p.id IN (SELECT product_id FROM product_fields WHERE field_id = ? AND " . implode(' AND ', array_column($lim, 0)) . ')';
                array_push($res['args'], $id, ...array_column($lim, 1));
            }
        } else {
            // Valores distintos entre los artículos que se ven (una consulta) y, de cada uno, los textos guardados que lo contienen.
            $st = db()->prepare("SELECT value, COUNT(*) AS n FROM product_fields WHERE field_id = ? AND value <> '' AND product_id IN ($scopeSql) GROUP BY value");
            $st->execute(array_merge([$id], $scopeArgs));
            $opts = [];
            $raws = [];
            $cnt = [];
            foreach ($st->fetchAll() as $r) {
                $raw = (string)$r['value'];
                foreach (filter_tokens($f, $raw) as $t) {
                    $key = mb_strtolower($t);
                    $opts[$key] ??= $t;
                    $raws[$key][$raw] = true;
                    $cnt[$key] = ($cnt[$key] ?? 0) + (int)$r['n'];
                }
            }
            if (!$opts) {
                continue; // sin valores en lo que se está viendo: no se ofrece el filtro
            }
            if (count($opts) > 300) { // campos de texto muy variados: se ofrecen los 300 valores más usados
                arsort($cnt);
                $opts = array_intersect_key($opts, array_slice($cnt, 0, 300, true));
            }
            uasort($opts, 'strnatcasecmp');
            $sel = $get[$name] ?? '';
            $key = is_string($sel) ? mb_strtolower(trim($sel)) : '';
            $item['options'] = $opts;
            $item['value'] = isset($opts[$key]) ? $key : '';
            if ($item['value'] !== '') {
                $list = array_map('strval', array_keys($raws[$key]));
                if (count($list) <= $budget) { // exacto: los textos guardados que contienen ese valor
                    $budget -= count($list);
                    $res['where'] .= ' AND p.id IN (SELECT product_id FROM product_fields WHERE field_id = ? AND value IN (' . implode(',', array_fill(0, count($list), '?')) . '))';
                    array_push($res['args'], $id, ...$list);
                } else { // valor muy repetido: lista demasiado larga para SQL Server (2.100 parámetros); se busca el texto
                    $res['where'] .= " AND p.id IN (SELECT product_id FROM product_fields WHERE field_id = ? AND LOWER(value) LIKE ? ESCAPE '!')";
                    array_push($res['args'], $id, '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $key) . '%');
                }
                $res['qs'][$name] = $key;
            }
        }
        $res['filters'][] = $item;
    }
    return $res;
}

/* ---------- Campos en el catálogo PDF (/catalogo/completo) ---------- */

/** Campos que se pueden imprimir en el PDF: públicos y de un tipo corto (no texto largo ni enlace). Nunca los internos. */
function print_field_candidates(): array
{
    return array_filter(fields_all(), fn($f) => $f['is_public'] === 1 && !in_array($f['kind'], ['textarea', 'url'], true));
}

/**
 * Valores ya formateados de los campos elegidos para todos los artículos, en UNA consulta:
 * [id artículo => [id campo => texto]]. Solo campos públicos (se vuelve a comprobar aquí), sin vacíos y sin «No».
 */
function print_field_values(array $fids): array
{
    $cand = print_field_candidates();
    $fids = array_values(array_filter(array_map('intval', $fids), fn($i) => isset($cand[$i])));
    if (!$fids) {
        return [];
    }
    $st = db()->prepare('SELECT product_id, field_id, value FROM product_fields WHERE value <> \'\' AND field_id IN (' . implode(',', array_fill(0, count($fids), '?')) . ')');
    $st->execute($fids);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $f = $cand[(int)$r['field_id']];
        $v = (string)$r['value'];
        if ($f['kind'] === 'bool' && $v !== '1') {
            continue;
        }
        $out[(int)$r['product_id']][(int)$r['field_id']] = $f['kind'] === 'bool' ? 'Sí' : field_format($f, $v);
    }
    return $out;
}
