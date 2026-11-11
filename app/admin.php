<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/erp.php';

function admin_dispatch(array $segs): void
{
    start_session();
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Frame-Options: SAMEORIGIN');
    $page = $segs[1] ?? '';

    $users = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($users === 0) {
        if (is_file(ROOT . '/instalar.php')) {
            redirect('/instalar.php');
        }
        render('admin/nosetup', ['title' => 'Falta el instalador'], 'admin/layout_bare');
        return;
    }
    if ($page === 'login') {
        admin_login();
        return;
    }
    if ($page === 'verificar') {
        admin_login_code();
        return;
    }
    if ($page === 'olvido') {
        admin_forgot();
        return;
    }
    if ($page === 'restablecer') {
        admin_reset((string)($segs[2] ?? ''));
        return;
    }
    if ($page === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            $_SESSION = [];
            session_destroy();
        }
        redirect('/admin/login');
    }
    require_login();
    $role = current_role();
    if ($role === 'erp') { // el rol ERP solo ve Clientes, Ventas, Sincronizar y su cuenta
        if ($page === '' || !in_array($page, [...ERP_PAGES, 'clave'], true)) {
            redirect('/admin/clientes');
        }
    } elseif ($role !== 'admin' && in_array($page, ERP_PAGES, true)) {
        flash('Tu usuario no tiene acceso a esa sección.', 'err');
        redirect('/admin');
    }
    if (!is_admin() && in_array($page, ['representantes', 'empresa', 'pagina-inicio', 'pagina-catalogo', 'paginas', 'menu', 'configuracion', 'campos', 'slider', 'usuarios', 'copia', 'reiniciar'], true)) {
        flash('Tu usuario no tiene acceso a esa sección.', 'err');
        redirect('/admin');
    }

    switch ($page) {
        case '':
            // Direcciones antiguas: /admin?c=…, ?q=… eran la lista de artículos → redirección temporal
            if (array_intersect(array_keys($_GET), ['c', 'cat', 'q', 'visible', 'foto', 'precio'])) {
                $q = $_GET;
                if (isset($q['cat']) && !isset($q['c'])) {
                    $q['c'] = $q['cat'];
                }
                header('Location: ' . url(admin_list_url(admin_list_query($q))), true, 302);
                exit;
            }
            admin_home();
            break;
        case 'articulos':
            admin_products();
            break;
        case 'articulo':
            admin_product($segs[2] ?? '');
            break;
        case 'categorias':
            admin_categories();
            break;
        case 'representantes':
            admin_reps();
            break;
        case 'empresa':
            admin_company();
            break;
        case 'pagina-inicio':
            admin_home_page();
            break;
        case 'paginas':
            admin_pages((string)($segs[2] ?? ''));
            break;
        case 'menu':
            admin_site_menu();
            break;
        case 'pagina-catalogo':
            admin_catalog_page();
            break;
        case 'configuracion':
            admin_config();
            break;
        case 'campos':
            admin_fields();
            break;
        case 'importar':
            admin_import((string)($segs[2] ?? ''));
            break;
        case 'slider':
            admin_slider();
            break;
        case 'portada':
            admin_cover((int)($segs[2] ?? 0));
            break;
        case 'ftp':
            admin_ftp();
            break;
        case 'fotos':
            admin_photos();
            break;
        case 'clientes':
            admin_clients((string)($segs[2] ?? ''));
            break;
        case 'ventas':
            $sub = (string)($segs[2] ?? '');
            if ($sub === '' || isset(ERP_SALES_TABS[$sub])) {
                admin_sales($sub);
            } else {
                admin_invoice($sub);
            }
            break;
        case 'erp':
            admin_erp_sync();
            break;
        case 'usuarios':
            admin_users();
            break;
        case 'copia':
            admin_backup();
            break;
        case 'reiniciar':
            admin_reset_catalog();
            break;
        case 'clave':
            admin_password();
            break;
        default:
            not_found();
    }
}

function admin_render(string $view, array $vars = []): void
{
    $vars['flashes'] = take_flash();
    $vars['title'] = ($vars['title'] ?? 'Panel') . ' · Panel ' . site_name();
    render('admin/' . $view, $vars, 'admin/layout');
}

/* ---------- Iconos del panel (SVG en línea, de trazo, sin librerías) ---------- */

/** Icono SVG decorativo (aria-hidden). Usa currentColor, así toma el color del texto. */
function admin_icon(string $name): string
{
    static $paths = [
        'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h5v-6h4v6h5V10"/>',
        'catalog' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'import' => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/>',
        'web' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.6-3.6 3.2-5.5 6.5-5.5s5.9 1.9 6.5 5.5"/><circle cx="17" cy="9" r="2.5"/><path d="M16.5 14.5c2.6.2 4.4 1.9 5 4.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c.8-4.2 4-6.5 8-6.5s7.2 2.3 8 6.5"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/>',
        'external' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v6H4V6h6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'folder' => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5H9l2 2.5h8.5A1.5 1.5 0 0 1 21 9v9.5a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5z"/>',
        'download' => '<path d="M12 4v11"/><path d="M7 10l5 5 5-5"/><path d="M5 20h14"/>',
        'alert' => '<path d="M12 3l9.5 17h-19z"/><path d="M12 10v4.5"/><path d="M12 17.5v.01"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6"/><path d="M12 7.5v.01"/>',
        'chevron' => '<path d="M9 6l6 6-6 6"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Menú del panel: secciones con sus páginas. Cada página: [etiqueta, ruta, segmentos que la marcan, solo admin].
 * Respeta roles (solo admin) y módulos desactivados (slider, representantes).
 */
function admin_menu(): array
{
    $adm = is_admin();
    $menu = [
        'home' => ['Inicio', 'home', [['Inicio', '/admin', [''], false]]],
        'catalog' => ['Catálogo', 'catalog', [
            ['Artículos', '/admin/articulos', ['articulos', 'articulo'], false],
            [term('families'), '/admin/categorias', ['categorias', 'portada'], false],
            ['Fotos', '/admin/fotos', ['fotos'], false],
        ]],
        'import' => ['Importar', 'import', [
            ['Importar artículos', '/admin/importar', ['importar'], false],
            ['Fotos por carpeta', '/admin/importar/carpetas', ['importar/carpetas'], false],
            ['Fotos por FTP', '/admin/ftp', ['ftp'], false],
        ]],
        'web' => ['Web', 'web', [
            ['Empresa', '/admin/empresa', ['empresa'], true],
            ['Página de inicio', '/admin/pagina-inicio', ['pagina-inicio', 'slider'], true],
            ['Página del catálogo', '/admin/pagina-catalogo', ['pagina-catalogo'], true],
            ['Páginas', '/admin/paginas', ['paginas'], true],
            ['Menú', '/admin/menu', ['menu'], true],
            ['Configuración', '/admin/configuracion', ['configuracion'], true],
            ['Campos', '/admin/campos', ['campos'], true],
            ...(feature('reps') ? [['Representantes', '/admin/representantes', ['representantes'], true]] : []),
        ]],
        'erp' => ['Clientes y Ventas', 'users', [
            ['Clientes', '/admin/clientes', ['clientes'], false],
            ['Ventas', '/admin/ventas', ['ventas'], false],
            ['Sincronizar con NCS', '/admin/erp', ['erp'], false],
        ]],
        'users' => ['Usuarios', 'users', [['Usuarios', '/admin/usuarios', ['usuarios'], true]]],
    ];
    $role = current_role();
    if ($role === 'erp') {
        $menu = ['erp' => $menu['erp']];
    } elseif ($role !== 'admin') {
        unset($menu['erp']);
    }
    foreach ($menu as $k => &$sec) {
        $sec[2] = array_values(array_filter($sec[2], fn($p) => !$p[3] || $adm));
        if (!$sec[2]) {
            unset($menu[$k]);
        }
    }
    unset($sec);
    return $menu;
}

/**
 * Tramos de la dirección del panel tras /admin: '' (Inicio), 'articulos', 'articulo/310', 'importar/carpetas'…
 * Con $full = false solo el primero.
 */
function admin_segment(bool $full = false): string
{
    $path = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '');
    $base = url('/admin');
    $rest = trim(str_starts_with($path, $base) ? substr($path, strlen($base)) : '', '/');
    return $full ? $rest : (explode('/', $rest)[0] ?? '');
}

/* ---------- Primer uso: crear la cuenta ---------- */

/* ---------- Acceso ---------- */

function admin_login(): void
{
    auth_ftp_disable_totp(); // data/sin-2fa.txt (recuperación por FTP)
    $errors = [];
    $db = db();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $db->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
    $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $st->execute([$ip]);
    $locked = (int)$st->fetchColumn() >= 8;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if ($locked) {
            $errors[] = 'Demasiados intentos. Espera 15 minutos y vuelve a probar.';
        } else {
            $u = $db->prepare('SELECT * FROM users WHERE username = ?');
            $u->execute([trim((string)($_POST['username'] ?? ''))]);
            $user = $u->fetch();
            $pass = (string)($_POST['password'] ?? '');
            // Una sola operación de contraseña exista o no el usuario (comprobar o crear un hash cuesta lo mismo):
            // el tiempo de respuesta no delata si el usuario existe.
            if ($user) {
                $ok = password_verify($pass, (string)$user['pass_hash']);
            } else {
                password_hash($pass, PASSWORD_DEFAULT);
                $ok = false;
            }
            if ($ok && !auth_locked($user) && (int)$user['totp_enabled'] === 1) {
                // Contraseña bien, pero falta el código: todavía NO hay sesión (uid); solo un paso pendiente de 5 minutos.
                session_regenerate_id(true);
                $_SESSION['pending_uid'] = (int)$user['id'];
                $_SESSION['pending_at'] = time();
                redirect('/admin/verificar');
            }
            if ($ok && !auth_locked($user)) {
                $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$user['id'];
                auth_after_login($user); // limpia fallos, anota el acceso y avisa por correo si el sitio es nuevo
                redirect('/admin');
            }
            $db->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
            if ($user && !auth_locked($user)) {
                auth_register_failure((int)$user['id']); // a los 5 fallos seguidos, 15 minutos bloqueado
            }
            $errors[] = AUTH_GENERIC_ERROR; // el mismo mensaje si no existe, si la contraseña falla o si está bloqueado
        }
    } elseif ($locked) {
        $errors[] = 'Demasiados intentos. Espera 15 minutos y vuelve a probar.';
    }
    render('admin/login', ['flashes' => take_flash(), 'errors' => $errors, 'title' => 'Acceso · Panel ' . site_name()], 'admin/layout_bare');
}

/** Segundo paso del acceso: código de la app (6 dígitos) o de recuperación. Sin esto no se crea la sesión. */
function admin_login_code(): void
{
    $db = db();
    $uid = (int)($_SESSION['pending_uid'] ?? 0);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $user = null;
    if ($uid > 0 && time() - (int)($_SESSION['pending_at'] ?? 0) <= TOTP_PENDING_SECS) {
        $st = $db->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$uid]);
        $user = $st->fetch() ?: null;
    }
    if (!$user || (int)$user['totp_enabled'] !== 1 || auth_locked($user)) {
        unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
        flash($user ? AUTH_GENERIC_ERROR : 'La verificación ha caducado. Vuelve a entrar.', 'err');
        redirect('/admin/login');
    }
    $errors = [];
    $db->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
    $cnt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
    $cnt->execute([$ip]);
    $locked = (int)$cnt->fetchColumn() >= 8;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        if ($locked) {
            $errors[] = 'Demasiados intentos. Espera 15 minutos y vuelve a probar.';
        } elseif (auth_verify_second_factor($user, (string)($_POST['code'] ?? ''))) {
            $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            session_regenerate_id(true);
            unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
            $_SESSION['uid'] = (int)$user['id'];
            auth_after_login($user);
            redirect('/admin');
        } else {
            $db->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
            auth_register_failure((int)$user['id']); // los códigos fallidos cuentan para el mismo bloqueo por usuario (5 → 15 min)
            $st = $db->prepare('SELECT locked_until FROM users WHERE id = ?');
            $st->execute([$uid]);
            if (auth_locked(['locked_until' => $st->fetchColumn()])) {
                unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
                flash(AUTH_GENERIC_ERROR, 'err');
                redirect('/admin/login');
            }
            $errors[] = 'Código incorrecto. Si fallas varias veces seguidas, el acceso se bloquea unos minutos.';
        }
    } elseif ($locked) {
        $errors[] = 'Demasiados intentos. Espera 15 minutos y vuelve a probar.';
    }
    render('admin/login_code', ['errors' => $errors, 'title' => 'Verificación · Panel ' . site_name()], 'admin/layout_bare');
}

