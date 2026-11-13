<?php
/** @var string $marca @var string $base @var string $appUrl @var string $apiUrl @var array $planes @var array $errores @var array $valores @var ?string $estadoContacto @var string $tokenForm */
$iconos = array(
    'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
    'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="m9 12 2 2 4-4"/>',
    'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9m-3 3 3 3m-6-0 2 2"/>',
    'refresh' => '<path d="M4 4v6h6M20 20v-6h-6"/><path d="M5 15a8 8 0 0 0 14 3M19 9A8 8 0 0 0 5 6"/>',
    'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 8-3 8h18s-3-1-3-8"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
    'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
    'layout' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18M9 20V9"/>',
    'gauge' => '<path d="m12 14 4-4"/><path d="M3.3 17a10 10 0 1 1 17.4 0"/>',
    'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5V21h16"/>',
    'check' => '<path d="m5 13 4 4L19 7"/>',
    'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
    'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'code' => '<path d="m8 18-6-6 6-6M16 6l6 6-6 6"/>',
    'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
);
function ico($n, $clase = 'ico')
{
    global $iconos;
    return '<svg class="' . h($clase) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $iconos[$n] . '</svg>';
}
$portalUrl = $appUrl . '/portal/';
$docsUrl = $appUrl . '/docs/';
$err = function ($c) use ($errores) {
    return isset($errores[$c]) ? '<p class="campo-error" id="err-' . h($c) . '">' . h($errores[$c]) . '</p>' : '';
};
$titulo = $marca . ' · API de facturación electrónica para SUNAT (Perú)';
$descripcion = 'Emita facturas, boletas, notas de crédito y débito, guías de remisión y más desde su sistema con una API REST. Pruebas con el beta de SUNAT, reintentos automáticos, webhooks firmados y portal de clientes.';
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?></title>
<meta name="description" content="<?= h($descripcion) ?>">
<meta name="theme-color" content="#07142b">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= h($titulo) ?>">
<meta property="og:description" content="<?= h($descripcion) ?>">
<meta property="og:locale" content="es_PE">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231d4ed8'/%3E%3Cpath d='M9 16.5l4.5 4.5L23 11' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= h($base . '/assets/sitio.css?v=' . @filemtime(dirname(__DIR__) . '/assets/sitio.css')) ?>">
</head>
<body>
<?php $ancla = ''; include __DIR__ . '/_cabecera.php'; ?>

<main id="contenido">

