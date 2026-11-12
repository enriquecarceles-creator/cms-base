<?php
declare(strict_types=1);

/*
 * Formulario de factura con aspecto de ERP (/admin/ventas/<número>): barra de herramientas, etiquetas de estado, cabecera en
 * recuadros, rejilla de líneas y pie con pestañas (desglose de IVA, notas internas e historial). El visor impreso sigue en
 * /admin/ventas/<número>/ver (app/documents.php) y «Modificar» (/modificar) solo toca los datos PROPIOS del CMS: nunca lo
 * que viene de NCS. Si algún día el CMS emite facturas propias (origin = 'cms'), esas sí serán editables.
 */

const ERP_FORM_TABS = ['iva' => 'Desglose de IVA', 'notas' => 'Notas internas', 'historial' => 'Historial'];
const ERP_INVOICE_NOTE_MAX = 2000;
const ERP_EVENT_LABELS = [
    'cobro' => 'Cobro', 'cobro_quitado' => 'Cobro quitado', 'nota' => 'Nota', 'nota_borrada' => 'Nota borrada', 'etiquetas' => 'Etiquetas',
    'correo_envio' => 'Correo de envío', 'envio' => 'Email enviado', 'envio_error' => 'Error al enviar', 'enlace_abierto' => 'Enlace abierto',
    'enlace_revocado' => 'Enlace revocado',
];
const ERP_NCS_LOCK_MSG = 'Factura de NCS: para cambiarla, haz una rectificativa en NCS.';

const ERP_CMS_LOCK_MSG = 'Factura emitida en el CMS: no se puede modificar. Para corregirla, haz una rectificativa desde su documento.';

/**
 * ¿Se pueden editar los datos fiscales desde Ventas? Nunca: las de NCS se corrigen en NCS y las emitidas en el CMS son inalterables
 * (se corrigen con rectificativa; los borradores se editan en /admin/facturas). Aquí solo se tocan los datos propios (cobro…).
 */
function erp_invoice_editable(array $inv): bool
{
    return false;
}

/** Número de factura → trozo de dirección: la «/» de «F00001/26» se escribe «~» (una barra, aunque vaya codificada, rompe la dirección). */
function erp_inv_seg(string $num): string
{
    return rawurlencode(str_replace('/', '~', $num));
}

/** Trozo de dirección → número de factura (deshace erp_inv_seg; un número que de verdad lleve «~» se busca tal cual primero). */
function erp_inv_from_seg(string $seg): string
{
    if (!str_contains($seg, '~')) {
        return $seg;
    }
    $st = db()->prepare('SELECT COUNT(*) FROM erp_invoices WHERE inv_number = ?');
    $st->execute([$seg]);
    return (int)$st->fetchColumn() > 0 ? $seg : str_replace('~', '/', $seg);
}

/**
 * Cliente de una factura para enseñarla: el de la ficha (erp_customers) o, si la factura es del CMS, los datos guardados en el
 * propio documento al emitirla (puede ser un cliente sin ficha, y una factura no cambia aunque cambie la ficha).
 */
function erp_invoice_customer(array $inv): ?array
{
    if ((string)($inv['origin'] ?? '') === 'cms') {
        $doc = erp_invoice_cms_doc($inv);
        if ($doc && trim((string)$doc['cust_name']) !== '') {
            return ['cust_code' => (string)$doc['cust_code'], 'name' => (string)$doc['cust_name'], 'trade_name' => '', 'nif' => (string)$doc['cust_nif'],
                'address' => (string)$doc['cust_address'], 'postcode' => (string)$doc['cust_postcode'], 'city' => (string)$doc['cust_city'],
                'province' => (string)$doc['cust_province'], 'email' => (string)$doc['cust_email'], 'country' => ''];
        }
        return null;
    }
    $st = db()->prepare('SELECT * FROM erp_customers WHERE cust_code = ?');
    $st->execute([$inv['cust_code']]);
    return $st->fetch() ?: null;
}

