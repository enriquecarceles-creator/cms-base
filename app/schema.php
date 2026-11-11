<?php
declare(strict_types=1);

/** v14: campos libres del catálogo (precio, tallas, color…) y sus valores por artículo. */
const SCHEMA_FIELDS_DDL = "
    CREATE TABLE custom_fields (
        id {ID},
        name {S} NOT NULL,
        slug {K} NOT NULL UNIQUE,
        kind {S} NOT NULL DEFAULT 'text',
        choices {T} NOT NULL DEFAULT '',
        unit {S} NOT NULL DEFAULT '',
        is_public {I} NOT NULL DEFAULT 1,
        sort {I} NOT NULL DEFAULT 0
    );
    CREATE TABLE product_fields (
        product_id {I} NOT NULL,
        field_id {I} NOT NULL,
        value {T} NOT NULL,
        PRIMARY KEY (product_id, field_id),
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        FOREIGN KEY (field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
    );
";

/** v15: perfiles de importación (emparejamientos de columnas guardados; ver app/profiles.php). */
const SCHEMA_PROFILES_DDL = "
    CREATE TABLE import_profiles (
        id {ID},
        name {S} NOT NULL,
        source_type {S} NOT NULL DEFAULT 'mdb',
        target_module {S} NOT NULL DEFAULT 'catalog',
        mapping_json {T} NOT NULL,
        rules_json {T} NOT NULL,
        is_system {I} NOT NULL DEFAULT 0,
        created_at {S} NOT NULL
    );
";

/** v16: páginas propias (/<slug>; ver app/cms_pages.php). El texto va en Markdown limitado, nunca HTML. */
const SCHEMA_PAGES_DDL = "
    CREATE TABLE pages (
        id {ID},
        title {S} NOT NULL,
        slug {K} NOT NULL UNIQUE,
        body {T} NOT NULL DEFAULT '',
        hero {S} NULL,
        seo_title {S} NOT NULL DEFAULT '',
        seo_desc {S} NOT NULL DEFAULT '',
        visible {I} NOT NULL DEFAULT 1,
        sort {I} NOT NULL DEFAULT 0,
        created_at {S} NOT NULL,
        updated_at {S} NOT NULL
    );
";

/** Esquema completo, válido para SQLite, MariaDB/MySQL y SQL Server (ver db_ddl() en app/db.php). */
const SCHEMA_DDL = "
    CREATE TABLE users (
        id {ID},
        username {K} NOT NULL UNIQUE,
        pass_hash {S} NOT NULL,
        role {S} NOT NULL DEFAULT 'admin',
        email {S} NOT NULL DEFAULT ''
    );
    CREATE TABLE login_attempts (
        id {ID},
        ip {S} NOT NULL,
        ts {BIG} NOT NULL
    );
    CREATE TABLE password_resets (
        id {ID},
        user_id {I} NOT NULL,
        token_hash {S} NOT NULL,
        expires {BIG} NOT NULL
    );
    CREATE TABLE settings (
        {Q:key} {K} NOT NULL PRIMARY KEY,
        value {T} NOT NULL
    );
    CREATE TABLE categories (
        id {ID},
        name {S} NOT NULL,
        slug {K} NOT NULL UNIQUE,
        sort {I} NOT NULL DEFAULT 0,
        parent_id {I} NULL,
        cover {S} NOT NULL DEFAULT '',
        banner {S} NOT NULL DEFAULT '',
        FOREIGN KEY (parent_id) REFERENCES categories(id)
    );
    CREATE TABLE products (
        id {ID},
        category_id {I} NOT NULL,
        ref {S} NOT NULL DEFAULT '',
        name {S} NOT NULL,
        slug {K} NOT NULL UNIQUE,
        dimensions {S} NOT NULL DEFAULT '',
        description {T} NOT NULL DEFAULT '',
        image {S} NULL,
        visible {I} NOT NULL DEFAULT 1,
        sort {I} NOT NULL DEFAULT 0,
        shape {S} NOT NULL DEFAULT '',
        created_at {S} NOT NULL,
        updated_at {S} NOT NULL,
        FOREIGN KEY (category_id) REFERENCES categories(id)
    );
    CREATE INDEX idx_products_cat ON products(category_id, visible, sort);
    CREATE TABLE product_images (
        id {ID},
        product_id {I} NOT NULL,
        {Q:file} {S} NOT NULL,
        src {T} NOT NULL DEFAULT '',
        sort {I} NOT NULL DEFAULT 0,
        enh {S} NOT NULL DEFAULT '',
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    );
    CREATE INDEX idx_pimg_product ON product_images(product_id, sort);
    CREATE TABLE slides (
        id {ID},
        {Q:file} {S} NOT NULL,
        title {S} NOT NULL DEFAULT '',
        text {T} NOT NULL DEFAULT '',
        link {S} NOT NULL DEFAULT '',
        btn {S} NOT NULL DEFAULT '',
        sort {I} NOT NULL DEFAULT 0,
        visible {I} NOT NULL DEFAULT 1
    );
    CREATE TABLE reps (
        id {ID},
        zone {S} NOT NULL,
        name {S} NOT NULL,
        address {S} NOT NULL DEFAULT '',
        phone {S} NOT NULL DEFAULT ''
    );
" . SCHEMA_FIELDS_DDL . SCHEMA_PROFILES_DDL . SCHEMA_PAGES_DDL;

/** Crea las tablas en una base de datos vacía. */
function install_schema(CmsPdo $pdo): void
{
    foreach (db_ddl(SCHEMA_DDL, $pdo->drv) as $st) {
        $pdo->rawExec($st);
    }
    db_set_schema_version($pdo, SCHEMA_VERSION);
}

/** Actualiza bases de datos creadas con versiones anteriores. */
function migrate_schema(CmsPdo $pdo): void
{
    $ver = db_schema_version($pdo);
    if ($pdo->drv === 'sqlite' && $ver < 13) {
        migrate_sqlite_legacy($pdo, $ver);
    }
    if ($ver < 14 && !in_array('custom_fields', db_tables($pdo), true)) {
        foreach (db_ddl(SCHEMA_FIELDS_DDL, $pdo->drv) as $st) {
            $pdo->rawExec($st);
        }
    }
    if ($ver < 15 && !in_array('import_profiles', db_tables($pdo), true)) {
        foreach (db_ddl(SCHEMA_PROFILES_DDL, $pdo->drv) as $st) {
            $pdo->rawExec($st);
        }
    }
    if ($ver < 16 && !in_array('pages', db_tables($pdo), true)) {
        foreach (db_ddl(SCHEMA_PAGES_DDL, $pdo->drv) as $st) {
            $pdo->rawExec($st);
        }
    }
    db_set_schema_version($pdo, SCHEMA_VERSION);
}

/** Versiones 1 a 13 (solo existían en SQLite). */
function migrate_sqlite_legacy(CmsPdo $pdo, int $ver): void
{
    if ($ver < 2) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS product_images (
                id INTEGER PRIMARY KEY,
                product_id INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                file TEXT NOT NULL,
                src TEXT NOT NULL DEFAULT '',
                sort INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS idx_pimg_product ON product_images(product_id, sort);
            INSERT INTO product_images (product_id, file, sort)
                SELECT id, image, 0 FROM products
                WHERE image IS NOT NULL AND image <> ''
                  AND id NOT IN (SELECT product_id FROM product_images);
        ");
    }
    if ($ver < 3) {
        $cols = array_column($pdo->query('PRAGMA table_info(categories)')->fetchAll(), 'name');
        if (!in_array('parent_id', $cols, true)) {
            $pdo->exec('ALTER TABLE categories ADD COLUMN parent_id INTEGER REFERENCES categories(id)');
        }
    }
    if ($ver < 4) {
        $cols = array_column($pdo->query('PRAGMA table_info(product_images)')->fetchAll(), 'name');
        if (!in_array('enh', $cols, true)) {
            $pdo->exec("ALTER TABLE product_images ADD COLUMN enh TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($ver < 5) {
        $cols = array_column($pdo->query('PRAGMA table_info(categories)')->fetchAll(), 'name');
        if (!in_array('cover', $cols, true)) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN cover TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($ver < 6) {
        $cols = array_column($pdo->query('PRAGMA table_info(categories)')->fetchAll(), 'name');
        if (!in_array('banner', $cols, true)) {
            $pdo->exec("ALTER TABLE categories ADD COLUMN banner TEXT NOT NULL DEFAULT ''");
        }
    }
    if ($ver < 7) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS slides (
            id INTEGER PRIMARY KEY, file TEXT NOT NULL, title TEXT NOT NULL DEFAULT '', text TEXT NOT NULL DEFAULT '',
            link TEXT NOT NULL DEFAULT '', btn TEXT NOT NULL DEFAULT '', sort INTEGER NOT NULL DEFAULT 0, visible INTEGER NOT NULL DEFAULT 1)");
    }
    if ($ver < 9) {
        $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('role', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN role TEXT NOT NULL DEFAULT 'admin'");
        }
    }
    if ($ver < 10) {
        $cols = array_column($pdo->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('shape', $cols, true)) {
            $pdo->exec("ALTER TABLE products ADD COLUMN shape TEXT NOT NULL DEFAULT ''");
        }
    }
    schema_v11($pdo);
}


/** v11: correo en usuarios y tabla de restablecimiento de contraseña (idempotente). */
function schema_v11(CmsPdo $pdo): void
{
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('email', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        expires INTEGER NOT NULL
    )');
}
