<?php
declare(strict_types=1);

/*
 * Enviar la factura por email (B3b). El correo lleva la factura dentro (HTML) y un ENLACE SEGURO a /factura/<código>,
 * sin adjunto (no hay generador de PDF sin librerías: el cliente la guarda como PDF desde el navegador).
 *
 * - El código del enlace son 32 bytes aleatorios (64 caracteres hex). En la base solo se guarda su huella sha256
 *   (erp_invoice_links.token_hash): el enlace completo existe únicamente en el correo enviado.
 * - Válido de 7 a 365 días (30 por defecto, ajuste erp_link_days). Se puede anular en el Historial de la factura.
 * - Se guardan las visitas, la fecha de la PRIMERA apertura y la última. Un enlace inexistente, caducado o anulado da
 *   el mismo 404 que cualquier dirección que no existe; los fallos por IP se limitan (login_attempts, prefijo «f:»).
 * - El enlace es público a propósito (quien lo tenga ve esa factura, y solo esa): por eso caduca y se puede anular.
 *   La página pública no enseña notas, etiquetas, estado de cobro ni enlaces al panel.
 * - Las facturas anuladas se pueden enviar con confirmación explícita y con el sello visible en el correo y en el enlace.
 * - Cada intento queda en erp_invoice_sends y en el historial de la factura. Tablas ERP: fuera de las copias.
 */

const ERP_LINK_DAYS_MIN = 7;
const ERP_LINK_DAYS_MAX = 365;
const ERP_LINK_DAYS_DEFAULT = 30;
const ERP_EMAIL_MAX_LINES = 60;   // líneas dentro del cuerpo del correo; el resto, en el enlace
const ERP_LINK_FAIL_MAX = 30;     // enlaces inválidos por IP en 15 minutos
const ERP_SEND_GAP_SECS = 60;     // otro envío de la misma factura antes de este tiempo pide confirmar

/** Días de validez por defecto (el último elegido) dentro del rango permitido. */
function erp_link_days_default(): int
{
    $d = (int)setting('erp_link_days', (string)ERP_LINK_DAYS_DEFAULT);
    return $d >= ERP_LINK_DAYS_MIN && $d <= ERP_LINK_DAYS_MAX ? $d : ERP_LINK_DAYS_DEFAULT;
}

function erp_link_hash(string $token): string
{
    return hash('sha256', $token);
}

/** Crea un enlace nuevo. Devuelve el código en claro (solo existe aquí y en el correo), el id y la caducidad. */
function erp_link_create(int $invoiceId, string $to, int $days, string $by): array
{
    $token = bin2hex(random_bytes(32));
    $now = time();
    $exp = $now + $days * 86400;
    $db = db();
    $db->prepare('INSERT INTO erp_invoice_links (invoice_id, token_hash, expires_at, created_at, created_by, email_to) VALUES (?,?,?,?,?,?)')
        ->execute([$invoiceId, erp_link_hash($token), $exp, $now, mb_substr($by, 0, 100), mb_substr($to, 0, 250)]);
    $st = $db->prepare('SELECT id FROM erp_invoice_links WHERE token_hash = ?');
    $st->execute([erp_link_hash($token)]);
    return ['id' => (int)$st->fetchColumn(), 'token' => $token, 'expires' => $exp];
}

function erp_link_url(string $token): string
{
    return site_origin() . url('/factura/' . $token);
}

/** 'vigente' | 'caducado' | 'anulado'. */
function erp_link_state(array $l): string
{
    if ((int)$l['revoked_at'] > 0) {
        return 'anulado';
    }
    return (int)$l['expires_at'] <= time() ? 'caducado' : 'vigente';
}

/** Enlaces de una factura, los más recientes primero. */
function erp_links_for(int $invoiceId): array
{
    $st = db()->prepare('SELECT * FROM erp_invoice_links WHERE invoice_id = ? ORDER BY created_at DESC, id DESC LIMIT 100');
    $st->execute([$invoiceId]);
    return $st->fetchAll();
}

