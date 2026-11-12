<?php
/** Configuración → Formas de pago. Variables: $rows, $inUse (códigos usados por datos importados), $usage (uso por código), $missing (códigos sin dar de alta), $errors, $old, $defaultCode. */
$old = $old ?? null;
$ex = function (string $terms): string { // reparto de 100 € con esos plazos
    $t = dues_parse_terms($terms) ?? [0];
    return implode(' · ', array_map(fn($r) => erp_money($r['cents']), dues_schedule(10000, 20260101, $t)));
};
?>
<div class="head"><h1>Formas de pago</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Las formas de pago de tus documentos: código, nombre (el que ves en facturas y fichas) y <strong>vencimientos</strong>: los días de cada cuota, separados por comas. <code>0</code> es contado, <code>30</code> una cuota a 30 días, <code>30,60,90</code> tres cuotas iguales (la última lleva los céntimos que sobren). Si el cliente tiene un <em>día de pago</em> (ficha del cliente), cada vencimiento se pasa al siguiente de esos días; el contado no se mueve.</p>

<?php if ($missing): ?>
  <form method="post" action="<?= e(url('/admin/configuracion/pagos')) ?>" class="cfgf">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_ncs">
    <p class="cfg-warn">Los clientes y facturas importados usan <?= count($missing) ?> código<?= count($missing) === 1 ? '' : 's' ?> que todavía no están aquí: <strong><?= e(implode(', ', $missing)) ?></strong>.
      <button class="btn small" type="submit" style="margin-left:8px">Traerlos</button></p>
  </form>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/configuracion/pagos')) ?>" class="cfgf" autocomplete="off" id="paymethods">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <fieldset>
    <legend>Formas de pago</legend>
    <?php if (!$rows): ?><p class="muted" style="margin:0">Todavía no hay ninguna. Añade la primera abajo<?= $missing ? ' o trae las de los datos importados' : '' ?>.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th scope="col">Código</th><th scope="col">Nombre</th><th scope="col">Vencimientos (días)</th><th scope="col">Reparto de 100 €</th><th scope="col">Activa</th><th scope="col">Uso</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int)$r['id']; $in = $old['p'][$id] ?? null; $u = pay_usage_text($usage[(string)$r['code']] ?? []); $isDefault = $defaultCode === (string)$r['code']; $active = $in !== null ? !empty($in['active']) : (int)$r['is_active'] === 1; ?>
        <tr>
          <td><strong><?= e($r['code']) ?></strong></td>
          <td><input name="p[<?= $id ?>][name]" value="<?= e($in['name'] ?? $r['name']) ?>" maxlength="40" aria-label="Nombre" placeholder="sin nombre"></td>
          <td><input name="p[<?= $id ?>][terms]" value="<?= e((string)($in['terms'] ?? $r['terms'])) ?>" maxlength="60" aria-label="Vencimientos (días)" placeholder="0, 30 o 30,60,90" style="width:9em"></td>
          <td><small class="muted"><?= e($ex((string)($in['terms'] ?? $r['terms']))) ?></small></td>
          <td><input type="checkbox" name="p[<?= $id ?>][active]" value="1"<?= $active ? ' checked' : '' ?> aria-label="Activa"></td>
          <td><small class="muted"><?= e($u) ?><?= $isDefault ? ($u !== '' ? ' · ' : '') . 'por defecto' : '' ?></small></td>
          <td>
            <?php if ($u === '' && !$isDefault): ?>
              <button class="link danger" type="submit" name="action" value="delete" onclick="document.getElementById('del-id').value=<?= $id ?>;return confirm('¿Borrar la forma de pago <?= e($r['code']) ?>?')">Borrar</button>
            <?php elseif ($active && !$isDefault): ?>
              <button class="link" type="submit" name="action" value="deactivate" onclick="document.getElementById('del-id').value=<?= $id ?>;return confirm('Esta forma de pago está en uso, así que no se borra. ¿Desactivarla? Dejará de salir al elegir, pero los clientes y documentos que la tienen la conservan.')">Desactivar</button>
            <?php elseif (!$active): ?><small class="muted">desactivada</small>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <small>«Borrar» solo sale si nadie la usa. Si ya la usan clientes o documentos, se ofrece «Desactivar»: deja de salir al elegir, pero los documentos antiguos la conservan.</small>
    <?php endif; ?>
    <input type="hidden" name="id" id="del-id" value="">
  </fieldset>

  <fieldset>
    <legend>Nueva forma de pago</legend>
    <div class="g3">
      <label>Código <input name="new[code]" value="<?= e((string)($old['new']['code'] ?? '')) ?>" maxlength="20" placeholder="Ej.: 01"></label>
      <label>Nombre <input name="new[name]" value="<?= e((string)($old['new']['name'] ?? '')) ?>" maxlength="40" placeholder="Ej.: Transferencia a 30-60-90"></label>
      <label>Vencimientos (días) <input name="new[terms]" value="<?= e((string)($old['new']['terms'] ?? '')) ?>" maxlength="60" placeholder="0 (contado), 30 o 30,60,90"></label>
    </div>
    <small>Si importas de otro programa, usa el mismo código que allí para que cuadre con sus clientes y facturas. Vacío = contado (0).</small>
  </fieldset>
  <div class="savebar"><button class="btn" type="submit" onclick="document.getElementById('del-id').value=''">Guardar</button></div>
</form>
