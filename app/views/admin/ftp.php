<div class="head"><h1>Fotos subidas por FTP</h1></div>
<p class="hint">Aquí aparecen las fotos (JPG, PNG o WebP) que has copiado a la carpeta <code>uploads</code> (o <code>uploads/entrada</code>) por FTP y que aún no están en ningún artículo. La web compara cada foto con las que ya hay y propone el artículo: a la izquierda tu foto y a su lado la foto actual del artículo, para que veas a simple vista si es la misma. No necesitas saber el nombre y pulsa <strong>Enlazar</strong>: se optimizan, se crea la miniatura y se guarda tu archivo original en <code>uploads/orig/ftp</code>.</p>

<?php if (!$running && $broken > 0): ?>
  <div class="flash err" style="margin-bottom:16px">
    <strong><?= (int)$broken ?> foto<?= $broken === 1 ? '' : 's' ?> de la web apuntan a un archivo que ya no existe</strong> (se ven rotas). Orden recomendado: 1) enlaza aquí abajo tus fotos retocadas con el mismo nombre (arregla las que coinciden); 2) pulsa este botón para quitar las que sigan rotas; 3) vuelve a traerlas desde Importar (CSV + XML).
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><button class="btn small" name="action" value="repair" type="submit" onclick="return confirm('¿Quitar las fotos rotas? Las que no estén rotas no se tocan.')">Quitar las fotos rotas</button></form>
  </div>
<?php endif; ?>
<?php if ($running): ?>
  <meta http-equiv="refresh" content="1">
  <p class="flash ok">Enlazando fotos… <?= (int)$done ?> hechas, quedan <?= (int)$left ?>. La página se actualiza sola; no la cierres.</p>
  <form method="post"><?= csrf_field() ?><button class="btn small ghost" name="action" value="stop" type="submit">Parar</button></form>
<?php elseif (!$rows): ?>
  <p class="empty">No hay fotos pendientes. Copia tus fotos a la carpeta <code>uploads</code> por FTP y recarga esta página.</p>
<?php else: ?>
<?php if ($exact > 0): ?>
  <div class="flash ok" style="margin-bottom:16px">
    <strong><?= (int)$exact ?> foto<?= $exact === 1 ? '' : 's' ?></strong> tienen el <strong>mismo nombre</strong> que una foto de la web: son las retocadas.
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><button class="btn" name="action" value="auto" type="submit" onclick="return confirm('¿Sustituir esas fotos por las retocadas?')">Sustituir automáticamente esas <?= (int)$exact ?></button></form>
  </div>
<?php endif; ?>
<form method="post" class="form">
  <?= csrf_field() ?>
  <p>
    <label class="check"><input type="radio" name="mode" value="replace" checked> <strong>Sustituir</strong> las fotos antiguas del artículo por estas</label>
    <label class="check"><input type="radio" name="mode" value="add"> <strong>Añadir</strong> estas y dejar las antiguas (la primera pasa a ser portada)</label>
  </p>
  <p><small><?= count($rows) ?> foto<?= count($rows) === 1 ? '' : 's' ?> · sin artículo elegido se ignoran. Si varias fotos van al mismo artículo, se ordenan por nombre y la primera es la portada.</small></p>
  <table class="tbl">
    <thead><tr><th>Tu foto</th><th>Foto actual del artículo</th><th>Artículo</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $key = $r['dir'] . $r['name']; ?>
      <tr>
        <td><img src="<?= e(url('/uploads/' . $key)) ?>" alt="" width="64" height="64" style="object-fit:contain;background:#f7f6f3;border-radius:6px" loading="lazy"></td>
        <td><?php if (!empty($r['cur'])): ?><img src="<?= e(img_url($r['cur'], true)) ?>" alt="" width="64" height="64" style="object-fit:contain;background:#f7f6f3;border-radius:6px" loading="lazy"><?php else: ?><small>—</small><?php endif; ?></td>
        <td>
          <select name="pid[<?= e($key) ?>]">
            <option value="0">— ignorar —</option>
            <?php foreach ($prods as $p): ?>
              <option value="<?= (int)$p['id'] ?>"<?= (int)$r['pid'] === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name'] . ($p['ref'] !== '' ? ' (Ref.' . $p['ref'] . ')' : '')) ?></option>
            <?php endforeach; ?>
          </select>
          <small><?= e($r['name']) ?><?= $r['why'] ? ' · propuesto: ' . e($r['why']) : '' ?></small>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="buttons"><button class="btn" type="submit" onclick="return confirm('¿Enlazar las fotos elegidas?')">Enlazar</button></div>
</form>
<?php endif; ?>