/** Anula un enlace vigente de la factura. Devuelve false si no existe, no es de esa factura o ya no estaba vigente. */
function erp_link_revoke(int $invoiceId, int $linkId): bool
{
    $st = db()->prepare('SELECT * FROM erp_invoice_links WHERE id = ? AND invoice_id = ?');
    $st->execute([$linkId, $invoiceId]);
    $l = $st->fetch();
    if (!$l || erp_link_state($l) !== 'vigente') {
        return false;
    }
    db()->prepare('UPDATE erp_invoice_links SET revoked_at = ? WHERE id = ? AND revoked_at = 0')->execute([time(), $linkId]);
    erp_event($invoiceId, 'enlace_revocado', 'Enlace enviado a ' . $l['email_to'] . ' el ' . date('d/m/Y H:i', (int)$l['created_at']));
    return true;
}

/** Asunto y texto por defecto del correo. */
function erp_send_defaults(array $inv, ?array $cust): array
{
    $co = doc_company();
    $company = $co['trade'] !== '' ? $co['trade'] : ($co['legal'] !== '' ? $co['legal'] : site_name());
    $annulled = (int)$inv['annulled'] === 1;
    $d = doc_settings();
    $subject = 'Factura ' . $inv['inv_number'] . ' de ' . $company . ($annulled ? ' (' . $d['stamp'] . ')' : '');
    $text = "Hola,\n\nTe enviamos la factura " . $inv['inv_number'] . ' de fecha ' . erp_day((int)$inv['inv_day']) . ' por importe de ' . erp_money((int)$inv['total_cents']) . ".\n\n"
        . "Puedes verla, imprimirla o guardarla en PDF desde el enlace que aparece debajo; también va incluida más abajo en este correo.\n"
        . ($annulled ? "\nIMPORTANTE: esta factura figura como " . $d['stamp'] . ".\n" : '')
        . "\nUn saludo,\n" . $company;
    return ['subject' => $subject, 'text' => $text];
}

/** Correo de envío por defecto: el de la factura (Modificar) o, si no hay, el del cliente (nunca el del cliente de contado). */
function erp_send_default_to(array $state, ?array $cust, bool $isRetail): string
{
    $to = trim((string)$state['email_to']);
    if ($to === '' && $cust && !$isRetail) {
        $to = trim((string)$cust['email']);
    }
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
}

/** HTML del correo: mensaje + enlace + la factura con estilos en línea (los programas de correo ignoran <style>). */
function erp_invoice_email_html(array $vars, string $message, string $url, int $expires): string
{
    $vars['message'] = $message;
    $vars['linkUrl'] = $url;
    $vars['expires'] = $expires;
    $vars['maxLines'] = ERP_EMAIL_MAX_LINES;
    ob_start();
    render('documents/invoice_email', $vars, null);
    return (string)ob_get_clean();
}

/** Versión de texto: el mensaje, el enlace y un resumen de la factura. */
function erp_invoice_email_text(array $vars, string $message, string $url, int $expires): string
{
    $inv = $vars['inv'];
    $annulled = (int)$inv['annulled'] === 1;
    return trim($message) . "\n\n"
        . "Ver la factura: " . $url . "\n"
        . '(El enlace es personal y caduca el ' . date('d/m/Y', $expires) . ".)\n\n"
        . '--- ' . $vars['d']['title'] . ' ' . $inv['inv_number'] . ($annulled ? ' · ' . $vars['d']['stamp'] : '') . " ---\n"
        . 'Fecha: ' . erp_day((int)$inv['inv_day']) . "\n"
        . 'Total: ' . erp_money((int)$inv['total_cents']) . "\n"
        . (!empty($vars['dues']) ? "Vencimientos:\n" . implode('', array_map(fn($du) => '  ' . erp_day((int)$du['day']) . ': ' . erp_money((int)$du['cents']) . "\n", $vars['dues'])) : '');
}

/**
 * POST «send» de /admin/ventas/<número>/enviar. Si todo va bien redirige al Historial; si algo falla devuelve
 * [formulario, errores] para volver a enseñar la pantalla con lo escrito.
 */
