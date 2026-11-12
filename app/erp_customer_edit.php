<?php
declare(strict_types=1);

/*
 * Alta y edición manual de clientes (Clientes → «Nuevo cliente» y «Modificar» en la ficha).
 * Los creados aquí llevan origin = 'cms' y se editan enteros (el código solo al crearlos; con letra, nunca solo números, para no chocar con NCS).
 * Los que vienen de NCS (origin = 'ncs') no se editan aquí: se cambian en NCS y llegan sincronizando (las notas internas, en su pestaña).
 * La sincronización con NCS nunca toca un cliente creado aquí (ver erp_stage_build / erp_run en app/erp.php).
 */

/** Prefijo de los códigos que propone el CMS. NCS usa códigos solo numéricos (00001…): con letra delante nunca chocan con un cliente nuevo de NCS. */
const ERP_CUST_PREFIX = 'C';

/** Siguiente código libre del CMS: C + (mayor número de los C<número> + 1), con 5 cifras como mínimo (C00001). */
function erp_customer_next_code(): string
{
    $max = 0;
    $st = db()->prepare('SELECT cust_code FROM erp_customers WHERE cust_code LIKE ?');
    $st->execute([ERP_CUST_PREFIX . '%']);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $n = substr((string)$c, strlen(ERP_CUST_PREFIX));
        if (ctype_digit($n) && strlen($n) <= 15) {
            $max = max($max, (int)$n);
        }
    }
    return ERP_CUST_PREFIX . str_pad((string)($max + 1), 5, '0', STR_PAD_LEFT);
}

/** Problema con un código de cliente nuevo ('' si vale). */
function erp_customer_code_error(string $code): string
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code)) {
        return 'El código solo puede llevar letras, números, punto, guion y guion bajo (máximo 40).';
    }
    if (ctype_digit($code)) {
        return 'El código no puede ser solo de números: esos son los de NCS y podría coincidir con un cliente que se cree allí. Usa una letra delante (por ejemplo ' . erp_customer_next_code() . ').';
    }
    if (in_array(strtolower($code), ['nuevo', 'modificar'], true)) {
        return 'Ese código está reservado.';
    }
    $st = db()->prepare('SELECT COUNT(*) FROM erp_customers WHERE LOWER(cust_code) = LOWER(?)');
    $st->execute([$code]);
    if ((int)$st->fetchColumn() > 0) {
        return 'Ya hay un cliente con el código «' . $code . '». El siguiente libre es ' . erp_customer_next_code() . '.';
    }
    return '';
}

/** ¿El NIF/CIF/NIE tiene formato español válido (con su letra o dígito de control)? '' si vale; si no, el aviso. */
function erp_nif_warning(string $nif): string
{
    $n = strtoupper(preg_replace('/[\s.\-\/]/', '', $nif) ?? '');
    if ($n === '') {
        return '';
    }
    $letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    if (preg_match('/^(\d{8})([A-Z])$/', $n, $m)) { // DNI
        return $letters[(int)$m[1] % 23] === $m[2] ? '' : 'La letra del NIF no corresponde a sus números.';
    }
    if (preg_match('/^([XYZ])(\d{7})([A-Z])$/', $n, $m)) { // NIE
        return $letters[(int)(strpos('XYZ', $m[1]) . $m[2]) % 23] === $m[3] ? '' : 'La letra del NIE no corresponde a sus números.';
    }
    if (preg_match('/^([ABCDEFGHJNPQRSUVW])(\d{7})([0-9A-J])$/', $n, $m)) { // CIF
        $sum = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = (int)$m[2][$i];
            $sum += $i % 2 === 0 ? intdiv($d * 2, 10) + ($d * 2) % 10 : $d;
        }
        $ctl = (10 - $sum % 10) % 10;
        $ok = str_contains('PQRSWN', $m[1]) ? $m[3] === 'JABCDEFGHI'[$ctl] : (str_contains('ABEH', $m[1]) ? $m[3] === (string)$ctl : ($m[3] === (string)$ctl || $m[3] === 'JABCDEFGHI'[$ctl]));
        return $ok ? '' : 'El dígito de control del CIF no es correcto.';
    }
    return 'El NIF no tiene formato español (DNI, NIE o CIF). Si es de otro país, puede estar bien.';
}

