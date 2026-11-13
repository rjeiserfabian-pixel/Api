<h1>Planes</h1>
<div class="tarjeta">
  <p class="sin-margen">Aquí define qué ofrece cada plan y cuánto cuesta. Los cambios se aplican <strong>de inmediato</strong> a las empresas que ya lo tienen y a la página pública. Deje un número vacío para “ilimitado / sin límite”.</p>
  <p class="ayuda"><strong>Autoservicio</strong>: el cliente se cambia solo desde su portal. Si no lo marca, el cambio queda como solicitud que usted aprueba (lo adecuado para los planes de pago hasta que exista cobro automático).
    <strong>Prueba gratis</strong>: días que el cliente puede probar el plan una sola vez. <strong>Descuento anual</strong>: lo que se descuenta si paga 12 meses juntos. <strong>Incluye PDF</strong>: la API genera el PDF de los comprobantes (A4/ticket) con el logo del cliente; sin esta casilla el pedido de PDF responde 403.</p>
</div>

<?php foreach ($planes as $p): $fid = 'pf' . (int) $p['id']; $pr = Cobros::precios($p); ?>
<form class="tarjeta form-plan-caja" method="post" action="<?= h(Vista::url('/planes/guardar')) ?>">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
  <div class="form-plan-cab">
    <h2 class="sin-margen"><?= h($p['nombre']) ?>
      <?= (int) $p['activo'] === 1 ? '' : '<span class="badge mal">inactivo</span>' ?>
      <span class="ayuda"><?= (int) $p['nempresas'] ?> empresa(s)</span></h2>
    <button class="chico" type="submit">Guardar</button>
  </div>
  <div class="form-plan">
    <div><label for="n<?= $fid ?>">Nombre</label><input id="n<?= $fid ?>" type="text" name="nombre" value="<?= h($p['nombre']) ?>" required maxlength="60"></div>
    <div><label for="pm<?= $fid ?>">Precio mensual (S/)</label><input id="pm<?= $fid ?>" type="text" name="precio_mensual" value="<?= h(Dinero::paraCampo($p['precio_mensual'])) ?>" inputmode="decimal" maxlength="12" placeholder="Ej. 99.90"></div>
    <div><label for="da<?= $fid ?>">Descuento anual (%)</label><input id="da<?= $fid ?>" type="text" name="descuento_anual" value="<?= h((int) $p['descuento_anual'] > 0 ? (int) $p['descuento_anual'] : '') ?>" inputmode="numeric" maxlength="2" placeholder="0"></div>
    <div><label for="dp<?= $fid ?>">Prueba gratis (días)</label><input id="dp<?= $fid ?>" type="text" name="dias_prueba" value="<?= h((int) $p['dias_prueba'] > 0 ? (int) $p['dias_prueba'] : '') ?>" inputmode="numeric" maxlength="3" placeholder="0"></div>
    <div><label for="c<?= $fid ?>">Documentos al mes</label><input id="c<?= $fid ?>" type="text" name="cuota_mensual" value="<?= h($p['cuota_mensual']) ?>" inputmode="numeric" maxlength="10" placeholder="ilimitado"></div>
    <div><label for="s<?= $fid ?>">Emisiones por minuto</label><input id="s<?= $fid ?>" type="text" name="limite_sunat_min" value="<?= h($p['limite_sunat_min']) ?>" inputmode="numeric" maxlength="10" placeholder="sin límite"></div>
    <div><label for="g<?= $fid ?>">Peticiones por minuto</label><input id="g<?= $fid ?>" type="text" name="limite_general_min" value="<?= h($p['limite_general_min']) ?>" inputmode="numeric" maxlength="10" placeholder="sin límite"></div>
    <div><label for="w<?= $fid ?>">Webhooks (1 a 100)</label><input id="w<?= $fid ?>" type="text" name="max_webhooks" value="<?= h($p['max_webhooks']) ?>" required inputmode="numeric" maxlength="3"></div>
    <div><label for="t<?= $fid ?>">Texto de precio (solo si no define precio mensual)</label><input id="t<?= $fid ?>" type="text" name="precio" value="<?= h($p['precio']) ?>" maxlength="40" placeholder="Consultar"></div>
  </div>
  <div class="form-plan-pie">
    <label class="check"><input type="checkbox" name="publico" value="1" <?= (int) $p['publico'] === 1 ? 'checked' : '' ?>> En el sitio público</label>
    <label class="check"><input type="checkbox" name="autoservicio" value="1" <?= (int) $p['autoservicio'] === 1 ? 'checked' : '' ?>> Autoservicio</label>
    <label class="check"><input type="checkbox" name="incluye_pdf" value="1" <?= (int) $p['incluye_pdf'] === 1 ? 'checked' : '' ?>> Incluye PDF</label>
    <label class="check"><input type="checkbox" name="activo" value="1" <?= (int) $p['activo'] === 1 ? 'checked' : '' ?>> Activo</label>
    <?php if ($pr['mensual'] !== null): ?>
      <span class="ayuda">Se cobra <strong><?= h(Dinero::formato($pr['mensual'])) ?></strong> al mes o <strong><?= h(Dinero::formato($pr['anual'])) ?></strong> al año<?= $pr['ahorro'] > 0 ? ' (ahorra ' . h(Dinero::formato($pr['ahorro'])) . ')' : '' ?>.</span>
    <?php endif; ?>
  </div>
