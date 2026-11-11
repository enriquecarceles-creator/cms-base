# Orígenes de importación

Cada archivo `.php` de esta carpeta es un origen que aparece solo en **Panel → Importar**.
Para traer artículos de una aplicación nueva, copia `hoja-calculo.php`, cámbiale el nombre y adapta la función de análisis. No hace falta tocar nada más.

## Qué devuelve el archivo

```php
return [
    'id'          => 'mi-erp',                    // único, sin espacios
    'label'       => 'Mi ERP (exportación XML)',  // título en el panel
    'order'       => 40,                          // posición en la lista (menor = antes)
    'recommended' => false,
    'help'        => 'Dónde se exporta el archivo en esa aplicación…',
    'template'    => null,                        // opcional: función que devuelve un CSV de ejemplo
    'fields'      => [                            // campos del formulario
        ['name' => 'archivo', 'type' => 'file', 'label' => 'Archivo', 'accept' => '.xml', 'required' => true],
        ['name' => 'zip', 'type' => 'file', 'label' => 'Opcional: ZIP con fotos', 'accept' => '.zip'],
        ['name' => 'url', 'type' => 'url', 'label' => 'Dirección', 'placeholder' => 'https://…'],
        // tipos: file, text, url, select (con 'options' => [valor => texto])
    ],
    'analyze'     => 'mierp_analyze',             // función que construye el plan
    // Opcional: paso intermedio (como en base-datos.php). Si analyze devuelve ['stage' => 'map', …],
    // se muestra map_form($trabajo) y al enviarlo se llama a map($trabajo, $_POST, $_FILES),
    // que devuelve otra vez el trabajo (stage map) o el plan.
    'map'         => null,
    'map_form'    => null,
];
```

Pon un prefijo propio a las funciones (`mierp_…`) para que no choquen con las de otros orígenes.

## La función de análisis

Recibe `$in` con:
- `$in['files']['archivo']`: ruta temporal del archivo subido (o `null`)
- `$in['names']['archivo']`: nombre original del archivo
- `$in['post']['url']`: texto de los campos

Devuelve el **plan** (todavía no se guarda nada en la base de datos):

```php
return [
    'origin'   => 'export.xml',          // se muestra en la pantalla de revisión
    'allowed'  => ['cdn.mi-erp.com'],    // servidores de los que se pueden descargar fotos
    'items'    => [ import_plan_item(...), ... ],
    'warnings' => ['Avisos para el usuario'],
];
```

Cada artículo se construye con `import_plan_item($existente, $nombre, $ruta, $ref, $medidas, $descripcion, $fotos, $visible, $campos)`:
- `$campos`: valores de los campos del catálogo, `[id de campo => valor]` (ver `fields_all()` y `field_for_column($tituloColumna)`).
- `$existente`: `import_existing_find(import_existing_index(), $nombre, $ref)` (busca por referencia y por nombre)
- `$ruta`: `['Familia', 'Subfamilia']` (se crean si no existen; máximo 2 niveles)
- `$fotos`: lista de `['key' => 'id-unico', 'urls' => ['https://…']]` o, para fotos de un ZIP,
  `['key' => 'zip:nombre.jpg', 'file' => $mapa['nombre.jpg']]` usando `import_extract_zip($rutaZip)`.

## Utilidades disponibles (app/import.php)

| Función | Para qué |
|---|---|
| `import_read_csv($ruta)` | Lee un CSV de Excel/Sheets/WordPress (separador y codificación automáticos) |
| `import_map_columns($cabecera, ['name' => ['nombre','name'], …])` | Localiza columnas por su título |
| `import_extract_zip($ruta)` / `import_zip_input($subido)` | Descomprime fotos de un ZIP (subido o en `data/importar/`) |
| `import_dims($texto)` | Saca «80 x 45 cm» de un texto |
| `import_csv_clean_text($html)` | Quita HTML y espacios sobrantes |
| `import_bool($texto)` | «sí / publicado / active» → 1, «no / oculto / draft» → 0 |
| `import_http($url, $permitidos)` | Descarga segura (solo servidores permitidos, nunca redes privadas) |

La importación real (crear artículos y familias, descargar y optimizar fotos por tandas, no duplicar) la hace el núcleo; el origen solo tiene que leer y describir.

## Bases de datos de Access

`app/mdb.php` (clase `MdbReader`) lee archivos `.mdb` y `.accdb` en PHP puro: `tables()`, `columns($pagina)`, `rowsByPage($pagina)`.
Para un programa de gestión concreto normalmente basta con el origen «Base de datos» y emparejar columnas; si se repite mucho, se puede crear un origen propio que fije el emparejamiento.

## Perfiles de importación (app/profiles.php)

El origen «Base de datos» guarda y aplica perfiles (tabla `import_profiles`). Para añadir un perfil de sistema para otro programa de gestión, añádelo a `IMPORT_SYSTEM_PROFILES` con:
- `mapping`: las mismas claves que el paso de columnas (`table`, `name`, `ref`, `family`, `fam_table`…) y `fields` => [slug del campo => columna].
- `rules`: `require_tables` (para proponerlo solo cuando encaja), `exclude_columns` (columnas que no se leen nunca; `PREFIJO*` vale para todas las que empiezan así) y `fields` (campos que se crean si faltan: slug => [nombre, tipo, unidad, público]).
