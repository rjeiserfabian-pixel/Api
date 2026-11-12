<h1>Planes</h1>
<div class="tarjeta">
  <p class="sin-margen">Aquí define qué ofrece cada plan. Los cambios se aplican <strong>de inmediato</strong> a todas las empresas que lo tienen. Deje un número vacío para “ilimitado / sin límite”.</p>
  <p class="ayuda"><strong>Activación inmediata</strong> (autoservicio): el cliente se cambia solo desde su portal. Si no la marca, el cambio queda como solicitud que usted aprueba. Un plan que no esté marcado como autoservicio es lo adecuado para los de pago hasta que exista cobro automático.</p>
</div>
<div class="tabla-scroll">
<table>
  <tr><th>Plan</th><th>Docs/mes</th><th>Emisiones/min</th><th>Peticiones/min</th><th>Webhooks</th><th>Autoservicio</th><th>Activo</th><th>Empresas</th><th></th></tr>
  <?php foreach ($planes as $p): $fid = 'pf' . (int) $p['id']; ?>
    <tr>
      <td><input class="corto" type="text" name="nombre" form="<?= $fid ?>" value="<?= h($p['nombre']) ?>" required maxlength="60" aria-label="Nombre"></td>
      <td><input class="num" type="text" name="cuota_mensual" form="<?= $fid ?>" value="<?= h($p['cuota_mensual']) ?>" inputmode="numeric" maxlength="10" aria-label="Documentos al mes"></td>
      <td><input class="num" type="text" name="limite_sunat_min" form="<?= $fid ?>" value="<?= h($p['limite_sunat_min']) ?>" inputmode="numeric" maxlength="10" aria-label="Emisiones por minuto"></td>
      <td><input class="num" type="text" name="limite_general_min" form="<?= $fid ?>" value="<?= h($p['limite_general_min']) ?>" inputmode="numeric" maxlength="10" aria-label="Peticiones por minuto"></td>
      <td><input class="num" type="text" name="max_webhooks" form="<?= $fid ?>" value="<?= h($p['max_webhooks']) ?>" required inputmode="numeric" maxlength="3" aria-label="Webhooks"></td>
      <td><input type="checkbox" name="autoservicio" form="<?= $fid ?>" value="1" <?= (int) $p['autoservicio'] === 1 ? 'checked' : '' ?> aria-label="Autoservicio"></td>
      <td><input type="checkbox" name="activo" form="<?= $fid ?>" value="1" <?= (int) $p['activo'] === 1 ? 'checked' : '' ?> aria-label="Activo"></td>
      <td><?= (int) $p['nempresas'] ?></td>
      <td>
        <form id="<?= $fid ?>" method="post" action="<?= h(Vista::url('/planes/guardar')) ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <button class="chico" type="submit">Guardar</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$planes): ?><tr><td colspan="9" class="ayuda">Aún no hay planes. Cree los recomendados con <code>php bin/admin.php plan:sembrar</code> o use el formulario de abajo.</td></tr><?php endif; ?>
</table>
</div>
<h2>Crear un plan</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/planes/guardar')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="n">Nombre</label><input id="n" type="text" name="nombre" required maxlength="60" placeholder="Ej. Pro">
    <label for="c">Documentos por mes en producción (vacío = ilimitado, 0 = sin producción)</label><input id="c" type="text" name="cuota_mensual" inputmode="numeric" maxlength="10">
    <label for="s">Emisiones por minuto (vacío = sin límite)</label><input id="s" type="text" name="limite_sunat_min" inputmode="numeric" maxlength="10">
    <label for="g">Peticiones por minuto (vacío = sin límite)</label><input id="g" type="text" name="limite_general_min" inputmode="numeric" maxlength="10">
    <label for="w">Máximo de webhooks (1 a 100)</label><input id="w" type="text" name="max_webhooks" value="5" required inputmode="numeric" maxlength="3">
    <p><input id="a" type="checkbox" name="autoservicio" value="1"> <label class="check" for="a">Autoservicio: el cliente puede activarlo solo desde su portal</label></p>
    <p><button type="submit">Crear plan</button></p>
  </form>
</div>
<p class="ayuda">Un plan con empresas asignadas no se puede desactivar (una empresa sin plan activo quedaría sin ningún límite). Páselas primero a otro plan.</p>