const ERP_CUST_FIELDS = ['name' => 255, 'trade_name' => 255, 'nif' => 40, 'contact' => 255, 'phone' => 40, 'phone2' => 40, 'email' => 190,
    'address' => 255, 'postcode' => 20, 'city' => 255, 'province' => 255, 'country' => 255];

/** ¿Este cliente se creó en el CMS (y por tanto se edita aquí)? */
function erp_customer_is_cms(array $c): bool
{
    return (string)($c['origin'] ?? 'ncs') === 'cms';
}

/** Campos de la ficha que son propios del CMS (valen también en clientes de NCS: la sincronización no los toca). */
const ERP_CUST_OWN = ['pay_days', 'def_series', 'doc_notes'];

/** Cuántos documentos tiene un cliente: los del CMS (borradores incluidos) y las facturas importadas de NCS. */
function erp_customer_docs_count(string $code): int
{
    $db = db();
    $st = $db->prepare('SELECT COUNT(*) FROM doc_headers WHERE cust_code = ?');
    $st->execute([$code]);
    $n = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT COUNT(*) FROM erp_invoices WHERE cust_code = ? AND origin = 'ncs'");
    $st->execute([$code]);
    return $n + (int)$st->fetchColumn();
}

/** Otros clientes con el mismo NIF (sin espacios, puntos ni guiones, sin mayúsculas): lista de códigos. */
function erp_customer_nif_dups(string $nif, int $exceptId): array
{
    $n = strtoupper(str_replace([' ', '.', '-'], '', $nif));
    if ($n === '') {
        return [];
    }
    $st = db()->prepare("SELECT cust_code FROM erp_customers WHERE id <> ? AND UPPER(REPLACE(REPLACE(REPLACE(nif, ' ', ''), '.', ''), '-', '')) = ? ORDER BY cust_code LIMIT 5");
    $st->execute([$exceptId, $n]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Lee y valida el formulario de cliente sobre $v (los datos actuales o los de un cliente nuevo). $ownOnly: solo los campos propios del CMS
 * (clientes de NCS). Rellena $errors. Devuelve $v con lo enviado.
 */
function erp_customer_read_post(array $v, array $in, array $tariffs, array $methods, bool $ownOnly, array &$errors): array
{
    if (!$ownOnly) {
        foreach (ERP_CUST_FIELDS as $k => $max) {
            $v[$k] = erp_txt($in[$k] ?? '', $max);
        }
        $v['nif'] = strtoupper(str_replace([' ', '.', '-'], '', $v['nif']));
        $wasEnded = (int)($v['ended_day'] ?? 0) > 0;
        $v['blocked'] = !empty($in['blocked']) ? 1 : 0;
        $v['ended_day'] = !empty($in['ended']) ? ($wasEnded ? (int)$v['ended_day'] : (int)date('Ymd')) : 0;
        $t = (int)($in['tariff'] ?? 0);
        $v['tariff'] = array_filter($tariffs, fn($x) => (int)$x['num'] === $t) ? $t : 0;
        $pc = trim((string)($in['pay_code'] ?? ''));
        if ($pc !== '' && !array_filter($methods, fn($x) => (string)$x['code'] === $pc)) {
            $errors[] = 'La forma de pago no está activa.';
            $pc = '';
        }
        $v['pay_code'] = $pc;
        $v['disc_txt'] = trim((string)($in['disc'] ?? ''));
        $dn = str_replace(',', '.', $v['disc_txt']);
        $d = $v['disc_txt'] === '' ? 0.0 : (is_numeric($dn) ? (float)$dn : -1.0);
        if ($d < 0 || $d > 100) {
            $errors[] = 'El descuento tiene que ser un número entre 0 y 100.';
        } else {
            $v['disc_bp'] = (int)round($d * 100);
        }
        if ($v['name'] === '') {
            $errors[] = 'Falta el nombre o razón social.';
        }
        if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El email no es válido.';
        }
    }
    $pd = dues_parse_days((string)($in['pay_days'] ?? ''));
    if ($pd === null) {
        $errors[] = 'El día de pago tiene que ser un día del mes (1 a 31); si son varios, sepáralos con comas (por ejemplo 5,25). Como mucho ' . DUES_MAX_PAYDAYS . '.';
        $pd = [];
    }
    $v['pay_days'] = implode(',', $pd);
    $ds = (int)($in['def_series'] ?? 0);
    $s = $ds > 0 ? series_get($ds) : null;
    if ($ds > 0 && (!$s || ((int)$s['is_active'] !== 1 && $ds !== (int)($v['def_series'] ?? 0)))) {
        $errors[] = 'La serie por defecto tiene que ser una serie activa.';
        $ds = 0;
    }
    $v['def_series'] = $ds;
    $v['doc_notes'] = mb_substr(trim(str_replace("\r", '', (string)($in['doc_notes'] ?? ''))), 0, 1000);
    return $v;
}

/** Pone al día los datos del cliente en sus BORRADORES (las facturas emitidas, pedidos y albaranes confirmados conservan lo que llevaban). */
function erp_customer_refresh_drafts(array $c): int
{
    $snap = docs_customer_snapshot($c);
    $st = db()->prepare("UPDATE doc_headers SET cust_name = ?, cust_nif = ?, cust_address = ?, cust_postcode = ?, cust_city = ?, cust_province = ?, cust_email = ?, rev = rev + 1
        WHERE cust_code = ? AND status = 'borrador'");
    $st->execute([mb_substr((string)$snap['cust_name'], 0, 255), mb_substr((string)$snap['cust_nif'], 0, 40), mb_substr((string)$snap['cust_address'], 0, 255),
        mb_substr((string)$snap['cust_postcode'], 0, 20), mb_substr((string)$snap['cust_city'], 0, 120), mb_substr((string)$snap['cust_province'], 0, 120),
        mb_substr((string)$snap['cust_email'], 0, 190), (string)$c['cust_code']]);
    return $st->rowCount();
}

/** Guarda la ficha de un cliente existente. Devuelve '' si fue bien, o el mensaje de error (conflicto de versión incluido). Lo llama admin_client(). */
function erp_customer_save(array $c, array $v, string $newCode, int $stamp, bool $ownOnly): string
{
    $db = db();
    $u = current_user();
    $by = mb_substr((string)($u['username'] ?? ''), 0, 100);
    $cols = $ownOnly ? ERP_CUST_OWN : [...array_keys(ERP_CUST_FIELDS), 'tariff', 'pay_code', 'disc_bp', 'blocked', 'ended_day', ...ERP_CUST_OWN];
    $db->beginTransaction();
    try {
        $st = $db->prepare('UPDATE erp_customers SET ' . implode(' = ?, ', $cols) . ' = ?, rev = rev + 1, updated_by = ?, updated_at = ? WHERE id = ? AND rev = ?');
        $st->execute([...array_map(fn($k) => $v[$k], $cols), $by, time(), (int)$c['id'], $stamp]);
        if ($st->rowCount() === 0) {
            $db->rollBack();
            return 'Otro usuario ha modificado este cliente mientras lo editabas. Recarga la página para ver sus cambios (no se ha guardado nada tuyo).';
        }
        if (!$ownOnly && $newCode !== (string)$c['cust_code']) { // el código solo cambia si no tiene documentos (se comprueba antes)
            $db->prepare('UPDATE erp_customers SET cust_code = ? WHERE id = ?')->execute([$newCode, (int)$c['id']]);
            $db->prepare('UPDATE erp_notes SET cust_code = ? WHERE cust_code = ?')->execute([$newCode, (string)$c['cust_code']]);
        }
        if (!$ownOnly) {
            erp_customer_refresh_drafts(array_merge($v, ['cust_code' => $newCode]));
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('cms: guardar cliente: ' . get_class($e));
        return 'No se pudo guardar (¿otro cliente con ese código?). Inténtalo de nuevo.';
    }
    return '';
}

/**
 * /admin/clientes/nuevo. El acceso (erp_guard) ya está comprobado. La edición de un cliente existente está en su ficha (?editar=1, admin_client()).
 */
function admin_client_edit(?array $c): void
{
    $db = db();
    if ($c !== null) { // dirección antigua /modificar: ahora se edita en la ficha
        redirect('/admin/clientes/' . rawurlencode((string)$c['cust_code']) . '?editar=1');
    }
    $tariffs = array_values(array_filter(tariffs_all(), fn($t) => (int)$t['is_active'] === 1));
    $methods = array_values(array_filter(pay_methods_all(), fn($m) => (int)$m['is_active'] === 1));
    $v = ['cust_code' => erp_customer_next_code(), 'name' => '', 'trade_name' => '', 'nif' => '', 'contact' => '', 'phone' => '', 'phone2' => '', 'email' => '',
        'address' => '', 'postcode' => '', 'city' => '', 'province' => '', 'country' => '', 'tariff' => docp_int('tariff'), 'pay_code' => docp('pay_method'),
        'pay_days' => '', 'disc_bp' => 0, 'blocked' => 0, 'ended_day' => 0, 'def_series' => 0, 'doc_notes' => ''];
    $v['disc_txt'] = '';
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $v = erp_customer_read_post($v, $_POST, $tariffs, $methods, false, $errors);
        $v['cust_code'] = mb_substr(trim((string)($_POST['cust_code'] ?? '')), 0, 40);
        if (($err = erp_customer_code_error($v['cust_code'])) !== '') {
            $errors[] = $err;
        }
        if (!$errors) {
            $v += ['origin' => 'cms', 'row_hash' => '', 'created_day' => (int)date('Ymd'), 'synced_at' => 0, 'rev' => 0, 'updated_by' => mb_substr((string)(current_user()['username'] ?? ''), 0, 100), 'updated_at' => time()];
            $all = ['cust_code', ...array_keys(ERP_CUST_FIELDS), 'tariff', 'pay_code', 'pay_days', 'disc_bp', 'blocked', 'ended_day', 'def_series', 'doc_notes', 'origin', 'row_hash', 'created_day', 'synced_at', 'rev', 'updated_by', 'updated_at'];
            try {
                $db->prepare('INSERT INTO erp_customers (' . implode(', ', $all) . ') VALUES (' . implode(', ', array_fill(0, count($all), '?')) . ')')
                    ->execute(array_map(fn($k) => $v[$k], $all));
                $warn = erp_nif_warning((string)$v['nif']);
                $dups = erp_customer_nif_dups((string)$v['nif'], 0);
                flash('Cliente ' . $v['cust_code'] . ' creado.' . ($warn !== '' ? ' Aviso: ' . $warn : '') . ($dups ? ' Aviso: ese NIF ya lo tiene ' . implode(', ', $dups) . '.' : ''));
                redirect('/admin/clientes/' . rawurlencode((string)$v['cust_code']));
            } catch (PDOException $e) {
                error_log('cms: alta de cliente: ' . get_class($e));
                $errors[] = 'No se pudo guardar (¿otro usuario ha creado a la vez un cliente con ese código?). Inténtalo de nuevo.';
            }
        }
    }
    admin_render('client_edit', ['v' => $v, 'tariffs' => $tariffs, 'methods' => $methods, 'errors' => $errors, 'series' => array_filter(series_all(), fn($s) => (int)$s['is_active'] === 1),
        'nifWarn' => erp_nif_warning((string)$v['nif']), 'title' => 'Nuevo cliente', 'crumb' => 'Nuevo cliente']);
}
