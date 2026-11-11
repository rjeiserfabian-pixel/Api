<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require('lib/pclzip.lib.php');

// =========================================================
// WEB SERVICE SUNAT — GUÍAS DE REMISIÓN ELECTRÓNICAS (GRE)
// ---------------------------------------------------------
// Las GRE usan obligatoriamente el NUEVO sistema REST con OAuth 2.0.
// (Error 1085 de SUNAT obliga a usar esta vía).
// Se requiere Client ID y Client Secret obtenidos desde
// SUNAT SOL → Gestión de Credenciales de API.
//
// Token Beta/Prod:  https://api-seguridad.sunat.gob.pe/v1/clientessol/{client_id}/oauth2/token/
// API Beta:         https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/comprobantes/{nombre_archivo}
// API Producción:   https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/comprobantes/{nombre_archivo}
// =========================================================

// Parámetros GET recibidos desde post_guia.php:
$NomArch      = $_GET['numero_documento'];
$entorno      = $_GET['cod_2'];
$ruc          = $_GET['cod_3'];
$user_sol     = $_GET['cod_4'];
$pass_sol     = $_GET['cod_5'];
$client_id    = isset($_GET['cod_9'])  ? $_GET['cod_9']  : '';
$client_secret = isset($_GET['cod_10']) ? $_GET['cod_10'] : '';

$ruta     = '../files/facturacion_electronica/GUIA/FIRMA/';
$ruta_cdr = '../files/facturacion_electronica/GUIA/CDR/';

// =========================================================
// Validar que se proporcionaron las credenciales OAuth
// =========================================================
if (empty($client_id) || empty($client_secret)) {
    echo json_encode([
        'error'                       => 'true',
        'respuesta_sunat_codigo'      => 'GRE_CREDENTIALS_MISSING',
        'respuesta_sunat_descripcion' => 'SUNAT exige usar el nuevo sistema GRE. Las credenciales GRE (gre_client_id y gre_client_secret) son obligatorias. Obténgalas en SUNAT SOL → Gestión de Credenciales de API.'
    ]);
    exit;
}

// =========================================================
// Crear ZIP desde el XML firmado
// =========================================================
$rutaZip = $ruta . $NomArch . '.zip';
$rutaXml = $ruta . $NomArch . '.xml';

if (file_exists($rutaZip)) {
    unlink($rutaZip);
}

if (!file_exists($rutaXml) || filesize($rutaXml) == 0) {
    echo json_encode(['error' => 'true', 'respuesta_sunat_codigo' => 'FILE_ERROR', 'respuesta_sunat_descripcion' => 'XML vacío.']);
    exit;
}

$zip    = new PclZip($rutaZip);
$result = $zip->add($rutaXml, PCLZIP_OPT_REMOVE_PATH, $ruta);

if ($result == 0) {
    echo json_encode(['error' => 'true', 'respuesta_sunat_codigo' => 'ZIP_ERROR', 'respuesta_sunat_descripcion' => $zip->errorInfo(true)]);
    exit;
}
chmod($rutaZip, 0777);

// =========================================================
// PASO 1: Obtener token OAuth 2.0 de SUNAT
// =========================================================
$access_token_response = obtenerTokenOAuth($client_id, $client_secret, $ruc, $user_sol, $pass_sol);

if (is_array($access_token_response) && isset($access_token_response['error_oauth'])) {
    echo json_encode([
        'error'                       => 'true',
        'respuesta_sunat_codigo'      => 'OAUTH_ERROR (HTTP ' . $access_token_response['http'] . ')',
        'respuesta_sunat_descripcion' => 'Error al obtener token OAuth: ' . $access_token_response['mensaje']
    ]);
    exit;
}

$access_token = $access_token_response;

// =========================================================
// PASO 2: Preparar contenido del ZIP en base64 + hash SHA256
// =========================================================
$zip_contenido = file_get_contents($rutaZip);
$zip_base64    = base64_encode($zip_contenido);
$hash_zip      = hash('sha256', $zip_contenido);

// =========================================================
// PASO 3: Enviar documento GRE al endpoint REST de SUNAT
// =========================================================
$respuesta_sunat = enviarDocumentoGRE($NomArch, $ruc, $access_token, $zip_base64, $hash_zip, $entorno);

