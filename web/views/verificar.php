<?php
/**
 * Pagina publica de verificacion de documentos.
 * Variables: $marca $base $appUrl $docsUrl $portalUrl $slug (o null) $buscado $resultado (array publico o null) $limitado $mensaje $form
 */
$docsUrl = $appUrl . '/docs/';
$portalUrl = $appUrl . '/portal/';
$ancla = $base . '/';
$titulo = 'Verificar un documento · ' . $marca;
$simbolo = array('PEN' => 'S/', 'USD' => 'US$', 'EUR' => '€');
$tiposForm = array('01' => 'Factura', '03' => 'Boleta de venta', '07' => 'Nota de crédito', '08' => 'Nota de débito', '20' => 'Comprobante de retención');
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($titulo) ?></title>
<meta name="description" content="Compruebe que un comprobante electrónico fue emitido por <?= h($marca) ?> y consulte el estado que informó SUNAT.">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#07142b">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%231d4ed8'/%3E%3Cpath d='M9 16.5l4.5 4.5L23 11' fill='none' stroke='white' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<link rel="stylesheet" href="<?= h($base . '/assets/sitio.css?v=' . @filemtime(dirname(__DIR__) . '/assets/sitio.css')) ?>">
</head>
<body>
<?php include __DIR__ . '/_cabecera.php'; ?>

