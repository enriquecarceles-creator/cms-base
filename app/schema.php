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
        pay_days {S} NOT NULL DEFAULT '',
        tariff {I} NOT NULL DEFAULT 0,
        disc_bp {I} NOT NULL DEFAULT 0,
        blocked {I} NOT NULL DEFAULT 0,
        created_day {I} NOT NULL DEFAULT 0,
        ended_day {I} NOT NULL DEFAULT 0,
        origin {S} NOT NULL DEFAULT 'ncs',
        row_hash {S} NOT NULL,
        synced_at {BIG} NOT NULL DEFAULT 0,
        rev {I} NOT NULL DEFAULT 0,
        updated_by {S} NOT NULL DEFAULT '',
        updated_at {BIG} NOT NULL DEFAULT 0,
        def_series {I} NOT NULL DEFAULT 0,
        doc_notes {T} NOT NULL DEFAULT '',
        imported_at {BIG} NOT NULL DEFAULT 0,
        cms_edited_at {BIG} NOT NULL DEFAULT 0,
        ext_ref {S} NOT NULL DEFAULT ''
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
        color_txt {S} NOT NULL DEFAULT '',
        kind {I} NOT NULL DEFAULT 0,
        detail {T} NOT NULL DEFAULT '',
        extra {T} NOT NULL DEFAULT ''
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

/**
 * Historial de datos (Datos → Historial): cada importación, sincronización y exportación. Sin datos personales: el detalle son
 * solo números. SÍ viaja en las copias (a diferencia de mail_log y backup_log).
 */
const SCHEMA_HISTORY_DDL = "
    CREATE TABLE data_history (
        id {ID},
        created_at {BIG} NOT NULL DEFAULT 0,
        user_id {I} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        kind {S} NOT NULL DEFAULT '',
        what {S} NOT NULL DEFAULT '',
        origin {S} NOT NULL DEFAULT '',
        result {S} NOT NULL DEFAULT '',
        detail {S} NOT NULL DEFAULT ''
    );
";

/**
 * Series de numeración de documentos (Configuración → Series): factura, rectificativa, albarán y pedido. Varias por tipo, una por
 * defecto. La numeración es correlativa por serie y año (doc_series_counters, una fila por serie y año; yr = 0 si la serie no usa
 * año). start_no = primer número del año; last_no = último usado (last_no >= start_no ⇒ ya se ha usado algún número).
 * Van dentro de las copias: restaurar no debe perder la numeración.
 */
const SCHEMA_SERIES_DDL = "
    CREATE TABLE doc_series (
        id {ID},
        doc_type {S} NOT NULL,
        letter {K} NOT NULL UNIQUE,
        descr {S} NOT NULL DEFAULT '',
        digits {I} NOT NULL DEFAULT 5,
        with_year {I} NOT NULL DEFAULT 1,
        is_default {I} NOT NULL DEFAULT 0,
        is_active {I} NOT NULL DEFAULT 1,
        created_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE TABLE doc_series_counters (
        series_id {I} NOT NULL,
        yr {I} NOT NULL,
        start_no {I} NOT NULL DEFAULT 1,
        last_no {I} NOT NULL DEFAULT 0,
        PRIMARY KEY (series_id, yr)
    );
";

/** Series iniciales (solo si no hay ninguna): W facturas (propia del CMS, no choca con las letras de NCS), R rectificativas, A albaranes y P pedidos. */
const SCHEMA_SERIES_SEED = [
    ['factura', 'W', 'Facturas'], ['rectificativa', 'R', 'Facturas rectificativas'], ['albaran', 'A', 'Albaranes'], ['pedido', 'P', 'Pedidos'],
    ['simplificada', 'T', 'Facturas simplificadas (tiques)'],
];

function schema_seed_series(CmsPdo $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM doc_series')->fetchColumn() > 0) {
        return;
    }
    $st = $pdo->prepare('INSERT INTO doc_series (doc_type, letter, descr, digits, with_year, is_default, is_active, created_at) VALUES (?, ?, ?, 5, 1, 1, 1, ?)');
    foreach (SCHEMA_SERIES_SEED as [$type, $letter, $descr]) {
        $st->execute([$type, $letter, $descr, time()]);
    }
}