// =========================================================
// PASO 4: Procesar respuesta
// =========================================================
$ruta_general = carpeta_actual() . '/files/facturacion_electronica/GUIA/';
$xml_base_64  = $zip_base64;

// Guardar respuesta cruda para debug
file_put_contents($ruta_cdr . 'debug_rest_response.json', json_encode($respuesta_sunat));

if (isset($respuesta_sunat['numTicket']) && $respuesta_sunat['numTicket'] != '') {
    $codigo_hash = obtenerHash($NomArch, $ruta);

    $resultado = [
        'error'                       => 'false',
        'respuesta_sunat_codigo'      => 'TICKET_GENERADO',
        'respuesta_sunat_descripcion' => 'Documento GRE enviado correctamente. SUNAT devolvio ticket para consulta posterior.',
        'num_ticket'                  => $respuesta_sunat['numTicket'],
        'fecha_recepcion'             => isset($respuesta_sunat['fecRecepcion']) ? $respuesta_sunat['fecRecepcion'] : '',
        'ruta_xml'                    => $ruta_general . 'FIRMA/' . $NomArch . '.xml',
        'codigo_hash'                 => $codigo_hash,
        'xml_base_64'                 => $xml_base_64,
        'respuesta_sunat'             => $respuesta_sunat
    ];
} elseif (isset($respuesta_sunat['codRespuesta']) && ($respuesta_sunat['codRespuesta'] === '0' || $respuesta_sunat['codRespuesta'] === '98')) {
    $cdr_base_64 = '';
    if (!empty($respuesta_sunat['arcCdr'])) {
        $cdr_bytes   = base64_decode($respuesta_sunat['arcCdr']);
        $cdr_zip_ruta = $ruta_cdr . 'R-' . $NomArch . '.zip';
        file_put_contents($cdr_zip_ruta, $cdr_bytes);
        $cdr_base_64 = $respuesta_sunat['arcCdr'];

        $archive_cdr = new PclZip($cdr_zip_ruta);
        $archive_cdr->extract(PCLZIP_OPT_PATH, $ruta_cdr);
    }

    $codigo_hash = obtenerHash($NomArch, $ruta);

    $resultado = [
        'error'                       => 'false',
        'respuesta_sunat_codigo'      => $respuesta_sunat['codRespuesta'],
        'respuesta_sunat_descripcion' => isset($respuesta_sunat['excCdr']) ? 'Aceptado con obs: '.$respuesta_sunat['excCdr'] : 'Aceptado correctamente.',
        'ruta_xml'                    => $ruta_general . 'FIRMA/' . $NomArch . '.xml',
        'ruta_cdr'                    => $ruta_general . 'CDR/R-' . $NomArch . '.xml',
        'codigo_hash'                 => $codigo_hash,
        'xml_base_64'                 => $xml_base_64,
        'cdr_base_64'                 => $cdr_base_64
    ];
} else {
    // Si contiene "error", estructurar el mensaje
    if (isset($respuesta_sunat['error'])) {
        $desc = isset($respuesta_sunat['error_description']) ? $respuesta_sunat['error_description'] : json_encode($respuesta_sunat);
        $code = isset($respuesta_sunat['error']) ? $respuesta_sunat['error'] : 'REST_ERROR';
    } elseif (isset($respuesta_sunat['message']) || isset($respuesta_sunat['status'])) {
        $desc = json_encode($respuesta_sunat);
        $code = 'REST_HTTP_' . (isset($respuesta_sunat['http_code']) ? $respuesta_sunat['http_code'] : (isset($respuesta_sunat['status']) ? $respuesta_sunat['status'] : 'ERROR'));
    } else {
        $desc = isset($respuesta_sunat['desRespuesta']) ? $respuesta_sunat['desRespuesta'] : json_encode($respuesta_sunat);
        $code = isset($respuesta_sunat['codRespuesta']) ? $respuesta_sunat['codRespuesta'] : 'REST_ERROR';
    }

    $resultado = [
        'error'                       => 'true',
        'respuesta_sunat_codigo'      => $code,
        'respuesta_sunat_descripcion' => $desc,
        'debug_rest'                  => [
            'http_code' => isset($respuesta_sunat['http_code']) ? $respuesta_sunat['http_code'] : '',
            'request_url' => isset($respuesta_sunat['request_url']) ? $respuesta_sunat['request_url'] : '',
            'jwt_payload' => isset($respuesta_sunat['jwt_payload']) ? $respuesta_sunat['jwt_payload'] : null,
            'intentos' => isset($respuesta_sunat['debug_intentos']) ? $respuesta_sunat['debug_intentos'] : []
        ]
    ];
}

