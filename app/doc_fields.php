<?php
declare(strict_types=1);

/*
 * Campos de las líneas de documentos y modelos de impresión.
 *
 * Campos (Configuración → Parámetros → «Campos de las líneas»): una lista configurable en el ajuste `docp_line_fields` (JSON).
 * Vienen de serie Talla, Color y Dto.; el usuario añade los suyos (Lote…) con nombre y tipo (texto, número o lista). Los valores
 * de los campos propios viajan en `doc_lines.extra` / `erp_invoice_lines.extra` (JSON {clave: valor}). Cada campo tiene: activo,
 * sale en el formulario, sale en la impresión y en qué documentos (pedido, albarán, factura). Desactivar un campo NO borra los
 * valores ya guardados: los documentos antiguos los conservan (y se siguen imprimiendo si tienen valor); solo deja de salir en los nuevos.
 *
 * Modelos de impresión (Configuración → Diseño de documentos): clásico, moderno y compacto, para todos los documentos o uno por tipo.
 * Los tres usan las mismas plantillas: cambia una capa de CSS (`doc_model_css()`), así que respetan campos, vencimientos y marca de prueba.
 */

/** Campos que vienen de serie: clave => [nombre, tipo]. */
const DOC_LINE_BUILTIN = ['size' => ['Talla', 'text'], 'color' => ['Color', 'text'], 'disc' => ['Dto.', 'number']];
const DOC_LINE_TYPES = ['text' => 'Texto', 'number' => 'Número', 'list' => 'Lista'];
const DOC_LINE_MAX_CUSTOM = 6;
/** Tipo de documento → grupo en el que se configura el campo (las simplificadas y rectificativas cuentan como factura). */
const DOC_GROUPS = ['pedido' => 'pedido', 'albaran' => 'albaran', 'factura' => 'factura', 'simplificada' => 'factura', 'rectificativa' => 'factura'];
const DOC_GROUP_LABELS = ['pedido' => 'Pedido', 'albaran' => 'Albarán', 'factura' => 'Factura'];

/** Un campo con todos sus valores completos y validados. */
function dlf_normalize(string $k, array $in, bool $builtin): array
{
    $docs = array_values(array_intersect(array_keys(DOC_GROUP_LABELS), array_map('strval', (array)($in['docs'] ?? array_keys(DOC_GROUP_LABELS)))));
    $type = (string)($in['type'] ?? 'text');
    $opts = $in['options'] ?? [];
    if (is_string($opts)) {
        $opts = preg_split('/[,;\n]+/', $opts) ?: [];
    }
    $opts = array_slice(array_values(array_unique(array_filter(array_map(fn($o) => mb_substr(trim(strip_tags((string)$o)), 0, 40), (array)$opts), 'strlen'))), 0, 40);
    return [
        'k' => $k, 'builtin' => $builtin ? 1 : 0,
        'name' => $builtin ? DOC_LINE_BUILTIN[$k][0] : (mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 30) ?: 'Campo'),
        'type' => $builtin ? DOC_LINE_BUILTIN[$k][1] : (isset(DOC_LINE_TYPES[$type]) ? $type : 'text'),
        'options' => $opts,
        'on' => !empty($in['on']) ? 1 : 0, 'form' => !empty($in['form']) ? 1 : 0, 'print' => !empty($in['print']) ? 1 : 0,
        'docs' => $docs,
    ];
}

/** Todos los campos de las líneas, en orden: [clave => campo]. Los de serie siempre existen. */
function docs_line_fields(): array
{
    if (isset($GLOBALS['dlf_cache'])) {
        return $GLOBALS['dlf_cache'];
    }
    $saved = json_decode((string)setting('docp_line_fields', ''), true);
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach (DOC_LINE_BUILTIN as $k => $_) {
        $in = isset($saved[$k]) && is_array($saved[$k]) ? $saved[$k] : ['on' => 1, 'form' => 1, 'print' => 1];
        $out[$k] = dlf_normalize($k, $in, true);
    }
    foreach ($saved as $k => $in) {
        if (is_array($in) && preg_match('/^x[0-9]{1,3}$/', (string)$k) && count($out) < count(DOC_LINE_BUILTIN) + DOC_LINE_MAX_CUSTOM) {
            $out[(string)$k] = dlf_normalize((string)$k, $in, false);
        }
    }
    return $GLOBALS['dlf_cache'] = $out;
}

