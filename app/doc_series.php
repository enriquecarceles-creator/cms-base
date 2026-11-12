<?php
declare(strict_types=1);

/*
 * Series de documentos (Configuración → Series): tipo (factura, rectificativa, albarán, pedido), letra (1-3 letras A-Z), descripción,
 * dígitos (3-8, 5 por defecto), con año sí/no (formato /AA), por defecto y activa. Ejemplo: F00001/26.
 *
 * NUMERACIÓN: correlativa por serie y año (tabla doc_series_counters). series_next_number() debe llamarse dentro de la
 * transacción del propio documento: el contador se incrementa con un UPDATE ... SET last_no = last_no + 1 (bloquea la fila) y se
 * lee después; si la transacción se deshace, el contador también, así que no hay huecos, y dos usuarios a la vez no pueden sacar
 * el mismo número. Es SQL estándar: vale igual en SQLite, MariaDB y SQL Server.
 *
 * REGLAS (facturas y rectificativas, y por coherencia todas): el siguiente número no puede bajar por debajo del último usado; sin
 * huecos (solo se puede fijar el primer número de un año mientras no se haya usado ninguno); una serie usada no se borra, solo se
 * desactiva, y su letra, dígitos y uso del año quedan bloqueados (cambiarlos alteraría números ya emitidos); el tipo no cambia;
 * la letra es única entre todas las series; las rectificativas tienen series propias. Se avisa si la letra coincide con la de las
 * facturas importadas de NCS, y se BLOQUEA si además la serie no lleva año (podría repetir un número importado).
 * Nada de esto es específico de NCS: las letras importadas se leen de erp_invoices.
 */

const DOC_SERIES_TYPES = ['factura' => 'Factura', 'rectificativa' => 'Factura rectificativa', 'simplificada' => 'Factura simplificada (tique)', 'albaran' => 'Albarán', 'pedido' => 'Pedido',
    'pedido_c' => 'Pedido a proveedor', 'albaran_c' => 'Albarán de compra', 'factura_c' => 'Factura recibida (registro)', 'rectificativa_c' => 'Rectificativa recibida'];
/** Tipos que son facturas: sus series no pueden usar una letra de las facturas importadas (conviven con NCS sin mezclarse). */
const DOC_INVOICE_TYPES = ['factura', 'rectificativa', 'simplificada'];
const DOC_SERIES_TYPES_PL = ['factura' => 'Facturas', 'rectificativa' => 'Facturas rectificativas', 'simplificada' => 'Facturas simplificadas (tiques)', 'albaran' => 'Albaranes', 'pedido' => 'Pedidos',
    'pedido_c' => 'Pedidos a proveedor', 'albaran_c' => 'Albaranes de compra', 'factura_c' => 'Facturas recibidas (registro)', 'rectificativa_c' => 'Rectificativas recibidas'];
const SERIES_DIGITS_MIN = 3;
const SERIES_DIGITS_MAX = 8;

/** Todas las series, por orden de tipo y letra: [id => fila con 'used' (bool)]. */
function series_all(): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM doc_series ORDER BY id')->fetchAll() as $r) {
        $r['id'] = (int)$r['id'];
        $out[$r['id']] = $r;
    }
    $used = [];
    foreach (db()->query('SELECT DISTINCT series_id FROM doc_series_counters WHERE last_no >= start_no')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $used[(int)$id] = true;
    }
    foreach ($out as $id => &$r) {
        $r['used'] = isset($used[$id]);
    }
    unset($r);
    uasort($out, fn($a, $b) => array_search($a['doc_type'], array_keys(DOC_SERIES_TYPES), true) <=> array_search($b['doc_type'], array_keys(DOC_SERIES_TYPES), true) ?: strcmp($a['letter'], $b['letter']));
    return $out;
}

function series_get(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM doc_series WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** ¿Se ha usado algún número de esta serie? (Cuando existan los documentos, aquí se comprobarán también.) */
function series_is_used(int $id): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM doc_series_counters WHERE series_id = ? AND last_no >= start_no');
    $st->execute([$id]);
    return (int)$st->fetchColumn() > 0;
}

/** Número con su formato: letra + número con ceros + «/AA» si la serie lleva año. */
function series_format(array $s, int $number, int $year): string
{
    return (string)$s['letter'] . str_pad((string)$number, (int)$s['digits'], '0', STR_PAD_LEFT) . ((int)$s['with_year'] === 1 ? '/' . substr((string)$year, -2) : '');
}