if (file_exists($rutaZip)) unlink($rutaZip);

echo json_encode($resultado);
exit;

// =========================================================
// FUNCIÓN: Obtener token OAuth 2.0 desde SUNAT
// =========================================================
function obtenerTokenOAuth($client_id, $client_secret, $ruc, $user_sol, $pass_sol) {
    $token_url = "https://api-seguridad.sunat.gob.pe/v1/clientessol/{$client_id}/oauth2/token/";

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // GRE usa el flujo SOL con grant_type=password y el scope del API CPE.
    $post_data = http_build_query([
        'grant_type'    => 'password',
        'scope'         => 'https://api-cpe.sunat.gob.pe',
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'username'      => $ruc . $user_sol,
        'password'      => $pass_sol
    ], '', '&', PHP_QUERY_RFC3986);

    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($result === false) {
        file_put_contents('respuesta_oauth.txt', 'CURL_ERROR: ' . $curl_error);
        return ['error_oauth' => true, 'mensaje' => 'Error cURL: ' . $curl_error, 'http' => $httpcode];
    }

    $data = json_decode($result, true);
    
    // Si falla, guardamos el error en un archivo para depurar o lo devolvemos
    if (!isset($data['access_token'])) {
        file_put_contents('respuesta_oauth.txt', $result);
        return ['error_oauth' => true, 'mensaje' => $result, 'http' => $httpcode];
    }
    
    return $data['access_token'];
}

// =========================================================
// FUNCIÓN: Enviar documento GRE al endpoint REST
// =========================================================
function enviarDocumentoGRE($NomArch, $ruc, $access_token, $zip_base64, $hash_zip, $entorno) {
    $documento = rawurlencode($NomArch);
    $body = json_encode([
        'archivo' => [
            'nomArchivo' => $NomArch . '.zip',
            'arcGreZip'  => $zip_base64,
            'hashZip'    => $hash_zip
        ]
    ]);

    $jwt_payload = decodificarJwtPayload($access_token);
    $hosts = [
        "https://api-cpe.sunat.gob.pe/v1/contribuyente/gem/comprobantes/{$documento}"
    ];

    $intentos = [];
    foreach ($hosts as $api_url) {
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: API_SUNAT/1.0'
        ]);

        $result = curl_exec($ch);
        $curl_error = curl_error($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false) {
            $data = ['error' => 'CURL_ERROR', 'error_description' => $curl_error];
        } else {
            $data = json_decode($result, true);
            if ($data === null) {
                $data = ['error' => 'PARSE_ERROR', 'error_description' => $result];
            }
        }

        $data['http_code'] = $httpcode;
        $data['request_url'] = $api_url;
        $intentos[] = [
            'http_code' => $httpcode,
            'request_url' => $api_url,
            'respuesta' => $data
        ];

        if ($httpcode < 400) {
            $data['jwt_payload'] = $jwt_payload;
            $data['debug_intentos'] = $intentos;
            return $data;
        }

        break;
    }

    $data['jwt_payload'] = $jwt_payload;
    $data['debug_intentos'] = $intentos;
    return $data;
}

// =========================================================
// Funciones auxiliares
// =========================================================
function decodificarJwtPayload($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;

    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $json = base64_decode($payload);
    $data = json_decode($json, true);

    return is_array($data) ? $data : null;
}
function obtenerHash($NomArch, $ruta) {
    $archivo = $ruta . $NomArch . '.xml';
    if (!file_exists($archivo)) return '';
    $xml = simplexml_load_file($archivo);
    foreach ($xml->xpath('//ds:DigestValue') as $response) {
    }
    return isset($response) ? (string)$response : '';
}

function carpeta_actual() {
    $archivo_actual = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $dir            = explode('/', $archivo_actual);
    $cadena         = '';
    for ($i = 0; $i < (count($dir) - 2); $i++) {
        $cadena .= $dir[$i] . '/';
    }
    return substr($cadena, 0, -1);
}
