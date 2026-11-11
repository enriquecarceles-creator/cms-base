<?php
declare(strict_types=1);

/*
 * Base de datos: SQLite (por defecto), MariaDB / MySQL o Microsoft SQL Server.
 *
 * - El motor se elige en el instalador y se guarda en data/db.php (fuera del alcance del navegador).
 * - El código escribe SQL «al estilo SQLite»; CmsPdo lo traduce al vuelo para MariaDB y SQL Server
 *   (db_sql). Todo lo que cambia entre motores está en este archivo.
 * - Las copias de seguridad son siempre un archivo SQLite, así que una copia se puede restaurar
 *   en cualquier motor (y sirve para pasar de uno a otro).
 */

const DB_CONFIG_FILE = DATA_DIR . '/db.php';
const DB_SQLITE_FILE = DATA_DIR . '/site.sqlite';

/** Motores que se pueden elegir: clave => [nombre, extensión PDO necesaria]. */
const DB_ENGINES = [
    'sqlite' => ['SQLite (archivo, sin servidor)', 'pdo_sqlite'],
    'mysql' => ['MariaDB / MySQL', 'pdo_mysql'],
    'sqlsrv' => ['Microsoft SQL Server', 'pdo_sqlsrv'],
];

/** Tablas de la aplicación en orden de dependencias (padres antes que hijas). */
const DB_TABLES = ['users', 'login_attempts', 'password_resets', 'login_devices', 'user_recovery_codes', 'settings', 'categories', 'products',
    'product_images', 'slides', 'reps', 'custom_fields', 'product_fields', 'import_profiles', 'pages'];

/** PDO que traduce el SQL al motor de destino. */
final class CmsPdo extends PDO
{
    public string $drv = 'sqlite';

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        db_sql_log($query);
        return parent::prepare(db_sql($query, $this->drv), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        db_sql_log($query);
        $query = db_sql($query, $this->drv);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        db_sql_log($statement);
        return parent::exec(db_sql($statement, $this->drv));
    }

    /** Sin traducir (para el esquema, que ya se escribe para cada motor). */
    public function rawExec(string $statement): int|false
    {
        return parent::exec($statement);
    }
}

/** Solo para desarrollo: si existe la variable de entorno CMS_SQL_LOG, apunta cada consulta distinta en ese archivo. */
function db_sql_log(string $sql): void
{
    static $file = null, $seen = [];
    $file ??= (string)getenv('CMS_SQL_LOG');
    if ($file === '') {
        return;
    }
    $k = md5($sql);
    if (!isset($seen[$k])) {
        $seen[$k] = true;
        file_put_contents($file, json_encode($sql, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    }
}

/* ---------- Configuración ---------- */

function db_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = is_file(DB_CONFIG_FILE) ? (array)(include DB_CONFIG_FILE) : [];
        $cfg += ['driver' => 'sqlite', 'host' => '', 'port' => '', 'name' => '', 'user' => '', 'pass' => ''];
        if (!isset(DB_ENGINES[$cfg['driver']])) {
            $cfg['driver'] = 'sqlite';
        }
    }
    return $cfg;
}

function db_driver(): string
{
    return db_config()['driver'];
}

/** ¿Hay una base de datos elegida? (archivo de configuración, o una SQLite ya creada). */
function db_configured(): bool
{
    return is_file(DB_CONFIG_FILE) || (is_file(DB_SQLITE_FILE) && filesize(DB_SQLITE_FILE) > 0);
}

/** Guarda la configuración en data/db.php. */
function db_save_config(array $cfg): void
{
    $keep = array_intersect_key($cfg, array_flip(['driver', 'host', 'port', 'name', 'user', 'pass']));
    $php = "<?php\n// Base de datos de la web (la crea el instalador). No la compartas: contiene la contraseña.\nreturn " . var_export($keep, true) . ";\n";
    if (file_put_contents(DB_CONFIG_FILE, $php, LOCK_EX) === false) {
        throw new RuntimeException('No se pudo guardar data/db.php (¿permisos de la carpeta data/?).');
    }
    @chmod(DB_CONFIG_FILE, 0640);
}

/** Extensiones de PHP disponibles para cada motor. */
function db_engine_available(string $drv): bool
{
    return extension_loaded(DB_ENGINES[$drv][1] ?? '');
}

