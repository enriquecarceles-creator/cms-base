<?php
declare(strict_types=1);

/*
 * Compras y proveedores. Reutiliza el motor de documentos (app/docs_engine.php): los documentos de compra son los mismos registros de
 * doc_headers / doc_lines con otro tipo (pedido_c, albaran_c, factura_c, rectificativa_c) y el «cliente» del documento es el proveedor
 * (erp_suppliers). Aquí solo está lo propio de compras:
 *   - Proveedores: lista, ficha compacta (consulta / modificar), alta, bloqueo, borrado (sin documentos) y notas internas.
 *   - Factura recibida: número del proveedor (obligatorio, avisa si se repite), número de REGISTRO interno correlativo por año
 *     (serie «factura_c», p. ej. RE00001/26, se toma al «Cerrar» sin huecos), cierre, rectificativa recibida y pagos de los vencimientos.
 *   - Acceso: permiso «Compras» separado de Ventas (rol «compras» y administradores) con su propia doble verificación (purch_require_2fa).
 * Las compras NO tocan el stock (viene de NCS y la sincronización lo sobrescribiría): el ajuste docp_purch_stock queda preparado y apagado.
 */

const PURCH_PAGES = ['compras', 'proveedores'];
const PURCH_PER_PAGE = 50;
const PURCH_SUP_FIELDS = ['name' => 255, 'trade_name' => 255, 'nif' => 40, 'contact' => 255, 'phone' => 40, 'phone2' => 40, 'email' => 190,
    'address' => 255, 'postcode' => 20, 'city' => 255, 'province' => 255, 'country' => 255];

/* ---------- Acceso ---------- */

/** ¿Se exige la doble verificación para Compras? Activada salvo que el ajuste valga «0» (igual que Clientes y Ventas). */
function purch_2fa_required(): bool
{
    return setting('purch_require_2fa', '1') !== '0'; // ojo: db_setting() devuelve '' para '0'
}

/** Si se exige y el usuario no la tiene, enseña el aviso y devuelve false. */
function purch_guard(): bool
{
    if (!purch_2fa_required()) {
        return true;
    }
    $st = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $st->execute([(int)($_SESSION['uid'] ?? 0)]);
    if ((int)$st->fetchColumn() === 1) {
        return true;
    }
    admin_render('erp_need_2fa', ['title' => 'Compras', 'scope' => 'Compras y proveedores', 'anchor' => 'compras']);
    return false;
}

/** ¿Puede este rol usar Compras? */
function purch_role_ok(string $role): bool
{
    return in_array($role, ['admin', 'compras'], true);
}

/** Despacha /admin/compras/<pedidos|albaranes|facturas>[/…]. */
function admin_purchases(string $seg, string $sub, string $sub2): void
{
    if (!purch_guard()) {
        return;
    }
    if ($seg === '' || !isset(DOCS_PAGE_KIND_PURCHASE[$seg])) {
        redirect('/admin/compras/facturas');
    }
    admin_docs_purchase(DOCS_PAGE_KIND_PURCHASE[$seg], $sub, $sub2);
}

/** admin_docs() ya hace su propio erp_guard(); para compras se comprueba antes purch_guard(), así que aquí se salta ese control. */
function admin_docs_purchase(string $kind, string $sub, string $sub2): void
{
    admin_docs($kind, $sub, $sub2);
}

/* ---------- Factura recibida: cierre, rectificativa y pagos ---------- */

