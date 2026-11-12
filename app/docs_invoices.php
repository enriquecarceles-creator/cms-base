<?php
declare(strict_types=1);

/*
 * Facturas propias del CMS (fase 2 del motor de documentos, app/docs_engine.php): factura completa, factura simplificada (tique)
 * y factura rectificativa. Conviven con las facturas importadas de NCS, con series de otras letras.
 *
 * Ciclo de vida (reglas legales):
 *   borrador  sin número; se modifica y se borra libremente.
 *   «Emitir»  docs_issue(): comprueba los datos obligatorios, toma el SIGUIENTE número de la serie (sin huecos: si algo falla,
 *             la transacción se deshace y el número vuelve), exige fecha no anterior a la última emitida de la serie y no futura,
 *             anota la factura en el registro encadenado (doc_register) y guarda su copia en erp_invoices (origin = 'cms') para que
 *             Ventas, Informes, la ficha del cliente, la exportación, el cobro y el envío por email la traten como cualquier otra.
 *   emitida   ya no se modifica, no se anula ni se borra (docs_save lo impide en la propia sentencia SQL). Se corrige con una
 *             rectificativa (docs_rectify): por diferencias (líneas en negativo) o por sustitución (la original queda «sustituida»
 *             y deja de contar en los informes: su copia en erp_invoices pasa a annulled = 1).
 *
 * La empresa presenta el SII (excluida de VERI*FACTU): el registro encadenado no es obligatorio, pero deja constancia de
 * cualquier alteración y el libro de facturas expedidas se exporta para el SII (export.php).
 */

/** Texto de la marca de documento de prueba. */
function docs_test_text(): string
{
    $t = trim(docp('test_text'));
    return $t !== '' ? mb_substr($t, 0, 120) : DOCP_DEFAULTS['test_text'];
}

/** ¿Es una factura de prueba? */
function docs_is_test(array $doc): bool
{
    return (int)($doc['is_test'] ?? 0) === 1;
}

/** Cuántas facturas de prueba hay (emitidas). */
function docs_test_count(): int
{
    return (int)db()->query("SELECT COUNT(*) FROM doc_headers WHERE is_test = 1")->fetchColumn();
}

/**
 * Borra TODAS las facturas de prueba (y los borradores de rectificativa que cuelgan de ellas). Los albaranes que facturaban vuelven a estar
 * pendientes de facturar y sus pedidos se recalculan. Devuelve cuántas facturas se han borrado.
 */
