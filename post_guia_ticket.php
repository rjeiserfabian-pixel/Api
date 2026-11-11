<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require('ws_sunat/lib/pclzip.lib.php');

$datos = file_get_contents('php://input');
$obj = json_decode($datos, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($obj)) {
    http_response_code(400);
    responderError('JSON_INVALIDO', 'El cuerpo enviado no es un JSON valido: ' . json_last_error_msg());
}

if (!isset($obj['empresa']) || !is_array($obj['empresa'])) {
    http_response_code(400);
    responderError('JSON_INCOMPLETO', 'Falta la seccion requerida: empresa');
}

$num_ticket = isset($obj['num_ticket']) ? trim($obj['num_ticket']) : (isset($obj['ticket']) ? trim($obj['ticket']) : '');
if ($num_ticket === '') {
    http_response_code(400);
    responderError('TICKET_REQUERIDO', 'Debe enviar num_ticket con el ticket devuelto por SUNAT.');
}

$empresa = $obj['empresa'];
$ruc = isset($empresa['ruc']) ? $empresa['ruc'] : '';
$user_sol = isset($empresa['usu_secundario_produccion_user']) ? $empresa['usu_secundario_produccion_user'] : '';
$pass_sol = isset($empresa['usu_secundario_produccion_password']) ? $empresa['usu_secundario_produccion_password'] : '';
$client_id = isset($empresa['gre_client_id']) ? $empresa['gre_client_id'] : '';
$client_secret = isset($empresa['gre_client_secret']) ? $empresa['gre_client_secret'] : '';

foreach (['ruc' => $ruc, 'usu_secundario_produccion_user' => $user_sol, 'usu_secundario_produccion_password' => $pass_sol, 'gre_client_id' => $client_id, 'gre_client_secret' => $client_secret] as $campo => $valor) {
    if ($valor === '') {
        http_response_code(400);
        responderError('CREDENCIALES_INCOMPLETAS', 'Falta empresa.' . $campo);
    }
}

$access_token_response = obtenerTokenOAuth($client_id, $client_secret, $ruc, $user_sol, $pass_sol);
if (is_array($access_token_response) && isset($access_token_response['error_oauth'])) {
    responderError('OAUTH_ERROR_HTTP_' . $access_token_response['http'], 'Error al obtener token OAuth: ' . $access_token_response['mensaje']);
}

$respuesta_sunat = consultarTicketGRE($num_ticket, $access_token_response);
$guardar = guardarCdrSiExiste($respuesta_sunat, $num_ticket, isset($obj['nombre_archivo']) ? $obj['nombre_archivo'] : '');

$codigo = isset($respuesta_sunat['codRespuesta']) ? (string)$respuesta_sunat['codRespuesta'] : '';
$descripcion = isset($respuesta_sunat['desRespuesta']) ? $respuesta_sunat['desRespuesta'] : '';
$error = 'true';
$estado = 'DESCONOCIDO';

if ($codigo === '0') {
    $error = 'false';
    $estado = 'ACEPTADO';
    if ($descripcion === '') $descripcion = 'GRE aceptada por SUNAT.';
} elseif ($codigo === '98') {
    $estado = 'EN_PROCESO';
    if ($descripcion === '') $descripcion = 'SUNAT aun esta procesando el ticket. Vuelva a consultar en unos minutos.';
} elseif ($codigo !== '') {
    $estado = 'RECHAZADO_O_OBSERVADO';
    if ($descripcion === '') $descripcion = 'SUNAT devolvio codigo ' . $codigo . '.';
} elseif (isset($respuesta_sunat['message'])) {
    $estado = 'ERROR_REST';
    $descripcion = $respuesta_sunat['message'];
}

echo json_encode([
    'data' => [
        'error' => $error,
        'estado' => $estado,
        'respuesta_sunat_codigo' => $codigo !== '' ? $codigo : (isset($respuesta_sunat['status']) ? $respuesta_sunat['status'] : 'REST_ERROR'),
        'respuesta_sunat_descripcion' => $descripcion,
        'num_ticket' => $num_ticket,
        'cdr_guardado' => $guardar,
        'respuesta_sunat' => $respuesta_sunat
    ]
], JSON_UNESCAPED_UNICODE);
exit;

function responderError($codigo, $descripcion) {
    echo json_encode([
        'data' => [
            'error' => 'true',
            'respuesta_sunat_codigo' => $codigo,
            'respuesta_sunat_descripcion' => $descripcion
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function obtenerTokenOAuth($client_id, $client_secret, $ruc, $user_sol, $pass_sol) {
    $token_url = "https://api-seguridad.sunat.gob.pe/v1/clientessol/{$client_id}/oauth2/token/";

    $post_data = http_build_query([
        'grant_type' => 'password',
        'scope' => 'https://api-cpe.sunat.gob.pe',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'username' => $ruc . $user_sol,
        'password' => $pass_sol
    ], '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return ['error_oauth' => true, 'mensaje' => 'Error cURL: ' . $curl_error, 'http' => $httpcode];
    }

    $data = json_decode($result, true);
    if (!isset($data['access_token'])) {
        return ['error_oauth' => true, 'mensaje' => $result, 'http' => $httpcode];
    }

    return $data['access_token'];
}

function consultarTicketGRE($num_ticket, $access_token) {
    $ticket = rawurlencode($num_ticket);
    $url = "https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/comprobantes/envios/{$ticket}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Accept: application/json',
        'User-Agent: API_SUNAT/1.0'
    ]);

    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        return [
            'error' => 'CURL_ERROR',
            'error_description' => $curl_error,
            'http_code' => $httpcode,
            'request_url' => $url
        ];
    }

    $data = json_decode($result, true);
    if ($data === null) {
        return [
            'error' => 'PARSE_ERROR',
            'error_description' => $result,
            'http_code' => $httpcode,
            'request_url' => $url
        ];
    }

    $data['http_code'] = $httpcode;
    $data['request_url'] = $url;
    return $data;
}

function guardarCdrSiExiste($respuesta_sunat, $num_ticket, $nombre_archivo) {
    if (empty($respuesta_sunat['arcCdr'])) {
        return null;
    }

    $ruta_cdr = __DIR__ . '/files/facturacion_electronica/GUIA/CDR/';
    if (!is_dir($ruta_cdr)) {
        mkdir($ruta_cdr, 0777, true);
    }

    $base = $nombre_archivo !== '' ? 'R-' . preg_replace('/[^A-Za-z0-9_\-.]/', '_', $nombre_archivo) : 'R-TICKET-' . preg_replace('/[^A-Za-z0-9_\-.]/', '_', $num_ticket);
    $zip_path = $ruta_cdr . $base . '.zip';
    file_put_contents($zip_path, base64_decode($respuesta_sunat['arcCdr']));

    $archive_cdr = new PclZip($zip_path);
    $extract = $archive_cdr->extract(PCLZIP_OPT_PATH, $ruta_cdr);

    return [
        'zip' => 'files/facturacion_electronica/GUIA/CDR/' . basename($zip_path),
        'extraido' => $extract != 0
    ];
}