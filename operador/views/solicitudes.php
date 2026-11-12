<h1>Solicitudes de cambio de plan</h1>
<h2>Pendientes</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Empresa</th><th>Plan actual</th><th>Plan pedido</th><th>Pedido por</th><th>Nota</th><th></th></tr>
  <?php foreach ($pendientes as $s): ?>
    <tr>
      <td class="mono"><?= h($s['creado']) ?></td>
      <td><a href="<?= h(Vista::url('/empresas/' . (int) $s['empresa_id'])) ?>"><?= h($s['ruc']) ?> · <?= h($s['razon_social']) ?></a></td>
      <td><?= h($s['plan_anterior'] ? $s['plan_anterior'] : 'sin plan') ?></td>
      <td><strong><?= h($s['plan']) ?></strong></td>
      <td><?= h($s['usuario']) ?></td>
      <td><?= h($s['nota']) ?></td>
      <td>
        <form method="post" action="<?= h(Vista::url('/solicitudes/resolver')) ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
          <button class="chico" type="submit" name="accion" value="aprobar">Aprobar y activar</button>
          <button class="suave chico" type="submit" name="accion" value="rechazar">Rechazar</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$pendientes): ?><tr><td colspan="7" class="ayuda">No hay solicitudes pendientes.</td></tr><?php endif; ?>
</table>
</div>
<p class="ayuda">Aprobar asigna el plan al instante. Cuando conecte el cobro automático, un pago confirmado hará esto mismo sin su intervención.</p>
<h2>Historial reciente</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Empresa</th><th>Plan</th><th>Resultado</th><th>Resuelto por</th></tr>
  <?php foreach ($historial as $s): $c = array('aprobada' => 'ok', 'automatica' => 'ok', 'rechazada' => 'mal', 'cancelada' => 'info'); ?>
    <tr>
      <td class="mono"><?= h($s['resuelto'] ? $s['resuelto'] : $s['creado']) ?></td>
      <td><?= h($s['ruc']) ?> · <?= h($s['razon_social']) ?></td>
      <td><?= h($s['plan']) ?></td>
      <td><span class="badge <?= h(isset($c[$s['estado']]) ? $c[$s['estado']] : 'info') ?>"><?= h($s['estado']) ?></span></td>
      <td><?= h($s['resuelto_por']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$historial): ?><tr><td colspan="5" class="ayuda">Sin historial todavía.</td></tr><?php endif; ?>
</table>
</div>
