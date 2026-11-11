<div class="head"><h1>Clientes y Ventas</h1></div>
<p class="alert warn"><?= admin_icon('alert') ?><span><strong>Hace falta la doble verificación.</strong> Los clientes y las facturas son datos personales. Para verlos tienes que activar primero la doble verificación en tu cuenta (un código de tu móvil además de la contraseña).</span></p>
<p><a class="btn" href="<?= e(url('/admin/clave#doble')) ?>">Activar la doble verificación en Mi cuenta</a></p>
<?php if (is_admin()): ?><p class="muted">Como administrador puedes cambiar esta exigencia en <a href="<?= e(url('/admin/usuarios#erp')) ?>">Configuración → Usuarios y seguridad</a>.</p><?php endif; ?>
