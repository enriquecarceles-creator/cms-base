# CMS de catálogo genérico (base multiempresa)
Catálogo + panel en PHP 8.1 / SQLite, sin datos. Instalar: subir todo y abrir la web (o instalar.php). Vale en la raíz o en una subcarpeta. El asistente se borra solo.

Instalador (6 pasos): servidor → base de datos (SQLite, MariaDB/MySQL, SQL Server) → web nueva o restaurar copia de seguridad → empresa (datos, logotipo, color, país) → opciones (módulos, campos del catálogo, prefijo, zona horaria, noindex) → administrador.

Base de datos: app/db.php (CmsPdo traduce el SQL estilo SQLite a MariaDB/SQL Server; esquema único con marcadores en app/schema.php). Configuración en data/db.php. Copias siempre en SQLite (sirven para cambiar de motor). MariaDB y SQL Server sin probar aún en servidor real.
Campos libres: app/fields.php, tablas custom_fields y product_fields; panel Web → Campos.

Configuración por empresa, sin tocar código:
- Web → Empresa: identidad, contacto, redes, textos de portada, banner del pie, copia de seguridad.
- Web → Configuración: módulos activables (representantes, slider, PDF, boceto, fila a escala, mapa), prefijo telefónico, zona horaria, ocultar a Google, textos fijos.

Importar (app/importers/, un archivo por origen; guía en app/importers/LEEME.md):
- WordPress / WooCommerce (CSV, en español o inglés, + XML de medios opcional).
- Hoja de cálculo u otra aplicación (CSV de Excel/Sheets/Shopify/PrestaShop/ERP; fotos por URL o en ZIP; plantilla descargable).
- Base de datos: Access .mdb/.accdb (lector propio en PHP puro, app/mdb.php), volcados SQL y SQLite; paso de emparejar tabla y columnas.
- Leer una web publicada (WordPress).
Pendiente: orígenes concretos de otras aplicaciones (se añadirán como archivos nuevos en app/importers/).

Base de datos: data/site.sqlite. No subir data/ ni uploads/ al actualizar.