/** Contadores de una serie: [yr => ['start' => int, 'last' => int]]. */
function series_counters(int $id): array
{
    $st = db()->prepare('SELECT yr, start_no, last_no FROM doc_series_counters WHERE series_id = ? ORDER BY yr');
    $st->execute([$id]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int)$r['yr']] = ['start' => (int)$r['start_no'], 'last' => (int)$r['last_no']];
    }
    return $out;
}

/**
 * Saca el siguiente número de la serie para ese año. Llamar dentro de la transacción del documento; si no hay ninguna abierta,
 * abre y cierra la suya. Devuelve ['number' => int, 'formatted' => 'F00001/26'].
 */
function series_next_number(int $seriesId, int $year): array
{
    $db = db();
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $s = series_get($seriesId);
        if (!$s) {
            throw new RuntimeException('La serie no existe.');
        }
        if ((int)$s['is_active'] !== 1) {
            throw new RuntimeException('La serie «' . $s['letter'] . '» está desactivada.');
        }
        $yr = (int)$s['with_year'] === 1 ? $year : 0;
        $up = $db->prepare('UPDATE doc_series_counters SET last_no = last_no + 1 WHERE series_id = ? AND yr = ?');
        $up->execute([$seriesId, $yr]);
        if ($up->rowCount() === 0) {
            try {
                $db->prepare('INSERT INTO doc_series_counters (series_id, yr, start_no, last_no) VALUES (?, ?, 1, 1)')->execute([$seriesId, $yr]);
            } catch (PDOException $e) { // otro proceso la creó a la vez: se incrementa la suya
                $up->execute([$seriesId, $yr]);
                if ($up->rowCount() === 0) {
                    throw $e;
                }
            }
        }
        $st = $db->prepare('SELECT last_no FROM doc_series_counters WHERE series_id = ? AND yr = ?');
        $st->execute([$seriesId, $yr]);
        $n = (int)$st->fetchColumn();
        if ($own) {
            $db->commit();
        }
        return ['number' => $n, 'formatted' => series_format($s, $n, $year)];
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/** Letras (prefijos alfabéticos) de las facturas importadas, p. ej. ['V' => true]. Se guarda 10 minutos para no leer todas las facturas cada vez. */
function series_ncs_letters(): array
{
    $c = json_decode(db_setting('series_ncs_letters'), true);
    if (is_array($c) && isset($c['at'], $c['letters']) && (int)$c['at'] > time() - 600) {
        return array_fill_keys((array)$c['letters'], true);
    }
    $letters = [];
    try {
        foreach (db()->query("SELECT inv_number FROM erp_invoices WHERE origin = 'ncs'")->fetchAll(PDO::FETCH_COLUMN) as $n) {
            if (preg_match('/^[A-Za-z]+/', (string)$n, $m)) {
                $letters[strtoupper($m[0])] = true;
            }
        }
    } catch (Throwable $e) {
        $letters = [];
    }
    save_setting('series_ncs_letters', (string)json_encode(['at' => time(), 'letters' => array_keys($letters)]));
    return $letters;
}

/** Normaliza una letra de serie (mayúsculas, sin espacios). */
function series_clean_letter(string $s): string
{
    return strtoupper(trim($s));
}

/** Mayor número que cabe con esos dígitos. */
function series_max_number(int $digits): int
{
    return (int)min(99999999, 10 ** $digits - 1);
}

/** Normaliza el valor por defecto de cada tipo: exactamente una serie por defecto entre las activas (ninguna si no hay activas). */
function series_fix_defaults(?int $prefer = null): void
{
    $db = db();
    $byType = [];
    foreach (db()->query('SELECT id, doc_type, is_default, is_active FROM doc_series ORDER BY id')->fetchAll() as $r) {
        $byType[$r['doc_type']][] = $r;
    }
    foreach ($byType as $rows) {
        $active = array_values(array_filter($rows, fn($r) => (int)$r['is_active'] === 1));
        $pick = null;
        foreach ($active as $r) {
            if ($prefer !== null && (int)$r['id'] === $prefer) {
                $pick = (int)$r['id'];
            }
        }
        if ($pick === null) {
            foreach ($active as $r) {
                if ((int)$r['is_default'] === 1) {
                    $pick = (int)$r['id'];
                    break;
                }
            }
        }
        if ($pick === null && $active) {
            $pick = (int)$active[0]['id'];
        }
        foreach ($rows as $r) {
            $want = (int)$r['id'] === $pick ? 1 : 0;
            if ((int)$r['is_default'] !== $want) {
                $db->prepare('UPDATE doc_series SET is_default = ? WHERE id = ?')->execute([$want, (int)$r['id']]);
            }
        }
    }
}

/** Pantalla Configuración → Series. */
function admin_series(): void
{
    $db = db();
    $year = (int)date('Y');
    $ncs = series_ncs_letters();
    $errors = [];
    $old = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? 'save');
        if ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $s = series_get($id);
            if (!$s) {
                flash('Esa serie ya no existe.', 'err');
            } elseif (series_is_used($id)) {
                flash('La serie «' . $s['letter'] . '» ya se ha usado: no se puede borrar, solo desactivarla.', 'err');
            } else {
                $db->beginTransaction();
                $db->prepare('DELETE FROM doc_series_counters WHERE series_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM doc_series WHERE id = ?')->execute([$id]);
                series_fix_defaults();
                $db->commit();
                flash('Serie «' . $s['letter'] . '» borrada.');
            }
            redirect('/admin/configuracion/series');
        }
        $old = $_POST;
        $plan = series_plan($_POST, $ncs, $year, $errors);
        if (!$errors) {
            $db->beginTransaction();
            try {
                series_apply($plan, $year);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e;
            }
            flash('Series guardadas.');
            redirect('/admin/configuracion/series');
        }
    }
    $rows = series_all();
    $counters = [];
    foreach ($rows as $id => $r) {
        $counters[$id] = series_counters($id);
    }
    admin_render('series', [
        'rows' => $rows, 'counters' => $counters, 'year' => $year, 'ncs' => $ncs, 'errors' => $errors, 'old' => $old,
        'cfg' => 'series', 'title' => 'Series',
    ]);
}