<!-- ================================================================ HERO -->
<section class="hero">
  <div class="contenedor hero-grid">
    <div class="hero-texto">
      <p class="etiqueta-hero"><?= ico('shield', 'ico ico-chico') ?> API de facturación electrónica · SUNAT, Perú</p>
      <h1>Facturación electrónica para su sistema, <span class="resalte">con una sola llamada</span>.</h1>
      <p class="hero-sub">Usted envía un JSON. Nosotros lo validamos, firmamos el XML, lo enviamos a SUNAT y le devolvemos el resultado con su <strong>XML firmado, la constancia (CDR) de SUNAT y, si lo desea, el PDF listo para imprimir</strong>. Sin librerías SOAP, sin firmar documentos, sin administrar colas.</p>
      <div class="hero-botones">
        <a class="boton boton-primario boton-grande" href="#contacto">Solicitar acceso <?= ico('arrow', 'ico ico-chico') ?></a>
        <a class="boton boton-contorno boton-grande" href="<?= h($docsUrl) ?>">Ver la documentación</a>
      </div>
      <ul class="hero-puntos">
        <li><?= ico('check', 'ico ico-chico') ?> Entorno de pruebas con el beta de SUNAT</li>
        <li><?= ico('check', 'ico ico-chico') ?> Reintentos automáticos sin duplicar</li>
        <li><?= ico('check', 'ico ico-chico') ?> Webhooks firmados</li>
      </ul>
    </div>

    <div class="hero-codigo" aria-label="Ejemplo de uso de la API">
      <div class="ventana">
        <div class="ventana-barra"><span class="punto"></span><span class="punto"></span><span class="punto"></span><span class="ventana-titulo">Emitir una boleta</span>
          <button type="button" class="copiar" data-copiar="#ej-curl">Copiar</button></div>
        <pre class="codigo" id="ej-curl" tabindex="0"><code><span class="c-cmd">curl</span> -X POST <span class="c-str"><?= h($apiUrl) ?>/comprobantes</span> \
  -H <span class="c-str">"Authorization: Bearer sk_test_…"</span> \
  -H <span class="c-str">"Idempotency-Key: venta-0001"</span> \
  -d <span class="c-str">'{ "cliente": { … },
        "venta": { "tipo_documento_codigo": "03",
                   "serie": "B001", … },
        "items": [ { … } ] }'</span></code></pre>
      </div>
      <div class="ventana ventana-resp">
        <div class="ventana-barra"><span class="estado-ok"><span class="punto-ok"></span> 201 Created</span><span class="ventana-titulo">Respuesta</span></div>
        <pre class="codigo" tabindex="0"><code>{
  <span class="c-key">"ok"</span>: <span class="c-bool">true</span>,
  <span class="c-key">"data"</span>: {
    <span class="c-key">"id"</span>: <span class="c-str">"doc_8f3a1c…"</span>,
    <span class="c-key">"estado"</span>: <span class="c-str">"aceptado"</span>,
    <span class="c-key">"sunat"</span>: { <span class="c-key">"codigo"</span>: <span class="c-str">"0"</span>,
               <span class="c-key">"descripcion"</span>: <span class="c-str">"…ha sido aceptada"</span> },
    <span class="c-key">"archivos"</span>: { <span class="c-key">"xml"</span>: <span class="c-str">"…"</span>, <span class="c-key">"cdr"</span>: <span class="c-str">"…"</span> }
  },
  <span class="c-key">"request_id"</span>: <span class="c-str">"req_…"</span>
}</code></pre>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================ FRANJA -->
<section class="franja" aria-label="Datos clave">
  <div class="contenedor franja-grid">
    <div><strong>9</strong><span>tipos de documento electrónico</span></div>
    <div><strong>Pruebas y producción</strong><span>separadas por llave API</span></div>
    <div><strong>Reintentos</strong><span>automáticos, sin duplicar</span></div>
    <div><strong>OpenAPI</strong><span>documentación y colección Postman</span></div>
  </div>
</section>

<!-- ================================================================ PROBLEMA -->
<section class="seccion">
  <div class="contenedor angosto centrado">
    <p class="sobretitulo">El problema</p>
    <h2>Integrarse directamente con SUNAT es un proyecto en sí mismo</h2>
    <p class="lead">XML UBL 2.1, firma digital, servicios SOAP, autenticación OAuth para guías, tickets que hay que consultar, códigos de error propios, entornos de prueba que no siempre responden… Todo eso antes de emitir su primera factura.</p>
    <p class="lead"><strong>Nosotros ya lo resolvimos.</strong> Su sistema habla con una API REST moderna; el resto lo hacemos nosotros, y bien.</p>
  </div>
</section>

