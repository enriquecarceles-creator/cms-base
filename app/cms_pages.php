<?php
declare(strict_types=1);

/*
 * Páginas propias (Web → Páginas): /<slug> con texto en MARKDOWN LIMITADO.
 *
 * Seguridad: el texto nunca se publica como HTML. md_render() genera todo el HTML ella sola con un conjunto
 * cerrado de etiquetas sin atributos (p, h2, h3, ul, ol, li, blockquote, strong, em, br) más <a> e <img>
 * con direcciones validadas. Todo lo demás se escapa con e(): un «<script>» escrito en el texto se ve como
 * texto. Formato admitido:
 *   # / ## título (h2) · ### subtítulo (h3) · **negrita** · *cursiva* · - viñeta · 1. numerada · > cita
 *   [texto](dirección) · ![descripción](/uploads/foto.webp)
 * Enlaces permitidos: http(s)://, mailto:, tel:, #ancla y direcciones de la propia web que empiezan por «/».
 * Imágenes permitidas: solo archivos subidos a uploads/ (nunca externos).
 */

const PAGE_BODY_MAX = 60000;
const PAGE_REVIEW_MARK = 'BORRADOR SIN REVISAR';

/** Direcciones que una página no puede usar (rutas de la web y carpetas/archivos de la instalación). */
const PAGE_RESERVED = ['catalogo', 'producto', 'contacto', 'admin', 'buscar', 'sitemap.xml', 'robots.txt', 'assets', 'uploads',
    'data', 'app', 'index.php', 'instalar.php', 'router.php', 'favicon.ico', 'login', 'api'];

/** Mensaje de error si la dirección no vale (o null si vale). */
function page_slug_error(string $slug, int $ignoreId = 0): ?string
{
    if ($slug === '' || strlen($slug) < 2 || strlen($slug) > 80 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        return 'La dirección solo puede llevar letras minúsculas, números y guiones (mínimo 2 caracteres).';
    }
    if (in_array($slug, PAGE_RESERVED, true)) {
        return '«' . $slug . '» está reservada por la web. Elige otra dirección.';
    }
    if (file_exists(ROOT . '/' . $slug)) { // archivo o carpeta real de la raíz de la instalación
        return '«' . $slug . '» coincide con un archivo o carpeta de la web. Elige otra dirección.';
    }
    $st = db()->prepare('SELECT COUNT(*) FROM pages WHERE slug = ? AND id <> ?');
    $st->execute([$slug, $ignoreId]);
    return (int)$st->fetchColumn() > 0 ? 'Ya hay otra página con la dirección «' . $slug . '».' : null;
}

/** Todas las páginas (también las ocultas) en su orden: una sola consulta por petición. */
function pages_all(): array
{
    static $rows = null;
    if ($rows === null) {
        try {
            $rows = db()->query('SELECT id, title, slug, visible, sort FROM pages ORDER BY sort, title')->fetchAll();
        } catch (PDOException $e) {
            $rows = [];
        }
    }
    return $rows;
}

/** Páginas visibles en orden (sitemap y menús): [['title', 'slug', …], …]. */
function pages_visible(): array
{
    return array_values(array_filter(pages_all(), fn($p) => (int)$p['visible'] === 1));
}

/* ---------- Menús de la web (Web → Menú) ---------- */

/** Elementos fijos que se pueden poner en un menú: clave => [nombre, dirección]. Las páginas son «p:<id>». */
const MENU_FIXED = ['home' => ['Inicio', '/'], 'catalog' => ['Catálogo', '/catalogo'], 'contact' => ['Contacto', '/contacto']];

/** Los dos menús: clave => nombre. Se guardan en los ajustes menu_top y menu_foot (JSON). */
const MENU_PLACES = ['top' => 'Menú de arriba', 'foot' => 'Pie de la web'];

/** Qué se puede poner en un menú ahora mismo: clave => [name, path, page (bool), visible (bool)]. */
function menu_available(): array
{
    $out = [];
    foreach (MENU_FIXED as $k => [$name, $path]) {
        $out[$k] = ['name' => $name, 'path' => $path, 'page' => false, 'visible' => true];
    }
    foreach (pages_all() as $p) {
        $out['p:' . $p['id']] = ['name' => (string)$p['title'], 'path' => '/' . $p['slug'], 'page' => true, 'visible' => (int)$p['visible'] === 1];
    }
    return $out;
}

/**
 * Configuración de un menú en su orden: [['k', 'on', 'label', 'name', 'path', 'page', 'visible'], …].
 * Sin ajustes guardados es el de siempre: arriba Inicio, Catálogo y Contacto; en el pie, las páginas.
 * Lo que falte (una página nueva) se añade al final con su valor por defecto; las páginas borradas se ignoran.
 */
