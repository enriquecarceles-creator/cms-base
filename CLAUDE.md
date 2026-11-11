# CMS base (catálogo multiempresa, camino hacia ERP)

Web de catálogo + panel de administración para adaptar a cualquier empresa (logotipo, datos, catálogo) sin tocar código. Escribe y responde en español; el usuario no es programador: explica en lenguaje sencillo.

> Este archivo distingue lo que **ya existe** (secciones 1–6) de lo que es **objetivo** (secciones 7–8). No des por hecho que existe algo de la hoja de ruta: compruébalo en el código.

## 1. Tecnología
- PHP 8.1+, sin frameworks, sin Composer, casi sin JavaScript.
- Base de datos a elegir en el instalador: SQLite (`data/site.sqlite`, por defecto), MariaDB/MySQL o SQL Server. Configuración en `data/db.php`.
- Hosting compartido (IONOS, Apache + `.htaccess`). Todo debe funcionar sin extensiones raras ni programas externos (nada de `exec`).
- Requisitos: `pdo_sqlite`, `mbstring`, `gd`; opcionales `zip`, `curl`.

## 2. Estructura (lo que existe)
- `index.php` — router de la web pública y del panel (`/admin/...`). En el hosting se llega a él por `.htaccess` (vale en la raíz o en subcarpeta sin cambiar nada).
- `router.php` — **solo para probar en local** con `php -S` (imita el `.htaccess`; en el hosting no hace nada).
- `instalar.php` — asistente de 6 pasos: servidor → base de datos → web nueva o restaurar copia → empresa (datos, logotipo, color, país) → opciones (módulos, campos del catálogo, prefijo, zona horaria, noindex) → administrador. Se borra solo al terminar.
- `app/bootstrap.php` — configuración común, `db()`, `setting()`, `feature()`, `term()` / `tg()` (nombres del catálogo y su género), `tel_href()`, `url()`, `COUNTRIES`, `FEATURES` (con `features_help()`), imágenes, **sesión, CSRF y acceso** (ver sección 4).
- `app/db.php` — conexión a los tres motores. `CmsPdo` traduce al vuelo el SQL escrito «al estilo SQLite» (`db_sql()`): `||` → CONCAT, `LIMIT n` → TOP en SQL Server, quita `COLLATE NOCASE`, cita `key`/`file`. También `db_put_setting()`, `db_tables()`, `db_columns()`, `db_copy_all()`, `db_export_sqlite()`.
- `app/schema.php` — esquema único con marcadores que `db_ddl()` convierte a cada motor, y migraciones (`SCHEMA_VERSION`, ahora 15). Marcadores: `{ID}` autonumérico · `{S}` texto corto (255) · `{K}` texto con índice único (VARCHAR(191) en MariaDB) · `{T}` texto largo · `{I}` entero · `{BIG}` entero grande · `{Q:nombre}` nombre reservado.
- `app/fields.php` — campos libres del catálogo (tablas `custom_fields` y `product_fields`); panel en Web → Campos.
- `app/admin.php` — controladores del panel (incluidos login, usuarios y bloqueo de intentos). `app/pages.php` — páginas públicas.
- **Panel** (`app/views/admin/layout.php` + `assets/admin.css`, con variables de color en `:root`): cabecera (marca, buscador rápido → `/admin/articulos?q=`, «Ver web», menú de usuario con `<details>`), menú lateral `admin_menu()` (respeta roles y módulos; nombres con `term()`), iconos SVG en línea `admin_icon()`, migas de pan (las pantallas pueden pasar `crumb`). En el móvil el menú se pliega con una casilla + `<label>` (sin JavaScript).
- **Direcciones del panel:** `/admin` = Inicio (`admin_home()`: cifras, avisos, accesos rápidos, últimos 10 modificados); la lista de artículos es `/admin/articulos`. `/admin?c=…`, `?cat=`, `?q=`, `?visible=`, `?foto=`, `?precio=` (direcciones antiguas) redirigen con **302** a `/admin/articulos` con esos filtros.
- Importar: `/admin/importar` (todos los orígenes) y `/admin/importar/carpetas` («Fotos por carpeta», con su entrada en el menú; `IMPORT_OWN_PAGES`). El trabajo en curso es el mismo en las dos.
- **Lista de artículos** (`admin_products()`): tabla paginada de 50 (`ADMIN_PER_PAGE`) con dos consultas por página (recuento + los 50 con familia, precio y stock por `LEFT JOIN`); orden por columna con lista cerrada (`ADMIN_SORTS`, `admin_list_order()`): precio y stock como número con `CAST(… AS DECIMAL(14,4))` solo si el campo es de tipo precio/número (SQL Server falla al convertir texto), vacíos al final. Va en ~50-100 ms con 6.868 artículos.
- **Acciones en bloque** (`admin_products_bulk()`, POST `action=bulk` con CSRF): ocultar, mostrar, mover a familia, borrar; sobre los marcados o sobre «todos los del filtro». «Todos los del filtro» y borrar siempre pasan por `bulk_confirm` con el número de artículos (y `expected`, por si cambia); borrar más de `ADMIN_BULK_TYPE_OVER` (50) pide escribir el número; se recomienda descargar copia. Al terminar vuelve a la lista con los mismos filtros, orden y página.
- **Ficha de artículo** (`product_form.php`): secciones General, Características y Fotos (sin JavaScript se ven seguidas; con él son pestañas, `#general`, `#caracteristicas`, `#fotos`, y si un campo obligatorio está en otra pestaña se abre esa). Barra de guardar `sticky` al final del formulario (orden lógico del tabulador; `scroll-padding-bottom` + corrección por JavaScript para que no tape el campo con el foco). Aviso de cambios sin guardar comparando el estado del formulario (no avisa al guardar, ni si se deshace el cambio, ni si se cancela «Eliminar»). Si el guardado falla con fotos elegidas, avisa de que hay que volver a elegirlas (`lostFiles`).
- **Pruebas en navegador real:** con Node.js y Chrome sin ventana (protocolo DevTools, sin librerías) se pueden probar avisos, teclado y capturas; los guiones de prueba no se guardan en el proyecto.
- **Borrar artículos:** siempre con `delete_products()` (bootstrap): borra filas en una transacción y después solo los archivos de `uploads/` que ya no usa nadie (`upload_in_use()`: otros artículos, portada/banner de familias, slider, ajustes).
- **Filtros de la lista:** `admin_list_query()` (solo `c`, `q`, `visible`, `foto`, `precio`, `orden`, `dir`, `pag`), `admin_list_url()`, `admin_list_where()`. Para volver a la lista con los mismos filtros, los enlaces de edición llevan `?back=<filtros>` y los formularios un campo oculto `back` (`admin_back()` lo limpia).
- Ajustes que guarda el panel: `last_import` (JSON al terminar cualquier importación), `last_backup_at` (al descargar la copia), `broken_photos` (recuento de fotos rotas guardado 10 minutos).
- `app/views/` (públicas) y `app/views/admin/` (panel). Escapar siempre con `e()`.
- `app/import.php` — núcleo del importador (plan → revisión → importación por tandas con transacciones). También aplica el modo «actualizar» (ítems con `update`: sobrescribe solo los campos elegidos).
- `app/profiles.php` — perfiles de importación (tabla `import_profiles`): perfiles de sistema en `IMPORT_SYSTEM_PROFILES` (se copian solos a la tabla), perfiles propios, columnas prohibidas (`exclude_columns`, admite `PREFIJO*`) y creación de los campos que pida un perfil (por nombre, sin duplicar).
- `app/cms_pages.php` — páginas propias: `md_render()` / `md_plain()` (Markdown limitado seguro), `page_slug_error()`, `page_templates()`, `pages_visible()`. Controlador en `admin_pages()` (admin.php) y público en `page_custom()` (pages.php, ruta `/<slug>` en index.php).
- `app/photofolders.php` — fotos por carpeta de artículo (`ARTICULO_<CODIGO>/<CODIGO>_2.jpg…`): busca el origen (ZIP subido, o ZIP/carpeta en `data/importar/`), ordena por número (GIF al final, y solo si se piden), empareja con la referencia sin mayúsculas ni signos (`pf_norm`: solo letras y números; primero la exacta; si hay varias, no se asigna).
- `app/importers/*.php` — un archivo por origen; aparecen solos en el panel. Guía: `app/importers/LEEME.md`.
  - `woocommerce-csv.php`, `hoja-calculo.php`, `base-datos.php` (Access/SQL/SQLite con paso de emparejar columnas, «Usar perfil» / «Guardar como perfil», modo «Actualizar precios y stock» y casilla «Fotos por carpeta de artículo» si la columna de foto está vacía), `fotos-carpetas.php` (fotos por carpeta para artículos que ya existen: solo a los que no tienen fotos salvo «Sustituir», que quita las que no vienen de la carpeta; clave `carpeta:<CARPETA>/<archivo>` en `product_images.src` para no duplicar), `wordpress-web.php`.