/**
 * Parámetros de documentos (Configuración → Documentos): tipos de IVA, formas de pago y tarifas. Genérico: nada fijo de ningún
 * programa externo. Dentro de las copias (son configuración, sin datos personales).
 *   doc_vat_rates   pct_bp = porcentaje × 100 (2100 = 21 %); surcharge_bp = recargo de equivalencia × 100 (520 = 5,2 %).
 *   doc_pay_methods code único (el de NCS, si se importa; si no, el que se elija), name, days de vencimiento.
 *   doc_tariffs     num (1, 2, 3… el número que usan los clientes), name y ext_ref (referencia externa para enlazar después con
 *                   las tarifas de otro programa, p. ej. «PVP1»; el conector decide cómo).
 */
const SCHEMA_DOCPARAMS_DDL = "
    CREATE TABLE doc_vat_rates (
        id {ID},
        name {S} NOT NULL DEFAULT '',
        pct_bp {I} NOT NULL DEFAULT 0,
        surcharge_bp {I} NOT NULL DEFAULT 0,
        is_default {I} NOT NULL DEFAULT 0,
        is_active {I} NOT NULL DEFAULT 1,
        sort {I} NOT NULL DEFAULT 0
    );
    CREATE TABLE doc_pay_methods (
        id {ID},
        code {K} NOT NULL UNIQUE,
        name {S} NOT NULL DEFAULT '',
        days {I} NOT NULL DEFAULT 0,
        terms {S} NOT NULL DEFAULT '0',
        is_active {I} NOT NULL DEFAULT 1,
        sort {I} NOT NULL DEFAULT 0
    );
    CREATE TABLE doc_tariffs (
        id {ID},
        num {I} NOT NULL UNIQUE,
        name {S} NOT NULL DEFAULT '',
        ext_ref {S} NOT NULL DEFAULT '',
        price_field {I} NOT NULL DEFAULT 0,
        is_active {I} NOT NULL DEFAULT 1
    );
";

/**
 * Documentos propios del CMS (app/docs_engine.php): pedidos y albaranes (y, más adelante, facturas y rectificativas).
 * En DB_TABLES: van SIEMPRE en las copias (no se recuperan sincronizando), aunque lleven datos de clientes.
 *   doc_headers  doc_code = número con formato de la serie (único: las letras de serie son únicas entre todos los tipos).
 *                status: pendiente | parcial | servido | facturado | anulado. Datos del cliente copiados al guardar.
 *   doc_lines    qty_u y done_u × 10.000; price_u × 1.000.000; amount_u × 10.000; disc_bp, vat_bp, rec_bp × 100.
 *                src_line_id = línea de la que viene (albarán ← pedido); done_u = unidades servidas de la línea.
 */
