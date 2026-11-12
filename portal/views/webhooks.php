<h1>Webhooks <span class="ayuda">(<?= $entorno === 'live' ? 'producción' : 'pruebas' ?>)</span></h1>
<div class="tarjeta">
  <p class="sin-margen">Un webhook es una dirección de su servidor a la que la API avisa cuando un documento queda aceptado, rechazado o con error, o cuando su cuota llega al 80 % o 100 %. Cada aviso va <strong>firmado</strong>: verifíquelo con el secreto que se muestra al crear el webhook.</p>
  <p class="ayuda">Plan <strong><?= h($plan) ?></strong>: hasta <?= (int) $maximo ?> webhooks por entorno. La dirección debe ser pública<?= $entorno === 'live' ? ' y usar HTTPS' : '' ?>.</p>
</div>
<div class="tabla-scroll">
<table>
  <tr><th>Dirección</th><th>Eventos</th><th>Creado</th><th></th></tr>
  <?php foreach ($webhooks as $w): ?>
    <tr>
      <td class="mono"><?= h($w['url']) ?></td>
      <td><?= h(implode(', ', $w['eventos'])) ?></td>
      <td class="mono"><?= h($w['creado']) ?></td>
      <td>
        <a class="boton suave chico" href="<?= h(Vista::url('/webhooks/entregas?id=' . $w['id'])) ?>">Entregas</a>
        <?php if ($esAdmin): ?>
        <form class="linea" method="post" action="<?= h(Vista::url('/webhooks/probar')) ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($w['id']) ?>">
          <button class="suave chico" type="submit">Probar</button>
        </form>
        <form class="linea" method="post" action="<?= h(Vista::url('/webhooks/eliminar')) ?>" data-confirmar="¿Eliminar este webhook y sus entregas pendientes?">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($w['id']) ?>">
          <button class="peligro chico" type="submit">Eliminar</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$webhooks): ?><tr><td colspan="4" class="ayuda">No tiene webhooks en este entorno.</td></tr><?php endif; ?>
</table>
</div>
<?php if ($esAdmin): ?>
<h2>Registrar un webhook</h2>
<div class="tarjeta">
  <form method="post" action="<?= h(Vista::url('/webhooks/crear')) ?>">
    <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
    <label for="url">Dirección (URL)</label>
    <input id="url" type="url" name="url" required maxlength="500" placeholder="https://midominio.com/webhooks/sunat">
    <label>Eventos</label>
    <?php foreach ($eventos as $ev): ?>
      <div><label class="check"><input type="checkbox" name="eventos[]" value="<?= h($ev) ?>" checked> <?= h($ev) ?></label></div>
    <?php endforeach; ?>
    <p><button type="submit">Registrar webhook</button></p>
  </form>
</div>
<?php endif; ?>
