<?php
declare(strict_types=1);

/*
 * Parámetros de documentos (Configuración → Documentos): Parámetros, IVA, Formas de pago, Tarifas; y Bloqueo de periodos (Sistema).
 * Todo genérico: nada fijo de ningún programa externo (las formas de pago de NCS se pueden IMPORTAR, no vienen de serie).
 *
 *  - Valores sueltos en `settings` con prefijo docp_ (ver DOCP_DEFAULTS y docp()). El cliente de contado sigue siendo el ajuste
 *    erp_retail_customer (una sola fuente), y la serie por defecto de cada tipo es doc_series.is_default (también una sola fuente).
 *  - IVA: doc_vat_rates (pct_bp = % × 100; surcharge_bp = recargo de equivalencia × 100). Siempre un tipo por defecto entre los activos.
 *  - Formas de pago: doc_pay_methods (código único; el nombre se usa en facturas y fichas vía erp_pay_names()). Un código que ya
 *    aparece en clientes o facturas no se borra ni cambia de código: solo se desactiva.
 *  - Tarifas: doc_tariffs (num = el número que usan los clientes; ext_ref = referencia externa para enlazar después con otro programa).
 *  - Bloqueo de periodos: docp_lock_until (aaaammdd). Ningún documento con fecha igual o anterior se podrá crear ni modificar
 *    (period_is_locked()). Subirlo es libre; bajarlo o quitarlo pide la contraseña y queda en el Historial.
 */

const DOCP_DEFAULTS = [
    'tariff' => '1', 'pay_method' => '',
    'dec_qty' => '2', 'dec_price' => '2', 'dec_amount' => '2',
    'ask_discount' => '0', 'ask_price' => '1', 'ask_tariff' => '0', 'ask_title' => '0',
    'warn_stock_min' => '1', 'warn_blocked' => '1',
    'vat_incl' => '1', 'cash_criterion' => '0',
    'lock_until' => '0',
];

function docp(string $k): string
{
    return setting('docp_' . $k, DOCP_DEFAULTS[$k]);
}

function docp_int(string $k): int
{
    return (int)docp($k);
}

function docp_bool(string $k): bool
{
    return docp($k) === '1';
}

/* ---------- Bloqueo de periodos ---------- */

/** Fecha (aaaammdd) hasta la que está bloqueado todo; 0 = nada bloqueado. */
function period_lock_day(): int
{
    $d = docp_int('lock_until');
    return $d >= 20000101 && $d <= 21001231 ? $d : 0;
}

/** ¿Está bloqueado ese día (aaaammdd)? Lo usarán la creación y modificación de documentos. */
function period_is_locked(int $day): bool
{
    $lock = period_lock_day();
    return $lock > 0 && $day <= $lock;
}

function period_lock_message(int $day): string
{
    return 'El periodo hasta el ' . erp_day(period_lock_day()) . ' está bloqueado: no se pueden crear ni modificar documentos con fecha ' . erp_day($day) . '.';
}

function admin_period_lock(): void
{
    $cur = period_lock_day();
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $in = trim((string)($_POST['lock'] ?? ''));
        $new = 0;
        if ($in !== '') {
            $new = erp_day_from_input($in);
            if ($new === 0) {
                $errors[] = 'La fecha no es válida.';
            } elseif ($new > (int)date('Ymd')) {
                $errors[] = 'No se puede bloquear un periodo que todavía no ha terminado: la fecha no puede ser posterior a hoy.';
            }
        }
        if (!$errors && $new !== $cur) {
            if ($new < $cur) { // desbloquear (bajar o quitar el bloqueo) reabre periodos cerrados: contraseña y rastro
                $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
                $st->execute([(int)$_SESSION['uid']]);
                if (empty($_POST['confirm']) || !password_verify((string)($_POST['password'] ?? ''), (string)$st->fetchColumn())) {
                    auth_register_failure((int)$_SESSION['uid']);
                    $errors[] = 'Para reabrir periodos (bajar o quitar el bloqueo) marca la casilla y escribe tu contraseña. No se ha cambiado nada.';
                }
            }
            if (!$errors) {
                save_setting('docp_lock_until', (string)$new);
                require_once __DIR__ . '/history.php';
                history_add('configuracion', 'bloqueo', 'Configuración → Bloqueo de periodos', 'ok',
                    ($cur > 0 ? erp_day($cur) : 'sin bloqueo') . ' → ' . ($new > 0 ? erp_day($new) : 'sin bloqueo'));
                flash($new > 0 ? 'Periodos bloqueados hasta el ' . erp_day($new) . '.' : 'Bloqueo quitado: todos los periodos están abiertos.');
                redirect('/admin/configuracion/bloqueo');
            }
        } elseif (!$errors) {
            flash('No hay cambios.');
            redirect('/admin/configuracion/bloqueo');
        }
    }
    $st = db()->query("SELECT created_at, username, detail FROM data_history WHERE kind = 'configuracion' AND what = 'bloqueo' ORDER BY id DESC LIMIT 10");
    admin_render('period_lock', ['cur' => $cur, 'errors' => $errors, 'log' => $st->fetchAll(), 'cfg' => 'bloqueo', 'title' => 'Bloqueo de periodos']);
}