/* ---------- Artículos ---------- */

/* ---------- Inicio del panel ---------- */

/** Nº de fotos rotas (archivo que ya no está en uploads/). Se guarda 10 minutos: mirar el disco es lento. */
function admin_broken_photos(): int
{
    $c = json_decode(setting('broken_photos'), true);
    if (is_array($c) && (int)($c['at'] ?? 0) > time() - 600) {
        return (int)$c['n'];
    }
    require_once __DIR__ . '/ftp.php';
    $n = count(ftp_broken());
    save_setting('broken_photos', (string)json_encode(['at' => time(), 'n' => $n]));
    return $n;
}

function admin_home(): void
{
    $db = db();
    $count = fn(string $sql, array $args = []) => (function () use ($db, $sql, $args): int {
        $st = $db->prepare($sql);
        $st->execute($args);
        return (int)$st->fetchColumn();
    })();
    $pf = admin_price_field();
    $stats = [
        'visible' => $count('SELECT COUNT(*) FROM products WHERE visible = 1'),
        'hidden' => $count('SELECT COUNT(*) FROM products WHERE visible = 0'),
        'families' => $count('SELECT COUNT(*) FROM categories WHERE parent_id IS NULL'),
        'subs' => $count('SELECT COUNT(*) FROM categories WHERE parent_id IS NOT NULL'),
        'nophoto' => $count('SELECT COUNT(*) FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)'),
        'noprice' => $pf ? $count("SELECT COUNT(*) FROM products p WHERE NOT EXISTS (SELECT 1 FROM product_fields v WHERE v.product_id = p.id AND v.field_id = ? AND v.value <> '')", [$pf['id']]) : null,
    ];
    $lastImport = json_decode(setting('last_import'), true) ?: null;

    // Avisos
    $alerts = [];
    if (is_file(ROOT . '/instalar.php')) {
        $alerts[] = ['err', 'El archivo instalar.php sigue en la web: bórralo por FTP. Cualquiera podría volver a instalarla.', null, null];
    }
    if (setting('noindex') === '1') {
        $alerts[] = ['warn', 'La web está oculta a Google (noindex). Quítalo cuando la publiques.', is_admin() ? '/admin/configuracion' : null, 'Configuración'];
    }
    if (is_admin()) {
        $last = setting('last_backup_at');
        $days = $last !== '' ? (int)floor((time() - (int)strtotime($last)) / 86400) : null;
        if ($days === null) {
            $alerts[] = ['warn', 'Todavía no has descargado ninguna copia de seguridad.', '/admin/empresa#copia', 'Descargar copia'];
        } elseif ($days > 30) {
            $alerts[] = ['warn', 'Hace ' . $days . ' días que no descargas una copia de seguridad.', '/admin/empresa#copia', 'Descargar copia'];
        }
    }
    if (is_admin() && !erp_2fa_required()) {
        $alerts[] = ['warn', 'La doble verificación para Clientes y Ventas está desactivada', '/admin/configuracion#erp', 'Configuración'];
    }
    $broken = admin_broken_photos();
    if ($broken > 0) {
        $alerts[] = ['err', $broken . ' foto' . ($broken === 1 ? '' : 's') . ' de artículos apunta' . ($broken === 1 ? '' : 'n') . ' a un archivo que ya no existe (se ven rotas).', '/admin/ftp', 'Revisar'];
    }

    $recent = $db->query('SELECT p.id, p.name, p.ref, p.image, p.visible, p.updated_at, ' . PCAT_FIELDS . ' FROM products p ' . PCAT_JOIN
        . ' ORDER BY p.updated_at DESC, p.id DESC LIMIT 10')->fetchAll();

    admin_render('home', [
        'stats' => $stats, 'pf' => $pf, 'lastImport' => $lastImport, 'alerts' => $alerts,
        'lastBackup' => setting('last_backup_at'), 'recent' => $recent, 'title' => 'Inicio',
    ]);
}

/* ---------- Lista de artículos: filtros y vuelta a la lista ---------- */


/** Artículos por página en la lista del panel. */
const ADMIN_PER_PAGE = 50;

/** Columnas por las que se puede ordenar la lista (lista cerrada: nunca se pega texto del usuario en el SQL). */
const ADMIN_SORTS = ['ref', 'nombre', 'familia', 'precio', 'stock', 'visible', 'modificado'];

/** Borrar más de estos artículos de una vez pide escribir el número para confirmar. */
const ADMIN_BULK_TYPE_OVER = 50;

/**
 * Filtros válidos de la lista de artículos (lo demás se descarta).
 * c = familia (incluye sus subfamilias) · q = nombre o referencia · visible = 1|0 · foto = 1|0 · precio = 1|0
 * orden = columna (ADMIN_SORTS) · dir = asc|desc · pag = página (desde 1)
 */
function admin_list_query(array $src): array
{
    $out = [];
    if ((int)($src['c'] ?? 0) > 0) {
        $out['c'] = (int)$src['c'];
    }
    $q = mb_substr(trim((string)($src['q'] ?? '')), 0, 100);
    if ($q !== '') {
        $out['q'] = $q;
    }
    foreach (['visible', 'foto', 'precio'] as $k) {
        if (isset($src[$k]) && in_array((string)$src[$k], ['0', '1'], true)) {
            $out[$k] = (string)$src[$k];
        }
    }
    if (in_array((string)($src['orden'] ?? ''), ADMIN_SORTS, true)) {
        $out['orden'] = (string)$src['orden'];
        if (($src['dir'] ?? '') === 'desc') {
            $out['dir'] = 'desc';
        }
    }
    if ((int)($src['pag'] ?? 0) > 1) {
        $out['pag'] = (int)$src['pag'];
    }
    return $out;
}

/** Dirección de la lista con esos filtros. */
function admin_list_url(array $q): string
{
    return '/admin/articulos' . ($q ? '?' . http_build_query($q) : '');
}

/** Filtros con los que se llegó a una pantalla (parámetro «back», en GET o POST). */
function admin_back(): array
{
    parse_str((string)($_POST['back'] ?? $_GET['back'] ?? ''), $raw);
    return admin_list_query($raw);
}

/** Campo del precio para «sin precio»: el primero de tipo precio (mejor si es público), o null. */
function admin_price_field(): ?array
{
    $any = null;
    foreach (fields_all() as $f) {
        if ($f['kind'] === 'price') {
            if ($f['is_public']) {
                return $f;
            }
            $any ??= $f;
        }
    }
    return $any;
}

/** Campo del stock: el que se llama «Stock»; si no, el primer número interno; o null. */
function admin_stock_field(): ?array
{
    foreach (fields_all() as $f) {
        if ($f['slug'] === 'stock' || import_norm_simple($f['name']) === 'stock') {
            return $f;
        }
    }
    foreach (fields_all() as $f) {
        if ($f['kind'] === 'number' && !$f['is_public']) {
            return $f;
        }
    }
    return null;
}

/** Condiciones SQL de los filtros de la lista: [sql, argumentos]. Necesita los alias p, c (familia) y pc (familia padre). */
function admin_list_where(array $f): array
{
    $sql = '';
    $args = [];
    if (isset($f['c'])) {
        $sql .= ' AND (p.category_id = ? OR c.parent_id = ?)';
        array_push($args, $f['c'], $f['c']);
    }
    if (isset($f['q'])) {
        $sql .= ' AND (p.name LIKE ? OR p.ref LIKE ?)';
        array_push($args, '%' . $f['q'] . '%', '%' . $f['q'] . '%');
    }
    if (isset($f['visible'])) {
        $sql .= ' AND p.visible = ?';
        $args[] = (int)$f['visible'];
    }
    if (isset($f['foto'])) {
        $sql .= ' AND ' . ($f['foto'] === '1' ? '' : 'NOT ') . 'EXISTS (SELECT 1 FROM product_images i WHERE i.product_id = p.id)';
    }
    $pf = admin_price_field();
    if (isset($f['precio']) && $pf) {
        $sql .= ' AND ' . ($f['precio'] === '1' ? '' : 'NOT ') . "EXISTS (SELECT 1 FROM product_fields v WHERE v.product_id = p.id AND v.field_id = ? AND v.value <> '')";
        $args[] = $pf['id'];
    }
    return [$sql, $args];
}

/**
 * ORDER BY de la lista. Precio y stock se ordenan como número (CAST … AS DECIMAL, igual en SQLite,
 * MariaDB y SQL Server) solo si el campo es de tipo precio o número; los vacíos van siempre al final.
 */
function admin_list_order(array $f, ?array $pf, ?array $sf): string
{
    $d = ($f['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
    $num = fn(string $alias, ?array $field) => $field && in_array($field['kind'], ['price', 'number'], true)
        ? "CASE WHEN $alias.value IS NULL THEN 1 ELSE 0 END, CAST($alias.value AS DECIMAL(14,4)) $d, p.name, p.id"
        : "CASE WHEN $alias.value IS NULL THEN 1 ELSE 0 END, $alias.value $d, p.name, p.id";
    return match ($f['orden'] ?? '') {
        'ref' => "CASE WHEN p.ref = '' THEN 1 ELSE 0 END, p.ref $d, p.id",
        'nombre' => "p.name $d, p.id",
        'familia' => "COALESCE(pc.name, c.name) $d, CASE WHEN pc.id IS NULL THEN 0 ELSE 1 END, c.name $d, p.name, p.id",
        'precio' => $pf ? $num('pr', $pf) : PCAT_ORDER,
        'stock' => $sf ? $num('sk', $sf) : PCAT_ORDER,
        'visible' => "p.visible $d, p.name, p.id",
        'modificado' => "p.updated_at $d, p.id $d",
        default => PCAT_ORDER,
    };
}

/** Ids de los artículos que cumplen unos filtros (para las acciones sobre «todos los del filtro»). */
function admin_list_ids(array $filters): array
{
    [$where, $args] = admin_list_where($filters);
    $st = db()->prepare('SELECT p.id FROM products p ' . PCAT_JOIN . ' WHERE 1=1' . $where);
    $st->execute($args);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Ids enviados en el formulario, solo los que existen de verdad. */
function admin_existing_ids(array $raw): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw), fn($i) => $i > 0)));
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $st = db()->prepare('SELECT id FROM products WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $st->execute($chunk);
        $out = array_merge($out, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }
    return $out;
}

