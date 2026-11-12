<h1>Certificado digital <span class="ayuda">(<?= $entorno === 'live' ? 'producción' : 'pruebas' ?>)</span></h1>
<div class="tarjeta">
  <?php if ($cert): ?>
    <dl class="det">
      <dt>Titular</dt><dd><?= h($cert['sujeto']) ?></dd>
      <dt>Vigente</dt><dd><span class="badge <?= $cert['vigente'] ? 'ok' : 'mal' ?>"><?= $cert['vigente'] ? 'sí' : 'VENCIDO' ?></span><?= !empty($cert['por_vencer']) ? ' <span class="badge espera">por vencer</span>' : '' ?></dd>
      <dt>Válido hasta</dt><dd class="mono"><?= h($cert['valido_hasta']) ?> (<?= (int) $cert['dias_restantes'] ?> días)</dd>
      <dt>Huella SHA-256</dt><dd class="mono"><?= h($cert['huella_sha256']) ?></dd>
    </dl>
  <?php else: ?>
    <p class="sin-margen"><?= $entorno === 'live' ? '<strong>No hay certificado de producción.</strong> Sin él no se puede emitir con llaves live.' : 'No hay certificado propio para pruebas: se usa uno compartido del beta (válido para probar).' ?></p>
  <?php endif; ?>
  <p class="ayuda">La llave privada se guarda <strong>cifrada</strong> y nunca se vuelve a mostrar. Para producción el certificado debe estar emitido a nombre de su empresa (contener su RUC).</p>
</div>
<?php if ($esAdmin): ?>
<h2>Subir o reemplazar el certificado</h2>
<div class="tarjeta">
  <h2 class="sin-margen">Opción A — archivo .pfx / .p12</h2>
  <form method="post" action="<?= h(Vista::url('/certificado/subir')) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="modo" value="pfx">
    <label for="pfx">Archivo del certificado (.pfx)</label><input id="pfx" type="file" name="pfx" accept=".pfx,.p12" required>
    <label for="pfxpass">Contraseña del archivo .pfx</label><input id="pfxpass" type="password" name="clave_pfx" required autocomplete="off" maxlength="200">
    <label for="ca1">Su contraseña del portal (por seguridad)</label><input id="ca1" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <p><button type="submit">Guardar certificado</button></p>
  </form>
</div>
<div class="tarjeta">
  <h2 class="sin-margen">Opción B — archivos PEM (certificado y llave privada)</h2>
  <form method="post" action="<?= h(Vista::url('/certificado/subir')) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="modo" value="pem">
    <label for="pc">Certificado (.pem / .crt)</label><input id="pc" type="file" name="cert" accept=".pem,.crt,.cer" required>
    <label for="pk">Llave privada (.pem / .key)</label><input id="pk" type="file" name="llave" accept=".pem,.key" required>
    <label for="pkp">Contraseña de la llave (solo si la tiene)</label><input id="pkp" type="password" name="clave_llave" autocomplete="off" maxlength="200">
    <label for="ca2">Su contraseña del portal (por seguridad)</label><input id="ca2" type="password" name="clave_actual" required autocomplete="current-password" maxlength="128">
    <p><button type="submit">Guardar certificado</button></p>
  </form>
</div>
<?php else: ?>
<p class="ayuda">Su rol es de solo lectura: no puede subir certificados.</p>
<?php endif; ?>