- `app/mdb.php` — lector de Access (.mdb Jet3/Jet4, .accdb) en PHP puro (`MdbReader`).
- `app/backup.php` — restaurar copias de seguridad (ZIP con `data/site.sqlite` + `uploads/`; en MariaDB/SQL Server se copian los datos con `db_copy_all()`).
- `assets/style.css` (web), `assets/admin.css` (panel). `uploads/` fotos (WebP). `data/` nunca accesible desde el navegador.

## 3. Reglas de SQL (para que funcione en los tres motores)
- Toda consulta pasa por `db()` (CmsPdo). Escribe SQL estándar «al estilo SQLite»; `db_sql()` lo adapta.
- Nada de `INSERT OR …`, `ON CONFLICT`, `PRAGMA`, `GROUP_CONCAT`, `IFNULL`, `IS ?`, `ENUM`, comillas dobles para textos, ni comparaciones dentro de `ORDER BY` (usar `CASE WHEN … THEN 0 ELSE 1 END`). Booleanos como `1`/`0` en columnas `{I}`.
- Tablas nuevas: añadirlas al esquema con marcadores, a `DB_TABLES` (orden de dependencias, para copias y restauraciones) y con una migración portable (crear si no está en `db_tables()`).
- Columnas con índice único: `{K}` (no `{S}`), por el límite de MariaDB con utf8mb4.
- Ajustes: `save_setting()` / `db_put_setting()`. Columnas: `db_columns()`. Tablas: `db_tables()`.
- Nombres de columna: evitar palabras reservadas (`public`, `order`, `option`, `user`, `key`, `file`…).
- Para revisar las consultas: arrancar con `CMS_SQL_LOG=/ruta/sql.log php -S …` y traducirlas con `db_sql($sql, 'mysql')` / `db_sql($sql, 'sqlsrv')`.
- MariaDB y SQL Server están **sin probar en un servidor real**: si falla algo, el arreglo suele ir en `db_sql()` o `db_ddl()`.