/** Ocultar, mostrar o cambiar de familia varios artículos (por tandas de 500, en una transacción). Devuelve cuántos. */
function admin_bulk_update(array $ids, string $op, int $target): int
{
    $db = db();
    $now = date('Y-m-d H:i:s');
    [$set, $arg] = match ($op) {
        'hide' => ['visible = 0', []],
        'show' => ['visible = 1', []],
        'move' => ['category_id = ?', [$target]],
    };
    $n = 0;
    $db->beginTransaction();
    try {
        foreach (array_chunk($ids, 500) as $chunk) {
            $st = $db->prepare('UPDATE products SET ' . $set . ', updated_at = ? WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
            $st->execute(array_merge($arg, [$now], $chunk));
            $n += $st->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $n;
}

/**
 * Acciones en bloque (POST action=bulk): op = hide|show|move|delete; scope = sel (marcados) | all (todos los del filtro).
 * «Todos los del filtro» y borrar siempre pasan antes por una página de confirmación con el número de artículos;
 * borrar más de ADMIN_BULK_TYPE_OVER pide escribir ese número. Al terminar se vuelve a la lista tal cual estaba.
 */
function admin_products_bulk(array $back): void
{
    $op = (string)($_POST['op'] ?? '');
    $scope = ($_POST['scope'] ?? '') === 'all' ? 'all' : 'sel';
    $target = (int)($_POST['target'] ?? 0);
    $listUrl = admin_list_url($back);
    if (!in_array($op, ['hide', 'show', 'move', 'delete'], true)) {
        flash('Elige qué hacer con los artículos.', 'err');
        redirect($listUrl);
    }
    if ($op === 'move' && !in_array($target, array_map('intval', array_column(admin_category_options(), 'id')), true)) {
        flash('Elige a qué ' . term('family', true) . ' moverlos.', 'err');
        redirect($listUrl);
    }
    $ids = $scope === 'all' ? admin_list_ids($back) : admin_existing_ids((array)($_POST['ids'] ?? []));
    $n = count($ids);
    if (!$n) {
        flash($scope === 'all' ? 'No hay artículos con ese filtro.' : 'No has marcado ningún artículo.', 'err');
        redirect($listUrl);
    }
    $needConfirm = $scope === 'all' || $op === 'delete';
    $needType = $op === 'delete' && $n > ADMIN_BULK_TYPE_OVER;
    $confirmed = ($_POST['confirm'] ?? '') === '1';
    if ($confirmed && $needType && trim((string)($_POST['typed'] ?? '')) !== (string)$n) {
        flash('El número escrito no coincide con ' . $n . ' (puede que la lista haya cambiado). No se ha borrado nada.', 'err');
        $confirmed = false;
    }
    if ($confirmed && $scope === 'all' && (int)($_POST['expected'] ?? -1) !== $n) {
        flash('Mientras confirmabas ha cambiado el número de artículos (ahora son ' . $n . '). Revísalo antes de seguir.', 'err');
        $confirmed = false;
    }
    if ($needConfirm && !$confirmed) {
        $st = db()->prepare('SELECT name, ref FROM products WHERE id IN (' . implode(',', array_fill(0, min(10, $n), '?')) . ') ORDER BY name');
        $st->execute(array_slice($ids, 0, 10));
        $targetName = '';
        foreach (admin_category_options() as $c) {
            if ((int)$c['id'] === $target) {
                $targetName = $c['name'];
            }
        }
        admin_render('bulk_confirm', [
            'op' => $op, 'scope' => $scope, 'n' => $n, 'ids' => $scope === 'sel' ? $ids : [], 'target' => $target,
            'targetName' => $targetName, 'sample' => $st->fetchAll(), 'needType' => $needType, 'back' => $back,
            'crumb' => 'Confirmar', 'title' => 'Confirmar',
        ]);
        return;
    }
    if ($op === 'delete') {
        [$del, $gone, $kept] = delete_products($ids);
        flash($del . ' artículo' . ($del === 1 ? '' : 's') . ' borrado' . ($del === 1 ? '' : 's') . '. ' . $gone . ' foto' . ($gone === 1 ? '' : 's') . ' borrada' . ($gone === 1 ? '' : 's') . ' de uploads/'
            . ($kept ? '; ' . $kept . ' se conserva' . ($kept === 1 ? '' : 'n') . ' porque se usa' . ($kept === 1 ? '' : 'n') . ' en otro sitio' : '') . '.');
    } else {
        $done = admin_bulk_update($ids, $op, $target);
        $verb = ['hide' => 'ocultado', 'show' => 'mostrado', 'move' => 'movido'][$op];
        flash($done . ' artículo' . ($done === 1 ? '' : 's') . ' ' . $verb . ($done === 1 ? '' : 's') . ($op === 'move' ? ' a «' . (admin_category_name($target)) . '»' : '') . '.');
    }
    redirect($listUrl);
}

/** Nombre de una familia (con su padre: «Familia › Subfamilia»). */
function admin_category_name(int $id): string
{
    foreach (admin_category_options() as $c) {
        if ((int)$c['id'] === $id) {
            return (string)$c['name'];
        }
    }
    return '';
}

function admin_products(): void
{
    $db = db();
    $filters = admin_list_query($_GET);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $back = admin_back();
        if (($_POST['action'] ?? '') === 'bulk') {
            admin_products_bulk($back);
            return;
        }
        if (($_POST['action'] ?? '') === 'toggle') {
            $db->prepare('UPDATE products SET visible = 1 - visible, updated_at = ? WHERE id = ?')
                ->execute([date('Y-m-d H:i:s'), (int)($_POST['id'] ?? 0)]);
            flash('Visibilidad actualizada.');
        }
        redirect(admin_list_url($back));
    }
    $pf = admin_price_field();
    $sf = admin_stock_field();
    [$where, $args] = admin_list_where($filters);

    // 1) Cuántos hay con este filtro
    $st = $db->prepare('SELECT COUNT(*) FROM products p ' . PCAT_JOIN . ' WHERE 1=1' . $where);
    $st->execute($args);
    $total = (int)$st->fetchColumn();
    $pages = max(1, (int)ceil($total / ADMIN_PER_PAGE));
    $page = min((int)($filters['pag'] ?? 1), $pages);
    if ($page > 1) {
        $filters['pag'] = $page;
    } else {
        unset($filters['pag']);
    }

    // 2) Los 50 de esta página, con su familia, precio y stock (una sola consulta)
    $joins = '';
    $cols = '';
    $jargs = [];
    if ($pf) {
        $joins .= ' LEFT JOIN product_fields pr ON pr.product_id = p.id AND pr.field_id = ?';
        $cols .= ', pr.value AS price';
        $jargs[] = $pf['id'];
    }
    if ($sf) {
        $joins .= ' LEFT JOIN product_fields sk ON sk.product_id = p.id AND sk.field_id = ?';
        $cols .= ', sk.value AS stock';
        $jargs[] = $sf['id'];
    }
    $st = $db->prepare('SELECT p.id, p.ref, p.name, p.image, p.visible, p.updated_at, p.category_id, ' . PCAT_FIELDS . $cols
        . ' FROM products p ' . PCAT_JOIN . $joins . ' WHERE 1=1' . $where
        . ' ORDER BY ' . admin_list_order($filters, $pf, $sf)
        . ' LIMIT ' . ADMIN_PER_PAGE . ($page > 1 ? ' OFFSET ' . (($page - 1) * ADMIN_PER_PAGE) : ''));
    $st->execute(array_merge($jargs, $args));

    admin_render('products', [
        'rows' => $st->fetchAll(), 'cats' => admin_category_options(), 'filters' => $filters,
        'c' => (int)($filters['c'] ?? 0), 'q' => (string)($filters['q'] ?? ''),
        'total' => $total, 'page' => $page, 'pages' => $pages, 'pf' => $pf, 'sf' => $sf,
        'back' => http_build_query($filters),
        'title' => 'Artículos',
    ]);
}

function admin_product(string $id): void
{
    $db = db();
    $cats = admin_category_options();
    $isNew = ($id === 'nuevo');
    $p = null;
    if (!$isNew) {
        $st = $db->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([(int)$id]);
        $p = $st->fetch();
        if (!$p) {
            not_found();
            return;
        }
    }
    $errors = [];
    $form = $p ?? [
        'category_id' => (int)($_GET['c'] ?? ($cats[0]['id'] ?? 0)), 'ref' => '', 'name' => '',
        'dimensions' => '', 'description' => '', 'image' => null, 'visible' => 1, 'sort' => 0, 'shape' => '',
    ];

    // Filtros de la lista de la que se vino (para volver a ella tal cual)
    $back = admin_back();
    $backQs = $back ? '?back=' . rawurlencode(http_build_query($back)) : '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('Las fotos pesan demasiado de una vez (el servidor admite ' . ini_get('post_max_size') . ' en total). Sube menos fotos cada vez.', 'err');
            redirect('/admin/articulo/' . $id . $backQs);
        }
        csrf_check();
        if (($_POST['action'] ?? '') === 'delete' && $p) {
            delete_products([(int)$p['id']]); // sus fotos solo se borran si no las usa nadie más
            flash('Artículo «' . $p['name'] . '» eliminado.');
            redirect(admin_list_url($back));
        }
        $form = array_merge($form, [
            'category_id' => (int)($_POST['category_id'] ?? 0),
            'ref' => trim((string)($_POST['ref'] ?? '')),
            'name' => trim((string)($_POST['name'] ?? '')),
            'dimensions' => trim((string)($_POST['dimensions'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'visible' => empty($_POST['visible']) ? 0 : 1,
            'sort' => (int)($_POST['sort'] ?? 0),
        ]);
        if ($form['name'] === '') {
            $errors[] = 'El nombre es obligatorio.';
        }
        if (!in_array($form['category_id'], array_column($cats, 'id'), true)) {
            $errors[] = 'Elige ' . tg('una', 'un') . ' ' . term('family', true) . '.';
        }
        if (!$errors) {
            $now = date('Y-m-d H:i:s');
            if ($isNew) {
                $slug = unique_slug('products', slugify(($form['ref'] !== '' ? 'ref ' . $form['ref'] . ' ' : '') . $form['name']));
                $db->prepare('INSERT INTO products (category_id, ref, name, slug, dimensions, description, visible, sort, created_at, updated_at)
                              VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$form['category_id'], $form['ref'], $form['name'], $slug, $form['dimensions'],
                        $form['description'], $form['visible'], $form['sort'], $now, $now]);
                $pid = (int)$db->lastInsertId();
                $msg = 'Artículo «' . $form['name'] . '» creado.';
            } else {
                $db->prepare('UPDATE products SET category_id=?, ref=?, name=?, dimensions=?, description=?, visible=?, sort=?, updated_at=? WHERE id=?')
                    ->execute([$form['category_id'], $form['ref'], $form['name'], $form['dimensions'],
                        $form['description'], $form['visible'], $form['sort'], $now, $p['id']]);
                $pid = (int)$p['id'];
                $msg = 'Cambios guardados.';
            }
            product_fields_save($pid, (array)($_POST['cf'] ?? []));
            $shape = (string)($_POST['shape'] ?? '');
            $db->prepare('UPDATE products SET shape = ? WHERE id = ?')->execute([in_array($shape, ['cilindrica', 'ranura', 'rectangular', 'conica'], true) ? $shape : '', $pid]);

            // Fotos: quitar las marcadas, añadir las nuevas y fijar la portada
            foreach ((array)($_POST['remove'] ?? []) as $rid) {
                remove_product_image($pid, (int)$rid);
            }
            $photoErrors = [];
            foreach (normalize_files($_FILES['images'] ?? null) as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                try {
                    add_product_image($pid, save_image($file));
                } catch (RuntimeException $ex) {
                    $photoErrors[] = (($file['name'] ?? '') !== '' ? $file['name'] . ': ' : '') . $ex->getMessage();
                }
            }
            if (!empty($_POST['cover'])) {
                set_cover($pid, (int)$_POST['cover']);
            }
            sync_gallery($pid);
            if (!empty($_POST['famcover'])) {
                $fc = $db->prepare('SELECT i.file FROM product_images i WHERE i.product_id = ? AND i.file = ?');
                $fc->execute([$pid, (string)$_POST['famcover']]);
                if ($fc->fetchColumn()) {
                    $db->prepare('UPDATE categories SET cover = ? WHERE id = (SELECT category_id FROM products WHERE id = ?)')->execute([(string)$_POST['famcover'], $pid]);
                }
            }

            flash($msg);
            if ($photoErrors) {
                foreach ($photoErrors as $m) {
                    flash($m, 'err');
                }
                redirect('/admin/articulo/' . $pid . $backQs);
            }
            redirect(admin_list_url($back ?: ['c' => (int)$form['category_id']]));
        }
    }
    // Si el guardado falló, las fotos elegidas se pierden (el navegador no recuerda el archivo): avisarlo
    $lostFiles = 0;
    if ($errors) {
        foreach (normalize_files($_FILES['images'] ?? null) as $file) {
            $lostFiles += ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? 0 : 1;
        }
    }
    admin_render('product_form', [
        'p' => $p, 'f' => $form, 'cats' => $cats, 'errors' => $errors, 'isNew' => $isNew,
        'lostFiles' => $lostFiles,
        'gallery' => $p ? gallery_for((int)$p['id']) : [],
        'back' => $back,
        'crumb' => $isNew ? 'Nuevo artículo' : 'Editar «' . $p['name'] . '»',
        'title' => $isNew ? 'Nuevo artículo' : 'Editar artículo',
    ]);
}

/* ---------- Familias ---------- */

/** Familias y subfamilias para los desplegables, en orden de árbol («Familia › Subfamilia»). */
function admin_category_options(): array
{
    return db()->query("SELECT c.id, CASE WHEN pc.id IS NULL THEN c.name ELSE pc.name || ' › ' || c.name END AS name
        FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id
        ORDER BY COALESCE(pc.sort, c.sort), COALESCE(pc.name, c.name), CASE WHEN pc.id IS NULL THEN 0 ELSE 1 END, c.sort, c.name")->fetchAll();
}

function admin_categories(): void
{
    $db = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $sort = (int)($_POST['sort'] ?? 0);
        $parent = (int)($_POST['parent_id'] ?? 0);
        // El padre debe ser una familia principal existente (solo hay un nivel de subfamilias).
        if ($parent) {
            $chk = $db->prepare('SELECT COUNT(*) FROM categories WHERE id = ? AND parent_id IS NULL');
            $chk->execute([$parent]);
            if (!(int)$chk->fetchColumn() || $parent === $id) {
                $parent = 0;
            }
        }
        if ($action === 'delete') {
            $n = $db->prepare('SELECT (SELECT COUNT(*) FROM products WHERE category_id = ?) + (SELECT COUNT(*) FROM categories WHERE parent_id = ?)');
            $n->execute([$id, $id]);
            if ((int)$n->fetchColumn() > 0) {
                flash('No se puede borrar si tiene artículos o ' . term('subs', true) . '. Muévelos o bórralos antes.', 'err');
            } else {
                $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                flash(tg('Eliminada.', 'Eliminado.'));
            }
        } elseif ($name === '') {
            flash('El nombre no puede estar vacío.', 'err');
        } elseif ($action === 'add') {
            $slug = unique_slug('categories', slugify(($parent ? (string)$db->query('SELECT slug FROM categories WHERE id = ' . $parent)->fetchColumn() . ' ' : '') . $name));
            $db->prepare('INSERT INTO categories (name, slug, sort, parent_id) VALUES (?,?,?,?)')->execute([$name, $slug, $sort, $parent ?: null]);
            flash(($parent ? term('sub') : term('family')) . ' «' . $name . '» ' . tg('creada', 'creado') . '.');
        } elseif ($action === 'save') {
            $kids = $db->prepare('SELECT COUNT(*) FROM categories WHERE parent_id = ?');
            $kids->execute([$id]);
            if ($parent && (int)$kids->fetchColumn() > 0) {
                flash(tg('Esta', 'Este') . ' ' . term('family', true) . ' tiene ' . term('subs', true) . ', así que no puede colgar de ' . tg('otra', 'otro') . '.', 'err');
            } else {
                $db->prepare('UPDATE categories SET name = ?, sort = ?, parent_id = ? WHERE id = ?')->execute([$name, $sort, $parent ?: null, $id]);
                flash(tg('Actualizada.', 'Actualizado.'));
            }
        }
        redirect('/admin/categorias');
    }
    $rows = $db->query('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS n,
            (SELECT COUNT(*) FROM categories k WHERE k.parent_id = c.id) AS nkids
        FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id
        ORDER BY COALESCE(pc.sort, c.sort), COALESCE(pc.name, c.name), CASE WHEN pc.id IS NULL THEN 0 ELSE 1 END, c.sort, c.name')->fetchAll();
    $roots = array_values(array_filter($rows, fn($r) => !$r['parent_id']));
    admin_render('categories', ['rows' => $rows, 'roots' => $roots, 'title' => term('families')]);
}

/** Elegir, entre las fotos ya subidas, la que sale en la tarjeta de una familia o subfamilia. */
function admin_cover(int $id): void
{
    $db = db();
    $st = $db->prepare('SELECT * FROM categories WHERE id = ?');
    $st->execute([$id]);
    $cat = $st->fetch();
    if (!$cat) {
        not_found();
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['what'] ?? '') === 'upload' || (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0))) {
        if (empty($_POST)) {
            flash('La imagen pesa demasiado para el servidor.', 'err');
            redirect('/admin/portada/' . $id);
        }
        csrf_check();
        try {
            if (!empty($_POST['banner_remove'])) {
                delete_image($cat['banner']);
                $db->prepare("UPDATE categories SET banner = '' WHERE id = ?")->execute([$id]);
                flash('Imagen de cabecera quitada.');
            } elseif (!empty($_FILES['photo']['name'])) {
                // Foto nueva solo para la caja de la familia: no toca el slider ni la cabecera.
                $new = save_image($_FILES['photo'], 1400, false);
                $used = $db->prepare('SELECT 1 FROM product_images WHERE file = ?');
                $used->execute([$cat['cover']]);
                if ($cat['cover'] !== '' && !$used->fetchColumn()) {
                    delete_image($cat['cover']);
                }
                $db->prepare('UPDATE categories SET cover = ? WHERE id = ?')->execute([$new, $id]);
                flash('Foto ' . tg('de la', 'del') . ' ' . term('family', true) . ' guardada.');
                redirect('/admin/categorias');
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        redirect('/admin/portada/' . $id);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $file = (string)($_POST['file'] ?? '');
        if ($file !== '') {
            $ok = $db->prepare('SELECT 1 FROM product_images i JOIN products p ON p.id = i.product_id
                JOIN categories c ON c.id = p.category_id WHERE i.file = ? AND (c.id = ? OR c.parent_id = ?)');
            $ok->execute([$file, $id, $id]);
            if (!$ok->fetchColumn()) {
                $file = '';
            }
        }
        $db->prepare('UPDATE categories SET cover = ? WHERE id = ?')->execute([$file, $id]);
        flash($file === '' ? 'Portada automática (la primera foto ' . tg('de la', 'del') . ' ' . term('family', true) . ').' : 'Portada guardada.');
        redirect('/admin/categorias');
    }
    $st = $db->prepare('SELECT i.file, p.name FROM product_images i JOIN products p ON p.id = i.product_id
        JOIN categories c ON c.id = p.category_id WHERE c.id = ? OR c.parent_id = ?
        ORDER BY p.sort, p.id, i.sort LIMIT 400');
    $st->execute([$id, $id]);
    admin_render('cover', ['cat' => $cat, 'photos' => $st->fetchAll(), 'crumb' => 'Foto de portada de «' . $cat['name'] . '»', 'title' => 'Portada de ' . $cat['name']]);
}

/* ---------- Slider de la portada ---------- */

/** Solo enlaces internos (/…) o http(s)://; cualquier otra cosa se descarta. */
function clean_link(string $l): string
{
    $l = trim($l);
    if ($l !== '' && (preg_match('#^/(?!/)#', $l) || preg_match('#^https?://#i', $l))) {
        return mb_substr($l, 0, 300);
    }
    return '';
}

function admin_slider(): void
{
    $db = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('La imagen pesa demasiado para el servidor.', 'err');
            redirect('/admin/slider');
        }
        csrf_check();
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $fields = [
            mb_substr(trim((string)($_POST['title'] ?? '')), 0, 120), mb_substr(trim((string)($_POST['text'] ?? '')), 0, 300),
            clean_link((string)($_POST['link'] ?? '')), mb_substr(trim((string)($_POST['btn'] ?? '')), 0, 40), (int)($_POST['sort'] ?? 0),
        ];
        try {
            if ($action === 'add') {
                $f = $_FILES['image'] ?? null;
                if (!$f || ($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) {
                    throw new RuntimeException('Elige una imagen para la diapositiva.');
                }
                $file = save_image($f, 2560, false);
                $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM slides')->fetchColumn();
                $db->prepare('INSERT INTO slides (file, title, text, link, btn, sort) VALUES (?,?,?,?,?,?)')
                    ->execute([$file, $fields[0], $fields[1], $fields[2], $fields[3], $fields[4] ?: $max]);
                flash('Diapositiva añadida.');
            } elseif ($action === 'save') {
                $db->prepare('UPDATE slides SET title = ?, text = ?, link = ?, btn = ?, sort = ? WHERE id = ?')->execute([...$fields, $id]);
                if (!empty($_FILES['image']['name']) && ($_FILES['image']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                    $new = save_image($_FILES["image"], 2560, false);
                    $old = $db->prepare('SELECT file FROM slides WHERE id = ?');
                    $old->execute([$id]);
                    delete_image((string)$old->fetchColumn());
                    $db->prepare('UPDATE slides SET file = ? WHERE id = ?')->execute([$new, $id]);
                }
                flash('Diapositiva guardada.');
            } elseif ($action === 'toggle') {
                $db->prepare('UPDATE slides SET visible = 1 - visible WHERE id = ?')->execute([$id]);
            } elseif ($action === 'delete') {
                $old = $db->prepare('SELECT file FROM slides WHERE id = ?');
                $old->execute([$id]);
                delete_image((string)$old->fetchColumn());
                $db->prepare('DELETE FROM slides WHERE id = ?')->execute([$id]);
                flash('Diapositiva eliminada.');
            } elseif ($action === 'settings') {
                $fx = in_array($_POST['effect'] ?? '', ['fade', 'slide', 'zoom'], true) ? $_POST['effect'] : 'fade';
                save_setting('slider_effect', $fx);
                save_setting('slider_seconds', (string)max(3, min(15, (int)($_POST['seconds'] ?? 6))));
                flash('Ajustes del slider guardados.');
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        redirect('/admin/slider');
    }
    admin_render('slider', [
        'crumb' => 'Slider',
        'slides' => $db->query('SELECT * FROM slides ORDER BY sort, id')->fetchAll(),
        'effect' => db_setting('slider_effect') ?: 'fade', 'seconds' => (int)(db_setting('slider_seconds') ?: 6),
        'title' => 'Slider de portada',
    ]);
}

/* ---------- Representantes ---------- */

function admin_reps(): void
{
    $db = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            trim((string)($_POST['zone'] ?? '')), trim((string)($_POST['name'] ?? '')),
            trim((string)($_POST['address'] ?? '')), trim((string)($_POST['phone'] ?? '')),
        ];
        if ($action === 'delete') {
            $db->prepare('DELETE FROM reps WHERE id = ?')->execute([$id]);
            flash('Representante eliminado.');
        } elseif ($data[0] === '' || $data[1] === '') {
            flash('Zona y nombre son obligatorios.', 'err');
        } elseif ($action === 'add') {
            $db->prepare('INSERT INTO reps (zone, name, address, phone) VALUES (?,?,?,?)')->execute($data);
            flash('Representante añadido.');
        } elseif ($action === 'save') {
            $db->prepare('UPDATE reps SET zone=?, name=?, address=?, phone=? WHERE id=?')->execute([...$data, $id]);
            flash('Representante actualizado.');
        }
        redirect('/admin/representantes');
    }
    admin_render('reps', ['rows' => $db->query('SELECT * FROM reps ORDER BY zone')->fetchAll(), 'title' => 'Representantes']);
}

/* ---------- Datos de empresa ---------- */

function admin_company(): void
{
    $db = db();
    // Lo de la portada (imagen de cabecera, textos, tarjetas) está ahora en Web → Página de inicio
    $keys = ['company_name', 'trade_name', 'brand_sub', 'tax_id', 'tagline', 'address', 'phone', 'whatsapp', 'email',
        'facebook', 'instagram', 'linkedin', 'brand_color', 'logo_height', 'footer_banner_alt'];
    $images = ['logo' => 'logo', 'banner' => 'footer_banner'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('La imagen pesa demasiado para el servidor.', 'err');
            redirect('/admin/empresa');
        }
        csrf_check();
        foreach ($images as $field => $key) {
            try {
                $old = db_setting($key);
                if (!empty($_POST[$field . '_remove'])) {
                    delete_image($old);
                    save_setting($key, '');
                } elseif (!empty($_FILES[$field]['name']) && ($_FILES[$field]['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                    $new = save_image($_FILES[$field], 1200, false);
                    delete_image($old);
                    save_setting($key, $new);
                }
            } catch (RuntimeException $e) {
                flash($e->getMessage(), 'err');
            }
        }
        foreach ($keys as $k) {
            $val = trim(str_replace("
", "
", (string)($_POST[$k] ?? ''))); // saltos de línea siempre 

            if ($k === 'brand_color' && $val !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $val)) {
                $val = '';
            }
            if ($k === 'logo_height') {
                $val = (string)max(24, min(110, (int)$val ?: 64));
            }
            db_put_setting($db, $k, $val);
        }
        flash('Datos de la empresa guardados.');
        redirect('/admin/empresa');
    }
    $vals = [];
    foreach ($keys as $k) {
        $vals[$k] = setting($k);
    }
    foreach ($images as $key) {
        $v = db_setting($key);
        $vals[$key] = $v;
    }
    admin_render('company', ['v' => $vals, 'title' => 'Empresa']);
}

/* ---------- Web → Página de inicio (bloques de la portada) ---------- */

/** Textos de la portada: clave => [etiqueta, valor por defecto, bloque]. Son los ajustes de siempre. */
const HOME_TEXTS = [
    'home_hello' => ['Saludo', 'Bienvenido a', 'welcome'],
    'home_lead' => ['Frase de bienvenida', '', 'welcome'],
    'home_title' => ['Título', '', 'about'],
    'home_text' => ['Texto', '', 'about'],
    'home_chips_title' => ['Título de la sección', 'Nuestros productos', 'families'],
    'home_news_title' => ['Título de la sección', 'Novedades', 'news'],
    'cta_title' => ['Título', '¿Hablamos?', 'cta'],
    'cta_text' => ['Texto', 'Pídenos información, precios o presupuesto.', 'cta'],
    'home_desc' => ['Descripción para Google (portada)', '', ''],
];

function admin_home_page(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('La imagen pesa demasiado para el servidor.', 'err');
            redirect('/admin/pagina-inicio');
        }
        csrf_check();
        // Imagen de cabecera
        try {
            $old = db_setting('hero_image');
            if (!empty($_POST['hero_remove'])) {
                delete_image($old);
                save_setting('hero_image', '');
            } elseif (!empty($_FILES['hero']['name']) && ($_FILES['hero']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                $new = save_image($_FILES['hero'], 2000, false);
                delete_image($old);
                save_setting('hero_image', $new);
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        // Textos (vacío = el texto por defecto)
        foreach (HOME_TEXTS as $k => $_) {
            $max = $k === 'home_text' ? 3000 : ($k === 'home_desc' ? 160 : 300);
            save_setting($k, mb_substr(trim(str_replace("
", "
", (string)($_POST[$k] ?? ''))), 0, $max));
        }
        save_setting('home_news_count', (string)max(1, min(24, (int)($_POST['home_news_count'] ?? 8))));
        // Tarjetas
        $cards = [];
        foreach ((array)($_POST['card_t'] ?? []) as $n => $t) {
            $t = trim((string)$t);
            if ($t !== '') {
                $cards[] = [mb_substr($t, 0, 60), mb_substr(trim((string)($_POST['card_d'][$n] ?? '')), 0, 120)];
            }
        }
        save_setting('home_cards', (string)json_encode(array_slice($cards, 0, 4), JSON_UNESCAPED_UNICODE));
        // {Familias} destacadas: todas, o solo las marcadas
        $valid = array_map('intval', array_column(db()->query('SELECT id FROM categories WHERE parent_id IS NULL')->fetchAll(), 'id'));
        $pick = array_values(array_intersect(array_map('intval', (array)($_POST['fam'] ?? [])), $valid));
        save_setting('home_families', ($_POST['fam_mode'] ?? '') === 'some' && $pick ? (string)json_encode($pick) : '');
        // Bloques: activo, orden (número) y flechas ↑ ↓
        $blocks = home_blocks();
        $on = (array)($_POST['on'] ?? []);
        $ord = (array)($_POST['ord'] ?? []);
        foreach ($blocks as $i => &$b) {
            $b['on'] = isset($on[$b['id']]) ? 1 : 0;
            $b['n'] = isset($ord[$b['id']]) && is_numeric($ord[$b['id']]) ? (float)$ord[$b['id']] : ($i + 1) * 10;
            $b['i'] = $i;
            if ($b['id'] === 'slider') {
                $b['replace'] = empty($_POST['slider_replace']) ? 0 : 1;
            }
        }
        unset($b);
        usort($blocks, fn($a, $b) => $a['n'] <=> $b['n'] ?: $a['i'] <=> $b['i']);
        [$mid, $dir] = array_pad(explode(':', (string)($_POST['move'] ?? '')), 2, '');
        $pos = array_search($mid, array_column($blocks, 'id'), true);
        if ($pos !== false) {
            $to = $dir === 'up' ? $pos - 1 : ($dir === 'down' ? $pos + 1 : $pos);
            if ($to >= 0 && $to < count($blocks)) {
                [$blocks[$pos], $blocks[$to]] = [$blocks[$to], $blocks[$pos]];
            }
        }
        $save = array_map(fn($b) => ['id' => $b['id'], 'on' => $b['on']] + ($b['id'] === 'slider' ? ['replace' => $b['replace']] : []), $blocks);
        save_setting('home_blocks', (string)json_encode($save));
        // El antiguo módulo «slider» sigue el bloque (compatibilidad)
        foreach ($save as $b) {
            if ($b['id'] === 'slider') {
                save_setting('mod_slider', $b['on'] ? '1' : '0');
            }
        }
        flash($mid !== '' ? 'Orden cambiado.' : 'Página de inicio guardada.');
        redirect('/admin/pagina-inicio' . ($mid !== '' ? '#bloques' : ''));
    }
    $v = [];
    foreach (HOME_TEXTS as $k => $_) {
        $v[$k] = db_setting($k);
    }
    $v['hero_image'] = db_setting('hero_image');
    $v['home_news_count'] = db_setting('home_news_count') ?: '8';
    admin_render('home_page', [
        'v' => $v, 'blocks' => home_blocks(),
        'cards' => array_pad(json_decode(setting('home_cards') ?: '[]', true) ?: [], 4, ['', '']),
        'roots' => db()->query('SELECT id, name FROM categories WHERE parent_id IS NULL ORDER BY sort, name')->fetchAll(),
        'pick' => array_map('intval', (array)(json_decode(setting('home_families'), true) ?: [])),
        'nSlides' => (int)db()->query('SELECT COUNT(*) FROM slides WHERE visible = 1')->fetchColumn(),
        'title' => 'Página de inicio',
    ]);
}

/* ---------- Web → Página del catálogo ---------- */

function admin_catalog_page(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        save_setting('catalog_title', mb_substr(trim((string)($_POST['catalog_title'] ?? '')), 0, 200));
        save_setting('catalog_intro', mb_substr(trim(str_replace("
", "
", (string)($_POST['catalog_intro'] ?? ''))), 0, 2000));
        save_setting('catalog_per_page', (string)max(0, min(200, (int)($_POST['catalog_per_page'] ?? 0))));
        $o = (string)($_POST['catalog_order'] ?? 'manual');
        save_setting('catalog_order', isset(CATALOG_ORDERS[$o]) ? $o : 'manual');
        save_setting('catalog_show_ref', empty($_POST['catalog_show_ref']) ? '0' : '1');
        save_setting('catalog_show_price', empty($_POST['catalog_show_price']) ? '0' : '1');
        $want = array_map('intval', is_array($_POST['catalog_filters'] ?? null) ? $_POST['catalog_filters'] : []);
        $keep = [];
        foreach (array_keys(catalog_filter_candidates()) as $fid) { // solo campos válidos, en el orden de Campos
            if (in_array($fid, $want, true) && count($keep) < CATALOG_FILTER_MAX) {
                $keep[] = $fid;
            }
        }
        save_setting('catalog_filters', json_encode($keep));
        flash('Página del catálogo guardada.');
        redirect('/admin/pagina-catalogo');
    }
    admin_render('catalog_page', [
        'title_v' => db_setting('catalog_title'), 'intro' => db_setting('catalog_intro'), 'opts' => catalog_opts(),
        'hasPrice' => field_price_public() !== null,
        'cand' => catalog_filter_candidates(), 'active' => catalog_filter_fields(),
        'title' => 'Página del catálogo',
    ]);
}

/* ---------- Configuración: módulos, país, textos fijos ---------- */

const CONFIG_TEXTS = [
    'reps_title' => ['Título de la lista de representantes', 'Representantes por zonas', ''],
];

/** Título del índice del catálogo si no se ha escrito otro: «Nuestras familias» (con los nombres configurados). */
function catalog_title_default(): string
{
    return tg('Nuestras', 'Nuestros') . ' ' . term('families', true);
}

/** Valor por defecto de un texto fijo. */
function config_text_default(string $k): string
{
    return $k === 'catalog_title' ? catalog_title_default() : (CONFIG_TEXTS[$k][1] ?? '');
}

/**
 * Cambiar la dirección del panel (Web → Configuración). Se guarda en data/panel.txt (no en la base de datos, para poder
 * recuperarla por FTP: borrar ese archivo = /admin). Muestra la dirección nueva en una pantalla aparte, SIN redirigir,
 * antes de que se salga de la página.
 */
function admin_set_panel_path(): void
{
    csrf_check();
    $new = strtolower(trim((string)($_POST['panel_path'] ?? '')));
    $new = $new === '' ? 'admin' : $new;
    $cur = admin_path();
    $err = null;
    if (empty($_POST['ack'])) {
        $err = 'Marca la casilla para confirmar que has anotado la nueva dirección.';
    } elseif ($new === $cur) {
        $err = 'Esa ya es la dirección actual del panel.';
    } elseif ($new !== 'admin') {
        if (strlen($new) < 3 || strlen($new) > 40 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $new)) {
            $err = 'La dirección solo puede llevar letras minúsculas, números y guiones (entre 3 y 40 caracteres).';
        } elseif (in_array($new, PAGE_RESERVED, true)) {
            $err = '«' . $new . '» está reservada por la web. Elige otra.';
        } elseif (file_exists(ROOT . '/' . $new)) {
            $err = '«' . $new . '» coincide con un archivo o carpeta de la web. Elige otra.';
        } else {
            $st = db()->prepare('SELECT COUNT(*) FROM pages WHERE slug = ?');
            $st->execute([$new]);
            if ((int)$st->fetchColumn() > 0) {
                $err = 'Ya hay una página con la dirección «' . $new . '». Elige otra.';
            }
        }
    }
    $file = DATA_DIR . '/panel.txt';
    if (!$err) {
        if ($new === 'admin') {
            if (is_file($file) && !@unlink($file)) {
                $err = 'No se ha podido borrar data/panel.txt (permisos). Bórralo por FTP.';
            }
        } else {
            $tmp = $file . '.tmp';
            if (@file_put_contents($tmp, $new . "\n", LOCK_EX) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                $err = 'No se ha podido guardar data/panel.txt (permisos de la carpeta data/). No se ha cambiado nada.';
            }
        }
    }
    if ($err) {
        flash($err, 'err');
        redirect('/admin/configuracion');
    }
    admin_path(true); // a partir de aquí, url('/admin/…') ya da la dirección nueva
    render('admin/panel_path_done', ['newPath' => $new, 'oldPath' => $cur, 'title' => 'Dirección del panel · ' . site_name()], 'admin/layout_bare');
}

function admin_config(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'panel_path') {
        admin_set_panel_path();
        return;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        foreach (array_keys(features_help()) as $k) { // sin «slider»: se activa en Web → Página de inicio
            save_setting('mod_' . $k, in_array($k, (array)($_POST['mods'] ?? []), true) ? '1' : '0');
        }
        save_setting('phone_prefix', preg_replace('/\D+/', '', (string)($_POST['phone_prefix'] ?? '')));
        $tz = (string)($_POST['timezone'] ?? '');
        if (in_array($tz, timezone_identifiers_list(), true)) {
            save_setting('timezone', $tz);
        }
        save_setting('noindex', empty($_POST['noindex']) ? '0' : '1');
        foreach (CONFIG_TEXTS as $k => $_) {
            save_setting($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 200));
        }
        // Nombres del catálogo (vacío = el de siempre)
        foreach (TERM_DEFAULTS as $k => $_) {
            save_setting('term_' . $k, mb_substr(trim(strip_tags((string)($_POST['term_' . $k] ?? ''))), 0, 40));
        }
        save_setting('term_gender', ($_POST['term_gender'] ?? '') === 'm' ? 'm' : 'f');
        // Doble verificación exigida para Clientes y Ventas: activarla es libre; desactivarla pide la contraseña del admin y avisa por correo
        $wantErp = !empty($_POST['erp_2fa']);
        if ($wantErp && !erp_2fa_required()) {
            save_setting('erp_require_2fa', '1');
        } elseif (!$wantErp && erp_2fa_required()) {
            $st = db()->prepare('SELECT username, pass_hash FROM users WHERE id = ?');
            $st->execute([(int)$_SESSION['uid']]);
            $me = $st->fetch();
            if (!$me || !password_verify((string)($_POST['erp_pass'] ?? ''), (string)$me['pass_hash'])) {
                auth_register_failure((int)$_SESSION['uid']);
                flash('No se ha desactivado la doble verificación de Clientes y Ventas: la contraseña no es correcta.', 'err');
            } else {
                save_setting('erp_require_2fa', '0');
                erp_notify_2fa_off((string)$me['username']);
                flash('Doble verificación de Clientes y Ventas desactivada. Se ha avisado por correo a los administradores.', 'err');
            }
        }
        flash('Configuración guardada.');
        redirect('/admin/configuracion');
    }
    $v = [];
    foreach (CONFIG_TEXTS as $k => $_) {
        $v[$k] = db_setting($k);
    }
    foreach (TERM_DEFAULTS as $k => $_) {
        $v['term_' . $k] = db_setting('term_' . $k);
    }
    $v['term_gender'] = db_setting('term_gender') === 'm' ? 'm' : 'f';
    admin_render('config', [
        'v' => $v,
        'mods' => array_filter(array_keys(FEATURES), 'feature'),
        'prefix' => setting('phone_prefix', '34'),
        'tz' => date_default_timezone_get(),
        'noindex' => setting('noindex') === '1',
        'erp2fa' => erp_2fa_required(),
        'title' => 'Configuración',
    ]);
}

/* ---------- Web → Menú (menú de arriba y pie de la web) ---------- */

/**
 * Elegir y ordenar qué sale en el menú de arriba y en el pie, con nombre opcional. Se guarda en los ajustes
 * menu_top y menu_foot (JSON [{k, on, label}]), sin tablas. Las flechas son botones del mismo formulario
 * (sin JavaScript): guardan todo y mueven el elemento. Solo administradores; todo POST lleva CSRF.
 */
function admin_site_menu(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'reset') {
            if (($_POST['confirm'] ?? '') !== '1') {
                admin_render('site_menu', ['confirmReset' => true, 'menus' => [], 'crumb' => 'Restaurar', 'title' => 'Menú']);
                return;
            }
            save_setting('menu_top', '');
            save_setting('menu_foot', '');
            flash('Menú restaurado: arriba Inicio, Catálogo y Contacto; en el pie, las páginas visibles.');
            redirect('/admin/menu');
        }
        if ($a === 'save') {
            // Flecha pulsada: «lugar:clave:up|down» (la clave puede llevar «:», como p:3)
            $mv = explode(':', (string)($_POST['move'] ?? ''));
            $moveDir = count($mv) >= 3 ? array_pop($mv) : '';
            $movePlace = count($mv) >= 2 ? array_shift($mv) : '';
            $moveKey = implode(':', $mv);
            foreach (array_keys(MENU_PLACES) as $place) {
                $rows = menu_config($place);
                $on = (array)($_POST['on'][$place] ?? []);
                $ord = (array)($_POST['ord'][$place] ?? []);
                $lab = (array)($_POST['label'][$place] ?? []);
                foreach ($rows as $i => &$r) {
                    $k = $r['k'];
                    $r['on'] = isset($on[$k]) ? 1 : 0;
                    $r['n'] = isset($ord[$k]) && is_numeric($ord[$k]) ? (float)$ord[$k] : ($i + 1) * 10;
                    $r['i'] = $i;
                    $txt = $lab[$k] ?? '';
                    $r['label'] = mb_substr(trim((string)preg_replace('/\s+/u', ' ', is_string($txt) ? $txt : '')), 0, 40); // ignora valores que no son texto
                }
                unset($r);
                usort($rows, fn($x, $y) => $x['n'] <=> $y['n'] ?: $x['i'] <=> $y['i']);
                if ($place === $movePlace && in_array($moveDir, ['up', 'down'], true)) {
                    $pos = array_search($moveKey, array_column($rows, 'k'), true);
                    if ($pos !== false) {
                        $to = $moveDir === 'up' ? $pos - 1 : $pos + 1;
                        if ($to >= 0 && $to < count($rows)) {
                            [$rows[$pos], $rows[$to]] = [$rows[$to], $rows[$pos]];
                        }
                    }
                }
                save_setting('menu_' . $place, (string)json_encode(array_map(fn($r) => ['k' => $r['k'], 'on' => $r['on'], 'label' => $r['label']], $rows), JSON_UNESCAPED_UNICODE));
            }
            flash($movePlace !== '' ? 'Orden cambiado.' : 'Menú guardado.');
            redirect('/admin/menu' . ($movePlace !== '' ? '#m-' . $movePlace : ''));
        }
    }
    $menus = [];
    foreach (MENU_PLACES as $place => $name) {
        $menus[$place] = menu_config($place);
    }
    admin_render('site_menu', ['confirmReset' => false, 'menus' => $menus, 'title' => 'Menú']);
}

/* ---------- Web → Páginas (páginas propias en /<dirección>) ---------- */

/**
 * /admin/paginas (lista) · /admin/paginas/nueva (plantillas o en blanco) · /admin/paginas/<id> (editar).
 * Solo administradores (se comprueba en admin_dispatch). Todo POST lleva CSRF.
 */
function admin_pages(string $sub): void
{
    $db = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = (string)($_POST['action'] ?? '');
        if ($a === 'toggle') {
            $db->prepare('UPDATE pages SET visible = 1 - visible, updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int)($_POST['id'] ?? 0)]);
            flash('Visibilidad actualizada.');
            redirect('/admin/paginas');
        }
        if ($a === 'delete') {
            admin_page_delete((int)($_POST['id'] ?? 0));
            return;
        }
        if ($a === 'template') {
            $t = page_templates()[(string)($_POST['tpl'] ?? '')] ?? null;
            if (!$t) {
                flash('Plantilla desconocida.', 'err');
                redirect('/admin/paginas/nueva');
            }
            $slug = unique_slug('pages', $t[2]);
            if (page_slug_error($slug) !== null) { // reservada o coincide con un archivo: no debería pasar con estos nombres
                flash('No se puede crear la plantilla: ' . page_slug_error($slug), 'err');
                redirect('/admin/paginas/nueva');
            }
            $now = date('Y-m-d H:i:s');
            $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM pages')->fetchColumn();
            $db->prepare('INSERT INTO pages (title, slug, body, visible, sort, created_at, updated_at) VALUES (?,?,?,0,?,?,?)')
                ->execute([$t[1], $slug, $t[3], $max, $now, $now]);
            flash('Plantilla «' . $t[0] . '» creada como borrador oculto. Revísala, completa lo que ponga [completar] y borra el aviso antes de publicarla.');
            redirect('/admin/paginas/' . (int)$db->lastInsertId());
        }
    }
    if ($sub === '') {
        $rows = $db->query('SELECT id, title, slug, body, visible, sort, updated_at FROM pages ORDER BY sort, title')->fetchAll();
        admin_render('pages_list', ['rows' => $rows, 'title' => 'Páginas']);
        return;
    }
    if ($sub === 'nueva') {
        $have = array_column($db->query('SELECT slug FROM pages')->fetchAll(), 'slug');
        $tpls = array_filter(page_templates(), fn($t) => !in_array($t[2], $have, true));
        if ($tpls && empty($_GET['en-blanco']) && ($_POST['action'] ?? '') !== 'save') {
            admin_render('page_new', ['tpls' => $tpls, 'first' => !$have, 'crumb' => 'Nueva', 'title' => 'Nueva página']);
            return;
        }
        admin_page_form(null);
        return;
    }
    $st = $db->prepare('SELECT * FROM pages WHERE id = ?');
    $st->execute([(int)$sub]);
    $page = $st->fetch();
    if (!$page || !ctype_digit($sub)) {
        not_found();
        return;
    }
    admin_page_form($page);
}

/** Crear o editar una página. */
function admin_page_form(?array $page): void
{
    $db = db();
    $isNew = $page === null;
    $f = $page ?? ['id' => 0, 'title' => '', 'slug' => '', 'body' => '', 'hero' => null, 'seo_title' => '', 'seo_desc' => '', 'visible' => 0, 'sort' => 0];
    $errors = [];
    $lost = 0;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('Las fotos pesan demasiado de una vez para el servidor.', 'err');
            redirect('/admin/paginas/' . ($isNew ? 'nueva' : (int)$f['id']));
        }
        $f['title'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? ''))), 0, 150); // se escapa al mostrarlo
        $f['body'] = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? ''))), 0, PAGE_BODY_MAX);
        $f['seo_title'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['seo_title'] ?? ''))), 0, 160);
        $f['seo_desc'] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['seo_desc'] ?? ''))), 0, 255); // VARCHAR(255) en MariaDB y SQL Server
        $f['visible'] = empty($_POST['visible']) ? 0 : 1;
        $f['sort'] = (int)($_POST['sort'] ?? 0);
        if ($f['title'] === '') {
            $errors[] = 'El título es obligatorio.';
        }
        $slugIn = trim((string)($_POST['slug'] ?? ''));
        $f['slug'] = slugify($slugIn !== '' ? $slugIn : $f['title']);
        if ($slugIn === '' && $f['title'] !== '') { // automática: si está repetida se le añade -2, -3…
            $f['slug'] = unique_slug('pages', $f['slug'], (int)$f['id']);
        }
        if ($f['title'] !== '' || $slugIn !== '') {
            if (($e = page_slug_error($f['slug'], (int)$f['id'])) !== null) {
                $errors[] = $e;
            }
        }
        if ($errors) {
            foreach (normalize_files($_FILES['images'] ?? null) as $file) {
                $lost += ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? 0 : 1;
            }
            if (!empty($_FILES['hero']['name']) && ($_FILES['hero']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                $lost++;
            }
        } else {
            $oldHero = (string)($f['hero'] ?? '');
            try {
                if (!empty($_POST['hero_remove'])) {
                    $f['hero'] = null;
                } elseif (!empty($_FILES['hero']['name']) && ($_FILES['hero']['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
                    $f['hero'] = save_image($_FILES['hero'], 2000, false);
                }
            } catch (RuntimeException $e) {
                flash('Imagen de cabecera: ' . $e->getMessage(), 'err');
            }
            // Fotos subidas: se guardan y se añaden al final del texto (con la descripción por rellenar)
            foreach (normalize_files($_FILES['images'] ?? null) as $file) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                try {
                    $f['body'] = rtrim($f['body']) . "\n\n![Describe la imagen](/uploads/" . save_image($file, 1600, false) . ')';
                } catch (RuntimeException $e) {
                    flash((($file['name'] ?? '') !== '' ? $file['name'] . ': ' : '') . $e->getMessage(), 'err');
                }
            }
            $f['body'] = trim($f['body']);
            $now = date('Y-m-d H:i:s');
            if ($isNew) {
                $db->prepare('INSERT INTO pages (title, slug, body, hero, seo_title, seo_desc, visible, sort, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$f['title'], $f['slug'], $f['body'], $f['hero'], $f['seo_title'], $f['seo_desc'], $f['visible'], $f['sort'], $now, $now]);
                $id = (int)$db->lastInsertId();
            } else {
                $id = (int)$f['id'];
                $db->prepare('UPDATE pages SET title = ?, slug = ?, body = ?, hero = ?, seo_title = ?, seo_desc = ?, visible = ?, sort = ?, updated_at = ? WHERE id = ?')
                    ->execute([$f['title'], $f['slug'], $f['body'], $f['hero'], $f['seo_title'], $f['seo_desc'], $f['visible'], $f['sort'], $now, $id]);
            }
            if ($oldHero !== '' && $oldHero !== (string)$f['hero'] && !upload_in_use($oldHero)) {
                delete_image($oldHero);
            }
            flash($isNew ? 'Página «' . $f['title'] . '» creada.' : 'Página guardada.');
            if ($f['visible'] && str_contains($f['body'], PAGE_REVIEW_MARK)) {
                flash('Esta página es pública pero todavía contiene el aviso «' . PAGE_REVIEW_MARK . '». Revisa el texto y borra ese párrafo.', 'err');
            }
            redirect('/admin/paginas/' . $id);
        }
    }
    admin_render('page_form', [
        'f' => $f, 'isNew' => $isNew, 'errors' => $errors, 'lostFiles' => $lost,
        'crumb' => $isNew ? 'Nueva' : 'Editar «' . $f['title'] . '»', 'title' => $isNew ? 'Nueva página' : 'Editar página',
    ]);
}

/** Borrar una página: primero una pantalla de confirmación (sin JavaScript), después se borra con sus fotos si nadie más las usa. */
function admin_page_delete(int $id): void
{
    $st = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) {
        flash('Esa página ya no existe.', 'err');
        redirect('/admin/paginas');
    }
    if (($_POST['confirm'] ?? '') !== '1') {
        admin_render('page_confirm', ['p' => $p, 'crumb' => 'Borrar', 'title' => 'Borrar página']);
        return;
    }
    db()->prepare('DELETE FROM pages WHERE id = ?')->execute([$id]);
    $gone = 0;
    foreach (page_files($p) as $file) {
        if (!upload_in_use($file)) {
            delete_image($file);
            $gone++;
        }
    }
    flash('Página «' . $p['title'] . '» borrada' . ($gone ? ' (y ' . $gone . ' foto' . ($gone === 1 ? '' : 's') . ' de uploads/)' : '') . '.');
    redirect('/admin/paginas');
}