const SCHEMA_DOCS_DDL = "
    CREATE TABLE doc_headers (
        id {ID},
        doc_type {S} NOT NULL,
        series_id {I} NOT NULL DEFAULT 0,
        yr {I} NOT NULL DEFAULT 0,
        doc_no {I} NOT NULL DEFAULT 0,
        doc_code {K} NOT NULL UNIQUE,
        doc_day {I} NOT NULL DEFAULT 0,
        status {S} NOT NULL DEFAULT 'pendiente',
        cust_code {K} NOT NULL DEFAULT '',
        cust_name {S} NOT NULL DEFAULT '',
        cust_nif {S} NOT NULL DEFAULT '',
        cust_address {S} NOT NULL DEFAULT '',
        cust_postcode {S} NOT NULL DEFAULT '',
        cust_city {S} NOT NULL DEFAULT '',
        cust_province {S} NOT NULL DEFAULT '',
        cust_email {S} NOT NULL DEFAULT '',
        pay_code {S} NOT NULL DEFAULT '',
        tariff {I} NOT NULL DEFAULT 0,
        vat_incl {I} NOT NULL DEFAULT 0,
        with_re {I} NOT NULL DEFAULT 0,
        ref_text {S} NOT NULL DEFAULT '',
        notes {T} NULL,
        base_cents {BIG} NOT NULL DEFAULT 0,
        vat_cents {BIG} NOT NULL DEFAULT 0,
        rec_cents {BIG} NOT NULL DEFAULT 0,
        total_cents {BIG} NOT NULL DEFAULT 0,
        vat_json {T} NULL,
        origin_id {I} NOT NULL DEFAULT 0,
        created_at {BIG} NOT NULL DEFAULT 0,
        created_by {S} NOT NULL DEFAULT '',
        updated_at {BIG} NOT NULL DEFAULT 0,
        updated_by {S} NOT NULL DEFAULT '',
        issued_at {BIG} NOT NULL DEFAULT 0,
        issued_by {S} NOT NULL DEFAULT '',
        op_day {I} NOT NULL DEFAULT 0,
        rect_of {I} NOT NULL DEFAULT 0,
        rect_mode {S} NOT NULL DEFAULT '',
        rect_reason {S} NOT NULL DEFAULT '',
        rect_text {S} NOT NULL DEFAULT '',
        sii_type {S} NOT NULL DEFAULT '',
        vat_note {S} NOT NULL DEFAULT '',
        cash_crit {I} NOT NULL DEFAULT 0,
        billed_by {I} NOT NULL DEFAULT 0,
        reg_hash {S} NOT NULL DEFAULT '',
        is_test {I} NOT NULL DEFAULT 0,
        rev {I} NOT NULL DEFAULT 0,
        dues_key {S} NOT NULL DEFAULT '',
        sup_ref {S} NOT NULL DEFAULT ''
    );
    CREATE INDEX idx_doch_type_day ON doc_headers(doc_type, doc_day);
    CREATE INDEX idx_doch_cust ON doc_headers(cust_code);
    CREATE TABLE doc_lines (
        id {ID},
        doc_id {I} NOT NULL,
        line_no {I} NOT NULL DEFAULT 0,
        product_id {I} NOT NULL DEFAULT 0,
        ref {S} NOT NULL DEFAULT '',
        title {S} NOT NULL DEFAULT '',
        size_txt {S} NOT NULL DEFAULT '',
        color_txt {S} NOT NULL DEFAULT '',
        qty_u {BIG} NOT NULL DEFAULT 0,
        price_u {BIG} NOT NULL DEFAULT 0,
        disc_bp {I} NOT NULL DEFAULT 0,
        vat_id {I} NOT NULL DEFAULT 0,
        vat_bp {I} NOT NULL DEFAULT 0,
        rec_bp {I} NOT NULL DEFAULT 0,
        amount_u {BIG} NOT NULL DEFAULT 0,
        src_line_id {I} NOT NULL DEFAULT 0,
        done_u {BIG} NOT NULL DEFAULT 0,
        kind {I} NOT NULL DEFAULT 0,
        detail {T} NOT NULL DEFAULT '',
        extra {T} NOT NULL DEFAULT ''
    );
    CREATE INDEX idx_docl_doc ON doc_lines(doc_id, line_no);
    CREATE INDEX idx_docl_src ON doc_lines(src_line_id);
    CREATE TABLE doc_events (
        id {ID},
        doc_id {I} NOT NULL,
        kind {S} NOT NULL,
        detail {S} NOT NULL DEFAULT '',
        user_id {I} NOT NULL DEFAULT 0,
        username {S} NOT NULL DEFAULT '',
        created_at {BIG} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_doce_doc ON doc_events(doc_id);
";

/** v30 (facturas propias): columnas nuevas de doc_headers para las bases de datos creadas con la v29. */
const SCHEMA_DOCS_V30_COLUMNS = [
    'issued_at' => '{BIG} NOT NULL DEFAULT 0', 'issued_by' => "{S} NOT NULL DEFAULT ''", 'op_day' => '{I} NOT NULL DEFAULT 0',
    'rect_of' => '{I} NOT NULL DEFAULT 0', 'rect_mode' => "{S} NOT NULL DEFAULT ''", 'rect_reason' => "{S} NOT NULL DEFAULT ''",
    'rect_text' => "{S} NOT NULL DEFAULT ''", 'sii_type' => "{S} NOT NULL DEFAULT ''", 'vat_note' => "{S} NOT NULL DEFAULT ''",
    'cash_crit' => '{I} NOT NULL DEFAULT 0', 'billed_by' => '{I} NOT NULL DEFAULT 0', 'reg_hash' => "{S} NOT NULL DEFAULT ''",
];

/**
 * Registro de facturas emitidas (doc_register): una fila por factura al emitirla, nunca se modifica ni se borra. Cada fila guarda
 * los datos fiscales (payload, JSON) y su huella sha256 encadenada con la anterior (hash = sha256(prev_hash . payload)): si alguien
 * alterase una factura o el registro, la cadena dejaría de cuadrar (docs_register_verify()). En DB_TABLES (va en las copias).
 */
const SCHEMA_INVOICES_DDL = "
    CREATE TABLE doc_register (
        id {ID},
        doc_id {I} NOT NULL,
        doc_code {K} NOT NULL UNIQUE,
        issued_at {BIG} NOT NULL DEFAULT 0,
        payload {T} NULL,
        prev_hash {S} NOT NULL DEFAULT '',
        hash {S} NOT NULL DEFAULT ''
    );
";

/**
 * Vencimientos de las facturas (doc_dues, v34): una fila por cuota de una factura (no de rectificativas ni simplificadas).
 * due_day = aaaammdd; amount_cents en céntimos (la suma de las cuotas es SIEMPRE el total de la factura); paid y paid_day quedan
 * preparados para marcar los cobros más adelante. En borrador se recalculan solos al cambiar la fecha, la forma de pago o el total
 * (doc_headers.dues_key guarda la base del cálculo) y se pueden retocar a mano; al emitir la factura quedan fijos. En DB_TABLES.
 */
const SCHEMA_DUES_DDL = "
    CREATE TABLE doc_dues (
        id {ID},
        doc_id {I} NOT NULL,
        due_no {I} NOT NULL DEFAULT 1,
        due_day {I} NOT NULL DEFAULT 0,
        amount_cents {BIG} NOT NULL DEFAULT 0,
        paid {I} NOT NULL DEFAULT 0,
        paid_day {I} NOT NULL DEFAULT 0
    );
    CREATE INDEX idx_dues_doc ON doc_dues(doc_id, due_no);
";

/**
 * v32: serie W de facturas del CMS (por defecto) para webs que ya tenían series. Si alguna factura importada de NCS ya usa la W, no se crea.
 * La F antigua se desactiva si nunca se ha usado; si se usó, queda activa pero sin ser la de por defecto.
 */
function schema_seed_w(CmsPdo $pdo): void
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM doc_series WHERE UPPER(letter) = ?');
    $st->execute(['W']);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    if (in_array('erp_invoices', db_tables($pdo), true) && (int)$pdo->query("SELECT COUNT(*) FROM erp_invoices WHERE origin = 'ncs' AND UPPER(inv_number) LIKE 'W%'")->fetchColumn() > 0) {
        return; // la W es de NCS: se queda la serie de antes
    }
    $pdo->exec("UPDATE doc_series SET is_default = 0 WHERE doc_type = 'factura'");
    $pdo->prepare('INSERT INTO doc_series (doc_type, letter, descr, digits, with_year, is_default, is_active, created_at) VALUES (?, ?, ?, 5, 1, 1, 1, ?)')
        ->execute(['factura', 'W', 'Facturas', time()]);
    $f = $pdo->query("SELECT id FROM doc_series WHERE doc_type = 'factura' AND UPPER(letter) = 'F'")->fetchColumn();
    if ($f !== false) {
        $used = (int)$pdo->query('SELECT COUNT(*) FROM doc_headers WHERE series_id = ' . (int)$f)->fetchColumn()
            + (int)$pdo->query('SELECT COUNT(*) FROM doc_series_counters WHERE series_id = ' . (int)$f . ' AND last_no > 0')->fetchColumn();
        if ($used === 0) {
            $pdo->exec('UPDATE doc_series SET is_active = 0 WHERE id = ' . (int)$f);
        }
    }
}

