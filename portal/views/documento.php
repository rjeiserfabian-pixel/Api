<h1><?= h(nombreTipo($d['tipo'])) ?> <?= h($d['serie']) ?>-<?= h($d['numero']) ?></h1>
<div class="tarjeta">
  <dl class="det">
    <dt>Estado</dt><dd><span class="badge <?= h(claseEstado($d['estado'])) ?>"><?= h($d['estado']) ?></span></dd>
    <dt>Código SUNAT</dt><dd class="mono"><?= h($d['sunat']['codigo'] === null ? '—' : $d['sunat']['codigo']) ?></dd>
    <dt>Respuesta de SUNAT</dt><dd><?= h($d['sunat']['descripcion'] === null ? '—' : $d['sunat']['descripcion']) ?></dd>
    <dt>Entorno</dt><dd><?= h($d['entorno']) ?></dd>
    <dt>Creado</dt><dd class="mono"><?= h($d['creado']) ?></dd>
    <?php if (!empty($d['ticket'])): ?><dt>Ticket</dt><dd class="mono"><?= h($d['ticket']) ?></dd><?php endif; ?>
    <?php if (!empty($d['hash'])): ?><dt>Hash de la firma</dt><dd class="mono"><?= h($d['hash']) ?></dd><?php endif; ?>
    <dt>Identificador</dt><dd class="mono"><?= h($d['id']) ?></dd>
  </dl>
</div>
<?php if ($d['estado'] === 'error'): ?>
  <div class="aviso info">Este documento falló por un problema temporal. Si fue enviado con <code>Idempotency-Key</code>, el sistema lo reintenta solo.</div>
<?php endif; ?>
<h2>Archivos</h2>
<div class="tarjeta">
  <?php $arch = (array) $d['archivos']; ?>
  <?php foreach (array('xml' => 'XML firmado', 'cdr' => 'CDR (constancia de SUNAT)', 'pdf' => 'PDF') as $k => $nombre): ?>
    <?php if (!empty($arch[$k])): ?><a class="boton suave" rel="noopener noreferrer" target="_blank" href="<?= h(urlSegura($arch[$k])) ?>"><?= h($nombre) ?></a> <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$arch): ?><span class="ayuda">Todavía no hay archivos para este documento.</span><?php endif; ?>
</div>
<p><a href="<?= h(Vista::url('/documentos')) ?>">← Volver a documentos</a></p>
