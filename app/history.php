<?php
declare(strict_types=1);

/*
 * Historial de datos (Datos → Historial): una fila por cada importación, sincronización o exportación, con quién, cuándo,
 * qué, desde dónde, el resultado y un detalle CORTO con solo números (nunca nombres, NIF ni correos). Se escribe desde ahora;
 * no se reconstruyen las operaciones anteriores. history_add() nunca lanza errores: un fallo del registro no puede impedir
 * una importación, una sincronización ni una exportación.
 *
 * Quién ve qué: admin todo; gestión de catálogo (rol catalogo), solo artículos, fotos y precios; rol erp, solo clientes,
 * ventas y cobros.
 */

const HISTORY_KEEP = 1000;
const HISTORY_PER_PAGE = 50;
const HISTORY_KINDS = ['importacion' => 'Importación', 'fotos' => 'Fotos', 'sincronizacion' => 'Sincronización', 'exportacion' => 'Exportación', 'configuracion' => 'Configuración'];
const HISTORY_WHATS = ['articulos' => 'Artículos', 'fotos' => 'Fotos', 'precios' => 'Precios y stock', 'clientes' => 'Clientes', 'ventas' => 'Ventas', 'clientes_ventas' => 'Clientes y ventas', 'cobros' => 'Cobros', 'bloqueo' => 'Bloqueo de periodos', 'pruebas' => 'Facturas de prueba'];
const HISTORY_RESULTS = ['ok' => 'Correcto', 'error' => 'Error', 'cancelado' => 'Cancelado', 'parcial' => 'Parcial'];

/** Lo que puede ver cada rol: lista de valores de «what» (null = todo). */
function history_whats_for(string $role): ?array
{
    return match ($role) {
        'admin' => null,
        'erp' => ['clientes', 'ventas', 'clientes_ventas', 'cobros'],
        'compras' => ['compras'],
        default => ['articulos', 'fotos', 'precios'],
    };
}

/** Anota un hecho. $user = null → el usuario de la sesión. */
function history_add(string $kind, string $what, string $origin, string $result, string $detail = '', ?string $user = null): void
{
    try {
        $uid = 0;
        if ($user === null) {
            $uid = (int)($_SESSION['uid'] ?? 0);
            $user = $uid > 0 ? (string)(current_user()['username'] ?? '') : '';
        }
        $db = db();
        $db->prepare('INSERT INTO data_history (created_at, user_id, username, kind, what, origin, result, detail) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([time(), $uid, mb_substr($user, 0, 100), mb_substr($kind, 0, 40), mb_substr($what, 0, 40), mb_substr($origin, 0, 200), mb_substr($result, 0, 20), mb_substr($detail, 0, 250)]);
        $max = (int)$db->query('SELECT MAX(id) FROM data_history')->fetchColumn();
        if ($max > 0 && $max % 50 === 0) { // recortar de vez en cuando, no en cada fila
            $db->prepare('DELETE FROM data_history WHERE id <= ?')->execute([$max - HISTORY_KEEP]);
        }
    } catch (Throwable $e) {
        error_log('Historial de datos: ' . get_class($e)); // nunca impide la operación
    }
}

/** Condición SQL y argumentos según el rol (alias t opcional sin prefijo). */
function history_scope(string $role): array
{
    $whats = history_whats_for($role);
    if ($whats === null) {
        return ['', []];
    }
    return [' AND what IN (' . implode(',', array_fill(0, count($whats), '?')) . ')', $whats];
}

/** Pantalla /admin/historial: lista paginada con filtros por tipo y usuario. */
function admin_history(): void
{
    $role = current_role();
    [$scope, $args] = history_scope($role);
    $kind = isset(HISTORY_KINDS[$_GET['tipo'] ?? '']) ? (string)$_GET['tipo'] : '';
    $user = mb_substr(trim((string)($_GET['usuario'] ?? '')), 0, 100);
    $w = $scope;
    $a = $args;
    if ($kind !== '') {
        $w .= ' AND kind = ?';
        $a[] = $kind;
    }
    if ($user !== '') {
        $w .= ' AND username = ?';
        $a[] = $user;
    }
    $db = db();
    $st = $db->prepare('SELECT COUNT(*) FROM data_history WHERE 1=1' . $w);
    $st->execute($a);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / HISTORY_PER_PAGE));
    $page = min($pages, max(1, (int)($_GET['pag'] ?? 1)));
    $st = $db->prepare('SELECT created_at, username, kind, what, origin, result, detail FROM data_history WHERE 1=1' . $w
        . ' ORDER BY id DESC LIMIT ' . HISTORY_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * HISTORY_PER_PAGE) : ''));
    $st->execute($a);
    $rows = $st->fetchAll();
    $st = $db->prepare('SELECT DISTINCT username FROM data_history WHERE username <> \'\'' . $scope . ' ORDER BY username');
    $st->execute($args);
    $users = array_slice($st->fetchAll(PDO::FETCH_COLUMN), 0, 100);
    admin_render('history', [
        'rows' => $rows, 'kind' => $kind, 'user' => $user, 'users' => $users, 'total' => $total, 'page' => $page, 'pages' => $pages,
        'title' => 'Historial',
    ]);
}
