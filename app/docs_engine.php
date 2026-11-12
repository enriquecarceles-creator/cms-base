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
 * Estados: pedido pendiente | parcial | servido | anulado (se calcula con lo servido); albarán pendiente | facturado | anulado.
 * Numeración: series_next_number() en la misma transacción que crea el documento (nunca repetidos). Los documentos no se
 * borran: se anulan (conservan su número). Bloqueo de periodos: no se crea, modifica ni anula nada con fecha bloqueada.
 */

const DOCS_QTY = 10000;        // unidades × 10.000
const DOCS_PRICE = 1000000;    // precio × 1.000.000
const DOCS_AMOUNT = 10000;     // importe de línea × 10.000
const DOCS_PER_PAGE = 50;
const DOCS_BLANK_LINES = 3;    // líneas vacías al final del formulario
const DOCS_MORE_LINES = 5;     // «Añadir líneas»
const DOCS_MAX_LINES = 300;

/** Tipos que tienen ya formulario (fase 1). Cada uno: etiqueta, plural, dirección, título impreso, género (para «nuevo/nueva»). */
const DOCS_KINDS = [
    'pedido' => ['label' => 'Pedido', 'plural' => 'Pedidos', 'path' => '/admin/pedidos', 'print' => 'PEDIDO', 'new' => 'Nuevo pedido'],
    'albaran' => ['label' => 'Albarán', 'plural' => 'Albaranes', 'path' => '/admin/albaranes', 'print' => 'ALBARÁN', 'new' => 'Nuevo albarán'],
];
const DOCS_PAGE_KIND = ['pedidos' => 'pedido', 'albaranes' => 'albaran'];

const DOCS_STATUS = [
    'pedido' => ['pendiente' => 'Pendiente', 'parcial' => 'Parcialmente servido', 'servido' => 'Servido', 'anulado' => 'Anulado'],
    'albaran' => ['pendiente' => 'Pendiente de facturar', 'facturado' => 'Facturado', 'anulado' => 'Anulado'],
];