/** Guarda la lista (y renueva la caché de la petición). */
function docs_line_fields_save(array $fields): void
{
    save_setting('docp_line_fields', (string)json_encode($fields, JSON_UNESCAPED_UNICODE));
    setting('', '', true);
    unset($GLOBALS['dlf_cache']);
}

/** Campos activos que salen en el FORMULARIO de ese tipo de documento: [clave => campo]. */
function docs_form_fields(string $docType): array
{
    $g = DOC_GROUPS[$docType] ?? 'factura';
    return array_filter(docs_line_fields(), fn($f) => $f['on'] === 1 && $f['form'] === 1 && in_array($g, $f['docs'], true));
}

/** Campos propios (no de serie) activos en el formulario de ese tipo. */
function docs_form_custom(string $docType): array
{
    return array_filter(docs_form_fields($docType), fn($f) => $f['builtin'] === 0);
}

/** Valores de los campos propios de una línea: [clave => texto]. */
function docs_extra_get(array $line): array
{
    $j = json_decode((string)($line['extra'] ?? ''), true);
    return is_array($j) ? array_map('strval', $j) : [];
}

/**
 * Valores propios de una línea a partir del formulario: los campos activos en el formulario toman lo enviado (validado por tipo);
 * los demás conservan lo que ya tenía la línea guardada (así desactivar un campo no borra nada).
 */
function docs_extra_from_post(array $in, ?array $old, string $docType): string
{
    $keep = $old ? docs_extra_get($old) : [];
    $out = $keep;
    $form = docs_form_custom($docType);
    foreach ($form as $k => $f) {
        $v = trim(mb_substr(trim(strip_tags((string)(((array)($in['x'] ?? []))[$k] ?? ''))), 0, 60));
        if ($f['type'] === 'number' && $v !== '' && !preg_match('/^-?[0-9]+([.,][0-9]+)?$/', $v)) {
            $v = '';
        }
        if ($f['type'] === 'list' && $v !== '' && !in_array($v, $f['options'], true)) {
            $v = isset($keep[$k]) && $keep[$k] === $v ? $v : ''; // un valor que ya no está en la lista solo se conserva si ya lo tenía
        }
        if ($v === '') {
            unset($out[$k]);
        } else {
            $out[$k] = $v;
        }
    }
    $known = docs_line_fields();
    foreach (array_keys($out) as $k) {
        if (!isset($known[$k])) {
            unset($out[$k]);
        }
    }
    return $out ? (string)json_encode($out, JSON_UNESCAPED_UNICODE) : '';
}

/** Etiqueta de una columna de la tabla impresa (las de serie y las propias, «x:<clave>»). */
function doc_col_label(string $c): string
{
    if (str_starts_with($c, 'x:')) {
        return docs_line_fields()[substr($c, 2)]['name'] ?? 'Campo';
    }
    return DOC_COLUMNS[$c] ?? $c;
}

/**
 * Columnas de la hoja impresa de ese tipo de documento: las del diseño ($d['cols']) filtradas por la configuración de los campos
 * de las líneas, más los campos propios (detrás de la descripción). Un campo desactivado sigue saliendo si ESE documento ya tiene
 * algún valor (los documentos antiguos conservan lo suyo).
 * @param array $lines líneas del documento: de doc_lines (size_txt, color_txt, disc_bp, extra) o de erp_invoice_lines
 */
