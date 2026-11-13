<?php /** Encabezado comun. Variables: $marca, $base, $docsUrl, $portalUrl, $ancla ('' en la portada, o la direccion de la portada) */ ?>
<a class="saltar" href="#contenido">Saltar al contenido</a>

<header class="cabecera" id="inicio">
  <div class="contenedor cabecera-fila">
    <a class="logo" href="<?= h($base . '/') ?>" aria-label="<?= h($marca) ?> — inicio">
      <span class="logo-marca"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span>
      <span class="logo-texto"><?= h($marca) ?></span>
    </a>
    <nav class="menu" aria-label="Principal">
      <a href="<?= h($ancla) ?>#caracteristicas">Características</a>
      <a href="<?= h($ancla) ?>#como-funciona">Cómo funciona</a>
      <a href="<?= h($ancla) ?>#documentos">Documentos</a>
      <a href="<?= h($ancla) ?>#planes">Planes</a>
      <a href="<?= h($ancla) ?>#faq">Preguntas</a>
      <a href="<?= h($base . '/verificar') ?>">Verificar documento</a>
      <a href="<?= h($docsUrl) ?>">Documentación</a>
    </nav>
    <div class="cabecera-acciones">
      <a class="enlace-suave" href="<?= h($portalUrl) ?>">Ingresar</a>
      <a class="boton boton-primario boton-chico" href="<?= h($ancla) ?>#contacto">Solicitar acceso</a>
    </div>
  </div>
</header>