/** Abre una conexión con la configuración indicada. */
function db_connect(array $cfg): CmsPdo
{
    $drv = $cfg['driver'] ?? 'sqlite';
    $opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if (!db_engine_available($drv)) {
        throw new RuntimeException('Este servidor no tiene la extensión de PHP «' . DB_ENGINES[$drv][1] . '» para ' . DB_ENGINES[$drv][0] . '.');
    }
    if ($drv === 'mysql') {
        $dsn = 'mysql:host=' . ($cfg['host'] ?: 'localhost') . ($cfg['port'] ? ';port=' . (int)$cfg['port'] : '') . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
        $opts[PDO::ATTR_EMULATE_PREPARES] = false;
        $pdo = new CmsPdo($dsn, (string)$cfg['user'], (string)$cfg['pass'], $opts);
        $pdo->rawExec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    } elseif ($drv === 'sqlsrv') {
        $dsn = 'sqlsrv:Server=' . ($cfg['host'] ?: 'localhost') . ($cfg['port'] ? ',' . (int)$cfg['port'] : '') . ';Database=' . $cfg['name'] . ';TrustServerCertificate=1';
        $pdo = new CmsPdo($dsn, (string)$cfg['user'], (string)$cfg['pass'], $opts);
        if (defined('PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE')) {
            $pdo->setAttribute(PDO::SQLSRV_ATTR_FETCHES_NUMERIC_TYPE, true);
        }
    } else {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0750, true);
        }
        $pdo = new CmsPdo('sqlite:' . ($cfg['file'] ?? DB_SQLITE_FILE), null, null, $opts);
        $pdo->rawExec('PRAGMA foreign_keys = ON');
        $drv = 'sqlite';
    }
    $pdo->drv = $drv;
    return $pdo;
}

/* ---------- Traductor de SQL ---------- */

/**
 * Traduce SQL escrito para SQLite a MariaDB / SQL Server:
 *   COLLATE NOCASE (se quita: los otros motores ya no distinguen mayúsculas), RANDOM(),
 *   a || b (concatenar), LIMIT n (TOP n en SQL Server) y nombres reservados (key, file).
 */
function db_sql(string $sql, string $drv): string
{
    if ($drv === 'sqlite') {
        return $sql;
    }
    static $cache = [];
    $ck = $drv . "\0" . $sql;
    if (isset($cache[$ck])) {
        return $cache[$ck];
    }
    // Apartar los textos entre comillas simples para no tocarlos
    $lits = [];
    $code = preg_replace_callback("/'(?:[^']|'')*'/", function ($m) use (&$lits) {
        $lits[] = $m[0];
        return "\x01" . (count($lits) - 1) . "\x01";
    }, $sql);

    $code = preg_replace('/\s+COLLATE\s+NOCASE\b/i', '', $code);
    $code = preg_replace('/\bRANDOM\(\)/i', $drv === 'mysql' ? 'RAND()' : 'NEWID()', $code);
    // a || ' › ' || b  →  CONCAT(a, ' › ', b)
    $operand = '(?:[A-Za-z_][\w.]*|\x01\d+\x01)';
    $code = preg_replace_callback('/' . $operand . '(?:\s*\|\|\s*' . $operand . ')+/', function ($m) {
        return 'CONCAT(' . implode(', ', preg_split('/\s*\|\|\s*/', $m[0])) . ')';
    }, $code);
    // Palabras reservadas usadas como nombre de columna
    if ($drv === 'mysql') {
        $code = preg_replace('/(?<![\w`.])key(?![\w`])/', '`key`', $code);
        $code = preg_replace('/\.key\b/', '.`key`', $code);
    } else {
        $code = preg_replace('/(?<![\w\[])(key|file)(?![\w\]])/', '[$1]', $code);
        $code = db_sql_top($code);
    }
    $out = preg_replace_callback("/\x01(\d+)\x01/", fn($m) => $lits[(int)$m[1]], $code);
    if (count($cache) > 500) {
        $cache = [];
    }
    return $cache[$ck] = $out;
}

