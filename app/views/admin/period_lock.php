<?php
/** Configuración → Bloqueo de periodos. Variables: $cur (aaaammdd o 0), $errors, $log. */
?>
<div class="head"><h1>Bloqueo de periodos</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Fecha hasta la que <strong>no se pueden crear ni modificar documentos</strong>. Sirve para cerrar un periodo ya presentado o revisado (por ejemplo, un trimestre de IVA) y que nadie lo cambie sin querer. Subir la fecha es libre; para bajarla o quitar el bloqueo hace falta tu contraseña y queda anotado en el <a href="<?= e(url('/admin/historial')) ?>">Historial</a>.</p>
<?php if ($cur > 0): ?>
  <p class="alert warn"><?= admin_icon('info') ?><span>Ahora está bloqueado todo hasta el <strong><?= e(erp_day($cur)) ?></strong> (incluido).</span></p>
<?php else: ?>
  <p class="muted">Ahora no hay ningún periodo bloqueado.</p>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/configuracion/bloqueo')) ?>" class="cfgf" autocomplete="off">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Bloquear hasta</legend>
    <div class="g3">
      <label>Fecha (incluida) <input type="date" name="lock" value="<?= e(erp_day_to_input($cur)) ?>" max="<?= e(date('Y-m-d')) ?>"><small>Vacía = sin bloqueo. No puede ser posterior a hoy.</small></label>
    </div>
    <p class="cfg-warn">Bajar la fecha o quitar el bloqueo <strong>reabre periodos cerrados</strong>. Confirma con tu contraseña.</p>
    <div class="g3">
      <label class="check" style="align-self:end"><input type="checkbox" name="confirm" value="1"> <span>Entiendo que reabro periodos cerrados</span></label>
      <label>Tu contraseña <input type="password" name="password" autocomplete="current-password"></label>
    </div>
  </fieldset>
  <div class="savebar"><button class="btn" type="submit">Guardar</button><span class="muted">La contraseña solo se pide al reabrir.</span></div>
</form>

<?php if ($log): ?>
  <h2 style="font-size:1rem;margin:14px 0 6px">Últimos cambios</h2>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th scope="col">Fecha</th><th scope="col">Quién</th><th scope="col">Cambio</th></tr></thead>
    <tbody><?php foreach ($log as $r): ?><tr><td><?= e(date('d/m/Y H:i', (int)$r['created_at'])) ?></td><td><?= e($r['username']) ?></td><td><?= e($r['detail']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
<?php endif; ?>