<!-- ================================================================ CARACTERISTICAS -->
<section class="seccion seccion-alt" id="caracteristicas">
  <div class="contenedor">
    <div class="cabecera-seccion">
      <p class="sobretitulo">Características</p>
      <h2>Todo lo que necesita para facturar con confianza</h2>
      <p class="lead">Pensada para sistemas comerciales, ERP y plataformas que emiten a nombre de muchos clientes.</p>
    </div>
    <div class="rejilla rejilla-3">
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('code') ?></span>
        <h3>API REST con respuestas uniformes</h3>
        <p>Un JSON de entrada, un JSON de salida: <code>ok</code>, <code>data</code> o <code>error</code> y un <code>request_id</code> para soporte. Los errores indican cada campo y cómo corregirlo.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('key') ?></span>
        <h3>Pruebas y producción, por llave</h3>
        <p>Las llaves <code>sk_test_</code> envían al beta de SUNAT sin efectos tributarios; las <code>sk_live_</code> emiten documentos reales. Cambiar de entorno es cambiar de llave.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('shield') ?></span>
        <h3>Certificado y credenciales por empresa</h3>
        <p>Cada empresa registra su certificado digital y su clave SOL una sola vez. Quedan cifrados y no viajan en cada petición.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('refresh') ?></span>
        <h3>Reintentos sin duplicados</h3>
        <p>Con <code>Idempotency-Key</code>, repetir una petición devuelve el resultado guardado en lugar de emitir dos veces. Si SUNAT falla, reintentamos automáticamente con espera creciente.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('bell') ?></span>
        <h3>Webhooks firmados</h3>
        <p>Reciba un aviso cuando un documento sea aceptado, rechazado o falle, o cuando su cuota se acerque al límite. Cada aviso lleva firma HMAC y se reintenta hasta 24 horas.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('list') ?></span>
        <h3>Numeración sincronizada</h3>
        <p>Su sistema puede llevar su propia numeración o dejar que la API asigne los correlativos de forma atómica, sin saltos ni duplicados, incluso con varios puntos de venta.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('layout') ?></span>
        <h3>Portal para sus clientes</h3>
        <p>Cada empresa ve sus documentos y descarga su XML firmado y su CDR; administra sus llaves, webhooks, certificado y credenciales; y controla su consumo del mes.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('gauge') ?></span>
        <h3>Límites y cuotas transparentes</h3>
        <p>Cada respuesta informa su cuota restante y su límite de velocidad. Le avisamos al llegar al 80 % y al 100 %, con mensajes claros, sin sorpresas.</p>
      </article>
      <article class="tarjeta">
        <span class="icono-caja"><?= ico('file') ?></span>
        <h3>PDF listo para imprimir</h3>
        <p>Pídalo cuando lo necesite: factura, boleta y notas en <strong>A4 o ticket de 80 mm</strong>, con el logo y los colores de cada empresa, el QR de SUNAT y el enlace de verificación. Si su sistema ya imprime los suyos, no tiene que usarlo.</p>
      </article>
    </div>
  </div>
</section>

<!-- ================================================================ COMO FUNCIONA -->
<section class="seccion" id="como-funciona">
  <div class="contenedor">
    <div class="cabecera-seccion">
      <p class="sobretitulo">Cómo funciona</p>
      <h2>De la solicitud a su primera factura en tres pasos</h2>
    </div>
    <ol class="pasos">
      <li>
        <span class="paso-num">1</span>
        <h3>Solicite acceso</h3>
        <p>Cuéntenos de su empresa y qué volumen espera. Le creamos su cuenta, su plan y su acceso al portal.</p>
      </li>
      <li>
        <span class="paso-num">2</span>
        <h3>Pruebe sin riesgo</h3>
        <p>Cree una llave de pruebas en su portal e integre su sistema contra el beta de SUNAT. Las pruebas no consumen cuota.</p>
      </li>
      <li>
        <span class="paso-num">3</span>
        <h3>Pase a producción</h3>
        <p>Registre su certificado digital y sus credenciales, cree una llave de producción y empiece a emitir documentos reales.</p>
      </li>
    </ol>
  </div>
</section>