/* ---------- Campos libres del catálogo ---------- */

function admin_fields(): void
{
    $db = db();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $a = (string)($_POST['action'] ?? '');
        try {
            if ($a === 'add') {
                field_create((string)($_POST['name'] ?? ''), (string)($_POST['kind'] ?? 'text'), (string)($_POST['unit'] ?? ''), empty($_POST['is_public']) ? 0 : 1, (string)($_POST['choices'] ?? ''));
                flash('Campo creado. Ya aparece en el formulario de cada artículo.');
            } elseif ($a === 'preset' && isset(FIELD_PRESETS[$_POST['preset'] ?? ''])) {
                [$n, $k, $u, $pub, $ch] = FIELD_PRESETS[$_POST['preset']];
                field_create($n, $k, $u, $pub, $ch);
                flash('Campo «' . $n . '» creado.');
            } elseif ($a === 'save') {
                $id = (int)($_POST['id'] ?? 0);
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
                $kind = (string)($_POST['kind'] ?? 'text');
                if ($name === '' || !isset(FIELD_KINDS[$kind])) {
                    throw new RuntimeException('Revisa el nombre y el tipo.');
                }
                $db->prepare('UPDATE custom_fields SET name = ?, kind = ?, unit = ?, is_public = ?, choices = ?, sort = ? WHERE id = ?')
                    ->execute([$name, $kind, mb_substr(trim((string)($_POST['unit'] ?? '')), 0, 20), empty($_POST['is_public']) ? 0 : 1,
                        trim((string)($_POST['choices'] ?? '')), (int)($_POST['sort'] ?? 0), $id]);
                flash('Campo guardado.');
            } elseif ($a === 'delete') {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare('DELETE FROM product_fields WHERE field_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM custom_fields WHERE id = ?')->execute([$id]);
                flash('Campo borrado, con sus valores en todos los artículos.');
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        }
        redirect('/admin/campos');
    }
    $counts = [];
    foreach ($db->query('SELECT field_id, COUNT(*) AS n FROM product_fields GROUP BY field_id')->fetchAll() as $r) {
        $counts[(int)$r['field_id']] = (int)$r['n'];
    }
    $have = array_column(fields_all(true), 'slug');
    admin_render('fields', [
        'fields' => fields_all(),
        'counts' => $counts,
        'presets' => array_diff_key(FIELD_PRESETS, array_flip($have)),
        'title' => 'Campos del catálogo',
    ]);
}