/** Lo que falta para cerrar un borrador de factura recibida (vacío = se puede cerrar). */
function purch_close_problems(array $doc, array $lines): array
{
    $e = [];
    if ($doc['status'] !== 'borrador') {
        return ['Esta factura ya está cerrada.'];
    }
    if (!array_filter($lines, fn($l) => (int)$l['kind'] === 0)) {
        $e[] = 'No tiene ninguna línea con artículo o concepto.';
    }
    if (trim((string)$doc['cust_name']) === '') {
        $e[] = 'Falta el proveedor.';
    }
    if (trim((string)$doc['sup_ref']) === '') {
        $e[] = 'Falta el número de la factura del proveedor.';
    }
    if ($doc['doc_type'] === 'factura_c' && (int)$doc['total_cents'] < 0) {
        $e[] = 'El total no puede ser negativo (para un abono usa una rectificativa recibida).';
    }
    if ($doc['doc_type'] === 'rectificativa_c' && (int)$doc['rect_of'] <= 0) {
        $e[] = 'La rectificativa no tiene factura de origen.';
    }
    if (period_is_locked((int)$doc['doc_day'])) {
        $e[] = period_lock_message((int)$doc['doc_day']);
    }
    if ((int)$doc['doc_day'] > (int)date('Ymd')) {
        $e[] = 'La fecha de la factura no puede ser futura.';
    }
    $s = series_get((int)$doc['series_id']);
    if (!$s || $s['doc_type'] !== $doc['doc_type'] || (int)$s['is_active'] !== 1) {
        $e[] = 'La serie del registro no está activa o no es de este tipo (Configuración → Parámetros → Series).';
    }
    return array_merge($e, dues_issue_problems($doc));
}

/**
 * «Cerrar» una factura recibida: toma el número de REGISTRO de su serie dentro de una transacción (correlativo por año, sin huecos),
 * fija los vencimientos y pasa a estado cerrada (en la base, 'emitida'). Desde ahí no se modifica: se corrige con una rectificativa recibida.
 */
