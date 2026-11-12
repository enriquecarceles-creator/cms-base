<?php
declare(strict_types=1);

/*
 * Acciones del motor de documentos para pedidos y albaranes (entrega 1 de Documentos): duplicar, anterior/siguiente y enviar por email.
 * El envío NO lleva adjunto ni enlace: la hoja va dentro del correo (HTML con estilos en línea, máx. DOCS_EMAIL_MAX_LINES líneas).
 * Las facturas se envían desde Ventas (app/erp_invoice_send.php), con enlace seguro.
 */

const DOCS_EMAIL_MAX_LINES = 60;
const DOCS_SEND_GAP_SECS = 60;

/** Anterior y siguiente del mismo tipo (las tres clases de factura cuentan como un tipo): [id|null, id|null] por orden de creación. */
function docs_neighbors(array $doc): array
{
    $db = db();
    $types = docs_is_invoice((string)$doc['doc_type']) ? (docs_is_purchase((string)$doc['doc_type']) ? ['factura_c', 'rectificativa_c'] : DOC_INVOICE_TYPES) : [(string)$doc['doc_type']];
    $in = implode(',', array_fill(0, count($types), '?'));
    $out = [];
    foreach ([['<', 'DESC'], ['>', 'ASC']] as [$op, $dir]) {
        $st = $db->prepare("SELECT id FROM doc_headers WHERE doc_type IN ($in) AND id $op ? ORDER BY id $dir LIMIT 1");
        $st->execute([...$types, (int)$doc['id']]);
        $v = $st->fetchColumn();
        $out[] = $v === false ? null : (int)$v;
    }
    return $out;
}

/**
 * Duplicar un pedido o albarán: documento nuevo del mismo tipo con la serie por defecto, fecha de hoy y las mismas líneas
 * (sin vínculos con pedidos ni facturas). Devuelve el id o lanza DomainException.
 */
function docs_duplicate(array $src): int
{
    $type = (string)$src['doc_type'];
    if (!in_array($type, ['pedido', 'albaran', 'pedido_c', 'albaran_c'], true)) {
        throw new DomainException('Solo se duplican pedidos y albaranes.');
    }
    $today = (int)date('Ymd');
    if (period_is_locked($today)) {
        throw new DomainException(period_lock_message($today));
    }
    $s = series_default_for($type);
    if (!$s) {
        throw new DomainException('No hay ninguna serie activa de ' . mb_strtolower(docs_kind($type)['plural']) . ' (Configuración → Series).');
    }
    $lines = [];
    foreach (docs_lines((int)$src['id']) as $l) {
        $nl = array_intersect_key($l, array_flip(DOCS_LINE_COLS));
        $nl['id'] = 0;
        $nl['src_line_id'] = 0;
        $lines[] = $nl;
    }
    $doc = array_merge(docs_blank($type), array_intersect_key($src, array_flip(DOCS_HEAD_COLS)));
    $doc['series_id'] = (int)$s['id'];
    $doc['doc_day'] = $today;
    $doc['origin_id'] = 0;
    $doc['op_day'] = 0;
    $doc['vat_note'] = '';
    $doc['rect_text'] = '';
    $doc['sup_ref'] = '';
    return docs_save($doc, $lines, -1, 'desde', 'Copia de ' . $src['doc_code']);
}

/* ---------- Enviar por email ---------- */

/** Correo por defecto del destinatario: el copiado en el documento o, si no, el de la ficha del cliente (nunca el de contado). */
function docs_send_default_to(array $doc): string
{
    $to = trim((string)$doc['cust_email']);
    if ($to === '' && $doc['cust_code'] !== '' && $doc['cust_code'] !== erp_retail_code()) {
        $c = docs_customer((string)$doc['cust_code']);
        $to = trim((string)($c['email'] ?? ''));
    }
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
}

function docs_send_vars(array $doc): array
{
    $vat = json_decode((string)$doc['vat_json'], true);
    return ['doc' => $doc, 'lines' => docs_lines((int)$doc['id']), 'vat' => is_array($vat) ? $vat : [], 'k' => DOCS_KINDS[$doc['doc_type']], 'd' => doc_settings(),
        'co' => doc_company(), 'payLabel' => erp_pay_label((string)$doc['pay_code']), 'maxLines' => DOCS_EMAIL_MAX_LINES,
        'dues' => dues_applies($doc) ? docs_dues((int)$doc['id']) : []];
}

function docs_email_html(array $doc, string $message): string
{
    $vars = docs_send_vars($doc);
    $vars['message'] = $message;
    $vars['title'] = DOCS_KINDS[$doc['doc_type']]['label'] . ' ' . $doc['doc_code'];
    ob_start();
    render('documents/doc_email', $vars, null);
    return (string)ob_get_clean();
}

