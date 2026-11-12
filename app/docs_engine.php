<?php
declare(strict_types=1);

/*
 * Motor de documentos propios del CMS (pedidos y albaranes; preparado para facturas y rectificativas).
 *
 * Un solo formulario tipo ERP para todos los tipos: cabecera (serie, fecha, cliente, forma de pago, tarifa, IVA incluido,
 * recargo de equivalencia), rejilla de líneas y totales con desglose de IVA. Funciona sin JavaScript (botón «Recalcular»: el
 * servidor completa artículos, precios y totales); con JavaScript, sugerencias de artículos y clientes y totales al momento.
 *
 * Tablas (SCHEMA_VERSION 29, en DB_TABLES: van siempre en las copias de seguridad, aunque lleven datos de clientes):
 *   doc_headers  un documento. Copia los datos del cliente tal como estaban (el documento no cambia si cambia la ficha).
 *   doc_lines    sus líneas. src_line_id = línea de origen (albarán ← pedido); done_u = unidades ya servidas de esa línea.
 *   doc_events   historial de cada documento.
 *
 * Escalas (enteros, sin coma flotante en lo guardado): unidades × 10.000 (qty_u, done_u), precio × 1.000.000 (price_u),
 * importe de línea × 10.000 (amount_u), totales en céntimos. Descuento e IVA en centésimas de por ciento (1000 = 10 %).
 *
 * Flujo: Pedido → «Servir» crea un albarán con lo pendiente (se puede servir menos) → (fase 2) Albarán → Factura.
 * Estados: pedido pendiente | parcial | servido | facturado (todos sus albaranes facturados) | anulado (se calcula con lo servido y facturado); albarán pendiente | facturado | anulado.
 * Numeración: series_next_number() en la misma transacción que crea el documento (nunca repetidos). Los documentos no se
 * borran: se anulan (conservan su número). Bloqueo de periodos: no se crea, modifica ni anula nada con fecha bloqueada.
 */

require_once __DIR__ . '/docs_dues.php';
require_once __DIR__ . '/doc_fields.php';

const DOCS_QTY = 10000;        // unidades × 10.000
const DOCS_PRICE = 1000000;    // precio × 1.000.000
const DOCS_AMOUNT = 10000;     // importe de línea × 10.000
const DOCS_PER_PAGE = 50;
const DOCS_CONFLICT_MSG = 'Otro usuario ha modificado este documento mientras lo editabas. Recarga la página para ver sus cambios (no se ha guardado nada tuyo).';
const DOCS_BLANK_LINES = 3;    // líneas vacías al final del formulario
const DOCS_MORE_LINES = 5;     // «Añadir líneas»
const DOCS_MAX_LINES = 300;

/** Tipos con formulario. Cada uno: etiqueta, plural, dirección, título impreso y texto del botón de nuevo. Las tres clases de factura comparten /admin/facturas. */
const DOCS_KINDS = [
    'pedido' => ['label' => 'Pedido', 'plural' => 'Pedidos', 'path' => '/admin/pedidos', 'print' => 'PEDIDO', 'new' => 'Nuevo pedido'],
    'albaran' => ['label' => 'Albarán', 'plural' => 'Albaranes', 'path' => '/admin/albaranes', 'print' => 'ALBARÁN', 'new' => 'Nuevo albarán'],
    'factura' => ['label' => 'Factura', 'plural' => 'Facturas', 'path' => '/admin/facturas', 'print' => 'FACTURA', 'new' => 'Nueva factura'],
    'simplificada' => ['label' => 'Factura simplificada', 'plural' => 'Facturas simplificadas', 'path' => '/admin/facturas', 'print' => 'FACTURA SIMPLIFICADA', 'new' => 'Nuevo tique'],
    'rectificativa' => ['label' => 'Factura rectificativa', 'plural' => 'Facturas rectificativas', 'path' => '/admin/facturas', 'print' => 'FACTURA RECTIFICATIVA', 'new' => 'Nueva rectificativa'],
];
const DOCS_PAGE_KIND = ['pedidos' => 'pedido', 'albaranes' => 'albaran', 'facturas' => 'factura'];

/** Documentos de COMPRA (mismo motor, mismas tablas): llevan _c. El «cliente» del documento es el proveedor (erp_suppliers). */
const DOCS_KINDS_PURCHASE = [
    'pedido_c' => ['label' => 'Pedido a proveedor', 'plural' => 'Pedidos a proveedor', 'path' => '/admin/compras/pedidos', 'print' => 'PEDIDO A PROVEEDOR', 'new' => 'Nuevo pedido'],
    'albaran_c' => ['label' => 'Albarán de compra', 'plural' => 'Albaranes de compra', 'path' => '/admin/compras/albaranes', 'print' => 'ALBARÁN DE COMPRA', 'new' => 'Nuevo albarán'],
    'factura_c' => ['label' => 'Factura recibida', 'plural' => 'Facturas recibidas', 'path' => '/admin/compras/facturas', 'print' => 'FACTURA RECIBIDA', 'new' => 'Nueva factura recibida'],
    'rectificativa_c' => ['label' => 'Rectificativa recibida', 'plural' => 'Rectificativas recibidas', 'path' => '/admin/compras/facturas', 'print' => 'RECTIFICATIVA RECIBIDA', 'new' => 'Nueva rectificativa'],
];
const DOCS_PAGE_KIND_PURCHASE = ['pedidos' => 'pedido_c', 'albaranes' => 'albaran_c', 'facturas' => 'factura_c'];
const DOCS_PURCHASE_INVOICE_STATUS = ['borrador' => 'Borrador', 'emitida' => 'Cerrada'];

const DOCS_INVOICE_STATUS = ['borrador' => 'Borrador', 'emitida' => 'Emitida'];
const DOCS_STATUS = [
    'pedido' => ['borrador' => 'Borrador', 'pendiente' => 'Pendiente', 'parcial' => 'Parcialmente servido', 'servido' => 'Servido', 'facturado' => 'Facturado', 'anulado' => 'Anulado'],
    'albaran' => ['borrador' => 'Borrador', 'pendiente' => 'Pendiente de facturar', 'facturado' => 'Facturado', 'anulado' => 'Anulado'],
    'factura' => DOCS_INVOICE_STATUS, 'simplificada' => DOCS_INVOICE_STATUS, 'rectificativa' => DOCS_INVOICE_STATUS,
    'pedido_c' => ['borrador' => 'Borrador', 'pendiente' => 'Pendiente', 'parcial' => 'Parcialmente recibido', 'servido' => 'Recibido', 'facturado' => 'Facturado', 'anulado' => 'Anulado'],
    'albaran_c' => ['borrador' => 'Borrador', 'pendiente' => 'Pendiente de facturar', 'facturado' => 'Facturado', 'anulado' => 'Anulado'],
    'factura_c' => DOCS_PURCHASE_INVOICE_STATUS, 'rectificativa_c' => DOCS_PURCHASE_INVOICE_STATUS,
];

const DOCS_EVENT_LABELS = [
    'creado' => 'Creado', 'modificado' => 'Modificado', 'servido' => 'Servido', 'anulado' => 'Anulado', 'desde' => 'Creado desde',
    'cerrada' => 'Cerrada (registro asignado)', 'pagado' => 'Pago anotado', 'despagado' => 'Pago deshecho',
    'emitida' => 'Emitida', 'facturado' => 'Facturado', 'rectificada' => 'Rectificada', 'reservado' => 'Reservado en un borrador',
    'liberado' => 'Liberado (borrador borrado)', 'enviado' => 'Enviado por email', 'confirmado' => 'Confirmado (número asignado)', 'envio_error' => 'Error al enviar por email',
];

/** Motivos de rectificación (art. 80 de la Ley del IVA) con su clave del SII. */
const DOCS_RECT_REASONS = [
    'R1' => 'R1 · Error fundado en derecho o causas del art. 80 Uno, Dos y Seis LIVA (devoluciones, descuentos, anulaciones…)',
    'R2' => 'R2 · Concurso de acreedores del cliente (art. 80 Tres)',
    'R3' => 'R3 · Créditos incobrables (art. 80 Cuatro)',
    'R4' => 'R4 · Resto de causas (errores en la factura…)',
    'R5' => 'R5 · Rectificación de una factura simplificada',
];
const DOCS_RECT_MODES = ['diferencias' => 'Por diferencias (solo lo que cambia)', 'sustitucion' => 'Por sustitución (sustituye a la factura entera)'];
const DOCS_TIQUE_LIMIT = 40000;          // 400 € IVA incluido (céntimos)
const DOCS_TIQUE_LIMIT_RETAIL = 300000;  // 3.000 € en comercio minorista

function docs_is_invoice(string $kind): bool
{
    return in_array($kind, DOC_INVOICE_TYPES, true) || in_array($kind, ['factura_c', 'rectificativa_c'], true);
}

/** ¿Es un documento de compra (pedido a proveedor, albarán de compra, factura recibida)? */
function docs_is_purchase(string $kind): bool
{
    return str_ends_with($kind, '_c');
}

/** Tipo base sin el sufijo de compra: pedido_c → pedido. */
function docs_base(string $kind): string
{
    return docs_is_purchase($kind) ? substr($kind, 0, -2) : $kind;
}

/** El tipo «$base» del mismo lado (ventas o compras) que $kind: docs_side('pedido_c', 'albaran') → albaran_c. */
function docs_side(string $kind, string $base): string
{
    return docs_is_purchase($kind) ? $base . '_c' : $base;
}

/** Datos del tipo (etiquetas, dirección…), de ventas o de compras. */
function docs_kind(string $kind): array
{
    return DOCS_KINDS[$kind] ?? DOCS_KINDS_PURCHASE[$kind];
}

/* ---------- Números ---------- */

/** «1.234,56», «12,5», «12.5», «3» → número; null si está vacío o no es un número. */
function docs_parse_num(string $s): ?float
{
    $n = preg_replace('/[^\d,.\-]/', '', trim($s)) ?? '';
    if ($n === '' || $n === '-') {
        return null;
    }
    if (str_contains($n, ',') && str_contains($n, '.')) {
        $n = strrpos($n, ',') > strrpos($n, '.') ? str_replace(['.', ','], ['', '.'], $n) : str_replace(',', '', $n);
    } else {
        $n = str_replace(',', '.', $n);
    }
    return is_numeric($n) ? (float)$n : null;
}

/** Entero escalado → texto con $dec decimales («1.234,50»). */
function docs_fmt(int $v, int $scale, int $dec): string
{
    return number_format($v / $scale, $dec, ',', '.');
}

function docs_dec(string $k): int
{
    $max = ['dec_qty' => 4, 'dec_price' => 6, 'dec_amount' => 4][$k];
    return max(0, min($max, docp_int($k)));
}

function docs_qty_txt(int $qtyU): string
{
    return docs_fmt($qtyU, DOCS_QTY, docs_dec('dec_qty'));
}

function docs_price_txt(int $priceU): string
{
    return docs_fmt($priceU, DOCS_PRICE, docs_dec('dec_price'));
}

function docs_amount_txt(int $amountU): string
{
    return docs_fmt($amountU, DOCS_AMOUNT, docs_dec('dec_amount')) . ' €';
}

/** Redondea un número a $dec decimales y lo pasa a la escala dada. */
function docs_scale(float $v, int $dec, int $scale): int
{
    return (int)round(round($v, $dec) * $scale);
}

/* ---------- Catálogo: artículos, precio por tarifa, tallas, colores y stock ---------- */

/** Campo de catálogo por nombre (sin mayúsculas ni acentos), p. ej. ['stock']. */
function docs_field_named(array $names, ?array $kinds = null): ?array
{
    foreach (fields_all() as $f) {
        if (in_array(import_norm_simple((string)$f['name']), $names, true) && ($kinds === null || in_array($f['kind'], $kinds, true))) {
            return $f;
        }
    }
    return null;
}

/** Campos de tipo precio (para elegir el de cada tarifa). */
function docs_price_fields(): array
{
    return array_values(array_filter(fields_all(), fn($f) => $f['kind'] === 'price'));
}

/** Campo de precio de una tarifa: el elegido en Configuración → Tarifas o, si no, el precio público (o el primer campo de precio). */
function docs_tariff_field(int $tariffNum): ?array
{
    static $cache = [];
    if (!array_key_exists($tariffNum, $cache)) {
        $fid = 0;
        try {
            $st = db()->prepare('SELECT price_field FROM doc_tariffs WHERE num = ?');
            $st->execute([$tariffNum]);
            $fid = (int)$st->fetchColumn();
        } catch (Throwable $e) {
        }
        $all = fields_all();
        $f = $fid > 0 && isset($all[$fid]) && $all[$fid]['kind'] === 'price' ? $all[$fid] : (field_price_public() ?? (docs_price_fields()[0] ?? null));
        $cache[$tariffNum] = $f;
    }
    return $cache[$tariffNum];
}

