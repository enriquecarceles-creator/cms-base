<?php
declare(strict_types=1);

/*
 * Vencimientos de las facturas (tabla doc_dues) y plazos de las formas de pago.
 *
 *  - Cada forma de pago (doc_pay_methods.terms) lleva sus plazos en días separados por comas: «0» (contado), «30», «30,60,90»…
 *    Cada número es una cuota (máximo 12, de 0 a 730 días, en orden creciente).
 *  - El cliente puede tener «día(s) de pago» (erp_customers.pay_days, p. ej. «5» o «5,25»): cada vencimiento con plazo se pasa al
 *    siguiente de esos días (los de plazo 0, contado, no se mueven). Si el mes no tiene ese día (31 en febrero) vale el último día.
 *  - Las cuotas se reparten a partes iguales y LA ÚLTIMA recoge los céntimos que sobren: la suma es exactamente el total
 *    (100,00 € a 30-60-90 → 33,33 + 33,33 + 33,34).
 *  - Solo las facturas completas (doc_type = factura) tienen vencimientos; pedidos, albaranes, simplificadas y rectificativas, no.
 *  - En borrador se recalculan al guardar si cambia la fecha, la forma de pago, sus plazos, el día de pago del cliente o el total
 *    (doc_headers.dues_key guarda la base del cálculo); si no ha cambiado nada de eso, se respetan los retoques a mano. Al emitir
 *    quedan fijos (docs_issue() los comprueba y, si no estaban calculados, los calcula en la misma transacción).
 */

const DUES_MAX = 12;
const DUES_MAX_DAYS = 730;
const DUES_MAX_PAYDAYS = 4;

/** «30,60,90» → [30, 60, 90]; null si no vale (vacío, no numérico, más de 12, fuera de 0-730 o no creciente). */
function dues_parse_terms(string $s): ?array
{
    $s = trim(str_replace([';', ' '], [',', ''], $s));
    if ($s === '') {
        return null;
    }
    $out = [];
    foreach (explode(',', $s) as $p) {
        if (!ctype_digit($p) || strlen($p) > 3 || (int)$p > DUES_MAX_DAYS) {
            return null;
        }
        $out[] = (int)$p;
    }
    if (count($out) > DUES_MAX) {
        return null;
    }
    for ($i = 1; $i < count($out); $i++) {
        if ($out[$i] < $out[$i - 1]) {
            return null;
        }
    }
    return $out;
}

function dues_terms_text(array $terms): string
{
    return implode(',', array_map('intval', $terms));
}

/** «5» o «5,25» → [5, 25] (días del mes, de 1 a 31, sin repetir, como mucho 4); '' → []; null si no vale. */
function dues_parse_days(string $s): ?array
{
    $s = trim(str_replace([';', ' ', 'y'], [',', '', ','], $s));
    if ($s === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $s) as $p) {
        if ($p === '') {
            continue;
        }
        if (!ctype_digit($p) || (int)$p < 1 || (int)$p > 31) {
            return null;
        }
        $out[(int)$p] = true;
    }
    $days = array_keys($out);
    sort($days);
    return count($days) > DUES_MAX_PAYDAYS ? null : $days;
}

/** aaaammdd + N días. */
function dues_add_days(int $day, int $n): int
{
    $d = DateTimeImmutable::createFromFormat('!Ymd', sprintf('%08d', $day));
    return $d === false ? $day : (int)$d->modify('+' . $n . ' days')->format('Ymd');
}

/** Primer día de pago (de la lista) igual o posterior a la fecha. Si el mes no tiene ese día, el último del mes. */
function dues_next_pay_day(int $day, array $payDays): int
{
    $d = DateTimeImmutable::createFromFormat('!Ymd', sprintf('%08d', $day));
    if ($d === false || !$payDays) {
        return $day;
    }
    $best = null;
    for ($m = 0; $m < 3; $m++) {
        $first = $d->modify('first day of this month')->modify('+' . $m . ' months');
        $last = (int)$first->format('t');
        foreach ($payDays as $pd) {
            $cand = (int)$first->format('Ym') * 100 + min((int)$pd, $last);
            if ($cand >= $day && ($best === null || $cand < $best)) {
                $best = $cand;
            }
        }
        if ($best !== null) {
            break;
        }
    }
    return $best ?? $day;
}

/**
 * Reparto de un total en cuotas: [['day' => aaaammdd, 'cents' => n], …]. Vacío si no hay total positivo ni plazos.
 * Las cuotas son iguales (división entera) y la última lleva lo que sobre: la suma es exactamente $totalCents.
 */
