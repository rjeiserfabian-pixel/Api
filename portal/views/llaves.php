<h1>Llaves API</h1>
<div class="tarjeta">
  <p class="sin-margen">Las llaves autentican a su sistema ante la API. Envíela en cada petición: <code>Authorization: Bearer sk_test_…</code></p>
  <p class="ayuda"><strong>sk_test_…</strong> envía al beta de SUNAT (pruebas, sin efectos). <strong>sk_live_…</strong> emite documentos reales y consume su cuota. Dirección de la API: <code><?= h($apiUrl) ?></code></p>
</div>
<div class="tabla-scroll">
<table>
  <tr><th>Nombre</th><th>Llave</th><th>Entorno</th><th>Último uso</th><th>Estado</th><th></th></tr>
  <?php foreach ($llaves as $k): ?>
    <tr>
      <td><?= h($k['nombre']) ?></td>
      <td class="mono"><?= h($k['prefijo']) ?>…</td>
      <td><span class="badge <?= $k['entorno'] === 'live' ? 'mal' : 'ok' ?>"><?= $k['entorno'] === 'live' ? 'producción' : 'pruebas' ?></span></td>
      <td class="mono"><?= h($k['ultimo_uso'] ? $k['ultimo_uso'] : 'nunca') ?></td>
      <td><?= (int) $k['activo'] === 1 ? '<span class="badge ok">activa</span>' : '<span class="badge info">revocada</span>' ?></td>
      <td>
        <?php if ($esAdmin && (int) $k['activo'] === 1): ?>
        <form method="post" action="<?= h(Vista::url('/llaves/revocar')) ?>" data-confirmar="¿Revocar esta llave? Los sistemas que la usan dejarán de funcionar de inmediato.">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
          <input type="password" name="clave_actual" placeholder="Su contraseña" required autocomplete="current-password" maxlength="128">
          <button class="peligro chico" type="submit">Revocar</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$llaves): ?><tr><td colspan="6" class="ayuda">Todavía no tiene llaves.</td></tr><?php endif; ?>
</table>
</div>
<?php if ($esAdmin): ?>
<h2>Crear una llave nueva</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/llaves/crear')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="nombre">Nombre (para reconocerla)</label>
    <input id="nombre" type="text" name="nombre" required maxlength="60" placeholder="Ej. Sistema de ventas">
    <label for="ent">Entorno</label>
    <select id="ent" name="entorno"><option value="test">Pruebas (beta de SUNAT)</option><option value="live">Producción (documentos reales)</option></select>
    <label for="ca">Su contraseña (por seguridad)</label>
    <input id="ca" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <p><button type="submit">Crear llave</button></p>
    <p class="ayuda">La llave completa se muestra una sola vez. Máximo <?= (int) $maximo ?> llaves activas por entorno.</p>
  </form>
</div>
<?php else: ?>
<p class="ayuda">Su rol es de solo lectura: no puede crear ni revocar llaves.</p>
<?php endif; ?>