/**
 * Valida lo enviado y lo convierte en un plan de cambios. No toca la base de datos: si hay errores, no se aplica nada.
 * @return array{edit: array, new: ?array, years: ?array, default: array}
 */
function series_plan(array $post, array $ncs, int $year, array &$errors): array
{
    $rows = series_all();
    $plan = ['edit' => [], 'new' => null, 'years' => null, 'default' => array_map('intval', (array)($post['default'] ?? []))];
    $letters = [];
    foreach ($rows as $id => $r) {
        $in = (array)(($post['s'] ?? [])[$id] ?? []);
        $used = $r['used'];
        $e = ['id' => $id, 'used' => $used];
        $descr = trim(mb_substr(trim(strip_tags((string)($in['descr'] ?? $r['descr']))), 0, 60));
        $e['descr'] = $descr !== '' ? $descr : DOC_SERIES_TYPES_PL[$r['doc_type']];
        $e['active'] = !empty($in['active']) ? 1 : 0;
        $e['letter'] = $r['letter'];
        $e['digits'] = (int)$r['digits'];
        $e['with_year'] = (int)$r['with_year'];
        if (!$used) { // una serie usada no cambia de letra, dígitos ni año: alteraría los números ya emitidos
            if (array_key_exists('letter', $in)) {
                $e['letter'] = series_clean_letter((string)$in['letter']);
                $e['digits'] = (int)($in['digits'] ?? $r['digits']);
                $e['with_year'] = !empty($in['with_year']) ? 1 : 0;
            }
            if (!preg_match('/^[A-Z]{1,3}$/', $e['letter'])) {
                $errors[] = 'La letra de la serie debe tener de 1 a 3 letras (A-Z), sin números ni espacios.';
            }
            if ($e['digits'] < SERIES_DIGITS_MIN || $e['digits'] > SERIES_DIGITS_MAX) {
                $errors[] = 'Los dígitos de la serie «' . $e['letter'] . '» deben estar entre ' . SERIES_DIGITS_MIN . ' y ' . SERIES_DIGITS_MAX . '.';
            }
        }
        $e['start'] = null;
        $start = trim((string)($in['start'] ?? ''));
        if ($start !== '') {
            $cur = series_counters($id)[(int)$r['with_year'] === 1 ? $year : 0] ?? null;
            $curUsed = $cur !== null && $cur['last'] >= $cur['start'];
            $n = (int)$start;
            if (!ctype_digit($start) || $n < 1 || $n > series_max_number($e['digits'])) {
                $errors[] = 'El siguiente número de la serie «' . $e['letter'] . '» debe estar entre 1 y ' . series_max_number($e['digits']) . '.';
            } elseif ($curUsed) {
                if ($n !== $cur['last'] + 1) { // ya hay números usados este año: no puede bajar (ni dejar huecos)
                    $errors[] = 'La serie «' . $e['letter'] . '» ya tiene números usados este año: el siguiente es ' . ($cur['last'] + 1) . ' y no se puede cambiar (no puede bajar del último usado ni dejar huecos).';
                }
            } elseif ($cur === null || $n !== $cur['start']) {
                $e['start'] = $n;
            }
        }
        $letters[$e['letter']][] = $id;
        $plan['edit'][$id] = $e;
    }
    $n = (array)($post['new'] ?? []);
    if (series_clean_letter((string)($n['letter'] ?? '')) !== '' || trim((string)($n['descr'] ?? '')) !== '') {
        $new = [
            'doc_type' => (string)($n['type'] ?? ''), 'letter' => series_clean_letter((string)($n['letter'] ?? '')),
            'descr' => trim(mb_substr(trim(strip_tags((string)($n['descr'] ?? ''))), 0, 60)),
            'digits' => (int)($n['digits'] ?? 5), 'with_year' => !empty($n['with_year']) ? 1 : 0, 'start' => trim((string)($n['start'] ?? '')),
        ];
        if (!isset(DOC_SERIES_TYPES[$new['doc_type']])) {
            $errors[] = 'Nueva serie: elige el tipo de documento.';
        }
        if (!preg_match('/^[A-Z]{1,3}$/', $new['letter'])) {
            $errors[] = 'Nueva serie: la letra debe tener de 1 a 3 letras (A-Z), sin números ni espacios.';
        }
        if ($new['digits'] < SERIES_DIGITS_MIN || $new['digits'] > SERIES_DIGITS_MAX) {
            $errors[] = 'Nueva serie: los dígitos deben estar entre ' . SERIES_DIGITS_MIN . ' y ' . SERIES_DIGITS_MAX . '.';
        }
        if ($new['start'] !== '' && (!ctype_digit($new['start']) || (int)$new['start'] < 1 || (int)$new['start'] > series_max_number($new['digits']))) {
            $errors[] = 'Nueva serie: el primer número debe estar entre 1 y ' . series_max_number($new['digits']) . '.';
        }
        if ($new['descr'] === '' && isset(DOC_SERIES_TYPES_PL[$new['doc_type']])) {
            $new['descr'] = DOC_SERIES_TYPES_PL[$new['doc_type']];
        }
        $letters[$new['letter']][] = 0;
        $plan['new'] = $new;
    }
    foreach ($letters as $letter => $ids) {
        if ($letter !== '' && count($ids) > 1) {
            $errors[] = 'La letra «' . $letter . '» está repetida: cada serie necesita una letra distinta.';
        }
    }
    $all = array_map(fn($e) => ['letter' => $e['letter'], 'with_year' => $e['with_year'], 'type' => $rows[$e['id']]['doc_type']], $plan['edit']);
    if ($plan['new']) {
        $all[] = ['letter' => $plan['new']['letter'], 'with_year' => $plan['new']['with_year'], 'type' => $plan['new']['doc_type']];
    }
    foreach ($all as $s) { // una serie sin año con la letra de las facturas importadas podría repetir un número ya existente
        if (in_array($s['type'], DOC_INVOICE_TYPES, true) && isset($ncs[$s['letter']])) { // facturas propias y de NCS conviven sin mezclarse
            $errors[] = 'La letra «' . $s['letter'] . '» ya la usan facturas importadas: una serie de facturas del CMS necesita otra letra para no mezclarse con ellas.';
        } elseif ($s['with_year'] === 0 && isset($ncs[$s['letter']])) {
            $errors[] = 'La letra «' . $s['letter'] . '» ya la usan facturas importadas y la serie no lleva año: podría repetir un número existente. Marca «con año» o usa otra letra.';
        }
    }
    $y = trim((string)($post['yr_year'] ?? ''));
    $ys = (int)($post['yr_series'] ?? 0);
    $yn = trim((string)($post['yr_start'] ?? ''));
    if ($yn !== '' || $y !== '') {
        $s = $rows[$ys] ?? null;
        if (!$s) {
            $errors[] = 'Primer número de otro año: elige la serie.';
        } elseif ((int)$s['with_year'] === 0 && !($plan['edit'][$ys]['with_year'] ?? 0)) {
            $errors[] = 'La serie «' . $s['letter'] . '» no lleva año: solo tiene un contador.';
        } elseif (!ctype_digit($y) || (int)$y < 2000 || (int)$y > 2100) {
            $errors[] = 'Primer número de otro año: escribe un año entre 2000 y 2100.';
        } elseif (!ctype_digit($yn) || (int)$yn < 1 || (int)$yn > series_max_number($plan['edit'][$ys]['digits'])) {
            $errors[] = 'Primer número de otro año: escribe un número válido.';
        } else {
            $cur = series_counters($ys)[(int)$y] ?? null;
            if ($cur !== null && $cur['last'] >= $cur['start']) {
                $errors[] = 'La serie «' . $s['letter'] . '» ya tiene números usados en ' . (int)$y . ': el primer número no se puede cambiar.';
            } else {
                $plan['years'] = ['id' => $ys, 'yr' => (int)$y, 'start' => (int)$yn];
            }
        }
    }
    return $plan;
}