function purch_close(int $id, int $rev = -1): string
{
    $db = db();
    $db->beginTransaction();
    try {
        $doc = docs_get($id);
        if (!$doc || !in_array($doc['doc_type'], ['factura_c', 'rectificativa_c'], true)) {
            throw new DomainException('No es una factura recibida.');
        }
        if ($rev >= 0 && (int)$doc['rev'] !== $rev) {
            throw new DomainException(DOCS_CONFLICT_MSG);
        }
        $problems = purch_close_problems($doc, docs_lines($id));
        if ($problems) {
            throw new DomainException(implode(' ', $problems));
        }
        dues_finalize($doc);
        $s = series_get((int)$doc['series_id']);
        $year = intdiv((int)$doc['doc_day'], 10000);
        $num = series_next_number((int)$s['id'], $year);
        $st = $db->prepare('SELECT COUNT(*) FROM doc_headers WHERE doc_code = ?');
        $st->execute([$num['formatted']]);
        if ((int)$st->fetchColumn() > 0) { // el registro no admite saltos
            throw new DomainException('El número de registro ' . $num['formatted'] . ' ya existe (¿se restauró una copia antigua?). Revisa Configuración → Parámetros → Series antes de cerrar.');
        }
        $now = time();
        $user = (string)(current_user()['username'] ?? '');
        $up = $db->prepare("UPDATE doc_headers SET yr = ?, doc_no = ?, doc_code = ?, status = 'emitida', issued_at = ?, issued_by = ?, updated_at = ?, updated_by = ?, rev = rev + 1
            WHERE id = ? AND status = 'borrador'");
        $up->execute([(int)$s['with_year'] === 1 ? $year : 0, $num['number'], $num['formatted'], $now, $user, $now, $user, $id]);
        if ($up->rowCount() !== 1) {
            throw new DomainException('Esta factura ya se ha cerrado.');
        }
        foreach ($db->query("SELECT id FROM doc_headers WHERE doc_type = 'albaran_c' AND billed_by = " . $id)->fetchAll() as $a) {
            $db->prepare("UPDATE doc_headers SET status = 'facturado' WHERE id = ?")->execute([(int)$a['id']]);
            docs_event((int)$a['id'], 'facturado', 'Factura recibida ' . $num['formatted']);
            docs_refresh_orders(docs_source_orders((int)$a['id']));
        }
        if ($doc['doc_type'] === 'rectificativa_c') {
            docs_event((int)$doc['rect_of'], 'rectificada', 'Rectificativa recibida ' . $num['formatted']);
        }
        docs_event($id, 'cerrada', $num['formatted']);
        $db->commit();
        return $num['formatted'];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Rectificativa recibida (por diferencias): borrador con las líneas de la factura en negativo; el usuario deja solo lo que se corrige y escribe el número del proveedor. */
function purch_rectify(array $orig, string $text): int
{
    if ($orig['doc_type'] !== 'factura_c' || $orig['status'] !== 'emitida') {
        throw new DomainException('Solo se rectifican facturas recibidas cerradas.');
    }
    $text = erp_txt($text, 200);
    if ($text === '') {
        throw new DomainException('Escribe en pocas palabras qué se corrige.');
    }
    $today = (int)date('Ymd');
    if (period_is_locked($today)) {
        throw new DomainException(period_lock_message($today));
    }
    $s = series_default_for('rectificativa_c');
    if (!$s) {
        throw new DomainException('No hay ninguna serie activa de rectificativas recibidas (Configuración → Parámetros → Series).');
    }
    $doc = array_merge(docs_blank('rectificativa_c'), array_intersect_key($orig, array_flip([...DOCS_HEAD_COLS, 'cash_crit'])));
    $doc['sup_ref'] = '';
    $doc['series_id'] = (int)$s['id'];
    $doc['doc_day'] = $today;
    $doc['op_day'] = 0;
    $doc['rect_of'] = (int)$orig['id'];
    $doc['rect_mode'] = 'diferencias';
    $doc['rect_text'] = $text;
    $doc['ref_text'] = 'Rectifica ' . $orig['doc_code'] . ' (' . $orig['sup_ref'] . ')';
    $doc['notes'] = '';
    $lines = [];
    foreach (docs_lines((int)$orig['id']) as $l) {
        $nl = array_intersect_key($l, array_flip(DOCS_LINE_COLS));
        $nl['id'] = 0;
        $nl['src_line_id'] = 0;
        $nl['qty_u'] = -(int)$l['qty_u'];
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

/** Marca un vencimiento de una factura recibida cerrada como pagado (con su fecha) o de nuevo como pendiente. No modifica la factura. */
function purch_due_mark(array $doc, int $dueNo, bool $paid, string $day): void
{
    if (!in_array($doc['doc_type'], ['factura_c', 'rectificativa_c'], true) || $doc['status'] !== 'emitida') {
        throw new DomainException('Los pagos se marcan en las facturas recibidas cerradas.');
    }
    $d = $paid ? (erp_day_from_input($day) ?: (int)date('Ymd')) : 0;
    $up = db()->prepare('UPDATE doc_dues SET paid = ?, paid_day = ? WHERE doc_id = ? AND due_no = ?');
    $up->execute([$paid ? 1 : 0, $d, (int)$doc['id'], $dueNo]);
    if ($up->rowCount() === 0) {
        throw new DomainException('Ese vencimiento no existe.');
    }
    docs_event((int)$doc['id'], $paid ? 'pagado' : 'despagado', 'Vencimiento ' . $dueNo . ($paid ? ' pagado el ' . erp_day($d) : ' pendiente'));
}

/* ---------- Proveedores ---------- */

/** Siguiente código libre: prefijo configurable (Parámetros) + número con 5 cifras como mínimo (P00001). Separado del de clientes. */
function suppliers_next_code(): string
{
    $pre = strtoupper(docp('sup_prefix')) ?: 'P';
    $max = 0;
    $st = db()->prepare('SELECT sup_code FROM erp_suppliers WHERE sup_code LIKE ?');
    $st->execute([$pre . '%']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $n = substr((string)$c, strlen($pre));
        if (ctype_digit($n) && strlen($n) <= 15) {
            $max = max($max, (int)$n);
        }
    }
    return $pre . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

function suppliers_code_error(string $code, int $exceptId = 0): string
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code)) {
        return 'El código solo puede llevar letras, números, punto, guion y guion bajo (máximo 40).';
    }
    if (in_array(strtolower($code), ['nuevo', 'modificar'], true)) {
        return 'Ese código está reservado.';
    }
    $st = db()->prepare('SELECT COUNT(*) FROM erp_suppliers WHERE LOWER(sup_code) = LOWER(?) AND id <> ?');
    $st->execute([$code, $exceptId]);
    return (int)$st->fetchColumn() > 0 ? 'Ya hay un proveedor con el código «' . $code . '». El siguiente libre es ' . suppliers_next_code() . '.' : '';
}

/** Documentos de compra que tiene un proveedor (en cualquier estado). */
function suppliers_docs_count(string $code): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM doc_headers WHERE cust_code = ? AND doc_type IN ('pedido_c', 'albaran_c', 'factura_c', 'rectificativa_c')");
    $st->execute([$code]);
    return (int)$st->fetchColumn();
}

function suppliers_nif_dups(string $nif, int $exceptId): array
{
    $n = strtoupper(str_replace([' ', '.', '-'], '', $nif));
    if ($n === '') {
        return [];
    }
    $st = db()->prepare("SELECT sup_code FROM erp_suppliers WHERE id <> ? AND UPPER(REPLACE(REPLACE(REPLACE(nif, ' ', ''), '.', ''), '-', '')) = ? ORDER BY sup_code LIMIT 5");
    $st->execute([$exceptId, $n]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Pone al día los datos del proveedor en sus BORRADORES (los documentos cerrados o confirmados conservan lo que llevaban). */
function suppliers_refresh_drafts(array $s): void
{
    $snap = docs_customer_snapshot($s);
    db()->prepare("UPDATE doc_headers SET cust_name = ?, cust_nif = ?, cust_address = ?, cust_postcode = ?, cust_city = ?, cust_province = ?, cust_email = ?, rev = rev + 1
        WHERE cust_code = ? AND status = 'borrador' AND doc_type IN ('pedido_c', 'albaran_c', 'factura_c', 'rectificativa_c')")
        ->execute([mb_substr((string)$snap['cust_name'], 0, 255), mb_substr((string)$snap['cust_nif'], 0, 40), mb_substr((string)$snap['cust_address'], 0, 255),
            mb_substr((string)$snap['cust_postcode'], 0, 20), mb_substr((string)$snap['cust_city'], 0, 120), mb_substr((string)$snap['cust_province'], 0, 120),
            mb_substr((string)$snap['cust_email'], 0, 190), (string)$s['sup_code']]);
}

/** /admin/proveedores[/nuevo|/<código>]. */
function admin_suppliers(string $sub): void
{
    if (!purch_guard()) {
        return;
    }
    $db = db();
    $methods = array_values(array_filter(pay_methods_all(), fn($m) => (int)$m['is_active'] === 1));
    if ($sub === '') { // lista
        $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
        $ver = (string)($_GET['ver'] ?? '');
        $w = ' WHERE 1 = 1';
        $a = [];
        rep_cond_like($w, $a, ['sup_code', 'name', 'trade_name', 'nif', 'city'], $q);
        if ($ver === 'bloq') {
            $w .= ' AND (blocked = 1 OR ended_day > 0)';
        }
        $st = $db->prepare('SELECT COUNT(*) FROM erp_suppliers' . $w);
        $st->execute($a);
        $total = (int)$st->fetchColumn();
        $pages = max(1, (int)ceil($total / PURCH_PER_PAGE));
        $page = min($pages, max(1, (int)($_GET['pag'] ?? 1)));
        $st = $db->prepare('SELECT * FROM erp_suppliers' . $w . ' ORDER BY name, sup_code LIMIT ' . PURCH_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * PURCH_PER_PAGE) : ''));
        $st->execute($a);
        admin_render('suppliers', ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages, 'q' => $q, 'ver' => $ver,
            'all' => (int)$db->query('SELECT COUNT(*) FROM erp_suppliers')->fetchColumn(), 'title' => 'Proveedores', 'crumb' => 'Proveedores']);
        return;
    }
    if ($sub === 'nuevo') {
        $v = ['sup_code' => suppliers_next_code(), 'name' => '', 'trade_name' => '', 'nif' => '', 'contact' => '', 'phone' => '', 'phone2' => '', 'email' => '', 'address' => '', 'postcode' => '', 'city' => '',
            'province' => '', 'country' => '', 'tariff' => 0, 'pay_code' => docp('pay_method'), 'pay_days' => '', 'disc_bp' => 0, 'disc_txt' => '', 'blocked' => 0, 'ended_day' => 0, 'def_series' => 0, 'doc_notes' => '',
            'rev' => 0, 'created_day' => 0, 'updated_at' => 0, 'updated_by' => '', 'id' => 0];
        $errors = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            $v = erp_customer_read_post($v, $_POST, [], $methods, false, $errors);
            $v['sup_code'] = mb_substr(trim((string)($_POST['sup_code'] ?? '')), 0, 40);
            if (($err = suppliers_code_error($v['sup_code'])) !== '') {
                $errors[] = $err;
            }
            if (!$errors) {
                $cols = ['sup_code', ...array_keys(PURCH_SUP_FIELDS), 'pay_code', 'pay_days', 'disc_bp', 'blocked', 'ended_day', 'def_series', 'doc_notes', 'created_day', 'updated_by', 'updated_at'];
                $v['created_day'] = (int)date('Ymd');
                $v['updated_by'] = mb_substr((string)(current_user()['username'] ?? ''), 0, 100);
                $v['updated_at'] = time();
                try {
                    $db->prepare('INSERT INTO erp_suppliers (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_map(fn($k) => $v[$k], $cols));
                    $w = erp_nif_warning((string)$v['nif']);
                    $d = suppliers_nif_dups((string)$v['nif'], 0);
                    flash('Proveedor ' . $v['sup_code'] . ' creado.' . ($w !== '' ? ' Aviso: ' . $w : '') . ($d ? ' Aviso: ese NIF ya lo tiene ' . implode(', ', $d) . '.' : ''));
                    redirect('/admin/proveedores/' . rawurlencode((string)$v['sup_code']));
                } catch (PDOException $e) {
                    error_log('cms: alta de proveedor: ' . get_class($e));
                    $errors[] = 'No se pudo guardar (¿otro usuario ha creado a la vez un proveedor con ese código?). Inténtalo de nuevo.';
                }
            }
        }
        admin_render('supplier_card', supplier_card_vars($v, $v, true, true, $errors, $methods) + ['title' => 'Nuevo proveedor', 'crumb' => 'Nuevo proveedor']);
        return;
    }
    $code = rawurldecode($sub);
    $st = $db->prepare('SELECT * FROM erp_suppliers WHERE sup_code = ?');
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c) {
        not_found();
        return;
    }
    $self = '/admin/proveedores/' . rawurlencode($code);
    $edit = isset($_GET['editar']);
    $errors = [];
    $f = $c;
    $f['disc_txt'] = (int)$c['disc_bp'] > 0 ? rtrim(rtrim(number_format($c['disc_bp'] / 100, 2, ',', ''), '0'), ',') : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? '');
        $nDocs = suppliers_docs_count($code);
        if ($act === 'block' || $act === 'unblock') {
            $db->prepare('UPDATE erp_suppliers SET blocked = ?, rev = rev + 1, updated_by = ?, updated_at = ? WHERE id = ?')
                ->execute([$act === 'block' ? 1 : 0, mb_substr((string)(current_user()['username'] ?? ''), 0, 100), time(), (int)$c['id']]);
            flash($act === 'block' ? 'Proveedor bloqueado: no se puede elegir en documentos nuevos.' : 'Proveedor desbloqueado.');
            redirect($self);
        }
        if ($act === 'delete') {
            if ($nDocs > 0) {
                flash('Este proveedor tiene documentos: no se puede borrar (bloquéalo si ya no lo usas).', 'err');
            } elseif (empty($_POST['confirm'])) {
                flash('Marca la casilla para confirmar el borrado.', 'err');
            } else {
                $db->beginTransaction();
                $db->prepare('DELETE FROM erp_notes WHERE cust_code = ?')->execute(['prov:' . $code]);
                $db->prepare('DELETE FROM erp_suppliers WHERE id = ?')->execute([(int)$c['id']]);
                $db->commit();
                flash('Proveedor ' . $code . ' borrado.');
                redirect('/admin/proveedores');
            }
            redirect($self);
        }
        if ($act === 'note_add') {
            $note = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['note'] ?? ''))), 0, ERP_NOTE_MAX);
            if ($note === '') {
                flash('Escribe la nota.', 'err');
            } else {
                $db->prepare('INSERT INTO erp_notes (cust_code, note, user_id, username, created_at) VALUES (?,?,?,?,?)')
                    ->execute(['prov:' . $code, $note, (int)$_SESSION['uid'], mb_substr((string)(current_user()['username'] ?? ''), 0, 100), time()]);
                flash('Nota guardada.');
            }
            redirect($self . '#notas');
        }
        if ($act === 'note_del') {
            $st = $db->prepare('SELECT id, user_id FROM erp_notes WHERE id = ? AND cust_code = ?');
            $st->execute([(int)($_POST['id'] ?? 0), 'prov:' . $code]);
            $n = $st->fetch();
            if ($n && (is_admin() || (int)$n['user_id'] === (int)$_SESSION['uid'])) {
                $db->prepare('DELETE FROM erp_notes WHERE id = ?')->execute([(int)$n['id']]);
                flash('Nota borrada.');
            } else {
                flash('No puedes borrar esa nota: solo su autor o un administrador.', 'err');
            }
            redirect($self . '#notas');
        }
        if ($act === 'save') {
            $edit = true;
            $f = erp_customer_read_post($f, $_POST, [], $methods, false, $errors);
            $newCode = mb_substr(trim((string)($_POST['sup_code'] ?? $code)), 0, 40);
            if ($newCode !== $code) {
                if ($nDocs > 0) {
                    $errors[] = 'El código no se puede cambiar: el proveedor ya tiene documentos.';
                    $newCode = $code;
                } elseif (($err = suppliers_code_error($newCode, (int)$c['id'])) !== '') {
                    $errors[] = $err;
                }
            }
            if (!$errors) {
                $cols = [...array_keys(PURCH_SUP_FIELDS), 'pay_code', 'pay_days', 'disc_bp', 'blocked', 'ended_day', 'def_series', 'doc_notes'];
                $db->beginTransaction();
                $up = $db->prepare('UPDATE erp_suppliers SET ' . implode(' = ?, ', $cols) . ' = ?, sup_code = ?, rev = rev + 1, updated_by = ?, updated_at = ? WHERE id = ? AND rev = ?');
                $up->execute([...array_map(fn($k) => $f[$k], $cols), $newCode, mb_substr((string)(current_user()['username'] ?? ''), 0, 100), time(), (int)$c['id'], (int)($_POST['stamp'] ?? -1)]);
                if ($up->rowCount() === 0) {
                    $db->rollBack();
                    $errors[] = 'Otro usuario ha modificado este proveedor mientras lo editabas. Recarga la página para ver sus cambios (no se ha guardado nada tuyo).';
                } else {
                    if ($newCode !== $code) {
                        $db->prepare('UPDATE erp_notes SET cust_code = ? WHERE cust_code = ?')->execute(['prov:' . $newCode, 'prov:' . $code]);
                    }
                    suppliers_refresh_drafts(array_merge($f, ['sup_code' => $newCode]));
                    $db->commit();
                    $w = erp_nif_warning((string)$f['nif']);
                    flash('Proveedor guardado.' . ($w !== '' ? ' Aviso: ' . $w : ''));
                    redirect('/admin/proveedores/' . rawurlencode($newCode));
                }
            }
        }
    }
    admin_render('supplier_card', supplier_card_vars($c, $f, $edit, false, $errors, $methods) + ['title' => 'Proveedor ' . $code, 'crumb' => $code]);
}

