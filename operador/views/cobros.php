<h1>Cobros y suscripciones</h1>
<p class="ayuda">Empresas con suscripción (pagada o en prueba gratis). Las empresas con plan asignado a mano, sin suscripción, no vencen nunca. <a href="<?= h(Vista::url('/pagos')) ?>">Ver historial de pagos</a> · <a href="<?= h(Vista::url('/ajustes')) ?>">Ajustes de cobro</a></p>
<div class="cuadricula">
  <div class="tarjeta"><div class="etiqueta">Activas</div><div class="cifra"><?= (int) (isset($cuentas['activa']) ? $cuentas['activa'] : 0) ?></div><div class="ayuda"><a href="<?= h(Vista::url('/cobros?estado=activa')) ?>">Ver</a></div></div>
  <div class="tarjeta"><div class="etiqueta">Por vencer (pronto)</div><div class="cifra"><?= (int) $porVencer ?></div><div class="ayuda"><a href="<?= h(Vista::url('/cobros?estado=porvencer')) ?>">Ver</a></div></div>
  <div class="tarjeta"><div class="etiqueta">En prueba gratis</div><div class="cifra"><?= (int) (isset($cuentas['prueba']) ? $cuentas['prueba'] : 0) ?></div><div class="ayuda"><a href="<?= h(Vista::url('/cobros?estado=prueba')) ?>">Ver</a></div></div>
  <div class="tarjeta"><div class="etiqueta">En gracia (venció)</div><div class="cifra"><?= (int) (isset($cuentas['gracia']) ? $cuentas['gracia'] : 0) ?></div><div class="ayuda"><a href="<?= h(Vista::url('/cobros?estado=gracia')) ?>">Ver</a></div></div>
  <div class="tarjeta"><div class="etiqueta">Vencidas</div><div class="cifra"><?= (int) (isset($cuentas['vencida']) ? $cuentas['vencida'] : 0) ?></div><div class="ayuda"><a href="<?= h(Vista::url('/cobros?estado=vencida')) ?>">Ver</a></div></div>
</div>
<?php if ($sinSuscripcion > 0): ?><div class="aviso info"><?= (int) $sinSuscripcion ?> empresa(s) activa(s) sin suscripción (plan a mano o sin plan): no vencen. Para que paguen, registre su primer pago desde su ficha.</div><?php endif; ?>
<form class="filtros" method="get" action="<?= h(Vista::url('/cobros')) ?>">
  <div><label for="e">Estado</label>
    <select id="e" name="estado">
      <?php foreach (array('' => 'Todas', 'activa' => 'Activas', 'porvencer' => 'Por vencer', 'prueba' => 'En prueba gratis', 'gracia' => 'En gracia', 'vencida' => 'Vencidas') as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $estado === $k ? 'selected' : '' ?>><?= h($v) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><button type="submit">Filtrar</button></div>
</form>
<div class="tabla-scroll">
<table>
  <tr><th>Empresa</th><th>Plan</th><th>Período</th><th>Estado</th><th>Vence</th><th>Días</th><th></th></tr>
  <?php foreach ($subs as $s):
      $dias = Cobros::diasHasta($s['vence']);
      $cls = array('activa' => 'ok', 'prueba' => 'info', 'gracia' => 'espera', 'vencida' => 'mal');
  ?>
    <tr>
      <td><a href="<?= h(Vista::url('/empresas/' . (int) $s['empresa_id'])) ?>"><?= h($s['ruc']) ?> · <?= h($s['razon_social']) ?></a><?= (int) $s['empresa_activa'] === 1 ? '' : ' <span class="badge mal">desactivada</span>' ?></td>
      <td><?= h($s['plan']) ?></td>
      <td><?= h($s['periodo']) ?></td>
      <td><span class="badge <?= h(isset($cls[$s['estado']]) ? $cls[$s['estado']] : 'info') ?>"><?= h($s['estado']) ?></span></td>
      <td class="mono"><?= h(substr($s['vence'], 0, 10)) ?><?= $s['estado'] === 'gracia' ? '<br><span class="ayuda">gracia hasta ' . h(substr($s['gracia_hasta'], 0, 10)) . '</span>' : '' ?></td>
      <td><?= in_array($s['estado'], array('activa', 'prueba'), true) ? h($dias . ' d') : '—' ?></td>
      <td><a href="<?= h(Vista::url('/empresas/' . (int) $s['empresa_id'])) ?>">Registrar pago</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$subs): ?><tr><td colspan="7" class="ayuda">No hay suscripciones<?= $estado !== '' ? ' en este estado' : ' todavía' ?>.</td></tr><?php endif; ?>
</table>
</div>
