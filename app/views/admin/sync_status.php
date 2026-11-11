<?php
/** Datos → Sincronización (solo estado). Variables: $last (['at', 'counts']) o null, $ncs, $totals. */
?>
<div class="head"><h1>Sincronización</h1><a class="btn" href="<?= e(url('/admin/erp')) ?>">Sincronización manual con NCS</a></div>

<h2>Sincronización automática</h2>
<p><span class="tag tag-off">Sin configurar</span></p>
<p class="hint">La sincronización automática mantendrá la web al día con tu programa de facturación sin que tengas que subir archivos a mano. <strong>Todavía no está disponible</strong> en esta versión: esta pantalla solo deja preparado el sitio donde se verá.</p>
<h3>Cómo funcionará</h3>
<ul>
  <li>Un pequeño programa en el ordenador de la oficina (sin instalar nada, con el Programador de tareas de Windows) enviará a la web, de forma segura, los cambios de NCS: artículos, precios y stock, clientes y facturas.</li>
  <li>Solo irá en un sentido: <strong>de NCS a la web</strong>. Lo que se edite en la web (cobros, etiquetas, notas) no se pisa.</li>
  <li>Cada equipo tendrá su propia clave, que se podrá anular. El servidor de datos de la oficina nunca se abre a internet.</li>
  <li>Aquí verás cuándo llegó la última sincronización, y la web avisará en el Inicio y por correo si deja de llegar.</li>
</ul>
<p class="muted">Mientras tanto, la <a href="<?= e(url('/admin/erp')) ?>">sincronización manual</a> sigue funcionando como siempre, y cada operación queda en el <a href="<?= e(url('/admin/historial')) ?>">Historial</a>.</p>

<h2>Lo que hay ahora</h2>
<ul>
  <li>Última sincronización manual: <?= $last ? '<strong>' . e(date('d/m/Y H:i', (int)$last['at'])) . '</strong>' : '<span class="muted">ninguna todavía</span>' ?></li>
  <li>Clientes guardados: <strong><?= (int)$totals['customers'] ?></strong> · Facturas: <strong><?= (int)$totals['invoices'] ?></strong> · Líneas: <strong><?= (int)$totals['lines'] ?></strong></li>
  <li>Archivos de NCS esperando en <code>data/importar/</code>: <?= ($ncs['almacen'] || $ncs['cuentas'] || $ncs['historico']) ? e(implode(', ', array_filter([$ncs['almacen'] ? 'FALMACEN' : '', $ncs['cuentas'] ? 'FCUENTAS' : '', $ncs['historico'] ? 'FHISTORI' : '']))) : '<span class="muted">ninguno</span>' ?></li>
</ul>
