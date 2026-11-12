<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h(isset($titulo) ? $titulo : 'Operador') ?> · Operador API SUNAT</title>
<link rel="stylesheet" href="<?= h(dirname(Vista::$base) . '/portal/assets/portal.css') ?>">
<link rel="stylesheet" href="<?= h(Vista::url('/assets/operador.css')) ?>">
</head>
<body class="modo-operador">
<?php if ($_menu): ?>
<div class="layout">
  <aside class="lateral">
    <div class="marca">API SUNAT <span class="etiqueta-op">OPERADOR</span></div>
    <nav>
      <?php
      $items = array('resumen' => array('/', 'Resumen'), 'empresas' => array('/empresas', 'Empresas'), 'solicitudes' => array('/solicitudes', 'Solicitudes de plan'),
          'planes' => array('/planes', 'Planes'), 'usuarios' => array('/usuarios', 'Usuarios'), 'actividad' => array('/actividad', 'Actividad'), 'cuenta' => array('/cuenta', 'Mi cuenta'));
      foreach ($items as $clave => $it): ?>
        <a href="<?= h(Vista::url($it[0])) ?>" class="<?= (isset($seccion) && $seccion === $clave) ? 'activo' : '' ?>"><?= h($it[1]) ?><?= ($clave === 'solicitudes' && $nSolicitudes > 0) ? ' <span class="badge espera">' . (int) $nSolicitudes . '</span>' : '' ?></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <div class="principal">
    <div class="barra">
      <div class="quien"><strong><?= h($operador['nombre']) ?></strong> · <?= h($operador['email']) ?></div>
      <div>
        <?php $wc = array('activo' => 'ok', 'demorado' => 'espera', 'detenido' => 'mal', 'nunca' => 'mal'); $wt = array('activo' => 'Worker activo', 'demorado' => 'Worker lento', 'detenido' => 'Worker DETENIDO', 'nunca' => 'Worker sin iniciar'); ?>
        <a class="badge <?= h($wc[$worker['estado']]) ?>" href="<?= h(Vista::url('/')) ?>" title="Último latido: <?= h($worker['ultimo'] ? $worker['ultimo'] : 'nunca') ?>"><?= h($wt[$worker['estado']]) ?></a>
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
          <strong><?= h($secreto['etiqueta']) ?></strong> — cópiela ahora: <u>no se vuelve a mostrar</u>.
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
<script src="<?= h(dirname(Vista::$base) . '/portal/assets/portal.js') ?>" defer></script>
</body>
</html>
