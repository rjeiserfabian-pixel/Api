<p><a href="<?= h(Vista::url('/empresas')) ?>">← Empresas</a></p>
<h1><?= h($e['razon_social']) ?> <?= (int) $e['activo'] === 1 ? '<span class="badge ok">activa</span>' : '<span class="badge mal">desactivada</span>' ?></h1>
<?php
$cuota = $plan['cuota_mensual'];
$pct = ($cuota !== null && $cuota > 0) ? min(100, (int) round(100 * $usado / $cuota)) : 0;
$claseBarra = $pct >= 100 ? 'critico' : ($pct >= 80 ? 'alerta' : '');
$volverEmp = 'empresa';
?>
<div class="cuadricula">
  <div class="tarjeta">
    <div class="etiqueta">Datos</div>
    <dl class="det">
      <dt>RUC</dt><dd class="mono"><?= h($e['ruc']) ?></dd>
      <dt>Nombre comercial</dt><dd><?= h($e['nombre_comercial']) ?></dd>
      <dt>Domicilio</dt><dd><?= h($e['domicilio_fiscal']) ?>, <?= h($e['distrito']) ?>, <?= h($e['provincia']) ?>, <?= h($e['departamento']) ?></dd>
      <dt>Credencial SOL</dt><dd><?= $e['sol_usuario'] ? '<span class="badge ok">registrada</span> ' . h($e['sol_usuario']) : '<span class="badge espera">falta</span>' ?></dd>
      <dt>Credenciales GRE</dt><dd><?= $e['gre_client_id'] ? '<span class="badge ok">registradas</span>' : '<span class="badge info">no (solo si emite guías)</span>' ?></dd>
      <dt>Certificados</dt><dd><?php foreach ($certs as $c): ?><span class="badge ok"><?= $c['entorno'] === 'live' ? 'producción' : 'pruebas' ?></span> vence <?= h($c['valido_hasta']) ?><br><?php endforeach; ?><?= $certs ? '' : '<span class="badge espera">ninguno propio</span>' ?></dd>
      <dt>Llaves API activas</dt><dd><?= (int) $nLlaves ?></dd>
      <dt>Documentos (total)</dt><dd><?= (int) $nDocs ?></dd>
      <dt>Alta</dt><dd class="mono"><?= h($e['creado']) ?></dd>
    </dl>
  </div>
  <div class="tarjeta">
    <div class="etiqueta">Plan y consumo de <?= h($periodo) ?></div>
    <div class="cifra"><?= h($plan['nombre'] === 'sin_plan' ? 'Sin plan' : $plan['nombre']) ?></div>
    <div class="ayuda">Cuota: <?= $cuota === null ? 'ilimitada' : (int) $cuota ?> · Emisiones/min: <?= $plan['limite_sunat_min'] === null ? 'sin límite' : (int) $plan['limite_sunat_min'] ?> · Peticiones/min: <?= $plan['limite_general_min'] === null ? 'sin límite' : (int) $plan['limite_general_min'] ?> · Webhooks: <?= (int) $plan['max_webhooks'] ?></div>
    <div class="cifra"><?= (int) $usado ?><?= ($cuota === null) ? '' : ' / ' . (int) $cuota ?></div>
    <?php if ($cuota !== null && $cuota > 0): ?><progress class="barra-uso <?= $claseBarra ?>" max="100" value="<?= (int) $pct ?>"><?= (int) $pct ?>%</progress><?php endif; ?>
    <?php if ($consumo): ?>
      <div class="ayuda">Meses anteriores:
        <?php foreach ($consumo as $c): ?><span class="badge info"><?= h($c['periodo']) ?>: <?= (int) $c['documentos'] ?></span> <?php endforeach; ?></div>
    <?php endif; ?>
  </div>
</div>

