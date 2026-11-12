<h1>Empresas</h1>
<form class="filtros" method="get" action="<?= h(Vista::url('/empresas')) ?>">
  <div><label for="q">Buscar por RUC o razón social</label><input id="q" type="text" name="q" value="<?= h($q) ?>" maxlength="60"></div>
  <div><button type="submit">Buscar</button></div>
  <div><a class="boton" href="<?= h(Vista::url('/empresas/nueva')) ?>">+ Nuevo cliente</a></div>
</form>
<div class="tabla-scroll">
<table>
  <tr><th>RUC</th><th>Empresa</th><th>Plan</th><th>Consumo <?= h($periodo) ?></th><th>Usuarios</th><th>Estado</th></tr>
  <?php foreach ($empresas as $e):
      $usados = (int) $e['usados'];
      $cuota = $e['cuota'];
      $pct = ($cuota !== null && (int) $cuota > 0) ? (int) round(100 * $usados / (int) $cuota) : null;
  ?>
    <tr>
      <td class="mono"><a href="<?= h(Vista::url('/empresas/' . $e['id'])) ?>"><?= h($e['ruc']) ?></a></td>
      <td><a href="<?= h(Vista::url('/empresas/' . $e['id'])) ?>"><?= h($e['razon_social']) ?></a></td>
      <td><?= $e['plan'] ? h($e['plan']) : '<span class="badge espera">sin plan</span>' ?></td>
      <td><?= $usados ?><?= $cuota === null ? ($e['plan'] ? ' (ilimitada)' : '') : ' / ' . (int) $cuota ?>
        <?php if ($pct !== null): ?><span class="badge <?= $pct >= 100 ? 'mal' : ($pct >= 80 ? 'espera' : 'info') ?>"><?= $pct ?> %</span><?php endif; ?></td>
      <td><?= (int) $e['nusuarios'] ?></td>
      <td><?= (int) $e['activo'] === 1 ? '<span class="badge ok">activa</span>' : '<span class="badge mal">desactivada</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$empresas): ?><tr><td colspan="6" class="ayuda">No hay empresas<?= $q !== '' ? ' con esa búsqueda' : '' ?>.</td></tr><?php endif; ?>
</table>
</div>
<p class="ayuda">Se muestran hasta 200 empresas. El consumo cuenta solo los documentos emitidos y aceptados con llave de producción.</p>
