<?php
/** Datos → Perfiles. Variables: $rows (perfiles), $created. */
?>
<div class="head"><h1>Perfiles de importación</h1></div>
<p class="hint">Un perfil recuerda qué columna de otra aplicación es cada cosa (referencia, nombre, familia, precio…) para no tener que emparejarlas cada vez. Los de <strong>sistema</strong> vienen con el programa y no se pueden cambiar ni borrar, pero se pueden <strong>duplicar</strong>. Los propios los creas tú al importar con «Guardar como perfil».</p>
<?php if (!$rows): ?>
  <p class="empty">No hay perfiles.</p>
<?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th scope="col">Nombre</th><th scope="col">Origen</th><th scope="col">Para</th><th scope="col">Tipo</th><th scope="col">Creado</th><th scope="col"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr>
        <td><a href="<?= e(url('/admin/perfiles/' . (int)$p['id'])) ?>"><?= e($p['name']) ?></a></td>
        <td><?= e(PROFILE_SOURCE_LABELS[$p['source_type']] ?? $p['source_type']) ?></td>
        <td><?= e(PROFILE_MODULE_LABELS[$p['target_module']] ?? $p['target_module']) ?></td>
        <td><?= $p['is_system'] === 1 ? '<span class="tag">Sistema</span>' : '<span class="tag tag-label">Propio</span>' ?></td>
        <td><?= e(isset($created[$p['id']]) && $created[$p['id']] !== '' ? date('d/m/Y', (int)strtotime($created[$p['id']])) : '—') ?></td>
        <td>
          <a class="btn small ghost" href="<?= e(url('/admin/perfiles/' . (int)$p['id'])) ?>">Ver</a>
          <form method="post" action="<?= e(url('/admin/perfiles')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="link" type="submit">Duplicar</button></form>
          <?php if ($p['is_system'] === 0): ?><a class="link danger" href="<?= e(url('/admin/perfiles/' . (int)$p['id'] . '?borrar=1')) ?>">Borrar</a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
