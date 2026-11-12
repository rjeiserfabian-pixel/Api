<h1>Mi cuenta</h1>
<?php if ($operador['debe_cambiar']): ?><div class="aviso info"><strong>Por seguridad debe elegir una contraseña nueva antes de continuar.</strong></div><?php endif; ?>
<div class="tarjeta">
  <dl class="det">
    <dt>Nombre</dt><dd><?= h($operador['nombre']) ?></dd>
    <dt>Correo</dt><dd><?= h($operador['email']) ?></dd>
    <dt>Último acceso</dt><dd class="mono"><?= h($operador['ultimo_acceso'] ? $operador['ultimo_acceso'] : '—') ?></dd>
  </dl>
</div>
<h2>Cambiar contraseña</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/cuenta/clave')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="a">Contraseña actual</label><input id="a" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <label for="n">Contraseña nueva</label><input id="n" type="password" name="clave_nueva" required autocomplete="new-password" maxlength="128">
    <div class="ayuda">Al menos 10 caracteres y dos tipos de caracteres. Como este panel controla a todos los clientes, use una contraseña larga y única.</div>
    <label for="r">Repita la nueva</label><input id="r" type="password" name="clave_repetida" required autocomplete="new-password" maxlength="128">
    <p><button type="submit">Cambiar contraseña</button></p>
  </form>
</div>
