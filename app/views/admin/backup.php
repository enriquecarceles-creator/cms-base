<div class="head"><h1>Copias de seguridad</h1></div>
<p class="hint">Una copia es un ZIP con la base de datos y todas las fotos. Hazla antes de actualizar la web y guárdala fuera del servidor.</p>
<?php if ($lastBackup !== ''): ?><p class="muted">Última copia descargada: <?= e(date('d/m/Y H:i', (int)strtotime($lastBackup))) ?>.</p><?php else: ?><p class="alert warn"><?= admin_icon('alert') ?><span>Todavía no has descargado ninguna copia de seguridad.</span></p><?php endif; ?>

<h2>Descargar una copia</h2>
<form method="post" action="<?= e(url('/admin/configuracion/copia')) ?>" class="form" id="copia">
  <?= csrf_field() ?>
  <p class="muted" style="margin:0">Descarga un ZIP con la base de datos (artículos, <?= e(term('families', true)) ?>, usuarios, textos, portada) y todas las fotos. Hazlo antes de actualizar la web.</p>
  <label class="check"><input type="checkbox" name="with_erp" value="1"> <span>Incluir clientes y ventas (datos personales)</span></label>
  <p class="muted" style="margin:0">Por defecto la copia <strong>no</strong> lleva los clientes ni las facturas de NCS, ni las claves de la doble verificación. <strong>Los pedidos y albaranes van siempre</strong> (se crean aquí y no se recuperan de otro sitio) y llevan nombre, NIF y dirección de los clientes: guarda la copia con cuidado. Si restauras una copia, los clientes y ventas se vuelven a traer sincronizando con NCS, pero <strong>las notas internas de los clientes no</strong> (las escribe la gente aquí): si las quieres conservar, marca la casilla.</p>
  <div class="buttons"><button class="btn" type="submit">Descargar copia de seguridad</button></div>
</form>

<h2 id="historial">Descargas anteriores</h2>
<?php if (!$log): ?>
  <p class="muted">Todavía no se ha descargado ninguna copia desde este panel.</p>
<?php else: ?>
  <p class="muted" style="margin:0">Las últimas 50. Este historial no viaja dentro de las copias.</p>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th scope="col">Fecha</th><th scope="col">Quién</th><th scope="col">Clientes y ventas</th><th scope="col">Tamaño</th></tr></thead>
    <tbody>
    <?php foreach ($log as $r): $mb = (int)$r['size_bytes'] / 1048576; ?>
      <tr>
        <td><?= e(date('d/m/Y H:i', (int)$r['created_at'])) ?></td>
        <td><?= e($r['username']) ?></td>
        <td><?= (int)$r['with_erp'] === 1 ? 'Incluidos' : 'No' ?></td>
        <td><?= e($mb >= 1 ? number_format($mb, 1, ',', '.') . ' MB' : number_format((int)$r['size_bytes'] / 1024, 0, ',', '.') . ' KB') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>

<?php if (ALLOW_RESET): ?>
<h2>Empezar de cero</h2>
<form method="post" action="<?= e(url('/admin/configuracion/reiniciar')) ?>" class="form" onsubmit="return confirm('Se borrarán artículos, <?= e(addslashes(term('families', true))) ?>, fotos, slider y representantes. ¿Continuar?')">
  <?= csrf_field() ?>
  <p class="muted" style="margin:0">Vacía el catálogo (artículos, <?= e(term('families', true)) ?>, fotos, slider y representantes). Se conservan los usuarios y los datos de esta página. Antes se guarda una copia en <code>data/backups/</code>.</p>
  <label>Escribe el nombre comercial («<?= e(site_name()) ?>») para confirmar <input name="confirm" autocomplete="off" required></label>
  <label>Tu contraseña <input type="password" name="password" autocomplete="current-password" required></label>
  <div class="buttons"><button class="btn danger-btn" type="submit">Vaciar el catálogo</button></div>
</form>
<?php endif; ?>
