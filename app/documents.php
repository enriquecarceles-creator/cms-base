<?php
declare(strict_types=1);

/*
 * Documentos (de momento, la factura como documento impreso; servirá también para albaranes y presupuestos).
 *
 * El diseño no está fijo en el código: la plantilla vive en su propio archivo (app/views/documents/<documento>_<plantilla>.php)
 * y lee sus variables (colores, letra, logotipo, márgenes, columnas, textos) de ajustes `doc_*` con valores por defecto.
 * Los datos de la empresa salen de Web → Empresa; nada es específico de NCS. La pantalla para elegir plantilla y editar
 * estos ajustes es la tarea «Diseño de documentos» de la hoja de ruta.
 */

/** Valores por defecto de los ajustes de diseño (se guardan como doc_<clave> en settings). */
const DOC_DEFAULTS = [
    'template' => 'classic',          // archivo app/views/documents/invoice_<plantilla>.php
    'color' => '',                    // '' = color de la marca (Web → Empresa)
    'font' => 'sans',                 // sans | serif | mono
    'logo_h' => '0',                  // alto del logotipo en px; 0 = el de Empresa
    'margin_mm' => '15',              // márgenes de la hoja (5 a 30 mm)
    'cols' => 'code,desc,size,color,qty,price,disc,amount,iva', // columnas visibles y su orden
    'title' => 'FACTURA',
    'stamp' => 'ANULADA',
    'footer' => 'Copia informativa — factura original emitida por el programa de facturación.',
];

const DOC_FONTS = [
    'sans' => 'system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif',
    'serif' => 'Georgia,"Times New Roman",serif',
    'mono' => 'ui-monospace,Consolas,"Courier New",monospace',
];

/** Columnas posibles de la tabla de líneas: clave => etiqueta. */
const DOC_COLUMNS = ['code' => 'Código', 'desc' => 'Descripción', 'size' => 'Talla', 'color' => 'Color', 'qty' => 'Uds.', 'price' => 'Precio', 'disc' => 'Dto.', 'amount' => 'Importe', 'iva' => 'IVA'];