<!-- ================================================================ DOCUMENTOS -->
<section class="seccion seccion-alt" id="documentos">
  <div class="contenedor">
    <div class="cabecera-seccion">
      <p class="sobretitulo">Documentos soportados</p>
      <h2>Los comprobantes que su negocio necesita</h2>
    </div>
    <ul class="docs">
      <li><?= ico('file') ?><div><strong>Factura</strong><span>Código 01</span></div></li>
      <li><?= ico('file') ?><div><strong>Boleta de venta</strong><span>Código 03</span></div></li>
      <li><?= ico('file') ?><div><strong>Nota de crédito</strong><span>Código 07</span></div></li>
      <li><?= ico('file') ?><div><strong>Nota de débito</strong><span>Código 08</span></div></li>
      <li><?= ico('file') ?><div><strong>Comprobante de retención</strong><span>Serie R001</span></div></li>
      <li><?= ico('file') ?><div><strong>Guía de remisión (GRE)</strong><span>Con llave de producción</span></div></li>
      <li><?= ico('file') ?><div><strong>Resumen diario de boletas</strong><span>Con consulta de ticket</span></div></li>
      <li><?= ico('file') ?><div><strong>Comunicación de baja</strong><span>Anulación de comprobantes</span></div></li>
      <li><?= ico('file') ?><div><strong>Reversión</strong><span>De retenciones</span></div></li>
    </ul>
    <p class="nota-centrada">Las guías de remisión se envían solo con llave de producción: SUNAT no ofrece un entorno de pruebas para ellas.</p>
  </div>
</section>

<!-- ================================================================ DESARROLLADORES -->
<section class="seccion oscura" id="desarrolladores">
  <div class="contenedor dos-col">
    <div>
      <p class="sobretitulo">Para desarrolladores</p>
      <h2>Hecha para integrarse bien, no solo rápido</h2>
      <ul class="lista-check">
        <li><?= ico('check', 'ico ico-chico') ?><span><strong>Idempotencia de verdad.</strong> Una clave por operación; los reintentos son seguros.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span><strong>Errores accionables.</strong> HTTP 4xx significa que no se envió nada a SUNAT; el detalle dice qué corregir.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span><strong>Cabeceras útiles.</strong> <code>X-Request-Id</code> en todas las respuestas, y <code>X-RateLimit-*</code> y <code>X-Quota-Remaining</code> para controlar su consumo.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span><strong>Estados claros.</strong> <code>aceptado</code>, <code>rechazado</code>, <code>pendiente</code> o <code>error</code>, con el mensaje de SUNAT.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span><strong>Cualquier lenguaje.</strong> HTTP y JSON: PHP, Node, Python, Java, .NET, Go…</span></li>
      </ul>
      <p><a class="boton boton-contorno-claro" href="<?= h($docsUrl) ?>">Explorar la referencia de la API <?= ico('arrow', 'ico ico-chico') ?></a></p>
    </div>
    <div>
      <div class="ventana">
        <div class="ventana-barra"><span class="punto"></span><span class="punto"></span><span class="punto"></span><span class="ventana-titulo">Aviso (webhook) a su sistema</span></div>
        <pre class="codigo" tabindex="0"><code><span class="c-cmd">POST</span> https://su-sistema.com/hooks/facturacion
<span class="c-key">X-Webhook-Event</span>: documento.aceptado
<span class="c-key">X-Webhook-Signature</span>: t=1731412345,v1=9b2c…e41f
<span class="c-key">Content-Type</span>: application/json

{
  <span class="c-key">"id"</span>: <span class="c-str">"evt_5d1e…"</span>,
  <span class="c-key">"tipo"</span>: <span class="c-str">"documento.aceptado"</span>,
  <span class="c-key">"entorno"</span>: <span class="c-str">"produccion"</span>,
  <span class="c-key">"creado"</span>: <span class="c-str">"2026-11-12 10:03:41"</span>,
  <span class="c-key">"data"</span>: {
    <span class="c-key">"id"</span>: <span class="c-str">"doc_8f3a1c…"</span>,
    <span class="c-key">"estado"</span>: <span class="c-str">"aceptado"</span>
  }
}</code></pre>
      </div>
      <p class="pie-codigo">Verifique la firma con su secreto y rechace avisos viejos: así sabe que el aviso es nuestro.</p>
    </div>
  </div>
</section>