/** SQL Server: «SELECT … LIMIT n» → «SELECT TOP n …» (también dentro de subconsultas). */
function db_sql_top(string $code): string
{
    $guard = 0;
    while (preg_match('/\s+LIMIT\s+(\d+)(?:\s+OFFSET\s+(\d+))?/i', $code, $m, PREG_OFFSET_CAPTURE) && $guard++ < 50) {
        $pos = $m[0][1];
        $n = (int)$m[1][0];
        if (isset($m[2])) {
            // Con OFFSET: «OFFSET x ROWS FETCH NEXT n ROWS ONLY» (necesita ORDER BY, que siempre ponemos)
            $code = substr($code, 0, $pos) . ' OFFSET ' . (int)$m[2][0] . ' ROWS FETCH NEXT ' . $n . ' ROWS ONLY' . substr($code, $pos + strlen($m[0][0]));
            continue;
        }
        // Buscar hacia atrás el SELECT del mismo nivel de paréntesis
        $depth = 0;
        $sel = -1;
        for ($i = $pos - 1; $i >= 0; $i--) {
            $c = $code[$i];
            if ($c === ')') {
                $depth++;
            } elseif ($c === '(') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && ($c === 'S' || $c === 's') && preg_match('/\GSELECT\b/i', $code, $mm, 0, $i)
                && ($i === 0 || !preg_match('/\w/', $code[$i - 1]))) {
                $sel = $i;
            }
        }
        $code = substr($code, 0, $pos) . substr($code, $pos + strlen($m[0][0]));
        if ($sel >= 0) {
            $after = $sel + 6;
            if (preg_match('/\G\s+DISTINCT\b/i', $code, $dm, 0, $after)) {
                $after += strlen($dm[0]);
            }
            $code = substr($code, 0, $after) . ' TOP ' . $n . substr($code, $after);
        }
    }
    return $code;
}

/* ---------- Esquema por motor ---------- */

/**
 * Convierte el esquema escrito con marcadores al motor:
 *   {ID} clave autonumérica · {S} texto corto (255) · {K} texto corto con índice único (191 en MariaDB, por utf8mb4) · {T} texto largo · {I} entero · {BIG} entero grande · {Q:nombre} nombre reservado
 */