function dues_schedule(int $totalCents, int $docDay, array $terms, array $payDays = []): array
{
    $n = count($terms);
    if ($totalCents <= 0 || $n === 0 || $docDay <= 0) {
        return [];
    }
    if ($totalCents < $n) { // un total de unos céntimos no se puede partir en tantas cuotas: una sola, al último plazo
        $terms = [(int)end($terms)];
        $n = 1;
    }
    $base = intdiv($totalCents, $n);
    $rows = [];
    foreach (array_values($terms) as $i => $t) {
        $day = dues_add_days($docDay, (int)$t);
        if ($payDays && (int)$t > 0) { // contado (0 días) no se mueve
            $day = dues_next_pay_day($day, $payDays);
        }
        $rows[] = ['day' => $day, 'cents' => $i === $n - 1 ? $totalCents - $base * ($n - 1) : $base];
    }
    return $rows;
}

function dues_sum(array $rows): int
{
    return (int)array_sum(array_map(fn($r) => (int)$r['cents'], $rows));
}

/** Plazos de una forma de pago por su código; sin forma de pago, ninguno; un código que no está en la tabla cuenta como contado. */
function dues_method_terms(string $code): array
{
    $code = trim($code);
    if ($code === '') {
        return [];
    }
    $st = db()->prepare('SELECT terms FROM doc_pay_methods WHERE code = ?');
    $st->execute([$code]);
    $t = $st->fetchColumn();
    return $t === false ? [0] : (dues_parse_terms((string)$t) ?? [0]);
}

/** Día(s) de pago de un cliente (por su código). */
function dues_customer_days(string $custCode, bool $supplier = false): array
{
    if (trim($custCode) === '') {
        return [];
    }
    $st = db()->prepare($supplier ? 'SELECT pay_days FROM erp_suppliers WHERE sup_code = ?' : 'SELECT pay_days FROM erp_customers WHERE cust_code = ?');
    $st->execute([$custCode]);
    return dues_parse_days((string)$st->fetchColumn()) ?? [];
}

/** ¿Lleva vencimientos este documento? Solo las facturas completas. */
function dues_applies(array $doc): bool
{
    return in_array((string)($doc['doc_type'] ?? ''), ['factura', 'factura_c'], true);
}

/** Calcula los vencimientos de una factura y la clave de la base del cálculo: ['rows' => [...], 'key' => md5]. */
function dues_compute(array $doc): array
{
    $terms = dues_method_terms((string)$doc['pay_code']);
    $days = dues_customer_days((string)$doc['cust_code'], docs_is_purchase((string)($doc['doc_type'] ?? '')));
    $key = md5(implode('|', [(int)$doc['doc_day'], (string)$doc['pay_code'], dues_terms_text($terms), dues_terms_text($days), (int)$doc['total_cents']]));
    return ['rows' => dues_schedule((int)$doc['total_cents'], (int)$doc['doc_day'], $terms, $days), 'key' => $key];
}

/** Vencimientos guardados de una factura: [['day', 'cents', 'paid', 'paid_day'], …] por orden. */
function docs_dues(int $docId): array
{
    $st = db()->prepare('SELECT due_day, amount_cents, paid, paid_day FROM doc_dues WHERE doc_id = ? ORDER BY due_no, id');
    $st->execute([$docId]);
    return array_map(fn($r) => ['day' => (int)$r['due_day'], 'cents' => (int)$r['amount_cents'], 'paid' => (int)$r['paid'], 'paid_day' => (int)$r['paid_day']], $st->fetchAll());
}

/** Sustituye los vencimientos de una factura (dentro de la transacción de quien llama). */
function dues_store(int $docId, array $rows): void
{
    $db = db();
    $db->prepare('DELETE FROM doc_dues WHERE doc_id = ?')->execute([$docId]);
    $ins = $db->prepare('INSERT INTO doc_dues (doc_id, due_no, due_day, amount_cents, paid, paid_day) VALUES (?, ?, ?, ?, 0, 0)');
    foreach (array_values($rows) as $i => $r) {
        $ins->execute([$docId, $i + 1, (int)$r['day'], (int)$r['cents']]);
    }
}

/**
 * Vencimientos enviados desde el formulario (due_day[] con fecha aaaa-mm-dd y due_amt[] con el importe). null si el formulario no
 * los trae. Las filas vacías se ignoran. ['rows' => [...], 'errors' => [...]].
 */
