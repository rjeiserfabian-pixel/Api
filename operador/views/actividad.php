<h1>Actividad (todas las empresas)</h1>
<p class="ayuda">Últimos 150 eventos: accesos de clientes y del operador, cambios de plan, llaves, certificados, credenciales y bloqueos.</p>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Empresa</th><th>Quién</th><th>Acción</th><th>Detalle</th><th>IP</th></tr>
  <?php foreach ($eventos as $a): ?>
    <tr>
      <td class="mono"><?= h($a['creado']) ?></td>
      <td><?= $a['ruc'] ? h($a['ruc']) . ' ' . h($a['razon_social']) : '<span class="ayuda">(operador)</span>' ?></td>
      <td><?= h($a['email'] ? $a['email'] : '—') ?></td>
      <td><span class="badge info"><?= h($a['accion']) ?></span></td>
      <td><?= h($a['detalle']) ?></td>
      <td class="mono"><?= h($a['ip']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$eventos): ?><tr><td colspan="6" class="ayuda">Sin actividad.</td></tr><?php endif; ?>
</table>
</div>