<!-- ================================================================ SEGURIDAD -->
<section class="seccion" id="seguridad">
  <div class="contenedor">
    <div class="cabecera-seccion">
      <p class="sobretitulo">Seguridad y confiabilidad</p>
      <h2>Los datos de sus clientes, tratados con cuidado</h2>
      <p class="lead">Diseñamos cada parte pensando en lo que pasa cuando algo sale mal.</p>
    </div>
    <div class="rejilla rejilla-4">
      <div class="mini"><span class="icono-caja icono-chico"><?= ico('lock') ?></span><h3>Secretos cifrados</h3><p>Clave SOL, secretos GRE y llaves privadas se guardan cifrados con AES-256-GCM.</p></div>
      <div class="mini"><span class="icono-caja icono-chico"><?= ico('key') ?></span><h3>Llaves protegidas</h3><p>De sus llaves API solo guardamos una huella: si nuestra base se filtrara, no sirven para emitir.</p></div>
      <div class="mini"><span class="icono-caja icono-chico"><?= ico('shield') ?></span><h3>Webhooks seguros</h3><p>Firmados con HMAC-SHA256 y con protección contra destinos internos (SSRF).</p></div>
      <div class="mini"><span class="icono-caja icono-chico"><?= ico('list') ?></span><h3>Registro de actividad</h3><p>Accesos, llaves, certificados y cambios de plan quedan registrados y visibles para su empresa.</p></div>
    </div>
  </div>
</section>

<!-- ================================================================ PLANES -->
<section class="seccion seccion-alt" id="planes">
  <div class="contenedor">
    <div class="cabecera-seccion">
      <p class="sobretitulo">Planes</p>
      <h2>Un plan para cada etapa de su negocio</h2>
      <p class="lead">Se cuenta un documento cuando SUNAT lo acepta con una llave de producción. Las pruebas, las consultas y los rechazos no consumen cuota.</p>
    </div>
    <?php if ($planes): ?>
    <div class="planes">
      <?php foreach ($planes as $p):
          $destacado = (strcasecmp($p['nombre'], 'Pro') === 0);
          $cuota = $p['cuota_mensual'];
          $precio = trim((string) $p['precio']);
      ?>
      <article class="plan <?= $destacado ? 'plan-destacado' : '' ?>">
        <?php if ($destacado): ?><span class="plan-cinta">Más elegido</span><?php endif; ?>
        <h3><?= h($p['nombre']) ?></h3>
        <?php $pr = Cobros::precios($p); ?>
        <?php if ($pr['mensual'] !== null && $pr['mensual'] > 0): ?>
          <p class="plan-precio"><?= h(Dinero::formato($pr['mensual'])) ?><span class="plan-por"> al mes</span></p>
          <?php if ($pr['ahorro'] > 0): ?>
            <p class="plan-anual">o <strong><?= h(Dinero::formato($pr['anual'])) ?></strong> al año · <span class="plan-ahorro">ahorra <?= h(Dinero::formato($pr['ahorro'])) ?> (<?= (int) $pr['ahorro_pct'] ?> %)</span></p>
          <?php else: ?>
            <p class="plan-anual">o <?= h(Dinero::formato($pr['anual'])) ?> al año</p>
          <?php endif; ?>
        <?php elseif ($pr['mensual'] === 0): ?>
          <p class="plan-precio">Gratis</p>
        <?php else: ?>
          <p class="plan-precio"><?= $precio !== '' ? h($precio) : 'Consultar' ?></p>
        <?php endif; ?>
        <?php if ((int) $p['dias_prueba'] > 0): ?><p class="plan-prueba"><?= (int) $p['dias_prueba'] ?> días de prueba gratis</p><?php endif; ?>
        <ul class="plan-lista">
          <li><?= ico('check', 'ico ico-chico') ?><span>
            <?php if ($cuota === null): ?><strong>Documentos ilimitados</strong>
            <?php elseif ((int) $cuota === 0): ?><strong>Solo pruebas</strong> (sin emisión en producción)
            <?php else: ?><strong><?= h(number_format((int) $cuota, 0, ',', ' ')) ?></strong> documentos al mes<?php endif; ?></span></li>
          <li><?= ico('check', 'ico ico-chico') ?><span><?= $p['limite_sunat_min'] === null ? 'Sin límite de emisiones por minuto' : 'Hasta <strong>' . (int) $p['limite_sunat_min'] . '</strong> emisiones por minuto' ?></span></li>
          <li><?= ico('check', 'ico ico-chico') ?><span><?= $p['limite_general_min'] === null ? 'Sin límite de consultas por minuto' : '<strong>' . (int) $p['limite_general_min'] . '</strong> peticiones por minuto' ?></span></li>
          <li><?= ico('check', 'ico ico-chico') ?><span><strong><?= (int) $p['max_webhooks'] ?></strong> <?= (int) $p['max_webhooks'] === 1 ? 'webhook' : 'webhooks' ?></span></li>
          <li><?= ico('check', 'ico ico-chico') ?><span>Pruebas con SUNAT beta, sin consumir cuota</span></li>
          <?php if (!empty($p['incluye_pdf'])): ?><li><?= ico('check', 'ico ico-chico') ?><span>PDF de comprobantes (A4 y ticket) con su logo</span></li><?php endif; ?>
          <li><?= ico('check', 'ico ico-chico') ?><span>Portal de clientes y documentación (OpenAPI y Postman)</span></li>
        </ul>
        <a class="boton <?= $destacado ? 'boton-primario' : 'boton-contorno-oscuro' ?> boton-bloque" href="<?= h($base . '/?plan=' . rawurlencode($p['nombre']) . '#contacto') ?>"><?= (int) $p['dias_prueba'] > 0 ? 'Probar ' . h($p['nombre']) . ' gratis' : 'Solicitar ' . h($p['nombre']) ?></a>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="nota-centrada">¿Más volumen o necesidades especiales? <a href="#contacto">Hablemos</a>.</p>
    <?php else: ?>
    <p class="nota-centrada">Escríbanos y le armamos un plan a su medida. <a href="#contacto">Solicitar información</a>.</p>
    <?php endif; ?>
  </div>