function dues_from_post(array $post): ?array
{
    if (!isset($post['due_day']) || !is_array($post['due_day'])) {
        return null;
    }
    $amts = is_array($post['due_amt'] ?? null) ? $post['due_amt'] : [];
    $rows = [];
    $errors = [];
    foreach ($post['due_day'] as $i => $d) {
        $d = trim((string)$d);
        $a = trim((string)($amts[$i] ?? ''));
        if ($d === '' && $a === '') {
            continue;
        }
        $day = erp_day_from_input($d);
        $num = docs_parse_num($a);
        if ($day === 0) {
            $errors[] = 'Vencimiento ' . (count($rows) + 1) . ': la fecha no es válida.';
        }
        if ($num === null || $num <= 0) {
            $errors[] = 'Vencimiento ' . (count($rows) + 1) . ': el importe tiene que ser un número mayor que 0.';
        }
        $rows[] = ['day' => $day, 'cents' => $num === null ? 0 : (int)round($num * 100)];
    }
    if (count($rows) > DUES_MAX) {
        $errors[] = 'Como mucho ' . DUES_MAX . ' vencimientos.';
    }
    usort($rows, fn($a, $b) => $a['day'] <=> $b['day']);
    return ['rows' => $rows, 'errors' => array_values(array_unique($errors))];
}

/**
 * Aplica los vencimientos a un documento al recibir el formulario (docs_from_post): recalcula si cambió la base del cálculo
 * (fecha, forma de pago, plazos, día de pago del cliente o total) y, si no, respeta lo que el usuario retocó.
 */
function docs_dues_apply(array &$doc, array $saved, array $post, bool $isNew, array &$errors, array &$warn): void
{
    $calc = dues_compute($doc);
    $posted = dues_from_post($post);
    $same = !$isNew && (string)($saved['dues_key'] ?? '') === $calc['key'];
    if ($posted !== null && $same) {
        $doc['dues'] = $posted['rows'];
        $errors = array_merge($errors, $posted['errors']);
        if ($doc['dues'] && (int)$doc['total_cents'] > 0 && dues_sum($doc['dues']) !== (int)$doc['total_cents']) {
            $warn[] = 'Los vencimientos suman ' . erp_money(dues_sum($doc['dues'])) . ' y el total es ' . erp_money((int)$doc['total_cents']) . ': corrígelos antes de emitir.';
        }
    } else {
        $doc['dues'] = $calc['rows'];
        if ($posted !== null && !$isNew && $posted['rows'] && $posted['rows'] !== $calc['rows']) {
            $warn[] = 'Los vencimientos se han recalculado porque ha cambiado la fecha, la forma de pago o el total.';
        }
    }
    $doc['dues_key'] = $calc['key'];
}

/** Guarda los vencimientos de una factura en borrador (si el documento no los trae, los calcula). Llamar con el id ya conocido. */
function docs_dues_save(array $doc, int $id): void
{
    if (!dues_applies($doc)) {
        return;
    }
    if (!isset($doc['dues'])) {
        $c = dues_compute($doc);
        $doc['dues'] = $c['rows'];
        $doc['dues_key'] = $c['key'];
    }
    dues_store($id, $doc['dues']);
    db()->prepare('UPDATE doc_headers SET dues_key = ? WHERE id = ?')->execute([(string)($doc['dues_key'] ?? ''), $id]);
}

/** Problemas con los vencimientos al emitir (vacío = bien): los guardados deben sumar exactamente el total. */
function dues_issue_problems(array $doc): array
{
    if (!dues_applies($doc) || (int)$doc['total_cents'] <= 0 || (int)($doc['id'] ?? 0) <= 0) {
        return [];
    }
    $rows = docs_dues((int)$doc['id']);
    $calc = dues_compute($doc);
    if ($rows && (string)($doc['dues_key'] ?? '') === $calc['key'] && dues_sum($rows) !== (int)$doc['total_cents']) {
        return ['Los vencimientos suman ' . erp_money(dues_sum($rows)) . ' y el total de la factura es ' . erp_money((int)$doc['total_cents']) . ': corrígelos en «Vencimientos» antes de emitir.'];
    }
    return [];
}

/** Al emitir: si los vencimientos no estaban calculados (o su base ha cambiado), se calculan ahora; desde aquí quedan fijos. */
function dues_finalize(array $doc): void
{
    if (!dues_applies($doc) || (int)$doc['total_cents'] <= 0) {
        return;
    }
    $rows = docs_dues((int)$doc['id']);
    $calc = dues_compute($doc);
    if (!$rows || (string)($doc['dues_key'] ?? '') !== $calc['key']) {
        dues_store((int)$doc['id'], $calc['rows']);
        db()->prepare('UPDATE doc_headers SET dues_key = ? WHERE id = ?')->execute([$calc['key'], (int)$doc['id']]);
    }
}
