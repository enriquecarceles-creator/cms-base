<?php
/** Configuración → Formas de pago. Variables: $rows, $inUse (códigos usados por datos importados), $missing (códigos sin dar de alta), $errors, $old, $defaultCode. */
$old = $old ?? null;
?>
<div class="head"><h1>Formas de pago</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Las formas de pago de tus documentos: código, nombre (el que ves en facturas y fichas) y días de vencimiento. Un código que ya usan clientes o facturas no se borra ni cambia: solo se puede desactivar.</p>

<?php if ($missing): ?>
  <form method="post" action="<?= e(url('/admin/configuracion/pagos')) ?>" class="cfgf">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_ncs">
    <p class="cfg-warn">Los clientes y facturas importados usan <?= count($missing) ?> código<?= count($missing) === 1 ? '' : 's' ?> que todavía no están aquí: <strong><?= e(implode(', ', $missing)) ?></strong>.
      <button class="btn small" type="submit" style="margin-left:8px">Traerlos</button></p>
  </form>
<?php endif; ?>

<form method="post" action="<?= e(url('/admin/configuracion/pagos')) ?>" class="cfgf" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <fieldset>
    <legend>Formas de pago</legend>
    <?php if (!$rows): ?><p class="muted" style="margin:0">Todavía no hay ninguna. Añade la primera abajo<?= $missing ? ' o trae las de los datos importados' : '' ?>.</p><?php else: ?>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th scope="col">Código</th><th scope="col">Nombre</th><th scope="col">Vencimiento (días)</th><th scope="col">Activa</th><th scope="col">Uso</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int)$r['id']; $in = $old['p'][$id] ?? null; $used = isset($inUse[(string)$r['code']]) || $defaultCode === (string)$r['code']; ?>
        <tr>
          <td><strong><?= e($r['code']) ?></strong></td>
          <td><input name="p[<?= $id ?>][name]" value="<?= e($in['name'] ?? $r['name']) ?>" maxlength="40" aria-label="Nombre" placeholder="sin nombre"></td>
          <td><input class="num" type="number" min="0" max="365" name="p[<?= $id ?>][days]" value="<?= e((string)($in['days'] ?? $r['days'])) ?>" aria-label="Días"></td>
          <td><input type="checkbox" name="p[<?= $id ?>][active]" value="1"<?= ($in !== null ? !empty($in['active']) : (int)$r['is_active'] === 1) ? ' checked' : '' ?> aria-label="Activa"></td>
          <td><small class="muted"><?= isset($inUse[(string)$r['code']]) ? 'datos importados' : '' ?><?= $defaultCode === (string)$r['code'] ? ' · por defecto' : '' ?></small></td>
          <td><?php if (!$used): ?><button class="link danger" type="submit" name="action" value="delete" onclick="document.getElementById('del-id').value=<?= $id ?>;return confirm('¿Borrar esta forma de pago?')">Borrar</button><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
    <input type="hidden" name="id" id="del-id" value="">
  </fieldset>

  <fieldset>
    <legend>Nueva forma de pago</legend>
    <div class="g3">
      <label>Código <input name="new[code]" value="<?= e((string)($old['new']['code'] ?? '')) ?>" maxlength="20" placeholder="Ej.: 01"></label>
      <label>Nombre <input name="new[name]" value="<?= e((string)($old['new']['name'] ?? '')) ?>" maxlength="40" placeholder="Ej.: Transferencia"></label>
      <label>Vencimiento (días) <input type="number" min="0" max="365" name="new[days]" value="<?= e((string)($old['new']['days'] ?? 0)) ?>"></label>
    </div>
    <small>Si importas de otro programa, usa el mismo código que allí para que cuadre con sus clientes y facturas.</small>
  </fieldset>
  <div class="savebar"><button class="btn" type="submit" onclick="document.getElementById('del-id').value=''">Guardar</button></div>
</form>
