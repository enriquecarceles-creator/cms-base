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

/**
 * /admin/clientes/nuevo y /admin/clientes/<código>/modificar. $c = cliente guardado (null = nuevo). El acceso (erp_guard) ya está comprobado.
 * Solo se modifican los creados en el CMS: los de NCS se cambian en NCS y llegan sincronizando.
 */
function admin_client_edit(?array $c): void
{
    $db = db();
    $isNew = $c === null;
    if (!$isNew && !erp_customer_is_cms($c)) {
        flash('Este cliente viene de NCS: sus datos se cambian en NCS y llegan al sincronizar. Las notas internas sí se escriben aquí, en su pestaña.', 'err');
        redirect('/admin/clientes/' . rawurlencode((string)$c['cust_code']));
    }
    $tariffs = array_values(array_filter(tariffs_all(), fn($t) => (int)$t['is_active'] === 1 || (!$isNew && (int)$t['num'] === (int)$c['tariff'])));
    $methods = array_values(array_filter(pay_methods_all(), fn($m) => (int)$m['is_active'] === 1 || (!$isNew && (string)$m['code'] === (string)$c['pay_code'])));
    $v = $c ?? ['cust_code' => erp_customer_next_code(), 'name' => '', 'trade_name' => '', 'nif' => '', 'contact' => '', 'phone' => '', 'phone2' => '', 'email' => '',
        'address' => '', 'postcode' => '', 'city' => '', 'province' => '', 'country' => '',
        'tariff' => docp_int('tariff'), 'pay_code' => docp('pay_method'), 'disc_bp' => 0, 'blocked' => 0, 'ended_day' => 0];
    $v['disc_txt'] = rtrim(rtrim(number_format((int)$v['disc_bp'] / 100, 2, ',', ''), '0'), ',');
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $in = $_POST;
        foreach (ERP_CUST_FIELDS as $k => $max) {
            $v[$k] = erp_txt($in[$k] ?? '', $max);
        }
        $v['nif'] = strtoupper(str_replace([' ', '.', '-'], '', $v['nif']));
        $v['blocked'] = !empty($in['blocked']) ? 1 : 0;
        $wasEnded = (int)($v['ended_day'] ?? 0) > 0;
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
        if ($isNew) {
            $v['cust_code'] = mb_substr(trim((string)($in['cust_code'] ?? '')), 0, 40);
            if (($err = erp_customer_code_error($v['cust_code'])) !== '') {
                $errors[] = $err;
            }
        }
        if (!$errors) {
            $cols = [...array_keys(ERP_CUST_FIELDS), 'tariff', 'pay_code', 'disc_bp', 'blocked', 'ended_day'];
            try {
                if ($isNew) {
                    $v['origin'] = 'cms';
                    $v['row_hash'] = '';
                    $v['created_day'] = (int)date('Ymd');
                    $v['synced_at'] = 0;
                    $all = ['cust_code', ...$cols, 'origin', 'row_hash', 'created_day', 'synced_at'];
                    $db->prepare('INSERT INTO erp_customers (' . implode(', ', $all) . ') VALUES (' . implode(', ', array_fill(0, count($all), '?')) . ')')
                        ->execute(array_map(fn($k) => $v[$k], $all));
                } else {
                    $db->prepare('UPDATE erp_customers SET ' . implode(' = ?, ', $cols) . " = ? WHERE id = ? AND origin = 'cms'")
                        ->execute([...array_map(fn($k) => $v[$k], $cols), (int)$c['id']]);
                }
            } catch (PDOException $e) {
                error_log('cms: alta de cliente: ' . get_class($e));
                $errors[] = 'No se pudo guardar (¿otro usuario ha creado a la vez un cliente con ese código?). Inténtalo de nuevo.';
            }
            if (!$errors) {
                $warn = erp_nif_warning((string)$v['nif']);
                flash('Cliente ' . $v['cust_code'] . ($isNew ? ' creado.' : ' guardado.') . ($warn !== '' ? ' Aviso: ' . $warn : ''));
                redirect('/admin/clientes/' . rawurlencode((string)$v['cust_code']));
            }
        }
    }
    admin_render('client_edit', ['v' => $v, 'isNew' => $isNew, 'tariffs' => $tariffs, 'methods' => $methods, 'errors' => $errors,
        'nifWarn' => erp_nif_warning((string)$v['nif']), 'title' => $isNew ? 'Nuevo cliente' : 'Modificar cliente', 'crumb' => $isNew ? 'Nuevo cliente' : 'Modificar']);
}
