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

/** v17: sitios desde los que ha entrado cada usuario (aviso por correo si hay uno nuevo; ver app/auth.php). */
const SCHEMA_DEVICES_DDL = "
    CREATE TABLE login_devices (
        id {ID},
        user_id {I} NOT NULL,
        ip_net {S} NOT NULL,
        browser {S} NOT NULL,
        first_seen {BIG} NOT NULL,
        last_seen {BIG} NOT NULL
    );
    CREATE INDEX idx_login_devices_user ON login_devices(user_id);
";

/** v17: columnas de seguridad de users (bloqueo por usuario y último acceso). Nombre => definición. */
const SCHEMA_USER_SECURITY_COLUMNS = [
    'failed_attempts' => '{I} NOT NULL DEFAULT 0',
    'last_failed_at' => '{BIG} NOT NULL DEFAULT 0',
    'locked_until' => '{BIG} NOT NULL DEFAULT 0',
    'last_login_at' => '{BIG} NOT NULL DEFAULT 0',
];

/** v18: doble verificación TOTP (ver app/auth.php): columnas de users y códigos de recuperación de un solo uso. */
const SCHEMA_USER_TOTP_COLUMNS = [
    'totp_secret' => "{S} NOT NULL DEFAULT ''",
    'totp_enabled' => '{I} NOT NULL DEFAULT 0',
    'totp_last_step' => '{BIG} NOT NULL DEFAULT 0',
];
const SCHEMA_RECOVERY_DDL = "
    CREATE TABLE user_recovery_codes (
        id {ID},
        user_id {I} NOT NULL,
        code_hash {S} NOT NULL,
        used_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_recovery_user ON user_recovery_codes(user_id);
";

/**
 * v19: ERP de solo consulta (clientes, facturas y líneas de NCS; ver app/erp.php). Tablas aparte del catálogo y
 * FUERA de DB_TABLES (lista ERP_TABLES): llevan datos personales y no viajan en las copias de seguridad salvo que se pida.
 * Importes en céntimos (enteros); fechas como aaaammdd (entero); sin claves foráneas (se sincroniza en cualquier orden).
 */
const SCHEMA_ERP_DDL = "
    CREATE TABLE erp_customers (
        id {ID},
        cust_code {K} NOT NULL UNIQUE,
        name {S} NOT NULL DEFAULT '',
        trade_name {S} NOT NULL DEFAULT '',
        nif {S} NOT NULL DEFAULT '',
        address {S} NOT NULL DEFAULT '',
        city {S} NOT NULL DEFAULT '',
        postcode {S} NOT NULL DEFAULT '',
        province {S} NOT NULL DEFAULT '',
        country {S} NOT NULL DEFAULT '',
        phone {S} NOT NULL DEFAULT '',
        phone2 {S} NOT NULL DEFAULT '',
        email {S} NOT NULL DEFAULT '',
        contact {S} NOT NULL DEFAULT '',
        pay_code {S} NOT NULL DEFAULT '',
        tariff {I} NOT NULL DEFAULT 0,
        disc_bp {I} NOT NULL DEFAULT 0,
        blocked {I} NOT NULL DEFAULT 0,
        created_day {I} NOT NULL DEFAULT 0,
        ended_day {I} NOT NULL DEFAULT 0,
        row_hash {S} NOT NULL,
        synced_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE TABLE erp_invoices (
        id {ID},
        inv_number {K} NOT NULL UNIQUE,
        cust_code {K} NOT NULL DEFAULT '',
        inv_day {I} NOT NULL DEFAULT 0,
        inv_year {I} NOT NULL DEFAULT 0,
        inv_month {I} NOT NULL DEFAULT 0,
        subtotal_cents {BIG} NOT NULL DEFAULT 0,
        base_cents {BIG} NOT NULL DEFAULT 0,
        vat_cents {BIG} NOT NULL DEFAULT 0,
        total_cents {BIG} NOT NULL DEFAULT 0,
        oper {S} NOT NULL DEFAULT '',
        seller {S} NOT NULL DEFAULT '',
        pay_code {S} NOT NULL DEFAULT '',
        annulled {I} NOT NULL DEFAULT 0,
        rectifies {S} NOT NULL DEFAULT '',
        iva_incl {I} NOT NULL DEFAULT 0,
        vat_json {T} NULL,
        origin {S} NOT NULL DEFAULT 'ncs',
        delivered_cents {BIG} NOT NULL DEFAULT 0,
        row_hash {S} NOT NULL,
        synced_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_erp_inv_cust ON erp_invoices(cust_code);
    CREATE INDEX idx_erp_inv_day ON erp_invoices(inv_day);
    CREATE INDEX idx_erp_inv_ym ON erp_invoices(inv_year, inv_month);
    CREATE TABLE erp_invoice_lines (
        id {ID},
        invoice_id {I} NOT NULL,
        src_key {K} NOT NULL UNIQUE,
        line_no {I} NOT NULL DEFAULT 0,
        article_code {K} NOT NULL DEFAULT '',
        title {S} NOT NULL DEFAULT '',
        qty_milli {BIG} NOT NULL DEFAULT 0,
        price_cents {BIG} NOT NULL DEFAULT 0,
        disc_bp {I} NOT NULL DEFAULT 0,
        amount_cents {BIG} NOT NULL DEFAULT 0,
        vat_code {S} NOT NULL DEFAULT '',
        size_no {I} NOT NULL DEFAULT 0,
        color_no {I} NOT NULL DEFAULT 0,
        size_txt {S} NOT NULL DEFAULT '',
        color_txt {S} NOT NULL DEFAULT ''
    );
    CREATE INDEX idx_erp_line_inv ON erp_invoice_lines(invoice_id);
    CREATE INDEX idx_erp_line_art ON erp_invoice_lines(article_code);
    CREATE TABLE erp_notes (
        id {ID},
        cust_code {K} NOT NULL,
        note {T} NOT NULL,
        user_id {I} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        created_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_erp_notes_cust ON erp_notes(cust_code, created_at);
";

/** v20: texto real de la talla y el color en las líneas (de FALMACEN) y notas internas de clientes. Las notas se escriben en la web: no se recuperan sincronizando. */
const SCHEMA_ERP_V20_COLUMNS = [
    'size_txt' => "{S} NOT NULL DEFAULT ''",
    'color_txt' => "{S} NOT NULL DEFAULT ''",
];
/** v21: IVA incluido y desglose por tipo (JSON: [{i, pct, base, cuota, rec, ret}] en céntimos) para la factura como documento. */
const SCHEMA_ERP_V21_COLUMNS = [
    'iva_incl' => '{I} NOT NULL DEFAULT 0',
    'vat_json' => '{T} NULL',
];
/**
 * v22: formulario de factura. Datos PROPIOS del CMS sobre las facturas (estado de cobro, etiquetas, correo de envío, notas,
 * historial, envíos por correo y enlaces públicos con token). La sincronización con NCS no los toca. `origin` es «ncs»
 * (solo lectura) o, el día que el CMS emita facturas propias, «cms» (editables). `delivered_cents` = ENTREGADO de NCS.
 */
const SCHEMA_ERP_V22_COLUMNS = [
    'origin' => "{S} NOT NULL DEFAULT 'ncs'",
    'delivered_cents' => '{BIG} NOT NULL DEFAULT 0',
];
/** Versión 24: fecha de la primera apertura de cada enlace de factura (B3b). */
const SCHEMA_ERP_V24_COLUMNS = [
    'first_view_at' => '{BIG} NOT NULL DEFAULT 0',
];
const SCHEMA_ERP_DOCS_DDL = "
    CREATE TABLE erp_invoice_state (
        id {ID},
        invoice_id {I} NOT NULL UNIQUE,
        paid {I} NOT NULL DEFAULT 0,
        paid_day {I} NOT NULL DEFAULT 0,
        tags {S} NOT NULL DEFAULT '',
        email_to {S} NOT NULL DEFAULT '',
        updated_at {BIG} NOT NULL DEFAULT 0,
        updated_by {S} NOT NULL DEFAULT ''
    );
    CREATE TABLE erp_invoice_notes (
        id {ID},
        invoice_id {I} NOT NULL,
        note {T} NOT NULL,
        user_id {I} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        created_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_erp_inote_inv ON erp_invoice_notes(invoice_id, created_at);
    CREATE TABLE erp_invoice_events (
        id {ID},
        invoice_id {I} NOT NULL,
        kind {S} NOT NULL DEFAULT '',
        detail {S} NOT NULL DEFAULT '',
        user_id {I} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        created_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_erp_ievent_inv ON erp_invoice_events(invoice_id, created_at);
    CREATE TABLE erp_invoice_links (
        id {ID},
        invoice_id {I} NOT NULL,
        token_hash {K} NOT NULL UNIQUE,
        expires_at {BIG} NOT NULL DEFAULT 0,
        created_at {BIG} NOT NULL DEFAULT 0,
        created_by {S} NOT NULL DEFAULT '',
        email_to {S} NOT NULL DEFAULT '',
        revoked_at {BIG} NOT NULL DEFAULT 0,
        views {I} NOT NULL DEFAULT 0,
        first_view_at {BIG} NOT NULL DEFAULT 0,
        last_view_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_erp_ilink_inv ON erp_invoice_links(invoice_id);
    CREATE TABLE erp_invoice_sends (
        id {ID},
        invoice_id {I} NOT NULL,
        link_id {I} NOT NULL DEFAULT 0,
        to_email {S} NOT NULL DEFAULT '',
        subject {S} NOT NULL DEFAULT '',
        status {S} NOT NULL DEFAULT '',
        error {S} NOT NULL DEFAULT '',
        user_id {I} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        sent_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_erp_isend_inv ON erp_invoice_sends(invoice_id, sent_at);
";
const SCHEMA_ERP_NOTES_DDL = "
    CREATE TABLE erp_notes (
        id {ID},
        cust_code {K} NOT NULL,
        note {T} NOT NULL,
        user_id {I} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        created_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_erp_notes_cust ON erp_notes(cust_code, created_at);
";

/**
 * Registro de los últimos 50 correos enviados (Web → Correo). Sin el cuerpo. Lleva direcciones de correo (a veces de clientes),
 * así que NO está en DB_TABLES: no viaja en las copias de seguridad (igual que data/mail.php).
 */
const SCHEMA_MAIL_DDL = "
    CREATE TABLE mail_log (
        id {ID},
        sent_at {BIG} NOT NULL DEFAULT 0,
        to_email {S} NOT NULL DEFAULT '',
        subject {S} NOT NULL DEFAULT '',
        ok {I} NOT NULL DEFAULT 0,
        transport {S} NOT NULL DEFAULT '',
        error {S} NOT NULL DEFAULT ''
    );
";

/**
 * Historial de descargas de la copia de seguridad (Configuración → Copias de seguridad). Solo usuario, fecha y tamaño.
 * Como mail_log, queda fuera de DB_TABLES: restaurar una copia no debe reescribir quién descargó qué.
 */
const SCHEMA_BACKUP_DDL = "
    CREATE TABLE backup_log (
        id {ID},
        created_at {BIG} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        with_erp {I} NOT NULL DEFAULT 0,
        size_bytes {BIG} NOT NULL DEFAULT 0
    );
";

/** Esquema completo, válido para SQLite, MariaDB/MySQL y SQL Server (ver db_ddl() en app/db.php). */
const SCHEMA_DDL = "
    CREATE TABLE users (
        id {ID},
        username {K} NOT NULL UNIQUE,
        pass_hash {S} NOT NULL,
        role {S} NOT NULL DEFAULT 'admin',
        email {S} NOT NULL DEFAULT '',
        failed_attempts {I} NOT NULL DEFAULT 0,
        last_failed_at {BIG} NOT NULL DEFAULT 0,
        locked_until {BIG} NOT NULL DEFAULT 0,
        last_login_at {BIG} NOT NULL DEFAULT 0,
        totp_secret {S} NOT NULL DEFAULT '',
        totp_enabled {I} NOT NULL DEFAULT 0,
        totp_last_step {BIG} NOT NULL DEFAULT 0
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
" . SCHEMA_FIELDS_DDL . SCHEMA_PROFILES_DDL . SCHEMA_PAGES_DDL . SCHEMA_DEVICES_DDL . SCHEMA_RECOVERY_DDL . SCHEMA_ERP_DDL . SCHEMA_ERP_DOCS_DDL . SCHEMA_MAIL_DDL . SCHEMA_BACKUP_DDL;

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
    if ($ver < 17) {
        $have = db_columns($pdo, 'users');
        foreach (SCHEMA_USER_SECURITY_COLUMNS as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'users', $col, $def);
            }
        }
        if (!in_array('login_devices', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_DEVICES_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
    }
    if ($ver < 18) {
        $have = db_columns($pdo, 'users');
        foreach (SCHEMA_USER_TOTP_COLUMNS as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'users', $col, $def);
            }
        }
        if (!in_array('user_recovery_codes', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_RECOVERY_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
    }
    if ($ver < 19 && !in_array('erp_customers', db_tables($pdo), true)) {
        foreach (db_ddl(SCHEMA_ERP_DDL, $pdo->drv) as $st) {
            $pdo->rawExec($st);
        }
    }
    if ($ver < 20 && in_array('erp_customers', db_tables($pdo), true)) {
        $have = db_columns($pdo, 'erp_invoice_lines');
        foreach (SCHEMA_ERP_V20_COLUMNS as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'erp_invoice_lines', $col, $def);
            }
        }
        if (!in_array('erp_notes', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_ERP_NOTES_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
    }
    if ($ver < 21 && in_array('erp_invoices', db_tables($pdo), true)) {
        $have = db_columns($pdo, 'erp_invoices');
        foreach (SCHEMA_ERP_V21_COLUMNS as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'erp_invoices', $col, $def);
            }
        }
    }
    if ($ver < 22 && in_array('erp_invoices', db_tables($pdo), true)) {
        $have = db_columns($pdo, 'erp_invoices');
        foreach (SCHEMA_ERP_V22_COLUMNS as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'erp_invoices', $col, $def);
            }
        }
        if (!in_array('erp_invoice_state', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_ERP_DOCS_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
    }
    if ($ver < 25 && !in_array('backup_log', db_tables($pdo), true)) {
        foreach (db_ddl(SCHEMA_BACKUP_DDL, $pdo->drv) as $st) {
            $pdo->rawExec($st);
        }
    }
    if ($ver < 24 && in_array('erp_invoice_links', db_tables($pdo), true)) {
        $have = db_columns($pdo, 'erp_invoice_links');
        foreach (SCHEMA_ERP_V24_COLUMNS as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'erp_invoice_links', $col, $def);
            }
        }
    }
    if ($ver < 23 && !in_array('mail_log', db_tables($pdo), true)) {
        foreach (db_ddl(SCHEMA_MAIL_DDL, $pdo->drv) as $st) {
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
