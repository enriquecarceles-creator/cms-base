# CMS de catálogo con panel de administración

Web de catálogo rápida (sin WordPress ni plugins) con un panel para crear, editar, ocultar y borrar artículos y fotos.
Es la misma base para cualquier empresa: el nombre, el logotipo, el color, los datos de contacto, los textos y el catálogo se configuran desde el instalador y el panel, sin tocar código.

## Requisitos del hosting

- PHP 8.1 o superior con `pdo_sqlite`, `mbstring` y `gd` (con WebP). Recomendables: `zip` (copias e importar fotos en ZIP) y `curl` (importar desde otra web).
- Apache con `mod_rewrite` (casi todos). En Nginx hay que añadir la regla equivalente (todo lo que no sea un archivo real → `index.php`) y bloquear `/data/` y `/app/`.
- Las carpetas `data/` y `uploads/` deben poder escribirse (permisos 755 o 775).

## Instalar para una empresa nueva

1. **Pruébala antes en un subdominio** (por ejemplo `nueva.empresa.com`).
2. Sube **todo el contenido** de esta carpeta, incluidos los archivos que empiezan por punto (`.htaccess`, `.user.ini`).
3. Abre la web en el navegador: se abre solo el **asistente de instalación** (o ve a `instalar.php`, por ejemplo `https://nueva.empresa.com/instalar.php` o `https://empresa.com/cms/instalar.php`).
4. Sigue los 6 pasos:
   1. **Servidor**: comprueba PHP, extensiones y permisos.
   2. **Base de datos**: SQLite, MariaDB/MySQL o SQL Server (ver más abajo).
   3. **Inicio**: *Web nueva* o *Restaurar una copia de seguridad* (ver más abajo).
   4. **Empresa**: razón social, nombre comercial, lema, CIF, país, teléfono, correo, dirección, logotipo y color de marca.
   5. **Opciones**: módulos que se usan, campos del catálogo (precio, tallas, color…), prefijo telefónico, zona horaria y «Ocultar a Google por ahora».
   6. **Administrador**: usuario, correo y contraseña (mínimo 10 caracteres).
5. Al terminar, el asistente **se borra solo**. Si no puede, bórralo por FTP: mientras exista, el panel muestra un aviso rojo.
6. Trae el catálogo desde **Importar** (WordPress, hoja de cálculo u otra aplicación) o créalo a mano.
7. Cuando esté todo bien: desmarca «Ocultar la web a Google» en *Web → Configuración* y apunta el dominio principal a esta carpeta.

Funciona igual en la raíz del dominio que en una subcarpeta (`/cms/`, `/nueva/`…), sin cambiar nada. Ojo al descomprimir el ZIP: los archivos (`index.php`, `instalar.php`, `app/`…) tienen que quedar **directamente** en la carpeta de la web, no dentro de otra carpeta `cms-base/`.

## Base de datos: qué motor elegir

| Motor | Cuándo | Qué hace falta |
|---|---|---|
| **SQLite** (recomendado) | Casi siempre. Va sobrado para catálogos de miles de artículos. | Nada: es un archivo dentro de `data/`. |
| **MariaDB / MySQL** | Mucho tráfico, o si otra aplicación tiene que leer los mismos datos. | Crear antes la base de datos en el panel del hosting (IONOS: *Hosting → Bases de datos*) y anotar servidor, nombre, usuario y contraseña. Usa una base de datos vacía solo para esta web. |
| **SQL Server** | Servidores Windows o empresas que ya lo usan. | La extensión `pdo_sqlsrv` de PHP (los hostings Linux normalmente no la tienen; el instalador avisa). |

- Los datos de conexión se guardan en `data/db.php` (no se puede abrir desde el navegador). No lo compartas: lleva la contraseña.
- Si eliges una base de datos que ya tiene una web de este CMS, se usa tal cual (sirve para reinstalar los archivos sin perder nada).
- **Cambiar de motor** (por ejemplo de SQLite a MariaDB): descarga una copia de seguridad, sube los archivos a una carpeta nueva, elige el motor nuevo en el instalador y restaura la copia.
- MariaDB y SQL Server están programados pero **no probados todavía en un servidor real**: al estrenarlos, revisa que todo funciona (crear, editar, importar, copia de seguridad) antes de publicar.

