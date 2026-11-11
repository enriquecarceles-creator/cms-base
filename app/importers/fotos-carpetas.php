<?php
declare(strict_types=1);

/*
 * Origen: fotos por carpeta de artículo (NCS Facturación y programas parecidos).
 *   ARTICULO_<CODIGO>/<CODIGO>_2.jpg, _3.jpg… (+ <CODIGO>_GIF.gif)
 * Empareja cada carpeta con un artículo que ya existe en la web por su referencia
 * (sin distinguir mayúsculas ni signos) y añade sus fotos en orden. Ver app/photofolders.php.
 * No crea artículos ni toca textos.
 */

function pfimp_analyze(array $in): array
{
    $upload = $in['files']['zip'] ?? null;
    $src = pf_find_source($upload);
    if (!$src) {
        throw new RuntimeException($upload
            ? 'Ese ZIP no tiene carpetas ARTICULO_<código>.'
            : 'No encuentro fotos por carpeta. Sube un ZIP con carpetas ARTICULO_<código>, o déjalo (el ZIP o la carpeta) por FTP en data/importar/.');
    }
    $folders = pf_scan($src);
    if (!$folders) {
        throw new RuntimeException('No hay fotos dentro de las carpetas ARTICULO_ de ' . $src['label'] . '.');
    }
    $gifs = ($in['post']['gifs'] ?? '') === '1';
    $replace = ($in['post']['replace'] ?? '') === '1';

    // Artículos de la web con referencia (una sola consulta)
    $rows = db()->query('SELECT p.id, p.category_id, p.ref, p.name, p.dimensions, p.description,
        (SELECT COUNT(*) FROM product_images i WHERE i.product_id = p.id) AS n_img FROM products p')->fetchAll();
    $idx = pf_index(array_map(fn($p) => [$p['ref'], $p], $rows));
    // Fotos actuales de cada artículo (para «Sustituir»: qué se quita y qué ya está)
    $cur = [];
    if ($replace) {
        foreach (db()->query('SELECT product_id, src FROM product_images')->fetchAll() as $r) {
            $cur[(int)$r['product_id']][] = (string)$r['src'];
        }
    }

    import_files_clear();
    $zip = pf_open($src);
    $items = [];
    $n = ['exact' => 0, 'loose' => 0, 'had' => 0, 'empty' => 0, 'same' => 0];
    $none = $amb = $had = [];
    $photos = $foreign = $withForeign = 0;
    foreach ($folders as $f) {
        [$p, $how] = pf_lookup($idx, $f['code']);
        if ($how === 'none') {
            $none[] = $f['code'];
            continue;
        }
        if ($how === 'ambiguous') {
            $amb[] = $f['code'];
            continue;
        }
        $n[$how]++;
        if (!$f['files']) {
            $n['empty']++; // carpeta vacía
            continue;
        }
        if ((int)$p['n_img'] > 0 && !$replace) {
            $n['had']++;
            $had[] = $p['ref'];
            continue;
        }
        $images = pf_images($f, $gifs, $zip);
        if (!$images) {
            $n['empty']++;
            continue;
        }
        $keys = array_column($images, 'key');
        $old = $cur[(int)$p['id']] ?? [];
        $gone = count(array_diff($old, $keys));
        $new = count(array_diff($keys, $old));
        if ($replace && !$gone && !$new) {
            $n['same']++; // ya tiene exactamente estas fotos
            continue;
        }
        $photos += $replace ? $new : count($images);
        $foreign += $gone;
        $withForeign += $gone ? 1 : 0;
        $item = import_plan_item($p, '', [], '', '', '', $images);
        $item['replace'] = $replace;
        $item['foreign'] = $gone;
        $item['folder'] = $f['folder'];
        $items[] = $item;
    }
    $zip?->close();

    $summary = [
        ['carpetas emparejadas con la referencia exacta', $n['exact']],
        ['carpetas emparejadas sin contar guiones, espacios ni barras (BUBN3633L = BUBN3633-L)', $n['loose']],
        ['carpetas sin artículo en la web (no se importan)', count($none), (bool)$none],
    ];
    if ($amb) {
        $summary[] = ['carpetas que encajan con varios artículos a la vez (no se importan)', count($amb), true];
    }
    if ($replace) {
        $summary[] = ['artículos ya tienen exactamente estas fotos (no cambian)', $n['same']];
        $summary[] = ['artículos tienen otras fotos que se quitarán (Sustituir)', $withForeign, $withForeign > 0];
        $summary[] = ['fotos que se quitarán', $foreign, $foreign > 0];
    } else {
        $summary[] = ['artículos ya tenían fotos (se saltan; marca «Sustituir» para cambiarlas)', $n['had']];
    }
    if ($n['empty']) {
        $summary[] = ['carpetas emparejadas sin fotos que importar (vacías' . ($gifs ? '' : ' o solo con GIF') . ')', $n['empty']];
    }
    $summary[] = ['fotos nuevas que se van a importar' . ($gifs ? ' (con GIF)' : ' (sin los GIF)'), $photos];
    $lists = [];
    if ($none) {
        $lists['Carpetas sin artículo'] = $none;
    }
    if ($amb) {
        $lists['Carpetas que encajan con varios artículos'] = $amb;
    }
    if ($had && !$replace) {
        $lists['Artículos que ya tenían fotos'] = $had;
    }
    return [
        'origin' => $src['label'],
        'items' => $items,
        'summary' => $summary,
        'lists' => $lists,
        'no_append' => true,
        'warnings' => [],
    ];
}

return [
    'id' => 'fotos-carpetas',
    'label' => 'Fotos por carpeta de artículo (NCS y parecidos)',
    'order' => 30,
    'help' => 'Para fotos organizadas en carpetas ARTICULO_<código> (por ejemplo FOTOS_ARTICULOS_001 de NCS Facturación): cada carpeta se empareja con el artículo de la web que tiene esa referencia (sin distinguir mayúsculas, guiones, espacios ni barras) y sus fotos se añaden en orden (_2, _3…; la primera es la principal). Solo se añaden a los artículos que no tienen fotos, salvo que marques «Sustituir». Se puede repetir sin duplicar. Si pesa mucho, deja el ZIP o la carpeta por FTP en data/importar/ y pulsa Analizar sin elegir archivo.',
    'fields' => [
        ['name' => 'zip', 'type' => 'file', 'label' => 'ZIP con las carpetas de fotos (opcional)', 'accept' => '.zip,application/zip'],
        ['name' => 'gifs', 'type' => 'checkbox', 'label' => 'Incluir también los GIF (_GIF). Se guardan como imagen fija, sin animación.'],
        ['name' => 'replace', 'type' => 'checkbox', 'label' => 'Sustituir las fotos de los artículos que ya tienen fotos (se borran las que no vengan en estas carpetas)'],
    ],
    'analyze' => 'pfimp_analyze',
];
