<h1>Resumen</h1>
<?php if (!empty($aviso)): ?>
  <div class="aviso <?= $aviso['tipo'] === 'alerta' ? 'error' : 'info' ?>"><?= h($aviso['texto']) ?> <a href="<?= h(Vista::url('/plan')) ?>">Ver mi plan</a></div>
<?php endif; ?>
<?php
$cuota = $uso['plan']['cuota_mensual'];
$pct = ($cuota !== null && $cuota > 0) ? min(100, (int) round(100 * $uso['documentos_usados'] / $cuota)) : 0;
$claseBarra = $pct >= 100 ? 'critico' : ($pct >= 80 ? 'alerta' : '');
?>
<div class="cuadricula">
  <div class="tarjeta">
    <div class="etiqueta">Plan</div>
    <div class="cifra"><?= h($uso['plan']['nombre'] === 'sin_plan' ? 'Sin plan' : $uso['plan']['nombre']) ?></div>
    <div class="ayuda">Emisiones/min: <?= h($uso['plan']['limite_emisiones_por_minuto'] === null ? 'sin límite' : $uso['plan']['limite_emisiones_por_minuto']) ?> ·
      Peticiones/min: <?= h($uso['plan']['limite_peticiones_por_minuto'] === null ? 'sin límite' : $uso['plan']['limite_peticiones_por_minuto']) ?>
      · <a href="<?= h(Vista::url('/plan')) ?>">Cambiar de plan</a></div>
  </div>
  <div class="tarjeta">
    <div class="etiqueta">Cuota de <?= h($uso['periodo']) ?> (producción)</div>
    <div class="cifra"><?= h($uso['documentos_usados']) ?><?= $cuota === null ? '' : ' / ' . h($cuota) ?></div>
    <?php if ($cuota !== null && $cuota > 0): ?>
      <progress class="barra-uso <?= $claseBarra ?>" max="100" value="<?= (int) $pct ?>"><?= (int) $pct ?>%</progress>
      <div class="ayuda"><?= h($uso['documentos_restantes']) ?> restantes · se reinicia el <?= h(date('d/m/Y', strtotime($uso['reinicia']))) ?></div>
    <?php elseif ($cuota === 0): ?>
      <div class="ayuda">Su plan no incluye emisión en producción.</div>
    <?php else: ?>
      <div class="ayuda">Cuota ilimitada.</div>
    <?php endif; ?>
    <?php if ($entorno === 'test'): ?><div class="ayuda">Las pruebas no consumen cuota.</div><?php endif; ?>
  </div>
  <div class="tarjeta">
    <div class="etiqueta">Certificado (<?= $entorno === 'live' ? 'producción' : 'pruebas' ?>)</div>
    <?php if ($cert): ?>
      <div class="cifra"><?= $cert['vigente'] ? h($cert['dias_restantes']) . ' días' : 'Vencido' ?></div>
      <div class="ayuda">Vence el <?= h($cert['valido_hasta']) ?><?= !empty($cert['por_vencer']) ? ' · ¡por vencer!' : '' ?></div>
    <?php else: ?>
      <div class="cifra">—</div>
      <div class="ayuda"><?= $entorno === 'live' ? 'Falta el certificado de producción: sin él no se puede emitir.' : 'Se usa un certificado compartido de pruebas.' ?>
        <a href="<?= h(Vista::url('/certificado')) ?>">Registrar</a></div>
    <?php endif; ?>
  </div>
  <div class="tarjeta">
    <div class="etiqueta">Integración</div>
    <div class="cifra"><?= (int) $nLlaves ?> <span class="ayuda">llaves activas</span></div>
    <div class="ayuda"><?= (int) $nWebhooks ?> webhooks · <a href="<?= h(Vista::url('/llaves')) ?>">Llaves</a> · <a href="<?= h(Vista::url('/webhooks')) ?>">Webhooks</a></div>
  </div>
</div>

<h2>Últimos 30 días (<?= $entorno === 'live' ? 'producción' : 'pruebas' ?>)</h2>
<div class="tarjeta">
  <?php if (!$conteos): ?><span class="ayuda">Aún no hay documentos en este entorno.</span><?php endif; ?>
  <?php foreach ($conteos as $estado => $n): ?>
    <span class="badge <?= h(claseEstado($estado)) ?>"><?= h($estado) ?>: <?= (int) $n ?></span>
  <?php endforeach; ?>
</div>

<h2>Documentos recientes</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Documento</th><th>Estado</th><th>SUNAT</th><th></th></tr>
  <?php foreach ($recientes as $d): ?>
    <tr>
      <td class="mono"><?= h($d['creado']) ?></td>
      <td><?= h(nombreTipo($d['tipo'])) ?> <strong><?= h($d['serie']) ?>-<?= h($d['numero']) ?></strong></td>
      <td><span class="badge <?= h(claseEstado($d['estado'])) ?>"><?= h($d['estado']) ?></span></td>
      <td><?= h(mb_substr((string) $d['sunat']['descripcion'], 0, 70, 'UTF-8')) ?></td>
      <td><a href="<?= h(Vista::url('/documentos/' . $d['id'])) ?>">Ver</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$recientes): ?><tr><td colspan="5" class="ayuda">Sin documentos todavía.</td></tr><?php endif; ?>
</table>
</div>