/** Serie de tiques (facturas simplificadas) para webs que ya tenían series: T, o TQ / TK si la T está ocupada; nada si ya hay una. */
function schema_seed_simplified(CmsPdo $pdo): void
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM doc_series WHERE doc_type = ?');
    $st->execute(['simplificada']);
    if ((int)$st->fetchColumn() > 0) {
        return;
    }
    $used = array_map('strtoupper', $pdo->query('SELECT letter FROM doc_series')->fetchAll(PDO::FETCH_COLUMN));
    foreach (['T', 'TQ', 'TK'] as $letter) {
        if (!in_array($letter, $used, true)) {
            $pdo->prepare('INSERT INTO doc_series (doc_type, letter, descr, digits, with_year, is_default, is_active, created_at) VALUES (?, ?, ?, 5, 1, 1, 1, ?)')
                ->execute(['simplificada', $letter, 'Facturas simplificadas (tiques)', time()]);
            return;
        }
    }
}


/**
 * v38: módulo de compras. Proveedores (erp_suppliers: va en DB_TABLES porque NO se recupera sincronizando con nada) y el número de la
 * factura del proveedor en doc_headers (sup_ref). Las notas internas de un proveedor usan erp_notes con el código «prov:<código>».
 */
const SCHEMA_SUPPLIERS_DDL = "
    CREATE TABLE erp_suppliers (
        id {ID},
        sup_code {K} NOT NULL UNIQUE,
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
        pay_days {S} NOT NULL DEFAULT '',
        tariff {I} NOT NULL DEFAULT 0,
        disc_bp {I} NOT NULL DEFAULT 0,
        blocked {I} NOT NULL DEFAULT 0,
        created_day {I} NOT NULL DEFAULT 0,
        ended_day {I} NOT NULL DEFAULT 0,
        def_series {I} NOT NULL DEFAULT 0,
        doc_notes {T} NOT NULL DEFAULT '',
        rev {I} NOT NULL DEFAULT 0,
        updated_by {S} NOT NULL DEFAULT '',
        updated_at {BIG} NOT NULL DEFAULT 0
    );
