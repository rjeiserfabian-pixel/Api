<h1>Comprobantes PDF</h1>
<?php if (!$planIncluye): ?><div class="aviso info">Su plan actual <strong>no incluye</strong> la generación de PDF. Puede configurar el diseño, pero no descargarlos hasta cambiar de plan (<a href="<?= h(Vista::url('/plan')) ?>">Mi plan</a>).</div><?php endif; ?>
<div class="tarjeta">
  <p class="sin-margen">La API genera el PDF de sus facturas, boletas y notas <strong>a pedido</strong> (A4 o ticket de 80 mm), con su logo, su color y su pie de página, el código QR de SUNAT y, si lo desea, el enlace de verificación. Usted o su sistema lo piden con <code>GET /documentos/{id}/pdf</code> o con el botón <em>PDF</em> de cada documento. Si su sistema ya imprime sus propios PDF, no necesita nada de esto.</p>
  <p class="ayuda">Los cambios se aplican a <strong>todos</strong> sus PDF, también a los de documentos anteriores.</p>
</div>

<h2>Logo</h2>
<div class="tarjeta">
  <?php if ($tieneLogo): ?>
    <p><img class="logo-actual" src="<?= h(Vista::url('/pdf/logo.png')) ?>" alt="Logo actual"></p>
  <?php else: ?>
    <p class="ayuda">Todavía no subió un logo: el PDF solo llevará el nombre de su empresa.</p>
  <?php endif; ?>
  <?php if ($esAdmin): ?>
  <form method="post" action="<?= h(Vista::url('/pdf/logo')) ?>" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="logo">Subir un logo (PNG o JPG, hasta 1 MB)</label>
    <input id="logo" type="file" name="logo" accept="image/png,image/jpeg" required>
    <div class="ayuda">Mejor con fondo blanco y de forma horizontal (por ejemplo 600 x 200 px). Se reduce y se limpia automáticamente.</div>
    <p><button type="submit"><?= $tieneLogo ? 'Reemplazar logo' : 'Subir logo' ?></button></p>
  </form>
  <?php if ($tieneLogo): ?>
  <form method="post" action="<?= h(Vista::url('/pdf/logo/quitar')) ?>" data-confirmar="¿Quitar el logo de sus PDF?">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <button class="suave chico" type="submit">Quitar logo</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<h2>Diseño</h2>
<div class="tarjeta">
  <?php if ($esAdmin): ?>
  <form method="post" action="<?= h(Vista::url('/pdf/guardar')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="color">Color principal</label>
    <input id="color" class="selector-color" type="color" name="color" value="<?= h($cfg['color']) ?>">
    <div class="ayuda">Se usa en el nombre de la empresa, el recuadro del documento, los títulos de la tabla y el total.</div>
    <label for="formato">Formato por defecto</label>
    <select id="formato" name="formato">
      <option value="a4" <?= $cfg['formato'] === 'a4' ? 'selected' : '' ?>>A4 (hoja completa)</option>
      <option value="ticket" <?= $cfg['formato'] === 'ticket' ? 'selected' : '' ?>>Ticket de 80 mm (impresora térmica)</option>
    </select>
    <div class="ayuda">Quien pide el PDF puede elegir otro formato en cada pedido (<code>?formato=a4</code> o <code>?formato=ticket</code>).</div>
    <label for="contacto">Línea de contacto (opcional)</label>
    <input id="contacto" type="text" name="contacto" value="<?= h($cfg['contacto']) ?>" maxlength="200" placeholder="Tel. (01) 555-0100 | ventas@miempresa.pe | www.miempresa.pe">
    <label for="pie">Pie de página (opcional, hasta 300 caracteres)</label>
    <textarea id="pie" name="pie" rows="3" maxlength="300" placeholder="Gracias por su compra. Cuentas bancarias, política de cambios..."><?= h($cfg['pie']) ?></textarea>
    <p><input id="mv" type="checkbox" name="mostrar_verificacion" value="1" <?= $cfg['mostrar_verificacion'] ? 'checked' : '' ?>>
      <label class="check" for="mv">Mostrar el código QR y el enlace de verificación</label></p>
    <div class="ayuda">Permite a quien reciba el comprobante comprobar que es auténtico.<?= $verifPublica ? '' : ' (Su verificación pública está desactivada en “Mi cuenta”: mientras tanto no se imprime.)' ?></div>
    <p><button type="submit">Guardar</button></p>
  </form>
  <?php else: ?>
    <p class="ayuda">Color: <?= h($cfg['color']) ?> · Formato: <?= $cfg['formato'] === 'ticket' ? 'ticket' : 'A4' ?>. Solo un administrador puede cambiar el diseño.</p>
  <?php endif; ?>
</div>

<h2>Vista previa</h2>
<div class="tarjeta">
  <p class="sin-margen">Vea cómo queda un comprobante <strong>de ejemplo</strong> con su logo, color y pie (no es un documento real).</p>
  <p>
    <a class="boton" rel="noopener noreferrer" target="_blank" href="<?= h(Vista::url('/pdf/ejemplo?formato=a4')) ?>">Ver ejemplo en A4</a>
    <a class="boton suave" rel="noopener noreferrer" target="_blank" href="<?= h(Vista::url('/pdf/ejemplo?formato=ticket')) ?>">Ver ejemplo en ticket</a>
  </p>
</div>
