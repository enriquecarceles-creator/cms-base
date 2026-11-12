<?php
/** Configuración → IVA. Variables: $rows (doc_vat_rates), $errors, $old. */
$old = $old ?? null;
$chk = fn(string $k) => $old !== null ? !empty($old[$k]) : docp_bool($k);
?>
<div class="head"><h1>IVA</h1></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<p class="hint">Tipos de IVA que se pueden usar en los documentos y cómo se tratan los precios. Un tipo sin uso se puede borrar; uno ya usado, solo desactivar. Siempre hay uno por defecto.</p>
<form method="post" action="<?= e(url('/admin/configuracion/iva')) ?>" class="cfgf" autocomplete="off">
  <?= csrf_field() ?>

  <fieldset>
    <legend>Tipos de IVA</legend>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th scope="col">Nombre</th><th scope="col">IVA %</th><th scope="col">Recargo de equivalencia %</th><th scope="col">Por defecto</th><th scope="col">Activo</th><th scope="col"></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $id = (int)$r['id']; $in = $old['v'][$id] ?? null; ?>
        <tr>
          <td><input name="v[<?= $id ?>][name]" value="<?= e($in['name'] ?? $r['name']) ?>" maxlength="60" aria-label="Nombre"></td>
          <td><input class="num" name="v[<?= $id ?>][pct]" value="<?= e($in['pct'] ?? vat_format_pct((int)$r['pct_bp'])) ?>" inputmode="decimal" aria-label="IVA %"></td>
          <td><input class="num" name="v[<?= $id ?>][sur]" value="<?= e($in['sur'] ?? vat_format_pct((int)$r['surcharge_bp'])) ?>" inputmode="decimal" aria-label="Recargo %"></td>
          <td><input type="radio" name="default" value="<?= $id ?>"<?= ($old !== null ? (int)($old['default'] ?? 0) === $id : (int)$r['is_default'] === 1) ? ' checked' : '' ?> aria-label="Por defecto"></td>
          <td><input type="checkbox" name="v[<?= $id ?>][active]" value="1"<?= ($in !== null ? !empty($in['active']) : (int)$r['is_active'] === 1) ? ' checked' : '' ?> aria-label="Activo"></td>
          <td><?php if (count($rows) > 1 && !vat_is_used($id)): ?><button class="link danger" type="submit" name="action" value="delete" onclick="document.getElementById('del-id').value=<?= $id ?>;return confirm('¿Borrar este tipo de IVA?')">Borrar</button><?php else: ?><small class="muted">en uso</small><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <input type="hidden" name="id" id="del-id" value="">
    <small>El recargo de equivalencia es el que se añade a clientes en ese régimen (en España 5,2 / 1,4 / 0,5). Déjalo a 0 si no lo usas.</small>
  </fieldset>

  <fieldset>
    <legend>Nuevo tipo de IVA</legend>
    <div class="g3">
      <label>Nombre <input name="new[name]" value="<?= e((string)($old['new']['name'] ?? '')) ?>" maxlength="60" placeholder="Ej.: Especial (7 %)"></label>
      <label>IVA % <input name="new[pct]" value="<?= e((string)($old['new']['pct'] ?? '')) ?>" inputmode="decimal" placeholder="7"></label>
      <label>Recargo % (opcional) <input name="new[sur]" value="<?= e((string)($old['new']['sur'] ?? '')) ?>" inputmode="decimal" placeholder="0"></label>
    </div>
  </fieldset>

  <fieldset>
    <legend>Régimen</legend>
    <label class="check"><input type="checkbox" name="vat_incl" value="1"<?= $chk('vat_incl') ? ' checked' : '' ?>> <span><b>Precios con IVA incluido</b> — los precios que se escriben y se ven ya llevan el IVA (como en un ticket de tienda). Si no, el IVA se suma al total.</span></label>
    <label class="check"><input type="checkbox" name="cash_criterion" value="1"<?= $chk('cash_criterion') ? ' checked' : '' ?>> <span><b>Criterio de caja</b> — solo si la empresa está acogida a este régimen especial (el IVA se declara al cobrar, no al facturar). Si dudas, consúltalo con tu gestor.</span></label>
  </fieldset>

  <div class="savebar"><button class="btn" type="submit" onclick="document.getElementById('del-id').value=''">Guardar</button></div>
</form>