function db_ddl(string $ddl, string $drv): array
{
    $map = [
        'sqlite' => ['{ID}' => 'INTEGER PRIMARY KEY', '{K}' => 'TEXT', '{S}' => 'TEXT', '{T}' => 'TEXT', '{I}' => 'INTEGER', '{BIG}' => 'INTEGER'],
        'mysql' => ['{ID}' => 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY', '{K}' => 'VARCHAR(191)', '{S}' => 'VARCHAR(255)', '{T}' => 'MEDIUMTEXT', '{I}' => 'INT', '{BIG}' => 'BIGINT'],
        'sqlsrv' => ['{ID}' => 'INT IDENTITY(1,1) PRIMARY KEY', '{K}' => 'NVARCHAR(255)', '{S}' => 'NVARCHAR(255)', '{T}' => 'NVARCHAR(MAX)', '{I}' => 'INT', '{BIG}' => 'BIGINT'],
    ][$drv];
    $ddl = preg_replace_callback('/\{Q:(\w+)\}/', fn($m) => $drv === 'mysql' ? '`' . $m[1] . '`' : ($drv === 'sqlsrv' ? '[' . $m[1] . ']' : '"' . $m[1] . '"'), $ddl);
    if ($drv === 'mysql') {
        // MariaDB/MySQL antiguos no admiten DEFAULT en columnas TEXT: siempre se rellenan al insertar
        $ddl = str_replace("{T} NOT NULL DEFAULT ''", '{T} NOT NULL', $ddl);
    }
    $ddl = strtr($ddl, $map);
    $out = [];
    foreach (explode(';', $ddl) as $st) {
        $st = trim($st);
        if ($st === '') {
            continue;
        }
        if ($drv === 'mysql' && str_starts_with($st, 'CREATE TABLE')) {
            $st .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        $out[] = $st;
    }
    return $out;
}

/** Tablas existentes en la base de datos. */
function db_tables(CmsPdo $pdo): array
{
    $sql = match ($pdo->drv) {
        'mysql' => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()',
        'sqlsrv' => "SELECT name FROM sys.tables WHERE type = 'U'",
        default => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
    };
    return array_map('strtolower', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
}

/** Columnas de una tabla. */
function db_columns(CmsPdo $pdo, string $table): array
{
    if ($pdo->drv === 'sqlite') {
        return array_column($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(), 'name');
    }
    $sql = $pdo->drv === 'mysql'
        ? 'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position'
        : 'SELECT name FROM sys.columns WHERE object_id = OBJECT_ID(?) ORDER BY column_id';
    $st = $pdo->prepare($sql);
    $st->execute([$table]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Añade una columna a una tabla que ya existe (migraciones). $def usa los marcadores de db_ddl()
 * («{I} NOT NULL DEFAULT 0»). SQL Server escribe «ADD col», SQLite y MariaDB «ADD COLUMN col».
 */
function db_add_column(CmsPdo $pdo, string $table, string $col, string $def): void
{
    $sql = 'ALTER TABLE ' . $table . ($pdo->drv === 'sqlsrv' ? ' ADD ' : ' ADD COLUMN ') . $col . ' ' . $def;
    foreach (db_ddl($sql, $pdo->drv) as $st) {
        $pdo->rawExec($st);
    }
}

function db_schema_version(CmsPdo $pdo): int
{
    if ($pdo->drv === 'sqlite') {
        return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    }
    $st = $pdo->prepare("SELECT value FROM settings WHERE key = 'schema_version'");
    $st->execute();
    return (int)$st->fetchColumn();
}

function db_set_schema_version(CmsPdo $pdo, int $v): void
{
    if ($pdo->drv === 'sqlite') {
        $pdo->rawExec('PRAGMA user_version = ' . $v);
        return;
    }
    db_put_setting($pdo, 'schema_version', (string)$v);
}

/** Guarda un ajuste (insertar o actualizar) de forma válida en los tres motores. */
function db_put_setting(CmsPdo $pdo, string $key, string $value): void
{
    $ex = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE key = ?');
    $ex->execute([$key]);
    if ((int)$ex->fetchColumn() > 0) {
        $pdo->prepare('UPDATE settings SET value = ? WHERE key = ?')->execute([$value, $key]);
    } else {
        $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute([$key, $value]);
    }
}

/* ---------- Copiar datos entre bases (copias de seguridad y cambio de motor) ---------- */

/** Copia todas las filas de las tablas de la aplicación de $from a $to (vacía antes las de $to). */
function db_copy_all(CmsPdo $from, CmsPdo $to): void
{
    $have = db_tables($from);
    $toTables = db_tables($to);
    $to->beginTransaction();
    try {
        if (in_array('categories', $toTables, true)) {
            $to->exec('UPDATE categories SET parent_id = NULL'); // MySQL comprueba las claves fila a fila
        }
        foreach (array_reverse(DB_TABLES) as $t) {
            if (in_array($t, $toTables, true)) {
                $to->exec('DELETE FROM ' . $t);
            }
        }
        foreach (DB_TABLES as $t) {
            if (!in_array($t, $have, true) || !in_array($t, $toTables, true)) {
                continue;
            }
            $cols = array_values(array_intersect(db_columns($to, $t), db_columns($from, $t)));
            if (!$cols) {
                continue;
            }
            $q = fn(string $c) => $to->drv === 'mysql' ? '`' . $c . '`' : ($to->drv === 'sqlsrv' ? '[' . $c . ']' : '"' . $c . '"');
            $qf = fn(string $c) => $from->drv === 'mysql' ? '`' . $c . '`' : ($from->drv === 'sqlsrv' ? '[' . $c . ']' : '"' . $c . '"');
            $order = in_array('id', $cols, true) ? ($t === 'categories' ? ' ORDER BY CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END, id' : ' ORDER BY id') : '';
            $identity = $to->drv === 'sqlsrv' && in_array('id', $cols, true);
            if ($identity) {
                $to->rawExec('SET IDENTITY_INSERT ' . $t . ' ON');
            }
            $ins = $to->prepare('INSERT INTO ' . $t . ' (' . implode(', ', array_map($q, $cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
            $rows = $from->query('SELECT ' . implode(', ', array_map($qf, $cols)) . ' FROM ' . $t . $order);
            while ($r = $rows->fetch(PDO::FETCH_NUM)) {
                $ins->execute($r);
            }
            if ($identity) {
                $to->rawExec('SET IDENTITY_INSERT ' . $t . ' OFF');
            }
        }
        $to->commit();
    } catch (Throwable $e) {
        $to->rollBack();
        throw $e;
    }
    // Los contadores autonuméricos de MySQL / SQL Server se ajustan solos al insertar con id;
    // en PostgreSQL haría falta reajustarlos, pero no está soportado.
}

/** Crea un archivo SQLite con el esquema y una copia de los datos de la base actual. */
function db_export_sqlite(string $file): void
{
    @unlink($file);
    $to = db_connect(['driver' => 'sqlite', 'file' => $file]);
    require_once __DIR__ . '/schema.php';
    install_schema($to);
    db_copy_all(db(), $to);
    db_set_schema_version($to, SCHEMA_VERSION);
}
