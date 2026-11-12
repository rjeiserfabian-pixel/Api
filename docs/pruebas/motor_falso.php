<?php
// Motor falso (solo para pruebas): imita las respuestas de los scripts del motor. Modo en fake_state.json
// Herramienta de DESARROLLO: no se usa en produccion. La arranca docs/probar_reintentos.php.
$dir = __DIR__ . '/tmp';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$ruta = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$estado = json_decode(@file_get_contents($dir . '/fake_state.json'), true) ?: array();
$modo = isset($estado['modo']) ? $estado['modo'] : 'ok';
$tmodo = isset($estado['ticket']) ? $estado['ticket'] : 'en_proceso';
$cuerpo = json_decode(file_get_contents('php://input'), true) ?: array();

// bitacora de llamadas (para contar cuantas veces se envio cada documento)
$ident = '';
foreach (array('venta' => 'numero', 'retencion' => 'numero', 'guia' => 'numero') as $k => $c) {
    if (isset($cuerpo[$k][$c])) {
        $ident = (isset($cuerpo[$k]['serie']) ? $cuerpo[$k]['serie'] : '') . '-' . $cuerpo[$k][$c];
    }
}
@file_put_contents($dir . '/motor_llamadas.log', json_encode(array('t' => date('H:i:s'), 'script' => $ruta, 'doc' => $ident, 'modo' => $modo)) . "\n", FILE_APPEND);

header('Content-Type: application/json');
if ($ruta === 'post_guia_ticket.php') {
    $t = isset($cuerpo['num_ticket']) ? $cuerpo['num_ticket'] : 'x';
    switch ($tmodo) {
        case 'aceptado':
            echo json_encode(array('data' => array('error' => 'false', 'estado' => 'ACEPTADO', 'respuesta_sunat_codigo' => '0', 'respuesta_sunat_descripcion' => 'GRE aceptada por SUNAT.', 'num_ticket' => $t)));
            break;
        case 'rechazado':
            echo json_encode(array('data' => array('error' => 'true', 'estado' => 'RECHAZADO_O_OBSERVADO', 'respuesta_sunat_codigo' => '2000', 'respuesta_sunat_descripcion' => 'Rechazo de prueba', 'num_ticket' => $t)));
            break;
        case 'error_rest':
            echo json_encode(array('data' => array('error' => 'true', 'estado' => 'ERROR_REST', 'respuesta_sunat_codigo' => 'REST_ERROR', 'respuesta_sunat_descripcion' => 'fallo tecnico', 'num_ticket' => $t)));
            break;
        case 'oauth':
            echo json_encode(array('data' => array('error' => 'true', 'respuesta_sunat_codigo' => 'OAUTH_ERROR_HTTP_401', 'respuesta_sunat_descripcion' => 'credenciales invalidas')));
            break;
        case 'html':
            http_response_code(503);
            header('Content-Type: text/html');
            echo '<html><body><h1>503 Service Unavailable</h1></body></html>';
            break;
        default: // en_proceso
            echo json_encode(array('data' => array('error' => 'true', 'estado' => 'EN_PROCESO', 'respuesta_sunat_codigo' => '98', 'respuesta_sunat_descripcion' => 'SUNAT aun esta procesando el ticket.', 'num_ticket' => $t)));
    }
    exit;
}

if ($modo === 'html') { // SUNAT limitando / caido: devuelve una pagina HTML en vez de JSON
    http_response_code(503);
    header('Content-Type: text/html');
    echo '<html><body><h1>503 Service Unavailable</h1></body></html>';
    exit;
}
if ($modo === 'falla_n') { // falla las primeras N llamadas
    $restantes = (int) $estado['restantes'];
    if ($restantes > 0) {
        $estado['restantes'] = $restantes - 1;
        file_put_contents($dir . '/fake_state.json', json_encode($estado));
        http_response_code(503);
        header('Content-Type: text/html');
        echo '<html><body>503</body></html>';
        exit;
    }
}
if ($modo === 'rechazo') {
    echo json_encode(array('data' => array('error' => 'true', 'respuesta_sunat_codigo' => 'soap-env:Client.3267', 'respuesta_sunat_descripcion' => 'Rechazo de prueba')));
    exit;
}
if ($ruta === 'post_guia.php') {
    echo json_encode(array('data' => array('error' => 'false', 'respuesta_sunat_codigo' => 'TICKET_GENERADO', 'respuesta_sunat_descripcion' => 'Documento GRE enviado correctamente.', 'num_ticket' => 'fake-' . $ident . '-' . mt_rand(100000, 999999),
        'ruta_xml' => 'http://127.0.0.1/API_SUNAT/files/facturacion_electronica/GUIA/FIRMA/fake.xml', 'codigo_hash' => 'FAKEHASH=')));
    exit;
}
if ($ruta === 'post_retencion.php') {
    echo json_encode(array('data' => array('respuesta_sunat_codigo' => '0', 'respuesta_sunat_descripcion' => 'El Comprobante ' . $ident . ' ha sido aceptado', 'ruta_xml' => 'http://127.0.0.1/API_SUNAT/files/facturacion_electronica/FIRMA/fake.xml', 'codigo_hash' => array('FAKEHASH='))));
    exit;
}
// post.php (factura, boleta, notas) y por defecto: aceptado
echo json_encode(array('data' => array('error' => 'false', 'respuesta_sunat_codigo' => '0', 'respuesta_sunat_descripcion' => 'El documento ' . $ident . ' ha sido aceptado (motor falso)',
    'ruta_xml' => 'http://127.0.0.1/API_SUNAT/files/facturacion_electronica/FIRMA/fake-' . $ident . '.xml',
    'ruta_cdr' => 'http://127.0.0.1/API_SUNAT/files/facturacion_electronica/CDR/R-fake-' . $ident . '.xml',
    'ruta_pdf' => 'http://127.0.0.1/API_SUNAT/files/facturacion_electronica/PDF/fake-' . $ident . '.pdf', 'codigo_hash' => 'FAKEHASH=')));