function erp_invoice_send_post(array $inv, string $self): array
{
    $id = (int)$inv['id'];
    $f = [
        'to' => mail_clean((string)($_POST['to'] ?? '')),
        'days' => (string)(int)($_POST['days'] ?? 0),
        'subject' => mb_substr(mail_clean((string)($_POST['subject'] ?? '')), 0, 150),
        'message' => mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? ''))), 0, 2000),
        'copy' => !empty($_POST['copy']),
        'revoke_old' => !empty($_POST['revoke_old']),
        'confirm_annulled' => !empty($_POST['confirm_annulled']),
        'confirm_dup' => !empty($_POST['confirm_dup']),
    ];
    $errors = [];
    if (!filter_var($f['to'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Escribe un correo válido para el destinatario.';
    }
    $days = (int)$f['days'];
    if ($days < ERP_LINK_DAYS_MIN || $days > ERP_LINK_DAYS_MAX) {
        $errors[] = 'El enlace debe durar entre ' . ERP_LINK_DAYS_MIN . ' y ' . ERP_LINK_DAYS_MAX . ' días.';
    }
    if ($f['subject'] === '') {
        $errors[] = 'Escribe el asunto.';
    }
    if ($f['message'] === '') {
        $errors[] = 'Escribe el mensaje.';
    }
    if ((int)$inv['annulled'] === 1 && !$f['confirm_annulled']) {
        $errors[] = 'Esta factura está ANULADA: marca la casilla para confirmar que quieres enviarla igualmente.';
    }
    $st = db()->prepare("SELECT MAX(sent_at) FROM erp_invoice_sends WHERE invoice_id = ? AND status = 'ok'");
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > time() - ERP_SEND_GAP_SECS && !$f['confirm_dup']) {
        $errors[] = 'Esta factura se acaba de enviar. Si quieres enviarla otra vez, marca la casilla de confirmación.';
    }
    if ($errors) {
        return [$f, $errors];
    }

    $by = (string)current_user()['username'];
    $vars = erp_invoice_doc_vars((string)$inv['inv_number']);
    $bcc = '';
    if ($f['copy']) {
        $me = db()->prepare('SELECT email FROM users WHERE id = ?');
        $me->execute([(int)$_SESSION['uid']]);
        $mine = trim((string)$me->fetchColumn());
        $bcc = filter_var($mine, FILTER_VALIDATE_EMAIL) ? $mine : '';
    }
    $link = erp_link_create($id, $f['to'], $days, $by);
    $url = erp_link_url($link['token']);
    $r = mail_send([
        'to' => $f['to'], 'subject' => $f['subject'],
        'text' => erp_invoice_email_text($vars, $f['message'], $url, $link['expires']),
        'html' => erp_invoice_email_html($vars, $f['message'], $url, $link['expires']),
        'bcc' => $bcc,
    ]);
    $db = db();
    $db->prepare('INSERT INTO erp_invoice_sends (invoice_id, link_id, to_email, subject, status, error, user_id, username, sent_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $link['id'], $f['to'], $f['subject'], $r['ok'] ? 'ok' : 'error', mb_substr($r['error'], 0, 250), (int)$_SESSION['uid'], mb_substr($by, 0, 100), time()]);
    if (!$r['ok']) {
        // El enlace nunca llegó a nadie: se anula para no dejar un acceso vigente sin dueño. Los anteriores se conservan.
        $db->prepare('UPDATE erp_invoice_links SET revoked_at = ? WHERE id = ?')->execute([time(), $link['id']]);
        erp_event($id, 'envio_error', 'A ' . $f['to'] . ': ' . $r['error']);
        return [$f, ['No se pudo enviar el correo. ' . $r['error']]];
    }
    $revoked = 0;
    if ($f['revoke_old']) {
        $st = $db->prepare('UPDATE erp_invoice_links SET revoked_at = ? WHERE invoice_id = ? AND id <> ? AND revoked_at = 0 AND expires_at > ?');
        $st->execute([time(), $id, $link['id'], time()]);
        $revoked = $st->rowCount();
    }
    save_setting('erp_link_days', (string)$days);
    erp_event($id, 'envio', 'A ' . $f['to'] . ' · enlace de ' . $days . ' días' . ((int)$inv['annulled'] === 1 ? ' · factura ANULADA' : ''));
    if ($revoked > 0) {
        erp_event($id, 'enlace_revocado', $revoked . ' enlace' . ($revoked === 1 ? '' : 's') . ' anterior' . ($revoked === 1 ? '' : 'es') . ' al enviar de nuevo');
    }
    flash('Factura enviada a ' . $f['to'] . '. El enlace caduca el ' . date('d/m/Y', $link['expires']) . '.');
    redirect($self . '?pestana=historial');
}

/** Pantalla /admin/ventas/<número>/enviar. $v son las variables del formulario de factura. */
function erp_invoice_send_screen(array $v, ?array $form, array $errors): void
{
    $def = erp_send_defaults($v['inv'], $v['cust']);
    $v['f'] = $form ?? [
        'to' => erp_send_default_to($v['state'], $v['cust'], $v['isRetail']),
        'days' => (string)erp_link_days_default(),
        'subject' => $def['subject'], 'message' => $def['text'],
        'copy' => false, 'revoke_old' => true, 'confirm_annulled' => false, 'confirm_dup' => false,
    ];
    $v['errors'] = $errors;
    $st = db()->prepare("SELECT MAX(sent_at) FROM erp_invoice_sends WHERE invoice_id = ? AND status = 'ok'");
    $st->execute([(int)$v['inv']['id']]);
    $v['recentSend'] = (int)$st->fetchColumn() > time() - ERP_SEND_GAP_SECS;
    $v['annulled'] = (int)$v['inv']['annulled'] === 1;
    $v['transport'] = mail_settings()['transport'];
    $v['mailError'] = mail_last_error();
    $v['https'] = is_https();
    $v['crumb'] = 'Enviar factura ' . $v['inv']['inv_number'];
    $v['title'] = 'Enviar factura';
    admin_render('invoice_send', $v);
}

/** POST «link_revoke» desde el Historial. */
function erp_invoice_link_revoke_post(array $inv, string $self): never
{
    if (erp_link_revoke((int)$inv['id'], (int)($_POST['id'] ?? 0))) {
        flash('Enlace anulado. Quien lo tenga ya no podrá abrir la factura.');
    } else {
        flash('Ese enlace ya no estaba vigente.', 'err');
    }
    redirect($self . '?pestana=historial');
}

/* ---------- Página pública /factura/<código> ---------- */

/** Enlace por su código en claro (con el número de factura) o null. No mira la caducidad: eso lo decide quien llama. */
function erp_link_find(string $token): ?array
{
    $st = db()->prepare('SELECT l.*, i.inv_number FROM erp_invoice_links l JOIN erp_invoices i ON i.id = l.invoice_id WHERE l.token_hash = ?');
    $st->execute([erp_link_hash($token)]);
    return $st->fetch() ?: null;
}

/** La factura de un enlace vigente, sin sesión. Cualquier otra cosa (inexistente, caducado, anulado) da el 404 normal. */
function page_invoice_link(string $token): void
{
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    $db = db();
    $ip = 'f:' . ($_SERVER['REMOTE_ADDR'] ?? '0');
    $db->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
    $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= ERP_LINK_FAIL_MAX) {
        not_found();
        return;
    }
    $link = preg_match('/^[a-f0-9]{64}$/', $token) ? erp_link_find($token) : null;
    if (!$link || erp_link_state($link) !== 'vigente') {
        $db->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
        not_found();
        return;
    }
    $vars = erp_invoice_doc_vars((string)$link['inv_number']);
    if ($vars === null) {
        not_found();
        return;
    }
    $now = time();
    $first = (int)$link['first_view_at'] === 0;
    $db->prepare('UPDATE erp_invoice_links SET views = views + 1, last_view_at = ?, first_view_at = CASE WHEN first_view_at = 0 THEN ? ELSE first_view_at END WHERE id = ?')
        ->execute([$now, $now, (int)$link['id']]);
    if ($first) {
        erp_event((int)$link['invoice_id'], 'enlace_abierto', 'Primera apertura del enlace enviado a ' . $link['email_to'], 'enlace', 0);
    }
    $vars['public'] = true;
    $vars['nb'] = ['prev' => null, 'next' => null];
    render('documents/invoice_' . $vars['d']['template'], $vars, null);
}
