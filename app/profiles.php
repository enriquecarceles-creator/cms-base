<?php
declare(strict_types=1);

/*
 * Perfiles de importación: emparejamientos de columnas guardados (tabla import_profiles).
 *
 *   mapping_json  qué columna es cada cosa: table, name, ref, family, visible, img, fam_table…
 *                 y 'fields' => [nombre o slug del campo libre => columna]. Los campos libres se
 *                 guardan por nombre (no por número), así el perfil sirve en cualquier web.
 *   rules_json    require_tables  tablas que debe tener la base de datos para proponer el perfil
 *                 exclude_columns columnas (o tablas) que NUNCA se leen; «PREFIJO*» vale para todas
 *                                 las que empiezan así. Se aplican siempre que la base de datos
 *                                 tenga las tablas del perfil, aunque se use otro perfil o ninguno.
 *                 fields          campos libres que el perfil necesita: slug => [nombre, tipo, unidad,
 *                                 público]. Si no existen en la web, se crean al aplicar el perfil.
 *
 * Los perfiles de sistema se definen aquí (IMPORT_SYSTEM_PROFILES) y se copian a la tabla solos
 * (profiles_ensure_system); no se pueden borrar ni modificar desde el panel.
 */

const IMPORT_SYSTEM_PROFILES = [
    [
        'name' => 'NCS Facturación — Almacén (FALMACEN.MDB)',
        'source_type' => 'mdb',
        'target_module' => 'catalog',
        'mapping' => [
            'table' => 'TART',
            'ref' => 'CODIGO',
            'name' => 'DESCRIPCION',
            'family' => 'FAMILIA',
            'visible' => 'BLOQUEADO',
            'visible_inv' => '1',          // BLOQUEADO = 0 → visible
            'img' => 'FOTO',               // ruta de Windows: se empareja por nombre con el ZIP de fotos
            'folders' => '1',              // si FOTO está vacía: carpeta ARTICULO_<CODIGO> (app/photofolders.php)
            'caps' => '1',
            'fam_table' => 'TFAM',
            'fam_id' => 'CODIGO',
            'fam_name' => 'FAMILIA',
            'fields' => ['precio' => 'PVP2', 'tallas' => 'T00', 'color' => 'C00', 'stock' => 'SACTUAL'],
        ],
        'rules' => [
            'require_tables' => ['TART', 'TFAM'],
            // Notas internas con precios (y sus traducciones AMPLIACIONDING, …FRA…): nunca se leen.
            'exclude_columns' => ['AMPLIACIOND*'],
            'fields' => [
                'precio' => ['Precio', 'price', '€', 1],
                'tallas' => ['Tallas', 'text', '', 1],
                'color' => ['Color', 'text', '', 1],
                'stock' => ['Stock', 'number', 'uds.', 0],
            ],
        ],
    ],
];

/** ¿El nombre encaja con el patrón? («AMPLIACIOND*» = empieza por; sin distinguir mayúsculas). */
function profiles_name_match(string $pattern, string $name): bool
{
    $p = mb_strtolower(trim($pattern));
    $n = mb_strtolower(trim($name));
    if ($p === '') {
        return false;
    }
    return str_ends_with($p, '*') ? str_starts_with($n, rtrim($p, '*')) : $p === $n;
}

function profiles_excluded(array $patterns, string $name): bool
{
    foreach ($patterns as $p) {
        if (profiles_name_match((string)$p, $name)) {
            return true;
        }
    }
    return false;
}

/** Nombre real de una tabla de $tables (sin distinguir mayúsculas), o null. */
function profiles_table(array $tables, string $want): ?string
{
    if ($want === '') {
        return null;
    }
    foreach (array_keys($tables) as $t) {
        if (mb_strtolower((string)$t) === mb_strtolower($want)) {
            return (string)$t;
        }
    }
    return null;
}

/** Nombre real de una columna (sin distinguir mayúsculas), o null. */
function profiles_column(array $cols, string $want): ?string
{
    if ($want === '') {
        return null;
    }
    foreach ($cols as $c) {
        if (mb_strtolower((string)$c) === mb_strtolower($want)) {
            return (string)$c;
        }
    }
    return null;
}

