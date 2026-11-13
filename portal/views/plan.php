<h1>Mi plan</h1>
<?php
$cuota = $uso['plan']['cuota_mensual'];
function limiteTxt($v)
{
    return $v === null ? 'sin límite' : (string) $v;
}
$claseAviso = array('info' => 'info', 'aviso' => 'info', 'alerta' => 'error');
?>
<?php if (!empty($aviso)): ?><div class="aviso <?= h($claseAviso[$aviso['tipo']]) ?>"><?= h($aviso['texto']) ?></div><?php endif; ?>

<div class="tarjeta">
  <div class="etiqueta">Plan actual</div>
  <div class="cifra"><?= h($uso['plan']['nombre'] === 'sin_plan' ? 'Sin plan' : $uso['plan']['nombre']) ?></div>
  <div class="ayuda">Este mes (<?= h($uso['periodo']) ?>) usó <?= h($uso['documentos_usados']) ?><?= $cuota === null ? ' documentos (cuota ilimitada)' : ' de ' . h($cuota) ?> en producción.</div>
  <?php if ($sus): ?>
    <div class="ayuda">Suscripción: <strong><?= h($sus['estado'] === 'prueba' ? 'prueba gratis' : $sus['estado']) ?></strong>
      <?= in_array($sus['estado'], array('activa', 'prueba'), true) ? ' · vence el ' . h(date('d/m/Y', strtotime($sus['vence']))) : '' ?>
      <?= $sus['estado'] === 'gracia' ? ' · renueve hasta el ' . h(date('d/m/Y', strtotime($sus['gracia_hasta']))) : '' ?>
      <?= $sus['estado'] === 'activa' ? ' · período ' . h($sus['periodo']) : '' ?></div>
  <?php else: ?>
    <div class="ayuda">Su plan no tiene fecha de vencimiento.</div>
  <?php endif; ?>
</div>

<?php if ($pendiente): ?>
  <div class="aviso info">
    Tiene una solicitud pendiente para el plan <strong><?= h($pendiente['plan']) ?></strong><?= $pendiente['periodo'] ? ' (' . h($pendiente['periodo']) . ')' : '' ?>, enviada el <?= h($pendiente['creado']) ?>. El administrador del servicio la revisará.
    <?php if ($esAdmin): ?>
    <form class="linea" method="post" action="<?= h(Vista::url('/plan/cancelar')) ?>">
      <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
      <button class="suave chico" type="submit">Cancelar solicitud</button>
    </form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($instrucciones !== ''): ?>
  <div class="tarjeta">
    <div class="etiqueta">Cómo pagar</div>
    <p class="sin-margen"><?= nl2br(h($instrucciones)) ?></p>
    <div class="ayuda">Después de pagar, su plan se activa cuando el administrador registra el pago.</div>
  </div>
<?php endif; ?>

<h2>Planes disponibles</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Plan</th><th>Documentos al mes</th><th>Emisiones/min</th><th>Peticiones/min</th><th>Webhooks</th><th>Precio</th><th></th></tr>
  <?php foreach ($planes as $p): $actual = (int) $p['id'] === $planActualId; $pr = Cobros::precios($p); $conPrecio = $pr['mensual'] !== null && $pr['mensual'] > 0; ?>
    <tr>
      <td><strong><?= h($p['nombre']) ?></strong><?= $actual ? ' <span class="badge ok">su plan</span>' : '' ?>
        <?php if ((int) $p['dias_prueba'] > 0 && isset($puedeProbar[(int) $p['id']])): ?><br><span class="badge info"><?= (int) $p['dias_prueba'] ?> días gratis</span><?php endif; ?></td>
      <td><?= $p['cuota_mensual'] === null ? 'ilimitados' : h($p['cuota_mensual']) ?></td>
      <td><?= h(limiteTxt($p['limite_sunat_min'])) ?></td>
      <td><?= h(limiteTxt($p['limite_general_min'])) ?></td>
      <td><?= h($p['max_webhooks']) ?></td>
      <td>
        <?php if ($conPrecio): ?>
          <strong><?= h(Dinero::formato($pr['mensual'])) ?></strong> <span class="ayuda">al mes</span><br>
          <?= h(Dinero::formato($pr['anual'])) ?> <span class="ayuda">al año<?= $pr['ahorro'] > 0 ? ' · ahorra ' . h(Dinero::formato($pr['ahorro'])) . ' (' . (int) $pr['ahorro_pct'] . ' %)' : '' ?></span>
        <?php else: ?>
          <span class="ayuda"><?= trim((string) $p['precio']) !== '' ? h($p['precio']) : ((int) $p['autoservicio'] === 1 ? 'Gratis' : 'Consultar') ?></span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($esAdmin && !$actual): ?>
          <?php if (isset($puedeProbar[(int) $p['id']])): ?>
          <form method="post" action="<?= h(Vista::url('/plan/prueba')) ?>" data-confirmar="¿Iniciar la prueba gratis de <?= (int) $p['dias_prueba'] ?> días del plan <?= h($p['nombre']) ?>? Solo se puede usar una vez.">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
            <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
            <button class="chico" type="submit">Probar gratis</button>
          </form>
          <?php endif; ?>
          <form method="post" action="<?= h(Vista::url('/plan/solicitar')) ?>" data-confirmar="¿Cambiar al plan <?= h($p['nombre']) ?>?">
            <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="plan_id" value="<?= (int) $p['id'] ?>">
            <?php if ($conPrecio): ?>
              <select name="periodo" aria-label="Período"><option value="mensual">Mensual</option><option value="anual">Anual</option></select>
            <?php endif; ?>
            <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
            <button class="<?= isset($puedeProbar[(int) $p['id']]) ? 'suave ' : '' ?>chico" type="submit"><?= (int) $p['autoservicio'] === 1 ? 'Cambiar' : 'Solicitar' ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$planes): ?><tr><td colspan="7" class="ayuda">No hay planes publicados por ahora.</td></tr><?php endif; ?>
</table>
</div>
<p class="ayuda">Los documentos de pruebas (llaves <code>sk_test_</code>), las consultas y los rechazos de SUNAT no consumen cuota. Los planes “Solicitar” los activa el administrador del servicio al confirmar el pago. La prueba gratis se puede usar una sola vez por empresa.</p>
<?php if (!$esAdmin): ?><p class="ayuda">Su rol es de solo lectura: solo un administrador de su empresa puede pedir un cambio de plan.</p><?php endif; ?>
