<?php
declare(strict_types=1);

/*
 * Parámetros de documentos (Configuración → Documentos): Parámetros, IVA, Formas de pago, Tarifas; y Bloqueo de periodos (Sistema).
 * Todo genérico: nada fijo de ningún programa externo (las formas de pago de NCS se pueden IMPORTAR, no vienen de serie).
 *
 *  - Valores sueltos en `settings` con prefijo docp_ (ver DOCP_DEFAULTS y docp()). El cliente de contado sigue siendo el ajuste
 *    erp_retail_customer (una sola fuente), y la serie por defecto de cada tipo es doc_series.is_default (también una sola fuente).
 *  - IVA: doc_vat_rates (pct_bp = % × 100; surcharge_bp = recargo de equivalencia × 100). Siempre un tipo por defecto entre los activos.
 *  - Formas de pago: doc_pay_methods (código único; el nombre se usa en facturas y fichas vía erp_pay_names(); `terms` = plazos de los
 *    vencimientos, ver app/docs_dues.php). Una que ya usan clientes o documentos no se borra: se ofrece desactivarla (deja de salir
 *    al elegir, pero los documentos antiguos la conservan).
 *  - Tarifas: doc_tariffs (num = el número que usan los clientes; ext_ref = referencia externa para enlazar después con otro programa).
 *  - Bloqueo de periodos: docp_lock_until (aaaammdd). Ningún documento con fecha igual o anterior se podrá crear ni modificar
 *    (period_is_locked()). Subirlo es libre; bajarlo o quitarlo pide la contraseña y queda en el Historial.
 */

const DOCP_DEFAULTS = [
    'tariff' => '1', 'pay_method' => '',
    'dec_qty' => '2', 'dec_price' => '2', 'dec_amount' => '2',
    'ask_discount' => '0', 'ask_price' => '1', 'ask_tariff' => '0', 'ask_title' => '0',
    'warn_stock_min' => '1', 'warn_blocked' => '1',
    'vat_incl' => '1', 'cash_criterion' => '0', 'retail_trade' => '0',
    'lock_until' => '0',
    'test_mode' => '1', 'test_text' => 'Documento de prueba – sin validez fiscal',
    'sup_prefix' => 'P', 'purch_stock' => '0',
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
        if (!in_array($code, $have, true) && !isset(pay_deleted()[$code]) && preg_match('/^[A-Za-z0-9._-]{1,20}$/', $code)) {
            $db->prepare("INSERT INTO doc_pay_methods (code, name, days, terms, is_active, sort) VALUES (?, ?, 0, '0', 1, ?)")->execute([$code, mb_substr($names[$code] ?? '', 0, 40), $max + 10 * (++$n)]);
        }
    }
    return $n;
}

/** Cuántos clientes, facturas de NCS y documentos del CMS usan cada forma de pago: [código => ['clientes' => n, 'ncs' => n, 'docs' => n]]. */
function pay_methods_usage(): array
{
    $out = [];
    foreach (['clientes' => 'erp_customers', 'ncs' => 'erp_invoices', 'docs' => 'doc_headers'] as $k => $t) {
        $only = $t === 'erp_invoices' ? " AND origin = 'ncs'" : ''; // las copias de facturas del CMS en Ventas ya cuentan como documentos
        foreach (db()->query("SELECT pay_code, COUNT(*) AS n FROM $t WHERE pay_code <> ''$only GROUP BY pay_code")->fetchAll() as $r) {
            $out[(string)$r['pay_code']][$k] = (int)$r['n'];
        }
    }
    return $out;
}