/* ---------- Importar (WordPress, hojas de cálculo y otros orígenes de app/importers/) ---------- */

/** Orígenes del importador con página propia en el menú: segmento de la dirección => id del origen. */
const IMPORT_OWN_PAGES = ['carpetas' => 'fotos-carpetas'];

/**
 * Importar. /admin/importar muestra todos los orígenes salvo los que tienen página propia
 * (/admin/importar/carpetas → «Fotos por carpeta»). El trabajo en curso es el mismo en las dos.
 */
function admin_import(string $sub = ''): void
{
    require_once __DIR__ . '/import.php';
    $sources = import_sources();
    if ($sub !== '' && !isset(IMPORT_OWN_PAGES[$sub])) {
        not_found();
        return;
    }
    $only = IMPORT_OWN_PAGES[$sub] ?? null;
    $self = '/admin/importar' . ($only ? '/' . $sub : '');

    // Plantilla CSV de un origen
    if (isset($_GET['plantilla'], $sources[$_GET['plantilla']]['template'])) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plantilla-' . $_GET['plantilla'] . '.csv"');
        echo ($sources[$_GET['plantilla']]['template'])();
        exit;
    }
    // Vista previa de una foto que venía en un ZIP o en una carpeta de data/importar/
    // (data/ no se puede abrir desde el navegador)
    if (isset($_GET['foto']) || isset($_GET['ftpfoto'])) {
        $f = isset($_GET['foto']) ? IMPORT_FILES_DIR . '/' . basename((string)$_GET['foto']) : (string)pf_ftp_path((string)$_GET['ftpfoto']);
        $type = is_file($f) ? (@getimagesize($f)['mime'] ?? '') : '';
        if (!str_starts_with($type, 'image/')) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $type);
        header('Cache-Control: private, max-age=600');
        readfile($f);
        exit;
    }

    $job = import_load();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            flash('Los archivos pesan más de lo que admite el servidor (' . ini_get('post_max_size') . '). Copia el ZIP de fotos por FTP a data/importar/ y vuelve a analizar.', 'err');
            redirect($self);
        }
        csrf_check();
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'analyze') {
                $plan = import_analyze_source((string)($_POST['source'] ?? ''), $_POST, $_FILES);
                admin_import_saved($plan);
            } elseif ($action === 'map' && $job && ($job['status'] ?? '') === 'map') {
                admin_import_saved(import_map_source($job, $_POST, $_FILES));
            } elseif ($action === 'start' && $job && ($job['status'] ?? '') === 'plan') {
                $new = import_build_job($job, $_POST);
                if (!$new['items']) {
                    flash('No has marcado nada para importar.', 'err');
                } else {
                    import_save($new);
                }
            } elseif ($action === 'reset') {
                import_clear();
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
        } catch (Throwable $e) {
            error_log((string)$e);
            flash('Ha ocurrido un error inesperado al analizar.', 'err');
        }
        redirect($self);
    }

    if ($job && ($job['status'] ?? '') === 'running') {
        if ((int)($job['lock'] ?? 0) < time()) {
            @set_time_limit(90);
            import_run($job, microtime(true) + import_budget());
        }
        $job = import_load();
    }
    admin_render('import', [
        'job' => $job,
        'sources' => $only ? array_intersect_key($sources, [$only => 1]) : array_diff_key($sources, array_flip(IMPORT_OWN_PAGES)),
        'only' => $only,
        'ftpZip' => import_zip_input(null),
        'refresh' => $job && ($job['status'] ?? '') === 'running',
        'title' => $only ? 'Fotos por carpeta' : 'Importar',
    ]);
}