</form>
<?php endforeach; ?>
<?php if (!$planes): ?><div class="tarjeta ayuda">Aún no hay planes. Cree los recomendados con <code>php bin/admin.php plan:sembrar</code> o use el formulario de abajo.</div><?php endif; ?>

<h2>Crear un plan</h2>
<form class="tarjeta" method="post" action="<?= h(Vista::url('/planes/guardar')) ?>">
  <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
  <div class="form-plan">
    <div><label for="n">Nombre</label><input id="n" type="text" name="nombre" required maxlength="60" placeholder="Ej. Pro"></div>
    <div><label for="pm">Precio mensual (S/)</label><input id="pm" type="text" name="precio_mensual" inputmode="decimal" maxlength="12" placeholder="Ej. 99.90"></div>
    <div><label for="da">Descuento anual (%)</label><input id="da" type="text" name="descuento_anual" inputmode="numeric" maxlength="2" placeholder="0"></div>
    <div><label for="dp">Prueba gratis (días)</label><input id="dp" type="text" name="dias_prueba" inputmode="numeric" maxlength="3" placeholder="0"></div>
    <div><label for="c">Documentos al mes</label><input id="c" type="text" name="cuota_mensual" inputmode="numeric" maxlength="10" placeholder="ilimitado (0 = sin producción)"></div>
    <div><label for="s">Emisiones por minuto</label><input id="s" type="text" name="limite_sunat_min" inputmode="numeric" maxlength="10" placeholder="sin límite"></div>
    <div><label for="g">Peticiones por minuto</label><input id="g" type="text" name="limite_general_min" inputmode="numeric" maxlength="10" placeholder="sin límite"></div>
    <div><label for="w">Webhooks (1 a 100)</label><input id="w" type="text" name="max_webhooks" value="5" required inputmode="numeric" maxlength="3"></div>
    <div><label for="pr">Texto de precio (si no hay precio mensual)</label><input id="pr" type="text" name="precio" maxlength="40" placeholder="Consultar"></div>
  </div>
  <div class="form-plan-pie">
    <label class="check"><input type="checkbox" name="publico" value="1" checked> En el sitio público</label>
    <label class="check"><input type="checkbox" name="autoservicio" value="1"> Autoservicio</label>
    <label class="check"><input type="checkbox" name="incluye_pdf" value="1" checked> Incluye PDF</label>
  </div>
  <p><button type="submit">Crear plan</button></p>
</form>
<p class="ayuda">Un plan con empresas asignadas no se puede desactivar (una empresa sin plan activo quedaría sin ningún límite). Páselas primero a otro plan.</p>