## 4. Seguridad (lo que existe hoy)
- **Usuarios** (tabla `users`): `id`, `username` (único), `pass_hash` (`password_hash(…, PASSWORD_DEFAULT)`), `role`, `email`. Roles (`USER_ROLES` en `app/admin.php`): `admin` (todo) y `catalogo` (artículos, importar y su cuenta). Siempre queda al menos un admin. El instalador crea el primero como `admin`.
- **Sesión** (`start_session()`): cookie `HttpOnly`, `SameSite=Lax`, `Secure` con HTTPS, ruta limitada a la carpeta de la web; `session_regenerate_id(true)` al entrar.
- **CSRF**: `csrf_field()` en cada formulario y `csrf_check()` en cada POST (token en `$_SESSION['csrf']`).
- **Acceso**: `require_login()` e `is_admin()` en `app/bootstrap.php`; el router del panel exige sesión en todo `/admin` salvo login y recuperar contraseña, y rol admin en las páginas de Web y Usuarios.
- **Fuerza bruta**: tabla `login_attempts` por IP: 8 intentos fallidos → bloqueo de 15 minutos.
- **Recuperar contraseña**: enlace de un solo uso (1 hora) por correo (`password_resets`).
- **Datos internos**: los campos marcados como internos no salen en la ficha pública, el buscador ni el catálogo.