function doc_print_cols(array $d, string $docType, array $lines): array
{
    $g = DOC_GROUPS[$docType] ?? 'factura';
    $fields = docs_line_fields();
    $hasValue = function (string $k) use ($lines): bool {
        foreach ($lines as $l) {
            if ((int)($l['kind'] ?? 0) === 1) {
                continue;
            }
            $v = match ($k) {
                'size' => (string)($l['size_txt'] ?? ''),
                'color' => (string)($l['color_txt'] ?? ''),
                'disc' => (int)($l['disc_bp'] ?? 0) > 0 ? '1' : '',
                default => docs_extra_get($l)[$k] ?? '',
            };
            if ($v !== '') {
                return true;
            }
        }
        return false;
    };
    $show = function (string $k) use ($fields, $g, $hasValue): bool {
        $f = $fields[$k] ?? null;
        if (!$f) {
            return false;
        }
        if ($f['on'] === 1) {
            return $f['print'] === 1 && in_array($g, $f['docs'], true);
        }
        return $hasValue($k); // desactivado: solo si el documento ya traía valores
    };
    $cols = [];
    foreach ($d['cols'] as $c) {
        if (isset(DOC_LINE_BUILTIN[$c]) && !$show($c)) {
            continue;
        }
        $cols[] = $c;
        if ($c === 'desc') {
            foreach ($fields as $k => $f) {
                if ($f['builtin'] === 0 && $show($k)) {
                    $cols[] = 'x:' . $k;
                }
            }
        }
    }
    if (!in_array('desc', $cols, true)) { // el diseño no mostraba la descripción: los propios van al principio
        foreach ($fields as $k => $f) {
            if ($f['builtin'] === 0 && $show($k)) {
                array_unshift($cols, 'x:' . $k);
            }
        }
    }
    return $cols;
}

/** Texto de una columna propia («x:<clave>») para una línea. */
function doc_line_extra_cell(string $c, array $l): string
{
    return docs_extra_get($l)[substr($c, 2)] ?? '';
}

/* ---------- Modelos de impresión ---------- */

const DOC_MODELS = ['classic' => 'Clásico', 'modern' => 'Moderno', 'compact' => 'Compacto'];
const DOC_MODEL_DESC = [
    'classic' => 'El de siempre: cabecera sencilla y recuadro de documento con el color de la empresa.',
    'modern' => 'Franja con el color de la empresa y logotipo grande.',
    'compact' => 'Letra pequeña y menos espacio: caben más líneas por página.',
];

/** Modelo de un tipo de documento: el propio de ese tipo o, si no lo tiene, el general. ?modelo= (solo con sesión del panel) sirve para la vista previa. */
function doc_model_for(string $docType, bool $public = false): string
{
    $prev = (string)($_GET['modelo'] ?? '');
    if (!$public && $prev !== '' && isset(DOC_MODELS[$prev]) && !empty($_SESSION['uid'])) {
        return $prev;
    }
    $g = DOC_GROUPS[$docType] ?? 'factura';
    $own = (string)setting('doc_model_' . $g, '');
    if (isset(DOC_MODELS[$own])) {
        return $own;
    }
    $all = (string)setting('doc_model', 'classic');
    return isset(DOC_MODELS[$all]) ? $all : 'classic';
}

/** Capa de CSS del modelo (se añade DESPUÉS del CSS base de la plantilla; el clásico no cambia nada). */
function doc_model_css(string $model): string
{
    $base = '.lines th{overflow-wrap:normal;word-break:normal;overflow:hidden}'; // los títulos de columna no se parten por la mitad («TALL/A»)
    if ($model === 'modern') {
        return $base . '.m-modern .head{background:var(--doc-color);color:#fff;padding:7mm 8mm;border-radius:5px;align-items:center}'
            . '.m-modern .co img{max-height:calc(var(--logo-h) * 1.7);max-width:78mm;background:#fff;padding:5px 8px;border-radius:4px}'
            . '.m-modern .co h1{font-size:19px}.m-modern .co p{color:rgba(255,255,255,.88)}'
            . '.m-modern .box{border-color:#fff;background:rgba(255,255,255,.1)}.m-modern .box h2{background:#fff;color:var(--doc-color)}'
            . '.m-modern .box dt{color:rgba(255,255,255,.8)}.m-modern .box dd{color:#fff}'
            . '.m-modern .lines th{background:var(--doc-color);color:#fff;border-color:var(--doc-color)}'
            . '.m-modern .lines td{border-bottom-color:#e4e2dc}.m-modern .lines tbody tr:nth-child(even) td{background:#faf9f6}'
            . '.m-modern .total{background:#faf9f6}.m-modern .total .big{color:var(--doc-color)}'
            . '.m-modern .cust{border-left:5px solid var(--doc-color)}';
    }
    if ($model === 'compact') {
        return $base . '.m-compact{font-size:11px;line-height:1.3}.m-compact .head{gap:5mm}.m-compact .co h1{font-size:14px}.m-compact .co img{max-height:calc(var(--logo-h) * .7)}'
            . '.m-compact .box h2{padding:3px 8px;font-size:13px}.m-compact .box dl{padding:4px 8px;gap:1px 8px}'
            . '.m-compact .cust{margin:4mm 0 3mm;padding:5px 9px}.m-compact .cust strong{font-size:12px}'
            . '.m-compact .lines{font-size:10.5px}.m-compact .lines th{padding:3px 4px;font-size:9.5px}.m-compact .lines td{padding:2px 4px}'
            . '.m-compact .foot{margin-top:4mm;gap:5mm}.m-compact .vat,.m-compact .dues table{font-size:10.5px}.m-compact .total{padding:5px 9px}.m-compact .total .big{font-size:15px}'
            . '.m-compact .obs{margin-top:3mm;padding:5px 9px}.m-compact .sign{margin-top:7mm}.m-compact .legal{margin-top:5mm}';
    }
    return $base;
}