/* ---------- IVA ---------- */

/** «21», «21,5» o «21.5» → centésimas de por ciento (2100, 2150); null si no es un porcentaje válido (0 a 100). */
function vat_parse_pct(string $s): ?int
{
    $s = trim(str_replace(',', '.', $s));
    if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $s)) {
        return null;
    }
    $bp = (int)round((float)$s * 100);
    return $bp >= 0 && $bp <= 10000 ? $bp : null;
}

/** 2100 → «21»; 520 → «5,2». */
function vat_format_pct(int $bp): string
{
    return rtrim(rtrim(number_format($bp / 100, 2, ',', ''), '0'), ',');
}

function vat_all(): array
{
    return db()->query('SELECT * FROM doc_vat_rates ORDER BY sort, id')->fetchAll();
}

/** Garantiza exactamente un tipo por defecto entre los activos (ninguno si no hay activos). */
function vat_fix_defaults(): void
{
    $db = db();
    $rows = vat_all();
    $active = array_values(array_filter($rows, fn($r) => (int)$r['is_active'] === 1));
    $pick = null;
    foreach ($active as $r) {
        if ((int)$r['is_default'] === 1) {
            $pick = (int)$r['id'];
            break;
        }
    }
    if ($pick === null && $active) {
        $pick = (int)$active[0]['id'];
    }
    foreach ($rows as $r) {
        $want = (int)$r['id'] === $pick ? 1 : 0;
        if ((int)$r['is_default'] !== $want) {
            $db->prepare('UPDATE doc_vat_rates SET is_default = ? WHERE id = ?')->execute([$want, (int)$r['id']]);
        }
    }
}