/** Frase con lo que usa una forma de pago («3 clientes y 2 documentos»); '' si nada. */
function pay_usage_text(array $u): string
{
    $parts = [];
    if (($u['clientes'] ?? 0) > 0) {
        $parts[] = $u['clientes'] . ' cliente' . ($u['clientes'] === 1 ? '' : 's');
    }
    if (($u['ncs'] ?? 0) > 0) {
        $parts[] = $u['ncs'] . ' factura' . ($u['ncs'] === 1 ? '' : 's') . ' importada' . ($u['ncs'] === 1 ? '' : 's');
    }
    if (($u['docs'] ?? 0) > 0) {
        $parts[] = $u['docs'] . ' documento' . ($u['docs'] === 1 ? '' : 's');
    }
    return count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' y ' . end($parts) : ($parts[0] ?? '');
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
        if ($act === 'delete' || $act === 'delete_move') { // «Eliminar»: sin uso se borra; con uso se pide a cuál pasarlo
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare('SELECT * FROM doc_pay_methods WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) {
                flash('Esa forma de pago ya no existe.', 'err');
                redirect('/admin/configuracion/pagos');
            }
            $code = (string)$row['code'];
            $u = pay_methods_usage()[$code] ?? [];
            $isDef = docp('pay_method') === $code;
            $needs = $isDef || ($u['clientes'] ?? 0) + ($u['docs'] ?? 0) > 0;
            $to = $act === 'delete_move' ? (string)($_POST['to'] ?? '') : null;
            if ($needs && $to === null) { // pantalla de confirmación con el desplegable
                $targets = [];
                foreach (pay_methods_all() as $m) {
                    if ((string)$m['code'] !== $code && (int)$m['is_active'] === 1) {
                        $targets[(string)$m['code']] = $m['code'] . ' · ' . ($m['name'] !== '' ? $m['name'] : 'sin nombre');
                    }
                }
                if (count(pay_methods_all()) <= 1 || !$targets) {
                    flash(count(pay_methods_all()) <= 1 ? 'Siempre tiene que quedar al menos una forma de pago.' : 'No hay otra forma de pago activa a la que pasarlo: crea o activa una antes.', 'err');
                    redirect('/admin/configuracion/pagos');
                }
                admin_delete_move('forma de pago', $code . ($row['name'] !== '' ? ' · ' . $row['name'] : ''), pay_usage_text($u), $isDef, $targets, '/admin/configuracion/pagos', $id, 'pagos');
                return;
            }
            $err = pay_method_delete($row, $needs ? $to : null);
            flash($err === '' ? 'Forma de pago «' . $code . '» eliminada.' . ($needs ? ' Sus clientes y documentos sin emitir pasan a «' . $to . '»; las facturas emitidas conservan su texto.' : '') : $err, $err === '' ? 'ok' : 'err');
            redirect('/admin/configuracion/pagos');
        }
        if ($act === 'deactivate') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $db->prepare('SELECT code, is_active FROM doc_pay_methods WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            $code = $row ? (string)$row['code'] : null;
            $usage = $code !== null ? pay_usage_text(pay_methods_usage()[$code] ?? []) : '';
            if ($code === null) {
                flash('Esa forma de pago ya no existe.', 'err');
            } elseif ($act === 'deactivate') {
                if (docp('pay_method') === $code) {
                    flash('La forma de pago «' . $code . '» es la de por defecto (Parámetros): elige otra antes de desactivarla.', 'err');
                } else {
                    $db->prepare('UPDATE doc_pay_methods SET is_active = 0 WHERE id = ?')->execute([$id]);
                    flash('Forma de pago «' . $code . '» desactivada: ya no sale al elegir, pero los clientes y documentos que la tenían la conservan.');
                }
            }
            redirect('/admin/configuracion/pagos');
        }
        $old = $_POST;
        $rows = pay_methods_all();
        $plan = [];
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            $in = (array)(($_POST['p'] ?? [])[$id] ?? []);
            $terms = dues_parse_terms((string)($in['terms'] ?? ''));
            if ($terms === null) {
                $errors[] = 'Vencimientos de «' . $r['code'] . '»: escribe los días separados por comas (0 para contado, 30, o 30,60,90), de 0 a ' . DUES_MAX_DAYS . ', en orden creciente y como mucho ' . DUES_MAX . '.';
                $terms = [0];
            }
            $active = !empty($in['active']) ? 1 : 0;
            if ($active === 0 && docp('pay_method') === (string)$r['code']) {
                $errors[] = 'La forma de pago «' . $r['code'] . '» es la de por defecto (Parámetros): elige otra antes de desactivarla.';
            }
            $plan[$id] = ['name' => mb_substr(trim(strip_tags((string)($in['name'] ?? $r['name']))), 0, 40), 'terms' => dues_terms_text($terms), 'days' => (int)$terms[0], 'active' => $active];
        }
        $new = (array)($_POST['new'] ?? []);
        $newRow = null;
        if (trim((string)($new['code'] ?? '')) !== '' || trim((string)($new['name'] ?? '')) !== '') {
            $code = trim((string)($new['code'] ?? ''));
            $nt = trim((string)($new['terms'] ?? '')) === '' ? [0] : dues_parse_terms((string)$new['terms']);
            if (!preg_match('/^[A-Za-z0-9._-]{1,20}$/', $code)) {
                $errors[] = 'Nueva forma de pago: el código puede tener hasta 20 letras, números, punto, guion o guion bajo.';
            } elseif (array_filter($rows, fn($r) => mb_strtolower((string)$r['code']) === mb_strtolower($code))) {
                $errors[] = 'Nueva forma de pago: el código «' . $code . '» ya existe.';
            } elseif (isset(pay_deleted()[$code])) {
                $errors[] = 'Nueva forma de pago: el código «' . $code . '» perteneció a una forma de pago eliminada que aún usan facturas antiguas; usa otro para que no cambien.';
            } elseif ($nt === null) {
                $errors[] = 'Nueva forma de pago: los vencimientos son días separados por comas (0, 30, o 30,60,90), de 0 a ' . DUES_MAX_DAYS . ', en orden creciente.';
            } else {
                $newRow = ['code' => $code, 'name' => mb_substr(trim(strip_tags((string)($new['name'] ?? ''))), 0, 40), 'terms' => dues_terms_text($nt), 'days' => (int)$nt[0]];
            }
        }
        if (!$errors) {
            $db->beginTransaction();
            foreach ($plan as $id => $p) {
                $db->prepare('UPDATE doc_pay_methods SET name = ?, terms = ?, days = ?, is_active = ? WHERE id = ?')->execute([$p['name'], $p['terms'], $p['days'], $p['active'], $id]);
            }
            if ($newRow) {
                $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) FROM doc_pay_methods')->fetchColumn();
                $db->prepare('INSERT INTO doc_pay_methods (code, name, days, terms, is_active, sort) VALUES (?, ?, ?, ?, 1, ?)')->execute([$newRow['code'], $newRow['name'], $newRow['days'], $newRow['terms'], $max + 10]);
            }
            $db->commit();
            flash('Formas de pago guardadas.');
            redirect('/admin/configuracion/pagos');
        }
    }
    $missing = array_values(array_diff(array_map('strval', array_keys($inUse)), array_map('strval', array_column(pay_methods_all(), 'code')), array_map('strval', array_keys(pay_deleted()))));
    admin_render('pay_methods', ['rows' => pay_methods_all(), 'inUse' => $inUse, 'usage' => pay_methods_usage(), 'missing' => $missing, 'errors' => $errors, 'old' => $old,
        'defaultCode' => docp('pay_method'), 'cfg' => 'pagos', 'title' => 'Formas de pago']);
}