</section>

<!-- ================================================================ FAQ -->
<section class="seccion" id="faq">
  <div class="contenedor angosto">
    <div class="cabecera-seccion">
      <p class="sobretitulo">Preguntas frecuentes</p>
      <h2>Lo que suelen preguntarnos</h2>
    </div>
    <div class="faq">
      <details><summary>¿Necesito un certificado digital?</summary>
        <p>Para probar, no: usamos un certificado de pruebas con el beta de SUNAT. Para emitir en producción, cada empresa registra su propio certificado digital vigente (lo sube desde su portal) y su clave SOL.</p></details>
      <details><summary>¿Cómo pruebo sin riesgo?</summary>
        <p>Con una llave <code>sk_test_</code>: los documentos van al entorno beta de SUNAT, sin efectos tributarios y sin consumir su cuota. Puede integrarse y validar todo antes de pasar a producción.</p></details>
      <details><summary>¿Qué pasa si SUNAT está caído o lento?</summary>
        <p>Si envió su petición con <code>Idempotency-Key</code>, el documento queda en cola y lo reintentamos automáticamente con espera creciente. Cuando llegue a un estado final le avisamos por webhook.</p></details>
      <details><summary>¿Puedo llevar mi propia numeración?</summary>
        <p>Sí. Puede enviar el número de cada comprobante, o dejar que la API asigne los correlativos de forma atómica para sus series. También puede reservar números para emitirlos más tarde.</p></details>
      <details><summary>¿La API genera el PDF del comprobante?</summary>
        <p>Sí, si lo pide: la API genera el PDF de facturas, boletas y notas de crédito y débito en A4 o ticket de 80 mm, con el logo, el color y el pie de página de cada empresa, el código QR de SUNAT y el enlace de verificación. Si su sistema ya tiene su propio PDF, puede seguir usándolo: el PDF de la API es opcional.</p></details>
      <details><summary>¿Qué documentos puedo emitir?</summary>
        <p>Facturas, boletas, notas de crédito y débito, comprobantes de retención, guías de remisión, resúmenes diarios, comunicaciones de baja y reversiones.</p></details>
      <details><summary>¿Cómo se cuenta mi consumo mensual?</summary>
        <p>Cuenta un documento cuando SUNAT lo acepta usando una llave de producción. Las pruebas, las consultas, los reenvíos idempotentes y los documentos rechazados no consumen cuota. Usted ve su consumo en el portal y en las cabeceras de cada respuesta.</p></details>
      <details><summary>¿Puedo cambiar de plan?</summary>
        <p>Sí, cuando lo necesite, desde su portal. Algunos planes se activan al instante y otros los confirmamos con usted.</p></details>
      <details><summary>¿Son una empresa afiliada a SUNAT?</summary>
        <p>No. Somos un servicio independiente de facturación electrónica que se integra con los servicios oficiales de SUNAT.</p></details>
    </div>
  </div>