/** Copia (o actualiza) los perfiles de sistema en la tabla. Se llama cada vez que se listan. */
function profiles_ensure_system(): void
{
    $db = db();
    foreach (IMPORT_SYSTEM_PROFILES as $p) {
        $mapping = json_encode($p['mapping'], JSON_UNESCAPED_UNICODE);
        $rules = json_encode($p['rules'], JSON_UNESCAPED_UNICODE);
        $st = $db->prepare('SELECT id, mapping_json, rules_json FROM import_profiles WHERE is_system = 1 AND name = ?');
        $st->execute([$p['name']]);
        $row = $st->fetch();
        if (!$row) {
            $db->prepare('INSERT INTO import_profiles (name, source_type, target_module, mapping_json, rules_json, is_system, created_at) VALUES (?,?,?,?,?,1,?)')
                ->execute([$p['name'], $p['source_type'], $p['target_module'], $mapping, $rules, date('Y-m-d H:i:s')]);
        } elseif ($row['mapping_json'] !== $mapping || $row['rules_json'] !== $rules) {
            $db->prepare('UPDATE import_profiles SET mapping_json = ?, rules_json = ? WHERE id = ?')
                ->execute([$mapping, $rules, (int)$row['id']]);
        }
    }
}

/** Todos los perfiles: [id => [id, name, source_type, target_module, is_system, mapping, rules]]. */
function profiles_all(): array
{
    profiles_ensure_system();
    $out = [];
    foreach (db()->query('SELECT * FROM import_profiles ORDER BY is_system DESC, name')->fetchAll() as $r) {
        $out[(int)$r['id']] = [
            'id' => (int)$r['id'],
            'name' => (string)$r['name'],
            'source_type' => (string)$r['source_type'],
            'target_module' => (string)$r['target_module'],
            'is_system' => (int)$r['is_system'],
            'mapping' => json_decode((string)$r['mapping_json'], true) ?: [],
            'rules' => json_decode((string)$r['rules_json'], true) ?: [],
        ];
    }
    return $out;
}

/** Tablas que necesita un perfil. */
function profiles_required_tables(array $p): array
{
    $req = (array)($p['rules']['require_tables'] ?? []);
    if (!$req) {
        foreach (['table', 'fam_table', 'img_table'] as $k) {
            if (!empty($p['mapping'][$k])) {
                $req[] = (string)$p['mapping'][$k];
            }
        }
    }
    return array_values(array_unique($req));
}

/** Perfiles que valen para estas tablas (las tienen todas). */
function profiles_compatible(array $tables, string $module = 'catalog'): array
{
    $out = [];
    foreach (profiles_all() as $id => $p) {
        if ($p['target_module'] !== $module) {
            continue;
        }
        $req = profiles_required_tables($p);
        if (!$req) {
            continue;
        }
        foreach ($req as $t) {
            if (profiles_table($tables, $t) === null) {
                continue 2;
            }
        }
        $out[$id] = $p;
    }
    return $out;
}