<h2>Asignar plan</h2>
<div class="tarjeta">
  <?php if ($pendiente): ?>
    <div class="aviso info">El cliente solicitó el plan <strong><?= h($pendiente['plan']) ?></strong> el <?= h($pendiente['creado']) ?>. Puede resolverlo en <a href="<?= h(Vista::url('/solicitudes')) ?>">Solicitudes</a> o asignar un plan aquí (lo cancela).</div>
  <?php endif; ?>
  <form method="post" action="<?= h(Vista::url('/empresas/' . (int) $e['id'] . '/plan')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="plan">Plan</label>
    <select id="plan" name="plan_id">
      <?php foreach ($planes as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $plan['id'] === (int) $p['id'] ? 'selected' : '' ?>><?= h($p['nombre']) ?> — <?= $p['cuota_mensual'] === null ? 'ilimitado' : (int) $p['cuota_mensual'] . ' docs/mes' ?></option><?php endforeach; ?>
      <option value="ninguno">Sin plan (quita TODOS los límites)</option>
    </select>
    <label for="cp">Su contraseña (solo si elige "Sin plan")</label>
    <input id="cp" type="password" name="clave_actual" autocomplete="current-password" maxlength="128">
    <p><button type="submit">Asignar plan</button></p>
    <p class="ayuda">Aplica de inmediato. Cambiar de plan no reinicia el consumo del mes.</p>
  </form>
</div>

<h2>Usuarios del portal</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Correo</th><th>Nombre</th><th>Rol</th><th>Último acceso</th><th>Estado</th><th>Acciones</th></tr>
  <?php foreach ($usuarios as $u):
      $bloq = $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time();
  ?>
    <tr>
      <td><?= h($u['email']) ?></td>
      <td><?= h($u['nombre']) ?></td>
      <td><?= h($u['rol']) ?></td>
      <td class="mono"><?= h($u['ultimo_acceso'] ? $u['ultimo_acceso'] : 'nunca') ?></td>
      <td><?= (int) $u['activo'] === 1 ? '<span class="badge ok">activo</span>' : '<span class="badge mal">desactivado</span>' ?><?= $bloq ? ' <span class="badge espera">bloqueado</span>' : '' ?><?= $u['debe_cambiar'] ? ' <span class="badge info">clave temporal</span>' : '' ?></td>
      <td>
        <form method="post" action="<?= h(Vista::url('/usuarios/reset')) ?>" data-confirmar="¿Restablecer la contraseña de <?= h($u['email']) ?>? Se cerrarán sus sesiones.">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="volver" value="empresa">
          <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
          <button class="chico" type="submit">Restablecer clave</button>
        </form>
        <form method="post" action="<?= h(Vista::url('/usuarios/estado')) ?>" data-confirmar="¿<?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?> a <?= h($u['email']) ?>?">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><input type="hidden" name="volver" value="empresa">
          <input type="hidden" name="activo" value="<?= (int) $u['activo'] === 1 ? '0' : '1' ?>">
          <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
          <button class="suave chico" type="submit"><?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$usuarios): ?><tr><td colspan="6" class="ayuda">Esta empresa no tiene usuarios: no puede entrar al portal. Cree uno abajo.</td></tr><?php endif; ?>
</table>
</div>

<h2>Crear usuario</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/empresas/' . (int) $e['id'] . '/usuario')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="ue">Correo</label><input id="ue" type="email" name="email" required maxlength="190">
    <label for="un">Nombre</label><input id="un" type="text" name="nombre" maxlength="120">
    <label for="ur">Rol</label><select id="ur" name="rol"><option value="admin">admin (gestiona todo)</option><option value="lector">lector (solo consulta)</option></select>
    <label for="uc">Su contraseña</label><input id="uc" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <p><button type="submit">Crear usuario</button></p>
    <p class="ayuda">Se genera una contraseña temporal que se muestra una sola vez; el usuario debe cambiarla al ingresar.</p>
  </form>
</div>

<h2>Estado de la empresa</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/empresas/' . (int) $e['id'] . '/estado')) ?>" data-confirmar="<?= (int) $e['activo'] === 1 ? '¿Desactivar la empresa? Su API y su portal dejarán de funcionar de inmediato.' : '¿Activar la empresa?' ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="activo" value="<?= (int) $e['activo'] === 1 ? '0' : '1' ?>">
    <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
    <button class="<?= (int) $e['activo'] === 1 ? 'peligro' : '' ?>" type="submit"><?= (int) $e['activo'] === 1 ? 'Desactivar empresa' : 'Activar empresa' ?></button>
    <p class="ayuda">Desactivar bloquea la API (las llaves responden 403) y el portal de esa empresa. No borra nada.</p>
  </form>
</div>

<h2>Actividad reciente</h2>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Acción</th><th>Detalle</th></tr>
  <?php foreach ($actividad as $a): ?>
    <tr><td class="mono"><?= h($a['creado']) ?></td><td><span class="badge info"><?= h($a['accion']) ?></span></td><td><?= h($a['email'] ? $a['email'] . ' · ' : '') ?><?= h($a['detalle']) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$actividad): ?><tr><td colspan="3" class="ayuda">Sin actividad.</td></tr><?php endif; ?>
</table>
</div>
