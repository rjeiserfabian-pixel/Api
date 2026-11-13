<h1>Contactos del sitio</h1>
<p class="ayuda">Personas que llenaron el formulario de la página promocional. Responda al correo indicado y márquelo como atendido.</p>
<form class="filtros" method="get" action="<?= h(Vista::url('/contactos')) ?>">
  <div><label for="e">Mostrar</label>
    <select id="e" name="estado"><option value="nuevo" <?= $solo ? 'selected' : '' ?>>Solo sin atender</option><option value="todos" <?= $solo ? '' : 'selected' ?>>Todos</option></select></div>
  <div><button type="submit">Filtrar</button></div>
</form>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Contacto</th><th>Empresa</th><th>Interés</th><th>Mensaje</th><th>Estado</th><th></th></tr>
  <?php foreach ($contactos as $c): ?>
    <tr>
      <td class="mono"><?= h($c['creado']) ?></td>
      <td><strong><?= h($c['nombre']) ?></strong><br><a href="mailto:<?= h($c['email']) ?>"><?= h($c['email']) ?></a><?= $c['telefono'] ? '<br>' . h($c['telefono']) : '' ?></td>
      <td><?= h($c['empresa']) ?></td>
      <td><?= h($c['interes']) ?></td>
      <td><?= nl2br(h($c['mensaje'])) ?></td>
      <td><?= $c['estado'] === 'nuevo' ? '<span class="badge espera">nuevo</span>' : '<span class="badge ok">atendido</span>' ?></td>
      <td>
        <form method="post" action="<?= h(Vista::url('/contactos/estado')) ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <input type="hidden" name="estado" value="<?= $c['estado'] === 'nuevo' ? 'atendido' : 'nuevo' ?>"><input type="hidden" name="ver" value="<?= $solo ? 'nuevo' : 'todos' ?>">
          <button class="suave chico" type="submit"><?= $c['estado'] === 'nuevo' ? 'Marcar atendido' : 'Reabrir' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$contactos): ?><tr><td colspan="7" class="ayuda">No hay contactos<?= $solo ? ' sin atender' : '' ?>.</td></tr><?php endif; ?>
</table>
</div>
