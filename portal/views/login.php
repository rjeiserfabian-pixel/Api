<div class="centro">
  <div class="acceso">
    <h1>API SUNAT</h1>
    <div class="tarjeta">
      <h2 class="sin-margen">Ingresar al portal</h2>
      <?php if (!empty($expirada)): ?><div class="aviso info">Su sesión terminó (<?= $expirada === 'tiempo' ? 'por inactividad o tiempo máximo' : 'se detectó un cambio de navegador' ?>). Ingrese de nuevo.</div><?php endif; ?>
      <form method="post" action="<?= h(Vista::url('/login')) ?>" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
        <label for="email">Correo</label>
        <input id="email" type="email" name="email" value="<?= h($email) ?>" required autofocus autocomplete="username" maxlength="190">
        <label for="clave">Contraseña</label>
        <input id="clave" type="password" name="clave" required autocomplete="current-password" maxlength="128">
        <p><button type="submit">Ingresar</button></p>
      </form>
      <p class="ayuda">¿No tiene acceso o olvidó su contraseña? Contacte al administrador del servicio.</p>
    </div>
  </div>
</div>
