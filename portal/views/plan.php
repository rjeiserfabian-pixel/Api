<h1>Mi plan</h1>
<?php
$cuota = $uso['plan']['cuota_mensual'];
function limiteTxt($v)
{
    return $v === null ? 'sin límite' : (string) $v;
}
?>
<div class="tarjeta">
  <div class="etiqueta">Plan actual</div>
  <div class="cifra"><?= h($uso['plan']['nombre'] === 'sin_plan' ? 'Sin plan' : $uso['plan']['nombre']) ?></div>
  <div class="ayuda">Este mes (<?= h($uso['periodo']) ?>) usó <?= h($uso['documentos_usados']) ?><?= $cuota === null ? ' documentos (cuota ilimitada)' : ' de ' . h($cuota) ?> en producción.</div>
</div>

<?php if ($pendiente): ?>
  <div class="aviso info">
    Tiene una solicitud pendiente para el plan <strong><?= h($pendiente['plan']) ?></strong> (enviada el <?= h($pendiente['creado']) ?>). El administrador del servicio la revisará.
    <?php if ($esAdmin): ?>
    <form class="linea" method="post" action="<?= h(Vista::url('/plan/cancelar')) ?>">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <button class="suave chico" type="submit">Cancelar solicitud</button>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<h2>Planes disponibles</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Plan</th><th>Documentos al mes</th><th>Emisiones/min</th><th>Peticiones/min</th><th>Webhooks</th><th>Activación</th><th></th></tr>
  <?php foreach ($planes as $p): $actual = (int) $p['id'] === $planActualId; ?>
    <tr>
      <td><strong><?= h($p['nombre']) ?></strong><?= $actual ? ' <span class="badge ok">su plan</span>' : '' ?></td>
      <td><?= $p['cuota_mensual'] === null ? 'ilimitados' : h($p['cuota_mensual']) ?></td>
      <td><?= h(limiteTxt($p['limite_sunat_min'])) ?></td>
      <td><?= h(limiteTxt($p['limite_general_min'])) ?></td>
      <td><?= h($p['max_webhooks']) ?></td>
      <td><?= (int) $p['autoservicio'] === 1 ? '<span class="badge ok">inmediata</span>' : '<span class="badge espera">con aprobación</span>' ?></td>
      <td>
        <?php if ($esAdmin && !$actual): ?>
        <form method="post" action="<?= h(Vista::url('/plan/solicitar')) ?>" data-confirmar="¿Cambiar al plan <?= h($p['nombre']) ?>?">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
          <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
          <button class="chico" type="submit"><?= (int) $p['autoservicio'] === 1 ? 'Cambiar' : 'Solicitar' ?></button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$planes): ?><tr><td colspan="7" class="ayuda">No hay planes publicados por ahora.</td></tr><?php endif; ?>
</table>
</div>
<p class="ayuda">Los documentos de pruebas (llaves <code>sk_test_</code>), las consultas y los rechazos de SUNAT no consumen cuota. Los planes “con aprobación” los activa el administrador del servicio.</p>
<?php if (!$esAdmin): ?><p class="ayuda">Su rol es de solo lectura: solo un administrador de su empresa puede pedir un cambio de plan.</p><?php endif; ?>
