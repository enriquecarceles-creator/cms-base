<?php
declare(strict_types=1);

/*
 * Restaurar una copia de seguridad (el ZIP de Web → Empresa → «Descargar copia de seguridad»).
 * Se usa desde el instalador para trasladar una web a otro servidor o recuperarla.
 */

/** Carpeta donde se puede dejar por FTP un ZIP de copia demasiado grande para subirlo desde el navegador. */
const RESTORE_FTP_DIR = DATA_DIR . '/restaurar';

/** ZIP más reciente dejado por FTP en data/restaurar/, o null. */
function backup_ftp_zip(): ?string
{
    $found = glob(RESTORE_FTP_DIR . '/*.zip') ?: [];
    usort($found, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $found[0] ?? null;
}

/**
 * Comprueba y restaura la copia: data/site.sqlite y las fotos de uploads/.
 * Devuelve el número de usuarios de la copia. Lanza RuntimeException si el ZIP no es válido.
 */
function backup_restore(string $zipPath): int
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Este servidor no tiene la extensión ZIP de PHP, necesaria para restaurar.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('No se pudo abrir el ZIP.');
    }
    $dbData = $zip->getFromName('data/site.sqlite');
    if ($dbData === false || !str_starts_with($dbData, "SQLite format 3\0")) {
        $zip->close();
        throw new RuntimeException('Este ZIP no es una copia de seguridad de la web (no contiene data/site.sqlite).');
    }
    // Comprobar la base de datos antes de tocar nada
    $tmpDb = DATA_DIR . '/.restaurar-' . bin2hex(random_bytes(4)) . '.sqlite';
    file_put_contents($tmpDb, $dbData);
    unset($dbData);
    try {
        $chk = new PDO('sqlite:' . $tmpDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $users = (int)$chk->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $chk->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $chk = null;
    } catch (Throwable $e) {
        @unlink($tmpDb);
        $zip->close();
        throw new RuntimeException('La base de datos de la copia está dañada o no es de esta aplicación.');
    }
    if ($users < 1) {
        @unlink($tmpDb);
        $zip->close();
        throw new RuntimeException('La copia no tiene ningún usuario administrador.');
    }
    // Fotos: solo archivos de imagen dentro de uploads/, sin rutas raras
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string)$zip->getNameIndex($i);
        if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/') || str_contains($name, '..') || str_contains($name, '\\')) {
            continue;
        }
        $rel = substr($name, 8);
        if (!preg_match('#^[\w\-./]+\.(webp|png|jpe?g|gif)$#i', $rel)) {
            continue;
        }
        $dest = UPLOAD_DIR . '/' . $rel;
        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0755, true);
        }
        $data = $zip->getFromIndex($i);
        if ($data !== false) {
            file_put_contents($dest, $data);
        }
    }
    $zip->close();
    // Sustituir la base de datos (las tablas se actualizan solas en la siguiente visita si la copia es antigua)
    foreach (['boxes.json', 'gaps.json', 'sigs.json', 'import_job.json'] as $c) {
        @unlink(DATA_DIR . '/' . $c);
    }
    if (db_driver() !== 'sqlite') {
        // MariaDB / SQL Server: se actualiza la copia a la versión actual y se copian sus datos
        try {
            require_once __DIR__ . '/schema.php';
            $src = db_connect(['driver' => 'sqlite', 'file' => $tmpDb]);
            if (db_schema_version($src) < SCHEMA_VERSION) {
                migrate_schema($src);
            }
            @set_time_limit(600);
            db_copy_all($src, db());
            $src = null;
        } catch (Throwable $e) {
            error_log((string)$e);
            throw new RuntimeException('No se pudieron copiar los datos a la base de datos: ' . $e->getMessage());
        } finally {
            @unlink($tmpDb);
        }
        return $users;
    }
    @unlink(DATA_DIR . '/site.sqlite-wal');
    @unlink(DATA_DIR . '/site.sqlite-shm');
    if (!rename($tmpDb, DB_SQLITE_FILE)) {
        @unlink($tmpDb);
        throw new RuntimeException('No se pudo escribir la base de datos en data/ (¿permisos?).');
    }
    return $users;
}
