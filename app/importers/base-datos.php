<?php
declare(strict_types=1);

/*
 * Origen: base de datos de otra aplicación.
 *   - Microsoft Access (.mdb de Access 97 a 2003, .accdb), leído en PHP puro (app/mdb.php)
 *   - Volcado SQL (.sql o .sql.gz) de MySQL / MariaDB / phpMyAdmin / SQL Server / PostgreSQL (INSERT INTO …)
 *   - Archivo SQLite (.sqlite, .db, .sqlite3)
 * Paso 1: se sube el archivo y se copia a una base temporal (data/import_db.sqlite).
 * Paso 2: se elige la tabla de artículos y qué columna es cada cosa (nombre, referencia, familia, fotos…),
 *         incluidas familias y fotos que están en otras tablas (por número de id).
 * Paso 3: el plan de siempre, para revisar e importar.
 *
 * Perfiles (app/profiles.php): en el paso 2 se puede aplicar un emparejamiento guardado o guardar
 * el actual. Si la base de datos encaja con un perfil de sistema (p. ej. NCS: tablas TART y TFAM),
 * se aplica solo. Las columnas prohibidas por los perfiles (AMPLIACIOND…) se quitan de la lista de
 * columnas nada más analizar: no aparecen en ningún desplegable ni se leen nunca.
 *
 * Modo «Actualizar precios y stock» (map['mode'] = 'update'): busca cada artículo SOLO por su
 * referencia y cambia únicamente los campos elegidos (y, si se marca, la visibilidad), aunque ya
 * tengan valor. Nunca crea ni borra artículos, ni toca nombre, descripción, familia o fotos.
 */

require_once dirname(__DIR__) . '/profiles.php';

const DBIMP_EXTS = ['sql', 'gz', 'sqlite', 'sqlite3', 'db', 'mdb', 'accdb'];
const DBIMP_MAX_BYTES = 300 * 1024 * 1024;

/* ---------- Lectura del volcado SQL ---------- */

/** Quita comillas de un identificador: `tabla`, "tabla", [tabla], esquema.tabla → tabla */
function dbimp_ident(string $s): string
{
    $s = trim($s);
    $parts = preg_split('/\.(?=(?:[^`"\]]*[`"\[][^`"\]]*[`"\]])*[^`"\]]*$)/', $s) ?: [$s];
    $s = trim((string)end($parts));
    return trim($s, '`"[] ');
}

/** Nombre seguro para SQLite. */
function dbimp_safe(string $s): string
{
    $s = preg_replace('/[^\p{L}\p{N}_ ]+/u', '_', $s);
    return mb_substr(trim((string)$s) !== '' ? (string)$s : 'col', 0, 64);
}

/**
 * Divide el texto SQL en sentencias (respetando comillas y comentarios) y llama a $fn por cada una.
 */
function dbimp_statements(string $sql, callable $fn): void
{
    $len = strlen($sql);
    $start = 0;
    $i = 0;
    while ($i < $len) {
        $i += strcspn($sql, "';\"`-/#[", $i);
        if ($i >= $len) {
            break;
        }
        $c = $sql[$i];
        if ($c === "'" || $c === '"' || $c === '`') {
            $i++;
            while ($i < $len) {
                $i += strcspn($sql, $c . '\\', $i);
                if ($i >= $len) {
                    break;
                }
                if ($sql[$i] === '\\' && $c !== '`') {
                    $i += 2;
                    continue;
                }
                if ($i + 1 < $len && $sql[$i + 1] === $c) {
                    $i += 2; // comilla doblada ''
                    continue;
                }
                $i++;
                break;
            }
        } elseif ($c === '[') {
            $e = strpos($sql, ']', $i);
            $i = $e === false ? $len : $e + 1;
        } elseif ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
            $e = strpos($sql, "\n", $i);
            $i = $e === false ? $len : $e + 1;
        } elseif ($c === '#') {
            $e = strpos($sql, "\n", $i);
            $i = $e === false ? $len : $e + 1;
        } elseif ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
            $e = strpos($sql, '*/', $i + 2);
            $i = $e === false ? $len : $e + 2;
        } elseif ($c === ';') {
            $fn(substr($sql, $start, $i - $start));
            $start = ++$i;
        } else {
            $i++;
        }
    }
    if (trim(substr($sql, $start)) !== '') {
        $fn(substr($sql, $start));
    }
}

/** Lee la lista de valores de un INSERT: (1,'a',NULL),(2,'b',…) → [[1,'a',null], …] */
function dbimp_tuples(string $s, int $pos): array
{
    $rows = [];
    $len = strlen($s);
    $row = null;
    while ($pos < $len) {
        $pos += strspn($s, " \t\r\n,", $pos);
        if ($pos >= $len) {
            break;
        }
        $c = $s[$pos];
        if ($row === null) {
            if ($c !== '(') {
                break; // fin de VALUES (ON DUPLICATE KEY…)
            }
            $row = [];
            $pos++;
            continue;
        }
        if ($c === ')') {
            $rows[] = $row;
            $row = null;
            $pos++;
            continue;
        }
        // Un valor
        $prefix = '';
        if (($c === 'N' || $c === 'n' || $c === 'E' || $c === 'e') && ($s[$pos + 1] ?? '') === "'") {
            $pos++;
            $c = "'";
        } elseif ($c === '_' && preg_match('/\G_\w+\s*(?=\')/A', $s, $m, 0, $pos)) {
            $pos += strlen($m[0]); // _binary '…', _utf8mb4 '…'
            $c = "'";
        }
        if ($c === "'") {
            $pos++;
            $val = '';
            while ($pos < $len) {
                $k = strcspn($s, "'\\", $pos);
                $val .= substr($s, $pos, $k);
                $pos += $k;
                if ($pos >= $len) {
                    break;
                }
                if ($s[$pos] === '\\') {
                    $n = $s[$pos + 1] ?? '';
                    $val .= match ($n) { 'n' => "\n", 'r' => "\r", 't' => "\t", '0' => "\0", 'Z' => "\x1a", default => $n };
                    $pos += 2;
                } elseif (($s[$pos + 1] ?? '') === "'") {
                    $val .= "'";
                    $pos += 2;
                } else {
                    $pos++;
                    break;
                }
            }
            $row[] = $prefix . $val;
            // posible ::tipo de PostgreSQL
            if (preg_match('/\G::[\w ]+/A', $s, $m, 0, $pos)) {
                $pos += strlen($m[0]);
            }
            continue;
        }
        // número, NULL, TRUE/FALSE, 0x…, función(…)
        $depth = 0;
        $st = $pos;
        while ($pos < $len) {
            $ch = $s[$pos];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                break;
            } elseif ($ch === "'") {
                $e = strpos($s, "'", $pos + 1);
                $pos = $e === false ? $len : $e;
            }
            $pos++;
        }
        $raw = trim(substr($s, $st, $pos - $st));
        $u = strtoupper($raw);
        if ($u === 'NULL') {
            $row[] = null;
        } elseif ($u === 'TRUE') {
            $row[] = '1';
        } elseif ($u === 'FALSE') {
            $row[] = '0';
        } elseif (preg_match('/^0x([0-9a-f]*)$/i', $raw, $m)) {
            $bin = (string)hex2bin(strlen($m[1]) % 2 ? '0' . $m[1] : $m[1]);
            $row[] = mb_check_encoding($bin, 'UTF-8') ? $bin : '';
        } else {
            $row[] = $raw;
        }
    }
    return $rows;
}

/** Columnas de un CREATE TABLE. */
function dbimp_create_columns(string $body): array
{
    $cols = [];
    $depth = 0;
    $cur = '';
    $parts = [];
    for ($i = 0, $n = strlen($body); $i < $n; $i++) {
        $c = $body[$i];
        if ($c === "'" ) {
            $e = strpos($body, "'", $i + 1);
            $e = $e === false ? $n - 1 : $e;
            $cur .= substr($body, $i, $e - $i + 1);
            $i = $e;
            continue;
        }
        if ($c === '(') {
            $depth++;
        } elseif ($c === ')') {
            $depth--;
        }
        if ($c === ',' && $depth === 0) {
            $parts[] = $cur;
            $cur = '';
        } else {
            $cur .= $c;
        }
    }
    $parts[] = $cur;
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '' || preg_match('/^(PRIMARY|KEY|UNIQUE|INDEX|CONSTRAINT|FOREIGN|FULLTEXT|SPATIAL|CHECK|PERIOD)\b/i', $p)) {
            continue;
        }
        if (preg_match('/^(`[^`]+`|"[^"]+"|\[[^\]]+\]|[\p{L}\p{N}_$]+)/u', $p, $m)) {
            $cols[] = dbimp_ident($m[1]);
        }
    }
    return $cols;
}

