<?php $scope = $scope ?? 'Clientes y Ventas'; $anchor = $anchor ?? 'erp'; ?>
<div class="head"><h1><?= e($scope) ?></h1></div>
<p class="alert warn"><?= admin_icon('alert') ?><span><strong>Hace falta la doble verificación.</strong> Los datos de <?= e(mb_strtolower($scope)) ?> son datos personales y económicos. Para verlos tienes que activar primero la doble verificación en tu cuenta (un código de tu móvil además de la contraseña).</span></p>
<p><a class="btn" href="<?= e(url('/admin/clave#doble')) ?>">Activar la doble verificación en Mi cuenta</a></p>
<?php if (is_admin()): ?><p class="muted">Como administrador puedes cambiar esta exigencia en <a href="<?= e(url('/admin/configuracion/usuarios#<?= e($anchor) ?>')) ?>">Configuración → Usuarios y seguridad</a>.</p><?php endif; ?>