<main id="contenido">
<section class="seccion verif">
  <div class="contenedor angosto">
    <p class="sobretitulo">Verificación de documentos</p>
    <h1 class="verif-titulo">Verifique un comprobante electrónico</h1>
    <p class="lead">Compruebe que un documento fue emitido a través de <?= h($marca) ?> y vea el estado que informó SUNAT.</p>

    <?php if ($limitado): ?>
      <div class="resultado resultado-espera" role="alert"><strong>Demasiadas consultas.</strong> Intente de nuevo en unos minutos.</div>
    <?php elseif ($buscado && $resultado === null): ?>
      <div class="resultado resultado-gris" role="status">
        <strong>No encontramos un documento con esos datos.</strong>
        <p>Revise que el enlace esté completo o que el RUC, tipo, serie, número, <strong>fecha y total</strong> sean exactamente los del documento. Si el emisor no habilita la verificación pública, tampoco aparecerá aquí.</p>
      </div>
    <?php elseif ($buscado && $resultado !== null): $r = $resultado;
        if ($r['estado'] === 'aceptado' && $r['entorno'] === 'produccion') { $clase = 'ok'; $titulo2 = 'Documento válido'; $texto = 'Fue emitido por el emisor indicado y SUNAT lo aceptó.'; }
        elseif ($r['estado'] === 'aceptado') { $clase = 'espera'; $titulo2 = 'Documento de PRUEBA'; $texto = 'Se emitió en el entorno de pruebas: no tiene validez tributaria.'; }
        elseif ($r['estado'] === 'rechazado') { $clase = 'mal'; $titulo2 = 'Documento NO válido'; $texto = 'SUNAT rechazó este documento.'; }
        elseif ($r['estado'] === 'pendiente') { $clase = 'espera'; $titulo2 = 'En proceso'; $texto = 'SUNAT aún no confirma este documento. Vuelva a consultar más tarde.'; }
        else { $clase = 'gris'; $titulo2 = 'Sin validación de SUNAT'; $texto = 'Este documento no llegó a ser validado por SUNAT.'; }
    ?>
      <div class="resultado resultado-<?= h($clase) ?>" role="status">
        <span class="resultado-icono" aria-hidden="true"><?= $clase === 'ok' ? '✓' : ($clase === 'mal' ? '✕' : '!') ?></span>
        <div><strong><?= h($titulo2) ?></strong><p><?= h($texto) ?></p></div>
      </div>
      <div class="verif-detalle">
        <dl>
          <dt>Emisor</dt><dd><strong><?= h($r['emisor']['razon_social']) ?></strong><br>RUC <?= h($r['emisor']['ruc']) ?></dd>
          <dt>Documento</dt><dd><?= h($r['tipo_nombre']) ?><br><strong><?= h($r['serie']) ?>-<?= h($r['numero']) ?></strong></dd>
          <?php if ($r['fecha_emision'] !== null): ?><dt>Fecha de emisión</dt><dd><?= h(date('d/m/Y', strtotime($r['fecha_emision']))) ?></dd><?php endif; ?>
          <?php if ($r['total'] !== null): ?><dt>Importe total</dt><dd><strong><?= h((isset($simbolo[$r['moneda']]) ? $simbolo[$r['moneda']] : (string) $r['moneda']) . ' ' . number_format((float) $r['total'], 2, '.', ',')) ?></strong></dd><?php endif; ?>
          <dt>Entorno</dt><dd><?= $r['entorno'] === 'produccion' ? 'Producción' : 'Pruebas' ?></dd>
          <?php if ($r['sunat'] !== null): ?><dt>Respuesta de SUNAT</dt><dd><?= h($r['sunat']['descripcion']) ?><?= $r['sunat']['codigo'] !== null && $r['sunat']['codigo'] !== '' ? ' <span class="verif-cod">(código ' . h($r['sunat']['codigo']) . ')</span>' : '' ?></dd><?php endif; ?>
          <?php if (!empty($r['hash'])): ?><dt>Huella de la firma</dt><dd class="verif-hash"><?= h($r['hash']) ?></dd><?php endif; ?>
        </dl>
        <?php if (!empty($r['verificacion']) && !empty($qrData)): ?>
          <div class="verif-qr">
            <img src="<?= h($qrData) ?>" width="150" height="150" alt="Código QR de este enlace de verificación">
            <span>Escanee para volver a verificar</span>
          </div>
        <?php endif; ?>
      </div>
      <p class="verif-nota">Esta consulta informa lo que SUNAT respondió cuando el emisor envió el documento. La validez tributaria la confirma SUNAT: también puede comprobarla en el servicio de <a href="https://www.sunat.gob.pe" rel="noopener noreferrer">consulta de validez de comprobantes de pago de SUNAT</a>. No se muestran datos del comprador.</p>
      <p><a class="boton boton-contorno-oscuro" href="<?= h($base . '/verificar') ?>">Verificar otro documento</a></p>
    <?php endif; ?>

    <?php if (!$buscado || $resultado === null): ?>
    <div class="formulario-caja verif-form">
      <h2>Consulta con los datos del documento</h2>
      <p class="ayuda-form">Escriba <strong>todos</strong> los datos tal como aparecen en el comprobante. Si tiene el código QR o el enlace de verificación impreso, escanéelo: es más rápido.</p>
      <?php if ($mensaje): ?><div class="aviso aviso-error" role="alert"><?= h($mensaje) ?></div><?php endif; ?>
      <form method="post" action="<?= h($base . '/verificar') ?>" novalidate>
        <div class="fila-2">
          <div class="campo"><label for="ruc">RUC del emisor</label><input id="ruc" type="text" name="ruc" value="<?= h($form['ruc']) ?>" inputmode="numeric" maxlength="11" required></div>
          <div class="campo"><label for="tipo">Tipo de documento</label>
            <select id="tipo" name="tipo"><?php foreach ($tiposForm as $k => $v): ?><option value="<?= h($k) ?>" <?= $form['tipo'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="fila-2">
          <div class="campo"><label for="serie">Serie</label><input id="serie" type="text" name="serie" value="<?= h($form['serie']) ?>" maxlength="8" placeholder="F001 / B001" required></div>
          <div class="campo"><label for="numero">Número</label><input id="numero" type="text" name="numero" value="<?= h($form['numero']) ?>" inputmode="numeric" maxlength="8" placeholder="150" required></div>
        </div>
        <div class="fila-2">
          <div class="campo"><label for="fecha">Fecha de emisión</label><input id="fecha" type="text" name="fecha" value="<?= h($form['fecha']) ?>" maxlength="10" placeholder="AAAA-MM-DD" required></div>
          <div class="campo"><label for="total">Importe total</label><input id="total" type="text" name="total" value="<?= h($form['total']) ?>" inputmode="decimal" maxlength="12" placeholder="118.00" required></div>
        </div>
        <button class="boton boton-primario boton-grande boton-bloque" type="submit">Verificar</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</section>
</main>

<?php include __DIR__ . '/_pie.php'; ?>
</body>
</html>