/** Miniatura SVG de un modelo (para elegirlo en Diseño de documentos). */
function doc_model_thumb(string $model): string
{
    $c = '#3b6fb6';
    $line = static function (int $y, int $w) { return '<rect x="12" y="' . $y . '" width="' . $w . '" height="2.4" rx="1" fill="#c9c6bf"/>'; };
    $svg = '<svg viewBox="0 0 120 160" width="120" height="160" role="img" aria-label="Miniatura del modelo ' . e(DOC_MODELS[$model]) . '"><rect x="0.5" y="0.5" width="119" height="159" rx="3" fill="#fff" stroke="#cfcdc6"/>';
    if ($model === 'modern') {
        $svg .= '<rect x="8" y="8" width="104" height="26" rx="3" fill="' . $c . '"/><rect x="12" y="12" width="26" height="14" rx="2" fill="#fff"/><rect x="74" y="13" width="34" height="5" rx="1" fill="#fff"/><rect x="74" y="21" width="34" height="3" rx="1" fill="#fff" opacity=".6"/>';
        $svg .= '<rect x="8" y="40" width="104" height="14" rx="2" fill="none" stroke="#cfcdc6"/><rect x="8" y="40" width="3" height="14" fill="' . $c . '"/>';
        $svg .= '<rect x="8" y="60" width="104" height="6" fill="' . $c . '"/>';
        for ($i = 0; $i < 6; $i++) {
            $svg .= ($i % 2 ? '<rect x="8" y="' . (68 + $i * 7) . '" width="104" height="6" fill="#f3f1ec"/>' : '') . $line(70 + $i * 7, 70 - ($i % 3) * 10);
        }
        $svg .= '<rect x="70" y="122" width="42" height="20" rx="2" fill="#f3f1ec" stroke="' . $c . '"/>';
    } elseif ($model === 'compact') {
        $svg .= '<rect x="10" y="9" width="18" height="8" rx="1" fill="' . $c . '"/><rect x="76" y="9" width="34" height="8" fill="' . $c . '"/>';
        $svg .= '<rect x="10" y="22" width="100" height="9" rx="1" fill="none" stroke="#cfcdc6"/><rect x="10" y="35" width="100" height="4" fill="#e6e3dc" stroke="' . $c . '" stroke-width=".6"/>';
        for ($i = 0; $i < 14; $i++) {
            $svg .= '<rect x="10" y="' . (42 + $i * 5.2) . '" width="' . (92 - ($i % 4) * 14) . '" height="1.8" rx=".8" fill="#c9c6bf"/>';
        }
        $svg .= '<rect x="74" y="120" width="36" height="14" rx="1" fill="none" stroke="' . $c . '"/>';
    } else {
        $svg .= '<rect x="12" y="12" width="22" height="12" rx="2" fill="' . $c . '" opacity=".85"/><rect x="74" y="12" width="34" height="5" fill="' . $c . '"/><rect x="74" y="17" width="34" height="9" fill="none" stroke="' . $c . '"/>';
        $svg .= '<rect x="12" y="34" width="96" height="16" rx="2" fill="none" stroke="#cfcdc6"/>';
        $svg .= '<rect x="12" y="58" width="96" height="6" fill="#f3f2ee" stroke="' . $c . '" stroke-width=".8"/>';
        for ($i = 0; $i < 6; $i++) {
            $svg .= $line(68 + $i * 8, 80 - ($i % 3) * 12);
        }
        $svg .= '<rect x="70" y="122" width="38" height="20" rx="2" fill="none" stroke="' . $c . '"/>';
    }
    return $svg . '</svg>';
}