/** Columnas prohibidas por los perfiles que encajan con estas tablas (siempre se aplican). */
function profiles_forced_excludes(array $tables): array
{
    $out = [];
    foreach (profiles_compatible($tables) as $p) {
        foreach ((array)($p['rules']['exclude_columns'] ?? []) as $x) {
            $out[] = (string)$x;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Quita de la lista de tablas las tablas y columnas prohibidas.
 * @return array{0: array, 1: string[]} [tablas, nombres quitados]
 */
function profiles_strip(array $tables, array $patterns): array
{
    $gone = [];
    if (!$patterns) {
        return [$tables, $gone];
    }
    foreach ($tables as $t => $info) {
        if (profiles_excluded($patterns, (string)$t)) {
            $gone[] = (string)$t;
            unset($tables[$t]);
            continue;
        }
        $keep = [];
        foreach ($info['cols'] as $c) {
            if (profiles_excluded($patterns, (string)$c)) {
                $gone[] = (string)$c;
            } else {
                $keep[] = $c;
            }
        }
        $tables[$t]['cols'] = $keep;
    }
    return [$tables, array_values(array_unique($gone))];
}

/** Campo libre por slug o por nombre (sin acentos ni mayúsculas), o null. */
function profiles_field(string $ref): ?array
{
    $n = import_norm_simple($ref);
    foreach (fields_all() as $f) {
        if ($f['slug'] === $ref || import_norm_simple($f['slug']) === $n || import_norm_simple($f['name']) === $n) {
            return $f;
        }
    }
    return null;
}

/**
 * Crea los campos que necesita el perfil y no existen (buscándolos por nombre, sin duplicar).
 * @return string[] nombres de los campos creados
 */
function profiles_ensure_fields(array $defs): array
{
    $created = [];
    foreach ($defs as $slug => $d) {
        [$name, $kind, $unit, $public] = array_pad(array_values((array)$d), 4, '');
        if (profiles_field((string)$slug) || profiles_field((string)$name)) {
            continue;
        }
        field_create((string)$name, (string)$kind, (string)$unit, (int)$public);
        $created[] = (string)$name . ((int)$public ? '' : ' (interno)');
    }
    return $created;
}

/** Guarda un perfil propio (si ya hay uno propio con ese nombre, lo sustituye). Devuelve su id. */
function profiles_save(string $name, string $sourceType, array $mapping, array $rules): int
{
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') {
        throw new RuntimeException('Escribe un nombre para el perfil.');
    }
    $db = db();
    foreach (IMPORT_SYSTEM_PROFILES as $p) {
        if (mb_strtolower($p['name']) === mb_strtolower($name)) {
            throw new RuntimeException('Ese nombre es el de un perfil de sistema. Elige otro.');
        }
    }
    $sourceType = in_array($sourceType, ['mdb', 'sql', 'csv'], true) ? $sourceType : 'sql';
    $mj = json_encode($mapping, JSON_UNESCAPED_UNICODE);
    $rj = json_encode($rules, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare('SELECT id FROM import_profiles WHERE is_system = 0 AND name = ?');
    $st->execute([$name]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        $db->prepare('UPDATE import_profiles SET source_type = ?, mapping_json = ?, rules_json = ? WHERE id = ?')
            ->execute([$sourceType, $mj, $rj, $id]);
        return $id;
    }
    $db->prepare('INSERT INTO import_profiles (name, source_type, target_module, mapping_json, rules_json, is_system, created_at) VALUES (?,?,?,?,?,0,?)')
        ->execute([$name, $sourceType, 'catalog', $mj, $rj, date('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
}

/** Borra un perfil propio (los de sistema no se pueden borrar). */
function profiles_delete(int $id): string
{
    $st = db()->prepare('SELECT name, is_system FROM import_profiles WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Ese perfil ya no existe.');
    }
    if ((int)$row['is_system'] === 1) {
        throw new RuntimeException('Los perfiles de sistema no se pueden borrar.');
    }
    db()->prepare('DELETE FROM import_profiles WHERE id = ? AND is_system = 0')->execute([$id]);
    return (string)$row['name'];
}

/* ---------- Datos → Perfiles (/admin/perfiles): lista, ver, renombrar, duplicar y borrar ---------- */

const PROFILE_SOURCE_LABELS = ['mdb' => 'Access (.mdb)', 'sql' => 'SQL / SQLite', 'csv' => 'CSV'];
const PROFILE_MODULE_LABELS = ['catalog' => 'Artículos', 'customers' => 'Clientes', 'sales' => 'Ventas'];

/** Un perfil por su id (con mapping y rules ya decodificados) o null. */
function profiles_get(int $id): ?array
{
    return profiles_all()[$id] ?? null;
}

/** ¿El nombre ya lo usa otro perfil propio (distinto de $exceptId) o es el de uno de sistema? Devuelve el motivo o null. */
function profiles_name_error(string $name, int $exceptId = 0): ?string
{
    if ($name === '') {
        return 'Escribe un nombre para el perfil.';
    }
    foreach (IMPORT_SYSTEM_PROFILES as $p) {
        if (mb_strtolower($p['name']) === mb_strtolower($name)) {
            return 'Ese nombre es el de un perfil de sistema. Elige otro.';
        }
    }
    $st = db()->prepare('SELECT id, name FROM import_profiles WHERE is_system = 0 AND id <> ?');
    $st->execute([$exceptId]);
    foreach ($st->fetchAll() as $r) {
        if (mb_strtolower((string)$r['name']) === mb_strtolower($name)) {
            return 'Ya tienes un perfil con ese nombre.';
        }
    }
    return null;
}

/** Cambia el nombre de un perfil propio. Lanza RuntimeException con el motivo si no se puede. */
function profiles_rename(int $id, string $name): void
{
    $p = profiles_get($id);
    if (!$p) {
        throw new RuntimeException('Ese perfil ya no existe.');
    }
    if ($p['is_system'] === 1) {
        throw new RuntimeException('Los perfiles de sistema no se pueden renombrar. Duplícalo y cambia el nombre de la copia.');
    }
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? ''), 0, 120);
    if ($err = profiles_name_error($name, $id)) {
        throw new RuntimeException($err);
    }
    db()->prepare('UPDATE import_profiles SET name = ? WHERE id = ? AND is_system = 0')->execute([$name, $id]);
}

/** Copia un perfil (de sistema o propio) como perfil propio nuevo: «Copia de X», «Copia de X (2)»… Devuelve el id nuevo. */
function profiles_duplicate(int $id): int
{
    $db = db();
    $st = $db->prepare('SELECT * FROM import_profiles WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('Ese perfil ya no existe.');
    }
    $base = mb_substr('Copia de ' . $row['name'], 0, 110);
    $name = $base;
    for ($n = 2; profiles_name_error($name) !== null && $n < 100; $n++) {
        $name = $base . ' (' . $n . ')';
    }
    $db->prepare('INSERT INTO import_profiles (name, source_type, target_module, mapping_json, rules_json, is_system, created_at) VALUES (?,?,?,?,?,0,?)')
        ->execute([$name, $row['source_type'], $row['target_module'], $row['mapping_json'], $row['rules_json'], date('Y-m-d H:i:s')]);
    $st = $db->prepare('SELECT id FROM import_profiles WHERE is_system = 0 AND name = ?');
    $st->execute([$name]);
    return (int)$st->fetchColumn();
}

/** Controlador de /admin/perfiles[/<id>]. Todo POST lleva CSRF; los perfiles de sistema solo se ven y se duplican. */
function admin_profiles(string $sub = ''): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $act = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($act === 'duplicate') {
                $new = profiles_duplicate($id);
                flash('Perfil duplicado. Ahora puedes cambiarle el nombre.');
                redirect('/admin/perfiles/' . $new);
            } elseif ($act === 'rename') {
                profiles_rename($id, (string)($_POST['name'] ?? ''));
                flash('Nombre cambiado.');
                redirect('/admin/perfiles/' . $id);
            } elseif ($act === 'delete') {
                if (empty($_POST['confirm'])) {
                    flash('Marca la casilla para confirmar el borrado.', 'err');
                    redirect('/admin/perfiles/' . $id . '?borrar=1');
                }
                $name = profiles_delete($id);
                flash('Perfil «' . $name . '» borrado.');
                redirect('/admin/perfiles');
            }
        } catch (RuntimeException $e) {
            flash($e->getMessage(), 'err');
            redirect($id > 0 && profiles_get($id) ? '/admin/perfiles/' . $id : '/admin/perfiles');
        }
        redirect('/admin/perfiles');
    }
    if ($sub !== '') {
        $p = ctype_digit($sub) ? profiles_get((int)$sub) : null;
        if (!$p) {
            not_found();
            return;
        }
        admin_render('profile_view', ['p' => $p, 'confirmDelete' => isset($_GET['borrar']) && $p['is_system'] === 0, 'crumb' => $p['name'], 'title' => 'Perfil']);
        return;
    }
    $rows = profiles_all();
    $created = [];
    foreach (db()->query('SELECT id, created_at FROM import_profiles')->fetchAll() as $r) {
        $created[(int)$r['id']] = (string)$r['created_at'];
    }
    admin_render('profiles', ['rows' => $rows, 'created' => $created, 'title' => 'Perfiles']);
}
