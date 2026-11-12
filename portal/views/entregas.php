<h1>Entregas del webhook</h1>
<p class="mono"><?= h($webhook['url']) ?></p>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Evento</th><th>Estado</th><th>Intentos</th><th>Último resultado</th><th>Próximo intento</th><th></th></tr>
  <?php foreach ($entregas as $e): ?>
    <tr>
      <td class="mono"><?= h($e['creado']) ?></td>
      <td><?= h($e['evento']['tipo']) ?></td>
      <td><span class="badge <?= $e['estado'] === 'entregado' ? 'ok' : ($e['estado'] === 'fallido' ? 'mal' : 'espera') ?>"><?= h($e['estado']) ?></span></td>
      <td><?= (int) $e['intentos'] ?></td>
      <td><?= $e['ultimo_http'] !== null ? 'HTTP ' . (int) $e['ultimo_http'] : '' ?> <?= h($e['ultimo_error']) ?></td>
      <td class="mono"><?= h($e['proximo_intento']) ?></td>
      <td>
        <?php if ($esAdmin && in_array($e['estado'], array('fallido', 'entregado'), true)): ?>
        <form class="linea" method="post" action="<?= h(Vista::url('/webhooks/reenviar')) ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= h($webhook['id']) ?>"><input type="hidden" name="entrega" value="<?= h($e['id']) ?>">
          <button class="suave chico" type="submit">Reenviar</button>
        </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$entregas): ?><tr><td colspan="7" class="ayuda">Todavía no hay entregas.</td></tr><?php endif; ?>
</table>
</div>
<p><a href="<?= h(Vista::url('/webhooks')) ?>">← Volver a webhooks</a></p>