</section>

<!-- ================================================================ VERIFICACION -->
<section class="seccion seccion-alt" id="verificacion">
  <div class="contenedor dos-col dos-col-verif">
    <div>
      <p class="sobretitulo">Verificación de documentos</p>
      <h2>¿Recibió un comprobante? Compruebe que es auténtico</h2>
      <p class="lead">Cada factura, boleta o nota emitida con <?= h($marca) ?> trae un <strong>enlace y un código QR de verificación</strong>. Cualquier persona puede escanearlo o consultar con los datos del documento y ver quién lo emitió, su número, fecha, total y el estado que informó SUNAT.</p>
      <ul class="lista-verif">
        <li><?= ico('check', 'ico ico-chico') ?><span>Sin crear cuenta ni instalar nada.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span>Solo datos públicos mínimos: <strong>nunca</strong> datos del comprador.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span>Los documentos de prueba se marcan como tales y no figuran como válidos.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span>Para desarrolladores: la misma consulta por API, sin llave.</span></li>
      </ul>
      <p><a class="boton boton-primario" href="<?= h($base . '/verificar') ?>">Verificar un documento <?= ico('arrow', 'ico ico-chico') ?></a></p>
    </div>
    <div class="verif-demo" aria-hidden="true">
      <div class="verif-demo-tarjeta">
        <div class="verif-demo-estado"><span class="resultado-icono">✓</span><div><strong>Documento válido</strong><br><span>SUNAT lo aceptó</span></div></div>
        <dl>
          <dt>Emisor</dt><dd>EMPRESA EJEMPLO S.A.C.</dd>
          <dt>Documento</dt><dd>Factura electrónica F001-00000150</dd>
          <dt>Importe</dt><dd>S/ 118.00</dd>
        </dl>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================ CONTACTO -->