function menu_config(string $place): array
{
    $avail = menu_available();
    $saved = json_decode(setting('menu_' . $place), true);
    $rows = [];
    foreach (is_array($saved) ? $saved : [] as $s) {
        $k = (string)($s['k'] ?? '');
        if (isset($avail[$k]) && !isset($rows[$k])) {
            $rows[$k] = ['k' => $k, 'on' => empty($s['on']) ? 0 : 1, 'label' => mb_substr(trim((string)($s['label'] ?? '')), 0, 40)];
        }
    }
    foreach ($avail as $k => $a) {
        $isPage = str_starts_with($k, 'p:');
        $rows[$k] ??= ['k' => $k, 'on' => $place === 'top' ? ($isPage ? 0 : 1) : ($isPage ? 1 : 0), 'label' => ''];
    }
    return array_values(array_map(fn($r) => $r + $avail[$r['k']], $rows));
}

/** Elementos que salen de verdad en un menú público: [['k', 'label', 'path', 'page'], …]. Las páginas ocultas nunca. */
function menu_items(string $place): array
{
    $out = [];
    foreach (menu_config($place) as $r) {
        if ($r['on'] && $r['visible']) {
            $out[] = ['k' => $r['k'], 'label' => $r['label'] !== '' ? $r['label'] : $r['name'], 'path' => $r['path'], 'page' => $r['page']];
        }
    }
    return $out;
}

/* ---------- Markdown limitado → HTML ---------- */

/** Dirección de enlace permitida, o null. */
function md_link_url(string $u): ?string
{
    $u = trim($u);
    if ($u === '' || strlen($u) > 500 || preg_match('/[\x00-\x20\x7f"\'<>`\\\\]|\p{Z}|\p{C}/u', $u)) {
        return null;
    }
    if (preg_match('#^/(?![/\\\\])#', $u)) { // de la propia web
        return url($u);
    }
    if (preg_match('/^#[\w\-]+$/', $u)) {
        return $u;
    }
    if (preg_match('#^https?://[\p{L}\p{N}][\p{L}\p{N}.\-]*(?::\d{1,5})?(?:[/?\#]\S*)?$#iu', $u)) {
        return $u;
    }
    if (preg_match('/^mailto:[^\s@]+@[^\s@]+\.[^\s@]+$/i', $u) || preg_match('/^tel:\+?[0-9().\-]{3,25}$/i', $u)) {
        return $u;
    }
    return null;
}

/** Foto de uploads/ permitida (devuelve su dirección pública) o null. */
function md_image_url(string $u): ?string
{
    if (!preg_match('#^/uploads/([a-f0-9]{16}\.(?:webp|png))$#', trim($u), $m) || !is_file(UPLOAD_DIR . '/' . $m[1])) {
        return null;
    }
    return url('/uploads/' . $m[1]);
}

/** Negrita y cursiva sobre texto YA escapado. Se aplican por trozos para que nunca queden etiquetas mal anidadas. */
function md_emphasis(string $t): string
{
    $t = preg_replace('/\*\*(?!\s)([^*\n]{1,400}?)(?<!\s)\*\*/', '<strong>$1</strong>', $t) ?? $t;
    $parts = preg_split('#(<strong>.*?</strong>)#s', $t, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$t];
    foreach ($parts as $i => $p) {
        $em = fn(string $x) => preg_replace('/(?<![*\w])\*(?![\s*])([^*\n]{1,400}?)(?<![\s*])\*(?![*\w])/', '<em>$1</em>', $x) ?? $x;
        if (str_starts_with($p, '<strong>')) {
            $parts[$i] = '<strong>' . $em(substr($p, 8, -9)) . '</strong>';
        } else {
            $parts[$i] = $em($p);
        }
    }
    return implode('', $parts);
}