/** ¿Se usa este tipo en alguna línea de un documento propio (pedidos, albaranes…)? Entonces no se borra: se desactiva. */
function vat_is_used(int $id): bool
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM doc_lines WHERE vat_id = ?');
        $st->execute([$id]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function admin_vat(): void
{
    $db = db();
    $errors = [];
    $old = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if (($_POST['action'] ?? '') === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $rows = vat_all();
            if (count($rows) <= 1) {
                flash('Tiene que quedar al menos un tipo de IVA.', 'err');
            } elseif (vat_is_used($id)) {
                flash('Ese tipo ya se ha usado en documentos: no se puede borrar, solo desactivar.', 'err');
            } elseif (!array_filter($rows, fn($r) => (int)$r['id'] === $id)) {
                flash('Ese tipo ya no existe.', 'err');
            } else {
                $db->prepare('DELETE FROM doc_vat_rates WHERE id = ?')->execute([$id]);
                vat_fix_defaults();
                flash('Tipo de IVA borrado.');
            }
            redirect('/admin/configuracion/iva');
        }
        $old = $_POST;
        $rows = vat_all();
        $plan = [];
        $seen = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $in = (array)(($_POST['v'] ?? [])[$id] ?? []);
            $name = trim(mb_substr(trim(strip_tags((string)($in['name'] ?? $r['name']))), 0, 60));
            $pct = vat_parse_pct((string)($in['pct'] ?? ''));
            $sur = vat_parse_pct((string)($in['sur'] ?? '0') === '' ? '0' : (string)($in['sur'] ?? '0'));
            if ($name === '') {
                $errors[] = 'Cada tipo de IVA necesita un nombre.';
            }
            if ($pct === null) {
                $errors[] = 'El porcentaje de «' . $name . '» debe estar entre 0 y 100 (hasta dos decimales).';
            }
            if ($sur === null) {
                $errors[] = 'El recargo de equivalencia de «' . $name . '» debe estar entre 0 y 100 (hasta dos decimales).';
            }
            $plan[$id] = ['name' => $name, 'pct' => $pct, 'sur' => $sur, 'active' => !empty($in['active']) ? 1 : 0];
            if ($pct !== null && $sur !== null && $plan[$id]['active'] === 1) {
                $key = $pct . '/' . $sur;
                if (isset($seen[$key])) {
                    $errors[] = 'Hay dos tipos activos con el mismo porcentaje y recargo (' . vat_format_pct($pct) . ' %): deja uno o desactiva el otro.';
                }
                $seen[$key] = true;
            }
        }
        $new = (array)($_POST['new'] ?? []);
        $newRow = null;
        if (trim((string)($new['name'] ?? '')) !== '' || trim((string)($new['pct'] ?? '')) !== '') {
            $nName = trim(mb_substr(trim(strip_tags((string)($new['name'] ?? ''))), 0, 60));
            $nPct = vat_parse_pct((string)($new['pct'] ?? ''));
            $nSur = vat_parse_pct(trim((string)($new['sur'] ?? '')) === '' ? '0' : (string)$new['sur']);
            if ($nName === '' || $nPct === null || $nSur === null) {
                $errors[] = 'Nuevo tipo de IVA: escribe un nombre y un porcentaje válido (0 a 100); el recargo es opcional.';
            } elseif (isset($seen[$nPct . '/' . $nSur])) {
                $errors[] = 'Nuevo tipo de IVA: ya hay uno activo con ese porcentaje y recargo.';
            } else {
                $newRow = ['name' => $nName, 'pct' => $nPct, 'sur' => $nSur];
            }
        }
        if (!$errors) {
            $db->beginTransaction();
            try {
                foreach ($plan as $id => $p) {
                    $db->prepare('UPDATE doc_vat_rates SET name = ?, pct_bp = ?, surcharge_bp = ?, is_active = ? WHERE id = ?')->execute([$p['name'], $p['pct'], $p['sur'], $p['active'], $id]);
                }
                if ($newRow) {
                    $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) FROM doc_vat_rates')->fetchColumn();
                    $db->prepare('INSERT INTO doc_vat_rates (name, pct_bp, surcharge_bp, is_default, is_active, sort) VALUES (?, ?, ?, 0, 1, ?)')->execute([$newRow['name'], $newRow['pct'], $newRow['sur'], $max + 10]);
                }
                $want = (int)($_POST['default'] ?? 0);
                if ($want > 0 && isset($plan[$want]) && $plan[$want]['active'] === 1) {
                    $db->exec('UPDATE doc_vat_rates SET is_default = 0');
                    $db->prepare('UPDATE doc_vat_rates SET is_default = 1 WHERE id = ?')->execute([$want]);
                }
                vat_fix_defaults();
                save_setting('docp_vat_incl', !empty($_POST['vat_incl']) ? '1' : '0');
                save_setting('docp_cash_criterion', !empty($_POST['cash_criterion']) ? '1' : '0');
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            flash('IVA guardado.');
            redirect('/admin/configuracion/iva');
        }
    }
    admin_render('vat_rates', ['rows' => vat_all(), 'errors' => $errors, 'old' => $old, 'cfg' => 'iva', 'title' => 'IVA']);
}

/* ---------- Formas de pago ---------- */

function pay_methods_all(): array
{
    return db()->query('SELECT * FROM doc_pay_methods ORDER BY sort, code')->fetchAll();
}

/** Códigos de forma de pago que ya aparecen en clientes o facturas importadas: [código => true]. */
function pay_codes_in_use(): array
{
    $out = [];
    foreach (erp_pay_codes() as $c) {
        $out[(string)$c] = true;
    }
    return $out;
}

