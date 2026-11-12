<?php
/** Configuración → Tarifas. Variables: $rows, $users (clientes por tarifa), $errors, $old. */
$old = $old ?? null;
?>
<div class="head"><h1>Tarifas</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Listas de precios (Tarifa 1, 2, 3…). El número es el que usan los clientes. La <strong>referencia externa</strong> sirve para enlazarla más adelante con la tarifa de otro programa (por ejemplo «PVP1»); de momento solo se guarda.</p>
<form method="post" action="<?= e(url('/admin/configuracion/tarifas')) ?>" class="cfgf" autocomplete="off">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Tarifas</legend>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th scope="col">Nº</th><th scope="col">Nombre</th><th scope="col">Referencia externa</th><th scope="col">Activa</th><th scope="col">Clientes</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int)$r['id']; $in = $old['t'][$id] ?? null; ?>
        <tr>
          <td><strong><?= (int)$r['num'] ?></strong></td>
          <td><input name="t[<?= $id ?>][name]" value="<?= e($in['name'] ?? $r['name']) ?>" maxlength="40" aria-label="Nombre"></td>
          <td><input name="t[<?= $id ?>][ext]" value="<?= e($in['ext'] ?? $r['ext_ref']) ?>" maxlength="40" placeholder="Ej.: PVP<?= (int)$r['num'] ?>" aria-label="Referencia externa"></td>
          <td><input type="checkbox" name="t[<?= $id ?>][active]" value="1"<?= ($in !== null ? !empty($in['active']) : (int)$r['is_active'] === 1) ? ' checked' : '' ?> aria-label="Activa"></td>
          <td><small class="muted"><?= (int)($users[(int)$r['num']] ?? 0) ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <small>Las tarifas no se borran (los clientes guardan su número): se desactivan. Una tarifa que usan clientes o que es la de por defecto no se puede desactivar.</small>
  </fieldset>
  <fieldset>
    <legend>Nueva tarifa</legend>
    <div class="g3">
      <label>Nombre <input name="new[name]" value="<?= e((string)($old['new']['name'] ?? '')) ?>" maxlength="40" placeholder="Ej.: Mayoristas"></label>
      <label>Referencia externa (opcional) <input name="new[ext]" value="<?= e((string)($old['new']['ext'] ?? '')) ?>" maxlength="40"></label>
    </div>
    <small>Se numera sola (la siguiente) y se crea al pulsar Guardar. Como mucho 9.</small>
  </fieldset>
  <div class="savebar"><button class="btn" type="submit">Guardar</button></div>
</form>