/** Campo del catálogo con el precio de compra (coste), si existe: se busca por nombre. */
function docs_cost_field(): ?array
{
    return docs_field_named(['coste', 'costo', 'precio compra', 'precio de compra', 'precio coste', 'pcompra'], ['price', 'number']);
}

/** Precio de un artículo en un documento: el de la tarifa en ventas; el de compra (campo «Coste») en compras, o null si no lo hay. */
function docs_price_for(string $kind, int $pid, int $tariffNum): ?int
{
    if (!docs_is_purchase($kind)) {
        return docs_product_price($pid, $tariffNum);
    }
    $f = docs_cost_field();
    if (!$f) {
        return null;
    }
    $v = field_values_for((int)$f['id'], [$pid])[$pid] ?? '';
    $n = $v !== '' ? docs_parse_num($v) : null;
    return $n === null ? null : (int)round($n * DOCS_PRICE);
}

/** Artículo por referencia exacta (sin distinguir mayúsculas). */
function docs_product_by_ref(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    $st = db()->prepare('SELECT id, ref, name, visible FROM products WHERE LOWER(ref) = LOWER(?) ORDER BY id LIMIT 1');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}

/** Precio del artículo en la tarifa (× DOCS_PRICE) o null si no tiene. */
function docs_product_price(int $pid, int $tariffNum): ?int
{
    $f = docs_tariff_field($tariffNum);
    if (!$f) {
        return null;
    }
    $v = field_values_for((int)$f['id'], [$pid])[$pid] ?? '';
    $n = $v !== '' ? docs_parse_num($v) : null;
    return $n === null ? null : (int)round($n * DOCS_PRICE);
}

/** Lista de valores de un campo de texto: «35, 36» / «35|36» / «35  36  37» (huecos de ancho fijo) → ['35', '36', '37']. */
function docs_split_values(string $v): array
{
    $v = trim($v);
    if ($v === '') {
        return [];
    }
    $parts = preg_match('/[,;|]/', $v) ? preg_split('/\s*[,;|]\s*/u', $v) : preg_split('/\s{2,}/u', $v);
    if (count($parts) === 1 && preg_match('/^[\d.,\/ ]+$/', $v)) { // tallas numéricas separadas por un espacio
        $parts = preg_split('/\s+/', $v);
    }
    return array_slice(array_values(array_unique(array_filter(array_map('trim', $parts), 'strlen'))), 0, 60);
}

/** Tallas y colores posibles del artículo (de los campos «Tallas»/«Talla» y «Color»/«Colores»). */
function docs_product_options(int $pid): array
{
    $out = ['sizes' => [], 'colors' => []];
    foreach (['sizes' => ['tallas', 'talla'], 'colors' => ['color', 'colores']] as $k => $names) {
        $f = docs_field_named($names);
        if ($f) {
            $out[$k] = docs_split_values((string)(field_values_for((int)$f['id'], [$pid])[$pid] ?? ''));
        }
    }
    return $out;
}

/** Stock y stock mínimo del artículo (de los campos «Stock» y «Stock mínimo»), null si no hay campo o valor. */
function docs_product_stock(int $pid): array
{
    $out = ['stock' => null, 'min' => null];
    foreach (['stock' => ['stock', 'existencias'], 'min' => ['stock minimo', 'stock min', 'minimo']] as $k => $names) {
        $f = docs_field_named($names, ['number', 'price', 'text']);
        if ($f) {
            $v = (string)(field_values_for((int)$f['id'], [$pid])[$pid] ?? '');
            $out[$k] = $v !== '' ? docs_parse_num($v) : null;
        }
    }
    return $out;
}

/* ---------- Clientes ---------- */

function docs_customer(string $code): ?array
{
    if (trim($code) === '') {
        return null;
    }
    $st = db()->prepare('SELECT * FROM erp_customers WHERE cust_code = ?');
    $st->execute([trim($code)]);
    return $st->fetch() ?: null;
}

/** Proveedor por código (erp_suppliers), con la misma forma que un cliente (cust_code = su código) para reutilizar el formulario. */
function docs_supplier(string $code): ?array
{
    if (trim($code) === '') {
        return null;
    }
    $st = db()->prepare('SELECT s.*, s.sup_code AS cust_code FROM erp_suppliers s WHERE s.sup_code = ?');
    $st->execute([trim($code)]);
    return $st->fetch() ?: null;
}

/** El cliente o el proveedor de un documento, según su tipo. */
function docs_party(string $code, string $kind): ?array
{
    return docs_is_purchase($kind) ? docs_supplier($code) : docs_customer($code);
}

/** Copia de los datos del cliente para el documento. */
function docs_customer_snapshot(array $c): array
{
    return [
        'cust_name' => $c['trade_name'] !== '' && $c['name'] === '' ? $c['trade_name'] : $c['name'],
        'cust_nif' => $c['nif'], 'cust_address' => $c['address'], 'cust_postcode' => $c['postcode'], 'cust_city' => $c['city'],
        'cust_province' => $c['province'], 'cust_email' => $c['email'],
    ];
}

/* ---------- Carga ---------- */

function docs_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM doc_headers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function docs_lines(int $id): array
{
    $st = db()->prepare('SELECT * FROM doc_lines WHERE doc_id = ? ORDER BY line_no, id');
    $st->execute([$id]);
    return $st->fetchAll();
}

function docs_status_label(array $doc): string
{
    return DOCS_STATUS[$doc['doc_type']][$doc['status']] ?? (string)$doc['status'];
}

/** Documento nuevo con los valores por defecto (Configuración → Parámetros). */
function docs_blank(string $kind): array
{
    $series = series_default_for($kind);
    return [
        'id' => 0, 'doc_type' => $kind, 'series_id' => $series ? (int)$series['id'] : 0, 'yr' => 0, 'doc_no' => 0, 'doc_code' => '',
        'doc_day' => (int)date('Ymd'), 'status' => 'borrador', 'cust_code' => '', 'cust_name' => '', 'cust_nif' => '', 'cust_address' => '',
        'cust_postcode' => '', 'cust_city' => '', 'cust_province' => '', 'cust_email' => '', 'pay_code' => docp('pay_method'),
        'tariff' => docp_int('tariff'), 'vat_incl' => docp_bool('vat_incl') && !docs_is_purchase($kind) ? 1 : 0, // en compras, por defecto sin IVA incluido
        'with_re' => 0, 'ref_text' => '', 'notes' => '',
        'base_cents' => 0, 'vat_cents' => 0, 'rec_cents' => 0, 'total_cents' => 0, 'vat_json' => '[]', 'origin_id' => 0,
        'created_at' => 0, 'created_by' => '', 'updated_at' => 0, 'updated_by' => '',
        'issued_at' => 0, 'issued_by' => '', 'op_day' => 0, 'rect_of' => 0, 'rect_mode' => '', 'rect_reason' => '', 'rect_text' => '',
        'sii_type' => '', 'vat_note' => '', 'cash_crit' => docp_bool('cash_criterion') ? 1 : 0, 'billed_by' => 0, 'reg_hash' => '', 'rev' => 0, 'dues_key' => '', 'sup_ref' => '', 'is_test' => 0,
    ];
}

/** Serie por defecto (activa) de un tipo. */
function series_default_for(string $type): ?array
{
    $first = null;
    foreach (series_all() as $s) {
        if ($s['doc_type'] !== $type || (int)$s['is_active'] !== 1) {
            continue;
        }
        if ((int)$s['is_default'] === 1) {
            return $s;
        }
        $first ??= $s;
    }
    return $first;
}

/* ---------- Cálculo ---------- */

/** Tipos de IVA activos por id (y el de por defecto). */
function docs_vat_rates(): array
{
    $out = [];
    foreach (vat_all() as $r) {
        if ((int)$r['is_active'] === 1) {
            $out[(int)$r['id']] = $r;
        }
    }
    return $out;
}

function docs_vat_default(): int
{
    foreach (docs_vat_rates() as $id => $r) {
        if ((int)$r['is_default'] === 1) {
            return $id;
        }
    }
    return (int)array_key_first(docs_vat_rates());
}

/** Importe de una línea (× DOCS_AMOUNT): unidades × precio − descuento, redondeado a los decimales de importes. */
function docs_line_amount(int $qtyU, int $priceU, int $discBp): int
{
    $v = ($qtyU / DOCS_QTY) * ($priceU / DOCS_PRICE) * (1 - $discBp / 10000);
    return docs_scale($v, docs_dec('dec_amount'), DOCS_AMOUNT);
}

/**
 * Totales del documento con desglose por tipo (IVA + recargo). Se suman las líneas de cada tipo y se redondea por tipo, en
 * céntimos. Con IVA incluido, el importe de las líneas ya lleva IVA (y recargo): base = suma / (1 + IVA + recargo).
 * Devuelve ['vat' => [['pct' => %, 'rec_pct' => %, 'base', 'cuota', 'rec', 'ret' => 0]], 'base', 'vat_cents', 'rec', 'total'] (céntimos).
 */
function docs_totals(array $lines, bool $vatIncl, bool $withRe): array
{
    $groups = [];
    foreach ($lines as $l) {
        if (!empty($l['kind'])) { // línea de texto libre: no suma
            continue;
        }
        $rec = $withRe ? (int)$l['rec_bp'] : 0;
        $k = (int)$l['vat_bp'] . '|' . $rec;
        $groups[$k] ??= ['vat_bp' => (int)$l['vat_bp'], 'rec_bp' => $rec, 'sum' => 0];
        $groups[$k]['sum'] += (int)$l['amount_u'];
    }
    krsort($groups, SORT_NATURAL);
    $rows = [];
    $tb = $tv = $tr = 0;
    foreach ($groups as $g) {
        $g_cents = (int)round($g['sum'] / (DOCS_AMOUNT / 100));
        if ($vatIncl) {
            $base = (int)round($g_cents * 10000 / (10000 + $g['vat_bp'] + $g['rec_bp']));
            $cuota = (int)round($base * $g['vat_bp'] / 10000);
            $rec = $g_cents - $base - $cuota;
            if ($g['rec_bp'] === 0) {
                $cuota += $rec;
                $rec = 0;
            }
        } else {
            $base = $g_cents;
            $cuota = (int)round($base * $g['vat_bp'] / 10000);
            $rec = (int)round($base * $g['rec_bp'] / 10000);
        }
        $rows[] = ['pct' => $g['vat_bp'] / 100, 'rec_pct' => $g['rec_bp'] / 100, 'base' => $base, 'cuota' => $cuota, 'rec' => $rec, 'ret' => 0];
        $tb += $base;
        $tv += $cuota;
        $tr += $rec;
    }
    return ['vat' => $rows, 'base' => $tb, 'vat_cents' => $tv, 'rec' => $tr, 'total' => $tb + $tv + $tr];
}

/* ---------- Progreso de pedidos (lo servido) ---------- */

