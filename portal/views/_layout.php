<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h(isset($titulo) ? $titulo : 'Portal') ?> · API SUNAT</title>
<link rel="stylesheet" href="<?= h(Vista::url('/assets/portal.css')) ?>">
</head>
<body>
<?php if ($_menu): ?>
<div class="layout">
  <aside class="lateral">
    <div class="marca">API SUNAT</div>
    <nav>
      <?php
      $items = array('resumen' => array('/', 'Resumen'), 'documentos' => array('/documentos', 'Documentos'), 'llaves' => array('/llaves', 'Llaves API'),
          'webhooks' => array('/webhooks', 'Webhooks'), 'certificado' => array('/certificado', 'Certificado digital'), 'credenciales' => array('/credenciales', 'Credenciales SUNAT'));
      if ($usuario['rol'] === 'admin') {
          $items['actividad'] = array('/actividad', 'Actividad');
      }
      $items['cuenta'] = array('/cuenta', 'Mi cuenta');
      foreach ($items as $clave => $it): ?>
        <a href="<?= h(Vista::url($it[0])) ?>" class="<?= (isset($seccion) && $seccion === $clave) ? 'activo' : '' ?>"><?= h($it[1]) ?></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="principal">
    <div class="barra">
      <div class="quien"><strong><?= h($empresa['razon_social']) ?></strong> · RUC <?= h($empresa['ruc']) ?> · <?= h($usuario['nombre']) ?> (<?= h($usuario['rol']) ?>)</div>
      <div>
        <span class="entorno <?= $entorno === 'live' ? 'live' : 'test' ?>"><?= $entorno === 'live' ? 'PRODUCCIÓN' : 'PRUEBAS' ?></span>
        <form class="linea" method="post" action="<?= h(Vista::url('/entorno')) ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="entorno" value="<?= $entorno === 'live' ? 'test' : 'live' ?>">
          <input type="hidden" name="volver" value="<?= h(isset($rutaActual) ? $rutaActual : '/') ?>">
          <button class="suave chico" type="submit">Cambiar a <?= $entorno === 'live' ? 'pruebas' : 'producción' ?></button>
        </form>
        <form class="linea" method="post" action="<?= h(Vista::url('/logout')) ?>">
          <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">
          <button class="suave chico" type="submit">Salir</button>
        </form>
      </div>
    </div>
    <div class="contenido">
<?php endif; ?>
      <?php if (!empty($flash)): ?><div class="aviso <?= h($flash['tipo']) ?>" role="alert"><?= h($flash['msg']) ?></div><?php endif; ?>
      <?php if (!empty($secreto)): ?>
        <div class="secreto" role="alert">
          <strong><?= h($secreto['etiqueta']) ?></strong> — guárdelo ahora: <u>no se vuelve a mostrar</u>.
          <code id="secreto-valor"><?= h($secreto['valor']) ?></code>
          <button type="button" class="chico" data-copiar="#secreto-valor">Copiar</button>
        </div>
      <?php endif; ?>
      <?= $contenido ?>
<?php if ($_menu): ?>
    </div>
  </div>
</div>
<?php endif; ?>
<script src="<?= h(Vista::url('/assets/portal.js')) ?>" defer></script>
</body>
</html>