/** Documento del CMS de una factura emitida en el CMS (o null). */
function erp_invoice_cms_doc(array $inv): ?array
{
    if ((string)($inv['origin'] ?? '') !== 'cms') {
        return null;
    }
    $st = db()->prepare("SELECT * FROM doc_headers WHERE doc_code = ? AND status = 'emitida'");
    $st->execute([(string)$inv['inv_number']]);
    return $st->fetch() ?: null;
}

/** Anota un hecho en el historial de la factura (con el usuario de la sesión; 'enlace' si no hay sesión). */
function erp_event(int $invoiceId, string $kind, string $detail = '', ?string $user = null, int $uid = 0): void
{
    if ($user === null) {
        $uid = (int)($_SESSION['uid'] ?? 0);
        $user = $uid > 0 ? (string)(current_user()['username'] ?? '') : '';
    }
    db()->prepare('INSERT INTO erp_invoice_events (invoice_id, kind, detail, user_id, username, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$invoiceId, mb_substr($kind, 0, 40), mb_substr($detail, 0, 250), $uid, mb_substr($user, 0, 100), time()]);
}

/** Etiquetas: «a, b, c» → lista limpia (máx. 10 de 30 caracteres, sin repetir). */
function erp_tags_list(string $s): array
{
    $out = [];
    foreach (explode(',', $s) as $t) {
        $t = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F<>"]+/u', ' ', strip_tags($t)) ?? ''), 0, 30);
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $out), true)) {
            $out[] = $t;
        }
    }
    return array_slice($out, 0, 10);
}

function erp_invoice_state(int $invoiceId): array
{
    $st = db()->prepare('SELECT * FROM erp_invoice_state WHERE invoice_id = ?');
    $st->execute([$invoiceId]);
    return $st->fetch() ?: ['id' => 0, 'invoice_id' => $invoiceId, 'paid' => 0, 'paid_day' => 0, 'tags' => '', 'email_to' => '', 'updated_at' => 0, 'updated_by' => ''];
}

/** aaaa-mm-dd (campo de fecha) → aaaammdd, o 0 si no es una fecha válida. */
function erp_day_from_input(string $s): int
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? (int)($m[1] . $m[2] . $m[3]) : 0;
}

function erp_day_to_input(int $d): string
{
    return $d > 0 ? sprintf('%04d-%02d-%02d', intdiv($d, 10000), intdiv($d, 100) % 100, $d % 100) : '';
}

/**
 * Precarga UNA VEZ el cobro de las facturas cuyo ENTREGADO en NCS cubre el total (probablemente tickets de caja): cobradas en la
 * fecha de la venta, con su anotación en el historial. No toca las que ya tienen estado. Devuelve cuántas.
 */