## Trasladar o recuperar una web (copia de seguridad)

- **Hacer la copia**: *Web → Empresa → Descargar copia de seguridad*. Es un ZIP con la base de datos (artículos, familias, campos, textos, usuarios) y todas las fotos. La base de datos va siempre como archivo SQLite, sea cual sea el motor, así que la copia se puede restaurar en cualquiera. Hazla antes de cada actualización.
- **Restaurarla en otro servidor**: sube los archivos, abre el instalador y elige *Restaurar una copia de seguridad*. Si el ZIP pesa más de lo que admite el hosting, cópialo por FTP a `data/restaurar/` y recarga el paso 2.
- Se entra con los mismos usuarios y contraseñas de la copia.

## Panel (`/admin`)

| Sección | Qué hay |
|---|---|
| **Artículos** | Listado, familias y subfamilias, fotos (mejorar, fondo blanco o transparente) |
| **Importar** | Importar artículos (WordPress, hoja de cálculo…) y enlazar fotos subidas por FTP |
| **Web** | Empresa (identidad, contacto, portada, pie, copia de seguridad), Configuración, Slider y Representantes |
| **Usuarios** | Usuarios y roles; Mi cuenta (correo y contraseña) |

**Roles**: *Administrador* ve todo. *Gestión de catálogo* solo ve Artículos, Importar y Mi cuenta. Siempre queda al menos un administrador.

### Web → Empresa
Todo lo que identifica a la empresa: razón social, nombre comercial, subtítulo, CIF, lema, logotipo y su tamaño, color de marca, dirección, teléfono, WhatsApp, correo, redes, textos de la portada («Quiénes somos» y cuatro tarjetas), imagen de fondo de la portada y banner de logotipos del pie.

### Web → Campos
Campos propios de cada artículo, además de nombre, referencia, medidas, descripción y fotos: precio, tallas, color, material, stock…
- **Tipos**: texto, texto largo, número (con unidad), precio, lista de una opción, lista de varias opciones, sí/no y enlace.
- **Se ve en la web** o **interno** (stock, precio de coste…: solo en el panel).
- Aparecen en el formulario de cada artículo y, si son públicos, en su ficha. El primer precio público sale también en las tarjetas del catálogo. El buscador de la web busca también en los campos públicos.
- Hay botones para añadir los habituales con un clic. Borrar un campo borra sus valores en todos los artículos.
- Al importar: en una hoja de cálculo, pon una columna con el mismo nombre que el campo («Precio», «Color»…); en WooCommerce el precio normal va al campo de precio y los atributos con el mismo nombre que un campo van a ese campo; en una base de datos se elige la columna de cada campo.

### Web → Configuración
- **Módulos** que se pueden activar o desactivar (desactivar solo oculta, no borra):
  - Representantes por zonas en Contacto
  - Slider de portada
  - Catálogo en PDF (`/catalogo/completo`)
  - Boceto con medidas en la ficha (dibujo «alto × ancho»)
  - Fila a escala (subfamilias en fila con su tamaño real relativo)
  - Mapa y botones «Cómo llegar» en Contacto
- **Prefijo telefónico** (se añade a los enlaces para llamar) y **zona horaria**.
- **Ocultar la web a Google** (pone `noindex` y bloquea todo en `robots.txt`).
- **Textos fijos**: saludo de la portada, título del catálogo, llamada final de la portada y título de representantes.

## Importar el catálogo

*Panel → Importar → Importar artículos*. Siempre en dos pasos: **Analizar** (no guarda nada y muestra lo que encuentra) e **Importar lo marcado** (crea familias y artículos y trae las fotos por tandas; no cierres la página hasta que termine).

Nunca se sobrescribe nada: solo se crean artículos nuevos, se añaden fotos y se rellenan campos vacíos. Un artículo que ya existe se reconoce por su referencia o por su nombre, así que se puede repetir sin duplicar.