/** Aplica un plan ya validado (dentro de una transacción abierta por quien llama). */
function series_apply(array $plan, int $year): void
{
    $db = db();
    foreach ($plan['edit'] as $id => $e) {
        if ($e['used']) {
            $db->prepare('UPDATE doc_series SET descr = ?, is_active = ? WHERE id = ?')->execute([$e['descr'], $e['active'], $id]);
        } else {
            $db->prepare('UPDATE doc_series SET letter = ?, descr = ?, digits = ?, with_year = ?, is_active = ? WHERE id = ?')
                ->execute([$e['letter'], $e['descr'], $e['digits'], $e['with_year'], $e['active'], $id]);
        }
        if ($e['start'] !== null) {
            series_set_start($id, $e['with_year'] === 1 ? $year : 0, $e['start']);
        }
    }
    $prefer = null;
    if ($plan['new']) {
        $n = $plan['new'];
        $db->prepare('INSERT INTO doc_series (doc_type, letter, descr, digits, with_year, is_default, is_active, created_at) VALUES (?, ?, ?, ?, ?, 0, 1, ?)')
            ->execute([$n['doc_type'], $n['letter'], $n['descr'], $n['digits'], $n['with_year'], time()]);
        $st = $db->prepare('SELECT id FROM doc_series WHERE letter = ?');
        $st->execute([$n['letter']]);
        $newId = (int)$st->fetchColumn();
        if ($n['start'] !== '' && (int)$n['start'] > 1) {
            series_set_start($newId, $n['with_year'] === 1 ? $year : 0, (int)$n['start']);
        }
    }
    if ($plan['years']) {
        series_set_start($plan['years']['id'], $plan['years']['yr'], $plan['years']['start']);
    }
    // Por defecto: lo elegido para cada tipo, si sigue siendo una serie activa de ese tipo
    $chosen = array_filter($plan['default']);
    foreach (series_all() as $id => $r) {
        if (isset($chosen[$r['doc_type']]) && $chosen[$r['doc_type']] === $id && (int)$r['is_active'] === 1) {
            $db->prepare('UPDATE doc_series SET is_default = 0 WHERE doc_type = ? AND id <> ?')->execute([$r['doc_type'], $id]);
            $db->prepare('UPDATE doc_series SET is_default = 1 WHERE id = ?')->execute([$id]);
        }
    }
    series_fix_defaults();
}

/** Fija el primer número de un año (solo si ese año no tiene números usados; lo comprueba quien llama). */
function series_set_start(int $id, int $yr, int $start): void
{
    $db = db();
    $up = $db->prepare('UPDATE doc_series_counters SET start_no = ?, last_no = ? WHERE series_id = ? AND yr = ?');
    $up->execute([$start, $start - 1, $id, $yr]);
    if ($up->rowCount() === 0) {
        $st = $db->prepare('SELECT COUNT(*) FROM doc_series_counters WHERE series_id = ? AND yr = ?');
        $st->execute([$id, $yr]);
        if ((int)$st->fetchColumn() === 0) {
            $db->prepare('INSERT INTO doc_series_counters (series_id, yr, start_no, last_no) VALUES (?, ?, ?, ?)')->execute([$id, $yr, $start, $start - 1]);
        }
    }
}