/** Guarda el resultado de analizar: o un paso de columnas pendiente o un plan para revisar. */
function admin_import_saved(array $plan): void
{
    if (($plan['stage'] ?? '') === 'map') {
        $plan['status'] = 'map';
        import_save($plan);
        if (!empty($plan['msg'])) {
            flash($plan['msg']);
        }
        return;
    }
    $plan['status'] = 'plan';
    import_save($plan);
    if (($plan['mode'] ?? '') === 'update') {
        flash($plan['items'] ? count($plan['items']) . ' artículo(s) cambian. Revisa el resumen y pulsa «Aplicar los cambios».' : 'No hay nada que cambiar: la web ya tiene esos valores.');
        return;
    }
    flash(count($plan['items']) . ' artículo(s) encontrados. Revisa lo que se va a importar y pulsa «Importar lo marcado».');
}

/* ---------- Cambiar contraseña ---------- */

function admin_password(): void
{
    $errors = [];
    $db = db();
    $me = $db->prepare('SELECT id, username, email, totp_enabled FROM users WHERE id = ?');
    $me->execute([(int)$_SESSION['uid']]);
    $me = $me->fetch() ?: ['id' => 0, 'username' => '', 'email' => '', 'totp_enabled' => 0];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_POST['action'] ?? ''), 'totp_')) {
        admin_totp_post($me, $errors);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'forget_sites') {
        csrf_check();
        $n = auth_forget_sites((int)$_SESSION['uid']);
        flash($n > 1 ? 'Se han olvidado ' . ($n - 1) . ' sitio' . ($n - 1 === 1 ? '' : 's') . ' conocido' . ($n - 1 === 1 ? '' : 's') . '. Solo queda este, el que estás usando ahora.' : 'No había otros sitios conocidos: solo queda este, el que estás usando ahora.');
        redirect('/admin/clave');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email') {
        csrf_check();
        $em = trim((string)($_POST['email'] ?? ''));
        if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'El correo no es válido.';
        } else {
            $db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$em, (int)$_SESSION['uid']]);
            flash('Correo guardado. Es el que se usa para recuperar la contraseña.');
            redirect('/admin/clave');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !str_starts_with((string)($_POST['action'] ?? ''), 'totp_')) {
        csrf_check();
        $st = $db->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([(int)$_SESSION['uid']]);
        $user = $st->fetch();
        $new = (string)($_POST['new'] ?? '');
        if (!$user || !password_verify((string)($_POST['current'] ?? ''), $user['pass_hash'])) {
            $errors[] = 'La contraseña actual no es correcta.';
        }
        if (mb_strlen($new) < 10) {
            $errors[] = 'La nueva contraseña debe tener al menos 10 caracteres.';
        }
        if ($new !== (string)($_POST['new2'] ?? '')) {
            $errors[] = 'Las contraseñas nuevas no coinciden.';
        }
        if (!$errors) {
            $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            flash('Contraseña cambiada.');
            redirect('/admin');
        }
    }
    $setup = (string)($_SESSION['totp_setup'] ?? '');
    $codes = $_SESSION['totp_codes'] ?? null; // se muestran una sola vez
    unset($_SESSION['totp_codes']);
    admin_render('password', ['errors' => $errors, 'me' => $me, 'tf' => [
        'enabled' => (int)$me['totp_enabled'] === 1, 'left' => auth_recovery_left((int)$me['id']),
        'setup' => $setup, 'pretty' => $setup !== '' ? totp_pretty($setup) : '', 'uri' => $setup !== '' ? totp_uri($setup, (string)$me['username']) : '',
        'codes' => is_array($codes) ? $codes : null,
    ], 'sites' => auth_sites((int)$_SESSION['uid']), 'siteNow' => auth_current_site(), 'title' => 'Mi cuenta']);
}