### WordPress / WooCommerce (CSV) — recomendado si vienes de WordPress
1. En WordPress: *Productos → Exportar → Generar CSV* (vale en español o en inglés).
2. Opcional: *Herramientas → Exportar → Medios* para traer fotos adjuntas que el CSV no lista.
3. Súbelos y pulsa Analizar. La web antigua debe seguir online mientras se descargan las fotos.

### Hoja de cálculo u otra aplicación (CSV)
Para Excel, Google Sheets, Shopify, PrestaShop, un ERP o cualquier programa que exporte una tabla.
- Guarda la hoja como **CSV** (en Excel: *Guardar como → CSV UTF-8*). Hay una **plantilla** para descargar en la misma pantalla.
- Columnas que se reconocen por su título (mayúsculas y acentos dan igual): **Nombre** (obligatoria), **Familia**, **Subfamilia**, **Referencia**, **Medidas**, **Descripción**, **Fotos**, **Visible**, **Orden**. También en inglés: *Name/Title, Category/Type, SKU, Description/Body (HTML), Image Src, Published…*
- En *Familia* puedes poner las dos cosas a la vez: `Sillas > Plegables`.
- **Fotos**: direcciones (`https://…`) o nombres de archivo (`silla-1.jpg`) separados por `|`. Si son nombres, sube las fotos en un **ZIP**. Si el ZIP es grande, cópialo por FTP a `data/importar/` y deja el campo vacío.
- Si hay varias filas por artículo con el mismo *Handle* (Shopify), se juntan sus fotos.

### Base de datos de otra aplicación (Access, SQL)
Para programas de gestión y tiendas que guardan los artículos en una base de datos.
- **Access** (`.mdb` de Access 97 a 2003 y `.accdb`): NCS Fact, Factusol y otros programas de gestión. Se lee directamente, sin instalar nada en el hosting. Si tiene contraseña, quítasela antes en Access.
- **Volcado SQL** (`.sql` o `.sql.gz`) de MySQL, MariaDB, SQL Server o PostgreSQL (por ejemplo exportado con phpMyAdmin), o un archivo **SQLite** (`.sqlite`, `.db`).
- Si el archivo pesa mucho, cópialo por FTP a `data/importar/` y pulsa Analizar sin elegir archivo.

Después de analizar se elige:
1. **La tabla de artículos.** Se propone sola y se ven sus primeras filas.
2. **Qué columna es cada cosa:** nombre, referencia, descripción, medidas, visible (con la opción «al revés» para columnas como *Bloqueado*), orden y columnas que se añaden a la descripción con el nombre que quieras (por ejemplo *Tallas* y *Color*).
3. **La familia.** Puede ser un texto o un número que apunta a otra tabla de familias (con familia padre si hay subfamilias).
4. **Las fotos.** Pueden ser una dirección, un nombre de archivo o una ruta de Windows (`C:\Fotos\A123.JPG`) en la tabla de artículos o en otra tabla de fotos. Si son nombres de archivo, sube un **ZIP con las fotos** (se emparejan por el nombre del archivo) o indica la dirección web donde están publicadas.

Las importaciones grandes (más de 250 artículos) muestran un resumen y una muestra en vez de una casilla por artículo, y se importan todas de una vez.

**Ejemplo, NCS Fact (`FALMACEN.MDB`):** tabla *TART*; nombre *DESCRIPCION*; referencia *CODIGO*; familia *FAMILIA*, que apunta a la tabla *TFAM* (*CODIGO* → *FAMILIA*); visible *BLOQUEADO* al revés; foto *FOTO*. Si has creado los campos Precio, Tallas, Color y Stock: Precio ← *PVP2*, Tallas ← *T00*, Color ← *C00*, Stock ← *SACTUAL* (comprueba con la empresa cuál de sus tarifas, *PVP1*, *PVP2* o *PVP3*, es la de venta al público). Sin campos, *T00* y *C00* se pueden añadir a la descripción con los nombres «Tallas, Color». Para las fotos, comprime en un ZIP la carpeta de imágenes del programa (por ejemplo `\NCSFACT.NET\IMAGENES`).