const DOCS_EVENT_LABELS = [
    'creado' => 'Creado', 'modificado' => 'Modificado', 'servido' => 'Servido', 'anulado' => 'Anulado', 'desde' => 'Creado desde',
];

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
        'doc_day' => (int)date('Ymd'), 'status' => 'pendiente', 'cust_code' => '', 'cust_name' => '', 'cust_nif' => '', 'cust_address' => '',
        'cust_postcode' => '', 'cust_city' => '', 'cust_province' => '', 'cust_email' => '', 'pay_code' => docp('pay_method'),
        'tariff' => docp_int('tariff'), 'vat_incl' => docp_bool('vat_incl') ? 1 : 0, 'with_re' => 0, 'ref_text' => '', 'notes' => '',
        'base_cents' => 0, 'vat_cents' => 0, 'rec_cents' => 0, 'total_cents' => 0, 'vat_json' => '[]', 'origin_id' => 0,
        'created_at' => 0, 'created_by' => '', 'updated_at' => 0, 'updated_by' => '',
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
function docs_served_by_line(array $lineIds, int $exceptDoc = 0): array
{
    $lineIds = array_values(array_filter(array_map('intval', $lineIds)));
    if (!$lineIds) {
        return [];
    }
    $out = [];
    foreach (array_chunk($lineIds, 500) as $chunk) {
        $st = db()->prepare("SELECT l.src_line_id, SUM(l.qty_u) AS q FROM doc_lines l JOIN doc_headers h ON h.id = l.doc_id
            WHERE h.doc_type = 'albaran' AND h.status <> 'anulado' AND h.id <> ? AND l.src_line_id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')
            GROUP BY l.src_line_id');
        $st->execute([$exceptDoc, ...$chunk]);
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
        if (!$doc || $doc['doc_type'] !== 'pedido' || $doc['status'] === 'anulado') {
            continue;
        }
        $lines = docs_lines($oid);
        $served = docs_served_by_line(array_column($lines, 'id'));
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
    if ($doc['doc_type'] === 'albaran' && $doc['status'] === 'facturado') {
        return 'Este albarán ya está facturado: no se puede modificar.';
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
    if ($hasSrc && $code !== (string)$saved['cust_code']) {
        $errors[] = 'Las líneas vienen de un pedido: el cliente no se puede cambiar.';
        $code = (string)$saved['cust_code'];
    }
    $cust = null;
    foreach (['cust_name' => 255, 'cust_nif' => 40, 'cust_address' => 255, 'cust_postcode' => 20, 'cust_city' => 120, 'cust_province' => 120, 'cust_email' => 190] as $k => $max) {
        $doc[$k] = erp_txt($h[$k] ?? '', $max);
    }
    if ($code !== '') {
        $cust = docs_customer($code);
        if (!$cust) {
            $errors[] = 'No hay ningún cliente con el código «' . $code . '». Bórralo para escribir un cliente sin ficha.';
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
                $warn[] = 'El cliente ' . $code . ' está ' . ((int)$cust['blocked'] === 1 ? 'bloqueado' : 'de baja') . '.';
            }
        }
    } elseif ($doc['cust_name'] === '') {
        $errors[] = 'Falta el cliente: escribe su código o, si no tiene ficha, al menos su nombre.';
    }
    $doc['cust_code'] = $code;
    if ($doc['cust_email'] !== '' && !filter_var($doc['cust_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'El correo del cliente no es válido.';
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
    $doc['notes'] = mb_substr(trim(str_replace("\r", '', (string)($h['notes'] ?? ''))), 0, 2000);
    $custDisc = $cust ? max(0, min(10000, (int)$cust['disc_bp'])) : 0;

    // Líneas
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
        $title = erp_txt($in['title'] ?? '', 255);
        $n = $i + 1;
        $locked = $old && ((int)$old['done_u'] > 0 || (int)$old['src_line_id'] > 0); // servida o venida de un pedido: artículo fijo
        if ($locked) {
            $ref = (string)$old['ref'];
            $in['size'] = $old['size_txt'];
            $in['color'] = $old['color_txt'];
        }
        if ($del) {
            if ($old && (int)$old['done_u'] > 0) {
                $errors[] = 'Línea ' . $n . ': ya está servida (total o parcialmente), no se puede quitar.';
            } else {
                continue;
            }
        }
        if (!$old && $ref === '' && $title === '' && trim((string)($in['qty'] ?? '')) === '') {
            continue; // fila vacía
        }
        $prod = $ref !== '' ? docs_product_by_ref($ref) : null;
        // oref = referencia de la línea tal como se pintó la última vez (también en líneas aún sin guardar, tras «Recalcular»)
        $refChanged = mb_strtolower($ref) !== mb_strtolower((string)($in['oref'] ?? ($old['ref'] ?? '')));
        $l = [
            'id' => $lid, 'product_id' => $prod ? (int)$prod['id'] : 0, 'ref' => $prod ? (string)$prod['ref'] : $ref, 'title' => $title,
            'size_txt' => erp_txt($in['size'] ?? '', 40), 'color_txt' => erp_txt($in['color'] ?? '', 60),
            'src_line_id' => $old ? (int)$old['src_line_id'] : 0, 'done_u' => $old ? (int)$old['done_u'] : 0,
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
        $l['qty_u'] = docs_scale($q, docs_dec('dec_qty'), DOCS_QTY);
        if ($l['qty_u'] <= 0) {
            $errors[] = 'Línea ' . $n . ': las unidades tienen que ser mayores que cero.';
        }
        if ($old && (int)$old['done_u'] > 0 && $l['qty_u'] < (int)$old['done_u']) {
            $errors[] = 'Línea ' . $n . ': ya se han servido ' . docs_qty_txt((int)$old['done_u']) . ' unidades; no puede quedar con menos.';
        }
        // Precio: de la tarifa si no se pide, si la línea es nueva o cambia el artículo, si se cambia la tarifa o se pide aplicarla
        $p = docs_parse_num((string)($in['price'] ?? ''));
        $useTariff = $prod && ($refChanged || $p === null || $reprice || (!docp_bool('ask_price') && ($tariffChanged || !$old)));
        if ($useTariff) {
            $tp = docs_product_price((int)$prod['id'], $tariff);
            if ($tp === null) {
                $warn[] = 'Línea ' . $n . ': el artículo ' . $prod['ref'] . ' no tiene precio en la tarifa ' . $tariff . '.';
                $l['price_u'] = $p !== null ? (int)round($p * DOCS_PRICE) : 0;
            } else {
                $l['price_u'] = $tp;
            }
        } elseif ($prod && $old && !docp_bool('ask_price')) {
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
            if (docp_bool('warn_stock_min')) {
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
        if (!isset($kept[$lid]) && (int)$old['done_u'] > 0) {
            $errors[] = 'La línea ' . $old['ref'] . ' ya está servida: no se puede quitar.';
        }
    }
    if (count($lines) > DOCS_MAX_LINES) {
        $errors[] = 'Como mucho ' . DOCS_MAX_LINES . ' líneas por documento.';
    }
    // Albarán con líneas de pedido: no servir más de lo pendiente
    $src = array_filter(array_column($lines, 'src_line_id'));
    if ($kind === 'albaran' && $src) {
        $served = docs_served_by_line($src, (int)$saved['id']);
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
    if (!$lines) {
        $errors[] = 'El documento no tiene líneas.';
    }
    return ['doc' => $doc, 'lines' => $lines, 'errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warn)), 'meta' => $meta];
}

/* ---------- Guardar ---------- */

const DOCS_HEAD_COLS = ['doc_day', 'cust_code', 'cust_name', 'cust_nif', 'cust_address', 'cust_postcode', 'cust_city', 'cust_province', 'cust_email',
    'pay_code', 'tariff', 'vat_incl', 'with_re', 'ref_text', 'notes', 'base_cents', 'vat_cents', 'rec_cents', 'total_cents', 'vat_json'];
const DOCS_LINE_COLS = ['product_id', 'ref', 'title', 'size_txt', 'color_txt', 'qty_u', 'price_u', 'disc_bp', 'vat_id', 'vat_bp', 'rec_bp', 'amount_u', 'src_line_id'];

/**
 * Guarda el documento (nuevo: saca su número de la serie dentro de la misma transacción). Las líneas existentes se actualizan
 * en su sitio (los albaranes apuntan a ellas), las nuevas se insertan y las quitadas se borran. Devuelve el id.
 * $stamp: updated_at que tenía el documento al abrir el formulario (si otro lo ha cambiado entre medias, no se guarda).
 */
function docs_save(array $doc, array $lines, int $stamp = -1, string $eventKind = '', string $eventDetail = ''): int
{
    $db = db();
    $user = (string)(current_user()['username'] ?? '');
    $now = time();
    $db->beginTransaction();
    try {
        $id = (int)$doc['id'];
        $ordersBefore = $id > 0 && $doc['doc_type'] === 'albaran' ? docs_source_orders($id) : []; // por si se quita una línea de un pedido
        if ($id === 0) {
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
            $up = $db->prepare('UPDATE doc_headers SET ' . implode(' = ?, ', DOCS_HEAD_COLS) . ' = ?, updated_at = ?, updated_by = ? WHERE id = ?' . ($stamp >= 0 ? ' AND updated_at = ?' : ''));
            $up->execute([...array_map(fn($c) => $doc[$c], DOCS_HEAD_COLS), max($now, (int)$doc['updated_at'] + 1), $user, $id, ...($stamp >= 0 ? [$stamp] : [])]);
            if ($up->rowCount() === 0) {
                throw new DomainException('Otra persona ha modificado este documento mientras lo editabas. Vuelve a abrirlo y repite el cambio.');
            }
            docs_event($id, $eventKind !== '' ? $eventKind : 'modificado', $eventDetail);
        }
        $have = array_map('intval', $db->query('SELECT id FROM doc_lines WHERE doc_id = ' . $id)->fetchAll(PDO::FETCH_COLUMN));
        $keep = [];
        $ins = $db->prepare('INSERT INTO doc_lines (doc_id, line_no, ' . implode(',', DOCS_LINE_COLS) . ', done_u) VALUES (?, ?, ' . implode(',', array_fill(0, count(DOCS_LINE_COLS), '?')) . ', 0)');
        $upd = $db->prepare('UPDATE doc_lines SET line_no = ?, ' . implode(' = ?, ', DOCS_LINE_COLS) . ' = ? WHERE id = ? AND doc_id = ?');
        foreach (array_values($lines) as $i => $l) {
            $vals = array_map(fn($c) => $l[$c], DOCS_LINE_COLS);
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
        if ($doc['doc_type'] === 'albaran') {
            docs_refresh_orders([...$ordersBefore, ...docs_source_orders($id)]);
        } else {
            docs_refresh_orders([$id]);
        }
        $db->commit();
        return $id;
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
    if ($reason = docs_locked_reason($doc)) {
        return $reason;
    }
    if ($doc['doc_type'] === 'pedido') {
        $st = db()->prepare('SELECT COUNT(*) FROM doc_lines WHERE doc_id = ? AND done_u > 0');
        $st->execute([(int)$doc['id']]);
        if ((int)$st->fetchColumn() > 0) {
            return 'Este pedido tiene unidades servidas: anula antes sus albaranes.';
        }
    }
    $db = db();
    $db->beginTransaction();
    try {
        $orders = $doc['doc_type'] === 'albaran' ? docs_source_orders((int)$doc['id']) : [];
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
    if ($order['doc_type'] !== 'pedido' || in_array($order['status'], ['anulado', 'servido'], true)) {
        throw new DomainException($order['status'] === 'servido' ? 'Este pedido ya está servido entero.' : 'Este pedido está anulado.');
    }
    $today = (int)date('Ymd');
    if (period_is_locked($today)) {
        throw new DomainException(period_lock_message($today));
    }
    $s = series_default_for('albaran');
    if (!$s) {
        throw new DomainException('No hay ninguna serie activa de albaranes (Configuración → Series).');
    }
    $lines = docs_lines((int)$order['id']);
    $served = docs_served_by_line(array_column($lines, 'id'));
    $out = [];
    foreach ($lines as $l) {
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
    if (!$out) {
        throw new DomainException('No queda nada pendiente de servir en este pedido.');
    }
    $doc = array_merge(docs_blank('albaran'), array_intersect_key($order, array_flip(DOCS_HEAD_COLS)));
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
    $id = docs_save($doc, $out, -1, 'desde', 'Pedido ' . $order['doc_code']);
    $alb = docs_get($id);
    docs_event((int)$order['id'], 'servido', 'Albarán ' . ($alb['doc_code'] ?? ''));
    return $id;
}

/** Documentos relacionados: de un pedido, sus albaranes; de un albarán, sus pedidos. [['id','doc_type','doc_code','status']]. */
function docs_related(array $doc): array
{
    if ($doc['doc_type'] === 'pedido') {
        $st = db()->prepare('SELECT DISTINCT h.id, h.doc_type, h.doc_code, h.status, h.doc_day FROM doc_lines l JOIN doc_lines o ON o.id = l.src_line_id
            JOIN doc_headers h ON h.id = l.doc_id WHERE o.doc_id = ? ORDER BY h.doc_day, h.id');
    } else {
        $st = db()->prepare('SELECT DISTINCT h.id, h.doc_type, h.doc_code, h.status, h.doc_day FROM doc_lines l JOIN doc_lines o ON o.id = l.src_line_id
            JOIN doc_headers h ON h.id = o.doc_id WHERE l.doc_id = ? ORDER BY h.doc_day, h.id');
    }
    $st->execute([(int)$doc['id']]);
    return $st->fetchAll();
}

/* ---------- Pantallas ---------- */

/** Despacha /admin/pedidos[/nuevo|/buscar|/<id>[/ver]] y lo mismo para /admin/albaranes. */
function admin_docs(string $kind, string $sub, string $sub2 = ''): void
{
    if (!erp_guard()) {
        return;
    }
    if ($sub === 'buscar') {
        docs_search_json();
        return;
    }
    if ($sub === '') {
        admin_docs_list($kind);
        return;
    }
    if ($sub === 'nuevo') {
        admin_docs_form($kind, null);
        return;
    }
    if (!ctype_digit($sub)) {
        not_found();
        return;
    }
    $doc = docs_get((int)$sub);
    if (!$doc || $doc['doc_type'] !== $kind) {
        not_found();
        return;
    }
    if ($sub2 === 'ver') {
        docs_print($doc);
        return;
    }
    if ($sub2 !== '') {
        not_found();
        return;
    }
    admin_docs_form($kind, $doc);
}

function admin_docs_list(string $kind): void
{
    $db = db();
    $f = [
        'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60),
        'estado' => isset(DOCS_STATUS[$kind][(string)($_GET['estado'] ?? '')]) ? (string)$_GET['estado'] : '',
        'anio' => preg_match('/^\d{4}$/', (string)($_GET['anio'] ?? '')) ? (string)$_GET['anio'] : '',
    ];
    $page = max(1, (int)($_GET['pag'] ?? 1));
    $w = ' WHERE doc_type = ?';
    $a = [$kind];
    if ($f['estado'] !== '') {
        $w .= ' AND status = ?';
        $a[] = $f['estado'];
    }
    if ($f['anio'] !== '') {
        $w .= ' AND doc_day BETWEEN ? AND ?';
        array_push($a, (int)$f['anio'] * 10000, (int)$f['anio'] * 10000 + 9999);
    }
    if ($f['q'] !== '') {
        $like = '%' . strtr(mb_strtolower($f['q']), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $w .= " AND (LOWER(doc_code) LIKE ? ESCAPE '!' OR LOWER(cust_name) LIKE ? ESCAPE '!' OR LOWER(cust_code) LIKE ? ESCAPE '!' OR LOWER(ref_text) LIKE ? ESCAPE '!')";
        array_push($a, $like, $like, $like, $like);
    }
    $st = $db->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status <> \'anulado\' THEN total_cents ELSE 0 END), 0) AS tot FROM doc_headers' . $w);
    $st->execute($a);
    $sum = $st->fetch();
    $pages = max(1, (int)ceil((int)$sum['n'] / DOCS_PER_PAGE));
    $page = min($page, $pages);
    $st = $db->prepare('SELECT * FROM doc_headers' . $w . ' ORDER BY doc_day DESC, id DESC LIMIT ' . DOCS_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * DOCS_PER_PAGE) : ''));
    $st->execute($a);
    $rows = $st->fetchAll();
    $years = array_map('intval', $db->query("SELECT DISTINCT yr FROM doc_headers WHERE yr > 0 ORDER BY yr DESC")->fetchAll(PDO::FETCH_COLUMN));
    $years = array_values(array_unique([(int)date('Y'), ...$years]));
    rsort($years);
    admin_render('docs_list', ['kind' => $kind, 'k' => DOCS_KINDS[$kind], 'rows' => $rows, 'f' => $f, 'sum' => $sum, 'page' => $page, 'pages' => $pages,
        'years' => $years, 'retail' => erp_retail_code(), 'title' => DOCS_KINDS[$kind]['plural']]);
}

function docs_list_url(string $kind, array $f, array $change = []): string
{
    $q = array_filter(array_merge($f, $change), fn($v) => $v !== '' && $v !== null);
    return DOCS_KINDS[$kind]['path'] . ($q ? '?' . http_build_query($q) : '');
}

/** Formulario (nuevo o existente) y sus acciones: recalcular, añadir líneas, aplicar tarifa, guardar, anular y servir. */
function admin_docs_form(string $kind, ?array $saved): void
{
    $k = DOCS_KINDS[$kind];
    $isNew = $saved === null;
    $saved ??= docs_blank($kind);
    $savedLines = $isNew ? [] : docs_lines((int)$saved['id']);
    $self = $isNew ? $k['path'] . '/nuevo' : $k['path'] . '/' . (int)$saved['id'];
    $locked = docs_locked_reason($saved);
    $errors = [];
    $warnings = [];
    $extra = DOCS_BLANK_LINES;
    $state = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = (string)($_POST['action'] ?? 'save');
        if ($action === 'annul' && !$isNew) {
            $msg = docs_annul($saved);
            flash($msg === '' ? $k['label'] . ' ' . $saved['doc_code'] . ' anulado.' : $msg, $msg === '' ? 'ok' : 'err');
            redirect($self);
        }
        if ($action === 'serve' && !$isNew && $kind === 'pedido') {
            try {
                $aid = docs_serve($saved);
                flash('Albarán creado con lo pendiente del pedido. Revisa las unidades (puedes servir menos) y guarda si cambias algo.');
                redirect(DOCS_KINDS['albaran']['path'] . '/' . $aid);
            } catch (DomainException $e) {
                flash($e->getMessage(), 'err');
                redirect($self);
            }
        }
        if ($locked !== '') {
            flash($locked, 'err');
            redirect($self);
        }
        $state = docs_from_post($saved, $savedLines, $_POST, $action === 'reprice');
        $errors = $state['errors'];
        $warnings = $state['warnings'];
        $extra = max(DOCS_BLANK_LINES, min(DOCS_MAX_LINES, (int)($_POST['extra'] ?? DOCS_BLANK_LINES)));
        if ($action === 'more') {
            $extra = min(DOCS_MAX_LINES, $extra + DOCS_MORE_LINES);
            $errors = [];
        } elseif ($action === 'recalc' || $action === 'reprice') {
            $errors = array_values(array_filter($errors, fn($e) => !str_starts_with($e, 'El documento no tiene líneas') && !str_starts_with($e, 'Falta el cliente')));
        } elseif (!$errors) {
            try {
                $id = docs_save($state['doc'], $state['lines'], $isNew ? -1 : (int)($_POST['stamp'] ?? -1));
                $doc = docs_get($id);
                flash($k['label'] . ' ' . ($doc['doc_code'] ?? '') . ' guardado.' . ($warnings ? ' Avisos: ' . implode(' ', $warnings) : ''));
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
    $tab = (string)($_GET['pestana'] ?? 'iva');
    admin_render('docs_form', [
        'kind' => $kind, 'k' => $k, 'doc' => $doc, 'saved' => $saved, 'isNew' => $isNew, 'lines' => $lines, 'meta' => $meta, 'extra' => $extra,
        'tot' => $tot, 'errors' => $errors, 'warnings' => $warnings, 'locked' => $locked, 'self' => $self, 'series' => $series,
        'rates' => docs_vat_rates(), 'methods' => array_filter(pay_methods_all(), fn($m) => (int)$m['is_active'] === 1 || (string)$m['code'] === (string)$doc['pay_code']),
        'tariffs' => array_filter(tariffs_all(), fn($t) => (int)$t['is_active'] === 1 || (int)$t['num'] === (int)$doc['tariff']),
        'events' => $isNew ? [] : docs_events((int)$saved['id']), 'related' => $isNew ? [] : docs_related($saved),
        'tab' => in_array($tab, ['iva', 'relacionados', 'historial'], true) ? $tab : 'iva', 'isRetail' => $doc['cust_code'] !== '' && $doc['cust_code'] === erp_retail_code(),
        'title' => $isNew ? $k['new'] : $k['label'] . ' ' . $saved['doc_code'], 'crumb' => $isNew ? $k['new'] : $saved['doc_code'],
    ]);
}

/** Sugerencias para el formulario (con JavaScript): ?t=art|cli&q=… → JSON (como mucho 15). Solo con sesión y acceso a ventas. */
function docs_search_json(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $q = mb_strtolower(mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60));
    $out = [];
    if (mb_strlen($q) >= 2) {
        $like = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $pre = strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        if (($_GET['t'] ?? '') === 'cli') {
            $st = db()->prepare("SELECT cust_code, name, trade_name, nif, city FROM erp_customers WHERE LOWER(cust_code) LIKE ? ESCAPE '!' OR LOWER(name) LIKE ? ESCAPE '!' OR LOWER(trade_name) LIKE ? ESCAPE '!' OR LOWER(nif) LIKE ? ESCAPE '!'
                ORDER BY CASE WHEN LOWER(cust_code) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END, name LIMIT 15");
            $st->execute([$like, $like, $like, $like, $pre]);
            foreach ($st->fetchAll() as $r) {
                $out[] = ['v' => $r['cust_code'], 'l' => trim(($r['trade_name'] !== '' ? $r['trade_name'] : $r['name']) . ($r['city'] !== '' ? ' · ' . $r['city'] : ''))];
            }
        } else {
            $st = db()->prepare("SELECT id, ref, name FROM products WHERE LOWER(ref) LIKE ? ESCAPE '!' OR LOWER(name) LIKE ? ESCAPE '!'
                ORDER BY CASE WHEN LOWER(ref) LIKE ? ESCAPE '!' THEN 0 ELSE 1 END, ref LIMIT 15");
            $st->execute([$like, $like, $pre]);
            foreach ($st->fetchAll() as $r) {
                if ((string)$r['ref'] !== '') {
                    $out[] = ['v' => $r['ref'], 'l' => $r['name']];
                }
            }
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** Documento impreso (hoja A4) con el diseño de Configuración → Diseño de documentos. */
function docs_print(array $doc): void
{
    $lines = docs_lines((int)$doc['id']);
    $vat = json_decode((string)$doc['vat_json'], true);
    render('documents/doc_classic', [
        'doc' => $doc, 'lines' => $lines, 'vat' => is_array($vat) ? $vat : [], 'k' => DOCS_KINDS[$doc['doc_type']], 'd' => doc_settings(), 'co' => doc_company(),
        'payLabel' => erp_pay_label((string)$doc['pay_code']), 'isRetail' => $doc['cust_code'] !== '' && $doc['cust_code'] === erp_retail_code(),
        'title' => DOCS_KINDS[$doc['doc_type']]['label'] . ' ' . $doc['doc_code'] . ' · ' . site_name(),
    ], null);
}
