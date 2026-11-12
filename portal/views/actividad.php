<h1>Actividad reciente</h1>
<p class="ayuda">Registro de accesos y cambios sensibles de su empresa (últimos 50 eventos).</p>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th><th>IP</th></tr>
  <?php foreach ($eventos as $e): ?>
    <tr>
      <td class="mono"><?= h($e['creado']) ?></td>
      <td><?= h($e['email'] ? $e['email'] : '(sistema)') ?></td>
      <td><span class="badge <?= (strpos($e['accion'], 'fallid') !== false || strpos($e['accion'], 'bloque') !== false) ? 'mal' : 'info' ?>"><?= h($e['accion']) ?></span></td>
      <td><?= h($e['detalle']) ?></td>
      <td class="mono"><?= h($e['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$eventos): ?><tr><td colspan="5" class="ayuda">Sin actividad registrada.</td></tr><?php endif; ?>
</table>
</div>
