<?php
declare(strict_types=1);

/*
 * ERP de solo consulta (clientes y ventas de NCS Facturación). NCS sigue siendo el programa que factura: aquí solo se
 * SINCRONIZAN FCUENTAS.MDB (clientes) y FHISTORI.MDB (facturas y líneas) y se consultan en el panel.
 *
 * Reglas de privacidad:
 *  - Solo roles «erp» y «admin»; con la doble verificación exigida si el ajuste erp_require_2fa no está a «0».
 *  - No se importan datos bancarios (cuenta, banco, BIC, mandato, tarjeta), notas libres ni saldos internos de NCS.
 *  - Tablas erp_* fuera de DB_TABLES: no entran en las copias de seguridad salvo que se marque «Incluir clientes y ventas».
 *  - Nada de datos personales en logs: las consultas son preparadas y los mensajes de error no llevan valores.
 *  - El archivo de trabajo (data/erp_stage.sqlite) y los .mdb de data/importar/ se borran al terminar.
 */

require_once __DIR__ . '/mdb.php';
require_once __DIR__ . '/erp_client.php';
require_once __DIR__ . '/erp_customer_edit.php';
require_once __DIR__ . '/documents.php';
require_once __DIR__ . '/erp_invoice.php';
require_once __DIR__ . '/erp_invoice_send.php';
require_once __DIR__ . '/erp_sales.php';

const ERP_STAGE = DATA_DIR . '/erp_stage.sqlite';
const ERP_JOB = DATA_DIR . '/erp_job.json';
const ERP_LOCK = DATA_DIR . '/erp_job.lock';
const ERP_FTP_DIR = DATA_DIR . '/importar';
const ERP_PER_PAGE = 50;
const ERP_TANDA_SECS = 6;     // segundos de trabajo en cada petición de la sincronización
const ERP_INV_BATCH = 200;    // facturas por transacción
const ERP_RETAIL_MIN = 1000;  // un cliente sin NIF con más facturas que esto se propone como «cliente de contado»

/* ---------- Formato ---------- */

function erp_money(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

/** aaaammdd → dd/mm/aaaa */
function erp_day(int $d): string
{
    return $d > 0 ? sprintf('%02d/%02d/%04d', $d % 100, intdiv($d, 100) % 100, intdiv($d, 10000)) : '—';
}

function erp_day_from(mixed $s): int
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)$s, $m) ? (int)($m[1] . $m[2] . $m[3]) : 0;
}

function erp_cents(mixed $v): int
{
    return (int)round((float)$v * 100);
}

function erp_txt(mixed $v, int $max = 255): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', trim((string)$v)) ?? '';
    return mb_substr(trim($s), 0, $max);
}

function erp_qty(int $milli): string
{
    return $milli % 1000 === 0 ? (string)intdiv($milli, 1000) : rtrim(rtrim(number_format($milli / 1000, 3, ',', '.'), '0'), ',');
}

/* ---------- Acceso: doble verificación exigida ---------- */

/** ¿Se exige doble verificación para Clientes y Ventas? Activada salvo que el ajuste valga «0» (instalaciones nuevas incluidas). */
function erp_2fa_required(): bool
{
    return setting('erp_require_2fa', '1') !== '0'; // ojo: db_setting() devuelve '' para '0'
}

/** Si se exige y el usuario no la tiene activada, enseña el aviso y devuelve false (la pantalla no debe seguir). */
function erp_guard(): bool
{
    if (!erp_2fa_required()) {
        return true;
    }
    $st = db()->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $st->execute([(int)($_SESSION['uid'] ?? 0)]);
    if ((int)$st->fetchColumn() === 1) {
        return true;
    }
    admin_render('erp_need_2fa', ['title' => 'Clientes y Ventas']);
    return false;
}

/** Avisa por correo a los administradores de que se ha desactivado la doble verificación de Clientes y Ventas. */
function erp_notify_2fa_off(string $who, string $scope = 'Clientes y Ventas'): void
{
    try {
        $body = "Se ha desactivado la doble verificación exigida para " . $scope . " en el panel de " . site_name() . ".\r\n\r\n"
            . "  Quién:   " . $who . "\r\n"
            . "  Cuándo:  " . date('d/m/Y H:i') . ' (' . date_default_timezone_get() . ")\r\n"
            . "  Desde:   " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\r\n\r\n"
            . "Mientras esté desactivada, quien tenga el rol ERP o sea administrador puede ver los datos de clientes y ventas solo con su contraseña.\r\n"
            . "Si no lo esperabas, vuelve a activarla en Configuración → Usuarios y seguridad (" . site_origin() . url('/admin/configuracion/usuarios') . "#erp) y revisa los usuarios.\r\n";
        foreach (mail_notify_recipients() as $to) { // los de Web → Correo → Avisos del sistema o, por defecto, los administradores
            app_mail($to, 'Doble verificación desactivada para ' . $scope . ' · ' . site_name(), $body);
        }
    } catch (Throwable $e) {
        error_log('Aviso 2FA ERP: ' . get_class($e)); // nunca impide cambiar el ajuste
    }
}

function erp_retail_code(): string
{
    return setting('erp_retail_customer');
}

/* ---------- Correspondencia NCS → tablas erp_* ---------- */