/** Unidades servidas de cada línea de pedido (albaranes no anulados), sin contar el documento $exceptDoc. */
function docs_served_by_line(array $lineIds, int $exceptDoc = 0, string $albType = 'albaran'): array
{
    $lineIds = array_values(array_filter(array_map('intval', $lineIds)));
    if (!$lineIds) {
        return [];
    }
    $out = [];
    foreach (array_chunk($lineIds, 500) as $chunk) {
        $st = db()->prepare("SELECT l.src_line_id, SUM(l.qty_u) AS q FROM doc_lines l JOIN doc_headers h ON h.id = l.doc_id
            WHERE h.doc_type = ? AND h.status <> 'anulado' AND h.id <> ? AND l.src_line_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
            GROUP BY l.src_line_id');
        $st->execute([$albType, $exceptDoc, ...$chunk]);
        foreach ($st->fetchAll() as $r) {
            $out[(int)$r['src_line_id']] = (int)$r['q'];
        }
    }
    return $out;
}

/** Recalcula lo servido de las líneas y el estado de los pedidos dados. Llamar dentro de la transacción. */
function docs_refresh_orders(array $orderIds): void
{
    $db = db();
    foreach (array_unique(array_filter(array_map('intval', $orderIds))) as $oid) {
        $doc = docs_get($oid);
        if (!$doc || docs_base((string)$doc['doc_type']) !== 'pedido' || in_array($doc['status'], ['anulado', 'borrador'], true)) {
            continue;
        }
        $albType = docs_side((string)$doc['doc_type'], 'albaran');
        $lines = docs_lines($oid);
        $served = docs_served_by_line(array_column($lines, 'id'), 0, $albType);
        $total = $done = 0;
        foreach ($lines as $l) {
            $d = min((int)$l['qty_u'], $served[(int)$l['id']] ?? 0);
            if ((int)$l['done_u'] !== ($served[(int)$l['id']] ?? 0)) {
                $db->prepare('UPDATE doc_lines SET done_u = ? WHERE id = ?')->execute([$served[(int)$l['id']] ?? 0, (int)$l['id']]);
            }
            $total += (int)$l['qty_u'];
            $done += $d;
        }
        $status = $done <= 0 ? 'pendiente' : ($done >= $total ? 'servido' : 'parcial');
        if ($status === 'servido') { // facturado = servido entero y ningún albarán suyo pendiente de facturar
            $st = $db->prepare("SELECT COUNT(DISTINCT h.id) FROM doc_lines l JOIN doc_lines o ON o.id = l.src_line_id JOIN doc_headers h ON h.id = l.doc_id
                WHERE o.doc_id = ? AND h.doc_type = ? AND h.status = 'pendiente'");
            $st->execute([$oid, $albType]);
            if ((int)$st->fetchColumn() === 0) {
                $status = 'facturado';
            }
        }
        if ($status !== $doc['status']) {
            $db->prepare('UPDATE doc_headers SET status = ? WHERE id = ?')->execute([$status, $oid]);
        }
    }
}

/** Pedidos de los que vienen las líneas de un documento. */
function docs_source_orders(int $docId): array
{
    $st = db()->prepare('SELECT DISTINCT o.doc_id FROM doc_lines l JOIN doc_lines o ON o.id = l.src_line_id WHERE l.doc_id = ? AND l.src_line_id > 0');
    $st->execute([$docId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/* ---------- Historial ---------- */

function docs_event(int $docId, string $kind, string $detail = ''): void
{
    $uid = (int)($_SESSION['uid'] ?? 0);
    $user = $uid > 0 ? (string)(current_user()['username'] ?? '') : '';
    db()->prepare('INSERT INTO doc_events (doc_id, kind, detail, user_id, username, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$docId, mb_substr($kind, 0, 40), mb_substr($detail, 0, 250), $uid, mb_substr($user, 0, 100), time()]);
}

function docs_events(int $docId): array
{
    $st = db()->prepare('SELECT * FROM doc_events WHERE doc_id = ? ORDER BY created_at DESC, id DESC');
    $st->execute([$docId]);
    return $st->fetchAll();
}

/* ---------- Reglas de edición ---------- */

/** Motivo por el que el documento no se puede modificar ('' = se puede). */
function docs_locked_reason(array $doc): string
{
    if ((int)$doc['id'] === 0) {
        return '';
    }
    if ($doc['status'] === 'anulado') {
        return 'Este documento está anulado: no se puede modificar.';
    }
    if (docs_base((string)$doc['doc_type']) === 'albaran' && $doc['status'] === 'facturado') {
        return 'Este albarán ya está facturado: no se puede modificar.';
    }
    if (docs_is_purchase((string)$doc['doc_type']) && docs_is_invoice((string)$doc['doc_type']) && $doc['status'] !== 'borrador') {
        return 'Factura cerrada: ya no se puede modificar. Para corregirla, haz una rectificativa recibida (botón «Rectificar»).';
    }
    if (docs_is_invoice((string)$doc['doc_type']) && $doc['status'] !== 'borrador') {
        return 'Factura emitida: ya no se puede modificar ni borrar. Para corregirla, haz una factura rectificativa (botón «Rectificar»).';
    }
    if (docs_base((string)$doc['doc_type']) === 'albaran' && (int)($doc['billed_by'] ?? 0) > 0) {
        $b = docs_get((int)$doc['billed_by']);
        return 'Este albarán está en ' . ($b && $b['status'] === 'borrador' ? 'un borrador de factura: no se puede modificar mientras exista (borra el borrador para liberarlo).' : 'una factura: no se puede modificar.');
    }
    if (period_is_locked((int)$doc['doc_day'])) {
        return period_lock_message((int)$doc['doc_day']);
    }
    return '';
}

/* ---------- Del formulario a un documento ---------- */

/**
 * Lee el formulario (POST) sobre el documento guardado (o uno nuevo) y devuelve
 * ['doc' => cabecera, 'lines' => líneas, 'errors' => [], 'warnings' => [], 'meta' => [por línea: opciones de talla/color, artículo…]].
 * Completa artículos (descripción, precio de la tarifa, IVA por defecto) y clientes; no guarda nada.
 * $reprice: aplicar el precio de la tarifa a todas las líneas con artículo.
 */
function docs_from_post(array $saved, array $savedLines, array $post, bool $reprice = false): array
{
    $kind = (string)$saved['doc_type'];
    $isNew = (int)$saved['id'] === 0;
    $h = (array)($post['h'] ?? []);
    $errors = [];
    $warn = [];
    $doc = $saved;
    $rates = docs_vat_rates();
    $savedById = [];
    foreach ($savedLines as $l) {
        $savedById[(int)$l['id']] = $l;
    }
    $hasSrc = (bool)array_filter($savedLines, fn($l) => (int)$l['src_line_id'] > 0);

    // Serie: solo al crear
    if ($isNew) {
        $sid = (int)($h['series_id'] ?? 0);
        $pickC = trim((string)($h['cust_code'] ?? ''));
        if ($pickC !== '' && $pickC !== (string)($h['cust_prev'] ?? '')) { // cliente elegido ahora: su serie por defecto, si es de este tipo y está activa
            $cs = ($cc = docs_party($pickC, $kind)) ? series_get((int)$cc['def_series']) : null;
            if ($cs && $cs['doc_type'] === $kind && (int)$cs['is_active'] === 1) {
                $sid = (int)$cs['id'];
            }
        }
        $s = series_get($sid);
        if (!$s || $s['doc_type'] !== $kind || (int)$s['is_active'] !== 1) {
            $errors[] = 'Elige una serie activa de ' . mb_strtolower(DOCS_KINDS[$kind]['plural']) . ' (Configuración → Series).';
        } else {
            $doc['series_id'] = $sid;
        }
    }
    // Fecha
    $day = erp_day_from_input((string)($h['day'] ?? ''));
    if ($day === 0) {
        $errors[] = 'La fecha no es válida.';
        $day = (int)$saved['doc_day'];
    } elseif (period_is_locked($day)) {
        $errors[] = period_lock_message($day);
    } elseif (!$isNew && (int)$saved['yr'] > 0 && intdiv($day, 10000) !== (int)$saved['yr']) {
        $errors[] = 'El número ' . $saved['doc_code'] . ' es del año ' . $saved['yr'] . ': la fecha tiene que ser de ese año.';
    }
    $doc['doc_day'] = $day;

    // Cliente: código de una ficha o «cliente sin ficha» (datos escritos a mano)
    $code = mb_substr(trim((string)($h['cust_code'] ?? '')), 0, 191);
    $partyWord = docs_is_purchase($kind) ? 'proveedor' : 'cliente';
    if (($hasSrc || (int)($saved['rect_of'] ?? 0) > 0) && $code !== (string)$saved['cust_code']) {
        $errors[] = 'Las líneas vienen de ' . ((int)($saved['rect_of'] ?? 0) > 0 ? 'la factura que se rectifica' : (docs_base($kind) === 'albaran' ? 'un pedido' : 'albaranes')) . ': el ' . $partyWord . ' no se puede cambiar.';
        $code = (string)$saved['cust_code'];
    }
    $cust = null;
    foreach (['cust_name' => 255, 'cust_nif' => 40, 'cust_address' => 255, 'cust_postcode' => 20, 'cust_city' => 120, 'cust_province' => 120, 'cust_email' => 190] as $k => $max) {
        $doc[$k] = erp_txt($h[$k] ?? '', $max);
    }
    if ($code !== '') {
        $cust = docs_party($code, $kind);
        if (!$cust) {
            $errors[] = 'No hay ningún ' . $partyWord . ' con el código «' . $code . '». Bórralo para escribir un ' . $partyWord . ' sin ficha.';
        } else {
            if ($code !== (string)($h['cust_prev'] ?? $saved['cust_code']) || $doc['cust_name'] === '') { // cliente elegido ahora: se copian sus datos
                $doc = array_merge($doc, docs_customer_snapshot($cust));
                if ($code !== (string)($h['cust_prev'] ?? $saved['cust_code'])) { // cliente nuevo en el formulario: su tarifa y forma de pago
                    if ((int)$cust['tariff'] > 0) {
                        $h['tariff'] = (string)$cust['tariff'];
                    }
                    if ((string)$cust['pay_code'] !== '') {
                        $h['pay_code'] = (string)$cust['pay_code'];
                    }
                }
            }
            if ((int)$cust['blocked'] === 1 || (int)$cust['ended_day'] > 0) {
                $why = 'El ' . $partyWord . ' ' . $code . ' está ' . ((int)$cust['blocked'] === 1 ? 'bloqueado' : 'de baja') . '.';
                if ($code !== (string)$saved['cust_code']) { // elegido ahora: no se puede; si ya estaba en el documento, solo se avisa
                    $errors[] = $why . ' No se puede elegir.';
                } else {
                    $warn[] = $why;
                }
            }
        }
    } elseif ($doc['cust_name'] === '' && $kind !== 'simplificada') { // en un tique el cliente es opcional
        $errors[] = 'Falta el ' . $partyWord . ': escribe su código o, si no tiene ficha, al menos su nombre.';
    }
    $doc['cust_code'] = $code;
    if ($doc['cust_email'] !== '' && !filter_var($doc['cust_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo del ' . $partyWord . ' no es válido.';
    }

    // Forma de pago y tarifa
    $pay = trim((string)($h['pay_code'] ?? ''));
    $methods = array_column(array_filter(pay_methods_all(), fn($m) => (int)$m['is_active'] === 1 || (string)$m['code'] === (string)$saved['pay_code']), null, 'code');
    if ($pay !== '' && !isset($methods[$pay])) {
        $errors[] = 'La forma de pago «' . mb_substr($pay, 0, 20) . '» no está activa (Configuración → Formas de pago).';
        $pay = (string)$saved['pay_code'];
    }
    $doc['pay_code'] = $pay;
    $tariffs = array_column(array_filter(tariffs_all(), fn($t) => (int)$t['is_active'] === 1 || (int)$t['num'] === (int)$saved['tariff']), null, 'num');
    $tariff = (int)($h['tariff'] ?? $saved['tariff']);
    if (!docp_bool('ask_tariff') && !$isNew && $code === (string)$saved['cust_code']) {
        $tariff = (int)$saved['tariff']; // la tarifa no se pide: la fija el cliente o el valor por defecto
    }
    if (!isset($tariffs[$tariff])) {
        $tariff = isset($tariffs[(int)$saved['tariff']]) ? (int)$saved['tariff'] : docp_int('tariff');
        if ($cust && isset($tariffs[(int)$cust['tariff']])) {
            $tariff = (int)$cust['tariff'];
        }
    }
    $tariffChanged = !$isNew && $tariff !== (int)$saved['tariff'];
    $doc['tariff'] = $tariff;
    $doc['vat_incl'] = !empty($h['vat_incl']) ? 1 : 0;
    $doc['with_re'] = !empty($h['with_re']) ? 1 : 0;
    $doc['ref_text'] = erp_txt($h['ref_text'] ?? '', 100);
    if (in_array($kind, ['factura_c', 'rectificativa_c'], true)) { // factura recibida: el número de la factura del proveedor es obligatorio y no debería repetirse
        $doc['sup_ref'] = erp_txt($h['sup_ref'] ?? $saved['sup_ref'], 60);
        if ($doc['sup_ref'] === '') {
            $errors[] = 'Falta el número de la factura del proveedor.';
        } else {
            $st = db()->prepare("SELECT doc_code, status FROM doc_headers WHERE doc_type IN ('factura_c', 'rectificativa_c') AND id <> ? AND LOWER(sup_ref) = LOWER(?)
                AND " . ($code !== '' ? 'cust_code = ?' : 'LOWER(cust_name) = LOWER(?)') . " AND status <> 'anulado' LIMIT 1");
            $st->execute([(int)$saved['id'], $doc['sup_ref'], $code !== '' ? $code : $doc['cust_name']]);
            if ($dup = $st->fetch()) {
                $warn[] = 'Ya hay otra factura de este proveedor con el número «' . $doc['sup_ref'] . '»' . ($dup['status'] === 'borrador' ? ' (en borrador)' : ' (registro ' . $dup['doc_code'] . ')') . '. Revisa que no sea un duplicado.';
            }
        }
    }
    $doc['notes'] = mb_substr(trim(str_replace("\r", '', (string)($h['notes'] ?? ''))), 0, 2000);
    if ($cust && $doc['notes'] === '' && trim((string)$cust['doc_notes']) !== '' && ($isNew || $code !== (string)($h['cust_prev'] ?? $saved['cust_code']))) {
        $doc['notes'] = mb_substr(trim((string)$cust['doc_notes']), 0, 2000); // notas para documentos del cliente: se proponen al elegirlo
    }
    if (docs_is_invoice($kind)) {
        $doc['vat_note'] = erp_txt($h['vat_note'] ?? '', 200); // mención legal (exención, inversión del sujeto pasivo…)
        $op = trim((string)($h['op_day'] ?? ''));
        $doc['op_day'] = $op === '' ? 0 : erp_day_from_input($op);
        if ($op !== '' && $doc['op_day'] === 0) {
            $errors[] = 'La fecha de la operación no es válida.';
        }
        if ($kind === 'rectificativa') {
            $doc['rect_text'] = erp_txt($h['rect_text'] ?? $saved['rect_text'], 200);
        }
    }
    $custDisc = $cust ? max(0, min(10000, (int)$cust['disc_bp'])) : 0;

    // Líneas
    $lineFields = docs_form_fields($kind);
    $lines = [];
    $meta = [];
    $kept = [];
    $rows = array_slice(array_values((array)($post['l'] ?? [])), 0, DOCS_MAX_LINES + DOCS_MORE_LINES);
    foreach ($rows as $i => $in) {
        $in = (array)$in;
        $lid = (int)($in['id'] ?? 0);
        $old = $savedById[$lid] ?? null;
        if ($lid > 0 && !$old) {
            continue; // una línea que no es de este documento
        }
        $del = !empty($in['del']);
        $ref = mb_substr(trim((string)($in['ref'] ?? '')), 0, 100);
        // Descripción de varias líneas: la primera línea es el título (máx. 255) y el resto va en «detail»
        $raw = mb_convert_encoding(str_replace(["\r\n", "\r"], "\n", (string)($in['title'] ?? '')), 'UTF-8', 'UTF-8'); // quita bytes que no sean UTF-8 válido (si no, el texto se perdería)
        $full = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $raw) ?? '');
        $full = mb_substr($full, 0, 2000);
        $parts = explode("\n", $full, 2);
        $title = mb_substr(trim($parts[0]), 0, 255);
        $detail = trim(mb_substr(trim($parts[0]), 255) . (isset($parts[1]) ? "\n" . $parts[1] : ''));
        $n = $i + 1;
        $isText = $old ? (int)$old['kind'] === 1 : (int)($in['kind'] ?? 0) === 1;
        $locked = $old && ((int)$old['done_u'] > 0 || (int)$old['src_line_id'] > 0); // servida o venida de un pedido: artículo fijo
        if ($locked) {
            $ref = (string)$old['ref'];
            $in['size'] = $old['size_txt'];
            $in['color'] = $old['color_txt'];
        }
        // Campos de las líneas desactivados (Parámetros → Campos de las líneas): no salen en el formulario, así que se conserva lo guardado
        if (!isset($lineFields['size'])) {
            $in['size'] = $old ? $old['size_txt'] : '';
        }
        if (!isset($lineFields['color'])) {
            $in['color'] = $old ? $old['color_txt'] : '';
        }
        $fromNote = docs_is_invoice($kind) && $old && (int)$old['src_line_id'] > 0; // línea de un albarán facturado: unidades fijas
        if ($del) {
            if ($fromNote) {
                $errors[] = 'Línea ' . $n . ': viene de un albarán; para quitarla, borra el borrador y factura los albaranes que quieras.';
            } elseif ($old && (int)$old['done_u'] > 0) {
                $errors[] = 'Línea ' . $n . ': ya está servida (total o parcialmente), no se puede quitar.';
            } else {
                continue;
            }
        }
        if (!$old && $ref === '' && $title === '' && trim((string)($in['qty'] ?? '')) === '') {
            continue; // fila vacía
        }
        if ($isText) { // línea de texto libre: solo descripción, sin unidades ni precio
            if ($title === '') {
                $errors[] = 'Línea ' . $n . ': escribe el texto o quita la línea.';
            }
            $vid = docs_vat_default();
            $lines[] = ['id' => $lid, 'product_id' => 0, 'ref' => '', 'title' => $title, 'detail' => $detail, 'size_txt' => '', 'color_txt' => '', 'extra' => '', 'src_line_id' => $old ? (int)$old['src_line_id'] : 0,
                'done_u' => $old ? (int)$old['done_u'] : 0, 'qty_u' => 0, 'price_u' => 0, 'disc_bp' => 0, 'vat_id' => $vid, 'vat_bp' => isset($rates[$vid]) ? (int)$rates[$vid]['pct_bp'] : 0,
                'rec_bp' => isset($rates[$vid]) ? (int)$rates[$vid]['surcharge_bp'] : 0, 'amount_u' => 0, 'kind' => 1];
            $meta[] = ['prod' => null, 'locked' => false, 'opts' => ['sizes' => [], 'colors' => []]];
            if ($lid > 0) {
                $kept[$lid] = true;
            }
            continue;
        }
        $prod = $ref !== '' ? docs_product_by_ref($ref) : null;
        // oref = referencia de la línea tal como se pintó la última vez (también en líneas aún sin guardar, tras «Recalcular»)
        $refChanged = mb_strtolower($ref) !== mb_strtolower((string)($in['oref'] ?? ($old['ref'] ?? '')));
        $l = [
            'id' => $lid, 'product_id' => $prod ? (int)$prod['id'] : 0, 'ref' => $prod ? (string)$prod['ref'] : $ref, 'title' => $title,
            'size_txt' => erp_txt($in['size'] ?? '', 40), 'color_txt' => erp_txt($in['color'] ?? '', 60),
            'src_line_id' => $old ? (int)$old['src_line_id'] : 0, 'done_u' => $old ? (int)$old['done_u'] : 0,
            'kind' => 0, 'detail' => $detail, 'extra' => docs_extra_from_post($in, $old, $kind),
        ];
        if ($prod && ($title === '' || $refChanged || !docp_bool('ask_title'))) {
            $l['title'] = (string)$prod['name'];
        }
        if ($ref !== '' && !$prod) {
            $warn[] = 'Línea ' . $n . ': la referencia «' . $ref . '» no está en el catálogo (se guarda como texto).';
        }
        if ($l['title'] === '' && $ref === '') {
            $errors[] = 'Línea ' . $n . ': escribe una referencia o una descripción.';
        }
        // Unidades
        $q = docs_parse_num((string)($in['qty'] ?? ''));
        if ($q === null) {
            $q = 1.0;
        }
        $l['qty_u'] = $fromNote ? (int)$old['qty_u'] : docs_scale($q, docs_dec('dec_qty'), DOCS_QTY);
        if ($kind === 'rectificativa') { // por diferencias: unidades negativas (lo que se devuelve o se descuenta)
            if ($l['qty_u'] === 0) {
                $errors[] = 'Línea ' . $n . ': las unidades no pueden ser cero.';
            }
        } elseif ($l['qty_u'] <= 0) {
            $errors[] = 'Línea ' . $n . ': las unidades tienen que ser mayores que cero.';
        }
        if ($old && (int)$old['done_u'] > 0 && $l['qty_u'] < (int)$old['done_u']) {
            $errors[] = 'Línea ' . $n . ': ya se han servido ' . docs_qty_txt((int)$old['done_u']) . ' unidades; no puede quedar con menos.';
        }
        // Precio: de la tarifa si no se pide, si la línea es nueva o cambia el artículo, si se cambia la tarifa o se pide aplicarla
        $p = docs_parse_num((string)($in['price'] ?? ''));
        $askPrice = docp_bool('ask_price') || docs_is_purchase($kind); // en compras el precio siempre se escribe (el coste del artículo, si lo trae, se propone)
        $useTariff = $prod && ($refChanged || $p === null || $reprice || (!$askPrice && ($tariffChanged || !$old)));
        if ($useTariff) {
            $tp = docs_price_for($kind, (int)$prod['id'], $tariff);
            if ($tp === null) {
                $warn[] = 'Línea ' . $n . ': el artículo ' . $prod['ref'] . (docs_is_purchase($kind) ? ' no tiene precio de compra (campo «Coste»): escríbelo.' : ' no tiene precio en la tarifa ' . $tariff . '.');
                $l['price_u'] = $p !== null ? (int)round($p * DOCS_PRICE) : 0;
            } else {
                $l['price_u'] = $tp;
            }
        } elseif ($prod && $old && !$askPrice) {
            $l['price_u'] = (int)$old['price_u']; // el precio no se pide: no se cambia a mano
        } else {
            $l['price_u'] = docs_scale($p ?? 0.0, docs_dec('dec_price'), DOCS_PRICE);
        }
        if ($l['price_u'] < 0) {
            $errors[] = 'Línea ' . $n . ': el precio no puede ser negativo.';
        }
        // Descuento: si no se pide, el del cliente; si se pide y está vacío en una línea nueva, también el del cliente
        $d = docs_parse_num((string)($in['disc'] ?? ''));
        if (!docp_bool('ask_discount')) { // no se pide: el del cliente (las líneas guardadas conservan el suyo)
            $l['disc_bp'] = $old && !$refChanged ? (int)$old['disc_bp'] : $custDisc;
        } elseif ($d === null && $refChanged) {
            $l['disc_bp'] = $custDisc;
        } else {
            $l['disc_bp'] = (int)round(($d ?? 0) * 100);
        }
        if (!isset($lineFields['disc'])) { // Dto. desactivado: las líneas guardadas conservan el suyo y las nuevas no llevan
            $l['disc_bp'] = $old && !$refChanged ? (int)$old['disc_bp'] : 0;
        }
        if ($l['disc_bp'] < 0 || $l['disc_bp'] > 10000) {
            $errors[] = 'Línea ' . $n . ': el descuento tiene que estar entre 0 y 100 %.';
            $l['disc_bp'] = 0;
        }
        // IVA
        $vid = (int)($in['vat'] ?? 0);
        if (!isset($rates[$vid])) {
            if ($old && (int)$old['vat_id'] === $vid && $vid > 0) { // tipo desactivado después: se conserva el guardado
                $l += ['vat_id' => $vid, 'vat_bp' => (int)$old['vat_bp'], 'rec_bp' => (int)$old['rec_bp']];
            } else {
                $vid = docs_vat_default();
            }
        }
        if (!isset($l['vat_id'])) {
            $l['vat_id'] = $vid;
            $l['vat_bp'] = isset($rates[$vid]) ? (int)$rates[$vid]['pct_bp'] : 0;
            $l['rec_bp'] = isset($rates[$vid]) ? (int)$rates[$vid]['surcharge_bp'] : 0;
        }
        $l['amount_u'] = docs_line_amount($l['qty_u'], $l['price_u'], $l['disc_bp']);
        // Avisos del artículo
        if ($prod) {
            if (docp_bool('warn_blocked') && (int)$prod['visible'] !== 1) {
                $warn[] = 'Línea ' . $n . ': el artículo ' . $prod['ref'] . ' está bloqueado (oculto en el catálogo).';
            }
            if (docp_bool('warn_stock_min') && !docs_is_purchase($kind)) { // en compras el stock no es un límite
                $sk = docs_product_stock((int)$prod['id']);
                $qty = $l['qty_u'] / DOCS_QTY;
                if ($sk['stock'] !== null && $qty > $sk['stock']) {
                    $warn[] = 'Línea ' . $n . ': el artículo ' . $prod['ref'] . ' tiene ' . rtrim(rtrim(number_format($sk['stock'], 2, ',', ''), '0'), ',') . ' en stock.';
                } elseif ($sk['stock'] !== null && $sk['min'] !== null && $sk['stock'] - $qty < $sk['min']) {
                    $warn[] = 'Línea ' . $n . ': el stock del artículo ' . $prod['ref'] . ' quedaría por debajo del mínimo.';
                }
            }
        }
        $meta[] = ['prod' => $prod, 'locked' => $locked, 'opts' => $prod ? docs_product_options((int)$prod['id']) : ['sizes' => [], 'colors' => []]];
        $lines[] = $l;
        if ($lid > 0) {
            $kept[$lid] = true;
        }
    }
    // Líneas guardadas que ya no vienen en el formulario (p. ej. borradas a mano del HTML): servidas no se pueden quitar
    foreach ($savedById as $lid => $old) {
        if (!isset($kept[$lid]) && ((int)$old['done_u'] > 0 || (docs_is_invoice($kind) && (int)$old['src_line_id'] > 0))) {
            $errors[] = 'La línea ' . $old['ref'] . ' ya está servida: no se puede quitar.';
        }
    }
    if (count($lines) > DOCS_MAX_LINES) {
        $errors[] = 'Como mucho ' . DOCS_MAX_LINES . ' líneas por documento.';
    }
    // Albarán con líneas de pedido: no servir más de lo pendiente
    $src = array_filter(array_column($lines, 'src_line_id'));
    if (docs_base($kind) === 'albaran' && $src) {
        $served = docs_served_by_line($src, (int)$saved['id'], $kind);
        $st = db()->prepare('SELECT id, qty_u FROM doc_lines WHERE id IN (' . implode(',', array_fill(0, count($src), '?')) . ')');
        $st->execute(array_values($src));
        $ordered = array_column($st->fetchAll(), 'qty_u', 'id');
        foreach ($lines as $i => $l) {
            if ($l['src_line_id'] > 0) {
                $pending = (int)($ordered[$l['src_line_id']] ?? 0) - ($served[$l['src_line_id']] ?? 0);
                if ($l['qty_u'] > $pending) {
                    $errors[] = 'Línea ' . ($i + 1) . ': del pedido quedan ' . docs_qty_txt(max(0, $pending)) . ' unidades por servir.';
                }
            }
        }
    }
    $tot = docs_totals($lines, (bool)$doc['vat_incl'], (bool)$doc['with_re']);
    $doc['base_cents'] = $tot['base'];
    $doc['vat_cents'] = $tot['vat_cents'];
    $doc['rec_cents'] = $tot['rec'];
    $doc['total_cents'] = $tot['total'];
    $doc['vat_json'] = (string)json_encode($tot['vat']);
    if (dues_applies($doc)) { // vencimientos: se recalculan si cambia la fecha, la forma de pago o el total; si no, se respetan los retoques
        docs_dues_apply($doc, $saved, $post, $isNew, $errors, $warn);
    }
    if (!$lines && !$isNew && $saved['status'] !== 'borrador') { // un borrador puede estar sin líneas (se exigen al confirmar o emitir)
        $errors[] = 'El documento no tiene líneas.';
    }
    return ['doc' => $doc, 'lines' => $lines, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warn)), 'meta' => $meta];
}

/* ---------- Guardar ---------- */

const DOCS_HEAD_COLS = ['doc_day', 'cust_code', 'cust_name', 'cust_nif', 'cust_address', 'cust_postcode', 'cust_city', 'cust_province', 'cust_email',
    'pay_code', 'tariff', 'vat_incl', 'with_re', 'ref_text', 'notes', 'base_cents', 'vat_cents', 'rec_cents', 'total_cents', 'vat_json', 'op_day', 'vat_note', 'rect_text', 'sup_ref'];
const DOCS_LINE_COLS = ['product_id', 'ref', 'title', 'size_txt', 'color_txt', 'qty_u', 'price_u', 'disc_bp', 'vat_id', 'vat_bp', 'rec_bp', 'amount_u', 'src_line_id', 'kind', 'detail', 'extra'];

/**
 * Guarda el documento (nuevo: saca su número de la serie dentro de la misma transacción). Las líneas existentes se actualizan
 * en su sitio (los albaranes apuntan a ellas), las nuevas se insertan y las quitadas se borran. Devuelve el id.
 * $stamp: versión (rev) que tenía el documento al abrir el formulario; si otro lo ha guardado entre medias, no se guarda.
 * Los documentos nuevos nacen como BORRADOR sin número; con $confirmed (pedido que se sirve) nacen ya numerados.
 */
function docs_save(array $doc, array $lines, int $stamp = -1, string $eventKind = '', string $eventDetail = '', bool $confirmed = false): int
{
    $db = db();
    $user = (string)(current_user()['username'] ?? '');
    $now = time();
    $own = !$db->inTransaction(); // docs_bill() y docs_rectify() lo llaman dentro de su propia transacción
    if ($own) {
        $db->beginTransaction();
    }
    $invoice = docs_is_invoice((string)$doc['doc_type']);
    try {
        $id = (int)$doc['id'];
        $ordersBefore = $id > 0 && docs_base((string)$doc['doc_type']) === 'albaran' ? docs_source_orders($id) : []; // por si se quita una línea de un pedido
        if ($id === 0 && ($invoice || !$confirmed)) {
            // Borrador: se guarda SIN número (el número de la serie se toma al emitir o confirmar, dentro de su transacción) (el número de la serie se toma al emitir, sin huecos)
            $doc['yr'] = 0;
            $doc['doc_no'] = 0;
            $doc['doc_code'] = '~' . bin2hex(random_bytes(8));
            $doc['status'] = 'borrador';
            $doc['created_at'] = $doc['updated_at'] = $now;
            $doc['created_by'] = $doc['updated_by'] = $user;
            $cols = ['doc_type', 'series_id', 'yr', 'doc_no', 'doc_code', 'status', 'origin_id', ...DOCS_HEAD_COLS, 'created_at', 'created_by', 'updated_at', 'updated_by',
                'rect_of', 'rect_mode', 'rect_reason', 'cash_crit'];
            $db->prepare('INSERT INTO doc_headers (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_map(fn($c) => $doc[$c], $cols));
            $st = $db->prepare('SELECT id FROM doc_headers WHERE doc_code = ?');
            $st->execute([$doc['doc_code']]);
            $id = (int)$st->fetchColumn();
            docs_event($id, $eventKind !== '' ? $eventKind : 'creado', $eventDetail);
        } elseif ($id === 0) {
            $s = series_get((int)$doc['series_id']);
            if (!$s) {
                throw new RuntimeException('La serie no existe.');
            }
            $year = intdiv((int)$doc['doc_day'], 10000);
            $num = series_next_number((int)$s['id'], $year);
            // Tras restaurar una copia antigua el contador puede ir por detrás de documentos que siguen guardados: pedidos y
            // albaranes saltan los números ya usados (las facturas, cuando existan, no podrán: ahí no se admiten huecos)
            $exists = $db->prepare('SELECT COUNT(*) FROM doc_headers WHERE doc_code = ?');
            for ($guard = 0; $guard < 10000; $guard++) {
                $exists->execute([$num['formatted']]);
                if ((int)$exists->fetchColumn() === 0) {
                    break;
                }
                $num = series_next_number((int)$s['id'], $year);
            }
            $doc['yr'] = (int)$s['with_year'] === 1 ? $year : 0;
            $doc['doc_no'] = $num['number'];
            $doc['doc_code'] = $num['formatted'];
            $cols = ['doc_type', 'series_id', 'yr', 'doc_no', 'doc_code', 'status', 'origin_id', ...DOCS_HEAD_COLS, 'created_at', 'created_by', 'updated_at', 'updated_by'];
            $doc['status'] = 'pendiente';
            $doc['created_at'] = $doc['updated_at'] = $now;
            $doc['created_by'] = $doc['updated_by'] = $user;
            $db->prepare('INSERT INTO doc_headers (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_map(fn($c) => $doc[$c], $cols));
            $st = $db->prepare('SELECT id FROM doc_headers WHERE doc_code = ?');
            $st->execute([$doc['doc_code']]);
            $id = (int)$st->fetchColumn();
            docs_event($id, $eventKind !== '' ? $eventKind : 'creado', $eventDetail);
        } else {
            // Una factura emitida no se toca nunca: la condición status = 'borrador' va en la propia sentencia
            $up = $db->prepare('UPDATE doc_headers SET ' . implode(' = ?, ', DOCS_HEAD_COLS) . ' = ?, updated_at = ?, updated_by = ?, rev = rev + 1 WHERE id = ?'
                . ($stamp >= 0 ? ' AND rev = ?' : '') . ($invoice ? " AND status = 'borrador'" : ''));
            $up->execute([...array_map(fn($c) => $doc[$c], DOCS_HEAD_COLS), max($now, (int)$doc['updated_at'] + 1), $user, $id, ...($stamp >= 0 ? [$stamp] : [])]);
            if ($up->rowCount() === 0) {
                throw new DomainException($invoice && (docs_get($id)['status'] ?? '') !== 'borrador'
                    ? 'Esta factura ya está emitida: no se puede modificar.'
                    : DOCS_CONFLICT_MSG);
            }
            docs_event($id, $eventKind !== '' ? $eventKind : 'modificado', $eventDetail);
        }
        $have = array_map('intval', $db->query('SELECT id FROM doc_lines WHERE doc_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN));
        $keep = [];
        $ins = $db->prepare('INSERT INTO doc_lines (doc_id, line_no, ' . implode(',', DOCS_LINE_COLS) . ', done_u) VALUES (?, ?, ' . implode(',', array_fill(0, count(DOCS_LINE_COLS), '?')) . ', 0)');
        $upd = $db->prepare('UPDATE doc_lines SET line_no = ?, ' . implode(' = ?, ', DOCS_LINE_COLS) . ' = ? WHERE id = ? AND doc_id = ?');
        foreach (array_values($lines) as $i => $l) {
            $vals = array_map(fn($c) => $l[$c] ?? ($c === 'extra' ? '' : null), DOCS_LINE_COLS);
            if ((int)$l['id'] > 0 && in_array((int)$l['id'], $have, true)) {
                $upd->execute([$i + 1, ...$vals, (int)$l['id'], $id]);
                $keep[(int)$l['id']] = true;
            } else {
                $ins->execute([$id, $i + 1, ...$vals]);
            }
        }
        foreach ($have as $lid) {
            if (!isset($keep[$lid])) {
                $db->prepare('DELETE FROM doc_lines WHERE id = ? AND doc_id = ? AND done_u = 0')->execute([$lid, $id]);
            }
        }
        docs_dues_save($doc, $id); // vencimientos de la factura (la misma transacción)
        if (docs_base((string)$doc['doc_type']) === 'albaran') {
            docs_refresh_orders([...$ordersBefore, ...docs_source_orders($id)]);
        } elseif (docs_base((string)$doc['doc_type']) === 'pedido') {
            docs_refresh_orders([$id]);
        }
        if ($own) {
            $db->commit();
        }
        return $id;
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Lo que falta para confirmar un borrador de pedido o albarán (vacío = se puede confirmar). */
function docs_confirm_problems(array $doc, array $lines): array
{
    $e = [];
    if ($doc['status'] !== 'borrador') {
        return ['Este documento ya está confirmado.'];
    }
    if (!array_filter($lines, fn($l) => (int)$l['kind'] === 0)) {
        $e[] = 'No tiene ninguna línea con artículo o concepto.';
    }
    if (trim((string)$doc['cust_name']) === '') {
        $e[] = docs_is_purchase((string)$doc['doc_type']) ? 'Falta el proveedor.' : 'Falta el cliente.';
    }
    if (period_is_locked((int)$doc['doc_day'])) {
        $e[] = period_lock_message((int)$doc['doc_day']);
    }
    $s = series_get((int)$doc['series_id']);
    if (!$s || $s['doc_type'] !== $doc['doc_type'] || (int)$s['is_active'] !== 1) {
        $e[] = 'La serie del borrador no está activa o no es de este tipo (Configuración → Series).';
    }
    return $e;
}

/**
 * Confirma un borrador de pedido o albarán: toma el número de su serie DENTRO de una transacción (dos usuarios a la vez nunca cogen
 * el mismo número ni dejan huecos; si algo falla, el número vuelve). $rev = versión con la que se abrió (-1 = sin comprobar).
 * Devuelve el número o lanza DomainException.
 */
function docs_confirm(int $id, int $rev = -1): string
{
    $db = db();
    $db->beginTransaction();
    try {
        $doc = docs_get($id);
        if (!$doc || !in_array(docs_base((string)$doc['doc_type']), ['pedido', 'albaran'], true) || docs_is_invoice((string)$doc['doc_type'])) {
            throw new DomainException('No es un pedido ni un albarán.');
        }
        if ($doc['status'] !== 'borrador') {
            throw new DomainException('Este documento ya está confirmado.');
        }
        if ($rev >= 0 && (int)$doc['rev'] !== $rev) {
            throw new DomainException(DOCS_CONFLICT_MSG);
        }
        $problems = docs_confirm_problems($doc, docs_lines($id));
        if ($problems) {
            throw new DomainException(implode(' ', $problems));
        }
        $s = series_get((int)$doc['series_id']);
        $year = intdiv((int)$doc['doc_day'], 10000);
        $num = series_next_number((int)$s['id'], $year);
        // Tras restaurar una copia antigua el contador puede ir por detrás de documentos guardados: se saltan los números ya usados
        $exists = $db->prepare('SELECT COUNT(*) FROM doc_headers WHERE doc_code = ?');
        for ($guard = 0; $guard < 10000; $guard++) {
            $exists->execute([$num['formatted']]);
            if ((int)$exists->fetchColumn() === 0) {
                break;
            }
            $num = series_next_number((int)$s['id'], $year);
        }
        $up = $db->prepare("UPDATE doc_headers SET yr = ?, doc_no = ?, doc_code = ?, status = 'pendiente', updated_at = ?, updated_by = ?, rev = rev + 1
            WHERE id = ? AND status = 'borrador'" . ($rev >= 0 ? ' AND rev = ?' : ''));
        $up->execute([(int)$s['with_year'] === 1 ? $year : 0, $num['number'], $num['formatted'], time(), (string)(current_user()['username'] ?? ''), $id, ...($rev >= 0 ? [$rev] : [])]);
        if ($up->rowCount() !== 1) {
            throw new DomainException(DOCS_CONFLICT_MSG);
        }
        docs_event($id, 'confirmado', $num['formatted']);
        docs_refresh_orders(docs_source_orders($id));
        $db->commit();
        return $num['formatted'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Anula un documento (conserva su número). Un pedido con algo servido no se anula: antes hay que anular sus albaranes. */
function docs_annul(array $doc): string
{
    if ($doc['status'] === 'anulado') {
        return 'Ya está anulado.';
    }
    if ($doc['status'] === 'borrador') {
        return 'Un borrador no se anula: bórralo (no tiene número).';
    }
    if ($reason = docs_locked_reason($doc)) {
        return $reason;
    }
    if (docs_base((string)$doc['doc_type']) === 'pedido') {
        $st = db()->prepare('SELECT COUNT(*) FROM doc_lines WHERE doc_id = ? AND done_u > 0');
        $st->execute([(int)$doc['id']]);
        if ((int)$st->fetchColumn() > 0) {
            return 'Este pedido tiene unidades servidas: anula antes sus albaranes.';
        }
    }
    $db = db();
    $db->beginTransaction();
    try {
        $orders = docs_base((string)$doc['doc_type']) === 'albaran' ? docs_source_orders((int)$doc['id']) : [];
        $db->prepare("UPDATE doc_headers SET status = 'anulado', updated_at = ?, updated_by = ? WHERE id = ?")->execute([time(), (string)(current_user()['username'] ?? ''), (int)$doc['id']]);
        docs_event((int)$doc['id'], 'anulado');
        docs_refresh_orders($orders);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return '';
}

/**
 * «Servir» un pedido: crea un albarán con las unidades pendientes de cada línea (fecha de hoy, serie de albaranes por defecto),
 * enlazando cada línea con la del pedido. Devuelve el id del albarán o lanza DomainException con el motivo.
 */
function docs_serve(array $order): int
{
    if (docs_base((string)$order['doc_type']) !== 'pedido' || in_array($order['status'], ['anulado', 'servido', 'facturado', 'borrador'], true)) {
        throw new DomainException(in_array($order['status'], ['servido', 'facturado'], true) ? 'Este pedido ya está servido entero.' : ($order['status'] === 'borrador' ? 'Confirma antes el pedido.' : 'Este pedido está anulado.'));
    }
    $today = (int)date('Ymd');
    if (period_is_locked($today)) {
        throw new DomainException(period_lock_message($today));
    }
    $albType = docs_side((string)$order['doc_type'], 'albaran');
    $s = series_default_for($albType);
    if (!$s) {
        throw new DomainException('No hay ninguna serie activa de ' . mb_strtolower(docs_kind($albType)['plural']) . ' (Configuración → Parámetros → Series).');
    }
    $lines = docs_lines((int)$order['id']);
    $served = docs_served_by_line(array_column($lines, 'id'), 0, $albType);
    $out = [];
    foreach ($lines as $l) {
        if ((int)$l['kind'] === 1) { // las líneas de texto pasan al albarán tal cual
            $nl = array_intersect_key($l, array_flip(DOCS_LINE_COLS));
            $nl['id'] = 0;
            $nl['src_line_id'] = (int)$l['id'];
            $out[] = $nl;
            continue;
        }
        $pending = (int)$l['qty_u'] - ($served[(int)$l['id']] ?? 0);
        if ($pending <= 0) {
            continue;
        }
        $nl = array_intersect_key($l, array_flip(DOCS_LINE_COLS));
        $nl['id'] = 0;
        $nl['qty_u'] = $pending;
        $nl['amount_u'] = docs_line_amount($pending, (int)$l['price_u'], (int)$l['disc_bp']);
        $nl['src_line_id'] = (int)$l['id'];
        $out[] = $nl;
    }
    if (!array_filter($out, fn($l) => (int)$l['kind'] === 0)) {
        throw new DomainException('No queda nada pendiente de servir en este pedido.');
    }
    $doc = array_merge(docs_blank($albType), array_intersect_key($order, array_flip(DOCS_HEAD_COLS)));
    $doc['sup_ref'] = '';
    $doc['series_id'] = (int)$s['id'];
    $doc['doc_day'] = $today;
    $doc['origin_id'] = (int)$order['id'];
    $doc['ref_text'] = $order['ref_text'] !== '' ? $order['ref_text'] : 'Pedido ' . $order['doc_code'];
    $tot = docs_totals($out, (bool)$doc['vat_incl'], (bool)$doc['with_re']);
    $doc['base_cents'] = $tot['base'];
    $doc['vat_cents'] = $tot['vat_cents'];
    $doc['rec_cents'] = $tot['rec'];
    $doc['total_cents'] = $tot['total'];
    $doc['vat_json'] = (string)json_encode($tot['vat']);
    $id = docs_save($doc, $out, -1, 'desde', 'Pedido ' . $order['doc_code'], true); // nace ya numerado
    $alb = docs_get($id);
    docs_event((int)$order['id'], 'servido', 'Albarán ' . ($alb['doc_code'] ?? ''));
    return $id;
}

/** Documentos relacionados: de un pedido, sus albaranes; de un albarán, sus pedidos. [['id','doc_type','doc_code','status']]. */
function docs_related(array $doc): array
{
    $db = db();
    $cols = 'h.id, h.doc_type, h.doc_code, h.status, h.doc_day';
    $type = (string)$doc['doc_type'];
    if (in_array(docs_base($type), ['pedido', 'albaran'], true) && !docs_is_invoice($type)) { // documentos hechos a partir de este (albaranes de un pedido, factura de un albarán)
        $st = $db->prepare('SELECT DISTINCT ' . $cols . ' FROM doc_lines l JOIN doc_lines o ON o.id = l.src_line_id JOIN doc_headers h ON h.id = l.doc_id WHERE o.doc_id = ? ORDER BY h.doc_day, h.id');
        $st->execute([(int)$doc['id']]);
        $out = $st->fetchAll();
    } else {
        $out = [];
    }
    if (docs_base($type) !== 'pedido') { // documentos de los que viene (pedidos de un albarán, albaranes de una factura)
        $st = $db->prepare('SELECT DISTINCT ' . $cols . ' FROM doc_lines l JOIN doc_lines o ON o.id = l.src_line_id JOIN doc_headers h ON h.id = o.doc_id WHERE l.doc_id = ? ORDER BY h.doc_day, h.id');
        $st->execute([(int)$doc['id']]);
        $out = array_merge($out, $st->fetchAll());
    }
    if (docs_is_invoice($type)) { // rectificativas de esta factura y factura que rectifica
        $st = $db->prepare('SELECT ' . $cols . ' FROM doc_headers h WHERE h.rect_of = ? OR h.id = ? ORDER BY h.doc_day, h.id');
        $st->execute([(int)$doc['id'], (int)$doc['rect_of']]);
        $out = array_merge($out, $st->fetchAll());
    }
    return $out;
}

/* ---------- Pantallas ---------- */

/** Despacha /admin/pedidos, /admin/albaranes y /admin/facturas: [/nuevo|/buscar|/<id>[/ver]]. */
function admin_docs(string $kind, string $sub, string $sub2 = ''): void
{
    if (!docs_is_purchase($kind) && !erp_guard()) { // compras: la doble verificación de Compras ya la comprueba purch_guard()
        return;
    }
    $invoices = docs_is_invoice($kind);
    if ($sub === 'buscar') {
        docs_search_json($invoices && !docs_is_purchase($kind) ? 'factura' : $kind);
        return;
    }
    if ($sub === 'pruebas' && $invoices) {
        admin_docs_test_purge();
        return;
    }
    if ($sub === '') {
        if (docs_base($kind) === 'albaran' && $_SERVER['REQUEST_METHOD'] === 'POST') { // «Facturar los marcados» de la lista de albaranes
            csrf_check();
            try {
                $fid = docs_bill((array)($_POST['ids'] ?? []));
                flash('Borrador de factura creado con los albaranes. Revísalo y pulsa «' . (docs_is_purchase($kind) ? 'Cerrar' : 'Emitir') . '» cuando esté bien.');
                redirect(docs_kind(docs_side($kind, 'factura'))['path'] . '/' . $fid);
            } catch (DomainException $e) {
                flash($e->getMessage(), 'err');
                redirect(docs_kind($kind)['path'] . (isset($_POST['back']) ? '?' . preg_replace('/[^\w=&%.-]/', '', (string)$_POST['back']) : ''));
            }
        }
        admin_docs_list($kind);
        return;
    }
    if ($sub === 'nuevo') {
        $t = (string)($_GET['tipo'] ?? $_POST['tipo'] ?? $kind);
        admin_docs_form($invoices && !docs_is_purchase($kind) ? (in_array($t, ['factura', 'simplificada'], true) ? $t : 'factura') : $kind, null);
        return;
    }
    if (!ctype_digit($sub)) {
        not_found();
        return;
    }
    $doc = docs_get((int)$sub);
    if (!$doc || ($invoices ? (!docs_is_invoice((string)$doc['doc_type']) || docs_is_purchase((string)$doc['doc_type']) !== docs_is_purchase($kind)) : $doc['doc_type'] !== $kind)) {
        not_found();
        return;
    }
    if ($sub2 === 'ver') {
        if (docs_is_invoice((string)$doc['doc_type']) && !docs_is_purchase((string)$doc['doc_type']) && $doc['status'] === 'emitida' && !docs_is_test($doc)) { // una sola versión impresa: la de Ventas
            redirect('/admin/ventas/' . erp_inv_seg((string)$doc['doc_code']) . '/ver');
        }
        docs_print($doc);
        return;
    }
    if ($sub2 === 'enviar' && !$invoices && !docs_is_purchase($kind)) {
        docs_send($doc);
        return;
    }
    if ($sub2 !== '') {
        not_found();
        return;
    }
    admin_docs_form((string)$doc['doc_type'], $doc);
}

function admin_docs_list(string $kind): void
{
    $db = db();
    $invoices = docs_is_invoice($kind);
    $purchase = docs_is_purchase($kind);
    $statuses = $invoices ? ($purchase ? DOCS_PURCHASE_INVOICE_STATUS : DOCS_INVOICE_STATUS) : DOCS_STATUS[$kind];
    $f = [
        'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60),
        'estado' => isset($statuses[(string)($_GET['estado'] ?? '')]) ? (string)$_GET['estado'] : '',
        'anio' => preg_match('/^\d{4}$/', (string)($_GET['anio'] ?? '')) ? (string)$_GET['anio'] : '',
        'tipo' => $invoices && in_array($_GET['tipo'] ?? '', $purchase ? ['factura_c', 'rectificativa_c'] : DOC_INVOICE_TYPES, true) ? (string)$_GET['tipo'] : '',
        'cli' => mb_substr(trim((string)($_GET['cli'] ?? '')), 0, 60),
        'desde' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['desde'] ?? '')) ? (string)$_GET['desde'] : '',
        'hasta' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['hasta'] ?? '')) ? (string)$_GET['hasta'] : '',
    ];
    if ($invoices && !$purchase) {
        docs_mirror_sync();
    }
    $page = max(1, (int)($_GET['pag'] ?? 1));
    if ($invoices) {
        $w = $purchase ? " WHERE doc_type IN ('factura_c','rectificativa_c')" : " WHERE doc_type IN ('factura','simplificada','rectificativa')";
        $a = [];
        if ($f['tipo'] !== '') {
            $w .= ' AND doc_type = ?';
            $a[] = $f['tipo'];
        }
    } else {
        $w = ' WHERE doc_type = ?';
        $a = [$kind];
    }
    if ($f['estado'] !== '') {
        $w .= ' AND status = ?';
        $a[] = $f['estado'];
    }
    if ($f['anio'] !== '') {
        $w .= ' AND doc_day BETWEEN ? AND ?';
        array_push($a, (int)$f['anio'] * 10000, (int)$f['anio'] * 10000 + 9999);
    }
    if ($f['cli'] !== '') { // cliente: código exacto o parte del nombre
        $w .= " AND (LOWER(cust_code) = ? OR LOWER(cust_name) LIKE ? ESCAPE '!')";
        array_push($a, mb_strtolower($f['cli']), '%' . strtr(mb_strtolower($f['cli']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
    }
    foreach ([['desde', '>='], ['hasta', '<=']] as [$k2, $op]) {
        if ($f[$k2] !== '') {
            $w .= ' AND doc_day ' . $op . ' ?';
            $a[] = (int)str_replace('-', '', $f[$k2]);
        }
    }
    if ($f['q'] !== '') {
        $like = '%' . strtr(mb_strtolower($f['q']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $w .= " AND (LOWER(doc_code) LIKE ? ESCAPE '!' OR LOWER(cust_name) LIKE ? ESCAPE '!' OR LOWER(cust_code) LIKE ? ESCAPE '!' OR LOWER(ref_text) LIKE ? ESCAPE '!' OR LOWER(sup_ref) LIKE ? ESCAPE '!')";
        array_push($a, $like, $like, $like, $like, $like);
    }
    $st = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status NOT IN (\'anulado\', \'borrador\') AND is_test = 0 THEN total_cents ELSE 0 END), 0) AS tot FROM doc_headers' . $w);
    $st->execute($a);
    $sum = $st->fetch();
    $pages = max(1, (int)ceil((int)$sum['n'] / DOCS_PER_PAGE));
    $page = min($page, $pages);
    $order = 'CASE WHEN status = \'borrador\' THEN 0 ELSE 1 END, doc_day DESC, id DESC';
    $st = $db->prepare('SELECT * FROM doc_headers' . $w . ' ORDER BY ' . $order . ' LIMIT ' . DOCS_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * DOCS_PER_PAGE) : ''));
    $st->execute($a);
    $rows = $st->fetchAll();
    $payState = []; // facturas recibidas: Pagada / Vencida / Pendiente de pago según sus vencimientos
    if ($purchase && $invoices && $rows) {
        $ids = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['status'] === 'emitida'));
        if ($ids) {
            $st = $db->prepare('SELECT doc_id, COUNT(*) AS n, SUM(CASE WHEN paid = 1 THEN 1 ELSE 0 END) AS np, SUM(CASE WHEN paid = 0 AND due_day < ? THEN 1 ELSE 0 END) AS nv FROM doc_dues WHERE doc_id IN (' . implode(',', $ids) . ') GROUP BY doc_id');
            $st->execute([(int)date('Ymd')]);
            foreach ($st->fetchAll() as $r) {
                $payState[(int)$r['doc_id']] = (int)$r['np'] === (int)$r['n'] ? 'pagada' : ((int)$r['nv'] > 0 ? 'vencida' : 'pendiente');
            }
        }
    }
    $years = array_map('intval', $db->query("SELECT DISTINCT yr FROM doc_headers WHERE yr > 0 ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN));
    $years = array_values(array_unique([(int)date('Y'), ...$years]));
    rsort($years);
    admin_render('docs_list', ['kind' => $kind, 'k' => docs_kind($kind), 'rows' => $rows, 'f' => $f, 'sum' => $sum, 'page' => $page, 'pages' => $pages,
        'years' => $years, 'retail' => erp_retail_code(), 'statuses' => $statuses, 'invoices' => $invoices, 'purchase' => $purchase, 'payState' => $payState,
        'register' => $invoices && !$purchase ? docs_register_verify() : null, 'testCount' => $invoices && !$purchase ? docs_test_count() : 0,
        'title' => $purchase ? docs_kind($kind)['plural'] : ($invoices ? 'Facturas del CMS' : DOCS_KINDS[$kind]['plural'])]);
}

function docs_list_url(string $kind, array $f, array $change = []): string
{
    $q = array_filter(array_merge($f, $change), fn($v) => $v !== '' && $v !== null);
    return docs_kind($kind)['path'] . ($q ? '?' . http_build_query($q) : '');
}

/**
 * Clientes por código, nombre, nombre comercial o NIF (como mucho 15). Los bloqueados o de baja salen marcados (no se pueden elegir).
 */
/** Proveedores por código, nombre, nombre comercial o NIF (como docs_customer_search, con la misma forma de fila). */
function docs_supplier_search(string $q, int $limit = 15): array
{
    $q = mb_strtolower(mb_substr(trim($q), 0, 60));
    if (mb_strlen($q) < 2) {
        return [];
    }
    $esc = strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']);
    $like = '%' . $esc . '%';
    $st = db()->prepare("SELECT sup_code AS cust_code, name, trade_name, nif, address, postcode, city, province, email, tariff, pay_code, blocked, ended_day FROM erp_suppliers
        WHERE LOWER(sup_code) LIKE ? ESCAPE '!' OR LOWER(name) LIKE ? ESCAPE '!' OR LOWER(trade_name) LIKE ? ESCAPE '!' OR LOWER(nif) LIKE ? ESCAPE '!'
        ORDER BY CASE WHEN LOWER(sup_code) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END, name LIMIT " . max(1, min(30, $limit)));
    $st->execute([$like, $like, $like, $like, $esc . '%']);
    return $st->fetchAll();
}

/** Clientes o proveedores según el tipo de documento. */
function docs_party_search(string $q, string $kind, int $limit = 15): array
{
    return docs_is_purchase($kind) ? docs_supplier_search($q, $limit) : docs_customer_search($q, $limit);
}

function docs_customer_search(string $q, int $limit = 15): array
{
    $q = mb_strtolower(mb_substr(trim($q), 0, 60));
    if (mb_strlen($q) < 2) {
        return [];
    }
    $esc = strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']);
    $like = '%' . $esc . '%';
    $st = db()->prepare("SELECT cust_code, name, trade_name, nif, address, postcode, city, province, email, tariff, pay_code, blocked, ended_day FROM erp_customers
        WHERE LOWER(cust_code) LIKE ? ESCAPE '!' OR LOWER(name) LIKE ? ESCAPE '!' OR LOWER(trade_name) LIKE ? ESCAPE '!' OR LOWER(nif) LIKE ? ESCAPE '!'
        ORDER BY CASE WHEN LOWER(cust_code) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END, name LIMIT " . max(1, min(30, $limit)));
    $st->execute([$like, $like, $like, $like, $esc . '%']);
    return $st->fetchAll();
}

/**
 * Formulario (nuevo o existente) y sus acciones.
 *  - Nuevo: solo la cabecera (cliente, fecha, serie, forma de pago) → «Grabar cabecera» crea un BORRADOR sin número; después se añaden las líneas.
 *  - Borrador: «Guardar» (sin número), «Confirmar» / «Emitir» (toma el número de la serie dentro de su transacción) o «Borrar borrador».
 *  - Versión (`stamp` = rev): si otro usuario guarda antes, el segundo no pisa los cambios y recibe un aviso.
 */
function admin_docs_form(string $kind, ?array $saved): void
{
    $k = docs_kind($kind);
    $purchase = docs_is_purchase($kind);
    $isNew = $saved === null;
    $saved ??= docs_blank($kind);
    if ($isNew && $_SERVER['REQUEST_METHOD'] === 'GET' && ($pc = docs_party((string)($_GET[$purchase ? 'proveedor' : 'cliente'] ?? ''), $kind)) && (int)$pc['blocked'] === 0 && (int)$pc['ended_day'] === 0) { // «Nuevo documento» desde la ficha del cliente o proveedor
        $saved = array_merge($saved, docs_customer_snapshot($pc), ['cust_code' => (string)$pc['cust_code'], 'tariff' => (int)$pc['tariff'] > 0 ? (int)$pc['tariff'] : $saved['tariff'],
            'pay_code' => (string)$pc['pay_code'] !== '' ? (string)$pc['pay_code'] : $saved['pay_code'], 'notes' => mb_substr(trim((string)$pc['doc_notes']), 0, 2000)]);
        $ps = series_get((int)$pc['def_series']);
        if ($ps && $ps['doc_type'] === $kind && (int)$ps['is_active'] === 1) {
            $saved['series_id'] = (int)$ps['id'];
        }
    }
    $savedLines = $isNew ? [] : docs_lines((int)$saved['id']);
    $self = $isNew ? $k['path'] . '/nuevo' . (docs_is_invoice($kind) && !$purchase ? '?tipo=' . $kind : '') : $k['path'] . '/' . (int)$saved['id'];
    $locked = docs_locked_reason($saved);
    $inv = docs_is_invoice($kind);
    $isDraft = !$isNew && $saved['status'] === 'borrador';
    $errors = [];
    $warnings = [];
    $extra = DOCS_BLANK_LINES;
    $extraText = 0;
    $state = null;
    $stamp = (int)$saved['rev']; // versión con la que se abre (en un POST, la que traía el formulario)
    $custResults = null;
    $custQuery = '';
    $artQuery = '';
    $artResults = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = (string)($_POST['action'] ?? 'save');
        $pick = '';
        if (str_starts_with($action, 'pick:')) { // sin JavaScript: botón de un resultado de la búsqueda de clientes
            $pick = mb_substr(substr($action, 5), 0, 191);
            $action = 'pick';
        }
        $addRef = '';
        if (str_starts_with($action, 'addart:')) { // sin JavaScript: botón de un resultado de la búsqueda de artículos
            $addRef = mb_substr(substr($action, 7), 0, 100);
            $action = 'recalc';
            $rowsIn = array_values((array)($_POST['l'] ?? []));
            $done = false;
            foreach ($rowsIn as $ri => $row) { // primera fila de artículo vacía
                if ((int)($row['id'] ?? 0) === 0 && (int)($row['kind'] ?? 0) === 0 && trim((string)($row['ref'] ?? '')) === '' && trim((string)($row['title'] ?? '')) === '') {
                    $rowsIn[$ri]['ref'] = $addRef;
                    $done = true;
                    break;
                }
            }
            if (!$done) {
                $rowsIn[] = ['id' => 0, 'kind' => 0, 'ref' => $addRef];
            }
            $_POST['l'] = $rowsIn;
        }
        if (isset($_POST['stamp'])) {
            $stamp = (int)$_POST['stamp'];
        }
        $label = $k['label'] . ' ' . ($saved['status'] === 'borrador' ? '(borrador)' : $saved['doc_code']);
        if ($action === 'annul' && !$isNew) {
            $msg = $inv ? 'Las facturas no se anulan: si es un borrador, bórralo; si está emitida, haz una rectificativa.' : docs_annul($saved);
            flash($msg === '' ? $label . ' anulado.' : $msg, $msg === '' ? 'ok' : 'err');
            redirect($self);
        }
        try {
            if ($purchase && !$isNew && (str_starts_with($action, 'due_pay:') || str_starts_with($action, 'due_unpay:'))) { // pagos de una factura recibida cerrada (no modifica la factura)
                [$act, $dno] = explode(':', $action, 2);
                purch_due_mark($saved, (int)$dno, $act === 'due_pay', (string)($_POST['paid_day'] ?? ''));
                flash($act === 'due_pay' ? 'Vencimiento marcado como pagado.' : 'Vencimiento marcado como pendiente.');
                redirect($self . '#vencimientos');
            }
            if ($action === 'serve' && !$isNew && docs_base($kind) === 'pedido' && !docs_is_invoice($kind)) {
                $aid = docs_serve($saved);
                flash($purchase ? 'Albarán de compra creado con lo pendiente de recibir. Si solo has recibido una parte, baja las unidades y guarda: el resto sigue pendiente en el pedido.' : 'Albarán creado con lo pendiente del pedido. Si solo vas a servir una parte, baja las unidades y guarda: el resto sigue pendiente en el pedido.');
                redirect(docs_kind(docs_side($kind, 'albaran'))['path'] . '/' . $aid);
            }
            if ($action === 'duplicate' && !$isNew && in_array(docs_base($kind), ['pedido', 'albaran'], true) && !docs_is_invoice($kind)) {
                $did = docs_duplicate($saved);
                flash($k['label'] . ' duplicado: es un borrador nuevo con las mismas líneas (sin vínculos con el original). Revisa fecha y precios y confírmalo.');
                redirect($k['path'] . '/' . $did);
            }
            if ($action === 'bill' && !$isNew && docs_base($kind) === 'albaran' && !docs_is_invoice($kind)) {
                $fid = docs_bill([(int)$saved['id']]);
                flash('Borrador de factura creado con el albarán. Revísalo y pulsa «' . ($purchase ? 'Cerrar' : 'Emitir') . '» cuando esté bien.');
                redirect(docs_kind(docs_side($kind, 'factura'))['path'] . '/' . $fid);
            }
            if ($action === 'confirm' && $isDraft && !$inv) {
                $code = docs_confirm((int)$saved['id'], $stamp);
                flash($k['label'] . ' ' . $code . ' confirmado.');
                redirect($self);
            }
            if ($action === 'issue' && $isDraft && $inv) {
                if ($purchase) {
                    $code = purch_close((int)$saved['id'], $stamp);
                    flash('Factura recibida cerrada con el registro ' . $code . '. Desde ahora no se puede modificar: para corregirla, haz una rectificativa recibida.');
                    redirect($self);
                }
                $code = docs_issue((int)$saved['id'], $stamp);
                flash('Factura ' . $code . ' emitida. Desde ahora no se puede modificar ni borrar.');
                redirect($self);
            }
            if ($action === 'delete' && $isDraft) {
                docs_delete_draft($saved);
                flash('Borrador borrado' . ((int)$saved['origin_id'] > 0 || $inv ? ': sus albaranes vuelven a estar pendientes de facturar.' : '.'));
                redirect($inv && !$purchase ? DOCS_KINDS['factura']['path'] : $k['path']);
            }
            if ($action === 'rectify' && !$isNew && $inv && $purchase) {
                $rid = purch_rectify($saved, (string)($_POST['text'] ?? ''));
                flash('Borrador de rectificativa recibida creado, con las líneas en negativo. Escribe el número de la rectificativa del proveedor, ajusta las líneas y pulsa «Cerrar».');
                redirect(docs_kind('rectificativa_c')['path'] . '/' . $rid);
            }
            if ($action === 'rectify' && !$isNew && $inv) {
                $rid = docs_rectify($saved, (string)($_POST['mode'] ?? ''), (string)($_POST['reason'] ?? ''), (string)($_POST['text'] ?? ''));
                flash('Borrador de rectificativa creado. ' . (($_POST['mode'] ?? '') === 'diferencias'
                    ? 'Lleva todas las líneas en negativo (devolución completa): quita las que no cambian y ajusta las demás.'
                    : 'Lleva las líneas de la factura original: corrígelas para que quede la factura correcta.') . ' Después pulsa «Emitir».');
                redirect(DOCS_KINDS['rectificativa']['path'] . '/' . $rid);
            }
        } catch (DomainException $e) {
            flash($e->getMessage(), 'err');
            redirect($self);
        }
        if ($locked !== '') {
            flash($locked, 'err');
            redirect($self);
        }
        if ($action === 'pick') {
            $_POST['h']['cust_code'] = $pick; // se trata como si hubieran escrito el código: se copian sus datos, tarifa y forma de pago
        }
        $state = docs_from_post($saved, $savedLines, $_POST, $action === 'reprice');
        $errors = $state['errors'];
        $warnings = $state['warnings'];
        $extra = max(DOCS_BLANK_LINES, min(DOCS_MAX_LINES, (int)($_POST['extra'] ?? DOCS_BLANK_LINES)));
        $extraText = max(0, min(50, (int)($_POST['extra_t'] ?? 0)));
        $render = ['more', 'more_text', 'recalc', 'reprice', 'search', 'pick', 'artsearch'];
        if ($action === 'more') {
            $extra = min(DOCS_MAX_LINES, $extra + DOCS_MORE_LINES);
            $errors = [];
        } elseif ($action === 'more_text') {
            $extraText = min(50, $extraText + 1);
            $errors = [];
        } elseif ($action === 'search') {
            $custQuery = mb_substr(trim((string)($_POST['cust_q'] ?? '')), 0, 60);
            $custResults = docs_party_search($custQuery, $kind);
            $errors = [];
        } elseif ($action === 'artsearch') {
            $artQuery = mb_substr(trim((string)($_POST['art_q'] ?? '')), 0, 60);
            $artResults = docs_article_search($artQuery, (int)($_POST['h']['tariff'] ?? 0), (string)($_POST['h']['cust_code'] ?? ''), $kind);
            $errors = [];
        } elseif (in_array($action, $render, true)) { // recalcular, aplicar tarifa o elegir cliente: no guarda
            $errors = array_values(array_filter($errors, fn($e) => !str_starts_with($e, 'El documento no tiene líneas') && !str_starts_with($e, 'Falta el cliente')));
        } elseif (!$errors) {
            try {
                $id = docs_save($state['doc'], $state['lines'], $isNew ? -1 : $stamp);
                $doc = docs_get($id);
                $name = $doc['status'] === 'borrador' ? 'Borrador de ' . mb_strtolower($k['label']) : $k['label'] . ' ' . ($doc['doc_code'] ?? '');
                flash($isNew
                    ? 'Cabecera grabada: ' . $name . ' (sin número hasta que lo ' . ($inv ? 'emitas' : 'confirmes') . '). Añade ahora las líneas.'
                    : $name . ' guardado.' . ($warnings ? ' Avisos: ' . implode(' ', $warnings) : ''));
                redirect($k['path'] . '/' . $id);
            } catch (DomainException $e) {
                $errors[] = $e->getMessage();
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
    $doc = $state['doc'] ?? $saved;
    $lines = $state['lines'] ?? $savedLines;
    $meta = $state['meta'] ?? array_map(function ($l) {
        $p = (int)$l['product_id'] > 0 ? ['id' => (int)$l['product_id'], 'ref' => $l['ref']] : null;
        return ['prod' => $p, 'locked' => (int)$l['done_u'] > 0 || (int)$l['src_line_id'] > 0, 'opts' => $p ? docs_product_options($p['id']) : ['sizes' => [], 'colors' => []]];
    }, $savedLines);
    $tot = docs_totals($lines, (bool)$doc['vat_incl'], (bool)$doc['with_re']);
    $series = array_filter(series_all(), fn($s) => $s['doc_type'] === $kind && ((int)$s['is_active'] === 1 || (int)$s['id'] === (int)$doc['series_id']));
    $draftTitle = 'Borrador de ' . mb_strtolower($k['label']);
    admin_render('docs_form', [
        'purchase' => $purchase, 'kind' => $kind, 'k' => $k, 'doc' => $doc, 'saved' => $saved, 'isNew' => $isNew, 'isDraft' => $isDraft, 'lines' => $lines, 'meta' => $meta, 'extra' => $extra, 'extraText' => $extraText,
        'tot' => $tot, 'errors' => $errors, 'warnings' => $warnings, 'locked' => $locked, 'self' => $self, 'series' => $series, 'stamp' => $stamp,
        'custResults' => $custResults, 'custQuery' => $custQuery, 'artResults' => $artResults, 'artQuery' => $artQuery,
        'rates' => docs_vat_rates(), 'methods' => array_filter(pay_methods_all(), fn($m) => (int)$m['is_active'] === 1 || (string)$m['code'] === (string)$doc['pay_code']),
        'tariffs' => array_filter(tariffs_all(), fn($t) => (int)$t['is_active'] === 1 || (int)$t['num'] === (int)$doc['tariff']),
        'nav' => $isNew ? [null, null] : docs_neighbors($saved),
        'events' => $isNew ? [] : docs_events((int)$saved['id']), 'related' => $isNew ? [] : docs_related($saved),
        'isRetail' => !$purchase && $doc['cust_code'] !== '' && $doc['cust_code'] === erp_retail_code(),
        'issueProblems' => $isDraft ? ($purchase && $inv ? purch_close_problems($saved, $savedLines) : ($inv ? docs_issue_problems($saved, $savedLines) : docs_confirm_problems($saved, $savedLines))) : [],
        'orig' => (int)$saved['rect_of'] > 0 ? docs_get((int)$saved['rect_of']) : null,
        'title' => $isNew ? $k['new'] : ($isDraft ? $draftTitle : $k['label'] . ' ' . $saved['doc_code']),
        'crumb' => $isNew ? $k['new'] : ($isDraft ? 'Borrador' : $saved['doc_code']),
    ]);
}

/** Sugerencias para el formulario (con JavaScript): ?t=art|cli&q=… → JSON (clientes: 15; artículos: 20).  Solo con sesión y acceso a ventas. */
function docs_search_json(string $kind = 'pedido'): void
{
    header('Content-Type: application/json; charset=utf-8');
    $q = mb_strtolower(mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60));
    $out = [];
    if (mb_strlen($q) >= 2) {
        $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $pre = strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        if (($_GET['t'] ?? '') === 'cli') {
            foreach (docs_party_search($q, $kind) as $r) {
                $out[] = ['v' => $r['cust_code'], 'l' => trim(($r['trade_name'] !== '' ? $r['trade_name'] : $r['name']) . ($r['city'] !== '' ? ' · ' . $r['city'] : '')),
                    'name' => $r['name'] !== '' ? $r['name'] : $r['trade_name'], 'nif' => $r['nif'], 'address' => $r['address'], 'postcode' => $r['postcode'], 'city' => $r['city'],
                    'province' => $r['province'], 'email' => $r['email'], 'tariff' => (int)$r['tariff'], 'pay' => $r['pay_code'],
                    'blocked' => (int)$r['blocked'] === 1 ? 'bloqueado' : ((int)$r['ended_day'] > 0 ? 'de baja' : '')];
            }
        } else {
            $out = docs_article_search($q, (int)($_GET['tariff'] ?? 0), (string)($_GET['cust'] ?? ''), $kind);
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** Máximo de resultados del buscador de artículos de las líneas. */
const DOCS_ART_SEARCH_MAX = 20;

/**
 * Buscador de artículos para las líneas: primero las referencias que empiezan por lo escrito y después las que lo contienen
 * (en la referencia o en la descripción). Máx. 20. Devuelve referencia, descripción, precio de la tarifa, stock, si está bloqueado,
 * tallas y colores, IVA por defecto y el descuento del cliente. Sin mayúsculas (LIKE sin LOWER para poder usar un índice de `ref`).
 */
function docs_article_search(string $q, int $tariff = 0, string $custCode = '', string $kind = 'pedido'): array
{
    $q = mb_substr(trim($q), 0, 60);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $esc = strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']);
    $db = db();
    $max = DOCS_ART_SEARCH_MAX;
    $rows = [];
    $st = $db->prepare("SELECT id, ref, name, visible FROM products WHERE ref <> '' AND ref LIKE ? ESCAPE '!' ORDER BY ref LIMIT " . $max);
    $st->execute([$esc . '%']);
    foreach ($st->fetchAll() as $r) {
        $rows[(int)$r['id']] = $r;
    }
    if (count($rows) < $max) {
        $skip = $rows ? ' AND id NOT IN (' . implode(',', array_map('intval', array_keys($rows))) . ')' : '';
        $st = $db->prepare("SELECT id, ref, name, visible FROM products WHERE ref <> '' AND (ref LIKE ? ESCAPE '!' OR name LIKE ? ESCAPE '!')" . $skip . ' ORDER BY ref LIMIT ' . ($max - count($rows)));
        $st->execute(['%' . $esc . '%', '%' . $esc . '%']);
        foreach ($st->fetchAll() as $r) {
            $rows[(int)$r['id']] = $r;
        }
    }
    if (!$rows) {
        return [];
    }
    $ids = array_keys($rows);
    if (!in_array($tariff, array_map(fn($t) => (int)$t['num'], tariffs_all()), true)) {
        $tariff = docp_int('tariff');
    }
    $pf = docs_is_purchase($kind) ? docs_cost_field() : docs_tariff_field($tariff);
    $prices = $pf ? field_values_for((int)$pf['id'], $ids) : [];
    $stockF = docs_field_named(['stock', 'existencias'], ['number', 'price', 'text']);
    $stocks = $stockF ? field_values_for((int)$stockF['id'], $ids) : [];
    $lf = docs_form_fields($kind);
    $sizeF = isset($lf['size']) ? docs_field_named(['tallas', 'talla']) : null;
    $colorF = isset($lf['color']) ? docs_field_named(['color', 'colores']) : null;
    $sizes = $sizeF ? field_values_for((int)$sizeF['id'], $ids) : [];
    $colors = $colorF ? field_values_for((int)$colorF['id'], $ids) : [];
    $disc = 0;
    if ($custCode !== '' && isset($lf['disc'])) {
        $c = docs_party($custCode, $kind);
        $disc = $c ? max(0, min(10000, (int)$c['disc_bp'])) : 0;
    }
    $out = [];
    foreach ($rows as $id => $r) {
        $n = isset($prices[$id]) && $prices[$id] !== '' ? docs_parse_num($prices[$id]) : null;
        $sk = isset($stocks[$id]) && $stocks[$id] !== '' ? docs_parse_num($stocks[$id]) : null;
        $out[] = [
            'v' => (string)$r['ref'], 'l' => (string)$r['name'],
            'price' => $n === null ? '' : docs_price_txt((int)round($n * DOCS_PRICE)),
            'stock' => $sk === null ? '' : rtrim(rtrim(number_format($sk, 2, ',', ''), '0'), ','),
            'blocked' => (int)$r['visible'] !== 1,
            'sizes' => docs_split_values((string)($sizes[$id] ?? '')), 'colors' => docs_split_values((string)($colors[$id] ?? '')),
            'vat' => docs_vat_default(), 'disc' => $disc > 0 ? rtrim(rtrim(number_format($disc / 100, 2, ',', ''), '0'), ',') : '',
        ];
    }
    return $out;
}

/** Documento impreso (hoja A4) con el diseño de Configuración → Diseño de documentos. */
function docs_print(array $doc): void
{
    $lines = docs_lines((int)$doc['id']);
    $vat = json_decode((string)$doc['vat_json'], true);
    render('documents/doc_classic', [
        'doc' => $doc, 'lines' => $lines, 'vat' => is_array($vat) ? $vat : [], 'k' => docs_kind((string)$doc['doc_type']), 'd' => doc_settings(), 'co' => doc_company(),
        'dues' => dues_applies($doc) ? docs_dues((int)$doc['id']) : [],
        'payLabel' => erp_pay_label((string)$doc['pay_code']), 'isRetail' => !docs_is_purchase((string)$doc['doc_type']) && $doc['cust_code'] !== '' && $doc['cust_code'] === erp_retail_code(),
        'title' => docs_kind((string)$doc['doc_type'])['label'] . ' ' . ($doc['status'] === 'borrador' ? '(borrador)' : $doc['doc_code']) . ' · ' . site_name(),
    ], null);
}