";

/** Series de compras (solo si no hay): PC pedidos, AC albaranes, RE facturas recibidas (número de registro RE00001/26), RC rectificativas recibidas. La R de ventas ya está ocupada. */
const SCHEMA_PURCHASE_SERIES = [['pedido_c', 'PC', 'Pedidos a proveedor'], ['albaran_c', 'AC', 'Albaranes de compra'], ['factura_c', 'RE', 'Facturas recibidas (registro)'], ['rectificativa_c', 'RC', 'Rectificativas recibidas']];

function schema_seed_purchase_series(CmsPdo $pdo): void
{
    $used = array_map('strtoupper', $pdo->query('SELECT letter FROM doc_series')->fetchAll(PDO::FETCH_COLUMN));
    foreach (SCHEMA_PURCHASE_SERIES as [$type, $letter, $descr]) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM doc_series WHERE doc_type = ?');
        $st->execute([$type]);
        if ((int)$st->fetchColumn() > 0) {
            continue;
        }
        foreach ([$letter, $letter . 'X', $letter . 'Y'] as $l) {
            if (!in_array($l, $used, true)) {
                $pdo->prepare('INSERT INTO doc_series (doc_type, letter, descr, digits, with_year, is_default, is_active, created_at) VALUES (?, ?, ?, 5, 1, 1, 1, ?)')->execute([$type, $l, $descr, time()]);
                $used[] = $l;
                break;
            }
        }
    }
}

