<?php
/** Datos → Historial. Variables: $rows, $kind, $user, $users, $total, $page, $pages. */
$q = function (array $c = []) use ($kind, $user): string {
    $p = array_filter(array_merge(['tipo' => $kind, 'usuario' => $user], $c), fn($v) => $v !== '' && $v !== null);
    return '/admin/historial' . ($p ? '?' . http_build_query($p) : '');
};
?>
<div class="head"><h1>Historial</h1></div>
<p class="hint">Cada importación, sincronización y exportación hecha desde el panel: quién, cuándo, qué, desde dónde y cómo terminó. Solo lleva números, nunca nombres ni datos de clientes. Se anota desde ahora; lo anterior no está.</p>
<form class="filters lf" method="get" action="<?= e(url('/admin/historial')) ?>">
  <label><span class="sr-only">Tipo</span><select name="tipo"><option value="">Todos los tipos</option><?php foreach (HISTORY_KINDS as $k => $l): ?><option value="<?= e($k) ?>"<?= $kind === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
  <label><span class="sr-only">Usuario</span><select name="usuario"><option value="">Todos los usuarios</option><?php foreach ($users as $u): ?><option<?= $user === $u ? ' selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?></select></label>
  <button class="btn small" type="submit">Filtrar</button>
  <?php if ($kind !== '' || $user !== ''): ?><a class="lf-clear" href="<?= e(url('/admin/historial')) ?>">Quitar filtros</a><?php endif; ?>
</form>
<?php if (!$rows): ?>
  <p class="empty">Todavía no hay nada anotado<?= ($kind !== '' || $user !== '') ? ' con ese filtro' : '' ?>. Aquí saldrá cada importación, sincronización o exportación que hagas.</p>
<?php else: ?>
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th scope="col">Fecha</th><th scope="col">Usuario</th><th scope="col">Tipo</th><th scope="col">Qué</th><th scope="col">Origen</th><th scope="col">Resultado</th><th scope="col">Detalle</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e(date('d/m/Y H:i', (int)$r['created_at'])) ?></td>
        <td><?= e($r['username'] !== '' ? $r['username'] : '—') ?></td>
        <td><?= e(HISTORY_KINDS[$r['kind']] ?? $r['kind']) ?></td>
        <td><?= e(HISTORY_WHATS[$r['what']] ?? $r['what']) ?></td>
        <td><?= e($r['origin']) ?></td>
        <td><?php $cls = $r['result'] === 'ok' ? 'tag tag-ok' : ($r['result'] === 'error' ? 'tag tag-off' : 'tag'); ?><span class="<?= $cls ?>"><?= e(HISTORY_RESULTS[$r['result']] ?? $r['result']) ?></span></td>
        <td><?= e($r['detail']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <nav class="pager" aria-label="Páginas"><span class="muted"><?= (int)$total ?> anotaciones · página <?= (int)$page ?> de <?= (int)$pages ?></span>
    <?php if ($pages > 1): ?><span class="pager-l">
      <?php if ($page > 1): ?><a href="<?= e(url($q(['pag' => (string)($page - 1)]))) ?>" rel="prev">‹ Anterior</a><?php endif; ?>
      <?php if ($page < $pages): ?><a href="<?= e(url($q(['pag' => (string)($page + 1)]))) ?>" rel="next">Siguiente ›</a><?php endif; ?>
    </span><?php endif; ?>
  </nav>
<?php endif; ?>