/** Desde qué documento real se enseña cada modelo: [grupo => dirección de la vista de impresión] (o '' si no hay ninguno). */
function doc_model_preview_links(): array
{
    $out = ['pedido' => '', 'albaran' => '', 'factura' => ''];
    foreach (['pedido' => 'pedidos', 'albaran' => 'albaranes'] as $t => $path) {
        $st = db()->prepare('SELECT id FROM doc_headers WHERE doc_type = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$t]);
        $id = (int)$st->fetchColumn();
        if ($id > 0) {
            $out[$t] = '/admin/' . $path . '/' . $id . '/ver';
        }
    }
    $st = db()->query("SELECT id FROM doc_headers WHERE doc_type = 'factura' ORDER BY id DESC LIMIT 1");
    $id = (int)$st->fetchColumn();
    if ($id > 0) {
        $out['factura'] = '/admin/facturas/' . $id . '/ver';
    }
    return $out;
}

/* ---------- Pantalla Configuración → Diseño de documentos ---------- */

function admin_doc_design(): void
{
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $general = (string)($_POST['model'] ?? 'classic');
        if (!isset(DOC_MODELS[$general])) {
            $errors[] = 'Elige uno de los tres modelos.';
        }
        $own = [];
        foreach (DOC_GROUP_LABELS as $g => $_) {
            $m = (string)(((array)($_POST['model_g'] ?? []))[$g] ?? '');
            if ($m !== '' && !isset(DOC_MODELS[$m])) {
                $errors[] = 'Modelo no válido para ' . mb_strtolower(DOC_GROUP_LABELS[$g]) . '.';
            }
            $own[$g] = $m;
        }
        $color = trim((string)($_POST['color'] ?? ''));
        if (empty($_POST['color_on'])) {
            $color = '';
        } elseif (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $errors[] = 'El color no es válido.';
        }
        $font = (string)($_POST['font'] ?? 'sans');
        if (!isset(DOC_FONTS[$font])) {
            $errors[] = 'Tipo de letra no válido.';
        }
        $logoH = trim((string)($_POST['logo_h'] ?? '0'));
        if (!ctype_digit($logoH) || ((int)$logoH !== 0 && ((int)$logoH < 20 || (int)$logoH > 200))) {
            $errors[] = 'El alto del logotipo debe ser 0 (el de Empresa) o estar entre 20 y 200.';
        }
        $margin = trim((string)($_POST['margin_mm'] ?? '15'));
        if (!ctype_digit($margin) || (int)$margin < 5 || (int)$margin > 30) {
            $errors[] = 'Los márgenes deben estar entre 5 y 30 mm.';
        }
        if (!$errors) {
            save_setting('doc_model', $general);
            foreach ($own as $g => $m) {
                save_setting('doc_model_' . $g, $m);
            }
            save_setting('doc_color', $color);
            save_setting('doc_font', $font);
            save_setting('doc_logo_h', $logoH);
            save_setting('doc_margin_mm', $margin);
            save_setting('doc_footer', mb_substr(trim((string)($_POST['footer'] ?? '')), 0, 400));
            flash('Diseño de documentos guardado.');
            redirect('/admin/configuracion/diseno');
        }
    }
    admin_render('doc_design', ['errors' => $errors, 'old' => $errors ? $_POST : null, 'links' => doc_model_preview_links(),
        'cfg' => 'diseno', 'title' => 'Diseño de documentos']);
}

