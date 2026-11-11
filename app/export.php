<?php
declare(strict_types=1);

/*
 * Datos → Exportar (/admin/exportar): CSV para Excel en español (separador «;», coma decimal, UTF-8 con BOM, fórmulas
 * neutralizadas con erp_csv_text()). Tres exportaciones, todas por POST con CSRF:
 *   - Artículos (admin y catalogo): referencia, nombre, familia, subfamilia, visible y los campos públicos. Los campos
 *     INTERNOS solo los puede incluir un admin, marcando la casilla.
 *   - Clientes (admin y erp, con la doble verificación si está exigida): sin el cliente de contado salvo que se marque. Nunca
 *     salen las notas internas.
 *   - Ventas (admin y erp, con la misma exigencia): una fila por factura o, con líneas, una por línea.
 * Cada exportación queda en Datos → Historial (aunque la descarga se corte a medias: se anota al terminar el script).
 * Cada exportación tiene una función *_write() que solo escribe filas en un «$put» (se prueba sin HTTP) y otra que hace la descarga.
 */

const EXPORT_WHATS = ['articulos' => 'Artículos', 'clientes' => 'Clientes', 'ventas' => 'Ventas'];

/** ¿Puede este rol pedir esa exportación? */
function export_allowed(string $what, string $role): bool
{
    return match ($what) {
        'articulos' => in_array($role, ['admin', 'catalogo'], true),
        'clientes', 'ventas' => in_array($role, ['admin', 'erp'], true),
        default => false,
    };
}

/** Cabeceras, BOM y escritor de filas. Devuelve [recurso, función que escribe una fila]. */
function export_open(string $base): array
{
    @set_time_limit(300);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $base . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    return [$out, fn(array $row) => fputcsv($out, $row, ';', '"', '\\')];
}

/** Anota la exportación en el Historial al terminar el script (también si la descarga se corta). $detail() devuelve el texto con los números. */
function export_log_at_end(string $what, callable $detail, bool &$done): void
{
    register_shutdown_function(function () use ($what, $detail, &$done) {
        require_once __DIR__ . '/history.php';
        history_add('exportacion', $what, 'Panel (CSV)', $done ? 'ok' : 'parcial', $detail());
    });
}

function export_user_has_2fa(): bool
{
    $st = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $st->execute([(int)($_SESSION['uid'] ?? 0)]);
    return (int)$st->fetchColumn() === 1;
}

/** Valor de un campo del catálogo para el CSV (coma decimal, Sí/No, listas con comas). */
function export_field_value(array $f, string $v): string
{
    if ($v === '') {
        return '';
    }
    return match ($f['kind']) {
        'price', 'number' => str_replace('.', ',', $v),
        'bool' => $v === '1' ? 'Sí' : 'No',
        'multi' => str_replace('|', ', ', $v),
        default => erp_csv_text($v),
    };
}

function admin_export(): void
{
    $role = current_role();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $what = (string)($_POST['what'] ?? '');
        if (!isset(EXPORT_WHATS[$what]) || !export_allowed($what, $role)) {
            flash('Tu usuario no puede hacer esa exportación.', 'err');
            redirect('/admin/exportar');
        }
        if ($what === 'articulos') {
            export_articles();
        }
        if (!erp_guard()) { // clientes y ventas: datos personales → doble verificación si está exigida
            return;
        }
        $what === 'clientes' ? export_customers() : export_sales();
        return;
    }
    $db = db();
    $families = export_allowed('articulos', $role) ? $db->query('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY sort, name')->fetchAll() : [];
    $years = export_allowed('ventas', $role) ? erp_sales_years() : [];
    $fields = fields_all();
    admin_render('export', [
        'canArticles' => export_allowed('articulos', $role), 'canErp' => export_allowed('clientes', $role),
        'families' => $families, 'years' => $years, 'retail' => erp_retail_code(),
        'internalFields' => array_filter($fields, fn($f) => (int)$f['is_public'] === 0),
        'publicFields' => array_filter($fields, fn($f) => (int)$f['is_public'] === 1),
        'isAdmin' => $role === 'admin', 'need2fa' => erp_2fa_required() && !export_user_has_2fa(),
        'totals' => export_allowed('clientes', $role) ? erp_totals() : ['customers' => 0, 'invoices' => 0, 'lines' => 0],
        'title' => 'Exportar',
    ]);
}