/** Da de alta los códigos de forma de pago de clientes y facturas importados que aún no están (con el nombre que tuvieran). Devuelve cuántos. */
function pay_methods_import_missing(): int
{
    $db = db();
    $have = array_map('strval', array_column(pay_methods_all(), 'code'));
    $names = erp_pay_names();
    $n = 0;
    $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) FROM doc_pay_methods')->fetchColumn();
    foreach (array_keys(pay_codes_in_use()) as $code) {
        $code = (string)$code; // las claves numéricas de un array llegan como enteros
        if (!in_array($code, $have, true) && preg_match('/^[A-Za-z0-9._-]{1,20}$/', $code)) {
            $db->prepare('INSERT INTO doc_pay_methods (code, name, days, is_active, sort) VALUES (?, ?, 0, 1, ?)')->execute([$code, mb_substr($names[$code] ?? '', 0, 40), $max + 10 * (++$n)]);
        }
    }
    return $n;
}

function admin_pay_methods(): void
{
    $db = db();
    $errors = [];
    $old = null;
    $inUse = pay_codes_in_use();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? 'save');
        if ($act === 'import_ncs') { // trae los códigos que ya usan clientes y facturas importadas (con el nombre que tuvieran)
            $n = pay_methods_import_missing();
            flash($n > 0 ? $n . ' forma' . ($n === 1 ? '' : 's') . ' de pago traída' . ($n === 1 ? '' : 's') . ' de los datos importados.' : 'No había formas de pago nuevas que traer.');
            redirect('/admin/configuracion/pagos');
        }
        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare('SELECT code FROM doc_pay_methods WHERE id = ?');
            $st->execute([$id]);
            $code = $st->fetchColumn();
            if ($code === false) {
                flash('Esa forma de pago ya no existe.', 'err');
            } elseif (isset($inUse[(string)$code]) || docp('pay_method') === (string)$code) {
                flash('La forma de pago «' . $code . '» está en uso (clientes, facturas o valor por defecto): no se puede borrar, solo desactivar.', 'err');
            } else {
                $db->prepare('DELETE FROM doc_pay_methods WHERE id = ?')->execute([$id]);
                flash('Forma de pago borrada.');
            }
            redirect('/admin/configuracion/pagos');
        }
        $old = $_POST;
        $rows = pay_methods_all();
        $plan = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $in = (array)(($_POST['p'] ?? [])[$id] ?? []);
            $days = trim((string)($in['days'] ?? '0'));
            if (!ctype_digit($days) || (int)$days > 365) {
                $errors[] = 'Los días de vencimiento de «' . $r['code'] . '» deben estar entre 0 y 365.';
            }
            $active = !empty($in['active']) ? 1 : 0;
            if ($active === 0 && docp('pay_method') === (string)$r['code']) {
                $errors[] = 'La forma de pago «' . $r['code'] . '» es la de por defecto (Parámetros): elige otra antes de desactivarla.';
            }
            $plan[$id] = ['name' => mb_substr(trim(strip_tags((string)($in['name'] ?? $r['name']))), 0, 40), 'days' => (int)$days, 'active' => $active];
        }
        $new = (array)($_POST['new'] ?? []);
        $newRow = null;
        if (trim((string)($new['code'] ?? '')) !== '' || trim((string)($new['name'] ?? '')) !== '') {
            $code = trim((string)($new['code'] ?? ''));
            $nd = trim((string)($new['days'] ?? '0'));
            if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $code)) {
                $errors[] = 'Nueva forma de pago: el código puede tener hasta 20 letras, números, punto, guion o guion bajo.';
            } elseif (array_filter($rows, fn($r) => mb_strtolower((string)$r['code']) === mb_strtolower($code))) {
                $errors[] = 'Nueva forma de pago: el código «' . $code . '» ya existe.';
            } elseif (!ctype_digit($nd) || (int)$nd > 365) {
                $errors[] = 'Nueva forma de pago: los días de vencimiento deben estar entre 0 y 365.';
            } else {
                $newRow = ['code' => $code, 'name' => mb_substr(trim(strip_tags((string)($new['name'] ?? ''))), 0, 40), 'days' => (int)$nd];
            }
        }
        if (!$errors) {
            $db->beginTransaction();
            foreach ($plan as $id => $p) {
                $db->prepare('UPDATE doc_pay_methods SET name = ?, days = ?, is_active = ? WHERE id = ?')->execute([$p['name'], $p['days'], $p['active'], $id]);
            }
            if ($newRow) {
                $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) FROM doc_pay_methods')->fetchColumn();
                $db->prepare('INSERT INTO doc_pay_methods (code, name, days, is_active, sort) VALUES (?, ?, ?, 1, ?)')->execute([$newRow['code'], $newRow['name'], $newRow['days'], $max + 10]);
            }
            $db->commit();
            flash('Formas de pago guardadas.');
            redirect('/admin/configuracion/pagos');
        }
    }
    $missing = array_values(array_diff(array_map('strval', array_keys($inUse)), array_map('strval', array_column(pay_methods_all(), 'code'))));
    admin_render('pay_methods', ['rows' => pay_methods_all(), 'inUse' => $inUse, 'missing' => $missing, 'errors' => $errors, 'old' => $old,
        'defaultCode' => docp('pay_method'), 'cfg' => 'pagos', 'title' => 'Formas de pago']);
}