## 5. Configuración por empresa (sin código)
- Web → Empresa: identidad (nombre, logotipo, color, banner del pie), contacto, redes y copia de seguridad.
- Web → Página de inicio (`/admin/pagina-inicio`): bloques de la portada (slider, imagen de cabecera, bienvenida, quiénes somos, tarjetas, familias destacadas, novedades, llamada final), cada uno activable y ordenable con número o flechas (botones de formulario, sin JavaScript). Ajuste `home_blocks` (JSON); si no existe se calcula de los ajustes antiguos (`home_blocks()` en bootstrap) para que la portada se vea igual. Imagen de cabecera + bienvenida seguidas = texto sobre la imagen; quiénes somos + tarjetas seguidas = lado a lado; el slider (`mod_slider` se mantiene sincronizado con el bloque) puede sustituir a cabecera y bienvenida. La gestión del slider (`/admin/slider`) cuelga de esta página.
- Web → Páginas (`/admin/paginas`, `/nueva`, `/<id>`; solo admin): páginas propias en `/<dirección>` (tabla `pages`, SCHEMA_VERSION 16). Ocultas = 404 para visitantes y borrador (con `noindex`) para quien tiene sesión. Las visibles salen en el pie (`pages_visible()`, provisional hasta el menú de la entrega 3) y en `/sitemap.xml`. Direcciones reservadas en `PAGE_RESERVED` + cualquier archivo/carpeta de la raíz (`page_slug_error()`); el slug se limpia con `slugify()`. Al crear la primera se ofrecen las plantillas legales (`page_templates()`: Aviso legal, Privacidad, Cookies), que se crean **ocultas**, rellenas con los datos de Empresa y con el aviso `BORRADOR SIN REVISAR` (la lista y el editor avisan mientras siga en el texto). Borrar pide una pantalla de confirmación (sin JavaScript) y solo borra de `uploads/` las fotos que nadie más usa (`upload_in_use()` mira también `pages`).
- **Texto de las páginas = Markdown limitado**, nunca HTML (`md_render()` en `app/cms_pages.php`): el HTML lo genera la propia web con etiquetas cerradas (p, h2, h3, ul, ol, li, blockquote, strong, em, br, a, img); todo lo demás se escapa con `e()`. Enlaces solo `http(s)://`, `mailto:`, `tel:`, `#ancla` y `/…` de la propia web; imágenes solo de `uploads/`. Si se amplía el formato, repetir la batería de ataques (script, onerror, javascript:, data:, //, comillas, entidades, anidados, entradas enormes) validando la ESTRUCTURA del HTML producido, no buscando texto.
- Web → Página del catálogo (`/admin/pagina-catalogo`): `catalog_title`, `catalog_intro`, `catalog_per_page` (0 = todos), `catalog_order` (`CATALOG_ORDERS`), `catalog_show_ref`, `catalog_show_price` (`catalog_opts()`). Aquí irá la activación de los filtros.
- Web → Configuración: módulos activables (`mod_reps`, `mod_pdf`, `mod_sketch`, `mod_lineup`, `mod_map`), prefijo telefónico, zona horaria, `noindex`, textos fijos (solo el título de Representantes; los de portada y catálogo están en sus páginas) y **nombres del catálogo** (`term_family`, `term_families`, `term_sub`, `term_subs`, `term_gender` f/m; por defecto Familia/Familias/Subfamilia/Subfamilias, femenino).
- Textos visibles con «familia»: siempre con `term('family'|'families'|'sub'|'subs', minúscula)` y las palabras que concuerdan con `tg('una', 'un')`, `tg('de la', 'del')`… (web, panel, importadores, PDF y mensajes). Las direcciones (`/catalogo/…`, `/admin/categorias`) y los nombres internos (`categories`, `family`, alias de columnas del importador) no cambian. Con los valores por defecto la web queda idéntica a como era. Detrás de `<?= … ?>` PHP se come el salto de línea: si importa, añade `. "
"`.
- Web → Campos: campos libres (texto, texto largo, número, precio, lista, lista múltiple, sí/no, enlace), públicos o internos.
- Nada específico de una empresa en el código: todo va a `settings` o a los campos.

## 6. Probar, instalar y caso real
**Local:**
```bash
php -d upload_max_filesize=64M -d post_max_size=70M -S 127.0.0.1:8099 router.php
```
Antes de dar algo por terminado: `php -l` de cada archivo tocado y probar el flujo en el navegador.

**Hosting:** subir el contenido (con `.htaccess` y `.user.ini`) directamente en la carpeta, no dentro de otra carpeta `cms-base/`. Al actualizar: no tocar `data/` ni `uploads/`, no subir `instalar.php`.

**NCS Facturación — `FALMACEN.MDB` (Access 97), catálogo de calzado:**
- `TFAM` (42 familias: `CODIGO`, `FAMILIA`). Regla: no juntar familias parecidas, solo plurales («Zapatillas» ≠ «Zapatillas señora»).
- `TART` (6.872 artículos): referencia `CODIGO`, nombre `DESCRIPCION`, familia `FAMILIA` → `TFAM`, visible `BLOQUEADO` invertido (`0` = visible), foto `FOTO` (ruta Windows; se empareja por nombre con un ZIP de `\NCSFACT.NET\IMAGENES`). Si `FOTO` está vacía, el perfil usa las carpetas `FOTOS_ARTICULOS_001/ARTICULO_<CODIGO>` (casilla «Fotos por carpeta»).
- Fotos por carpeta (`pruebas/FOTOS_ARTICULOS_001`, 205 carpetas): contra el MDB, 126 exactas + 23 sin signos = 149 emparejadas y 56 sin artículo (en la web salen 148/57: `CUTN8042` no tiene familia y no se importa; `ARTICULO_ZZZT` está vacía). Algunos códigos llevan `/` (`CASN013/22` ↔ `ARTICULO_CASN01322`) y los archivos a veces llevan otro código que la carpeta: manda la carpeta. Los GIF se guardan como imagen fija.
- Campos: Precio ← `PVP2` (confirmar con la empresa cuál es su tarifa pública), Tallas ← `T00`, Color ← `C00`, Stock ← `SACTUAL` (interno).
- **`AMPLIACIOND` tiene notas internas con precios: nunca importarla ni publicarla.** Sus traducciones (`AMPLIACIONDING`, `…FRA`, `…ALE`, `…ITA`, `…DIA`) tampoco. El perfil las prohíbe con `AMPLIACIOND*`: se quitan de la lista de columnas al analizar y nunca se leen (las consultas piden solo las columnas elegidas).
- Este emparejamiento es el perfil de sistema «NCS Facturación — Almacén (FALMACEN.MDB)», que se aplica solo si la base de datos tiene `TART` y `TFAM` y crea los campos Precio, Tallas, Color y Stock (interno) si faltan.
- Para pruebas con los datos reales: `pruebas/` (`FALMACEN.MDB`, `FCUENTAS.MDB`, `FHISTORI.MDB`, `FOTOS_ARTICULOS_001/`; fuera de git: datos de la empresa).

## 7. Seguridad: mejoras objetivo (NO hechas todavía)
- Ampliar `users` con `full_name`, `is_active`, `last_login_at`, `failed_attempts`, `locked_until`, `created_at`, `updated_at` y `email` único. **Mantener la columna `pass_hash`** (no renombrarla a `password_hash`: rompería las webs instaladas) y hacerlo con migración portable.
- Bloqueo también por usuario (5 intentos → `locked_until`), además del bloqueo por IP que ya existe.
- Rol nuevo `erp_operator` cuando exista el módulo ERP; separar los helpers de acceso en `app/auth.php` (`require_auth()`, `require_role()`) manteniendo `require_login()` / `is_admin()` como alias.
- Endpoints AJAX o JSON futuros: misma validación de sesión y CSRF antes de ejecutar nada.
- Ficha pública: si hay un admin con sesión iniciada, mostrar también los campos internos con la insignia «Solo visible para administradores». Nunca a visitantes ni en el PDF.
- Aviso por correo al usuario cuando entra desde una IP o un navegador nuevos.
- Dirección del panel configurable en Web → Configuración (por defecto `/admin`), cambiando todos los enlaces internos (hoy `/admin` está escrito en muchos sitios: centralizarlo). Al cambiarla, avisar claramente de la nueva dirección y dejar una forma de recuperarla por FTP (un ajuste en `data/`).
- **Tarea aparte, antes de la Fase C:** doble verificación opcional por usuario con código TOTP (Google Authenticator), sin librerías externas, con códigos de recuperación.

## 8. Hoja de ruta
**Fase A — Perfiles de importación** (hecha: ver `app/profiles.php`; solo para el catálogo y el origen «Base de datos»)
- Tabla `import_profiles`: `id`, `name`, `source_type` (`mdb`, `sql`, `csv`), `target_module` (`catalog`, `customers`, `sales`), `mapping_json`, `rules_json`, `is_system`, `created_at`.
- Perfil de sistema «NCS Facturación — Almacén (FALMACEN.MDB)» con el emparejamiento de la sección 6 (inversión de `BLOQUEADO`, exclusión de `AMPLIACIOND`, `T00`, `C00`, `PVP2`, `SACTUAL`).
- Guardar perfiles propios y **actualización incremental**: modo «Actualizar precios y stock» en el paso de columnas. Busca solo por referencia; cambia los campos marcados (y la visibilidad si se marca), aunque tengan valor; vacío en el origen = no tocar; precio 0 = vacío; stock 0 sí se actualiza. Resumen previo con cuántos cambian, cuántos códigos no están en la web y cuántos artículos de la web no están en el origen (primeros 20 de cada grupo). Nunca crea ni borra artículos.
- Pendiente: perfiles para el origen CSV y para los módulos `customers` / `sales` (Fase C).

**Sección Web dividida** (antes de los filtros del catálogo), en entregas. La 1 está hecha (ver sección 5); quedan la 2 y la 3:
1. ~~Empresa (identidad, contacto, redes, copia) / Página de inicio (bloques: cabecera, bienvenida, «Quiénes somos», tarjetas, slider —su gestión se mueve aquí—, familias destacadas, novedades, llamada final; cada bloque activable y ordenable sin JavaScript obligatorio) / Catálogo (título y texto, artículos por página, orden por defecto, mostrar referencia y precio en tarjetas; aquí irá la activación de filtros). La web debe verse igual tras la migración.~~
2. ~~Páginas propias: tabla `pages` (título, slug `{K}` único, contenido, imagen de cabecera, título y descripción SEO, visible, orden, fechas; migración portable y en `DB_TABLES`); dirección `/<slug>` sin chocar con rutas existentes; editor sencillo guardado en formato seguro (Markdown limitado o HTML saneado con lista blanca; nunca HTML libre); plantillas «Aviso legal», «Política de privacidad», «Política de cookies» rellenas con datos de Empresa y aviso de revisarlas; en el sitemap.~~ (hecho: ver sección 5)
3. Menú (Web → Menú): elegir y ordenar qué sale arriba y en el pie (Inicio, Catálogo, Contacto, cada página) con nombre opcional.

**Fase B — Catálogo avanzado**
- ~~Nombres de la jerarquía configurables~~ (hecho: ver sección 5).
- Filtros por campos públicos (Tallas, Color…) en el catálogo.
- Campos públicos en el catálogo PDF (`/catalogo/completo`), nunca los internos.

**Fase C — ERP (clientes y ventas de NCS)**, en tablas y módulos aparte, sin ensuciar el catálogo:
- `FCUENTAS.MDB` (clientes y proveedores): `CODIGO`, `NOMBRE`, `NIF`, `NCOMERCIAL`, `DOMICILIO`, `POBLACION`, `CODPOSTAL`, `PROVINCIA`, `TELEFONO1`, `EMAIL`, `TARIFA`, `FPAGO`, `BLOQUEADA` (invertido).
- `FHISTORI.MDB` (facturas): cabeceras (`FACTURA`, `CLIENTE`, `FECHA`, `SUBTOTAL`, `BASE0..9`, `CUOTA0..9`, `TOTAL`, `ANULADA`, `OPER`) y líneas (`CLAVE`, `FACTURA`, `LINEA`, `CODIGO`, `TITULOL`, `UNIDADES`, `PRECIO`, `DCTO`, `IMPORTE`, `TIVA`, `TALLA`, `COLOR`).
- Los `.mdf` (archivos de SQL Server) **no se pueden leer directamente**: hay que exportarlos a `.sql` (o restaurarlos en un SQL Server y conectar) e importar desde ahí.
- Clientes y facturas son datos personales: solo en el panel, con acceso por rol, y fuera de las copias que se compartan.

**Otros pendientes**
- Probar MariaDB en IONOS (y SQL Server si algún cliente lo necesita).
- El instalador queda abierto hasta que alguien lo completa: instalar nada más subir.
