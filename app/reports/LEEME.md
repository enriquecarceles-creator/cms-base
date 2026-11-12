# Informes

Cada archivo `.php` de esta carpeta es un informe que aparece solo en **Panel → Informes** (menú y página «Todos los informes»),
igual que los orígenes de `app/importers/`. El motor común está en `app/reports.php` (pantalla, agrupar, subtotales, paginar, CSV, Excel
e impresión A4 / PDF) y las piezas que comparten varios informes en `app/reports_lib.php`.

No hace falta tocar nada más: copia un informe parecido, cámbiale el `id` y adapta los filtros, las columnas y la consulta.

## Qué devuelve el archivo

```php
return [
    'id'      => 'mi-informe',            // único, sin espacios: es la dirección /admin/listados/mi-informe
    'title'   => 'Mi informe',            // título en el menú y en la pantalla
    'section' => 'Ventas',                // Ventas | Compras | Documentos | Clientes | Artículos | Fiscal (así se agrupa en la página de informes)
    'order'   => 50,                      // posición dentro de la sección (menor = antes)
    'perm'    => 'erp',                   // quién lo ve: 'erp' (administradores y rol ERP: clientes y ventas, con la doble verificación
                                          //   si está exigida), 'purch' (administradores y rol Compras, con la doble verificación de Compras), 'catalog' (admin y catálogo),
                                          //   'all' (todos los roles) o 'admin'. Con 'guards' => ['erp', 'purch'] se exigen las dos (p. ej. el modelo 347)
    'history' => 'ventas',                // qué se anota en Datos → Historial al exportar: ventas | clientes | articulos | compras
    'help'    => 'Una frase que explica el informe.',
    'notice'  => '',                      // opcional: aviso destacado (pantalla, impresión, CSV y Excel); p. ej. «Revisar con el asesor…»
    'filters' => [ ... ],                 // ver abajo
    'variants'=> ['simple' => 'Listado simple', 'lineas' => 'Detallando líneas'],   // «Tipo de listado»
    'groups'  => ['cliente' => ['label' => 'Cliente', 'field' => '_cliente']],      // «Agrupar por»: field = clave de la fila con el nombre del grupo
    'orders'  => ['fecha' => ['label' => 'Fecha', 'by' => ['fecha', 'num']]],       // «Ordenado por»: by = claves de la fila (la primera es la predeterminada)
    'options' => [['name' => 'anulados', 'label' => 'Incluir anulados', 'default' => 0]],   // casillas
    'columns' => fn(string $variant, array $sel) => [ ... ],                         // o una lista fija
    'rows'    => fn(array $sel) => [ ... ],                                          // las filas
];
```

### Filtros

| `type`   | Qué pinta | Qué llega a `rows()` en `$sel['f'][nombre]` |
|----------|-----------|---------------------------------------------|
| `date`   | Desde / Hasta (fechas) | `['d' => aaaammdd, 'h' => aaaammdd]` (0 = vacío) |
| `code`   | Desde / Hasta (códigos); con `'lookup' => 'cliente'` o `'articulo'` sale el botón «…» que busca | `['d' => texto, 'h' => texto]` |
| `select` | Lista cerrada; `'options'` = array `[valor => texto]` o función que lo devuelve | el valor elegido (`''` = todos) |
| `text`   | Texto «contiene» | texto |

Con `'personal' => true` (p. ej. un NIF) el valor no se recuerda para la próxima vez. **Vacío = todos.** Las casillas llegan en
`$sel['opt'][nombre]` (0 o 1), el tipo de listado en `$sel['v']`, el agrupado en `$sel['g']`.

Ayudas para construir la consulta: `rep_cond_date()`, `rep_cond_code()`, `rep_cond_like()` (añaden la condición y el parámetro).

### Columnas

`['key' => 'total', 'label' => 'Total', 'type' => 'money', 'sum' => true, 'w' => 14]`

- `type`: `text` (por defecto), `date` (entero aaaammdd), `money` (**céntimos**, entero), `qty` (número con decimales), `int`, `pct` (porcentaje).
- `sum => true`: se suma en los subtotales de cada grupo y en el total general.
- `w`: ancho aproximado en Excel.

### Filas

Un array de arrays: la clave es la de la columna. Para agrupar, añade a cada fila el campo del grupo (el `field` de `groups`, p. ej.
`'_cliente' => '00391 · Calzados X'`; para el mes, `rep_month_key($aaaammdd)`). Para ordenar de mayor a menor un importe, añade una
clave negativa (`'_ni' => -$importe`) y úsala en `by`.

## Reglas (obligatorias)

- SQL portable (sección 3 del CLAUDE.md): consultas preparadas, `LIKE … ESCAPE '!'`, nada de `IFNULL`, `GROUP_CONCAT`, etc.
- Rápido con miles de documentos: agrupa en SQL cuando se pueda y no hagas una consulta por fila. Tope de `REP_MAX_ROWS` filas (100.000).
- **Facturas de prueba excluidas por defecto**: incluirlas solo con una casilla «Incluir documentos de prueba».
- Un informe de clientes o ventas lleva `'perm' => 'erp'`; el motor exige además la doble verificación si está exigida.
- Datos personales: el motor no guarda el resultado (el CSV, el Excel y la hoja se generan al momento y se descargan; el `.xlsx`
  temporal se borra nada más enviarlo). Lo único que se recuerda por usuario son los filtros (ajuste `rep_<usuario>_<informe>`).
- El stock es un campo interno: solo con `report_can_stock()` (administradores y catálogo).
- Si el servidor no tiene la extensión `zip`, el motor no ofrece Excel y deja solo CSV.

## Salidas

`?salida=pantalla|imprimir|csv|xlsx` sobre la misma selección. CSV: UTF-8 con BOM, `;` y coma decimal. Excel: `.xlsx` con números como
números y fechas como fechas. Imprimir / PDF: hoja A4 con logotipo, filtros usados, fecha, «Página x de y» (márgenes de página de CSS:
Chrome y Edge) y totales; «Guardar como PDF» es el del navegador.