/* ---------- Tarifas ---------- */

function tariffs_all(): array
{
    return db()->query('SELECT * FROM doc_tariffs ORDER BY num')->fetchAll();
}

/** Clientes importados que usan la tarifa de ese número. */
function tariff_customers(int $num): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM erp_customers WHERE tariff = ?');
    $st->execute([$num]);
    return (int)$st->fetchColumn();
}

function admin_tariffs(): void
{
    $db = db();
    $errors = [];
    $old = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $old = $_POST;
        $rows = tariffs_all();
        $plan = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $in = (array)(($_POST['t'] ?? [])[$id] ?? []);
            $name = mb_substr(trim(strip_tags((string)($in['name'] ?? $r['name']))), 0, 40);
            $active = !empty($in['active']) ? 1 : 0;
            if ($name === '') {
                $errors[] = 'La tarifa ' . $r['num'] . ' necesita un nombre.';
            }
            if ($active === 0 && (int)$r['is_active'] === 1) {
                if (docp_int('tariff') === (int)$r['num']) {
                    $errors[] = 'La tarifa ' . $r['num'] . ' es la de por defecto (Parámetros): elige otra antes de desactivarla.';
                } elseif (($n = tariff_customers((int)$r['num'])) > 0) {
                    $errors[] = 'La tarifa ' . $r['num'] . ' la usan ' . $n . ' cliente' . ($n === 1 ? '' : 's') . ': no se puede desactivar.';
                }
            }
            $pf = (int)($in['pf'] ?? 0); // campo de precio del catálogo que usa la tarifa (0 = el precio público)
            if ($pf !== 0 && !in_array($pf, array_column(docs_price_fields(), 'id'), true)) {
                $errors[] = 'La tarifa ' . $r['num'] . ': el campo de precio elegido no existe o no es de tipo precio.';
                $pf = 0;
            }
            $plan[$id] = ['name' => $name, 'ext' => mb_substr(trim(strip_tags((string)($in['ext'] ?? ''))), 0, 40), 'active' => $active, 'pf' => $pf];
        }
        $new = (array)($_POST['new'] ?? []);
        $newRow = null;
        if (trim((string)($new['name'] ?? '')) !== '') {
            $max = (int)$db->query('SELECT COALESCE(MAX(num), 0) FROM doc_tariffs')->fetchColumn();
            if ($max >= 9) {
                $errors[] = 'Como mucho 9 tarifas.';
            } else {
                $newRow = ['num' => $max + 1, 'name' => mb_substr(trim(strip_tags((string)$new['name'])), 0, 40), 'ext' => mb_substr(trim(strip_tags((string)($new['ext'] ?? ''))), 0, 40)];
            }
        }
        if (!$errors) {
            $db->beginTransaction();
            foreach ($plan as $id => $p) {
                $db->prepare('UPDATE doc_tariffs SET name = ?, ext_ref = ?, is_active = ?, price_field = ? WHERE id = ?')->execute([$p['name'], $p['ext'], $p['active'], $p['pf'], $id]);
            }
            if ($newRow) {
                $db->prepare('INSERT INTO doc_tariffs (num, name, ext_ref, is_active) VALUES (?, ?, ?, 1)')->execute([$newRow['num'], $newRow['name'], $newRow['ext']]);
            }
            $db->commit();
            flash('Tarifas guardadas.');
            redirect('/admin/configuracion/tarifas');
        }
    }
    $rows = tariffs_all();
    $users = [];
    foreach ($rows as $r) {
        $users[(int)$r['num']] = tariff_customers((int)$r['num']);
    }
    admin_render('tariffs', ['rows' => $rows, 'users' => $users, 'errors' => $errors, 'old' => $old, 'priceFields' => docs_price_fields(),
        'defaultPrice' => field_price_public() ?? (docs_price_fields()[0] ?? null), 'cfg' => 'tarifas', 'title' => 'Tarifas']);
}

