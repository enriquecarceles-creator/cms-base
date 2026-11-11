<?php
declare(strict_types=1);

/*
 * Datos → Importar como asistente «¿Qué quieres traer? → ¿De dónde?» y Datos → Sincronización (solo la pantalla de estado).
 *
 * El asistente es una puerta de entrada delante de los importadores que ya existen (app/importers/*.php), que no cambian:
 *   - Paso 1 (/admin/importar): cinco tarjetas según el rol (catalogo: Artículos, Fotos, Precios y stock; erp: Clientes y Ventas).
 *   - Paso 2 (/admin/importar?que=…): solo los orígenes compatibles con lo elegido. Cada importador puede declarar qué trae con
 *     'kinds' => ['articulos', 'precios', 'fotos']; si no lo declara, vale IMPORT_KINDS (por id) o, por defecto, 'articulos'.
 * NCS se recomienda si en data/importar/ hay archivos con sus nombres (FALMACEN, FCUENTAS, FHISTORI); la comprobación por tablas
 * ocurre al analizar (leer los 15–20 MB de cada MDB en cada visita sería demasiado).
 */

const IMPORT_WHATS = [
    'articulos' => ['Artículos', 'Trae artículos, familias, textos y fotos desde una hoja de cálculo, otra web o la base de datos de otra aplicación.'],
    'fotos' => ['Fotos', 'Añade fotos a los artículos que ya están en la web: desde carpetas por artículo o desde fotos subidas por FTP.'],
    'precios' => ['Precios y stock', 'Pone al día precios y stock de los artículos que ya existen, desde la base de datos de tu programa de gestión. No crea ni borra artículos.'],
    'clientes' => ['Clientes', 'Trae la lista de clientes desde tu programa de facturación.'],
    'ventas' => ['Ventas', 'Trae las facturas y sus líneas desde tu programa de facturación.'],
];

/** Qué trae cada importador, por id, si él mismo no lo declara con 'kinds'. */
const IMPORT_KINDS = [
    'woocommerce' => ['articulos'], 'hoja-calculo' => ['articulos'], 'wordpress-web' => ['articulos'],
    'base-datos' => ['articulos', 'precios'], 'fotos-carpetas' => ['fotos'],
];

function import_source_kinds(array $src): array
{
    return (array)($src['kinds'] ?? IMPORT_KINDS[$src['id']] ?? ['articulos']);
}

/** ¿Puede este rol traer eso? Gestión de catálogo: artículos, fotos y precios; ERP: clientes y ventas; admin: todo. */
function import_what_allowed(string $what, string $role): bool
{
    if ($role === 'admin') {
        return isset(IMPORT_WHATS[$what]);
    }
    if ($role === 'erp') {
        return in_array($what, ['clientes', 'ventas'], true);
    }
    return in_array($what, ['articulos', 'fotos', 'precios'], true);
}

/** Archivos de NCS dejados en data/importar/ (por nombre): ['almacen' => bool, 'cuentas' => bool, 'historico' => bool]. */
function import_detect_ncs(): array
{
    $f = erp_ftp_sources();
    return ['almacen' => $f['almacen'] !== null, 'cuentas' => $f['cuentas'] !== null, 'historico' => $f['historico'] !== null];
}

/** ¿Hay archivos de NCS para esto? */
function import_ncs_hint(string $what, array $ncs): bool
{
    return match ($what) {
        'articulos', 'precios' => $ncs['almacen'],
        'clientes' => $ncs['cuentas'],
        'ventas' => $ncs['historico'],
        default => false,
    };
}

/** Paso 1 y paso 2 de las opciones que no son formularios de importador (fotos, clientes, ventas). Devuelve true si ha pintado algo. */
function admin_import_wizard(string $que): bool
{
    $role = current_role();
    $ncs = import_detect_ncs();
    if ($que === '' || !import_what_allowed($que, $role)) {
        $cards = [];
        foreach (IMPORT_WHATS as $k => [$title, $text]) {
            if (import_what_allowed($k, $role)) {
                $cards[$k] = [$title, $text, import_ncs_hint($k, $ncs)];
            }
        }
        admin_render('import_wizard', ['step' => 1, 'cards' => $cards, 'title' => 'Importar']);
        return true;
    }
    if (in_array($que, ['fotos', 'clientes', 'ventas'], true)) {
        admin_render('import_wizard', ['step' => 2, 'que' => $que, 'what' => IMPORT_WHATS[$que], 'ncs' => import_ncs_hint($que, $ncs), 'crumb' => IMPORT_WHATS[$que][0], 'title' => 'Importar']);
        return true;
    }
    return false; // artículos y precios: el formulario de los importadores, filtrado
}

/** Datos → Sincronización: SOLO la pantalla de estado. La sincronización automática real es una tarea posterior. */
function admin_sync_status(): void
{
    $last = json_decode(db_setting('erp_last_sync'), true);
    admin_render('sync_status', [
        'last' => is_array($last) && isset($last['at']) ? $last : null,
        'ncs' => import_detect_ncs(),
        'totals' => erp_totals(),
        'title' => 'Sincronización',
    ]);
}