<section class="seccion contacto" id="contacto">
  <div class="contenedor dos-col dos-col-contacto">
    <div class="contacto-texto">
      <p class="sobretitulo sobretitulo-claro">Contacto</p>
      <h2>Empecemos con su integración</h2>
      <p class="lead">Déjenos sus datos y le responderemos al correo indicado con los siguientes pasos: creación de su cuenta, su plan y su acceso al portal.</p>
      <ul class="lista-check lista-check-claro">
        <li><?= ico('check', 'ico ico-chico') ?><span>Acceso al entorno de pruebas para integrar sin riesgo.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span>Documentación OpenAPI y colección de Postman.</span></li>
        <li><?= ico('check', 'ico ico-chico') ?><span>Portal para administrar llaves, webhooks y certificado.</span></li>
      </ul>
    </div>

    <div class="formulario-caja">
      <div id="contacto-estado" role="status" aria-live="polite">
        <?php if ($estadoContacto === 'ok'): ?>
          <div class="aviso aviso-ok"><strong>¡Gracias!</strong> Recibimos su solicitud. Le responderemos al correo que nos indicó.</div>
        <?php elseif ($estadoContacto === 'error'): ?>
          <div class="aviso aviso-error"><strong>Revise el formulario.</strong> <?= isset($errores['general']) ? h($errores['general']) : 'Hay datos por corregir.' ?></div>
        <?php endif; ?>
      </div>
      <form id="form-contacto" method="post" action="<?= h($base . '/contacto') ?>" novalidate>
        <input type="hidden" name="_t" value="<?= h($tokenForm) ?>">
        <div class="trampa" aria-hidden="true"><label for="sitio_web">No llenar este campo</label><input id="sitio_web" type="text" name="sitio_web" tabindex="-1" autocomplete="off"></div>
        <div class="fila-2">
          <div class="campo"><label for="nombre">Nombre y apellido *</label>
            <input id="nombre" type="text" name="nombre" value="<?= h($valores['nombre']) ?>" maxlength="120" required autocomplete="name" <?= isset($errores['nombre']) ? 'aria-invalid="true" aria-describedby="err-nombre"' : '' ?>><?= $err('nombre') ?></div>
          <div class="campo"><label for="email">Correo *</label>
            <input id="email" type="email" name="email" value="<?= h($valores['email']) ?>" maxlength="190" required autocomplete="email" <?= isset($errores['email']) ? 'aria-invalid="true" aria-describedby="err-email"' : '' ?>><?= $err('email') ?></div>
        </div>
        <div class="fila-2">
          <div class="campo"><label for="empresa">Empresa</label>
            <input id="empresa" type="text" name="empresa" value="<?= h($valores['empresa']) ?>" maxlength="150" autocomplete="organization"></div>
          <div class="campo"><label for="telefono">Teléfono / WhatsApp</label>
            <input id="telefono" type="tel" name="telefono" value="<?= h($valores['telefono']) ?>" maxlength="30" autocomplete="tel" <?= isset($errores['telefono']) ? 'aria-invalid="true" aria-describedby="err-telefono"' : '' ?>><?= $err('telefono') ?></div>
        </div>
        <div class="campo"><label for="interes">Plan de interés</label>
          <select id="interes" name="interes">
            <option value="">Aún no lo sé</option>
            <?php foreach ($planes as $p): ?><option value="<?= h($p['nombre']) ?>" <?= $valores['interes'] === $p['nombre'] ? 'selected' : '' ?>><?= h($p['nombre']) ?></option><?php endforeach; ?>
            <option value="otro" <?= $valores['interes'] === 'otro' ? 'selected' : '' ?>>Otro / a medida</option>
          </select></div>
        <div class="campo"><label for="mensaje">Cuéntenos de su proyecto</label>
          <textarea id="mensaje" name="mensaje" rows="4" maxlength="1000" placeholder="¿Qué sistema usa? ¿Cuántos documentos emite al mes?"><?= h($valores['mensaje']) ?></textarea></div>
        <div class="campo campo-check"><input id="acepto" type="checkbox" name="acepto" value="1" <?= isset($errores['acepto']) ? 'aria-invalid="true" aria-describedby="err-acepto"' : '' ?>>
          <label for="acepto">Acepto que usen mis datos solo para responder esta solicitud.</label><?= $err('acepto') ?></div>
        <button class="boton boton-primario boton-grande boton-bloque" type="submit">Enviar solicitud</button>
      </form>
    </div>
  </div>
</section>

</main>

<?php include __DIR__ . '/_pie.php'; ?>

<script src="<?= h($base . '/assets/sitio.js?v=' . @filemtime(dirname(__DIR__) . '/assets/sitio.js')) ?>" defer></script>
</body>
</html>