function erp_map_customer(array $r): array
{
    return [
        'cust_code' => erp_txt($r['CODIGO'] ?? '', 191),
        'name' => erp_txt($r['NOMBRE'] ?? ''),
        'trade_name' => erp_txt($r['NCOMERCIAL'] ?? ''),
        'nif' => erp_txt($r['NIF'] ?? '', 40),
        'address' => erp_txt($r['DOMICILIO'] ?? ''),
        'city' => erp_txt($r['POBLACION'] ?? ''),
        'postcode' => erp_txt($r['CODPOSTAL'] ?? '', 20),
        'province' => erp_txt($r['PROVINCIA'] ?? ''),
        'country' => erp_txt($r['PAIS'] ?? ''),
        'phone' => erp_txt($r['TELEFONO1'] ?? '', 40),
        'phone2' => erp_txt($r['TELEFONO2'] ?? '', 40),
        'email' => erp_txt($r['EMAIL'] ?? ''),
        'contact' => erp_txt($r['PCONTACTO'] ?? ''),
        'pay_code' => erp_txt($r['FPAGO'] ?? '', 20),
        'tariff' => (int)($r['TARIFA'] ?? 0),
        'disc_bp' => (int)round((float)($r['DCTO'] ?? 0) * 100),
        'blocked' => (int)($r['BLOQUEADA'] ?? 0) !== 0 ? 1 : 0,
        'created_day' => erp_day_from($r['FALTA'] ?? ''),
        'ended_day' => erp_day_from($r['FBAJA'] ?? ''),
    ];
}

function erp_map_invoice(array $h): array
{
    $base = 0.0;
    $vat = 0.0;
    for ($i = 0; $i < 10; $i++) {
        $base += (float)($h["BASE$i"] ?? 0);
        $vat += (float)($h["CUOTA$i"] ?? 0);
    }
    // Desglose por tipo de IVA (índices 0 a 9). El porcentaje de cada índice NO está en FHISTORI: se calcula por factura (CUOTA/BASE).
    $vatRows = [];
    for ($i = 0; $i < 10; $i++) {
        $bs = erp_cents($h["BASE$i"] ?? 0);
        $cu = erp_cents($h["CUOTA$i"] ?? 0);
        $rc = erp_cents($h["RECARGO$i"] ?? 0);
        $rt = erp_cents($h["RETENCION$i"] ?? 0);
        if ($bs !== 0 || $cu !== 0 || $rc !== 0 || $rt !== 0) {
            $vatRows[] = ['i' => $i, 'pct' => $bs !== 0 ? round($cu / $bs * 100) : null, 'base' => $bs, 'cuota' => $cu, 'rec' => $rc, 'ret' => $rt];
        }
    }
    $day = erp_day_from($h['FECHA'] ?? '');
    return [
        'inv_number' => erp_txt($h['FACTURA'] ?? '', 191),
        'cust_code' => erp_txt($h['CLIENTE'] ?? '', 191),
        'inv_day' => $day,
        'inv_year' => intdiv($day, 10000),
        'inv_month' => intdiv($day, 100) % 100,
        'subtotal_cents' => erp_cents($h['SUBTOTAL'] ?? 0),
        'base_cents' => erp_cents($base),
        'vat_cents' => erp_cents($vat),
        'total_cents' => erp_cents($h['TOTAL'] ?? 0),
        'oper' => erp_txt($h['OPER'] ?? '', 20),
        'seller' => erp_txt($h['VENDEDOR'] ?? '', 20),
        'pay_code' => erp_txt($h['FPAGO2'] ?? '', 20),
        'annulled' => (int)($h['ANULADA'] ?? 0) !== 0 ? 1 : 0,
        'rectifies' => erp_txt($h['FRECTIFI'] ?? '', 40),
        'iva_incl' => (int)($h['IVAINCLUIDO'] ?? 0) !== 0 ? 1 : 0,
        'vat_json' => json_encode($vatRows),
        'origin' => 'ncs',
        'delivered_cents' => erp_cents($h['ENTREGADO'] ?? 0),
    ];
}

function erp_map_line(array $l): array
{
    return [
        'src_key' => erp_txt($l['CLAVE'] ?? '', 191),
        'line_no' => (int)($l['LINEA'] ?? 0),
        'article_code' => erp_txt($l['CODIGO'] ?? '', 60),
        'title' => erp_txt($l['TITULOL'] ?? ''),
        'qty_milli' => (int)round((float)($l['UNIDADES'] ?? 0) * 1000),
        'price_cents' => erp_cents($l['PRECIO'] ?? 0),
        'disc_bp' => (int)round((float)($l['DCTO'] ?? 0) * 100),
        'amount_cents' => erp_cents($l['IMPORTE'] ?? 0),
        'vat_code' => erp_txt($l['TIVA'] ?? '', 10),
        'size_no' => (int)($l['TALLA'] ?? 0),
        'color_no' => (int)($l['COLOR'] ?? 0),
        'size_txt' => '',
        'color_txt' => '',
    ];
}

/** Texto de un hueco de ancho fijo (talla: 4 caracteres; color: 10). $idx empieza en 1; 0 = sin talla / sin color. */
function erp_slot(string $raw, int $idx, int $width): string
{
    return $idx < 1 ? '' : erp_txt(mb_substr($raw, ($idx - 1) * $width, $width), 60);
}