/* ---------- Eliminar formas de pago y tarifas (con traspaso de lo que las usa) ---------- */

/** Cuántos documentos (los del CMS) usan cada tarifa: [número => n]. */
function tariff_docs_count(int $num): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM doc_headers WHERE tariff = ?');
    $st->execute([$num]);
    return (int)$st->fetchColumn();
}

/**
 * Borra una forma de pago pasando a $to (código de otra) lo que la usa: clientes y documentos NO emitidos (borradores, pedidos, albaranes).
 * Las facturas emitidas y las importadas conservan su código y siguen mostrando el nombre que tenía (queda guardado en «pay_deleted»);
 * sus vencimientos ya calculados no se tocan. Devuelve '' o el error.
 */
function pay_method_delete(array $row, ?string $to): string
{
    $db = db();
    $code = (string)$row['code'];
    if (count(pay_methods_all()) <= 1) {
        return 'Siempre tiene que quedar al menos una forma de pago.';
    }
    $isDefault = docp('pay_method') === $code;
    $used = pay_methods_usage()[$code] ?? [];
    if (($isDefault || array_sum(array_intersect_key($used, ['clientes' => 1, 'docs' => 1])) > 0) && $to === null) {
        return 'Elige a qué forma de pago pasar lo que usa «' . $code . '».';
    }
    if ($to !== null) {
        $ok = array_filter(pay_methods_all(), fn($m) => (string)$m['code'] === $to && (string)$m['code'] !== $code && (int)$m['is_active'] === 1);
        if (!$ok) {
            return 'La forma de pago a la que pasarlo tiene que existir y estar activa.';
        }
    }
    $db->beginTransaction();
    try {
        if ($to !== null) {
            $db->prepare('UPDATE erp_customers SET pay_code = ? WHERE pay_code = ?')->execute([$to, $code]);
            $db->prepare("UPDATE doc_headers SET pay_code = ?, rev = rev + 1 WHERE pay_code = ? AND status <> 'emitida'")->execute([$to, $code]);
            if ($isDefault) {
                save_setting('docp_pay_method', $to);
            }
        }
        $dead = pay_deleted();
        $dead[$code] = (string)($row['name'] ?? '');
        save_setting('pay_deleted', (string)json_encode($dead, JSON_UNESCAPED_UNICODE));
        $db->prepare('DELETE FROM doc_pay_methods WHERE id = ?')->execute([(int)$row['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return '';
}

/** Borra una tarifa pasando a $to (número de otra) los clientes y documentos NO emitidos que la usan. Las facturas emitidas conservan su número y sus precios (están en sus líneas). */
function tariff_delete(array $row, ?int $to): string
{
    $db = db();
    $num = (int)$row['num'];
    if (count(tariffs_all()) <= 1) {
        return 'Siempre tiene que quedar al menos una tarifa.';
    }
    $isDefault = docp_int('tariff') === $num;
    $in = tariff_customers($num) + tariff_docs_count($num);
    if (($isDefault || $in > 0) && $to === null) {
        return 'Elige a qué tarifa pasar lo que usa la tarifa ' . $num . '.';
    }
    if ($to !== null && !array_filter(tariffs_all(), fn($t) => (int)$t['num'] === $to && $to !== $num && (int)$t['is_active'] === 1)) {
        return 'La tarifa a la que pasarlo tiene que existir y estar activa.';
    }
    $db->beginTransaction();
    try {
        if ($to !== null) {
            $db->prepare('UPDATE erp_customers SET tariff = ? WHERE tariff = ?')->execute([$to, $num]);
            $db->prepare("UPDATE doc_headers SET tariff = ?, rev = rev + 1 WHERE tariff = ? AND status <> 'emitida'")->execute([$to, $num]);
            if ($isDefault) {
                save_setting('docp_tariff', (string)$to);
            }
        }
        $dead = tariff_deleted();
        $dead[(string)$num] = (string)$row['name'];
        save_setting('tariff_deleted', (string)json_encode($dead, JSON_UNESCAPED_UNICODE));
        $db->prepare('DELETE FROM doc_tariffs WHERE id = ?')->execute([(int)$row['id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    return '';
}

/** Pantalla de confirmación con el desplegable de a cuál pasar lo que usa lo que se borra. */
function admin_delete_move(string $what, string $label, string $usage, bool $isDefault, array $targets, string $back, int $id, string $cfg): void
{
    admin_render('delete_move', ['what' => $what, 'label' => $label, 'usage' => $usage, 'isDefault' => $isDefault, 'targets' => $targets, 'back' => $back, 'id' => $id,
        'cfg' => $cfg, 'title' => 'Eliminar ' . $what]);
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
        $act = (string)($_POST['action'] ?? 'save');
        if ($act === 'delete' || $act === 'delete_move') {
            $st = $db->prepare('SELECT * FROM doc_tariffs WHERE id = ?');
            $st->execute([(int)($_POST['id'] ?? 0)]);
            $row = $st->fetch();
            if (!$row) {
                flash('Esa tarifa ya no existe.', 'err');
                redirect('/admin/configuracion/tarifas');
            }
            $num = (int)$row['num'];
            $nUse = ['clientes' => tariff_customers($num), 'docs' => tariff_docs_count($num)];
            $isDef = docp_int('tariff') === $num;
            $needs = $isDef || $nUse['clientes'] + $nUse['docs'] > 0;
            $to = $act === 'delete_move' ? (int)($_POST['to'] ?? 0) : null;
            if ($needs && $to === null) {
                $targets = [];
                foreach (tariffs_all() as $t) {
                    if ((int)$t['num'] !== $num && (int)$t['is_active'] === 1) {
                        $targets[(string)(int)$t['num']] = (int)$t['num'] . ' · ' . $t['name'];
                    }
                }
                if (count(tariffs_all()) <= 1 || !$targets) {
                    flash(count(tariffs_all()) <= 1 ? 'Siempre tiene que quedar al menos una tarifa.' : 'No hay otra tarifa activa a la que pasarlo: crea o activa una antes.', 'err');
                    redirect('/admin/configuracion/tarifas');
                }
                admin_delete_move('tarifa', $num . ' · ' . $row['name'], pay_usage_text($nUse), $isDef, $targets, '/admin/configuracion/tarifas', (int)$row['id'], 'tarifas');
                return;
            }
            $err = tariff_delete($row, $needs ? $to : null);
            flash($err === '' ? 'Tarifa ' . $num . ' eliminada.' . ($needs ? ' Sus clientes y documentos sin emitir pasan a la tarifa ' . $to . '; las facturas emitidas conservan sus precios.' : '') : $err, $err === '' ? 'ok' : 'err');
            redirect('/admin/configuracion/tarifas');
        }
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
            $taken = array_merge(array_map('intval', array_column($rows, 'num')), array_map('intval', array_keys(tariff_deleted()))); // el número de una tarifa eliminada no se reutiliza: las facturas antiguas lo conservan
            $free = array_values(array_diff(range(1, 9), $taken));
            if (!$free) {
                $errors[] = 'Como mucho 9 tarifas (los números de las eliminadas no se reutilizan).';
            } else {
                $newRow = ['num' => $free[0], 'name' => mb_substr(trim(strip_tags((string)$new['name'])), 0, 40), 'ext' => mb_substr(trim(strip_tags((string)($new['ext'] ?? ''))), 0, 40)];
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
        $sp = strtoupper(trim((string)($_POST['sup_prefix'] ?? 'P')));
        if (!preg_match('/^[A-Z]{1,3}$/', $sp)) {
            $errors[] = 'El prefijo de los proveedores son de 1 a 3 letras (por ejemplo P).';
            $sp = docp('sup_prefix');
        }
        $v['sup_prefix'] = $sp;
        $v['test_text'] = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($_POST['test_text'] ?? '')) ?? ''), 0, 120);
        $testWasOn = docp_bool('test_mode');
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
        // Series (tabla compacta): siguiente número y activa; se reutiliza el plan de la pantalla Series (mismas reglas)
        $year = (int)date('Y');
        $post2 = $_POST;
        foreach (series_all() as $sid => $sr) { // si el número mostrado no se ha tocado, no se envía como cambio
            $yr = (int)$sr['with_year'] === 1 ? $year : 0;
            $cc = series_counters($sid)[$yr] ?? null;
            $shown = $cc === null ? 1 : $cc['last'] + 1;
            if (isset($post2['s'][$sid]['start']) && (string)$post2['s'][$sid]['start'] === (string)$shown) {
                unset($post2['s'][$sid]['start']);
            }
            if (!isset($post2['s'][$sid])) {
                $post2['s'][$sid] = [];
            }
        }
        unset($post2['default'], $post2['yr_year'], $post2['yr_series'], $post2['yr_start']);
        $plan = series_plan($post2, series_ncs_letters(), $year, $errors);
        // Campos de las líneas
        $lfOld = docs_line_fields();
        $lfNew = [];
        $lfIn = (array)($_POST['lf'] ?? []);
        $names = [];
        foreach ($lfOld as $k => $f) {
            $in = (array)($lfIn[$k] ?? []);
            $in['docs'] = array_map('strval', (array)($in['docs'] ?? []));
            if (!$f['builtin']) {
                $in['name'] = trim((string)($in['name'] ?? $f['name']));
                $in['type'] = $in['type'] ?? $f['type'];
                $in['options'] = $in['options'] ?? $f['options'];
            }
            $nf = dlf_normalize($k, $in, (bool)$f['builtin']);
            if (!$f['builtin'] && $in['name'] === '') {
                $errors[] = 'Un campo de las líneas necesita nombre.';
            }
            if ($nf['type'] === 'list' && !$nf['options']) {
                $errors[] = 'El campo «' . $nf['name'] . '» es una lista: escribe sus opciones, separadas por comas.';
            }
            $lfNew[$k] = $nf;
            $names[mb_strtolower($nf['name'])][] = $k;
        }
        $nw = (array)($_POST['lfnew'] ?? []);
        if (trim((string)($nw['name'] ?? '')) !== '') {
            $customN = count(array_filter($lfNew, fn($f) => !$f['builtin']));
            $nums = array_map(fn($k) => (int)substr((string)$k, 1), array_filter(array_keys($lfNew), fn($k) => str_starts_with((string)$k, 'x')));
            $nk = 'x' . ($nums ? max($nums) + 1 : 1);
            $nf = dlf_normalize($nk, ['name' => (string)$nw['name'], 'type' => (string)($nw['type'] ?? 'text'), 'options' => (string)($nw['options'] ?? ''), 'on' => 1, 'form' => 1, 'print' => 1], false);
            if ($customN >= DOC_LINE_MAX_CUSTOM) {
                $errors[] = 'Como mucho ' . DOC_LINE_MAX_CUSTOM . ' campos propios.';
            } elseif ($nf['type'] === 'list' && !$nf['options']) {
                $errors[] = 'El campo «' . $nf['name'] . '» es una lista: escribe sus opciones, separadas por comas.';
            } else {
                $lfNew[$nk] = $nf;
                $names[mb_strtolower($nf['name'])][] = $nk;
            }
        }
        foreach ($names as $nm => $ks) {
            if (count($ks) > 1) {
                $errors[] = 'Hay dos campos de las líneas con el nombre «' . $nm . '».';
            }
        }
        $defaults = [];
        foreach (DOC_SERIES_TYPES as $type => $label) {
            $id = (int)(($_POST['series'] ?? [])[$type] ?? 0);
            if ($id > 0) {
                $s = $series[$id] ?? null;
                if ($s && isset($_POST['s'][$id]) && empty($_POST['s'][$id]['active'])) {
                    $s = null; // se está desactivando en esta misma pantalla
                }
                if (!$s || $s['doc_type'] !== $type) {
                    $errors[] = 'La serie por defecto de «' . $label . '» debe ser una serie activa de ese tipo.';
                } else {
                    $defaults[$type] = $id;
                }
            }
        }
        if (!$errors) {
            $db->beginTransaction();
            series_apply($plan, $year);
            docs_line_fields_save($lfNew);
            foreach ($v as $k => $val) {
                save_setting('docp_' . $k, $val);
            }
            foreach (['ask_discount', 'ask_price', 'ask_tariff', 'ask_title', 'warn_stock_min', 'warn_blocked', 'retail_trade', 'test_mode', 'purch_stock'] as $k) {
                save_setting('docp_' . $k, !empty($_POST[$k]) ? '1' : '0');
            }
            save_setting('erp_retail_customer', mb_substr($retail, 0, 40)); // una sola fuente: el mismo ajuste que usa la sincronización
            foreach ($defaults as $type => $id) {
                $db->prepare('UPDATE doc_series SET is_default = 0 WHERE doc_type = ?')->execute([$type]);
                $db->prepare('UPDATE doc_series SET is_default = 1 WHERE id = ?')->execute([$id]);
            }
            $db->commit();
            if ($testWasOn && empty($_POST['test_mode'])) { // se quita la marca de prueba: si hay facturas de prueba, se ofrece borrarlas
                history_add('configuracion', 'pruebas', 'manual', 'ok', 'Modo de prueba desactivado');
                if (docs_test_count() > 0) {
                    flash('Parámetros guardados. Ya no se marcan los documentos como prueba: las próximas facturas serán reales.');
                    redirect('/admin/facturas/pruebas');
                }
            }
            flash('Parámetros guardados.');
            redirect('/admin/configuracion/parametros');
        }
    }
    $allSeries = series_all();
    $counters = [];
    foreach ($allSeries as $sid => $_) {
        $counters[$sid] = series_counters($sid);
    }
    admin_render('doc_params', ['tariffs' => $tariffs, 'methods' => $methods, 'series' => $series, 'retail' => erp_retail_code(),
        'allSeries' => $allSeries, 'counters' => $counters, 'year' => (int)date('Y'), 'lineFields' => docs_line_fields(),
        'errors' => $errors, 'old' => $old, 'cfg' => 'parametros', 'title' => 'Parámetros de documentos']);
}