function docs_test_purge(): int
{
    $db = db();
    $db->beginTransaction();
    try {
        $ids = array_map('intval', $db->query('SELECT id FROM doc_headers WHERE is_test = 1')->fetchAll(PDO::FETCH_COLUMN));
        foreach ($ids as $id) {
            foreach ($db->query("SELECT id FROM doc_headers WHERE doc_type IN ('albaran', 'albaran_c') AND billed_by = " . $id)->fetchAll(PDO::FETCH_COLUMN) as $aid) {
                $db->prepare("UPDATE doc_headers SET status = 'pendiente', billed_by = 0 WHERE id = ?")->execute([(int)$aid]);
                docs_event((int)$aid, 'liberado', 'Factura de prueba borrada');
                docs_refresh_orders(docs_source_orders((int)$aid));
            }
            $drafts = $db->query("SELECT id FROM doc_headers WHERE status = 'borrador' AND rect_of = " . $id)->fetchAll(PDO::FETCH_COLUMN);
            foreach ([...$drafts, $id] as $did) {
                $did = (int)$did;
                foreach (['doc_lines', 'doc_events', 'doc_dues'] as $t) {
                    $db->exec("DELETE FROM $t WHERE doc_id = $did");
                }
                $db->exec("DELETE FROM doc_headers WHERE id = $did");
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return count($ids);
}

/** /admin/facturas/pruebas: confirmación para borrar las facturas de prueba (solo administradores). */
function admin_docs_test_purge(): void
{
    if (!is_admin()) {
        flash('Solo un administrador puede borrar las facturas de prueba.', 'err');
        redirect('/admin/facturas');
    }
    $n = docs_test_count();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if ($n === 0) {
            redirect('/admin/facturas');
        }
        $del = docs_test_purge();
        history_add('configuracion', 'pruebas', 'manual', 'ok', $del . ' facturas de prueba borradas');
        flash($del . ' factura' . ($del === 1 ? '' : 's') . ' de prueba borrada' . ($del === 1 ? '' : 's') . '. Los albaranes que facturaban vuelven a estar pendientes.');
        redirect('/admin/facturas');
    }
    $codes = db()->query("SELECT doc_code FROM doc_headers WHERE is_test = 1 ORDER BY id LIMIT 30")->fetchAll(PDO::FETCH_COLUMN);
    admin_render('docs_purge', ['n' => $n, 'codes' => $codes, 'on' => docp_bool('test_mode'), 'title' => 'Facturas de prueba', 'crumb' => 'Facturas de prueba']);
}

/** Errores que impiden emitir el borrador (vacío = se puede emitir). */
function docs_issue_problems(array $doc, array $lines): array
{
    $e = [];
    $kind = (string)$doc['doc_type'];
    if ($doc['status'] !== 'borrador') {
        return ['Esta factura ya está emitida.'];
    }
    if (!$lines) {
        $e[] = 'La factura no tiene líneas.';
    }
    $day = (int)$doc['doc_day'];
    if (period_is_locked($day)) {
        $e[] = period_lock_message($day);
    }
    if ($day > (int)date('Ymd')) {
        $e[] = 'La fecha de la factura no puede ser futura.';
    }
    if (trim(setting('company_name')) === '' || trim(setting('tax_id')) === '') {
        $e[] = 'Faltan el nombre fiscal o el NIF de la empresa (Configuración → Empresa): son obligatorios en una factura.';
    }
    if ($kind !== 'simplificada') {
        foreach (['cust_name' => 'el nombre', 'cust_nif' => 'el NIF', 'cust_address' => 'la dirección'] as $k => $label) {
            if (trim((string)$doc[$k]) === '') {
                $e[] = 'Falta ' . $label . ' del cliente: es obligatorio en una factura completa.';
            }
        }
    }
    $total = (int)$doc['total_cents'];
    if ($kind === 'simplificada') {
        $limit = docp_bool('retail_trade') ? DOCS_TIQUE_LIMIT_RETAIL : DOCS_TIQUE_LIMIT;
        if ($total > $limit) {
            $e[] = 'Una factura simplificada no puede pasar de ' . erp_money($limit) . ' (IVA incluido): haz una factura completa.';
        }
    }
    if ($kind !== 'rectificativa' && $total < 0) {
        $e[] = 'El total es negativo: para devolver o descontar, haz una rectificativa de la factura original.';
    }
    if ($kind === 'rectificativa') {
        $orig = docs_get((int)$doc['rect_of']);
        if (!$orig || !docs_is_invoice((string)$orig['doc_type']) || $orig['status'] !== 'emitida') {
            $e[] = 'No se encuentra la factura que se rectifica (tiene que estar emitida).';
        } elseif (docs_is_test($orig) !== docp_bool('test_mode')) {
            $e[] = docp_bool('test_mode')
                ? 'La factura que rectificas es real y el modo de prueba está activado: desactívalo (Configuración → Parámetros) para emitir esta rectificativa.'
                : 'La factura que rectificas es de prueba: borra las facturas de prueba en lugar de rectificarla.';
        } elseif ((int)$orig['doc_day'] > $day) {
            $e[] = 'La rectificativa no puede tener fecha anterior a la factura que rectifica (' . erp_day((int)$orig['doc_day']) . ').';
        }
        if (!isset(DOCS_RECT_REASONS[(string)$doc['rect_reason']]) || !isset(DOCS_RECT_MODES[(string)$doc['rect_mode']])) {
            $e[] = 'Falta el motivo o el modo de la rectificación.';
        }
    }
    $s = series_get((int)$doc['series_id']);
    if (!$s || $s['doc_type'] !== $kind || (int)$s['is_active'] !== 1) {
        $e[] = 'La serie del borrador no está activa o no es de este tipo (Configuración → Series).';
    } else {
        $st = db()->prepare("SELECT MAX(doc_day) FROM doc_headers WHERE series_id = ? AND status = 'emitida' AND is_test = 0" . ((int)$s['with_year'] === 1 ? ' AND yr = ?' : ''));
        $st->execute((int)$s['with_year'] === 1 ? [(int)$s['id'], intdiv($day, 10000)] : [(int)$s['id']]);
        $last = (int)$st->fetchColumn();
        if ($last > $day) { // numeración correlativa: un número mayor no puede llevar fecha anterior
            $e[] = 'La última factura emitida de la serie ' . $s['letter'] . ' es del ' . erp_day($last) . ': esta no puede llevar una fecha anterior.';
        }
    }
    return array_merge($e, dues_issue_problems($doc));
}

/** Clave del SII del tipo de factura: F1 completa, F2 simplificada, R1-R5 rectificativas. */
function docs_sii_type(array $doc): string
{
    return match ((string)$doc['doc_type']) {
        'simplificada' => 'F2',
        'rectificativa' => (string)$doc['rect_reason'],
        default => 'F1',
    };
}

/** Datos fiscales que se anotan (y encadenan) en el registro: siempre en el mismo orden y formato. */
function docs_register_payload(array $doc, ?string $issuerNif = null): string
{
    $orig = (int)$doc['rect_of'] > 0 ? docs_get((int)$doc['rect_of']) : null;
    return (string)json_encode([
        'v' => 1, 'emisor_nif' => $issuerNif ?? setting('tax_id'), 'numero' => (string)$doc['doc_code'], 'fecha' => (int)$doc['doc_day'],
        'fecha_operacion' => (int)$doc['op_day'], 'tipo' => (string)$doc['sii_type'], 'cliente_nif' => (string)$doc['cust_nif'],
        'cliente' => (string)$doc['cust_name'], 'base' => (int)$doc['base_cents'], 'cuota' => (int)$doc['vat_cents'],
        'recargo' => (int)$doc['rec_cents'], 'total' => (int)$doc['total_cents'], 'iva' => json_decode((string)$doc['vat_json'], true) ?: [],
        'rectifica' => $orig ? (string)$orig['doc_code'] : '', 'modo' => (string)$doc['rect_mode'], 'emitida' => (int)$doc['issued_at'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Añade la factura al registro encadenado (dentro de la transacción de la emisión). Devuelve la huella. */
function docs_register_append(array $doc): string
{
    $db = db();
    $prev = (string)$db->query('SELECT hash FROM doc_register ORDER BY id DESC LIMIT 1')->fetchColumn();
    $payload = docs_register_payload($doc);
    $hash = hash('sha256', $prev . $payload);
    $db->prepare('INSERT INTO doc_register (doc_id, doc_code, issued_at, payload, prev_hash, hash) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([(int)$doc['id'], (string)$doc['doc_code'], (int)$doc['issued_at'], $payload, $prev, $hash]);
    $db->prepare('UPDATE doc_headers SET reg_hash = ? WHERE id = ?')->execute([$hash, (int)$doc['id']]);
    return $hash;
}

/**
 * Comprueba el registro: la cadena de huellas y que cada factura emitida siga teniendo los mismos datos que cuando se emitió.
 * Devuelve ['n' => facturas comprobadas, 'problems' => [texto]].
 */
function docs_register_verify(): array
{
    $db = db();
    $prev = '';
    $n = 0;
    $problems = [];
    foreach ($db->query('SELECT * FROM doc_register ORDER BY id')->fetchAll() as $r) {
        $n++;
        if ((string)$r['prev_hash'] !== $prev || hash('sha256', $prev . (string)$r['payload']) !== (string)$r['hash']) {
            $problems[] = 'La cadena del registro no cuadra en la factura ' . $r['doc_code'] . '.';
        }
        $doc = docs_get((int)$r['doc_id']);
        $nif = (string)(json_decode((string)$r['payload'], true)['emisor_nif'] ?? ''); // el NIF de la empresa al emitir (puede cambiar después)
        if (!$doc || $doc['doc_code'] !== $r['doc_code'] || $doc['status'] !== 'emitida' || docs_register_payload($doc, $nif) !== (string)$r['payload'] || $doc['reg_hash'] !== $r['hash']) {
            $problems[] = 'La factura ' . $r['doc_code'] . ' no coincide con lo que se anotó al emitirla.';
        }
        $prev = (string)$r['hash'];
    }
    $missing = (int)$db->query("SELECT COUNT(*) FROM doc_headers h WHERE h.is_test = 0 AND h.status = 'emitida' AND h.doc_type IN ('factura','simplificada','rectificativa')
        AND NOT EXISTS (SELECT 1 FROM doc_register r WHERE r.doc_id = h.id)")->fetchColumn();
    if ($missing > 0) {
        $problems[] = $missing . ' factura(s) emitida(s) no están en el registro.';
    }
    return ['n' => $n, 'problems' => $problems];
}

/** Copia de la factura emitida en erp_invoices / erp_invoice_lines (origin = 'cms'), si aún no está. */
function docs_mirror(array $doc): void
{
    $db = db();
    $st = $db->prepare('SELECT id FROM erp_invoices WHERE inv_number = ?');
    $st->execute([(string)$doc['doc_code']]);
    if ($st->fetchColumn() !== false) {
        return;
    }
    $lines = docs_lines((int)$doc['id']);
    $vat = [];
    foreach (json_decode((string)$doc['vat_json'], true) ?: [] as $i => $r) {
        $vat[] = ['i' => $i, 'pct' => (float)$r['pct'], 'base' => (int)$r['base'], 'cuota' => (int)$r['cuota'], 'rec' => (int)$r['rec'], 'ret' => 0];
    }
    $orig = (int)$doc['rect_of'] > 0 ? docs_get((int)$doc['rect_of']) : null;
    $cust = (string)$doc['cust_code'];
    if ($cust === '' && $doc['doc_type'] === 'simplificada') {
        $cust = erp_retail_code(); // tique sin cliente: como los tickets de mostrador
    }
    $day = (int)$doc['doc_day'];
    $row = [
        'inv_number' => (string)$doc['doc_code'], 'cust_code' => $cust, 'inv_day' => $day, 'inv_year' => intdiv($day, 10000), 'inv_month' => intdiv($day, 100) % 100,
        'subtotal_cents' => (int)round(array_sum(array_column($lines, 'amount_u')) / (DOCS_AMOUNT / 100)), 'base_cents' => (int)$doc['base_cents'],
        'vat_cents' => (int)$doc['vat_cents'], 'total_cents' => (int)$doc['total_cents'], 'oper' => '', 'seller' => mb_substr((string)$doc['issued_by'], 0, 100),
        'pay_code' => (string)$doc['pay_code'], 'annulled' => 0, 'rectifies' => $orig ? (string)$orig['doc_code'] : '', 'iva_incl' => (int)$doc['vat_incl'],
        'vat_json' => (string)json_encode($vat), 'origin' => 'cms', 'delivered_cents' => 0, 'row_hash' => 'cms', 'synced_at' => 0,
    ];
    $cols = array_keys($row);
    $db->prepare('INSERT INTO erp_invoices (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
    $st->execute([(string)$doc['doc_code']]);
    $iid = (int)$st->fetchColumn();
    $ins = $db->prepare('INSERT INTO erp_invoice_lines (invoice_id, src_key, line_no, article_code, title, qty_milli, price_cents, disc_bp, amount_cents, vat_code, size_no, color_no, size_txt, color_txt, kind, detail, extra)
        VALUES (?,?,?,?,?,?,?,?,?,?,0,0,?,?,?,?,?)');
    foreach ($lines as $i => $l) {
        $ins->execute([$iid, 'cms:' . (int)$l['id'], $i + 1, mb_substr((string)$l['ref'], 0, 191), (string)$l['title'], (int)round((int)$l['qty_u'] / 10),
            (int)round((int)$l['price_u'] / (DOCS_PRICE / 100)), (int)$l['disc_bp'], (int)round((int)$l['amount_u'] / (DOCS_AMOUNT / 100)),
            vat_format_pct((int)$l['vat_bp']), (string)$l['size_txt'], (string)$l['color_txt'], (int)$l['kind'], (string)$l['detail'], (string)($l['extra'] ?? '')]);
    }
}

/** Facturas sustituidas (rectificadas por sustitución): su copia en erp_invoices deja de contar (annulled = 1). */
function docs_mirror_mark_substituted(): void
{
    db()->exec("UPDATE erp_invoices SET annulled = 1 WHERE origin = 'cms' AND annulled = 0 AND inv_number IN (SELECT o.doc_code FROM doc_headers r
        JOIN doc_headers o ON o.id = r.rect_of WHERE r.doc_type = 'rectificativa' AND r.status = 'emitida' AND r.rect_mode = 'sustitucion')");
}

/**
 * Repone las copias que falten (p. ej. tras restaurar una copia de seguridad sin «Incluir clientes y ventas»: las facturas del CMS
 * viajan siempre, pero erp_invoices no). Barato: una consulta si no falta nada.
 */
function docs_mirror_sync(): void
{
    try {
        $db = db();
        $ids = $db->query("SELECT h.id FROM doc_headers h WHERE h.is_test = 0 AND h.status = 'emitida' AND h.doc_type IN ('factura','simplificada','rectificativa')
            AND NOT EXISTS (SELECT 1 FROM erp_invoices e WHERE e.inv_number = h.doc_code)")->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) {
            return;
        }
        $db->beginTransaction();
        foreach ($ids as $id) {
            docs_mirror(docs_get((int)$id));
        }
        docs_mirror_mark_substituted();
        $db->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        error_log('Copia de facturas del CMS: ' . get_class($e));
    }
}

/** Emite el borrador: número de la serie sin huecos, registro, copia en Ventas y albaranes facturados. Devuelve el número. */
function docs_issue(int $id, int $rev = -1): string
{
    $db = db();
    if ($db->query("SELECT COUNT(*) FROM settings WHERE key = 'doc_register_lock'")->fetchColumn() == 0) {
        save_setting('doc_register_lock', '1');
    }
    $db->beginTransaction();
    try {
        // Fila de bloqueo: dos emisiones a la vez se ponen en fila (la cadena del registro necesita la huella anterior)
        $db->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([(string)microtime(true), 'doc_register_lock']);
        $doc = docs_get($id);
        if (!$doc || !docs_is_invoice((string)$doc['doc_type'])) {
            throw new DomainException('No es una factura.');
        }
        if ($rev >= 0 && (int)$doc['rev'] !== $rev) {
            throw new DomainException(DOCS_CONFLICT_MSG);
        }
        $problems = docs_issue_problems($doc, docs_lines($id));
        if ($problems) {
            throw new DomainException(implode(' ', $problems));
        }
        dues_finalize($doc); // los vencimientos quedan calculados y fijos con la factura
        $s = series_get((int)$doc['series_id']);
        $year = intdiv((int)$doc['doc_day'], 10000);
        $test = docp_bool('test_mode'); // modo de prueba: numeración aparte, sin tocar la serie ni el registro
        if ($test) {
            $no = (int)$db->query('SELECT COALESCE(MAX(doc_no), 0) FROM doc_headers WHERE is_test = 1')->fetchColumn() + 1;
            $num = ['number' => $no, 'formatted' => 'PRUEBA-' . str_pad((string)$no, 5, '0', STR_PAD_LEFT)];
        } else {
            $num = series_next_number((int)$s['id'], $year);
        }
        $st = $db->prepare('SELECT COUNT(*) FROM doc_headers WHERE doc_code = ?');
        $st->execute([$num['formatted']]);
        if ((int)$st->fetchColumn() > 0) { // en facturas NO se saltan números
            throw new DomainException('El número ' . $num['formatted'] . ' ya existe (el contador de la serie va por detrás, ¿se restauró una copia antigua?). Revisa Configuración → Series antes de emitir.');
        }
        $now = time();
        $user = (string)(current_user()['username'] ?? '');
        $sii = docs_sii_type($doc);
        $up = $db->prepare("UPDATE doc_headers SET yr = ?, doc_no = ?, doc_code = ?, status = 'emitida', issued_at = ?, issued_by = ?, sii_type = ?, updated_at = ?, updated_by = ?, is_test = ?, rev = rev + 1
            WHERE id = ? AND status = 'borrador'");
        $up->execute([!$test && (int)$s['with_year'] === 1 ? $year : 0, $num['number'], $num['formatted'], $now, $user, $sii, $now, $user, $test ? 1 : 0, $id]);
        if ($up->rowCount() !== 1) {
            throw new DomainException('Esta factura ya se ha emitido.');
        }
        $doc = docs_get($id);
        if (!$test) { // las de prueba no entran en el registro encadenado ni en Ventas
            docs_register_append($doc);
            $doc = docs_get($id);
            docs_mirror($doc);
        }
        foreach ($db->query("SELECT id, doc_code FROM doc_headers WHERE doc_type = 'albaran' AND billed_by = " . $id)->fetchAll() as $a) {
            $db->prepare("UPDATE doc_headers SET status = 'facturado' WHERE id = ?")->execute([(int)$a['id']]);
            docs_event((int)$a['id'], 'facturado', 'Factura ' . $num['formatted']);
            docs_refresh_orders(docs_source_orders((int)$a['id'])); // el pedido pasa a «Facturado» si ya no le queda ningún albarán por facturar
        }
        if ($doc['doc_type'] === 'rectificativa') {
            docs_event((int)$doc['rect_of'], 'rectificada', 'Rectificativa ' . $num['formatted'] . ' (' . mb_strtolower(DOCS_RECT_MODES[$doc['rect_mode']] ?? '') . ')');
            if (!$test) {
                docs_mirror_mark_substituted();
            }
        }
        docs_event($id, 'emitida', $num['formatted']);
        $db->commit();
        return $num['formatted'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Crea el borrador de una rectificativa de una factura emitida. Devuelve su id o lanza DomainException. */
function docs_rectify(array $orig, string $mode, string $reason, string $text): int
{
    if (!docs_is_invoice((string)$orig['doc_type']) || $orig['status'] !== 'emitida') {
        throw new DomainException('Solo se rectifican facturas emitidas.');
    }
    if (!isset(DOCS_RECT_MODES[$mode])) {
        throw new DomainException('Elige cómo se rectifica (por diferencias o por sustitución).');
    }
    if ($orig['doc_type'] === 'simplificada') {
        $reason = 'R5'; // rectificación de factura simplificada
    } elseif ($reason === 'R5' || !isset(DOCS_RECT_REASONS[$reason])) {
        throw new DomainException('Elige el motivo de la rectificación.');
    }
    $text = erp_txt($text, 200);
    if ($text === '') {
        throw new DomainException('Escribe en pocas palabras qué se corrige (sale en la factura rectificativa).');
    }
    $today = (int)date('Ymd');
    if (period_is_locked($today)) {
        throw new DomainException(period_lock_message($today));
    }
    $s = series_default_for('rectificativa');
    if (!$s) {
        throw new DomainException('No hay ninguna serie activa de facturas rectificativas (Configuración → Series).');
    }
    $doc = array_merge(docs_blank('rectificativa'), array_intersect_key($orig, array_flip([...DOCS_HEAD_COLS, 'cash_crit'])));
    $doc['series_id'] = (int)$s['id'];
    $doc['doc_day'] = $today;
    $doc['op_day'] = 0;
    $doc['rect_of'] = (int)$orig['id'];
    $doc['rect_mode'] = $mode;
    $doc['rect_reason'] = $reason;
    $doc['rect_text'] = $text;
    $doc['ref_text'] = 'Rectifica ' . $orig['doc_code'];
    $doc['notes'] = '';
    $lines = [];
    foreach (docs_lines((int)$orig['id']) as $l) {
        $nl = array_intersect_key($l, array_flip(DOCS_LINE_COLS));
        $nl['id'] = 0;
        $nl['src_line_id'] = 0;
        if ($mode === 'diferencias') { // se parte de la devolución completa: se quitan las líneas que no cambian y se ajustan las demás
            $nl['qty_u'] = -(int)$l['qty_u'];
        }
        $nl['amount_u'] = docs_line_amount((int)$nl['qty_u'], (int)$nl['price_u'], (int)$nl['disc_bp']);
        $lines[] = $nl;
    }
    $tot = docs_totals($lines, (bool)$doc['vat_incl'], (bool)$doc['with_re']);
    $doc['base_cents'] = $tot['base'];
    $doc['vat_cents'] = $tot['vat_cents'];
    $doc['rec_cents'] = $tot['rec'];
    $doc['total_cents'] = $tot['total'];
    $doc['vat_json'] = (string)json_encode($tot['vat']);
    return docs_save($doc, $lines, -1, 'desde', 'Rectificación de ' . $orig['doc_code']);
}

/**
 * Factura (borrador) con uno o varios albaranes del mismo cliente. Las líneas enlazan con las de los albaranes (src_line_id) y los
 * albaranes quedan reservados (billed_by) hasta que se emite (pasan a «Facturado») o se borra el borrador (quedan libres).
 */
function docs_bill(array $albIds): int
{
    $albIds = array_values(array_unique(array_filter(array_map('intval', $albIds))));
    if (!$albIds) {
        throw new DomainException('Marca al menos un albarán.');
    }
    $albs = [];
    foreach ($albIds as $aid) {
        $a = docs_get($aid);
        if (!$a || !in_array($a['doc_type'], ['albaran', 'albaran_c'], true) || ($albs && $a['doc_type'] !== $albs[0]['doc_type'])) {
            throw new DomainException('Algún documento marcado no es un albarán (o mezclas albaranes de venta y de compra).');
        }
        if ($a['status'] !== 'pendiente' || (int)$a['billed_by'] > 0) {
            throw new DomainException('El albarán ' . $a['doc_code'] . ' ya está facturado, anulado o en otro borrador de factura.');
        }
        $albs[] = $a;
    }
    $key = fn(array $a) => $a['cust_code'] !== '' ? 'c:' . $a['cust_code'] : 'n:' . mb_strtolower(trim($a['cust_name'] . '|' . $a['cust_nif']));
    $first = $albs[0];
    foreach ($albs as $a) {
        if ($key($a) !== $key($first)) {
            throw new DomainException('Los albaranes son de clientes distintos: una factura solo puede reunir albaranes del mismo cliente.');
        }
        if ((int)$a['vat_incl'] !== (int)$first['vat_incl'] || (int)$a['with_re'] !== (int)$first['with_re']) {
            throw new DomainException('Los albaranes no tienen los mismos ajustes de IVA (IVA incluido / recargo de equivalencia): factúralos por separado.');
        }
    }
    $today = (int)date('Ymd');
    if (period_is_locked($today)) {
        throw new DomainException(period_lock_message($today));
    }
    $billType = docs_side((string)$first['doc_type'], 'factura');
    $s = series_default_for($billType);
    if (!$s) {
        throw new DomainException('No hay ninguna serie activa de ' . mb_strtolower(docs_kind($billType)['plural']) . ' (Configuración → Parámetros → Series).');
    }
    usort($albs, fn($x, $y) => [(int)$x['doc_day'], (int)$x['id']] <=> [(int)$y['doc_day'], (int)$y['id']]);
    $doc = array_merge(docs_blank($billType), array_intersect_key($first, array_flip(DOCS_HEAD_COLS)));
    $doc['sup_ref'] = '';
    $doc['series_id'] = (int)$s['id'];
    $doc['doc_day'] = $today;
    $doc['origin_id'] = (int)$first['id'];
    $doc['ref_text'] = '';
    $doc['op_day'] = 0;
    $doc['vat_note'] = '';
    $doc['notes'] = 'Albaranes: ' . implode(', ', array_map(fn($a) => $a['doc_code'] . ' (' . erp_day((int)$a['doc_day']) . ')', $albs));
    $lines = [];
    foreach ($albs as $a) {
        foreach (docs_lines((int)$a['id']) as $l) {
            $nl = array_intersect_key($l, array_flip(DOCS_LINE_COLS));
            $nl['id'] = 0;
            $nl['src_line_id'] = (int)$l['id'];
            $lines[] = $nl;
        }
    }
    $tot = docs_totals($lines, (bool)$doc['vat_incl'], (bool)$doc['with_re']);
    $doc['base_cents'] = $tot['base'];
    $doc['vat_cents'] = $tot['vat_cents'];
    $doc['rec_cents'] = $tot['rec'];
    $doc['total_cents'] = $tot['total'];
    $doc['vat_json'] = (string)json_encode($tot['vat']);
    $db = db();
    $db->beginTransaction();
    try {
        $fid = docs_save($doc, $lines, -1, 'desde', 'Albaranes ' . implode(', ', array_column($albs, 'doc_code')));
        $up = $db->prepare("UPDATE doc_headers SET billed_by = ? WHERE id = ? AND doc_type = '" . ($billType === 'factura_c' ? 'albaran_c' : 'albaran') . "' AND status = 'pendiente' AND billed_by = 0");
        foreach ($albs as $a) {
            $up->execute([$fid, (int)$a['id']]);
            if ($up->rowCount() !== 1) { // otra persona lo ha facturado a la vez
                throw new DomainException('El albarán ' . $a['doc_code'] . ' acaba de facturarse en otro borrador.');
            }
            docs_event((int)$a['id'], 'reservado', 'Borrador de factura');
        }
        $db->commit();
        return $fid;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Borra un borrador (de factura, pedido o albarán; nunca un documento con número) y deja libres sus albaranes. */
function docs_delete_draft(array $doc): void
{
    if ($doc['status'] !== 'borrador') {
        throw new DomainException('Solo se pueden borrar borradores: un documento con número no se borra nunca' . (docs_is_invoice((string)$doc['doc_type']) ? '' : ' (se anula)') . '.');
    }
    $db = db();
    $db->beginTransaction();
    try {
        $id = (int)$doc['id'];
        $del = $db->prepare("DELETE FROM doc_headers WHERE id = ? AND status = 'borrador'");
        $del->execute([$id]);
        if ($del->rowCount() !== 1) {
            throw new DomainException('Este documento ya no es un borrador: no se puede borrar.');
        }
        foreach ($db->query("SELECT id FROM doc_headers WHERE doc_type = 'albaran' AND billed_by = " . $id)->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $db->prepare('UPDATE doc_headers SET billed_by = 0 WHERE id = ?')->execute([(int)$aid]);
            docs_event((int)$aid, 'liberado');
        }
        $db->prepare('DELETE FROM doc_lines WHERE doc_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM doc_events WHERE doc_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM doc_dues WHERE doc_id = ?')->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Libro de facturas expedidas (para el SII): una fila por factura y tipo de IVA. Escribe con $put(array $fila). Devuelve cuántas facturas. */
function docs_sii_book_write(callable $put, int $from, int $to): int
{
    $put(['Serie y número', 'Fecha de expedición', 'Fecha de operación', 'Tipo de factura (SII)', 'Tipo de rectificativa', 'Factura rectificada',
        'Fecha factura rectificada', 'NIF destinatario', 'Nombre destinatario', 'Base imponible', 'Tipo IVA %', 'Cuota IVA', 'Tipo recargo %', 'Cuota recargo',
        'Total factura', 'Criterio de caja', 'Mención', 'Huella del registro']);
    $st = db()->prepare("SELECT * FROM doc_headers WHERE is_test = 0 AND status = 'emitida' AND doc_type IN ('factura','simplificada','rectificativa') AND doc_day BETWEEN ? AND ? ORDER BY doc_day, id");
    $st->execute([$from, $to]);
    $n = 0;
    $money = fn(int $c) => number_format($c / 100, 2, ',', '');
    foreach ($st->fetchAll() as $d) {
        $n++;
        $orig = (int)$d['rect_of'] > 0 ? docs_get((int)$d['rect_of']) : null;
        $rows = json_decode((string)$d['vat_json'], true) ?: [['pct' => 0, 'rec_pct' => 0, 'base' => 0, 'cuota' => 0, 'rec' => 0]];
        foreach ($rows as $i => $r) {
            $put([
                $d['doc_code'], erp_day((int)$d['doc_day']), (int)$d['op_day'] > 0 ? erp_day((int)$d['op_day']) : '', $d['sii_type'],
                $d['doc_type'] === 'rectificativa' ? ($d['rect_mode'] === 'sustitucion' ? 'S' : 'I') : '', $orig ? $orig['doc_code'] : '',
                $orig ? erp_day((int)$orig['doc_day']) : '', erp_csv_text((string)$d['cust_nif']), erp_csv_text((string)$d['cust_name']), $money((int)$r['base']),
                str_replace('.', ',', (string)(float)$r['pct']), $money((int)$r['cuota']), str_replace('.', ',', (string)(float)($r['rec_pct'] ?? 0)), $money((int)$r['rec']),
                $i === 0 ? $money((int)$d['total_cents']) : '', (int)$d['cash_crit'] === 1 ? 'Sí' : 'No', erp_csv_text((string)$d['vat_note']), $i === 0 ? $d['reg_hash'] : '',
            ]);
        }
    }
    return $n;
}