/** Valores iniciales de IVA (21, 10, 4, 0 con sus recargos de equivalencia) y tarifas 1-3; solo si las tablas están vacías. */
function schema_seed_doc_params(CmsPdo $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM doc_vat_rates')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO doc_vat_rates (name, pct_bp, surcharge_bp, is_default, is_active, sort) VALUES (?, ?, ?, ?, 1, ?)');
        foreach ([['General (21 %)', 2100, 520, 1], ['Reducido (10 %)', 1000, 140, 0], ['Superreducido (4 %)', 400, 50, 0], ['Exento (0 %)', 0, 0, 0]] as $i => [$n, $p, $r, $d]) {
            $st->execute([$n, $p, $r, $d, ($i + 1) * 10]);
        }
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM doc_tariffs')->fetchColumn() === 0) {
        $st = $pdo->prepare('INSERT INTO doc_tariffs (num, name, ext_ref, is_active) VALUES (?, ?, \'\', 1)');
        foreach ([1, 2, 3] as $n) {
            $st->execute([$n, 'Tarifa ' . $n]);
        }
    }
}

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
" . SCHEMA_FIELDS_DDL . SCHEMA_PROFILES_DDL . SCHEMA_PAGES_DDL . SCHEMA_DEVICES_DDL . SCHEMA_RECOVERY_DDL . SCHEMA_ERP_DDL . SCHEMA_ERP_DOCS_DDL . SCHEMA_MAIL_DDL . SCHEMA_BACKUP_DDL . SCHEMA_HISTORY_DDL . SCHEMA_SERIES_DDL . SCHEMA_DOCPARAMS_DDL . SCHEMA_DOCS_DDL . SCHEMA_INVOICES_DDL . SCHEMA_DUES_DDL . SCHEMA_SUPPLIERS_DDL;

