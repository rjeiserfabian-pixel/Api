<?php /** Pie comun. Variables: $marca, $base, $docsUrl, $portalUrl, $ancla */ ?>
<footer class="pie">
  <div class="contenedor pie-grid">
    <div>
      <a class="logo logo-claro" href="<?= h($base . '/') ?>"><span class="logo-marca"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg></span><span class="logo-texto"><?= h($marca) ?></span></a>
      <p class="pie-texto">API de facturación electrónica para SUNAT (Perú).</p>
    </div>
    <div>
      <h4>Producto</h4>
      <a href="<?= h($ancla) ?>#caracteristicas">Características</a>
      <a href="<?= h($ancla) ?>#documentos">Documentos</a>
      <a href="<?= h($ancla) ?>#planes">Planes</a>
      <a href="<?= h($ancla) ?>#faq">Preguntas frecuentes</a>
      <a href="<?= h($base . '/verificar') ?>">Verificar un documento</a>
    </div>
    <div>
      <h4>Desarrolladores</h4>
      <a href="<?= h($docsUrl) ?>">Documentación de la API</a>
      <a href="<?= h($ancla) ?>#desarrolladores">Webhooks</a>
      <a href="<?= h($ancla) ?>#seguridad">Seguridad</a>
    </div>
    <div>
      <h4>Clientes</h4>
      <a href="<?= h($portalUrl) ?>">Ingresar al portal</a>
      <a href="<?= h($ancla) ?>#contacto">Solicitar acceso</a>
    </div>
  </div>
  <div class="contenedor pie-base">
    <p>© <?= (int) date('Y') ?> <?= h($marca) ?>. Servicio independiente: no está afiliado ni respaldado por la SUNAT. “SUNAT” es una denominación de su titular y se menciona solo para indicar compatibilidad.</p>
  </div>
</footer>