/** Variables de la ficha de proveedor (consulta, edición y alta). */
function supplier_card_vars(array $c, array $f, bool $edit, bool $isNew, array $errors, array $methods): array
{
    $db = db();
    $code = (string)($c['sup_code'] ?? '');
    $quick = ['year' => (int)date('Y'), 'bought' => 0, 'pending' => 0, 'overdue' => 0, 'last' => null, 'count' => 0];
    $docs = [];
    $notes = [];
    $nb = ['prev' => null, 'next' => null];
    $docType = '';
    if (!$isNew) {
        $y = (int)date('Y') * 10000;
        $st = $db->prepare("SELECT COALESCE(SUM(total_cents), 0) FROM doc_headers WHERE cust_code = ? AND doc_type IN ('factura_c', 'rectificativa_c') AND status = 'emitida' AND doc_day BETWEEN ? AND ?");
        $st->execute([$code, $y, $y + 9999]);
        $quick['bought'] = (int)$st->fetchColumn();
        $st = $db->prepare("SELECT COALESCE(SUM(u.amount_cents), 0) AS t, COALESCE(SUM(CASE WHEN u.due_day < ? THEN u.amount_cents ELSE 0 END), 0) AS o
            FROM doc_dues u JOIN doc_headers h ON h.id = u.doc_id WHERE h.cust_code = ? AND h.doc_type IN ('factura_c', 'rectificativa_c') AND h.status = 'emitida' AND u.paid = 0");
        $st->execute([(int)date('Ymd'), $code]);
        $r = $st->fetch();
        $quick['pending'] = (int)$r['t'];
        $quick['overdue'] = (int)$r['o'];
        $types = "('pedido_c', 'albaran_c', 'factura_c', 'rectificativa_c')";
        $docType = (string)($_GET['tipo'] ?? '');
        $w = " WHERE cust_code = ? AND doc_type IN $types";
        $a = [$code];
        if (isset(DOCS_KINDS_PURCHASE[$docType . '_c'])) {
            $w .= ' AND doc_type IN (?, ?)';
            array_push($a, $docType . '_c', $docType === 'factura' ? 'rectificativa_c' : $docType . '_c');
        } else {
            $docType = '';
        }
        $st = $db->prepare('SELECT id, doc_type, doc_code, doc_day, status, total_cents, sup_ref FROM doc_headers' . $w . ' ORDER BY doc_day DESC, id DESC LIMIT 100');
        $st->execute($a);
        foreach ($st->fetchAll() as $d) {
            $k = DOCS_KINDS_PURCHASE[$d['doc_type']];
            $docs[] = ['day' => (int)$d['doc_day'], 'label' => $k['label'], 'code' => $d['status'] === 'borrador' ? '(borrador)' : (string)$d['doc_code'], 'sup_ref' => (string)$d['sup_ref'],
                'url' => $k['path'] . '/' . (int)$d['id'], 'total' => (int)$d['total_cents'], 'status' => DOCS_STATUS[$d['doc_type']][$d['status']] ?? (string)$d['status']];
        }
        $quick['last'] = $docs[0] ?? null;
        $quick['count'] = suppliers_docs_count($code);
        $st = $db->prepare('SELECT * FROM erp_notes WHERE cust_code = ? ORDER BY created_at DESC, id DESC');
        $st->execute(['prov:' . $code]);
        $notes = $st->fetchAll();
        foreach (['prev' => ['<', 'DESC'], 'next' => ['>', 'ASC']] as $k => [$op, $dir]) {
            $st = $db->prepare("SELECT sup_code, name FROM erp_suppliers WHERE name $op ? ORDER BY name $dir LIMIT 1");
            $st->execute([(string)$c['name']]);
            $nb[$k] = $st->fetch() ?: null;
        }
    }
    return ['c' => $c, 'f' => $f, 'edit' => $edit, 'isNew' => $isNew, 'errors' => $errors, 'methods' => $methods, 'quick' => $quick, 'docs' => $docs, 'docType' => $docType, 'notes' => $notes, 'nb' => $nb,
        'nDocs' => $isNew ? 0 : $quick['count'], 'series' => array_filter(series_all(), fn($s) => (int)$s['is_active'] === 1 && docs_is_purchase((string)$s['doc_type'])),
        'self' => $isNew ? '/admin/proveedores/nuevo' : '/admin/proveedores/' . rawurlencode($code),
        'nifWarn' => erp_nif_warning((string)($f['nif'] ?? '')), 'nifDups' => $isNew ? [] : suppliers_nif_dups((string)$c['nif'], (int)$c['id'])];
}