function erp_preload_paid(): int
{
    $db = db();
    $where = 'delivered_cents >= total_cents AND total_cents > 0 AND annulled = 0 AND id NOT IN (SELECT invoice_id FROM erp_invoice_state)';
    $uid = (int)($_SESSION['uid'] ?? 0);
    $user = $uid > 0 ? (string)(current_user()['username'] ?? '') : '';
    $now = time();
    $db->beginTransaction();
    try {
        $n = (int)$db->query('SELECT COUNT(*) FROM erp_invoices WHERE ' . $where)->fetchColumn();
        $db->prepare("INSERT INTO erp_invoice_events (invoice_id, kind, detail, user_id, username, created_at)
            SELECT id, 'cobro', 'Precarga: lo entregado en caja (ENTREGADO de NCS) cubre el total', ?, ?, ? FROM erp_invoices WHERE " . $where)->execute([$uid, $user, $now]);
        $db->prepare("INSERT INTO erp_invoice_state (invoice_id, paid, paid_day, tags, email_to, updated_at, updated_by)
            SELECT id, 1, inv_day, '', '', ?, 'precarga' FROM erp_invoices WHERE " . $where)->execute([$now]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    save_setting('erp_paid_preload_done', (string)$now);
    return $n;
}

function erp_preload_candidates(): int
{
    return (int)db()->query('SELECT COUNT(*) FROM erp_invoices WHERE delivered_cents >= total_cents AND total_cents > 0 AND annulled = 0 AND id NOT IN (SELECT invoice_id FROM erp_invoice_state)')->fetchColumn();
}

/** Despacha /admin/ventas/<número>[/<ver|modificar|enviar>]. */
function admin_invoice(string $num, string $sub = ''): void
{
    if (!erp_guard()) {
        return;
    }
    $num = erp_inv_from_seg($num); // «F00001~26» → «F00001/26»
    if ($sub === 'ver') {
        admin_invoice_view($num);
        return;
    }
    $db = db();
    $st = $db->prepare('SELECT * FROM erp_invoices WHERE inv_number = ?');
    $st->execute([$num]);
    $inv = $st->fetch();
    if (!$inv) {
        not_found();
        return;
    }
    $id = (int)$inv['id'];
    $self = '/admin/ventas/' . erp_inv_seg($num);

    $sendForm = null;
    $sendErrors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'link_revoke') {
            erp_invoice_link_revoke_post($inv, $self);
        }
        if ($act === 'send' && $sub === 'enviar') {
            [$sendForm, $sendErrors] = erp_invoice_send_post($inv, $self); // si va bien, redirige; si no, se vuelve a enseñar la pantalla
        }
        if ($act === 'note_add') {
            $note = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['note'] ?? ''))), 0, ERP_INVOICE_NOTE_MAX);
            if ($note === '') {
                flash('Escribe la nota.', 'err');
            } else {
                $uid = (int)$_SESSION['uid'];
                $db->prepare('INSERT INTO erp_invoice_notes (invoice_id, note, user_id, username, created_at) VALUES (?,?,?,?,?)')
                    ->execute([$id, $note, $uid, mb_substr((string)current_user()['username'], 0, 100), time()]);
                erp_event($id, 'nota', mb_substr($note, 0, 80));
                flash('Nota guardada.');
            }
            redirect($self . '?pestana=notas');
        }
        if ($act === 'note_del') {
            $st = $db->prepare('SELECT id, user_id FROM erp_invoice_notes WHERE id = ? AND invoice_id = ?');
            $st->execute([(int)($_POST['id'] ?? 0), $id]);
            $n = $st->fetch();
            if ($n && (is_admin() || (int)$n['user_id'] === (int)$_SESSION['uid'])) {
                $db->prepare('DELETE FROM erp_invoice_notes WHERE id = ?')->execute([(int)$n['id']]);
                erp_event($id, 'nota_borrada');
                flash('Nota borrada.');
            } else {
                flash('No puedes borrar esa nota: solo su autor o un administrador.', 'err');
            }
            redirect($self . '?pestana=notas');
        }
        if ($act === 'save' && $sub === 'modificar') {
            $old = erp_invoice_state($id);
            $paid = !empty($_POST['paid']) ? 1 : 0;
            $day = $paid ? (erp_day_from_input((string)($_POST['paid_day'] ?? '')) ?: (int)date('Ymd')) : 0;
            $tags = implode(', ', erp_tags_list((string)($_POST['tags'] ?? '')));
            $mail = trim((string)($_POST['email_to'] ?? ''));
            if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                flash('El correo de envío no es válido.', 'err');
                redirect($self . '/modificar');
            }
            $by = mb_substr((string)current_user()['username'], 0, 100);
            if ((int)$old['id'] === 0) {
                $db->prepare('INSERT INTO erp_invoice_state (invoice_id, paid, paid_day, tags, email_to, updated_at, updated_by) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$id, $paid, $day, $tags, mb_substr($mail, 0, 250), time(), $by]);
            } else {
                $db->prepare('UPDATE erp_invoice_state SET paid = ?, paid_day = ?, tags = ?, email_to = ?, updated_at = ?, updated_by = ? WHERE invoice_id = ?')
                    ->execute([$paid, $day, $tags, mb_substr($mail, 0, 250), time(), $by, $id]);
            }
            if ($paid !== (int)$old['paid'] || ($paid && $day !== (int)$old['paid_day'])) {
                $paid ? erp_event($id, 'cobro', 'Cobrada el ' . erp_day($day)) : erp_event($id, 'cobro_quitado');
            }
            if ($tags !== (string)$old['tags']) {
                erp_event($id, 'etiquetas', $tags !== '' ? $tags : '(sin etiquetas)');
            }
            if ($mail !== (string)$old['email_to']) {
                erp_event($id, 'correo_envio', $mail !== '' ? $mail : '(el del cliente)');
            }
            flash('Cambios guardados.');
            redirect($self);
        }
        if ($sendForm === null) {
            redirect($self);
        }
    }

    $cust = erp_invoice_customer($inv);
    $st = $db->prepare('SELECT * FROM erp_invoice_lines WHERE invoice_id = ? ORDER BY line_no, id');
    $st->execute([$id]);
    $lines = $st->fetchAll();
    $vat = erp_invoice_vat($inv);
    $withBase = array_values(array_filter($vat, fn($r) => (int)$r['base'] !== 0));
    $state = erp_invoice_state($id);
    $st = $db->prepare("SELECT COUNT(*) AS n, COALESCE(MAX(sent_at), 0) AS last FROM erp_invoice_sends WHERE invoice_id = ? AND status = 'ok'");
    $st->execute([$id]);
    $sent = $st->fetch();
    $v = [
        'inv' => $inv, 'lines' => $lines, 'cust' => $cust, 'isRetail' => (string)$inv['origin'] === 'cms' ? $cust === null : erp_retail_code() === (string)$inv['cust_code'], 'docx' => erp_invoice_cms_doc($inv), 'vat' => $vat,
        'lineRate' => count($withBase) === 1 && $withBase[0]['pct'] !== null ? (int)$withBase[0]['pct'] : null,
        'serie' => erp_serie((string)$inv['inv_number']), 'payLabel' => erp_pay_label((string)$inv['pay_code']),
        'map' => erp_family_map(), 'nb' => erp_invoice_neighbours($inv), 'state' => $state, 'sent' => $sent, 'self' => $self,
        'editable' => erp_invoice_editable($inv), 'crumb' => 'Factura ' . $inv['inv_number'], 'title' => 'Factura',
    ];
    if ($sub === 'modificar') {
        admin_render('invoice_edit', $v);
        return;
    }
    if ($sub === 'enviar') {
        erp_invoice_send_screen($v, $sendForm, $sendErrors);
        return;
    }
    if ($sub !== '') {
        not_found();
        return;
    }
    $tab = (string)($_GET['pestana'] ?? 'iva');
    $tab = isset(ERP_FORM_TABS[$tab]) ? $tab : 'iva';
    $v['tab'] = $tab;
    $st = $db->prepare('SELECT COUNT(*) FROM erp_invoice_notes WHERE invoice_id = ?');
    $st->execute([$id]);
    $v['nNotes'] = (int)$st->fetchColumn();
    if ($tab === 'notas') {
        $st = $db->prepare('SELECT id, note, user_id, username, created_at FROM erp_invoice_notes WHERE invoice_id = ? ORDER BY created_at DESC, id DESC LIMIT 200');
        $st->execute([$id]);
        $v['notes'] = $st->fetchAll();
    } elseif ($tab === 'historial') {
        $st = $db->prepare('SELECT kind, detail, username, created_at FROM erp_invoice_events WHERE invoice_id = ? ORDER BY created_at DESC, id DESC LIMIT 200');
        $st->execute([$id]);
        $v['events'] = $st->fetchAll();
        $v['links'] = erp_links_for($id);
    }
    admin_render('invoice_form', $v);
}