/** Doble verificación en «Mi cuenta»: empezar, confirmar con un código, desactivar o pedir códigos de recuperación nuevos. */
function admin_totp_post(array $me, array &$errors): void
{
    csrf_check();
    $db = db();
    $uid = (int)$me['id'];
    $act = (string)($_POST['action'] ?? '');
    $enabled = (int)$me['totp_enabled'] === 1;
    if ($act === 'totp_start' && !$enabled) {
        $_SESSION['totp_setup'] = totp_new_secret(); // hasta confirmar con un código, NO se guarda en la base de datos
        redirect('/admin/clave#doble');
    }
    if ($act === 'totp_cancel') {
        unset($_SESSION['totp_setup']);
        redirect('/admin/clave#doble');
    }
    if ($act === 'totp_enable' && !$enabled) {
        $secret = (string)($_SESSION['totp_setup'] ?? '');
        $step = $secret !== '' ? totp_check($secret, (string)($_POST['code'] ?? '')) : null;
        if ($step === null) {
            $errors[] = $secret === '' ? 'Pulsa «Activar» para empezar de nuevo.' : 'El código no es correcto. Comprueba que has copiado bien la clave y que la hora del móvil es la correcta.';
            return;
        }
        $db->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?')->execute([$secret, $step, $uid]);
        $codes = auth_new_recovery_codes();
        auth_save_recovery_codes($uid, $codes);
        unset($_SESSION['totp_setup']);
        $_SESSION['totp_codes'] = $codes;
        flash('Doble verificación activada. Guarda ahora los códigos de recuperación.');
        redirect('/admin/clave#doble');
    }
    if (($act === 'totp_disable' || $act === 'totp_newcodes') && $enabled) {
        $st = $db->prepare('SELECT * FROM users WHERE id = ?');
        $st->execute([$uid]);
        $user = $st->fetch();
        if (!$user || !password_verify((string)($_POST['current'] ?? ''), (string)$user['pass_hash']) || !auth_verify_second_factor($user, (string)($_POST['code'] ?? ''))) {
            auth_register_failure($uid);
            $errors[] = 'La contraseña o el código no son correctos.';
            return;
        }
        if ($act === 'totp_disable') {
            auth_totp_disable($uid);
            flash('Doble verificación desactivada.');
        } else {
            $codes = auth_new_recovery_codes();
            auth_save_recovery_codes($uid, $codes);
            $_SESSION['totp_codes'] = $codes;
            flash('Códigos de recuperación nuevos. Los anteriores ya no valen.');
        }
        redirect('/admin/clave#doble');
    }
}