/** Texto en línea: enlaces, imágenes, negrita y cursiva. Todo lo que no es una de esas marcas queda escapado. */
function md_inline(string $t): string
{
    $t = preg_replace('/[\x00-\x08\x0b-\x1f\x7f]/', '', $t) ?? ''; // fuera caracteres de control (también los marcadores internos)
    $safe = [];
    $hold = function (string $html) use (&$safe): string {
        $safe[] = $html;
        return "\x01" . (count($safe) - 1) . "\x02";
    };
    // Imágenes primero (![alt](/uploads/…)), después enlaces ([texto](dirección))
    $t = preg_replace_callback('/!\[([^\]\n]{0,200})\]\(([^\s()]{1,300})\)/u', function ($m) use ($hold) {
        $src = md_image_url($m[2]);
        return $hold($src ? '<img src="' . e($src) . '" alt="' . e($m[1]) . '" loading="lazy">' : e($m[0]));
    }, $t) ?? $t;
    $t = preg_replace_callback('/\[([^\]\n]{1,300})\]\(([^\s()]{1,500})\)/u', function ($m) use ($hold) {
        $href = md_link_url($m[2]);
        if ($href === null) {
            return $hold(e($m[0])); // dirección no permitida: se ve como texto, sin enlace
        }
        $ext = preg_match('#^https?://#i', $href) ? ' target="_blank" rel="noopener noreferrer"' : '';
        return $hold('<a href="' . e($href) . '"' . $ext . '>' . md_emphasis(e($m[1])) . '</a>');
    }, $t) ?? $t;
    $t = md_emphasis(e($t));
    return preg_replace_callback('/\x01(\d+)\x02/', fn($m) => $safe[(int)$m[1]] ?? '', $t) ?? '';
}

/** Markdown limitado → HTML seguro. */
function md_render(string $src): string
{
    $src = mb_substr(str_replace(["\r\n", "\r"], "\n", $src), 0, PAGE_BODY_MAX);
    $out = '';
    $para = [];
    $list = null; // ['ul'|'ol', [items]]
    $quote = [];
    $flush = function () use (&$out, &$para, &$list, &$quote): void {
        if ($para) {
            $out .= '<p>' . implode('<br>', array_map('md_inline', $para)) . "</p>\n";
            $para = [];
        }
        if ($list) {
            $out .= '<' . $list[0] . '>' . implode('', array_map(fn($i) => '<li>' . md_inline($i) . '</li>', $list[1])) . '</' . $list[0] . ">\n";
            $list = null;
        }
        if ($quote) {
            $out .= '<blockquote><p>' . implode('<br>', array_map('md_inline', $quote)) . "</p></blockquote>\n";
            $quote = [];
        }
    };
    foreach (explode("\n", $src) as $line) {
        $line = rtrim($line);
        if (trim($line) === '') {
            $flush();
        } elseif (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $m)) {
            $flush();
            $tag = strlen($m[1]) === 3 ? 'h3' : 'h2'; // el título de la página ya es el h1
            $out .= "<$tag>" . md_inline(trim($m[2])) . "</$tag>\n";
        } elseif (preg_match('/^\s{0,3}[-*]\s+(.+)$/u', $line, $m)) {
            if (!$list || $list[0] !== 'ul') { $flush(); $list = ['ul', []]; }
            $list[1][] = trim($m[1]);
        } elseif (preg_match('/^\s{0,3}\d{1,3}[.)]\s+(.+)$/u', $line, $m)) {
            if (!$list || $list[0] !== 'ol') { $flush(); $list = ['ol', []]; }
            $list[1][] = trim($m[1]);
        } elseif (preg_match('/^>\s?(.*)$/u', $line, $m)) {
            if (!$quote) { $flush(); }
            $quote[] = $m[1];
        } else {
            if ($list || $quote) { $flush(); }
            $para[] = trim($line);
        }
    }
    $flush();
    return $out;
}

/** Texto sin marcas de formato (para la descripción de Google). */
function md_plain(string $src, int $max = 155): string
{
    $t = preg_replace('/!\[[^\]\n]*\]\([^)\s]*\)/u', ' ', $src) ?? '';
    $t = preg_replace('/\[([^\]\n]+)\]\([^)\s]*\)/u', '$1', $t) ?? '';
    $t = preg_replace('/^\s*(?:#{1,3}|>|[-*]|\d{1,3}[.)])\s+/mu', '', $t) ?? '';
    $t = str_replace(['**', '*'], '', $t);
    $t = trim((string)preg_replace('/\s+/u', ' ', $t));
    return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
}

/** Fotos de uploads/ que usa una página (cabecera y las del texto). */
function page_files(array $page): array
{
    $files = [];
    if (!empty($page['hero'])) {
        $files[] = (string)$page['hero'];
    }
    if (preg_match_all('#/uploads/([a-f0-9]{16}\.(?:webp|png))#', (string)($page['body'] ?? ''), $m)) {
        $files = array_merge($files, $m[1]);
    }
    return array_values(array_unique($files));
}

/* ---------- Plantillas legales ---------- */