### Leer una web publicada (WordPress)
Analiza las páginas de una web antigua y copia fotos, descripciones y medidas. Úsalo si no puedes exportar el CSV.

### Añadir otra aplicación
Cada origen es un archivo en `app/importers/`. Para una aplicación nueva se copia uno y se adapta (ver `app/importers/LEEME.md`); aparece solo en el panel.

**Seguridad del importador**: solo descarga de los servidores que aparecen en el archivo, nunca de direcciones internas. No cambies `IMPORT_ALLOW_LOCAL` en `app/config.php`: debe seguir en `false`.

## Fotos subidas por FTP

Una foto copiada a `uploads/` por FTP no aparece sola en la web: hay que enlazarla a su artículo.
1. Copia las fotos (JPG, PNG o WebP) a `uploads/` (o a `uploads/entrada/`).
2. *Panel → Importar → Fotos por FTP*. La web compara cada foto con las existentes (por nombre y por parecido) y propone el artículo, enseñando las dos fotos para comprobarlo.
3. Elige **Sustituir** o **Añadir** y pulsa **Enlazar**.

Si las fotos retocadas (por ejemplo con Photoroom) conservan el nombre de la foto de la web, el botón **«Sustituir automáticamente»** las cambia todas por tandas.
**Fotos rotas** (el archivo ya no está en `uploads/`): la misma pantalla avisa y permite quitarlas. Al copiar por FTP, **no sincronices ni borres**: copia solo añadiendo.

## Mejorar fotos (fondo blanco, centradas, mismo tamaño)

*Artículos → Fotos*: «Probar con 6 fotos» enseña el antes y el después; «Mejorar todas las pendientes» las procesa por tandas; «Deshacer todo» vuelve a los originales (guardados en `uploads/orig/`). Fondo **blanco** o **transparente**. Las fotos con fondo irregular se listan aparte y se pueden descargar en un ZIP para retocarlas. Si ya están retocadas, usa «Marcar como ya retocadas».

## Slider de la portada

*Web → Slider*: imágenes anchas (por ejemplo 1920 × 800 px) con título, texto y botón opcionales; efecto (fundido, deslizar, zoom lento) y segundos entre diapositivas. Sin diapositivas visibles, la portada muestra el titular normal. En el enlace del botón escribe una dirección de la web (`/catalogo/nombre-de-familia`) o una completa (`https://…`).

## Actualizar a una versión nueva

1. Haz una copia de seguridad.
2. Sube los archivos nuevos **encima**, sin tocar `data/` ni `uploads/` (y sin `instalar.php`).
3. La base de datos se actualiza sola en la primera visita.

## Recuperar la contraseña

- En la pantalla de acceso: **¿Olvidaste tu contraseña?** envía un enlace de un solo uso (1 hora) al correo de la cuenta. Usa el correo del servidor (`mail()` de PHP).
- Si el correo no llega: sube `reset-clave.php` (del ZIP de recuperación), crea por FTP el archivo vacío `data/reset.txt`, abre `/reset-clave.php` y **borra después `reset-clave.php`**.

## Seguridad (incluida)

Contraseñas cifradas, protección CSRF, bloqueo de 15 minutos tras 8 intentos fallidos, validación y recompresión de cada foto subida, texto escapado, `.htaccess` que impide abrir `data/` y `app/` desde el navegador, y en `uploads/` nunca se ejecuta código.

**Empezar de cero** (vaciar el catálogo conservando usuarios y datos de empresa) está oculto: se activa con `ALLOW_RESET = true` en `app/config.php` y aparece en *Web → Empresa*. Vuelve a ponerlo en `false` en producción.

## Rendimiento

Las fotos se convierten a WebP al subirlas (1400 px y miniatura de 480 px), el CSS es pequeño, apenas hay JavaScript y el `.htaccess` activa compresión y caché.
