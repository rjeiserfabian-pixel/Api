<h1>Mi cuenta</h1>
<?php if ($usuario['debe_cambiar']): ?><div class="aviso info"><strong>Por seguridad debe elegir una contraseña nueva antes de continuar.</strong></div><?php endif; ?>
<div class="tarjeta">
  <dl class="det">
    <dt>Nombre</dt><dd><?= h($usuario['nombre']) ?></dd>
    <dt>Correo</dt><dd><?= h($usuario['email']) ?></dd>
    <dt>Rol</dt><dd><?= h($usuario['rol']) ?> <?= $usuario['rol'] === 'admin' ? '(puede gestionar llaves, webhooks, certificado y credenciales)' : '(solo lectura)' ?></dd>
    <dt>Último acceso</dt><dd class="mono"><?= h($usuario['ultimo_acceso'] ? $usuario['ultimo_acceso'] : '—') ?></dd>
  </dl>
</div>
<h2>Cambiar contraseña</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/cuenta/clave')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="a">Contraseña actual</label><input id="a" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <label for="n">Contraseña nueva</label><input id="n" type="password" name="clave_nueva" required autocomplete="new-password" maxlength="128">
    <div class="ayuda">Al menos 10 caracteres y dos tipos de caracteres (minúsculas, mayúsculas, números o símbolos). No use su correo ni claves comunes.</div>
    <label for="r">Repita la nueva</label><input id="r" type="password" name="clave_repetida" required autocomplete="new-password" maxlength="128">
    <p><button type="submit">Cambiar contraseña</button></p>
  </form>
</div>