/** Crea las tablas en una base de datos vacía. */
function install_schema(CmsPdo $pdo): void
{
    foreach (db_ddl(SCHEMA_DDL, $pdo->drv) as $st) {
        $pdo->rawExec($st);
    }
    schema_seed_series($pdo);
    schema_seed_purchase_series($pdo);
    schema_seed_doc_params($pdo);
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
    if ($ver < 29) { // pedidos y albaranes; campo de precio de cada tarifa (si doc_tariffs aún no existe, la crea el bloque de la v28 ya con la columna)
        if (!in_array('doc_headers', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_DOCS_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
        if (in_array('doc_tariffs', db_tables($pdo), true) && !in_array('price_field', db_columns($pdo, 'doc_tariffs'), true)) {
            db_add_column($pdo, 'doc_tariffs', 'price_field', '{I} NOT NULL DEFAULT 0');
        }
    }
    if ($ver < 30) { // facturas propias: columnas de factura, registro encadenado y serie T de tiques
        if (in_array('doc_headers', db_tables($pdo), true)) {
            $have = db_columns($pdo, 'doc_headers');
            foreach (SCHEMA_DOCS_V30_COLUMNS as $col => $def) {
                if (!in_array($col, $have, true)) {
                    db_add_column($pdo, 'doc_headers', $col, $def);
                }
            }
        }
        if (!in_array('doc_register', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_INVOICES_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
        if (in_array('doc_series', db_tables($pdo), true)) {
            schema_seed_simplified($pdo);
        }
    }
    if ($ver < 28) {
        if (!in_array('doc_vat_rates', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_DOCPARAMS_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
        schema_seed_doc_params($pdo);
        // Formas de pago: las que ya tenían nombre (Sincronizar con NCS → Documentos) y los códigos que aparecen en clientes y facturas
        if ((int)$pdo->query('SELECT COUNT(*) FROM doc_pay_methods')->fetchColumn() === 0) {
            $names = [];
            $st = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
            $st->execute(['erp_pay_names']);
            $j = json_decode((string)$st->fetchColumn(), true);
            foreach (is_array($j) ? $j : [] as $code => $nm) {
                $names[trim((string)$code)] = mb_substr(trim((string)$nm), 0, 40);
            }
            foreach (['erp_invoices', 'erp_customers'] as $t) {
                if (in_array($t, db_tables($pdo), true)) {
                    foreach ($pdo->query("SELECT DISTINCT pay_code FROM $t WHERE pay_code <> ''")->fetchAll(PDO::FETCH_COLUMN) as $code) {
                        $names += [trim((string)$code) => ''];
                    }
                }
            }
            ksort($names, SORT_NATURAL);
            $ins = $pdo->prepare('INSERT INTO doc_pay_methods (code, name, days, is_active, sort) VALUES (?, ?, 0, 1, ?)');
            $i = 0;
            foreach ($names as $code => $nm) {
                $code = (string)$code; // ojo: un código numérico como «12» llega como entero (clave de array)
                if ($code !== '' && mb_strlen($code) <= 20) {
                    $ins->execute([$code, $nm, (++$i) * 10]);
                }
            }
        }
    }
    if ($ver < 27) {
        if (!in_array('doc_series', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_SERIES_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
        schema_seed_series($pdo);
    }
    if ($ver < 26 && !in_array('data_history', db_tables($pdo), true)) {
        foreach (db_ddl(SCHEMA_HISTORY_DDL, $pdo->drv) as $st) {
            $pdo->rawExec($st);
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
    // v38: compras: proveedores, número de factura del proveedor y series de compra
    if ($ver < 38) {
        if (!in_array('erp_suppliers', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_SUPPLIERS_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
        if (in_array('doc_headers', db_tables($pdo), true) && !in_array('sup_ref', db_columns($pdo, 'doc_headers'), true)) {
            db_add_column($pdo, 'doc_headers', 'sup_ref', "{S} NOT NULL DEFAULT ''");
        }
        if (in_array('doc_series', db_tables($pdo), true)) {
            schema_seed_purchase_series($pdo);
        }
    }
    // v37: clientes traspasados editables: cuándo se traspasaron, cuándo se modificaron en el CMS (la sincronización no los pisa) y el código que tenían en NCS
    if ($ver < 37 && in_array('erp_customers', db_tables($pdo), true)) {
        $have = db_columns($pdo, 'erp_customers');
        foreach (['imported_at' => '{BIG} NOT NULL DEFAULT 0', 'cms_edited_at' => '{BIG} NOT NULL DEFAULT 0', 'ext_ref' => "{S} NOT NULL DEFAULT ''"] as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'erp_customers', $col, $def);
            }
        }
        $pdo->exec("UPDATE erp_customers SET imported_at = synced_at WHERE origin = 'ncs' AND imported_at = 0 AND synced_at > 0");
    }
    // v36: ficha de cliente editable: versión (avisa de cambios simultáneos), quién y cuándo, serie por defecto y notas para documentos
    if ($ver < 36 && in_array('erp_customers', db_tables($pdo), true)) {
        $have = db_columns($pdo, 'erp_customers');
        foreach (['rev' => '{I} NOT NULL DEFAULT 0', 'updated_by' => "{S} NOT NULL DEFAULT ''", 'updated_at' => '{BIG} NOT NULL DEFAULT 0',
                  'def_series' => '{I} NOT NULL DEFAULT 0', 'doc_notes' => "{T} NOT NULL DEFAULT ''"] as $col => $def) {
            if (!in_array($col, $have, true)) {
                db_add_column($pdo, 'erp_customers', $col, $def);
            }
        }
    }
    // v35: campos libres de las líneas de documentos (Lote…): valores en JSON en la columna «extra»
    if ($ver < 35) {
        foreach (['doc_lines', 'erp_invoice_lines'] as $t) {
            if (in_array($t, db_tables($pdo), true) && !in_array('extra', db_columns($pdo, $t), true)) {
                db_add_column($pdo, $t, 'extra', "{T} NOT NULL DEFAULT ''");
            }
        }
    }
    // v34: plazos de las formas de pago (terms), día(s) de pago del cliente, base de los vencimientos y tabla doc_dues
    if ($ver < 34) {
        foreach ([['doc_pay_methods', ['terms' => "{S} NOT NULL DEFAULT '0'"]], ['erp_customers', ['pay_days' => "{S} NOT NULL DEFAULT ''"]],
                  ['doc_headers', ['dues_key' => "{S} NOT NULL DEFAULT ''"]]] as [$t, $cols]) {
            if (in_array($t, db_tables($pdo), true)) {
                $have = db_columns($pdo, $t);
                foreach ($cols as $col => $def) {
                    if (!in_array($col, $have, true)) {
                        db_add_column($pdo, $t, $col, $def);
                    }
                }
            }
        }
        // Las formas de pago que tenían «días» pasan a tener ese plazo (una sola cuota); 0 = contado
        if (in_array('doc_pay_methods', db_tables($pdo), true)) {
            foreach ($pdo->query('SELECT id, days FROM doc_pay_methods WHERE days > 0')->fetchAll() as $r) { // en PHP: CAST a texto no es igual en los tres motores
                $pdo->prepare('UPDATE doc_pay_methods SET terms = ? WHERE id = ?')->execute([(string)(int)$r['days'], (int)$r['id']]);
            }
        }
        if (!in_array('doc_dues', db_tables($pdo), true)) {
            foreach (db_ddl(SCHEMA_DUES_DDL, $pdo->drv) as $st) {
                $pdo->rawExec($st);
            }
        }
    }
    // v33: versión de cada documento (aviso de cambios simultáneos), líneas de texto libre y descripción de varias líneas
    if ($ver < 33) {
        foreach ([['doc_headers', ['rev' => '{I} NOT NULL DEFAULT 0']],
                  ['doc_lines', ['kind' => '{I} NOT NULL DEFAULT 0', 'detail' => "{T} NOT NULL DEFAULT ''"]],
                  ['erp_invoice_lines', ['kind' => '{I} NOT NULL DEFAULT 0', 'detail' => "{T} NOT NULL DEFAULT ''"]]] as [$t, $cols]) {
            if (in_array($t, db_tables($pdo), true)) {
                $have = db_columns($pdo, $t);
                foreach ($cols as $col => $def) {
                    if (!in_array($col, $have, true)) {
                        db_add_column($pdo, $t, $col, $def);
                    }
                }
            }
        }
    }
    // v32: columna is_test (facturas de prueba) y serie W de facturas
    if ($ver < 32) {
        if (in_array('doc_headers', db_tables($pdo), true) && !in_array('is_test', db_columns($pdo, 'doc_headers'), true)) {
            db_add_column($pdo, 'doc_headers', 'is_test', '{I} NOT NULL DEFAULT 0');
        }
        if (in_array('doc_series', db_tables($pdo), true)) {
            schema_seed_w($pdo);
        }
    }
    // v31: clientes dados de alta a mano en el CMS (origin = 'cms'); los de NCS quedan con 'ncs'
    if ($ver < 31 && in_array('erp_customers', db_tables($pdo), true) && !in_array('origin', db_columns($pdo, 'erp_customers'), true)) {
        db_add_column($pdo, 'erp_customers', 'origin', "{S} NOT NULL DEFAULT 'ncs'");
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
