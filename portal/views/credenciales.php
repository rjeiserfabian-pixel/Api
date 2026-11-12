<h1>Credenciales de SUNAT</h1>
<div class="tarjeta">
  <p class="sin-margen">Con estas credenciales la API se identifica ante SUNAT <strong>en nombre de su empresa</strong> al emitir en producción. Se guardan <strong>cifradas</strong> y <strong>nunca se muestran</strong>: aquí solo puede reemplazarlas.</p>
  <dl class="det">
    <dt>Usuario SOL</dt><dd><?= $sol_usuario !== '' ? h($sol_usuario) : '<span class="badge mal">sin registrar</span>' ?></dd>
    <dt>Clave SOL</dt><dd><?= $sol_clave ? '<span class="badge ok">registrada</span>' : '<span class="badge mal">sin registrar</span>' ?></dd>
    <dt>Credenciales GRE (guías)</dt><dd><?= ($gre_id && $gre_secreto) ? '<span class="badge ok">registradas</span>' : '<span class="badge info">sin registrar (solo se necesitan para guías en producción)</span>' ?></dd>
  </dl>
</div>
<?php if ($esAdmin): ?>
<h2>Reemplazar credenciales</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/credenciales/guardar')) ?>" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <p class="ayuda">Complete solo lo que quiera cambiar; lo que deje en blanco se conserva.</p>
    <label for="su">Usuario SOL</label><input id="su" type="text" name="sol_usuario" maxlength="30" autocomplete="off">
    <label for="sc">Clave SOL</label><input id="sc" type="password" name="sol_clave" maxlength="100" autocomplete="new-password">
    <label for="gi">GRE: client_id</label><input id="gi" type="text" name="gre_client_id" maxlength="80" autocomplete="off">
    <label for="gs">GRE: client_secret</label><input id="gs" type="password" name="gre_client_secret" maxlength="200" autocomplete="new-password">
    <label for="ca">Su contraseña del portal (por seguridad)</label><input id="ca" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <p><button type="submit">Guardar</button></p>
  </form>
</div>
<?php else: ?>
<p class="ayuda">Su rol es de solo lectura: no puede cambiar credenciales.</p>
<?php endif; ?>