/** Carga un volcado SQL en la base temporal. Devuelve [tabla => nº de filas]. */
function dbimp_load_sql(string $sql, PDO $out): array
{
    $defs = [];      // tabla => columnas
    $made = [];      // tabla => [nombre seguro, nº columnas]
    $counts = [];
    $out->beginTransaction();
    $ensure = function (string $table, int $n) use (&$defs, &$made, $out): string {
        $key = mb_strtolower($table);
        if (!isset($made[$key])) {
            $cols = $defs[$key] ?? [];
            for ($k = count($cols); $k < $n; $k++) {
                $cols[] = 'col' . ($k + 1);
            }
            $seen = [];
            foreach ($cols as &$c) {
                $c = dbimp_safe($c);
                while (isset($seen[mb_strtolower($c)])) {
                    $c .= '_';
                }
                $seen[mb_strtolower($c)] = true;
            }
            unset($c);
            $safe = dbimp_safe($table);
            $out->exec('DROP TABLE IF EXISTS "' . $safe . '"');
            $out->exec('CREATE TABLE "' . $safe . '" (' . implode(', ', array_map(fn($c) => '"' . $c . '" TEXT', $cols)) . ')');
            $made[$key] = [$safe, $cols];
        }
        return $made[$key][0];
    };
    dbimp_statements($sql, function (string $st) use (&$defs, &$made, &$counts, $ensure, $out): void {
        $st = ltrim($st);
        if (preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?((?:[`"\[]?[^\s(]+[`"\]]?\.)?[`"\[]?[^`"\]\s(]+[`"\]]?)\s*\(/i', $st, $m)) {
            $body = substr($st, strlen($m[0]));
            $body = substr($body, 0, (int)strrpos($body, ')'));
            $defs[mb_strtolower(dbimp_ident($m[1]))] = dbimp_create_columns($body);
            return;
        }
        if (!preg_match('/^(?:INSERT|REPLACE)\s+(?:IGNORE\s+)?(?:INTO\s+)?((?:[`"\[]?[^\s(]+[`"\]]?\.)?[`"\[]?[^`"\]\s(]+[`"\]]?)\s*(\(([^)]*)\))?\s*VALUES\s*/i', $st, $m)) {
            return;
        }
        $table = dbimp_ident($m[1]);
        $key = mb_strtolower($table);
        if (!empty($m[3]) && !isset($defs[$key])) {
            $defs[$key] = array_map('dbimp_ident', explode(',', $m[3]));
        }
        $rows = dbimp_tuples($st, strlen($m[0]));
        if (!$rows) {
            return;
        }
        $safe = $ensure($table, count($rows[0]));
        $cols = $made[$key][1];
        // INSERT con lista de columnas en otro orden que el CREATE TABLE
        $order = null;
        if (!empty($m[3])) {
            $listed = array_map(fn($c) => mb_strtolower(dbimp_safe(dbimp_ident($c))), explode(',', $m[3]));
            $order = [];
            foreach ($listed as $pos => $c) {
                $idx = array_search($c, array_map('mb_strtolower', $cols), true);
                $order[$pos] = $idx === false ? null : $idx;
            }
        }
        $ins = $out->prepare('INSERT INTO "' . $safe . '" VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        foreach ($rows as $r) {
            $vals = array_fill(0, count($cols), null);
            foreach ($r as $p => $v) {
                $t = $order === null ? $p : ($order[$p] ?? null);
                if ($t !== null && $t < count($cols)) {
                    $vals[$t] = $v;
                }
            }
            $ins->execute($vals);
        }
        $counts[$safe] = ($counts[$safe] ?? 0) + count($rows);
    });
    $out->commit();
    return $counts;
}

/** Copia todas las tablas de un .mdb / .accdb a la base temporal. */
function dbimp_load_mdb(string $file, PDO $out): array
{
    require_once dirname(__DIR__) . '/mdb.php';
    try {
        $mdb = new MdbReader($file);
        $tables = $mdb->tables();
    } catch (Throwable $e) {
        throw new RuntimeException('No se pudo leer la base de datos de Access: ' . ($e instanceof RuntimeException ? $e->getMessage() : 'formato no reconocido') . ' Si está protegida con contraseña, quítasela en Access antes.');
    }
    if (!$tables) {
        throw new RuntimeException('La base de datos de Access no tiene tablas (¿son tablas vinculadas a otro archivo? Sube ese otro archivo).');
    }
    $out->beginTransaction();
    foreach ($tables as $name => $pg) {
        $cols = [];
        $seen = [];
        foreach ($mdb->columns($pg) as $c) {
            $c = dbimp_safe($c);
            while (isset($seen[mb_strtolower($c)])) {
                $c .= '_';
            }
            $seen[mb_strtolower($c)] = true;
            $cols[] = $c;
        }
        $safe = dbimp_safe($name);
        $out->exec('DROP TABLE IF EXISTS "' . $safe . '"');
        $out->exec('CREATE TABLE "' . $safe . '" (' . implode(', ', array_map(fn($c) => '"' . $c . '" TEXT', $cols)) . ')');
        $ins = $out->prepare('INSERT INTO "' . $safe . '" VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
        foreach ($mdb->rowsByPage($pg) as $r) {
            $ins->execute(array_map(fn($v) => $v === null ? null : rtrim($v), array_values($r)));
        }
    }
    $out->commit();
    unset($mdb);
    return dbimp_tables($out);
}

/* ---------- Paso 1: subir la base de datos ---------- */

function dbimp_open(): PDO
{
    $pdo = new PDO('sqlite:' . IMPORT_DB_FILE, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}

/** Tablas de la base temporal con sus columnas y nº de filas. */
function dbimp_tables(PDO $db): array
{
    $out = [];
    foreach ($db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $cols = array_column($db->query('PRAGMA table_info("' . str_replace('"', '""', $t) . '")')->fetchAll(), 'name');
        $n = (int)$db->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $t) . '"')->fetchColumn();
        $out[$t] = ['cols' => $cols, 'rows' => $n];
    }
    return $out;
}

function dbimp_analyze(array $in): array
{
    $file = import_ftp_file($in['files']['db'] ?? null, DBIMP_EXTS);
    if (!$file) {
        throw new RuntimeException('Elige el archivo de la base de datos (o déjalo por FTP en data/importar/).');
    }
    $name = $in['names']['db'] ?: basename($file);
    $lname = strtolower($name);
    if (filesize($file) > DBIMP_MAX_BYTES) {
        throw new RuntimeException('La base de datos pesa más de 300 MB. Exporta solo las tablas de artículos, familias y fotos.');
    }
    $head = (string)file_get_contents($file, false, null, 0, 32);
    @unlink(IMPORT_DB_FILE);

    if (str_contains(substr($head, 4, 16), 'Standard Jet DB') || str_contains(substr($head, 4, 16), 'Standard ACE DB')) {
        @ini_set('memory_limit', '512M');
        $tables = dbimp_load_mdb($file, dbimp_open());
    } elseif (str_ends_with($lname, '.mdb') || str_ends_with($lname, '.accdb')) {
        throw new RuntimeException('Este archivo no es una base de datos de Access válida (¿está dañado o protegido con contraseña?).');
    } elseif (str_starts_with($head, "SQLite format 3\0")) {
        if (!copy($file, IMPORT_DB_FILE)) {
            throw new RuntimeException('No se pudo copiar la base de datos a data/ (¿permisos?).');
        }
        try {
            $tables = dbimp_tables(dbimp_open());
        } catch (Throwable $e) {
            throw new RuntimeException('El archivo SQLite está dañado o cifrado.');
        }
    } else {
        $sql = (string)file_get_contents($file);
        if (str_starts_with($sql, "\x1f\x8b")) {
            $sql = (string)@gzdecode($sql);
            if ($sql === '') {
                throw new RuntimeException('No se pudo descomprimir el archivo .gz.');
            }
        }
        if (str_starts_with($sql, "\xFF\xFE") || str_starts_with($sql, "\xFE\xFF")) {
            $sql = (string)mb_convert_encoding($sql, 'UTF-8', 'UTF-16'); // volcados de SQL Server
        }
        $sql = (string)preg_replace('/^\xEF\xBB\xBF/', '', $sql);
        if (!mb_check_encoding($sql, 'UTF-8')) {
            $sql = (string)mb_convert_encoding($sql, 'UTF-8', 'Windows-1252');
        }
        if (!preg_match('/\b(INSERT|CREATE\s+TABLE)\b/i', substr($sql, 0, 2 * 1024 * 1024) . substr($sql, -1024 * 1024))) {
            throw new RuntimeException('Este archivo no parece un volcado SQL (no tiene CREATE TABLE ni INSERT).');
        }
        @ini_set('memory_limit', '512M');
        $db = dbimp_open();
        dbimp_load_sql($sql, $db);
        unset($sql);
        $tables = dbimp_tables($db);
    }
    $tables = array_filter($tables, fn($t) => $t['rows'] > 0);
    // Columnas y tablas que no se leen nunca (p. ej. AMPLIACIOND en NCS): fuera desde ya
    $exclude = profiles_forced_excludes($tables);
    [$tables, $hidden] = profiles_strip($tables, $exclude);
    $tables = array_filter($tables, fn($t) => $t['cols'] !== []);
    if (!$tables) {
        @unlink(IMPORT_DB_FILE);
        throw new RuntimeException('La base de datos no tiene ninguna tabla con datos.');
    }
    $job = [
        'stage' => 'map',
        'origin' => $name,
        'source_type' => preg_match('/\.(mdb|accdb)$/', $lname) || str_contains(substr($head, 4, 16), 'Standard') ? 'mdb' : 'sql',
        'tables' => $tables,
        'exclude' => $exclude,
        'hidden' => $hidden,
        'map' => [],
        'msg' => count($tables) . ' tabla(s) con datos. Elige la tabla de artículos y sus columnas.',
    ];
    $job['map'] = dbimp_guess($tables, null);
    foreach (profiles_compatible($tables) as $p) {
        if ($p['is_system']) {
            $job = dbimp_apply_profile($job, $p);
            $job['msg'] = count($tables) . ' tabla(s) con datos. ' . $job['msg'];
            break;
        }
    }
    return $job;
}

/** Aplica un perfil al paso de columnas (el perfil manda: lo que no dice queda en «— ninguna —»). */
function dbimp_apply_profile(array $job, array $p): array
{
    $tables = $job['tables'];
    $mp = $p['mapping'];
    $table = profiles_table($tables, (string)($mp['table'] ?? ''));
    if ($table === null) {
        throw new RuntimeException('Esta base de datos no tiene la tabla «' . ($mp['table'] ?? '') . '» que usa el perfil.');
    }
    $created = profiles_ensure_fields((array)($p['rules']['fields'] ?? []));
    $prev = $job['map'] ?? [];
    $m = dbimp_guess($tables, $table);
    foreach ($m as $k => $v) {
        if (is_string($v) && $k !== 'table') {
            $m[$k] = '';
        }
    }
    $missing = [];
    $col = function (?string $t, string $c) use ($tables, &$missing): string {
        if ($c === '') {
            return '';
        }
        $r = $t !== null ? profiles_column($tables[$t]['cols'], $c) : null;
        if ($r === null) {
            $missing[] = $c;
        }
        return (string)$r;
    };
    foreach (['id', 'name', 'ref', 'family', 'sub', 'dims', 'desc', 'short', 'visible', 'sort', 'img'] as $k) {
        $m[$k] = $col($table, (string)($mp[$k] ?? ''));
    }
    foreach (['fam' => ['fam_id', 'fam_name', 'fam_parent'], 'img' => ['img_fk', 'img_file', 'img_sort']] as $g => $keys) {
        $t = profiles_table($tables, (string)($mp[$g . '_table'] ?? ''));
        if (($mp[$g . '_table'] ?? '') !== '' && $t === null) {
            $missing[] = (string)$mp[$g . '_table'];
        }
        $m[$g . '_table'] = (string)$t;
        foreach ($keys as $k) {
            $m[$k] = $t !== null ? $col($t, (string)($mp[$k] ?? '')) : '';
        }
    }
    $m['visible_inv'] = !empty($mp['visible_inv']) ? '1' : '';
    $m['caps'] = !empty($mp['caps']) ? '1' : '';
    $m['folders'] = !empty($mp['folders']) ? '1' : '';
    $m['extra'] = array_values(array_filter(array_map(fn($c) => $col($table, (string)$c), (array)($mp['extra'] ?? []))));
    $m['extra_labels'] = (string)($mp['extra_labels'] ?? '');
    $m['img_base'] = preg_match('#^https?://[^/\s]+#i', (string)($mp['img_base'] ?? '')) ? (string)$mp['img_base'] : '';
    foreach ((array)($mp['fields'] ?? []) as $ref => $c) {
        $f = profiles_field((string)$ref);
        if ($f) {
            $m['cf_' . $f['id']] = $col($table, (string)$c);
        } else {
            $missing[] = 'campo «' . $ref . '»';
        }
    }
    $m['mode'] = (string)($prev['mode'] ?? 'import');
    $m['upd_fields'] = null;
    $m['upd_visible'] = '';
    $m['profile'] = (int)$p['id'];
    $job['map'] = $m;
    $job['msg'] = 'Se ha aplicado el perfil «' . $p['name'] . '».'
        . ($created ? ' He creado estos campos que no existían: ' . implode(', ', $created) . ' (puedes cambiarlos en Web → Campos).' : '');
    $job['warnings'] = $missing ? ['El perfil usa cosas que no están en esta base de datos y se han dejado en «— ninguna —»: ' . implode(', ', array_unique($missing)) . '.'] : [];
    return $job;
}

/* ---------- Paso 2: emparejar columnas ---------- */

/** Propuesta automática de tabla y columnas. */
function dbimp_guess(array $tables, ?string $table): array
{
    if ($table === null || !isset($tables[$table])) {
        $best = null;
        $bestScore = -1;
        foreach ($tables as $t => $info) {
            $n = import_norm($t);
            $score = 0;
            if (preg_match('/(^| )(products?|productos?|articulos?|articles?|items?|arts?|catalogo|referencias|stock|mercancias)( |$)|^[tf]?art(icul\w*)?$/', $n)) {
                $score += 1000;
            }
            if (preg_match('/(lang|idioma|image|imagen|foto|photo|stock|price|precio|log|order|pedido|cart|user|cliente|customer)/', $n)) {
                $score -= 500;
            }
            $cm = import_map_columns($info['cols'], SHEET_COLUMNS);
            $score += isset($cm['name']) ? 300 : 0;
            $score += count($cm) * 20 + min(200, (int)log(max(1, $info['rows'])) * 20);
            if ($score > $bestScore) {
                $best = $t;
                $bestScore = $score;
            }
        }
        $table = (string)$best;
    }
    $cols = $tables[$table]['cols'];
    $cm = import_map_columns($cols, SHEET_COLUMNS + ['id' => ['id', 'id producto', 'id_product', 'idarticulo', 'id articulo', 'codigo interno', 'product id']]);
    $imgCols = function_exists('sheet_image_columns') ? sheet_image_columns($cols) : [];
    $m = ['table' => $table];
    foreach (['id', 'name', 'ref', 'family', 'sub', 'dims', 'desc', 'short', 'visible', 'sort'] as $k) {
        $m[$k] = isset($cm[$k]) ? $cols[$cm[$k]] : '';
    }
    // Descartar como nombre una columna que casi no varía (p. ej. «PRODUCTO» = SI/NO)
    if ($m['name'] !== '') {
        try {
            $qn = '"' . str_replace('"', '""', $m['name']) . '"';
            $distinct = (int)dbimp_open()->query('SELECT COUNT(DISTINCT ' . $qn . ') FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
            if ($distinct <= max(3, (int)($tables[$table]['rows'] * 0.05))) {
                $m['name'] = '';
            }
        } catch (Throwable $e) {
        }
    }
    // En muchos programas de gestión el nombre del artículo está en «Descripción»
    if ($m['name'] === '' && $m['desc'] !== '') {
        $m['name'] = $m['desc'];
        $m['desc'] = '';
    }
    // Columna de familia con otro nombre (id_category_default, cod_familia, idfamilia…)
    if ($m['family'] === '') {
        foreach ($cols as $c) {
            if (preg_match('/categor|famil/', import_norm($c))) {
                $m['family'] = $c;
                break;
            }
        }
    }
    $m['visible_inv'] = '';
    if ($m['visible'] === '') {
        $inv = import_map_columns($cols, ['x' => ['bloqueado', 'baja', 'de baja', 'inactivo', 'descatalogado', 'obsoleto', 'oculto', 'discontinued', 'blocked', 'deleted', 'borrado']]);
        if (isset($inv['x'])) {
            $m['visible'] = $cols[$inv['x']];
            $m['visible_inv'] = '1';
        }
    }
    // Campos del catálogo: columna con el mismo nombre (o alias habituales, p. ej. PVP para el precio)
    foreach (fields_all() as $f) {
        $m['cf_' . $f['id']] = '';
        foreach ($cols as $c) {
            $cf = field_for_column($c);
            if ($cf && $cf['id'] === $f['id'] && !in_array($c, [$m['name'], $m['ref'], $m['desc']], true) && dbimp_varies($table, $c, $tables[$table]['rows'], $f['kind'])) {
                $m['cf_' . $f['id']] = $c;
                break;
            }
        }
    }
    $m['extra'] = [];
    $m['extra_labels'] = '';
    $m['caps'] = '1';
    if ($m['id'] === '' && in_array('id', array_map('strtolower', $cols), true)) {
        $m['id'] = $cols[array_search('id', array_map('strtolower', $cols), true)];
    }
    $m['img'] = $imgCols ? $cols[$imgCols[0]] : '';
    // Familia guardada como número de otra tabla (id_categoria → categorias.id)
    $m['fam_table'] = $m['fam_id'] = $m['fam_name'] = $m['fam_parent'] = '';
    foreach ($tables as $t => $info) {
        if ($t !== $table && preg_match('/categor|famil|grupo|seccion|^[tf]?(fam|cat|fami|grp|sec|tipos?)$/', import_norm($t)) && !preg_match('/lang|product|articul/', import_norm($t))) {
            $tc = import_map_columns($info['cols'], ['id' => ['id', 'codigo', 'code', 'id categoria', 'id_category', 'idfamilia', 'id familia', 'idcategoria'], 'name' => SHEET_COLUMNS['name'] + [99 => 'descripcion', 100 => 'familia', 101 => 'categoria'], 'parent' => ['parent', 'parent id', 'id parent', 'padre', 'id padre', 'idpadre', 'parent_id']]);
            if (isset($tc['id'], $tc['name'])) {
                $m['fam_table'] = $t;
                $m['fam_id'] = $info['cols'][$tc['id']];
                $m['fam_name'] = $info['cols'][$tc['name']];
                $m['fam_parent'] = isset($tc['parent']) ? $info['cols'][$tc['parent']] : '';
                break;
            }
        }
    }
    // Fotos en otra tabla (imagenes.id_producto → archivo)
    $m['img_table'] = $m['img_fk'] = $m['img_file'] = $m['img_sort'] = '';
    foreach ($tables as $t => $info) {
        if ($t !== $table && preg_match('/(imag|foto|photo|picture|media)/', import_norm($t))) {
            $tc = import_map_columns($info['cols'], [
                'fk' => ['id producto', 'id_product', 'product id', 'idarticulo', 'id articulo', 'articulo', 'producto', 'product', 'idproducto'],
                'file' => ['archivo', 'fichero', 'file', 'filename', 'imagen', 'image', 'foto', 'url', 'ruta', 'path', 'src', 'nombre'],
                'sort' => ['orden', 'position', 'posicion', 'sort', 'order'],
            ]);
            if (isset($tc['fk'], $tc['file'])) {
                $m['img_table'] = $t;
                $m['img_fk'] = $info['cols'][$tc['fk']];
                $m['img_file'] = $info['cols'][$tc['file']];
                $m['img_sort'] = isset($tc['sort']) ? $info['cols'][$tc['sort']] : '';
                break;
            }
        }
    }
    $m['img_base'] = '';
    $m['folders'] = '';       // '1' = fotos por carpeta ARTICULO_<código> si la columna de foto está vacía
    $m['mode'] = 'import';        // 'import' (crear y rellenar huecos) o 'update' (actualizar precios y stock)
    $m['upd_fields'] = null;      // ids de campos a actualizar; null = aún sin elegir (se proponen precio y números)
    $m['upd_visible'] = '';       // '1' = actualizar también la visibilidad
    $m['profile'] = 0;            // perfil aplicado
    return $m;
}

/** ¿La columna tiene datos que sirvan para un campo? (descarta columnas casi vacías o de marcas 0/1 para textos) */
function dbimp_varies(string $table, string $col, int $rows, string $kind): bool
{
    try {
        $q = '"' . str_replace('"', '""', $col) . '"';
        $d = (int)dbimp_open()->query('SELECT COUNT(DISTINCT ' . $q . ') FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
    } catch (Throwable $e) {
        return true;
    }
    return in_array($kind, ['bool', 'select'], true) ? $d >= 2 : $d > max(3, (int)($rows * 0.002));
}

/** Formulario del paso 2 (HTML). */
function dbimp_map_form(array $job): string
{
    $tables = $job['tables'];
    $m = $job['map'];
    $t = $m['table'];
    $sel = function (string $name, array $opts, string $cur, string $empty = '— ninguna —'): string {
        $h = '<select name="m[' . e($name) . ']"><option value="">' . e($empty) . '</option>';
        foreach ($opts as $v => $label) {
            $h .= '<option value="' . e((string)$v) . '"' . ((string)$v === $cur ? ' selected' : '') . '>' . e($label) . '</option>';
        }
        return $h . '</select>';
    };
    $colOpts = array_combine($tables[$t]['cols'], $tables[$t]['cols']);
    $tabOpts = [];
    foreach ($tables as $tn => $info) {
        $tabOpts[$tn] = $tn . ' (' . $info['rows'] . ' filas)';
    }
    $allCols = function (string $table) use ($tables): array {
        return $table !== '' && isset($tables[$table]) ? array_combine($tables[$table]['cols'], $tables[$table]['cols']) : [];
    };
    // Vista previa de la tabla elegida (solo columnas permitidas: nunca las prohibidas por un perfil)
    $preview = '';
    try {
        $qc = implode(', ', array_map(fn($c) => '"' . str_replace('"', '""', $c) . '"', $tables[$t]['cols']));
        $rows = dbimp_open()->query('SELECT ' . $qc . ' FROM "' . str_replace('"', '""', $t) . '" LIMIT 4')->fetchAll();
        if ($rows) {
            $preview = '<div class="dbprev"><table><tr>';
            foreach (array_keys($rows[0]) as $c) {
                $preview .= '<th>' . e($c) . '</th>';
            }
            $preview .= '</tr>';
            foreach ($rows as $r) {
                $preview .= '<tr>';
                foreach ($r as $v) {
                    $preview .= '<td>' . e(mb_strimwidth((string)$v, 0, 60, '…')) . '</td>';
                }
                $preview .= '</tr>';
            }
            $preview .= '</table></div>';
        }
    } catch (Throwable $e) {
    }
    $f = fn(string $k, string $label, string $help = '') => '<label>' . e($label) . ' ' . $sel($k, $colOpts, (string)($m[$k] ?? '')) . ($help !== '' ? '<small>' . e($help) . '</small>' : '') . '</label>';

    // Perfiles que encajan con esta base de datos
    $profiles = profiles_compatible($tables);
    $curProfile = (int)($m['profile'] ?? 0);
    $profOpts = '';
    foreach ($profiles as $pid => $p) {
        $profOpts .= '<option value="' . $pid . '"' . ($pid === $curProfile ? ' selected' : '') . '>' . e($p['name']) . ($p['is_system'] ? ' · sistema' : '') . '</option>';
    }
    $mode = ($m['mode'] ?? 'import') === 'update' ? 'update' : 'import';
    $updFields = $m['upd_fields'] ?? null;
    $updBoxes = '';
    foreach (fields_all() as $cf) {
        $on = $updFields === null ? in_array($cf['kind'], ['price', 'number'], true) : in_array($cf['id'], array_map('intval', (array)$updFields), true);
        $updBoxes .= '<label class="check"><input type="checkbox" name="m[upd_fields][]" value="' . $cf['id'] . '"' . ($on ? ' checked' : '') . '> <span>' . e($cf['name']) . ($cf['is_public'] ? '' : ' (interno)')
            . (($m['cf_' . $cf['id']] ?? '') !== '' ? ' <small class="muted">← ' . e((string)$m['cf_' . $cf['id']]) . '</small>' : ' <small class="muted">(sin columna asignada)</small>') . '</span></label>';
    }
    $visCol = (string)($m['visible'] ?? '');

    $h = '<p class="hint">Base de datos <strong>' . e($job['origin']) . '</strong>. Elige la tabla donde están los artículos y qué columna es cada cosa. Lo que no exista, déjalo en «— ninguna —».</p>'
        . '<form method="post" enctype="multipart/form-data" class="form">' . csrf_field()
        . ($profiles ? '<fieldset><legend>Perfil de importación</legend><div class="row-inline"><label class="grow">Usar perfil <select name="profile">' . $profOpts . '</select></label>'
            . '<button class="btn small ghost" name="sub" value="profile_apply" type="submit">Aplicar</button>'
            . (isset($profiles[$curProfile]) && !$profiles[$curProfile]['is_system'] ? ' <button class="btn small ghost" name="sub" value="profile_delete" type="submit" onclick="return confirm(\'¿Borrar este perfil?\')">Borrar perfil</button>' : '')
            . '</div><small>Un perfil rellena solo todas las columnas de abajo. Después puedes cambiar lo que quieras.</small></fieldset>' : '')
        . (!empty($job['hidden']) ? '<p class="muted">Por seguridad, estas columnas no se leen nunca: ' . e(implode(', ', $job['hidden'])) . '.</p>' : '')
        . '<fieldset><legend>Qué hacer</legend>'
        . '<label class="check"><input type="radio" name="m[mode]" value="import"' . ($mode === 'import' ? ' checked' : '') . '> <span><b>Importar artículos</b>: crea los nuevos y rellena los huecos de los que ya existen (no cambia nada que ya tenga valor).</span></label>'
        . '<label class="check"><input type="radio" name="m[mode]" value="update"' . ($mode === 'update' ? ' checked' : '') . '> <span><b>Actualizar precios y stock</b>: busca cada artículo por su <b>referencia</b> y cambia solo los campos marcados, aunque ya tengan valor. No crea ni borra artículos, ni toca nombre, descripción, familia ni fotos.</span></label>'
        . '<div class="upd-opts"><small>Solo para «Actualizar»: campos que se actualizan</small>'
        . ($updBoxes !== '' ? $updBoxes : '<small>No hay campos del catálogo. Créalos en Web → Campos.</small>')
        . '<label class="check"><input type="checkbox" name="m[upd_visible]" value="1"' . (($m['upd_visible'] ?? '') === '1' ? ' checked' : '') . '> <span>Actualizar también la visibilidad' . ($visCol !== '' ? ' (' . e($visCol) . ')' : ' <small class="muted">(sin columna de visible)</small>') . '</span></label>'
        . '<small>Si en el origen un valor está vacío, se deja el que hay en la web. Un precio 0 cuenta como vacío; un stock 0 sí se actualiza.</small></div>'
        . '</fieldset>'
        . '<h2 style="margin:0">1. Tabla de artículos</h2>'
        . '<div class="row-inline"><label class="grow">Tabla ' . str_replace('<option value="">— ninguna —</option>', '', $sel('table', $tabOpts, $t)) . '</label>'
        . '<button class="btn small ghost" name="sub" value="table" type="submit">Ver sus columnas</button></div>'
        . $preview
        . '<h2>2. Columnas del artículo</h2>'
        . '<div class="two">' . $f('name', 'Nombre *') . $f('ref', 'Referencia / código') . '</div>'
        . '<div class="two">' . $f('desc', 'Descripción') . $f('short', 'Descripción corta', 'Se usa si no hay descripción.') . '</div>'
        . '<div class="two">' . $f('dims', 'Medidas') . $f('visible', 'Visible / activo', '0, no, inactivo… = oculto.') . '</div>'
        . '<div class="two">' . $f('sort', 'Orden') . $f('id', 'Identificador (id)', 'Hace falta si las fotos están en otra tabla.') . '</div>'
        . '<label class="check"><input type="checkbox" name="m[visible_inv]" value="1"' . (($m['visible_inv'] ?? '') === '1' ? ' checked' : '') . '> <span>La columna de visible está al revés (por ejemplo «Bloqueado» o «De baja»): sí = oculto</span></label>'
        . '<label>Añadir a la descripción <select name="m[extra][]" multiple size="6">' . implode('', array_map(fn($c) => '<option value="' . e($c) . '"' . (in_array($c, (array)($m['extra'] ?? []), true) ? ' selected' : '') . '>' . e($c) . '</option>', $tables[$t]['cols'])) . '</select>'
        . '<small>Columnas que se añaden al final de la descripción como «Columna: valor» (tallas, colores, material…). Ctrl o Cmd + clic para elegir varias.</small></label>'
        . '<label>Nombres para esas columnas <input name="m[extra_labels]" value="' . e((string)($m['extra_labels'] ?? '')) . '" placeholder="Tallas, Color"><small>Separados por comas, en el mismo orden. Si lo dejas vacío se usa el nombre de la columna.</small></label>'
        . '<label class="check"><input type="checkbox" name="m[caps]" value="1"' . (($m['caps'] ?? '') === '1' ? ' checked' : '') . '> <span>Pasar los nombres escritos TODO EN MAYÚSCULAS a minúsculas («CHANCLA DE PLAYA» → «Chancla de playa»)</span></label>'
        . (fields_all() ? '<h2>Campos del catálogo</h2><div class="two">' . implode('', array_map(fn($cf) => $f('cf_' . $cf['id'], $cf['name'] . ($cf['is_public'] ? '' : ' (interno)'), FIELD_KINDS[$cf['kind']][0] . ($cf['unit'] !== '' ? ' · ' . $cf['unit'] : '')), fields_all())) . '</div>' : '')
        . '<h2>3. Familia</h2>'
        . '<div class="two">' . $f('family', 'Columna de la familia', 'El nombre de la familia o su número (id).') . $f('sub', 'Columna de la subfamilia') . '</div>'
        . '<fieldset><legend>Si la familia es un número que apunta a otra tabla</legend>'
        . '<div class="two"><label>Tabla de familias ' . $sel('fam_table', $tabOpts, (string)$m['fam_table']) . '</label>'
        . '<label>Su columna id ' . $sel('fam_id', $allCols((string)$m['fam_table']), (string)$m['fam_id']) . '</label></div>'
        . '<div class="two"><label>Su columna con el nombre ' . $sel('fam_name', $allCols((string)$m['fam_table']), (string)$m['fam_name']) . '</label>'
        . '<label>Columna de la familia padre (opcional) ' . $sel('fam_parent', $allCols((string)$m['fam_table']), (string)$m['fam_parent']) . '<small>Si las familias tienen subfamilias en la misma tabla.</small></label></div>'
        . '<small>Si cambias la tabla de familias, pulsa «Ver sus columnas» para elegir las columnas.</small></fieldset>'
        . '<h2>4. Fotos</h2>'
        . '<div class="two">' . $f('img', 'Columna con la foto', 'Dirección (https://…) o nombre de archivo. Varias separadas por | o ;') . ''
        . '<label>Dirección base de las fotos <input name="m[img_base]" value="' . e((string)$m['img_base']) . '" placeholder="https://mi-web-antigua.com/fotos/"><small>Si la base de datos guarda solo el nombre del archivo (silla.jpg) y las fotos están publicadas en una web.</small></label></div>'
        . '<fieldset><legend>Si las fotos están en otra tabla</legend>'
        . '<div class="two"><label>Tabla de fotos ' . $sel('img_table', $tabOpts, (string)$m['img_table']) . '</label>'
        . '<label>Su columna con el id del artículo ' . $sel('img_fk', $allCols((string)$m['img_table']), (string)$m['img_fk']) . '</label></div>'
        . '<div class="two"><label>Su columna con la foto ' . $sel('img_file', $allCols((string)$m['img_table']), (string)$m['img_file']) . '</label>'
        . '<label>Su columna de orden (opcional) ' . $sel('img_sort', $allCols((string)$m['img_table']), (string)$m['img_sort']) . '</label></div></fieldset>'
        . '<label class="check"><input type="checkbox" name="m[folders]" value="1"' . (($m['folders'] ?? '') === '1' ? ' checked' : '') . '> <span>Fotos por carpeta de artículo (<code>ARTICULO_&lt;código&gt;</code>): si la columna de la foto está vacía, se usan las fotos de la carpeta con su referencia (del ZIP o de una carpeta en data/importar/). Sin los GIF.</span></label>'
        . '<label>Opcional: ZIP con las fotos <input type="file" name="zip" accept=".zip,application/zip"><small>Si las fotos son nombres de archivo y no hay dirección base. Si pesa mucho, déjalo por FTP en data/importar/.</small></label>'
        . '<input type="hidden" name="action" value="map">'
        . '<fieldset><legend>Guardar como perfil</legend><div class="row-inline"><label class="grow">Nombre del perfil <input name="profile_name" maxlength="120" value="' . e(isset($profiles[$curProfile]) && !$profiles[$curProfile]['is_system'] ? $profiles[$curProfile]['name'] : '') . '" placeholder="Por ejemplo: Almacén de mi programa"></label>'
        . '<button class="btn small ghost" name="sub" value="profile_save" type="submit">Guardar como perfil</button></div>'
        . '<small>Guarda la tabla y las columnas elegidas para usarlas la próxima vez. Si ya tienes un perfil con ese nombre, se sustituye.</small></fieldset>'
        . '<div class="buttons"><button class="btn small ghost" name="sub" value="table" type="submit">Ver sus columnas</button> <button class="btn" name="sub" value="go" type="submit">Analizar</button></div>'
        . '</form>';
    return $h;
}

/** Recibe el formulario del paso 2: o recarga columnas, o construye el plan. */
function dbimp_map(array $job, array $post, array $files): array
{
    if (!is_file(IMPORT_DB_FILE)) {
        throw new RuntimeException('La base de datos temporal ya no está. Vuelve a subir el archivo.');
    }
    $tables = $job['tables'];
    $sub = (string)($post['sub'] ?? '');
    $job['warnings'] = [];

    // Aplicar un perfil: sustituye todo lo elegido
    if ($sub === 'profile_apply') {
        $p = profiles_compatible($tables)[(int)($post['profile'] ?? 0)] ?? null;
        if (!$p) {
            throw new RuntimeException('Ese perfil no vale para esta base de datos (le faltan tablas).');
        }
        return dbimp_apply_profile($job, $p);
    }
    if ($sub === 'profile_delete') {
        $name = profiles_delete((int)($post['profile'] ?? 0));
        $job['map']['profile'] = 0;
        $job['msg'] = 'Perfil «' . $name . '» borrado.';
        return $job;
    }

    $in = (array)($post['m'] ?? []);
    $m = $job['map'];
    $newTable = (string)($in['table'] ?? $m['table']);
    if (!isset($tables[$newTable])) {
        $newTable = $m['table'];
    }
    if ($newTable !== $m['table']) {
        $keep = ['mode' => $in['mode'] ?? $m['mode'] ?? 'import', 'profile' => $m['profile'] ?? 0];
        $m = array_merge(dbimp_guess($tables, $newTable), $keep); // otra tabla: nueva propuesta de columnas
    } else {
        foreach ($m as $k => $_) {
            if (in_array($k, ['table', 'extra', 'visible_inv', 'caps', 'folders', 'upd_fields', 'upd_visible', 'profile'], true)) {
                continue;
            }
            if (array_key_exists($k, $in) && !is_array($in[$k])) {
                $m[$k] = trim((string)$in[$k]);
            }
        }
        foreach (array_keys(fields_all()) as $fid) {
            $m['cf_' . $fid] = trim((string)($in['cf_' . $fid] ?? ($m['cf_' . $fid] ?? '')));
        }
        $m['extra'] = array_values(array_intersect((array)($in['extra'] ?? []), $tables[$m['table']]['cols']));
        $m['visible_inv'] = empty($in['visible_inv']) ? '' : '1';
        $m['caps'] = empty($in['caps']) ? '' : '1';
        $m['folders'] = empty($in['folders']) ? '' : '1';
        if (array_key_exists('mode', $in)) { // el formulario siempre envía el modo junto a sus casillas
            $m['upd_fields'] = array_values(array_intersect(array_map('intval', (array)($in['upd_fields'] ?? [])), array_keys(fields_all())));
            $m['upd_visible'] = empty($in['upd_visible']) ? '' : '1';
        }
    }
    $m['mode'] = ($m['mode'] ?? '') === 'update' ? 'update' : 'import';
    // Validar que cada columna existe en su tabla
    $chk = function (string $table, array $keys) use (&$m, $tables): void {
        foreach ($keys as $k) {
            if ($m[$k] !== '' && ($table === '' || !in_array($m[$k], $tables[$table]['cols'] ?? [], true))) {
                $m[$k] = '';
            }
        }
    };
    foreach (['fam_table', 'img_table'] as $tk) {
        if ($m[$tk] !== '' && !isset($tables[$m[$tk]])) {
            $m[$tk] = '';
        }
    }
    $chk($m['table'], array_merge(['id', 'name', 'ref', 'family', 'sub', 'dims', 'desc', 'short', 'visible', 'sort', 'img'], array_map(fn($id) => 'cf_' . $id, array_keys(fields_all()))));
    $chk($m['fam_table'], ['fam_id', 'fam_name', 'fam_parent']);
    $chk($m['img_table'], ['img_fk', 'img_file', 'img_sort']);
    if ($m['img_base'] !== '' && !preg_match('#^https?://[^/\s]+#i', $m['img_base'])) {
        $m['img_base'] = '';
    }
    $job['map'] = $m;
    if ($sub === 'profile_save') {
        $pname = trim(mb_scrub((string)($post['profile_name'] ?? ''), 'UTF-8'));
        $id = dbimp_save_profile($job, $pname);
        $job['map']['profile'] = $id;
        $job['msg'] = 'Perfil «' . $pname . '» guardado. Aparecerá en «Usar perfil» cuando subas una base de datos con las mismas tablas.';
        return $job;
    }
    if ($sub !== 'go') {
        $job['msg'] = '';
        return $job;
    }
    if ($m['mode'] === 'update') {
        return dbimp_build_update($job);
    }
    if ($m['name'] === '') {
        $job['warnings'] = ['Elige la columna del nombre.'];
        return $job;
    }
    if (($m['fam_table'] !== '' || $m['img_table'] !== '') && $m['id'] === '' && $m['img_table'] !== '') {
        $job['warnings'] = ['Para las fotos de otra tabla hace falta la columna «Identificador (id)» del artículo.'];
        return $job;
    }
    return dbimp_build_plan($job, $files['zip'] ?? null);
}

/** Paso 3: plan de importación a partir del emparejamiento. */
function dbimp_build_plan(array $job, ?array $zipUpload): array
{
    $m = $job['map'];
    $db = dbimp_open();
    $q = fn(string $s) => '"' . str_replace('"', '""', $s) . '"';
    $warnings = [];

    // Familias de otra tabla: id => [nombre, id padre]
    $fams = [];
    if ($m['fam_table'] !== '' && $m['fam_id'] !== '' && $m['fam_name'] !== '') {
        foreach ($db->query('SELECT ' . $q($m['fam_id']) . ' AS i, ' . $q($m['fam_name']) . ' AS n' . ($m['fam_parent'] !== '' ? ', ' . $q($m['fam_parent']) . ' AS p' : '') . ' FROM ' . $q($m['fam_table'])) as $r) {
            $fams[trim((string)$r['i'])] = [trim(html_entity_decode(strip_tags((string)$r['n']), ENT_QUOTES | ENT_HTML5, 'UTF-8')), trim((string)($r['p'] ?? ''))];
        }
    }
    $caps = dbimp_caps($m);
    $xLabels = array_map('trim', explode(',', (string)($m['extra_labels'] ?? '')));
    $famPath = function (string $v) use ($fams): array {
        $v = trim($v);
        if ($v === '' || !$fams) {
            return $v === '' ? [] : [$v];
        }
        if (!isset($fams[$v])) {
            return [];
        }
        $path = [$fams[$v][0]];
        $seen = [$v => true];
        $p = $fams[$v][1];
        // Sube por los padres (se omiten raíces genéricas tipo «Inicio» / «Root»)
        while ($p !== '' && $p !== '0' && isset($fams[$p]) && !isset($seen[$p])) {
            $seen[$p] = true;
            array_unshift($path, $fams[$p][0]);
            $p = $fams[$p][1];
        }
        $path = array_values(array_filter($path, fn($n) => $n !== '' && !preg_match('/^(root|ra[ií]z|inicio|home|todas?|all)$/iu', $n)));
        return array_slice($path, -2); // familia › subfamilia
    };

    // Fotos de otra tabla: id artículo => [archivos]
    $imgsBy = [];
    if ($m['img_table'] !== '' && $m['img_fk'] !== '' && $m['img_file'] !== '') {
        $sql = 'SELECT ' . $q($m['img_fk']) . ' AS k, ' . $q($m['img_file']) . ' AS f FROM ' . $q($m['img_table'])
            . ($m['img_sort'] !== '' ? ' ORDER BY CAST(' . $q($m['img_sort']) . ' AS REAL)' : '');
        foreach ($db->query($sql) as $r) {
            if (trim((string)$r['f']) !== '') {
                $imgsBy[trim((string)$r['k'])][] = trim((string)$r['f']);
            }
        }
    }

    // ZIP de fotos (opcional)
    $zipMap = [];
    $uploaded = ($zipUpload && ($zipUpload['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file((string)$zipUpload['tmp_name'])) ? (string)$zipUpload['tmp_name'] : null;

    // Fotos por carpeta ARTICULO_<código> (se usan cuando la columna de la foto está vacía)
    $pfSrc = null;
    $pfIdx = null;
    $pfZip = null;
    $pfUsed = 0;
    if (($m['folders'] ?? '') === '1') {
        $pfSrc = pf_find_source($uploaded && pf_zip_has_folders($uploaded) ? $uploaded : null);
        if ($pfSrc) {
            $pfIdx = pf_index(array_map(fn($f) => [$f['code'], $f], pf_scan($pfSrc)));
        } else {
            $warnings[] = 'Has marcado «Fotos por carpeta de artículo» pero no hay ningún ZIP ni carpeta con carpetas ARTICULO_<código> (súbelo o déjalo por FTP en data/importar/).';
        }
    }
    // ZIP de fotos sueltas (las de la columna de foto), si no es el de las carpetas
    $zipPath = $uploaded ?? import_zip_input(null);
    if ($zipPath && !($pfSrc && $pfSrc['type'] === 'zip' && realpath($pfSrc['path']) === realpath($zipPath))) {
        $zipMap = import_extract_zip($zipPath);
    }
    if ($pfSrc) {
        $pfZip = pf_open($pfSrc); // después de import_extract_zip, que vacía data/import_files/
    }

    $idx = import_existing_index();
    $items = [];
    $hosts = [];
    $missing = [];
    $noFam = 0;
    $order = $m['sort'] !== '' ? ' ORDER BY CAST(' . $q($m['sort']) . ' AS REAL)' : '';
    $n = 0;
    foreach ($db->query(dbimp_select($job, $m) . $order) as $r) {
        if (++$n > 20000) {
            $warnings[] = 'Solo se leen los primeros 20 000 artículos.';
            break;
        }
        $get = fn(string $k) => $m[$k] !== '' ? trim((string)($r[$m[$k]] ?? '')) : '';
        $name = $caps(trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($get('name')), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        if ($name === '') {
            continue;
        }
        $path = $famPath($get('family'));
        if ($get('sub') !== '') {
            $sp = $famPath($get('sub'));
            $path = array_slice(array_merge(array_slice($path, 0, 1), array_slice($sp, -1)), 0, 2);
        }
        $path = array_map($caps, $path);
        $desc = import_csv_clean_text($get('desc'));
        if ($desc === '') {
            $desc = import_csv_clean_text($get('short'));
        }
        $dims = $get('dims');
        $dims = $dims !== '' ? (import_dims($dims) ?: $dims) : import_dims($desc);
        $extra = [];
        foreach ($m['extra'] as $xi => $xc) {
            $xv = trim((string)preg_replace('/\s{2,}/u', ', ', trim((string)($r[$xc] ?? ''))));
            if ($xv !== '' && $xv !== '0') {
                $label = $xLabels[$xi] ?? '';
                $extra[] = ($label !== '' ? $label : $caps(mb_convert_case(mb_strtolower($xc), MB_CASE_TITLE))) . ': ' . $caps($xv);
            }
        }
        if ($extra) {
            $desc = trim($desc . "\n\n" . implode("\n", $extra));
        }
        $visible = import_bool($get('visible'));
        if ($m['visible'] !== '' && $m['visible_inv'] === '1') {
            $visible = $get('visible') === '' ? 1 : 1 - import_bool($get('visible'));
        }

        $files = [];
        if ($m['img'] !== '') {
            $files = array_merge($files, preg_split('/\s*[|;\n]\s*|\s*,\s+/u', $get('img')) ?: []);
        }
        if ($m['id'] !== '' && isset($imgsBy[$get('id')])) {
            $files = array_merge($files, $imgsBy[$get('id')]);
        }
        $images = [];
        $seen = [];
        foreach ($files as $v) {
            $v = trim($v, " \t\"'");
            if ($v === '' || isset($seen[mb_strtolower($v)])) {
                continue;
            }
            $seen[mb_strtolower($v)] = true;
            $b = mb_strtolower(basename(str_replace('\\', '/', $v)));
            if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $b) && !preg_match('#^https?://#i', $v)) {
                continue;
            }
            if (isset($zipMap[$b])) {
                $images[] = ['key' => 'zip:' . $b, 'file' => $zipMap[$b], 'label' => basename(str_replace('\\', '/', $v))];
                continue;
            }
            $url = preg_match('#^https?://#i', $v) ? $v : ($m['img_base'] !== '' ? rtrim($m['img_base'], '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim(str_replace('\\', '/', $v), '/')))) : '');
            if ($url === '') {
                $missing[$b] = basename(str_replace('\\', '/', $v));
                continue;
            }
            $h = strtolower((string)parse_url($url, PHP_URL_HOST));
            if ($h !== '') {
                $hosts[$h] = true;
                $images[] = ['key' => $url, 'urls' => [$url]];
            }
        }
        if ($pfIdx && $get('img') === '' && $get('ref') !== '') {
            [$folder] = pf_lookup($pfIdx, $get('ref'));
            if ($folder) {
                $more = pf_images($folder, false, $pfZip);
                if ($more) {
                    $images = array_merge($images, $more);
                    $pfUsed++;
                }
            }
        }
        $fv = [];
        foreach (fields_all() as $cf) {
            $k = 'cf_' . $cf['id'];
            if (($m[$k] ?? '') !== '') {
                $fv[$cf['id']] = dbimp_cf_value($cf, (string)($r[$m[$k]] ?? ''), $caps);
            }
        }
        $item = import_plan_item(import_existing_find($idx, $name, $get('ref')), $name, $path, $get('ref'), $dims, $desc, $images, $visible, $fv);
        if (!$item['pid'] && !$item['cat_id'] && !$item['cat_path']) {
            $noFam++;
        }
        $key = mb_strtolower($get('ref') !== '' ? 'r:' . $get('ref') : 'n:' . $name . '|' . implode('>', $path));
        $items[$key] ??= $item; // filas repetidas: la primera
    }
    if (!$items) {
        $job['warnings'] = ['La tabla «' . $m['table'] . '» no tiene filas con nombre en la columna «' . $m['name'] . '».'];
        return $job;
    }
    if ($noFam) {
        $warnings[] = $noFam . ' artículo(s) sin familia: no se importarán. Revisa la columna de familia.';
    }
    $pfZip?->close();
    if ($pfSrc) {
        array_unshift($warnings, 'Fotos por carpeta (' . $pfSrc['label'] . '): ' . $pfUsed . ' artículo(s) sin foto en la columna toman las de su carpeta ARTICULO_<código>.');
    }
    if ($missing) {
        $warnings[] = count($missing) . ' foto(s) son nombres de archivo sin ZIP ni dirección base: ' . mb_strimwidth(implode(', ', array_values($missing)), 0, 300, '…');
    }
    $allowed = [];
    foreach (array_keys($hosts) as $h) {
        $allowed = array_merge($allowed, import_hosts('https://' . $h));
    }
    return [
        'origin' => $job['origin'] . ' · tabla ' . $m['table'],
        'allowed' => array_values(array_unique($allowed)),
        'items' => array_values($items),
        'warnings' => $warnings,
    ];
}

/**
 * SELECT solo con las columnas elegidas en el emparejamiento (nunca SELECT *: así una columna
 * prohibida o que no se ha elegido no se lee jamás). Solo se aceptan columnas permitidas de la tabla.
 */
function dbimp_select(array $job, array $m, ?array $keys = null, bool $withExtra = true): string
{
    $cols = $job['tables'][$m['table']]['cols'] ?? [];
    $keys ??= array_merge(['id', 'name', 'ref', 'family', 'sub', 'dims', 'desc', 'short', 'visible', 'sort', 'img'], array_map(fn($id) => 'cf_' . $id, array_keys(fields_all())));
    $want = [];
    foreach ($keys as $k) {
        $c = (string)($m[$k] ?? '');
        if ($c !== '' && in_array($c, $cols, true)) {
            $want[$c] = true;
        }
    }
    if ($withExtra) {
        foreach ((array)($m['extra'] ?? []) as $c) {
            if (in_array($c, $cols, true)) {
                $want[$c] = true;
            }
        }
    }
    if (!$want && $cols) {
        $want[$cols[0]] = true;
    }
    $q = fn(string $s) => '"' . str_replace('"', '""', $s) . '"';
    return 'SELECT ' . implode(', ', array_map($q, array_keys($want))) . ' FROM ' . $q($m['table']);
}

/** Valor de un campo libre leído de la base de datos, ya normalizado ('' = vacío). Un precio 0 cuenta como vacío. */
function dbimp_cf_value(array $cf, string $raw, callable $caps): string
{
    $v = trim((string)preg_replace('/\s{2,}/u', ', ', trim($raw)));
    if (in_array($cf['kind'], ['multi', 'select'], true)) {
        $v = str_replace(', ', '|', $v);
    } elseif (in_array($cf['kind'], ['text', 'textarea'], true)) {
        $v = $caps($v);
    }
    $v = field_clean($cf, $v);
    if ($cf['kind'] === 'price' && $v !== '' && (float)$v == 0.0) {
        return ''; // nunca «0,00 €»: un precio 0 en el programa de gestión es «sin precio»
    }
    return $v;
}

/** Función de mayúsculas del emparejamiento («CHANCLA DE PLAYA» → «Chancla de playa»). */
function dbimp_caps(array $m): callable
{
    return fn(string $s): string => (($m['caps'] ?? '') === '1' && $s !== '' && $s === mb_strtoupper($s) && preg_match('/\p{L}{3}/u', $s))
        ? mb_strtoupper(mb_substr(mb_strtolower($s), 0, 1)) . mb_substr(mb_strtolower($s), 1) : $s;
}

/** Guarda el emparejamiento actual como perfil propio. Devuelve su id. */
function dbimp_save_profile(array $job, string $name): int
{
    $m = $job['map'];
    $mapping = [];
    foreach (['table', 'id', 'name', 'ref', 'family', 'sub', 'dims', 'desc', 'short', 'visible', 'sort', 'img',
        'fam_table', 'fam_id', 'fam_name', 'fam_parent', 'img_table', 'img_fk', 'img_file', 'img_sort',
        'visible_inv', 'caps', 'folders', 'extra_labels', 'img_base'] as $k) {
        if ((string)($m[$k] ?? '') !== '') {
            $mapping[$k] = (string)$m[$k];
        }
    }
    $mapping['extra'] = array_values((array)($m['extra'] ?? []));
    $mapping['fields'] = [];
    $defs = [];
    foreach (fields_all() as $cf) {
        if ((string)($m['cf_' . $cf['id']] ?? '') !== '') {
            $mapping['fields'][$cf['slug']] = (string)$m['cf_' . $cf['id']];
            $defs[$cf['slug']] = [$cf['name'], $cf['kind'], $cf['unit'], $cf['is_public']]; // para crearlos en otra web
        }
    }
    $rules = [
        'require_tables' => array_values(array_filter([(string)$m['table'], (string)$m['fam_table'], (string)$m['img_table']])),
        'exclude_columns' => array_values((array)($job['exclude'] ?? [])),
        'fields' => $defs,
    ];
    return profiles_save($name, (string)($job['source_type'] ?? 'sql'), $mapping, $rules);
}

/**
 * Modo «Actualizar precios y stock»: compara la base de datos con la web por referencia y
 * devuelve un plan solo con los artículos que cambian, más el resumen para revisarlo.
 */
function dbimp_build_update(array $job): array
{
    $m = $job['map'];
    if ($m['ref'] === '') {
        $job['warnings'] = ['Para actualizar hace falta la columna «Referencia / código»: es la única forma segura de saber qué artículo es cada fila.'];
        return $job;
    }
    $all = fields_all();
    $fids = [];
    foreach ((array)($m['upd_fields'] ?? []) as $fid) {
        if (isset($all[(int)$fid]) && (string)($m['cf_' . $fid] ?? '') !== '') {
            $fids[] = (int)$fid;
        }
    }
    $vis = ($m['upd_visible'] ?? '') === '1' && $m['visible'] !== '';
    if (!$fids && !$vis) {
        $job['warnings'] = ['Marca al menos un campo que tenga una columna asignada (o la visibilidad, eligiendo antes su columna).'];
        return $job;
    }

    // La web: referencia (sin mayúsculas) → artículos
    $web = [];
    $info = [];
    $webNoRef = 0;
    foreach (db()->query('SELECT id, ref, name, visible FROM products')->fetchAll() as $p) {
        $ref = mb_strtolower(trim((string)$p['ref']));
        if ($ref === '') {
            $webNoRef++;
            continue;
        }
        $web[$ref][] = (int)$p['id'];
        $info[(int)$p['id']] = ['ref' => trim((string)$p['ref']), 'name' => (string)$p['name'], 'visible' => (int)$p['visible']];
    }
    $cur = [];
    if ($fids) {
        foreach (db()->query('SELECT product_id, field_id, value FROM product_fields WHERE field_id IN (' . implode(',', $fids) . ')')->fetchAll() as $r) {
            $cur[(int)$r['product_id']][(int)$r['field_id']] = (string)$r['value'];
        }
    }

    $caps = dbimp_caps($m);
    $keys = array_merge(['ref'], $vis ? ['visible'] : [], array_map(fn($f) => 'cf_' . $f, $fids));
    $seen = [];
    $notFound = [];
    $items = [];
    $counts = ['visible' => 0];
    foreach ($fids as $fid) {
        $counts[$fid] = 0;
    }
    $same = $noRef = $dup = $matched = 0;
    foreach (dbimp_open()->query(dbimp_select($job, $m, $keys, false)) as $r) {
        $code = trim((string)($r[$m['ref']] ?? ''));
        if ($code === '') {
            $noRef++;
            continue;
        }
        $lk = mb_strtolower($code);
        if (isset($seen[$lk])) {
            $dup++; // código repetido en el origen: vale la primera fila
            continue;
        }
        $seen[$lk] = true;
        if (!isset($web[$lk])) {
            $notFound[] = $code;
            continue;
        }
        $new = [];
        foreach ($fids as $fid) {
            $v = dbimp_cf_value($all[$fid], (string)($r[$m['cf_' . $fid]] ?? ''), $caps);
            if ($v !== '') { // vacío (o precio 0) en el origen: no se toca
                $new[$fid] = $v;
            }
        }
        $newVis = null;
        if ($vis) {
            $raw = trim((string)($r[$m['visible']] ?? ''));
            if ($raw !== '') {
                $b = import_bool($raw);
                $newVis = $m['visible_inv'] === '1' ? 1 - $b : $b;
            }
        }
        foreach ($web[$lk] as $pid) {
            $matched++;
            $chg = [];
            $old = [];
            foreach ($new as $fid => $v) {
                $o = $cur[$pid][$fid] ?? '';
                if ($o !== $v) {
                    $chg[$fid] = $v;
                    $old[$fid] = $o;
                    $counts[$fid]++;
                }
            }
            $vchg = $newVis !== null && $newVis !== $info[$pid]['visible'];
            if ($vchg) {
                $counts['visible']++;
            }
            if (!$chg && !$vchg) {
                $same++;
                continue;
            }
            $items[] = [
                'pid' => $pid, 'ref' => $info[$pid]['ref'], 'name' => $info[$pid]['name'],
                'fields' => $chg, 'old' => $old,
                'visible' => $vchg ? $newVis : null, 'old_visible' => $info[$pid]['visible'],
            ];
        }
    }
    $missing = [];
    foreach ($web as $lk => $pids) {
        if (!isset($seen[$lk])) {
            foreach ($pids as $pid) {
                $missing[] = $info[$pid]['ref'];
            }
        }
    }
    natcasesort($missing);
    $warnings = [];
    if ($dup) {
        $warnings[] = $dup . ' código(s) están repetidos en la base de datos: se ha usado la primera fila de cada uno.';
    }
    if ($noRef) {
        $warnings[] = $noRef . ' fila(s) de la base de datos no tienen código: se ignoran.';
    }
    if ($webNoRef) {
        $warnings[] = $webNoRef . ' artículo(s) de la web no tienen referencia: no se pueden actualizar.';
    }
    return [
        'origin' => $job['origin'] . ' · tabla ' . $m['table'],
        'mode' => 'update',
        'allowed' => [],
        'items' => $items,
        'warnings' => $warnings,
        'upd' => [
            'fields' => $fids, 'visible' => $vis, 'visible_col' => $m['visible'], 'counts' => $counts,
            'matched' => $matched, 'same' => $same,
            'not_found' => count($notFound), 'not_found_codes' => array_slice($notFound, 0, 20),
            'missing' => count($missing), 'missing_codes' => array_slice(array_values($missing), 0, 20),
        ],
    ];
}

return [
    'id' => 'base-datos',
    'label' => 'Base de datos de otra aplicación (Access, SQL)',
    'order' => 25,
    'help' => 'Base de datos de Access (.mdb, .accdb) de programas de gestión (NCS Fact, Factusol, ContaPlus…), volcado SQL de MySQL, MariaDB, SQL Server o PostgreSQL (.sql o .sql.gz, por ejemplo exportado con phpMyAdmin), o un archivo SQLite (.sqlite, .db). Después eliges la tabla de artículos y qué columna es cada cosa. Si pesa mucho, déjalo por FTP en data/importar/ y pulsa Analizar sin elegir archivo.',
    'fields' => [
        ['name' => 'db', 'type' => 'file', 'label' => 'Archivo de la base de datos', 'accept' => '.sql,.gz,.sqlite,.sqlite3,.db,.mdb,.accdb'],
    ],
    'analyze' => 'dbimp_analyze',
    'map' => 'dbimp_map',
    'map_form' => 'dbimp_map_form',
];
