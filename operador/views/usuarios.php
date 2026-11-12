<h1>Usuarios del portal</h1>
<form class="filtros" method="get" action="<?= h(Vista::url('/usuarios')) ?>">
  <div><label for="q">Buscar por correo, RUC o empresa</label><input id="q" type="text" name="q" value="<?= h($q) ?>" maxlength="60"></div>
  <div><button type="submit">Buscar</button></div>
</form>
<div class="tabla-scroll">
<table>
  <tr><th>Correo</th><th>Empresa</th><th>Rol</th><th>Último acceso</th><th>Estado</th><th>Acciones</th></tr>
  <?php foreach ($usuarios as $u):
      $bloq = $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time();
  ?>
    <tr>
      <td><?= h($u['email']) ?><br><span class="ayuda"><?= h($u['nombre']) ?></span></td>
      <td><a href="<?= h(Vista::url('/empresas/' . (int) $u['empresa_id'])) ?>"><?= h($u['ruc']) ?> · <?= h($u['razon_social']) ?></a></td>
      <td><?= h($u['rol']) ?></td>
      <td class="mono"><?= h($u['ultimo_acceso'] ? $u['ultimo_acceso'] : 'nunca') ?></td>
      <td><?= (int) $u['activo'] === 1 ? '<span class="badge ok">activo</span>' : '<span class="badge mal">desactivado</span>' ?><?= $bloq ? ' <span class="badge espera">bloqueado</span>' : '' ?><?= $u['debe_cambiar'] ? ' <span class="badge info">clave temporal</span>' : '' ?></td>
      <td>
        <form method="post" action="<?= h(Vista::url('/usuarios/reset')) ?>" data-confirmar="¿Restablecer la contraseña de <?= h($u['email']) ?>? Se cerrarán sus sesiones.">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
          <button class="chico" type="submit">Restablecer clave</button>
        </form>
        <form method="post" action="<?= h(Vista::url('/usuarios/estado')) ?>" data-confirmar="¿<?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?> a <?= h($u['email']) ?>?">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
          <input type="hidden" name="activo" value="<?= (int) $u['activo'] === 1 ? '0' : '1' ?>">
          <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
          <button class="suave chico" type="submit"><?= (int) $u['activo'] === 1 ? 'Desactivar' : 'Activar' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$usuarios): ?><tr><td colspan="6" class="ayuda">No hay usuarios<?= $q !== '' ? ' con esa búsqueda' : '' ?>. Se crean desde la ficha de cada empresa.</td></tr><?php endif; ?>
</table>
</div>
<p class="ayuda">Restablecer la clave genera una contraseña temporal (se muestra una vez), desbloquea la cuenta y cierra las sesiones abiertas del usuario.</p>