/** Plantillas con los datos de Empresa: clave => [nombre, título, dirección, texto]. Lo que falta queda como [completar]. */
function page_templates(): array
{
    $v = fn(string $k) => trim(setting($k)) !== '' ? trim(setting($k)) : '[completar]';
    $name = $v('company_name');
    $addr = str_replace("\n", ', ', $v('address'));
    $host = (string)parse_url(site_origin(), PHP_URL_HOST);
    $mail = $v('email');
    $warn = "> **" . PAGE_REVIEW_MARK . ":** este texto es una plantilla general generada con los datos de Empresa y **no es asesoramiento legal**. "
        . "Revísalo (idealmente con un profesional), completa lo que ponga [completar] y **borra este párrafo antes de publicar**.\n\n";

    $legal = $warn . "## Datos del titular\n\n"
        . "En cumplimiento de la Ley 34/2002 de Servicios de la Sociedad de la Información y de Comercio Electrónico (LSSI-CE), se informa de que este sitio web, **$host**, es titularidad de:\n\n"
        . "- **Titular:** $name\n- **CIF/NIF:** " . $v('tax_id') . "\n- **Domicilio:** $addr\n- **Correo electrónico:** $mail\n- **Teléfono:** " . $v('phone') . "\n\n"
        . "## Objeto\n\nEste sitio web ofrece información sobre la actividad, los productos y los servicios de $name. El acceso es libre y gratuito y supone la aceptación de estas condiciones.\n\n"
        . "## Propiedad intelectual\n\nLos contenidos del sitio (textos, fotografías, logotipos y diseño) son propiedad de $name o de terceros que han autorizado su uso. Queda prohibida su reproducción sin autorización expresa.\n\n"
        . "## Responsabilidad\n\n$name procura que la información sea correcta y esté actualizada, pero no garantiza la ausencia de errores ni se responsabiliza de los daños derivados del uso del sitio. Los precios y características de los productos son orientativos.\n\n"
        . "## Enlaces a otros sitios\n\nEste sitio puede enlazar a páginas de terceros. $name no se responsabiliza de sus contenidos ni de sus políticas.\n\n"
        . "## Legislación aplicable\n\nEstas condiciones se rigen por la legislación española. Para cualquier controversia, las partes se someterán a los juzgados y tribunales que correspondan conforme a la ley.\n";

    $priv = $warn . "## Responsable del tratamiento\n\n- **Responsable:** $name\n- **CIF/NIF:** " . $v('tax_id') . "\n- **Domicilio:** $addr\n- **Correo electrónico:** $mail\n\n"
        . "## Qué datos tratamos y para qué\n\nEste sitio web no tiene formularios ni recoge datos personales de forma automática. Si nos escribes por correo electrónico, WhatsApp o teléfono, trataremos los datos que nos facilites (nombre, contacto y contenido de tu consulta) **únicamente para atenderla**.\n\n"
        . "## Base jurídica\n\nEl tratamiento se basa en tu consentimiento al contactar con nosotros y, en su caso, en la ejecución de una relación comercial.\n\n"
        . "## Conservación y destinatarios\n\nConservaremos los datos mientras sea necesario para atender tu solicitud y durante los plazos legales. No los cedemos a terceros salvo obligación legal; pueden acceder a ellos proveedores que nos prestan servicios (alojamiento, correo) con las garantías exigidas.\n\n"
        . "## Tus derechos\n\nPuedes ejercer tus derechos de acceso, rectificación, supresión, oposición, limitación y portabilidad escribiendo a $mail. Si consideras que no hemos tratado tus datos correctamente, puedes presentar una reclamación ante la Agencia Española de Protección de Datos (www.aepd.es).\n";

    $cook = $warn . "## Qué son las cookies\n\nLas cookies son pequeños archivos que un sitio web guarda en tu dispositivo.\n\n"
        . "## Cookies de este sitio\n\nEste sitio web **no utiliza cookies propias de seguimiento, analítica ni publicidad**. Solo puede usar cookies técnicas estrictamente necesarias para su funcionamiento, que no requieren consentimiento.\n\n"
        . (feature('map') && trim(setting('address')) !== '' ? "## Servicios de terceros\n\nLa página de contacto incluye un mapa de **Google Maps**. Al cargarlo, Google puede instalar cookies propias según su política de privacidad (policies.google.com).\n\n" : '')
        . "## Cómo desactivarlas\n\nPuedes bloquear o eliminar las cookies desde la configuración de tu navegador. Contacto: $mail.\n";

    return [
        'aviso-legal' => ['Aviso legal', 'Aviso legal', 'aviso-legal', $legal],
        'privacidad' => ['Política de privacidad', 'Política de privacidad', 'politica-de-privacidad', $priv],
        'cookies' => ['Política de cookies', 'Política de cookies', 'politica-de-cookies', $cook],
    ];
}
