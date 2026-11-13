<h1>Resumen del servicio</h1>
<?php
$w = $worker;
$nombresW = array('activo' => 'Activo', 'demorado' => 'Lento', 'detenido' => 'DETENIDO', 'nunca' => 'Nunca se inició');
?>
<div class="tarjeta">
  <div class="etiqueta">Worker (reintentos y avisos en segundo plano)</div>
  <div class="cifra"><span class="badge <?= $w['estado'] === 'activo' ? 'ok' : ($w['estado'] === 'demorado' ? 'espera' : 'mal') ?>"><?= h($nombresW[$w['estado']]) ?></span></div>
  <div class="ayuda">Último latido: <?= h($w['ultimo'] ? $w['ultimo'] . ' (' . Salud::edadTexto($w['edad']) . ')' : 'nunca') ?></div>
  <?php if ($w['estado'] === 'detenido' || $w['estado'] === 'nunca'): ?>
    <div class="aviso error">
      El worker no está corriendo: los documentos con error <strong>no se reintentan</strong> y los webhooks <strong>no se envían</strong>.
      Inícielo con <code>php bin/worker.php</code> (en producción como tarea programada o servicio; ver la guía, sección 7).
    </div>
  <?php elseif ($w['estado'] === 'demorado'): ?>
    <div class="aviso info">El worker no ha dado señales hace unos minutos; puede estar procesando algo lento o a punto de caerse. Revise <code>logs/worker.log</code>.</div>
  <?php endif; ?>
  <div class="ayuda">
    Reintentos programados: <strong><?= (int) $colas['reintentos_programados'] ?></strong> (vencidos: <?= (int) $colas['reintentos_vencidos'] ?>) ·
    Avisos pendientes: <strong><?= (int) $colas['entregas_pendientes'] ?></strong> ·
    Avisos fallidos (7 días): <?= (int) $colas['entregas_fallidas_7d'] ?> ·
    Documentos con error (7 días): <?= (int) $colas['documentos_error_7d'] ?>
    <?php if ($colas['espera_max'] > 120): ?> · <strong>Trabajo vencido esperando <?= h(Salud::edadTexto($colas['espera_max'])) ?></strong><?php endif; ?>
  </div>
</div>

<div class="cuadricula">
  <div class="tarjeta"><div class="etiqueta">Empresas activas</div><div class="cifra"><?= (int) $nEmpresas ?></div>
    <div class="ayuda"><?= (int) $nInactivas ?> desactivadas · <a href="<?= h(Vista::url('/empresas')) ?>">Ver</a></div></div>
  <div class="tarjeta"><div class="etiqueta">Usuarios del portal</div><div class="cifra"><?= (int) $nUsuarios ?></div>
    <div class="ayuda"><?= (int) $sinUsuarios ?> empresas sin ningún usuario</div></div>
  <div class="tarjeta"><div class="etiqueta">Documentos en producción (<?= h($periodo) ?>)</div><div class="cifra"><?= (int) $docsMes ?></div>
    <div class="ayuda">Aceptados o en proceso, todas las empresas</div></div>
  <div class="tarjeta"><div class="etiqueta">Contactos del sitio sin atender</div><div class="cifra"><?= (int) $nContactos ?></div>
    <div class="ayuda"><a href="<?= h(Vista::url('/contactos')) ?>">Ver bandeja</a></div></div>
  <div class="tarjeta"><div class="etiqueta">Solicitudes de plan</div><div class="cifra"><?= (int) $nSolicitudes ?></div>
    <div class="ayuda"><a href="<?= h(Vista::url('/solicitudes')) ?>">Revisar</a></div></div>
</div>

<?php if ($nSinPlan > 0): ?>
  <div class="aviso info"><?= (int) $nSinPlan ?> empresa(s) activa(s) <strong>sin plan</strong>: no tienen ningún límite de velocidad ni de cuota. Asígneles un plan desde <a href="<?= h(Vista::url('/empresas')) ?>">Empresas</a>.</div>
<?php endif; ?>

<h2>Cerca del límite de su cuota (80 % o más)</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Empresa</th><th>Plan</th><th>Usados</th><th>%</th></tr>
  <?php foreach ($cerca as $c): $pct = (int) round(100 * $c['usados'] / $c['cuota']); ?>
    <tr>
      <td><a href="<?= h(Vista::url('/empresas/' . $c['id'])) ?>"><?= h($c['ruc']) ?> · <?= h($c['razon_social']) ?></a></td>
      <td><?= h($c['plan']) ?></td>
      <td><?= (int) $c['usados'] ?> / <?= (int) $c['cuota'] ?></td>
      <td><span class="badge <?= $pct >= 100 ? 'mal' : 'espera' ?>"><?= $pct ?> %</span></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$cerca): ?><tr><td colspan="4" class="ayuda">Ninguna empresa está cerca de su límite este mes.</td></tr><?php endif; ?>
</table>
</div>
