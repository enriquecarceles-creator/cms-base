<?php
/** Alta / edición manual de un cliente del CMS. Variables: $v, $isNew, $tariffs, $methods, $errors, $nifWarn. */
$back = $isNew ? '/admin/clientes' : '/admin/clientes/' . rawurlencode((string)$v['cust_code']);
$f = fn(string $k, string $label, int $max, string $extra = '') => '<label>' . e($label) . ' <input name="' . e($k) . '" value="' . e((string)$v[$k]) . '" maxlength="' . $max . '"' . $extra . '></label>';
?>
<div class="head"><h1><?= $isNew ? 'Nuevo cliente' : 'Modificar cliente ' . e((string)$v['cust_code']) ?></h1><a class="btn ghost" href="<?= e(url($back)) ?>">Volver</a></div>
<?php foreach ($errors as $er): ?><p class="flash err"><?= e($er) ?></p><?php endforeach; ?>
<?php if ($nifWarn !== '' && !$isNew && !$errors): ?><p class="alert warn keep"><?= admin_icon('alert') ?><span><?= e($nifWarn) ?></span></p><?php endif; ?>
<form method="post" action="<?= e(url($isNew ? '/admin/clientes/nuevo' : $back . '/modificar')) ?>" class="cfgf" autocomplete="off">
  <?= csrf_field() ?>
  <fieldset>
    <legend>Datos</legend>
    <div class="g3">
      <label>Código <input name="cust_code" value="<?= e((string)$v['cust_code']) ?>" maxlength="40" required<?= $isNew ? '' : ' readonly' ?>><?php if ($isNew): ?><small>El siguiente libre. Si lo cambias, con una letra delante (los solo números son de NCS).</small><?php endif; ?></label>
      <?= $f('name', 'Nombre o razón social', 255, ' required') ?>
      <?= $f('trade_name', 'Nombre comercial', 255) ?>
      <label>NIF <input name="nif" value="<?= e((string)$v['nif']) ?>" maxlength="40"><small>DNI, NIE o CIF. Si el formato no cuadra, solo se avisa.</small></label>
      <?= $f('contact', 'Persona de contacto', 255) ?>
      <?= $f('email', 'Email', 190, ' type="email"') ?>
      <?= $f('phone', 'Teléfono', 40) ?>
      <?= $f('phone2', 'Teléfono 2', 40) ?>
    </div>
    <div class="g4">
      <?= $f('address', 'Dirección', 255) ?>
      <?= $f('postcode', 'Código postal', 20) ?>
      <?= $f('city', 'Población', 255) ?>
      <?= $f('province', 'Provincia', 255) ?>
      <?= $f('country', 'País', 255) ?>
    </div>
  </fieldset>
  <fieldset>
    <legend>Venta</legend>
    <div class="g4">
      <label>Tarifa <select name="tariff"><option value="0">—</option><?php foreach ($tariffs as $t): ?><option value="<?= (int)$t['num'] ?>"<?= (int)$v['tariff'] === (int)$t['num'] ? ' selected' : '' ?>><?= (int)$t['num'] ?> · <?= e($t['name']) ?></option><?php endforeach; ?></select></label>
      <label>Forma de pago <select name="pay_code"><option value="">—</option><?php foreach ($methods as $m): ?><option value="<?= e($m['code']) ?>"<?= (string)$v['pay_code'] === (string)$m['code'] ? ' selected' : '' ?>><?= e($m['code']) ?> · <?= e($m['name'] !== '' ? $m['name'] : 'sin nombre') ?></option><?php endforeach; ?></select></label>
      <label>Día de pago <input name="pay_days" value="<?= e((string)($v['pay_days'] ?? '')) ?>" maxlength="20" placeholder="Ej.: 5 o 5,25"><small>Opcional. Los vencimientos se pasan al siguiente de esos días.</small></label>
      <label>Descuento habitual (%) <input name="disc" value="<?= e((string)$v['disc_txt']) ?>" maxlength="6" inputmode="decimal"></label>
      <div>
        <label class="check"><input type="checkbox" name="blocked" value="1"<?= (int)$v['blocked'] === 1 ? ' checked' : '' ?>> <span>Bloqueado</span></label>
        <label class="check"><input type="checkbox" name="ended" value="1"<?= (int)$v['ended_day'] > 0 ? ' checked' : '' ?>> <span>De baja</span></label>
      </div>
    </div>
  </fieldset>
  <div class="savebar"><button class="btn" type="submit">Guardar</button></div>
</form>