/**
 * CSS de la hoja A4 completa (pantalla e impresión), común a todas las plantillas de documentos y a los tres modelos:
 * cabecera arriba, líneas en medio y totales, desglose, vencimientos y pie (`.bottom`) abajo del todo. Al imprimir, la cabecera de
 * la tabla se repite en cada página y el margen de la hoja lleva el número del documento y «Página X de Y» (márgenes de página de CSS).
 * `$label` = «Factura W00001/26»: se limpia para ir dentro de una cadena CSS.
 * Una página: el pie baja con `margin-top:auto` (flex). Varias páginas: Chrome fragmenta mal los flex, así que `doc_sheet_js()` pone la
 * clase `multi` (bloque normal) y un hueco (`.fill`) calculado para que el pie quede abajo de la última página.
 */
function doc_sheet_css(string $label): string
{
    $label = preg_replace('/[^\p{L}\p{N} \/.\-~()]/u', '', $label) ?? '';
    return '.sheet{display:flex;flex-direction:column}.sheet>*{flex:none}.sheet>.bottom{margin-top:auto;padding-top:6mm}.fill{display:none}'
        . '@page{@top-left{content:"' . $label . '";font:9px sans-serif;color:#6b665d}@bottom-right{content:"Página " counter(page) " de " counter(pages);font:9px sans-serif;color:#6b665d}}'
        . '@media print{.sheet{min-height:calc(297mm - 2 * var(--doc-margin) - 1.5mm);width:auto}.bottom{break-inside:avoid}.toolbar{display:none!important}'
        . '.sheet.multi{display:block;min-height:0}.sheet.multi .fill{display:block}.sheet.multi>.bottom{margin-top:0}}';
}

/**
 * JavaScript opcional de impresión: antes de imprimir calcula cuántas páginas ocupa el documento (contando la cabecera de la tabla que
 * se repite y el hueco que dejan las filas que no se parten) y, si son varias, rellena con un hueco para que el pie quede abajo del
 * todo de la última página. Sin JavaScript todo sigue saliendo, pero con varias páginas el pie no queda pegado abajo. También «Cerrar».
 */
function doc_sheet_js(): string
{
    return "(function(){var s=document.querySelector('.sheet'),b=s&&s.querySelector('.bottom');if(!s||!b){return;}"
        . "var fill=document.createElement('div');fill.className='fill';s.insertBefore(fill,b);"
        . "function mm(n){var p=document.createElement('div');p.style.cssText='position:absolute;visibility:hidden;height:100mm';document.body.appendChild(p);var v=p.offsetHeight/100*n;document.body.removeChild(p);return v;}"
        . "function reset(){s.classList.remove('multi');fill.style.height='0';s.style.minHeight='';}"
        . "window.addEventListener('beforeprint',function(){reset();var m=parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--doc-margin'))||0;"
        . "s.style.minHeight='0';var cs=getComputedStyle(s),pad=parseFloat(cs.paddingTop)+parseFloat(cs.paddingBottom);var T=s.getBoundingClientRect().height-pad;s.style.minHeight='';"
        . "var pg=mm(297-2*m-1.5),th=s.querySelector('.lines thead'),hh=th?th.offsetHeight:0,rr=0;"
        . "s.querySelectorAll('.lines tbody tr').forEach(function(r){rr=Math.max(rr,r.offsetHeight);});"
        . "var p=Math.max(1,Math.ceil(T/pg)),need=T;for(var i=0;i<6;i++){need=T+(p-1)*(hh+rr);var q=Math.max(1,Math.ceil(need/pg));if(q===p){break;}p=q;}"
        . "if(p>1){s.classList.add('multi');fill.style.height=Math.max(0,p*pg-need-mm(2))+'px';}});"
        . "window.addEventListener('afterprint',reset);})();"
        . "(function(){var c=document.getElementById('close');if(!c){return;}c.addEventListener('click',function(e){"
        . "try{if(document.referrer&&new URL(document.referrer).origin===location.origin&&history.length>1){e.preventDefault();history.back();}}catch(x){}});})();";
}