/* ---------- Parámetros ---------- */

function admin_doc_params(): void
{
    $db = db();
    $errors = [];
    $old = null;
    $tariffs = array_filter(tariffs_all(), fn($t) => (int)$t['is_active'] === 1);
    $methods = array_filter(pay_methods_all(), fn($m) => (int)$m['is_active'] === 1);
    $series = array_filter(series_all(), fn($s) => (int)$s['is_active'] === 1);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $old = $_POST;
        $v = [];
        $t = (int)($_POST['tariff'] ?? 0);
        if (!array_filter($tariffs, fn($x) => (int)$x['num'] === $t)) {
            $errors[] = 'La tarifa por defecto debe ser una tarifa activa.';
        }
        $v['tariff'] = (string)$t;
        $pm = trim((string)($_POST['pay_method'] ?? ''));
        if ($pm !== '' && !array_filter($methods, fn($x) => (string)$x['code'] === $pm)) {
            $errors[] = 'La forma de pago por defecto debe ser una forma de pago activa.';
        }
        $v['pay_method'] = $pm;
        $retail = trim((string)($_POST['retail'] ?? ''));
        if ($retail !== '') {
            $st = $db->prepare('SELECT COUNT(*) FROM erp_customers WHERE cust_code = ?');
            $st->execute([$retail]);
            if ((int)$st->fetchColumn() === 0) {
                $errors[] = 'El cliente de contado «' . mb_substr($retail, 0, 30) . '» no existe entre los clientes. Déjalo vacío o escribe un código de cliente.';
            }
        }
        foreach (['dec_qty' => [0, 4, 'unidades'], 'dec_price' => [0, 6, 'precios'], 'dec_amount' => [0, 4, 'importes']] as $k => [$min, $max, $label]) {
            $x = trim((string)($_POST[$k] ?? ''));
            if (!ctype_digit($x) || (int)$x < $min || (int)$x > $max) {
                $errors[] = 'Los decimales de ' . $label . ' deben estar entre ' . $min . ' y ' . $max . '.';
            }
            $v[$k] = $x;
        }
        $defaults = [];
        foreach (DOC_SERIES_TYPES as $type => $label) {
            $id = (int)(($_POST['series'] ?? [])[$type] ?? 0);
            if ($id > 0) {
                $s = $series[$id] ?? null;
                if (!$s || $s['doc_type'] !== $type) {
                    $errors[] = 'La serie por defecto de «' . $label . '» debe ser una serie activa de ese tipo.';
                } else {
                    $defaults[$type] = $id;
                }
            }
        }
        if (!$errors) {
            $db->beginTransaction();
            foreach ($v as $k => $val) {
                save_setting('docp_' . $k, $val);
            }
            foreach (['ask_discount', 'ask_price', 'ask_tariff', 'ask_title', 'warn_stock_min', 'warn_blocked'] as $k) {
                save_setting('docp_' . $k, !empty($_POST[$k]) ? '1' : '0');
            }
            save_setting('erp_retail_customer', mb_substr($retail, 0, 40)); // una sola fuente: el mismo ajuste que usa la sincronización
            foreach ($defaults as $type => $id) {
                $db->prepare('UPDATE doc_series SET is_default = 0 WHERE doc_type = ?')->execute([$type]);
                $db->prepare('UPDATE doc_series SET is_default = 1 WHERE id = ?')->execute([$id]);
            }
            $db->commit();
            flash('Parámetros guardados.');
            redirect('/admin/configuracion/parametros');
        }
    }
    admin_render('doc_params', ['tariffs' => $tariffs, 'methods' => $methods, 'series' => $series, 'retail' => erp_retail_code(),
        'errors' => $errors, 'old' => $old, 'cfg' => 'parametros', 'title' => 'Parámetros de documentos']);
}