function erp_hash(array $data): string
{
    return sha1((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/* ---------- Trabajo de sincronización (sin datos personales en el archivo de estado) ---------- */

function erp_job_load(): ?array
{
    $j = is_file(ERP_JOB) ? json_decode((string)@file_get_contents(ERP_JOB), true) : null;
    return is_array($j) ? $j : null;
}

function erp_job_save(array $job): void
{
    file_put_contents(ERP_JOB, json_encode($job), LOCK_EX);
}

/** Borra el trabajo y el archivo de trabajo con los datos a importar. */
function erp_job_clear(): void
{
    foreach ([ERP_JOB, ERP_STAGE, ERP_STAGE . '-wal', ERP_STAGE . '-shm', ERP_STAGE . '-journal'] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/** Archivos NCS dejados por FTP en data/importar/: ['cuentas' => ruta|null, 'historico' => ruta|null]. */
function erp_ftp_sources(): array
{
    $out = ['cuentas' => null, 'historico' => null, 'almacen' => null];
    foreach (is_dir(ERP_FTP_DIR) ? (scandir(ERP_FTP_DIR) ?: []) : [] as $n) {
        if (preg_match('/^fcuentas.*\.mdb$/i', $n)) {
            $out['cuentas'] = ERP_FTP_DIR . '/' . $n;
        } elseif (preg_match('/^fhistori.*\.mdb$/i', $n)) {
            $out['historico'] = ERP_FTP_DIR . '/' . $n;
        } elseif (preg_match('/^falmacen.*\.mdb$/i', $n)) {
            $out['almacen'] = ERP_FTP_DIR . '/' . $n; // lo usa también la importación del catálogo: NO se borra al terminar
        }
    }
    return $out;
}

/**
 * Lee las dos bases de NCS, prepara en data/erp_stage.sqlite lo que se va a importar (solo los campos elegidos) y
 * lo compara con lo que ya hay. Devuelve los recuentos (nada personal). Lanza RuntimeException con mensajes sin datos.
 */
function erp_stage_build(string $cuentasFile, string $historicoFile, ?string $almacenFile = null): array
{
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    erp_job_clear();
    try {
        $cu = new MdbReader($cuentasFile);
        $hi = new MdbReader($historicoFile);
        $tc = $cu->tables();
        $th = $hi->tables();
    } catch (Throwable $e) {
        throw new RuntimeException('No se pudo leer alguno de los archivos: comprueba que son FCUENTAS.MDB y FHISTORI.MDB de NCS.');
    }
    if (!isset($tc['TCLIENTES']) || !isset($th['TCHVENTAS']) || !isset($th['TLHVENTAS'])) {
        throw new RuntimeException('Los archivos no tienen las tablas esperadas (TCLIENTES, TCHVENTAS y TLHVENTAS).');
    }
    $db = db();
    $haveC = [];
    $cmsC = []; // clientes dados de alta a mano en el CMS: NCS nunca los sobrescribe
    $editedC = []; // clientes de NCS modificados a mano en el CMS: NCS no los pisa sin avisar
    foreach ($db->query('SELECT cust_code, row_hash, origin, ext_ref, cms_edited_at FROM erp_customers')->fetchAll(PDO::FETCH_NUM) as $r) {
        if ((string)$r[2] === 'cms') {
            $cmsC[mb_strtolower((string)$r[0])] = true;
        } else {
            $key = (string)$r[3] !== '' ? (string)$r[3] : (string)$r[0]; // si se cambió el código en el CMS, se casa con el que tenía en NCS
            $haveC[$key] = $r[1];
            if ((int)$r[4] > 0) {
                $editedC[$key] = true;
            }
        }
    }
    $haveI = [];
    foreach ($db->query("SELECT inv_number, row_hash FROM erp_invoices WHERE origin = 'ncs'")->fetchAll(PDO::FETCH_NUM) as $r) { // las del CMS no vienen de NCS
        $haveI[$r[0]] = $r[1];
    }

    $st = new PDO('sqlite:' . ERP_STAGE);
    $st->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $st->exec('PRAGMA synchronous = OFF');
    $st->exec('CREATE TABLE cust (seq INTEGER PRIMARY KEY, code TEXT, state TEXT, data TEXT)');
    $st->exec('CREATE TABLE inv (seq INTEGER PRIMARY KEY, num TEXT, state TEXT, data TEXT)');

    $cnt = ['cust' => ['new' => 0, 'changed' => 0, 'same' => 0, 'gone' => 0], 'inv' => ['new' => 0, 'changed' => 0, 'same' => 0, 'gone' => 0], 'lines' => 0, 'skipped' => 0, 'cust_conflict' => 0, 'cust_kept' => 0];

    // Clientes
    $st->beginTransaction();
    $ins = $st->prepare('INSERT INTO cust (seq, code, state, data) VALUES (?,?,?,?)');
    $seen = [];
    $seq = 0;
    foreach ($cu->rows('TCLIENTES') as $r) {
        $m = erp_map_customer($r);
        if ($m['cust_code'] === '' || isset($seen[$m['cust_code']])) {
            $cnt['skipped']++;
            continue;
        }
        $seen[$m['cust_code']] = true;
        if (isset($cmsC[mb_strtolower($m['cust_code'])])) { // mismo código que un cliente creado en el CMS: no se toca
            $cnt['cust_conflict']++;
            continue;
        }
        $m['row_hash'] = erp_hash($m);
        $state = !isset($haveC[$m['cust_code']]) ? 'new' : ($haveC[$m['cust_code']] === $m['row_hash'] ? 'same' : 'changed');
        if ($state === 'changed' && isset($editedC[$m['cust_code']])) { // NCS lo ha cambiado, pero aquí se modificó a mano: se respeta lo del CMS y se avisa
            $state = 'kept';
            $cnt['cust_kept']++;
        } else {
            $cnt['cust'][$state]++;
        }
        $ins->execute([++$seq, $m['cust_code'], $state, json_encode($m, JSON_UNESCAPED_UNICODE)]);
    }
    $st->commit();
    $cnt['cust']['gone'] = count(array_diff_key($haveC, $seen));
    unset($seen);

    // Líneas agrupadas por factura
    $lines = [];
    foreach ($hi->rows('TLHVENTAS') as $r) {
        $m = erp_map_line($r);
        $f = erp_txt($r['FACTURA'] ?? '', 191);
        if ($m['src_key'] === '' || $f === '') {
            $cnt['skipped']++;
            continue;
        }
        $lines[$f][] = $m;
    }

    // Facturas (cabecera + sus líneas; la huella cubre las dos cosas)
    $perCust = [];
    $seenI = [];
    $seq = 0;
    $st->beginTransaction();
    $ins = $st->prepare('INSERT INTO inv (seq, num, state, data) VALUES (?,?,?,?)');
    foreach ($hi->rows('TCHVENTAS') as $r) {
        $m = erp_map_invoice($r);
        if ($m['inv_number'] === '' || isset($seenI[$m['inv_number']])) {
            $cnt['skipped']++;
            continue;
        }
        $seenI[$m['inv_number']] = true;
        $ls = $lines[$m['inv_number']] ?? [];
        usort($ls, fn($a, $b) => $a['line_no'] <=> $b['line_no'] ?: strcmp($a['src_key'], $b['src_key']));
        unset($lines[$m['inv_number']]);
        // La huella no incluye los textos de talla y color (vienen de FALMACEN, que es opcional): no cambian al sincronizar sin él
        $m['row_hash'] = erp_hash(['i' => $m, 'l' => array_map(fn($l) => array_diff_key($l, ['size_txt' => 1, 'color_txt' => 1]), $ls)]);
        $perCust[$m['cust_code']] = ($perCust[$m['cust_code']] ?? 0) + 1;
        $state = !isset($haveI[$m['inv_number']]) ? 'new' : ($haveI[$m['inv_number']] === $m['row_hash'] ? 'same' : 'changed');
        $cnt['inv'][$state]++;
        if ($state !== 'same') {
            $cnt['lines'] += count($ls);
        }
        $ins->execute([++$seq, $m['inv_number'], $state, json_encode(['i' => $m, 'l' => $ls], JSON_UNESCAPED_UNICODE)]);
    }
    $st->commit();
    $cnt['inv']['gone'] = count(array_diff_key($haveI, $seenI));
    $cnt['skipped'] += array_sum(array_map('count', $lines)); // líneas sin cabecera
    unset($lines, $seenI);

    // Tallas y colores reales (opcional): TART.T00 y TART.C00 son texto de ancho fijo (huecos de 4 y de 10 caracteres)
    // y TLHVENTAS.TALLA / COLOR son la posición, empezando en 1 (comprobado: 100 % de las líneas caen en un hueco con valor).
    $cnt['art'] = 0;
    if ($almacenFile !== null) {
        try {
            $al = new MdbReader($almacenFile);
            if (!isset($al->tables()['TART'])) {
                throw new RuntimeException('x');
            }
            $st->exec('CREATE TABLE art (code TEXT PRIMARY KEY, t TEXT, c TEXT)');
            $st->beginTransaction();
            $ins = $st->prepare('INSERT OR REPLACE INTO art (code, t, c) VALUES (?,?,?)');
            foreach ($al->rows('TART') as $r) { // solo CODIGO, T00 y C00: ninguna otra columna se guarda ni se muestra
                $code = erp_txt($r['CODIGO'] ?? '', 60);
                if ($code !== '') {
                    $ins->execute([$code, (string)($r['T00'] ?? ''), (string)($r['C00'] ?? '')]);
                    $cnt['art']++;
                }
            }
            $st->commit();
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo leer FALMACEN.MDB (tabla TART). Quítalo para sincronizar sin los textos de talla y color.');
        }
    }

    // Cliente de contado: el que más facturas tiene si es muy grande y no tiene NIF
    $suggest = '';
    arsort($perCust);
    $top = array_key_first($perCust);
    if ($top !== null && $perCust[$top] >= ERP_RETAIL_MIN) {
        $q = $st->prepare('SELECT data FROM cust WHERE code = ?');
        $q->execute([$top]);
        $d = json_decode((string)$q->fetchColumn(), true);
        if (is_array($d) && $d['nif'] === '') {
            $suggest = (string)$top;
        } elseif (!$d) {
            $suggest = (string)$top;
        }
    }
    return ['counts' => $cnt, 'retail_suggest' => $suggest];
}

/** Inserta o actualiza una fila de una tabla erp_* a partir de un array columna => valor (columnas de confianza). */
function erp_save_row(string $table, string $keyCol, array $data, ?int $existingId): int
{
    $db = db();
    $cols = array_keys($data);
    if ($existingId !== null) {
        $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', array_map(fn($c) => $c . ' = ?', $cols)) . ' WHERE id = ?')
            ->execute([...array_values($data), $existingId]);
        return $existingId;
    }
    $db->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($data));
    return (int)$db->lastInsertId();
}

/**
 * Hace una tanda de la sincronización (hasta $deadline). Actualiza $job (fase, cursor, contadores). Sin datos personales
 * en $job. Devuelve true si ya ha terminado todo.
 */
function erp_run(array &$job, float $deadline): bool
{
    $db = db();
    $st = new PDO('sqlite:' . ERP_STAGE);
    $st->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $now = time();
    try {
        if (($job['phase'] ?? 'cust') === 'cust') {
            $db->beginTransaction();
            $q = $st->query("SELECT data, state FROM cust WHERE state NOT IN ('same', 'kept') ORDER BY seq");
            $find = $db->prepare('SELECT id, origin FROM erp_customers WHERE LOWER(cust_code) = LOWER(?) OR (ext_ref <> \'\' AND LOWER(ext_ref) = LOWER(?))');
            foreach ($q as $row) {
                $d = json_decode((string)$row['data'], true);
                $d['synced_at'] = $now;
                $find->execute([$d['cust_code'], $d['cust_code']]);
                $found = $find->fetch();
                if (!$found) {
                    $d['imported_at'] = $now; // primer traspaso de este cliente
                }
                if ($found && (string)$found['origin'] === 'cms') { // creado en el CMS mientras tanto: no se sobrescribe
                    continue;
                }
                $id = $found ? $found['id'] : false;
                erp_save_row('erp_customers', 'cust_code', $d, $id === false ? null : (int)$id);
                $job['done_cust'] = ($job['done_cust'] ?? 0) + 1;
            }
            $db->commit();
            $job['phase'] = 'inv';
            $job['cursor'] = 0;
        }
        if ($job['phase'] === 'inv') {
            while (microtime(true) < $deadline) {
                $q = $st->prepare("SELECT seq, data FROM inv WHERE seq > ? AND state <> 'same' ORDER BY seq LIMIT " . ERP_INV_BATCH);
                $q->execute([(int)$job['cursor']]);
                $rows = $q->fetchAll();
                if (!$rows) {
                    if (empty($job['has_art'])) {
                        return true;
                    }
                    $job['phase'] = 'txt_prep';
                    break;
                }
                $db->beginTransaction();
                $find = $db->prepare('SELECT id FROM erp_invoices WHERE inv_number = ?');
                $old = $db->prepare('SELECT src_key, size_no, color_no, size_txt, color_txt FROM erp_invoice_lines WHERE invoice_id = ?');
                foreach ($rows as $row) {
                    $d = json_decode((string)$row['data'], true);
                    $inv = $d['i'];
                    $inv['synced_at'] = $now;
                    $find->execute([$inv['inv_number']]);
                    $id = $find->fetchColumn();
                    $keep = [];
                    if ($id !== false) { // al reescribir las líneas se conservan los textos de talla y color ya guardados
                        $old->execute([(int)$id]);
                        foreach ($old->fetchAll() as $o) {
                            $keep[$o['src_key']] = $o;
                        }
                    }
                    $id = erp_save_row('erp_invoices', 'inv_number', $inv, $id === false ? null : (int)$id);
                    $db->prepare('DELETE FROM erp_invoice_lines WHERE invoice_id = ?')->execute([$id]);
                    foreach ($d['l'] as $l) {
                        $db->prepare('DELETE FROM erp_invoice_lines WHERE src_key = ?')->execute([$l['src_key']]); // una CLAVE no puede repetirse
                        $o = $keep[$l['src_key']] ?? null;
                        if ($o && (int)$o['size_no'] === $l['size_no'] && (int)$o['color_no'] === $l['color_no']) {
                            $l['size_txt'] = (string)$o['size_txt'];
                            $l['color_txt'] = (string)$o['color_txt'];
                        }
                        $l['invoice_id'] = $id;
                        erp_save_row('erp_invoice_lines', 'src_key', $l, null);
                    }
                    $job['cursor'] = (int)$row['seq'];
                    $job['done_inv'] = ($job['done_inv'] ?? 0) + 1;
                }
                $db->commit();
            }
        }
        if (in_array($job['phase'], ['txt_prep', 'txt'], true)) {
            if ($job['phase'] === 'txt_prep') { // combinaciones distintas artículo + talla + color de lo guardado
                $st->exec('CREATE TABLE IF NOT EXISTS combo (seq INTEGER PRIMARY KEY, code TEXT, s INTEGER, c INTEGER)');
                $st->exec('DELETE FROM combo');
                $st->beginTransaction();
                $ins = $st->prepare('INSERT INTO combo (seq, code, s, c) VALUES (?,?,?,?)');
                $n = 0;
                foreach ($db->query('SELECT DISTINCT article_code, size_no, color_no FROM erp_invoice_lines WHERE size_no > 0 OR color_no > 0') as $r) {
                    $ins->execute([++$n, (string)$r['article_code'], (int)$r['size_no'], (int)$r['color_no']]);
                }
                $st->commit();
                $job['total_txt'] = $n;
                $job['done_txt'] = 0;
                $job['cursor'] = 0;
                $job['phase'] = 'txt';
            }
            $art = $st->prepare('SELECT t, c FROM art WHERE code = ?');
            while (microtime(true) < $deadline) {
                $q = $st->prepare('SELECT seq, code, s, c FROM combo WHERE seq > ? ORDER BY seq LIMIT 500');
                $q->execute([(int)$job['cursor']]);
                $rows = $q->fetchAll();
                if (!$rows) {
                    return true;
                }
                $db->beginTransaction();
                $upd = $db->prepare('UPDATE erp_invoice_lines SET size_txt = ?, color_txt = ? WHERE article_code = ? AND size_no = ? AND color_no = ? AND (size_txt <> ? OR color_txt <> ?)');
                foreach ($rows as $r) {
                    $art->execute([$r['code']]);
                    $a = $art->fetch();
                    if ($a) {
                        $sz = erp_slot((string)$a['t'], (int)$r['s'], 4);
                        $co = erp_slot((string)$a['c'], (int)$r['c'], 10);
                        $upd->execute([$sz, $co, $r['code'], (int)$r['s'], (int)$r['c'], $sz, $co]);
                    } else {
                        $job['txt_missing'] = ($job['txt_missing'] ?? 0) + 1;
                    }
                    $job['cursor'] = (int)$r['seq'];
                    $job['done_txt'] = ($job['done_txt'] ?? 0) + 1;
                }
                $db->commit();
            }
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Sincronización ERP: ' . get_class($e)); // sin mensaje: podría llevar valores
        $job['error'] = 'La sincronización se ha parado por un error de la base de datos. Lo ya guardado es correcto; puedes volver a empezar.';
        return false;
    }
    return false;
}
/** Fin: anota el resumen (solo números), borra el archivo de trabajo y los .mdb dejados por FTP. */
function erp_finish(array $job): void
{
    require_once __DIR__ . '/history.php';
    save_setting('series_ncs_letters', ''); // las letras de las facturas importadas pueden haber cambiado
    $c = $job['counts'] ?? [];
    history_add('sincronizacion', 'clientes_ventas', 'NCS (sincronización manual)', 'ok',
        'clientes: ' . (int)($c['cust']['new'] ?? 0) . ' nuevos, ' . (int)($c['cust']['changed'] ?? 0) . ' cambiados · facturas: ' . (int)($c['inv']['new'] ?? 0) . ' nuevas, ' . (int)($c['inv']['changed'] ?? 0) . ' cambiadas');
    save_setting('erp_last_sync', (string)json_encode(['at' => time(), 'counts' => $job['counts'] ?? []]));
    foreach (($job['ftp_files'] ?? []) as $f) {
        if (is_string($f) && str_starts_with($f, ERP_FTP_DIR . '/') && is_file($f)) {
            @unlink($f);
        }
    }
    erp_job_clear();
}

/* ---------- Pantalla: sincronizar con NCS ---------- */

function admin_erp_sync(): void
{
    if (!erp_guard()) {
        return;
    }
    $job = erp_job_load();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? '');
        if ($act === 'analyze' && !$job) {
            $ftp = erp_ftp_sources();
            $paths = [];
            $fromFtp = [];
            foreach (['cuentas' => 'cuentas', 'historico' => 'historico'] as $key => $field) {
                $up = $_FILES[$field] ?? null;
                if ($up && (int)($up['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$up['tmp_name'])) {
                    $paths[$key] = (string)$up['tmp_name'];
                } elseif ($ftp[$key]) {
                    $paths[$key] = $ftp[$key];
                    $fromFtp[] = $ftp[$key];
                }
            }
            $alm = null; // FALMACEN.MDB es opcional (textos de talla y color)
            $up = $_FILES['almacen'] ?? null;
            if ($up && (int)($up['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$up['tmp_name'])) {
                $alm = (string)$up['tmp_name'];
            } elseif ($ftp['almacen'] && !empty($_POST['con_almacen'])) {
                $alm = $ftp['almacen'];
            }
            if (count($paths) < 2) {
                flash('Faltan archivos: sube FCUENTAS.MDB y FHISTORI.MDB, o déjalos por FTP en data/importar/.', 'err');
                redirect('/admin/erp');
            }
            try {
                $res = erp_stage_build($paths['cuentas'], $paths['historico'], $alm);
            } catch (RuntimeException $e) {
                erp_job_clear();
                flash($e->getMessage(), 'err');
                redirect('/admin/erp');
            } catch (Throwable $e) {
                erp_job_clear();
                error_log('Análisis ERP: ' . get_class($e));
                flash('No se pudieron analizar los archivos (¿son de NCS Facturación? ¿pesan demasiado para este servidor?).', 'err');
                redirect('/admin/erp');
            }
            erp_job_save(['status' => 'plan', 'counts' => $res['counts'], 'retail_suggest' => $res['retail_suggest'], 'ftp_files' => $fromFtp, 'has_art' => $alm !== null, 'at' => time()]);
            redirect('/admin/erp');
        }
        if ($act === 'cancel') {
            if ($job && ($job['status'] ?? '') === 'run') { // cancelar a medias: queda anotado
                require_once __DIR__ . '/history.php';
                history_add('sincronizacion', 'clientes_ventas', 'NCS (sincronización manual)', 'cancelado', 'cancelada durante la importación');
            }
            erp_job_clear();
            flash('Sincronización cancelada. No se ha cambiado nada.');
            redirect('/admin/erp');
        }
        if ($act === 'retail') {
            save_setting('erp_retail_customer', erp_txt($_POST['retail'] ?? '', 40));
            flash('Cliente de contado guardado.');
            redirect('/admin/erp');
        }
        if ($act === 'preload_paid') { // una sola vez
            if (db_setting('erp_paid_preload_done') !== '' || empty($_POST['confirm'])) {
                flash(db_setting('erp_paid_preload_done') !== '' ? 'Los cobros ya se precargaron.' : 'Marca la casilla para confirmar.', 'err');
            } else {
                $nPaid = erp_preload_paid();
                require_once __DIR__ . '/history.php';
                history_add('sincronizacion', 'cobros', 'Precarga de cobros desde NCS', 'ok', $nPaid . ' facturas marcadas como cobradas');
                flash('Cobros precargados: ' . $nPaid . ' facturas marcadas como cobradas (la fecha de la venta).');
            }
            redirect('/admin/erp#documentos');
        }
        if ($act === 'docs') { // serie de las facturas y nombres de las formas de pago
            $mode = (string)($_POST['serie_mode'] ?? 'letras');
            save_setting('erp_serie_mode', in_array($mode, ['letras', 'ninguna', 'n'], true) ? $mode : 'letras');
            save_setting('erp_serie_n', (string)max(1, min(6, (int)($_POST['serie_n'] ?? 1))));
            if (isset($_POST['pay'])) { // formulario antiguo; los nombres se editan ahora en Configuración → Formas de pago
                $names = [];
                foreach (erp_pay_codes() as $code) { // solo los códigos que existen
                    $nm = mb_substr(trim(strip_tags((string)(((array)($_POST['pay'] ?? []))[$code] ?? ''))), 0, 40);
                    if ($nm !== '') {
                        $names[$code] = $nm;
                    }
                }
                save_setting('erp_pay_names', (string)json_encode($names, JSON_UNESCAPED_UNICODE));
            }
            flash('Ajustes de documentos guardados.');
            redirect('/admin/erp#documentos');
        }
        if ($act === 'start' && $job && ($job['status'] ?? '') === 'plan') {
            save_setting('erp_retail_customer', erp_txt($_POST['retail'] ?? '', 40));
            $job['status'] = 'run';
            $job['phase'] = 'cust';
            $job['cursor'] = 0;
            $job['done_cust'] = 0;
            $job['done_inv'] = 0;
            erp_job_save($job);
            redirect('/admin/erp');
        }
        redirect('/admin/erp');
    }

    $refresh = false;
    if ($job && ($job['status'] ?? '') === 'run') {
        $lock = @fopen(ERP_LOCK, 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) { // dos pestañas no trabajan a la vez
            $finished = erp_run($job, microtime(true) + ERP_TANDA_SECS);
            if ($finished && empty($job['error'])) {
                $c = $job['counts'];
                erp_finish($job);
                flock($lock, LOCK_UN);
                fclose($lock);
                flash('Sincronización terminada: clientes ' . ($c['cust']['new'] + $c['cust']['changed']) . ' (' . $c['cust']['new'] . ' nuevos, ' . $c['cust']['changed'] . ' cambiados) y facturas ' . ($c['inv']['new'] + $c['inv']['changed']) . ' (' . $c['inv']['new'] . ' nuevas, ' . $c['inv']['changed'] . ' cambiadas).');
                redirect('/admin/erp');
            }
            if (!empty($job['error']) && empty($job['hist'])) { // error de la sincronización: se anota una sola vez (sin el texto, por si lleva datos)
                require_once __DIR__ . '/history.php';
                history_add('sincronizacion', 'clientes_ventas', 'NCS (sincronización manual)', 'error', 'la sincronización se detuvo con un error');
                $job['hist'] = 1;
            }
            erp_job_save($job);
            flock($lock, LOCK_UN);
        }
        if ($lock) {
            fclose($lock);
        }
        $refresh = empty($job['error']);
    }

    $last = json_decode(db_setting('erp_last_sync'), true);
    admin_render('erp_sync', [
        'job' => $job, 'last' => is_array($last) ? $last : null, 'ftp' => erp_ftp_sources(), 'retail' => erp_retail_code(),
        'refresh' => $refresh, 'totals' => erp_totals(), 'payCodes' => erp_pay_codes(), 'payNames' => erp_pay_names(), 'preloadDone' => db_setting('erp_paid_preload_done'), 'preloadN' => erp_preload_candidates(), 'title' => 'Sincronizar con NCS',
    ]);
}

/** Códigos de forma de pago que aparecen en facturas y clientes (para ponerles nombre). */
function erp_pay_codes(): array
{
    $db = db();
    $out = [];
    foreach (['SELECT DISTINCT pay_code FROM erp_invoices WHERE pay_code <> \'\'', 'SELECT DISTINCT pay_code FROM erp_customers WHERE pay_code <> \'\''] as $q) {
        foreach ($db->query($q)->fetchAll(PDO::FETCH_COLUMN) as $c) {
            $out[(string)$c] = true;
        }
    }
    $codes = array_map('strval', array_keys($out));
    sort($codes, SORT_NATURAL);
    return $codes;
}

/** Recuentos de lo que hay guardado. */
function erp_totals(): array
{
    $db = db();
    return [
        'customers' => (int)$db->query('SELECT COUNT(*) FROM erp_customers')->fetchColumn(),
        'invoices' => (int)$db->query('SELECT COUNT(*) FROM erp_invoices')->fetchColumn(),
        'lines' => (int)$db->query('SELECT COUNT(*) FROM erp_invoice_lines')->fetchColumn(),
    ];
}

/* ---------- Pantalla: clientes ---------- */

const ERP_CLIENT_SORTS = ['nombre' => 'c.name', 'codigo' => 'c.cust_code', 'facturas' => 'n_inv', 'total' => 'tot', 'ultima' => 'last_day'];

/** Parámetros de la lista de clientes limpios (lista cerrada). */
function erp_clients_query(array $src): array
{
    $sort = (string)($src['orden'] ?? 'nombre');
    return array_filter([
        'q' => mb_substr(trim((string)($src['q'] ?? '')), 0, 100),
        'ver' => in_array($src['ver'] ?? '', ['con', 'bloq'], true) ? (string)$src['ver'] : '',
        'contado' => !empty($src['contado']) ? '1' : '',
        'orden' => isset(ERP_CLIENT_SORTS[$sort]) && $sort !== 'nombre' ? $sort : '',
        'dir' => ($src['dir'] ?? '') === 'desc' ? 'desc' : '',
        'pag' => max(1, (int)($src['pag'] ?? 1)) > 1 ? (string)max(1, (int)$src['pag']) : '',
    ], fn($v) => $v !== '');
}

function erp_clients_url(array $q, array $change = []): string
{
    $q = array_filter(array_merge($q, $change), fn($v) => $v !== '' && $v !== null);
    return '/admin/clientes' . ($q ? '?' . http_build_query($q) : '');
}

function admin_clients(string $code, string $sub = ''): void
{
    if (!erp_guard()) {
        return;
    }
    if ($code === 'nuevo' && $sub === '') {
        admin_client_edit(null);
        return;
    }
    if ($code !== '' && $sub === 'modificar') {
        $st = db()->prepare('SELECT * FROM erp_customers WHERE cust_code = ?');
        $st->execute([$code]);
        $c = $st->fetch();
        $c ? admin_client_edit($c) : not_found();
        return;
    }
    if ($code !== '' && $sub !== '') {
        not_found();
        return;
    }
    if ($code !== '') {
        admin_client($code);
        return;
    }
    $db = db();
    $q = erp_clients_query($_GET);
    $where = '';
    $args = [];
    if (($q['q'] ?? '') !== '') {
        $like = '%' . $q['q'] . '%';
        $where .= ' AND (c.name LIKE ? OR c.trade_name LIKE ? OR c.cust_code LIKE ? OR c.nif LIKE ? OR c.city LIKE ? OR c.phone LIKE ? OR c.phone2 LIKE ? OR c.email LIKE ? OR c.contact LIKE ?)';
        array_push($args, $like, $like, $like, $like, $like, $like, $like, $like, $like);
    }
    if (($q['ver'] ?? '') === 'con') {
        $where .= ' AND c.cust_code IN (SELECT cust_code FROM erp_invoices WHERE annulled = 0)';
    } elseif (($q['ver'] ?? '') === 'bloq') {
        $where .= ' AND (c.blocked = 1 OR c.ended_day > 0)';
    }
    $retail = erp_retail_code();
    $hidden = false;
    if ($retail !== '' && ($q['q'] ?? '') === '' && empty($q['contado'])) {
        $where .= ' AND c.cust_code <> ?';
        $args[] = $retail;
        $hidden = true;
    }
    $st = $db->prepare('SELECT COUNT(*) FROM erp_customers c WHERE 1=1' . $where);
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / ERP_PER_PAGE));
    $page = min($pages, (int)($q['pag'] ?? 1));
    $sortKey = isset($q['orden']) ? $q['orden'] : 'nombre';
    $dir = ($q['dir'] ?? '') === 'desc' ? 'DESC' : 'ASC';
    $order = ERP_CLIENT_SORTS[$sortKey] . ' ' . $dir . ($sortKey === 'nombre' ? '' : ', c.name') . ', c.id'; // SQL Server no admite repetir una columna en ORDER BY
    $sql = 'SELECT c.id, c.cust_code, c.name, c.trade_name, c.nif, c.city, c.phone, c.blocked, c.ended_day,
            COALESCE(s.n, 0) AS n_inv, COALESCE(s.tot, 0) AS tot, COALESCE(s.last_day, 0) AS last_day
        FROM erp_customers c
        LEFT JOIN (SELECT cust_code, COUNT(*) AS n, SUM(total_cents) AS tot, MAX(inv_day) AS last_day FROM erp_invoices WHERE annulled = 0 GROUP BY cust_code) s ON s.cust_code = c.cust_code
        WHERE 1=1' . $where . ' ORDER BY ' . $order . ' LIMIT ' . ERP_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * ERP_PER_PAGE) : '');
    $st = $db->prepare($sql);
    $st->execute($args);
    admin_render('clients', [
        'rows' => $st->fetchAll(), 'q' => $q, 'total' => $total, 'page' => $page, 'pages' => $pages, 'hidden' => $hidden, 'retail' => $retail,
        'totals' => erp_totals(), 'title' => 'Clientes',
    ]);
}

/** Enlaza códigos de artículo de NCS con el catálogo por referencia: [código => id del artículo]. Una consulta. */
function erp_article_links(array $codes): array
{
    $codes = array_values(array_unique(array_filter(array_map('strval', $codes), 'strlen')));
    if (!$codes) {
        return [];
    }
    $out = [];
    foreach (array_chunk($codes, 500) as $chunk) {
        $st = db()->prepare('SELECT id, ref FROM products WHERE ref IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $st->execute($chunk);
        foreach ($st->fetchAll() as $r) {
            $out[mb_strtolower((string)$r['ref'])] = (int)$r['id'];
        }
    }
    $res = [];
    foreach ($codes as $c) {
        if (isset($out[mb_strtolower($c)])) {
            $res[$c] = $out[mb_strtolower($c)];
        }
    }
    return $res;
}
