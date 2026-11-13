<h1>Historial de pagos</h1>
<div class="tarjeta">
  <div class="etiqueta">Cobrado en <?= h($mes) ?></div>
  <div class="cifra"><?= h(Dinero::formato($totalMes)) ?></div>
  <div class="ayuda">Suma de los pagos registrados este mes (todas las empresas). <a href="<?= h(Vista::url('/cobros')) ?>">Volver a Cobros</a></div>
</div>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Empresa</th><th>Plan</th><th>Período</th><th>Monto</th><th>Método</th><th>Referencia</th><th>Vence</th><th>Registró</th></tr>
  <?php foreach ($pagos as $g): ?>
    <tr>
      <td class="mono"><?= h($g['creado']) ?></td>
      <td><a href="<?= h(Vista::url('/empresas/' . (int) $g['empresa_id'])) ?>"><?= h($g['ruc']) ?> · <?= h($g['razon_social']) ?></a></td>
      <td><?= h($g['plan']) ?></td>
      <td><?= h($g['periodo']) ?></td>
      <td><strong><?= h(Dinero::formato($g['monto'])) ?></strong></td>
      <td><?= h(isset(Cobros::$metodos[$g['metodo']]) ? Cobros::$metodos[$g['metodo']] : $g['metodo']) ?></td>
      <td class="mono"><?= h($g['referencia']) ?><?= $g['nota'] ? '<br><span class="ayuda">' . h($g['nota']) . '</span>' : '' ?></td>
      <td class="mono"><?= h(substr((string) $g['vence_resultante'], 0, 10)) ?></td>
      <td><?= h($g['registrado_por']) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$pagos): ?><tr><td colspan="9" class="ayuda">Aún no hay pagos registrados.</td></tr><?php endif; ?>
</table>
</div>