function docs_email_text(array $doc, string $message): string
{
    $out = trim($message) . "\n\n--- " . DOCS_KINDS[$doc['doc_type']]['print'] . ' ' . $doc['doc_code'] . " ---\nFecha: " . erp_day((int)$doc['doc_day']) . "\n";
    foreach (array_slice(docs_lines((int)$doc['id']), 0, DOCS_EMAIL_MAX_LINES) as $l) {
        $out .= (int)$l['kind'] === 1 ? (string)$l['title'] . ((string)$l['detail'] !== '' ? "\n" . $l['detail'] : '') . "\n"
            : '- ' . trim($l['ref'] . ' ' . $l['title'] . ' ' . $l['size_txt'] . ' ' . $l['color_txt']) . ' × ' . docs_qty_txt((int)$l['qty_u']) . "\n";
    }
    $out .= 'Total: ' . erp_money((int)$doc['total_cents']) . "\n";
    $dues = dues_applies($doc) ? docs_dues((int)$doc['id']) : [];
    if ($dues) {
        $out .= "\nVencimientos:\n";
        foreach ($dues as $du) {
            $out .= '  ' . erp_day((int)$du['day']) . ': ' . erp_money((int)$du['cents']) . "\n";
        }
    }
    return $out;
}

/** Lo primero que se propone en el formulario de envío. */
function docs_send_defaults(array $doc): array
{
    $k = DOCS_KINDS[$doc['doc_type']];
    $co = site_name();
    return ['to' => docs_send_default_to($doc), 'subject' => $k['label'] . ' ' . $doc['doc_code'] . ' de ' . $co, 'copy' => false, 'confirm_dup' => false,
        'message' => "Hola,\n\nTe enviamos el " . mb_strtolower($k['label']) . ' ' . $doc['doc_code'] . " con fecha " . erp_day((int)$doc['doc_day']) . ".\n\nUn saludo,\n" . $co];
}

/** /admin/pedidos/<id>/enviar y /admin/albaranes/<id>/enviar. El acceso (erp_guard) ya está comprobado. */
function docs_send(array $doc): void
{
    $k = DOCS_KINDS[$doc['doc_type']];
    $self = $k['path'] . '/' . (int)$doc['id'];
    if (!in_array($doc['doc_type'], ['pedido', 'albaran'], true)) {
        not_found();
        return;
    }
    if ($doc['status'] === 'borrador') {
        flash('Confirma antes el documento: un borrador no tiene número y no se envía.', 'err');
        redirect($self);
    }
    if ($doc['status'] === 'anulado') {
        flash('Este documento está anulado: no se envía.', 'err');
        redirect($self);
    }
    $f = docs_send_defaults($doc);
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $f = ['to' => mail_clean((string)($_POST['to'] ?? '')), 'subject' => mb_substr(mail_clean((string)($_POST['subject'] ?? '')), 0, 150),
            'message' => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? ''))), 0, 2000),
            'copy' => !empty($_POST['copy']), 'confirm_dup' => !empty($_POST['confirm_dup'])];
        if (!filter_var($f['to'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Escribe un correo válido para el destinatario.';
        }
        if ($f['subject'] === '') {
            $errors[] = 'Escribe el asunto.';
        }
        if ($f['message'] === '') {
            $errors[] = 'Escribe el mensaje.';
        }
        $st = db()->prepare("SELECT MAX(created_at) FROM doc_events WHERE doc_id = ? AND kind = 'enviado'");
        $st->execute([(int)$doc['id']]);
        if ((int)$st->fetchColumn() > time() - DOCS_SEND_GAP_SECS && !$f['confirm_dup']) {
            $errors[] = 'Este documento se acaba de enviar. Si quieres enviarlo otra vez, marca la casilla de confirmación.';
        }
        if (!$errors) {
            $bcc = '';
            if ($f['copy']) {
                $me = db()->prepare('SELECT email FROM users WHERE id = ?');
                $me->execute([(int)$_SESSION['uid']]);
                $mine = trim((string)$me->fetchColumn());
                $bcc = filter_var($mine, FILTER_VALIDATE_EMAIL) ? $mine : '';
            }
            $r = mail_send(['to' => $f['to'], 'subject' => $f['subject'], 'text' => docs_email_text($doc, $f['message']),
                'html' => docs_email_html($doc, $f['message']), 'bcc' => $bcc]);
            if ($r['ok']) {
                docs_event((int)$doc['id'], 'enviado', 'A ' . $f['to']);
                flash($k['label'] . ' enviado a ' . $f['to'] . '.');
                redirect($self);
            }
            docs_event((int)$doc['id'], 'envio_error', 'A ' . $f['to'] . ': ' . $r['error']);
            $errors[] = 'No se pudo enviar el correo. ' . $r['error'];
        }
    }
    $st = db()->prepare("SELECT MAX(created_at) FROM doc_events WHERE doc_id = ? AND kind = 'enviado'");
    $st->execute([(int)$doc['id']]);
    admin_render('docs_send', ['doc' => $doc, 'k' => $k, 'self' => $self, 'f' => $f, 'errors' => $errors, 'recentSend' => (int)$st->fetchColumn() > time() - DOCS_SEND_GAP_SECS,
        'transport' => mail_settings()['transport'] ?? 'mail', 'title' => 'Enviar ' . mb_strtolower($k['label']) . ' ' . $doc['doc_code'], 'crumb' => 'Enviar']);
}
