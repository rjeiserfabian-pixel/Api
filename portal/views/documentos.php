<h1>Documentos <span class="ayuda">(<?= $entorno === 'live' ? 'producción' : 'pruebas' ?>)</span></h1>
<form method="get" action="<?= h(Vista::url('/documentos')) ?>" class="filtros">
  <div><label for="f-tipo">Tipo</label>
    <select id="f-tipo" name="tipo"><option value="">Todos</option>
      <?php foreach (array('01', '03', '07', '08', '20', '09', 'RC', 'RA', 'RR') as $t): ?>
        <option value="<?= h($t) ?>" <?= $f['tipo'] === $t ? 'selected' : '' ?>><?= h(nombreTipo($t)) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label for="f-estado">Estado</label>
    <select id="f-estado" name="estado"><option value="">Todos</option>
      <?php foreach (array('aceptado', 'rechazado', 'pendiente', 'error', 'procesando', 'reservado', 'simulado') as $e): ?>
        <option value="<?= h($e) ?>" <?= $f['estado'] === $e ? 'selected' : '' ?>><?= h($e) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div><label for="f-serie">Serie</label><input id="f-serie" type="text" name="serie" value="<?= h($f['serie']) ?>" maxlength="16"></div>
  <div><label for="f-numero">Número</label><input id="f-numero" type="text" name="numero" value="<?= h($f['numero']) ?>" maxlength="8"></div>
  <div><button type="submit">Filtrar</button> <a class="boton suave" href="<?= h(Vista::url('/documentos')) ?>">Limpiar</a></div>
</form>
<div class="tabla-scroll">
<table>
  <tr><th>Fecha</th><th>Documento</th><th>Estado</th><th>SUNAT</th><th></th></tr>
  <?php foreach ($items as $d): ?>
    <tr>
      <td class="mono"><?= h($d['creado']) ?></td>
      <td><?= h(nombreTipo($d['tipo'])) ?> <strong><?= h($d['serie']) ?>-<?= h($d['numero']) ?></strong></td>
      <td><span class="badge <?= h(claseEstado($d['estado'])) ?>"><?= h($d['estado']) ?></span></td>
      <td><?= h(mb_substr((string) $d['sunat']['descripcion'], 0, 90, 'UTF-8')) ?></td>
      <td><a href="<?= h(Vista::url('/documentos/' . $d['id'])) ?>">Detalle</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="5" class="ayuda">No hay documentos con esos filtros.</td></tr><?php endif; ?>
</table>
</div>
<div class="pag">
  <span class="ayuda"><?= (int) $total ?> documentos · página <?= (int) $pagina ?> de <?= (int) $paginas ?></span>
  <?php if ($pagina > 1): ?><a class="boton suave chico" href="<?= h(Vista::url('/documentos?' . http_build_query(array_merge($f, array('pagina' => $pagina - 1))))) ?>">← Anterior</a><?php endif; ?>
  <?php if ($pagina < $paginas): ?><a class="boton suave chico" href="<?= h(Vista::url('/documentos?' . http_build_query(array_merge($f, array('pagina' => $pagina + 1))))) ?>">Siguiente →</a><?php endif; ?>
</div>