/* ---------- Artículos ---------- */

/** Escribe la cabecera y las filas de artículos en $put. $count se incrementa por fila. */
function export_articles_write(callable $put, int $fid, string $vis, bool $withInternal, int &$count): void
{
    $db = db();
    $w = '';
    $a = [];
    if ($fid > 0) {
        $ids = [$fid];
        $st = $db->prepare('SELECT id FROM categories WHERE parent_id = ?');
        $st->execute([$fid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $ids[] = (int)$c;
        }
        $w .= ' AND p.category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($a, ...$ids);
    }
    if ($vis !== '') {
        $w .= ' AND p.visible = ?';
        $a[] = (int)$vis;
    }
    $fields = array_values(array_filter(fields_all(), fn($f) => (int)$f['is_public'] === 1 || $withInternal));
    $values = [];
    if ($fields) {
        $fids = array_map(fn($f) => (int)$f['id'], $fields);
        foreach ($db->query('SELECT product_id, field_id, value FROM product_fields WHERE field_id IN (' . implode(',', $fids) . ')')->fetchAll() as $r) {
            $values[(int)$r['product_id']][(int)$r['field_id']] = (string)$r['value'];
        }
    }
    $put(array_merge(['Referencia', 'Nombre', term('family'), term('sub'), 'Visible'],
        array_map(fn($f) => erp_csv_text((string)$f['name']) . ((int)$f['is_public'] === 0 ? ' (interno)' : ''), $fields)));
    $st = $db->prepare('SELECT p.id, p.ref, p.name, p.visible, c.name AS cname, c.parent_id AS cparent, pc.name AS pname
        FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN categories pc ON pc.id = c.parent_id WHERE 1=1' . $w . ' ORDER BY p.id');
    $st->execute($a);
    while ($r = $st->fetch()) {
        $isSub = $r['cparent'] !== null;
        $row = [erp_csv_text((string)$r['ref']), erp_csv_text((string)$r['name']), erp_csv_text((string)($isSub ? $r['pname'] : $r['cname'])),
            $isSub ? erp_csv_text((string)$r['cname']) : '', (int)$r['visible'] === 1 ? 'Sí' : 'No'];
        foreach ($fields as $f) {
            $row[] = export_field_value($f, $values[(int)$r['id']][(int)$f['id']] ?? '');
        }
        $put($row);
        $count++;
    }
}

function export_articles(): never
{
    $fid = (int)($_POST['familia'] ?? 0);
    $vis = in_array($_POST['visible'] ?? '', ['1', '0'], true) ? (string)$_POST['visible'] : '';
    $withInternal = current_role() === 'admin' && !empty($_POST['internos']); // los internos, solo un admin y marcando la casilla
    $count = 0;
    $done = false;
    // ojo: una función flecha (fn) copiaría $count tal como está ahora (0); hace falta capturarlo por referencia
    export_log_at_end('articulos', function () use (&$count, $fid, $vis, $withInternal) {
        return $count . ' fila' . ($count === 1 ? '' : 's') . ($fid > 0 ? ' · una ' . term('family', true) : '')
            . ($vis !== '' ? ($vis === '1' ? ' · solo visibles' : ' · solo ocultos') : '') . ($withInternal ? ' · con campos internos' : '');
    }, $done);
    [$out, $put] = export_open('articulos');
    export_articles_write($put, $fid, $vis, $withInternal, $count);
    fclose($out);
    $done = true;
    exit;
}

/* ---------- Clientes ---------- */

function export_customers_write(callable $put, bool $withRetail, int &$count): void
{
    $retail = erp_retail_code();
    $skip = !$withRetail && $retail !== '';
    $put(['Código', 'Nombre', 'Nombre comercial', 'NIF', 'Dirección', 'Población', 'Código postal', 'Provincia', 'País', 'Teléfono', 'Teléfono 2', 'Correo', 'Contacto', 'Forma de pago', 'Tarifa', 'Estado', 'Alta', 'Baja']);
    $st = db()->prepare('SELECT * FROM erp_customers' . ($skip ? ' WHERE cust_code <> ?' : '') . ' ORDER BY name, id');
    $st->execute($skip ? [$retail] : []);
    while ($r = $st->fetch()) {
        $state = (int)$r['ended_day'] > 0 ? 'De baja' : ((int)$r['blocked'] === 1 ? 'Bloqueado' : 'Activo');
        $put([erp_csv_text((string)$r['cust_code']), erp_csv_text((string)$r['name']), erp_csv_text((string)$r['trade_name']), erp_csv_text((string)$r['nif']),
            erp_csv_text((string)$r['address']), erp_csv_text((string)$r['city']), erp_csv_text((string)$r['postcode']), erp_csv_text((string)$r['province']), erp_csv_text((string)$r['country']),
            erp_csv_text((string)$r['phone']), erp_csv_text((string)$r['phone2']), erp_csv_text((string)$r['email']), erp_csv_text((string)$r['contact']),
            $r['pay_code'] !== '' ? erp_csv_text(erp_pay_label((string)$r['pay_code'])) : '', (int)$r['tariff'] > 0 ? (string)(int)$r['tariff'] : '', $state,
            (int)$r['created_day'] > 0 ? erp_day((int)$r['created_day']) : '', (int)$r['ended_day'] > 0 ? erp_day((int)$r['ended_day']) : '']);
        $count++;
    }
}

function export_customers(): never
{
    $withRetail = !empty($_POST['contado']);
    $count = 0;
    $done = false;
    export_log_at_end('clientes', function () use (&$count, $withRetail) {
        return $count . ' cliente' . ($count === 1 ? '' : 's') . ($withRetail ? ' · con el cliente de contado' : '');
    }, $done);
    [$out, $put] = export_open('clientes');
    export_customers_write($put, $withRetail, $count);
    fclose($out);
    $done = true;
    exit;
}

/* ---------- Ventas ---------- */

/** $count = filas escritas; $invoices = facturas distintas. */
function export_sales_write(callable $put, array $f, bool $withLines, int &$count, int &$invoices): void
{
    [$cond, $args] = erp_sales_cond($f);
    $retail = erp_retail_code();
    $head = ['Factura', 'Fecha', 'Cliente (código)', 'Cliente', 'Base', 'IVA', 'Total', 'Estado', 'Forma de pago', 'Cobrada', 'Fecha de cobro', 'Vendedor'];
    if ($withLines) {
        $head = array_merge($head, ['Línea', 'Artículo', 'Descripción', 'Talla', 'Color', 'Unidades', 'Precio', 'Dto. %', 'Importe']);
    }
    $put($head);
    $sql = 'SELECT i.id, i.inv_number, i.inv_day, i.cust_code, i.base_cents, i.vat_cents, i.total_cents, i.annulled, i.pay_code, i.seller, c.name AS cname, c.trade_name AS ctrade,
            s.paid, s.paid_day' . ($withLines ? ', l.line_no, l.article_code, l.title, l.size_txt, l.size_no, l.color_txt, l.color_no, l.qty_milli, l.price_cents, l.disc_bp, l.amount_cents' : '')
        . ' FROM erp_invoices i LEFT JOIN erp_customers c ON c.cust_code = i.cust_code LEFT JOIN erp_invoice_state s ON s.invoice_id = i.id'
        . ($withLines ? ' LEFT JOIN erp_invoice_lines l ON l.invoice_id = i.id' : '') . ' WHERE 1=1' . $cond . ' ORDER BY i.inv_day, i.id' . ($withLines ? ', l.line_no' : '');
    $st = db()->prepare($sql);
    $st->execute($args);
    $last = 0;
    while ($r = $st->fetch()) {
        $isRetail = $retail !== '' && (string)$r['cust_code'] === $retail;
        $cname = $isRetail ? 'Cliente de contado' : ((string)$r['ctrade'] !== '' ? (string)$r['ctrade'] : (string)$r['cname']);
        $row = [erp_csv_text((string)$r['inv_number']), erp_day((int)$r['inv_day']), erp_csv_text((string)$r['cust_code']), erp_csv_text($cname),
            erp_csv_num((int)$r['base_cents']), erp_csv_num((int)$r['vat_cents']), erp_csv_num((int)$r['total_cents']),
            (int)$r['annulled'] === 1 ? 'anulada' : ((int)$r['total_cents'] < 0 ? 'devolución' : ''),
            $r['pay_code'] !== '' ? erp_csv_text(erp_pay_label((string)$r['pay_code'])) : '',
            (int)($r['paid'] ?? 0) === 1 ? 'Sí' : 'No', (int)($r['paid'] ?? 0) === 1 && (int)$r['paid_day'] > 0 ? erp_day((int)$r['paid_day']) : '', erp_csv_text((string)$r['seller'])];
        if ($withLines) {
            $size = (string)($r['size_txt'] ?? '') !== '' ? (string)$r['size_txt'] : ((int)($r['size_no'] ?? 0) > 0 ? 'nº ' . $r['size_no'] : '');
            $color = (string)($r['color_txt'] ?? '') !== '' ? (string)$r['color_txt'] : ((int)($r['color_no'] ?? 0) > 0 ? 'nº ' . $r['color_no'] : '');
            $row = array_merge($row, [$r['line_no'] === null ? '' : (string)$r['line_no'], erp_csv_text((string)$r['article_code']), erp_csv_text((string)$r['title']),
                erp_csv_text($size), erp_csv_text($color),
                $r['qty_milli'] === null ? '' : erp_qty((int)$r['qty_milli']), $r['price_cents'] === null ? '' : erp_csv_num((int)$r['price_cents']),
                $r['disc_bp'] === null ? '' : erp_csv_num((int)$r['disc_bp']), $r['amount_cents'] === null ? '' : erp_csv_num((int)$r['amount_cents'])]);
        }
        $put($row);
        if ((int)$r['id'] !== $last) {
            $invoices++;
            $last = (int)$r['id'];
        }
        $count++;
    }
}

function export_sales(): never
{
    $f = erp_sales_filters(array_intersect_key($_POST, ['anio' => 1, 'mes' => 1, 'ambito' => 1]));
    $withLines = !empty($_POST['lineas']);
    $count = 0;
    $invoices = 0;
    $done = false;
    export_log_at_end('ventas', function () use (&$count, &$invoices, $withLines, $f) {
        return $invoices . ' factura' . ($invoices === 1 ? '' : 's') . ($withLines ? ' · ' . $count . ' fila' . ($count === 1 ? '' : 's') . ' con líneas' : '')
            . (!empty($f['anio']) ? ' · año ' . $f['anio'] : '') . (!empty($f['mes']) ? ' · mes ' . $f['mes'] : '');
    }, $done);
    [$out, $put] = export_open('ventas' . (!empty($f['anio']) ? '-' . $f['anio'] . (!empty($f['mes']) ? '-' . sprintf('%02d', $f['mes']) : '') : ''));
    export_sales_write($put, $f, $withLines, $count, $invoices);
    fclose($out);
    $done = true;
    exit;
}