/** Ajustes de diseño ya validados, con sus valores por defecto. */
function doc_settings(): array
{
    $d = [];
    foreach (DOC_DEFAULTS as $k => $def) {
        $v = setting('doc_' . $k, $def);
        $d[$k] = $v;
    }
    $d['template'] = preg_match('/^[a-z]{3,20}$/', $d['template']) && is_file(ROOT . '/app/views/documents/invoice_' . $d['template'] . '.php') ? $d['template'] : 'classic';
    $d['color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $d['color']) ? $d['color'] : (preg_match('/^#[0-9a-fA-F]{6}$/', setting('brand_color', '')) ? setting('brand_color') : '#1c1b19');
    $d['font'] = isset(DOC_FONTS[$d['font']]) ? $d['font'] : 'sans';
    $d['font_css'] = DOC_FONTS[$d['font']];
    $d['logo_h'] = (int)$d['logo_h'] >= 20 && (int)$d['logo_h'] <= 200 ? (int)$d['logo_h'] : max(20, min(200, (int)setting('logo_height', '48')));
    $d['margin_mm'] = max(5, min(30, (int)$d['margin_mm']));
    $cols = array_values(array_unique(array_filter(array_map('trim', explode(',', $d['cols'])), fn($c) => isset(DOC_COLUMNS[$c]))));
    $d['cols'] = $cols ?: array_keys(DOC_COLUMNS);
    $d['title'] = mb_substr(trim($d['title']) !== '' ? $d['title'] : DOC_DEFAULTS['title'], 0, 30);
    $d['stamp'] = mb_substr(trim($d['stamp']) !== '' ? $d['stamp'] : DOC_DEFAULTS['stamp'], 0, 20);
    $d['footer'] = mb_substr($d['footer'], 0, 400);
    return $d;
}

/** Datos de la empresa para la cabecera del documento (Web → Empresa). */
function doc_company(): array
{
    return [
        'legal' => setting('company_name'), 'trade' => setting('trade_name'), 'tax_id' => setting('tax_id'),
        'address' => setting('address'), 'phone' => setting('phone'), 'email' => setting('email'), 'logo' => brand_logo(),
    ];
}

/** Serie de una factura, parametrizable (Sincronizar con NCS → Documentos): letras iniciales del número, ninguna o los N primeros caracteres. */
function erp_serie(string $number): string
{
    $mode = setting('erp_serie_mode', 'letras');
    if ($mode === 'ninguna') {
        return '';
    }
    if ($mode === 'n') {
        return mb_substr($number, 0, max(1, min(6, (int)setting('erp_serie_n', '1'))));
    }
    return preg_match('/^[A-Za-z]+/', $number, $m) ? $m[0] : '';
}

/** Nombres de las formas de pago por código (lista editable): [código => nombre]. */
function erp_pay_names(): array
{
    static $cache = null;
    if ($cache === null) {
        $j = json_decode(setting('erp_pay_names'), true); // lista antigua (Sincronizar con NCS): sigue valiendo como respaldo
        $cache = is_array($j) ? array_map('strval', $j) : [];
        try { // la tabla de Configuración → Formas de pago manda
            foreach (db()->query("SELECT code, name FROM doc_pay_methods WHERE name <> ''")->fetchAll() as $r) {
                $cache[(string)$r['code']] = (string)$r['name'];
            }
        } catch (Throwable $e) {
        }
    }
    return $cache;
}

/** «Transferencia (03)» si hay nombre; «F. pago 03» si solo hay código; '' si no hay nada. */
function erp_pay_label(string $code): string
{
    $code = trim($code);
    if ($code === '') {
        return '';
    }
    $n = erp_pay_names()[$code] ?? '';
    return $n !== '' ? $n . ' (' . $code . ')' : 'F. pago ' . $code;
}

/** Desglose de IVA de una factura (de vat_json; para facturas antiguas sin él, una fila con la base y la cuota totales). */
function erp_invoice_vat(array $inv): array
{
    $rows = json_decode((string)($inv['vat_json'] ?? ''), true);
    if (is_array($rows) && $rows) {
        return $rows;
    }
    if ((int)$inv['base_cents'] !== 0 || (int)$inv['vat_cents'] !== 0) {
        return [['i' => 0, 'pct' => (int)$inv['base_cents'] !== 0 ? round((int)$inv['vat_cents'] / (int)$inv['base_cents'] * 100) : null, 'base' => (int)$inv['base_cents'], 'cuota' => (int)$inv['vat_cents'], 'rec' => 0, 'ret' => 0]];
    }
    return [];
}

/** Factura anterior y siguiente por fecha y número interno. */
function erp_invoice_neighbours(array $inv): array
{
    $db = db();
    $res = [];
    foreach (['prev' => ['<', 'DESC'], 'next' => ['>', 'ASC']] as $k => [$op, $dir]) {
        $st = $db->prepare('SELECT inv_number FROM erp_invoices WHERE (inv_day ' . $op . ' ? OR (inv_day = ? AND id ' . $op . ' ?)) ORDER BY inv_day ' . $dir . ', id ' . $dir . ' LIMIT 1');
        $st->execute([(int)$inv['inv_day'], (int)$inv['inv_day'], (int)$inv['id']]);
        $res[$k] = $st->fetchColumn() ?: null;
    }
    return $res;
}

/** Datos de la factura como documento (null si no existe). Sin tocar la sesión: lo usa también la batería de pruebas. */
function erp_invoice_doc_vars(string $num): ?array
{
    $db = db();
    $st = $db->prepare('SELECT * FROM erp_invoices WHERE inv_number = ?');
    $st->execute([$num]);
    $inv = $st->fetch();
    if (!$inv) {
        return null;
    }
    $st = $db->prepare('SELECT * FROM erp_invoice_lines WHERE invoice_id = ? ORDER BY line_no, id');
    $st->execute([(int)$inv['id']]);
    $lines = $st->fetchAll();
    $cust = erp_invoice_customer($inv);
    $vat = erp_invoice_vat($inv);
    $withBase = array_values(array_filter($vat, fn($r) => (int)$r['base'] !== 0));
    $d = doc_settings();
    return [
        'inv' => $inv, 'lines' => $lines, 'cust' => $cust, 'isRetail' => (string)$inv['origin'] === 'cms' ? $cust === null : erp_retail_code() === (string)$inv['cust_code'], 'docx' => erp_invoice_cms_doc($inv),
        'vat' => $vat, 'lineRate' => count($withBase) === 1 && $withBase[0]['pct'] !== null ? (int)$withBase[0]['pct'] : null,
        'serie' => erp_serie((string)$inv['inv_number']), 'payLabel' => erp_pay_label((string)$inv['pay_code']),
        'map' => erp_family_map(), 'nb' => erp_invoice_neighbours($inv), 'd' => $d, 'co' => doc_company(),
        'title' => 'Factura ' . $inv['inv_number'] . ' · ' . site_name(),
    ];
}

/** Visor de la factura como documento impreso (/admin/ventas/<número>/ver). Página aparte: hoja A4 sobre fondo gris. El acceso ya está comprobado. */
function admin_invoice_view(string $num): void
{
    $vars = erp_invoice_doc_vars($num);
    if ($vars === null) {
        not_found();
        return;
    }
    render('documents/invoice_' . $vars['d']['template'], $vars, null);
}

/** Descripción de una línea (de doc_lines o de erp_invoice_lines) en HTML: el título y, debajo, el detalle con sus saltos de línea. Todo escapado. */
function doc_line_html(array $l): string
{
    $detail = (string)($l['detail'] ?? '');
    return e((string)$l['title']) . ($detail !== '' ? '<br>' . nl2br(e($detail), false) : '');
}
