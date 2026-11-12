<h1>Nuevo cliente</h1>
<?php $v = function ($c) use ($f) { return isset($f[$c]) ? $f[$c] : ''; }; ?>
<div class="tarjeta">
  <p class="sin-margen">Crea la empresa, le asigna un plan y, opcionalmente, su primer usuario administrador (con una contraseña temporal). Las credenciales SUNAT y el certificado los carga el cliente desde su portal.</p>
  <form method="post" action="<?= h(Vista::url('/empresas/crear')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <h2>Empresa</h2>
    <label for="ruc">RUC (11 dígitos)</label><input id="ruc" type="text" name="ruc" value="<?= h($v('ruc')) ?>" required maxlength="11" pattern="[0-9]{11}" inputmode="numeric">
    <label for="razon">Razón social</label><input id="razon" type="text" name="razon" value="<?= h($v('razon')) ?>" required maxlength="150">
    <label for="nombre">Nombre comercial (opcional)</label><input id="nombre" type="text" name="nombre" value="<?= h($v('nombre')) ?>" maxlength="150">
    <label for="direccion">Domicilio fiscal</label><input id="direccion" type="text" name="direccion" value="<?= h($v('direccion')) ?>" required maxlength="200">
    <label for="ubigeo">Ubigeo (6 dígitos)</label><input id="ubigeo" type="text" name="ubigeo" value="<?= h($v('ubigeo')) ?>" required maxlength="6" pattern="[0-9]{6}" inputmode="numeric">
    <label for="dep">Departamento</label><input id="dep" type="text" name="dep" value="<?= h($v('dep')) ?>" required maxlength="50">
    <label for="prov">Provincia</label><input id="prov" type="text" name="prov" value="<?= h($v('prov')) ?>" required maxlength="50">
    <label for="dist">Distrito</label><input id="dist" type="text" name="dist" value="<?= h($v('dist')) ?>" required maxlength="50">
    <h2>Plan</h2>
    <label for="plan">Plan contratado</label>
    <select id="plan" name="plan_id">
      <option value="0">Sin plan (sin ningún límite)</option>
      <?php foreach ($planes as $p): ?><option value="<?= (int) $p['id'] ?>"><?= h($p['nombre']) ?> — <?= $p['cuota_mensual'] === null ? 'ilimitado' : (int) $p['cuota_mensual'] . ' docs/mes' ?></option><?php endforeach; ?>
    </select>
    <h2>Primer usuario administrador (opcional)</h2>
    <label for="email">Correo</label><input id="email" type="email" name="email" value="<?= h($v('email')) ?>" maxlength="190">
    <label for="nu">Nombre</label><input id="nu" type="text" name="nombre_usuario" value="<?= h($v('nombre_usuario')) ?>" maxlength="120">
    <h2>Confirmación</h2>
    <label for="ca">Su contraseña de operador</label><input id="ca" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <p><button type="submit">Crear cliente</button> <a href="<?= h(Vista::url('/empresas')) ?>">Cancelar</a></p>
  </form>
</div>