/* ---------- Usuarios (solo administradores) ---------- */

const USER_ROLES = ['admin' => 'Administrador (acceso a todo)', 'catalogo' => 'Gestión de catálogo', 'erp' => 'ERP (clientes y ventas)'];

/** Secciones del ERP (clientes y ventas de NCS): solo rol «erp» y administradores. */
const ERP_PAGES = ['clientes', 'ventas', 'erp'];

function admin_users(): void
{
    $db = db();
    $errors = [];
    $me = (int)$_SESSION['uid'];
    $form = ['username' => '', 'role' => 'catalogo', 'email' => ''];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? '');
        $admins = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
        $id = (int)($_POST['id'] ?? 0);
        $tg = $db->prepare('SELECT * FROM users WHERE id = ?');
        $tg->execute([$id]);
        $target = $tg->fetch();
        if ($act === 'create') {
            $form = ['username' => trim((string)($_POST['username'] ?? '')), 'role' => (string)($_POST['role'] ?? ''), 'email' => trim((string)($_POST['email'] ?? ''))];
            $pass = (string)($_POST['password'] ?? '');
            if (mb_strlen($form['username']) < 3) {
                $errors[] = 'El usuario debe tener al menos 3 caracteres.';
            }
            if (!isset(USER_ROLES[$form['role']])) {
                $errors[] = 'Elige un rol.';
            }
            if (mb_strlen($pass) < 10) {
                $errors[] = 'La contraseña debe tener al menos 10 caracteres.';
            }
            if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'El correo no es válido.';
            }
            $ex = $db->prepare('SELECT 1 FROM users WHERE username = ? COLLATE NOCASE');
            $ex->execute([$form['username']]);
            if ($ex->fetchColumn()) {
                $errors[] = 'Ya existe un usuario con ese nombre.';
            }
            if (!$errors) {
                $db->prepare('INSERT INTO users (username, pass_hash, role, email) VALUES (?, ?, ?, ?)')
                    ->execute([$form['username'], password_hash($pass, PASSWORD_DEFAULT), $form['role'], $form['email']]);
                flash('Usuario creado.');
                redirect('/admin/usuarios');
            }
        } elseif ($target && $act === 'email') {
            $em = trim((string)($_POST['email'] ?? ''));
            if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'El correo no es válido.';
            } else {
                $db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$em, $id]);
                flash('Correo guardado.');
                redirect('/admin/usuarios');
            }
        } elseif ($target && $act === 'sendreset') {
            if (reset_send($target)) {
                flash('Enlace enviado a ' . $target['email'] . '.');
            } else {
                flash('No se pudo enviar: comprueba que el usuario tiene un correo válido.', 'err');
            }
            redirect('/admin/usuarios');
        } elseif ($target && $act === 'role') {
            $role = (string)($_POST['role'] ?? '');
            if (!isset(USER_ROLES[$role])) {
                $errors[] = 'Rol no válido.';
            } elseif ($target['role'] === 'admin' && $role !== 'admin' && $admins <= 1) {
                $errors[] = 'Debe quedar al menos un administrador.';
            } else {
                $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
                flash('Rol cambiado.');
                redirect('/admin/usuarios');
            }
        } elseif ($target && $act === 'password') {
            $pass = (string)($_POST['password'] ?? '');
            if (mb_strlen($pass) < 10) {
                $errors[] = 'La contraseña debe tener al menos 10 caracteres.';
            } else {
                $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
                auth_unlock($id); // una contraseña nueva también quita el bloqueo
                flash('Contraseña de «' . $target['username'] . '» cambiada.');
                redirect('/admin/usuarios');
            }
        } elseif ($target && $act === 'totp_off' && $id !== $me) {
            auth_totp_disable($id);
            flash('Doble verificación de «' . $target['username'] . '» desactivada.');
            redirect('/admin/usuarios');
        } elseif ($target && $act === 'unlock') {
            auth_unlock($id);
            flash('Usuario «' . $target['username'] . '» desbloqueado.');
            redirect('/admin/usuarios');
        } elseif ($target && $act === 'delete') {
            if ($id === $me) {
                $errors[] = 'No puedes borrar tu propio usuario.';
            } elseif ($target['role'] === 'admin' && $admins <= 1) {
                $errors[] = 'Debe quedar al menos un administrador.';
            } else {
                $db->prepare('DELETE FROM login_devices WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
                flash('Usuario borrado.');
                redirect('/admin/usuarios');
            }
        }
    }
    admin_render('users', [
        'users' => $db->query('SELECT id, username, role, email, locked_until, last_login_at, totp_enabled FROM users ORDER BY username COLLATE NOCASE')->fetchAll(),
        'errors' => $errors, 'form' => $form, 'me' => $me, 'title' => 'Usuarios',
    ]);
}


/* ---------- Olvido de contraseña ---------- */

/** Crea un enlace de un solo uso (1 h) y lo envía por correo. Devuelve si el envío se aceptó. */
function reset_send(array $user): bool
{
    if (trim((string)$user['email']) === '' || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $db = db();
    $db->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires < ?')->execute([$user['id'], time()]);
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO password_resets (user_id, token_hash, expires) VALUES (?, ?, ?)')
        ->execute([$user['id'], hash('sha256', $token), time() + 3600]);
    $link = site_origin() . url('/admin/restablecer/' . $token);
    $body = "Hola " . $user['username'] . ",\r\n\r\nPara elegir una contraseña nueva en el panel de " . site_name() . " abre este enlace (válido 1 hora):\r\n\r\n"
        . $link . "\r\n\r\nSi no lo has pedido tú, ignora este mensaje: tu contraseña no cambia.\r\n";
    return app_mail($user['email'], 'Restablecer contraseña · Panel ' . site_name(), $body);
}

function admin_forgot(): void
{
    $db = db();
    $sent = false;
    $errors = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $ip = 'r:' . ($_SERVER['REMOTE_ADDR'] ?? '0');
        $db->prepare('DELETE FROM login_attempts WHERE ts < ?')->execute([time() - 900]);
        $n = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ?');
        $n->execute([$ip]);
        if ((int)$n->fetchColumn() >= 5) {
            $errors[] = 'Demasiadas solicitudes. Espera 15 minutos y vuelve a probar.';
        } else {
            $db->prepare('INSERT INTO login_attempts (ip, ts) VALUES (?, ?)')->execute([$ip, time()]);
            $q = trim((string)($_POST['who'] ?? ''));
            if ($q !== '') {
                $u = $db->prepare('SELECT * FROM users WHERE username = ? COLLATE NOCASE OR (email <> \'\' AND email = ? COLLATE NOCASE) LIMIT 1');
                $u->execute([$q, $q]);
                if ($user = $u->fetch()) {
                    reset_send($user);
                }
            }
            $sent = true; // misma respuesta exista o no la cuenta
        }
    }
    render('admin/forgot', ['sent' => $sent, 'errors' => $errors, 'title' => 'Recuperar contraseña · Panel ' . site_name()], 'admin/layout_bare');
}

function admin_reset(string $token): void
{
    $db = db();
    $row = null;
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $st = $db->prepare('SELECT * FROM password_resets WHERE token_hash = ? AND expires > ?');
        $st->execute([hash('sha256', $token), time()]);
        $row = $st->fetch();
    }
    $errors = [];
    if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $new = (string)($_POST['new'] ?? '');
        if (mb_strlen($new) < 10) {
            $errors[] = 'La contraseña debe tener al menos 10 caracteres.';
        } elseif ($new !== (string)($_POST['new2'] ?? '')) {
            $errors[] = 'Las contraseñas no coinciden.';
        } else {
            $db->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $row['user_id']]);
            auth_unlock((int)$row['user_id']); // elegir contraseña nueva por correo también desbloquea
            $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$row['user_id']]);
            flash('Contraseña cambiada. Ya puedes entrar.');
            redirect('/admin/login');
        }
    }
    render('admin/reset', ['valid' => (bool)$row, 'errors' => $errors, 'title' => 'Nueva contraseña · Panel ' . site_name()], 'admin/layout_bare');
}


/* ---------- Copia de seguridad y «Empezar de cero» ---------- */

/** Crea un ZIP con la base de datos y las fotos. Devuelve la ruta del archivo temporal. */
function backup_make(string $dest, bool $withErp = false): bool
{
    if (!class_exists('ZipArchive')) {
        return false;
    }
    $zip = new ZipArchive();
    if ($zip->open($dest, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    // La copia lleva siempre la base de datos como archivo SQLite (se puede restaurar en cualquier motor)
    $tmpDb = DATA_DIR . '/.copia-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        if (db()->drv === 'sqlite') {
            db()->exec("VACUUM INTO '" . str_replace("'", "''", $tmpDb) . "'");
        } else {
            @set_time_limit(300);
            db_export_sqlite($tmpDb, $withErp);
        }
        auth_scrub_backup($tmpDb, $withErp); // la copia no lleva las claves de doble verificación
        $zip->addFile($tmpDb, 'data/site.sqlite');
    } catch (Throwable $e) {
        error_log((string)$e);
        $zip->close();
        @unlink($tmpDb);
        @unlink($dest);
        return false; // mejor sin copia que una copia con las claves
    }
    $root = ROOT . '/uploads';
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $zip->addFile($f->getPathname(), 'uploads/' . substr($f->getPathname(), strlen($root) + 1));
            }
        }
    }
    $ok = $zip->close();
    if (is_file($tmpDb)) {
        @unlink($tmpDb);
    }
    return $ok;
}

function admin_backup(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect('/admin/empresa');
    }
    csrf_check();
    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'bak');
    if (!$tmp || !backup_make($tmp, !empty($_POST['with_erp']))) { // clientes y ventas solo si se marca la casilla
        flash('No se pudo crear la copia de seguridad en este servidor.', 'err');
        redirect('/admin/empresa');
    }
    $name = 'copia-' . preg_replace('/[^a-z0-9]+/', '-', strtolower(site_name())) . '-' . date('Y-m-d-His') . '.zip';
    save_setting('last_backup_at', date('Y-m-d H:i:s')); // para el aviso de Inicio (más de 30 días sin copia)
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function admin_reset_catalog(): void
{
    if (!ALLOW_RESET || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        flash('«Empezar de cero» está desactivado en esta instalación.', 'err');
        redirect('/admin/empresa');
    }
    csrf_check();
    $db = db();
    $me = $db->prepare('SELECT pass_hash FROM users WHERE id = ?');
    $me->execute([(int)$_SESSION['uid']]);
    $hash = (string)$me->fetchColumn();
    if (trim((string)($_POST['confirm'] ?? '')) !== site_name() || !password_verify((string)($_POST['password'] ?? ''), $hash)) {
        flash('No coincide el nombre escrito o la contraseña. No se ha borrado nada.', 'err');
        redirect('/admin/empresa');
    }
    @set_time_limit(300);
    $dir = DATA_DIR . '/backups';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $bak = $dir . '/antes-de-vaciar-' . date('Y-m-d-His') . '.zip';
    if (!backup_make($bak)) {
        flash('No se pudo hacer la copia previa, así que no se ha borrado nada.', 'err');
        redirect('/admin/empresa');
    }
    // Fotos: se conservan solo los archivos de marca (logotipo, banner, icono, fondo de portada)
    $keep = array_filter(array_map('setting', ['logo', 'footer_banner', 'favicon', 'hero_image']));
    $root = ROOT . '/uploads';
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $rel = substr($f->getPathname(), strlen($root) + 1);
            if ($f->isDir()) {
                @rmdir($f->getPathname());
            } elseif ($rel !== 'index.html' && !in_array($rel, $keep, true)) {
                @unlink($f->getPathname());
            }
        }
    }
    $db->exec('UPDATE categories SET parent_id = NULL');
    foreach (['product_fields', 'product_images', 'products', 'categories', 'reps', 'slides'] as $t) {
        $db->exec('DELETE FROM ' . $t);
    }
    foreach (['boxes.json', 'gaps.json', 'sigs.json', 'import_job.json'] as $c) {
        @unlink(DATA_DIR . '/' . $c);
    }
    $db->exec("DELETE FROM settings WHERE key IN ('ftp_auto','ftp_done','ftp_fail')");
    flash('Catálogo vaciado. Copia previa guardada en data/backups/' . basename($bak) . '.');
    redirect('/admin/empresa');
}
