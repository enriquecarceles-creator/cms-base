<?php
/** Alta de un cliente del CMS (compacta, misma rejilla que la ficha). Variables: $v, $tariffs, $methods, $series, $errors, $nifWarn. */
$in = fn(string $k, string $label, int $span, int $max, string $extra = '') => '<div class="cfld s' . $span . (in_array($k, ['cust_code', 'name', 'nif'], true) ? ' bold' : '') . '"><label class="cl" for="n-' . e($k) . '">' . e($label) . '</label><input id="n-' . e($k) . '" name="' . e($k) . '" value="' . e((string)$v[$k]) . '" maxlength="' . $max . '"' . $extra . '></div>';
?>
<div class="head"><h1>Nuevo cliente</h1><a class="btn ghost" href="<?= e(url('/admin/clientes')) ?>">Volver</a></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<form method="post" action="<?= e(url('/admin/clientes/nuevo')) ?>" class="cfc is-edit" autocomplete="off">
  <?= csrf_field() ?>
  <section class="cc-card"><h2>General</h2>
    <div class="cgrid">
      <?= $in('cust_code', 'Código', 2, 40, ' required') ?>
      <?= $in('name', 'Nombre o razón social', 7, 255, ' required') ?>
      <?= $in('nif', 'NIF', 3, 40) ?>
      <?= $in('trade_name', 'Nombre comercial', 4, 255) ?>
      <?= $in('address', 'Dirección', 8, 255) ?>
      <?= $in('postcode', 'C. postal', 2, 20) ?>
      <?= $in('city', 'Población', 4, 255) ?>
      <?= $in('province', 'Provincia', 3, 255) ?>
      <?= $in('country', 'País', 3, 255) ?>
    </div>
    <p class="cc-note"><small>El código propuesto es el siguiente libre; con letra delante (nunca solo números: esos son los de NCS). DNI, NIE o CIF: si el formato no cuadra, solo se avisa.</small></p>
  </section>
  <section class="cc-card"><h2>Contacto</h2>
    <div class="cgrid">
      <?= $in('phone', 'Teléfono', 3, 40, ' inputmode="tel"') ?>
      <?= $in('phone2', 'Teléfono 2', 3, 40, ' inputmode="tel"') ?>
      <?= $in('email', 'Correo', 3, 190, ' type="email"') ?>
      <?= $in('contact', 'Persona de contacto', 3, 255) ?>
    </div>
  </section>
  <section class="cc-card"><h2>Comercial</h2>
    <div class="cgrid">
      <div class="cfld s2"><label class="cl" for="n-tariff">Tarifa</label><select id="n-tariff" name="tariff"><option value="0">—</option><?php foreach ($tariffs as $t): ?><option value="<?= (int)$t['num'] ?>"<?= (int)$v['tariff'] === (int)$t['num'] ? ' selected' : '' ?>><?= (int)$t['num'] ?> · <?= e($t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="cfld s3"><label class="cl" for="n-pay">Forma de pago</label><select id="n-pay" name="pay_code"><option value="">—</option><?php foreach ($methods as $m): ?><option value="<?= e($m['code']) ?>"<?= (string)$v['pay_code'] === (string)$m['code'] ? ' selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name'] !== '' ? $m['name'] : 'sin nombre') ?></option><?php endforeach; ?></select></div>
      <?= $in('pay_days', 'Día de pago', 2, 20, ' placeholder="Ej.: 5 o 5,25"') ?>
      <div class="cfld s2"><label class="cl" for="n-disc">Descuento %</label><input id="n-disc" name="disc" value="<?= e((string)$v['disc_txt']) ?>" maxlength="6" inputmode="decimal"></div>
      <div class="cfld s3"><label class="cl" for="n-ser">Serie por defecto</label><select id="n-ser" name="def_series"><option value="0">— la de cada tipo —</option><?php foreach ($series as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$v['def_series'] === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['letter']) ?> · <?= e($s['descr']) ?></option><?php endforeach; ?></select></div>
      <div class="cfld s12"><label class="cl" for="n-dn">Notas para documentos <span class="muted">(se proponen en las observaciones de sus documentos nuevos)</span></label><textarea id="n-dn" name="doc_notes" rows="2" maxlength="1000"><?= e((string)$v['doc_notes']) ?></textarea></div>
      <div class="cfld s12"><span class="cl">Estado</span>
        <label class="check"><input type="checkbox" name="blocked" value="1"<?= (int)$v['blocked'] === 1 ? ' checked' : '' ?>> <span>Bloqueado</span></label>
        <label class="check"><input type="checkbox" name="ended" value="1"<?= (int)$v['ended_day'] > 0 ? ' checked' : '' ?>> <span>De baja</span></label></div>
    </div>
  </section>
  <div class="savebar"><button class="btn" type="submit">Crear cliente</button></div>
</form>
