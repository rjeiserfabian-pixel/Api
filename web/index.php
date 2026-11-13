<?php
/**
 * Sitio publico (pagina promocional) del servicio de facturacion electronica.
 * - Los planes que se muestran salen de la base de datos (los que el operador marca como "publicos").
 * - El formulario de contacto guarda la solicitud en la tabla "contactos" (el operador la ve en su panel).
 * - Sin scripts ni estilos en linea: la politica de seguridad solo permite los archivos de /assets/.
 */
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
require_once $raiz . '/v1/src/Db.php';
require_once $raiz . '/v1/src/Planes.php';
require_once $raiz . '/v1/src/Crypto.php';
require_once $raiz . '/portal/src/Cobros.php';
require_once $raiz . '/v1/src/Verificacion.php';
date_default_timezone_set('America/Lima');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $raiz . '/logs/php_errors.log');
error_reporting(E_ALL);

header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
header_remove('X-Powered-By');

function h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (api_env('PORTAL_PROXY_CONFIABLE') === '1' && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
if ($https) {
    header('Strict-Transport-Security: max-age=31536000');
}

// ---------------------------------------------------------------- direcciones
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');       // /API_SUNAT/web   (o '' si el sitio esta en la raiz)
$appUrl = api_env('SITIO_URL_APP');                                                    // direccion de la API/portal/docs, si no cuelgan de la carpeta superior
if ($appUrl === null || $appUrl === false || $appUrl === '') {
    $appUrl = rtrim(str_replace('\\', '/', dirname($base)), '/');
    if ($appUrl === '.' || $appUrl === '\\') {
        $appUrl = '';
    }
}
$appUrl = rtrim($appUrl, '/');
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$apiUrl = api_env('API_BASE_URL') ? rtrim(api_env('API_BASE_URL'), '/') . '/v1' : ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', $host) . $appUrl . '/v1';
$marca = api_env('SITIO_NOMBRE') ? mb_substr((string) api_env('SITIO_NOMBRE'), 0, 40, 'UTF-8') : 'API SUNAT';
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$ruta = '/' . trim((string) substr($path, strlen($base)), '/');
$metodo = $_SERVER['REQUEST_METHOD'];
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

Db::asegurarEsquema();

// ---------------------------------------------------------------- planes publicos
function planesPublicos()
{
    try {
        return Db::consultar('SELECT * FROM planes WHERE activo = 1 AND publico = 1 ORDER BY (cuota_mensual IS NULL), cuota_mensual, id');
    } catch (Exception $e) {
        return array();
    }
}
$planes = planesPublicos();
$nombresPlanes = array();
foreach ($planes as $p) {
    $nombresPlanes[] = $p['nombre'];
}

// ---------------------------------------------------------------- formulario de contacto
/** Token anti-robots: la hora de carga firmada. Un formulario enviado en menos de 3 s no lo llena una persona. */
function tokenForm()
{
    $t = (string) time();
    try {
        return $t . '.' . substr(Crypto::firmar('form|' . $t), 0, 24);
    } catch (Exception $e) {
        return '';
    }
}
function tokenFormValido($tok)
{
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{24})$/', (string) $tok, $m)) {
        return false;
    }
    try {
        if (!hash_equals(substr(Crypto::firmar('form|' . $m[1]), 0, 24), $m[2])) {
            return false;
        }
    } catch (Exception $e) {
        return true; // sin llave configurada no se puede firmar: no se bloquea a nadie
    }
    $edad = time() - (int) $m[1];
    return $edad >= 3 && $edad <= 86400;
}

$errores = array();
$valores = array('nombre' => '', 'email' => '', 'empresa' => '', 'telefono' => '', 'interes' => '', 'mensaje' => '');
$estadoContacto = null; // 'ok' | 'error'
if (isset($_GET['plan']) && is_string($_GET['plan']) && in_array($_GET['plan'], $nombresPlanes, true)) {
    $valores['interes'] = $_GET['plan'];
}

$quiereJson = isset($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
function responderJson($status, $datos)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos);
    exit;
}

if ($ruta === '/contacto' && $metodo === 'POST') {
    // misma procedencia (si el navegador indica el origen)
    foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $c) {
        if (!empty($_SERVER[$c])) {
            $oh = parse_url($_SERVER[$c], PHP_URL_HOST);
            $op = parse_url($_SERVER[$c], PHP_URL_PORT);
            if (strcasecmp($oh . ($op ? ':' . $op : ''), $host) !== 0) {
                http_response_code(403);
                echo 'Solicitud no valida';
                exit;
            }
            break;
        }
    }
    $campo = function ($n, $max, $multilinea = false) {
        $v = isset($_POST[$n]) && is_string($_POST[$n]) ? trim(str_replace("\r\n", "\n", $_POST[$n])) : '';
        $v = $multilinea ? preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $v) : preg_replace('/[\x00-\x1F\x7F]/', '', $v);
        return mb_substr($v, 0, $max, 'UTF-8');
    };
    $valores = array('nombre' => $campo('nombre', 120), 'email' => $campo('email', 190), 'empresa' => $campo('empresa', 150), 'telefono' => $campo('telefono', 30),
        'interes' => $campo('interes', 60), 'mensaje' => $campo('mensaje', 1000, true));

    // Robots: casilla trampa llena o formulario enviado demasiado rapido -> se finge exito y no se guarda nada
    $robot = (isset($_POST['sitio_web']) && $_POST['sitio_web'] !== '') || !tokenFormValido(isset($_POST['_t']) ? $_POST['_t'] : '');
    if ($robot) {
        if ($quiereJson) {
            responderJson(200, array('ok' => true));
        }
        header('Location: ' . $base . '/?contacto=ok#contacto');
        exit;
    }
    if (mb_strlen($valores['nombre'], 'UTF-8') < 2) {
        $errores['nombre'] = 'Indique su nombre.';
    }
    if (!filter_var($valores['email'], FILTER_VALIDATE_EMAIL)) {
        $errores['email'] = 'Indique un correo válido.';
    }
    if ($valores['telefono'] !== '' && !preg_match('/^[0-9 +()\-]{6,30}$/', $valores['telefono'])) {
        $errores['telefono'] = 'El teléfono solo puede tener números, espacios, + ( ) y guion.';
    }
    if ($valores['interes'] !== '' && $valores['interes'] !== 'otro' && !in_array($valores['interes'], $nombresPlanes, true)) {
        $valores['interes'] = '';
    }
    if (empty($_POST['acepto'])) {
        $errores['acepto'] = 'Debe aceptar que usemos sus datos para responderle.';
    }
    if (!$errores) {
        // límites: 5 solicitudes por hora por IP y 300 por dia en total (el campo "ventana" de la tabla se mide en minutos)
        $hora = (int) floor(time() / 3600);
        $dia = (int) floor(time() / 86400);
        $porIp = Planes::incrementar('contacto:ip:' . $ip . ':' . $hora, ($hora + 1) * 60);
        $global = Planes::incrementar('contacto:dia:' . $dia, ($dia + 1) * 1440);
        if ($porIp > 5 || $global > 300) {
            $errores['general'] = 'Recibimos demasiadas solicitudes. Intente de nuevo más tarde.';
        }
    }
    if (!$errores) {
        Db::ejecutar('INSERT INTO contactos (nombre, email, empresa, telefono, interes, mensaje, estado, ip, creado) VALUES (?,?,?,?,?,?,?,?,?)',
            array($valores['nombre'], strtolower($valores['email']), $valores['empresa'] !== '' ? $valores['empresa'] : null, $valores['telefono'] !== '' ? $valores['telefono'] : null,
                $valores['interes'] !== '' ? $valores['interes'] : null, $valores['mensaje'] !== '' ? $valores['mensaje'] : null, 'nuevo', substr($ip, 0, 45), Db::ahora()));
        if ($quiereJson) {
            responderJson(200, array('ok' => true));
        }
        header('Location: ' . $base . '/?contacto=ok#contacto');
        exit;
    }
    if ($quiereJson) {
        responderJson(422, array('ok' => false, 'errores' => $errores));
    }
    $estadoContacto = 'error';
    http_response_code(422);
} elseif (preg_match('#^/verificar(?:/([A-Za-z0-9\-]{20,60}))?(/qr)?$#', $ruta, $mv) && (isset($mv[1]) || !isset($mv[2])) && in_array($metodo, array('GET', 'HEAD', 'POST'), true)) {
    // ---- Verificacion de documentos: nunca se indexa ni se guarda en cache ----
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    $slug = isset($mv[1]) && $mv[1] !== '' ? $mv[1] : null;
    $resultado = null;
    $buscado = false;
    $limitado = false;
    $mensaje = null;
    $qrData = null;
    $form = array('ruc' => '', 'tipo' => '01', 'serie' => '', 'numero' => '', 'fecha' => '', 'total' => '');

    if (isset($mv[2])) { // /verificar/{codigo}/qr -> imagen PNG del enlace
        if (!Verificacion::dentroDelLimite($ip, 'web', 120)) {
            http_response_code(429);
            exit;
        }
        $f = Verificacion::porEnlace($slug);
        $p = $f ? Verificacion::publico($f) : null;
        if (!$p) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        echo Verificacion::qrPng($p['verificacion']['url']);
        exit;
    }
    if ($slug !== null && $metodo !== 'POST') { // enlace / QR del documento
        if (!Verificacion::dentroDelLimite($ip, 'web', 60)) {
            $limitado = true;
        } else {
            $buscado = true;
            $f = Verificacion::porEnlace($slug);
            $resultado = $f ? Verificacion::publico($f) : null;
        }
    } elseif ($slug === null && $metodo === 'POST') { // consulta con los datos del documento
        foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $c) {
            if (!empty($_SERVER[$c])) {
                $oh = parse_url($_SERVER[$c], PHP_URL_HOST);
                $op = parse_url($_SERVER[$c], PHP_URL_PORT);
                if (strcasecmp($oh . ($op ? ':' . $op : ''), $host) !== 0) {
                    http_response_code(403);
                    echo 'Solicitud no valida';
                    exit;
                }
                break;
            }
        }
        $c = function ($n, $max) {
            $v = isset($_POST[$n]) && is_string($_POST[$n]) ? trim($_POST[$n]) : '';
            return mb_substr(preg_replace('/[\x00-\x1F\x7F]/', '', $v), 0, $max, 'UTF-8');
        };
        $form = array('ruc' => $c('ruc', 11), 'tipo' => $c('tipo', 2), 'serie' => strtoupper($c('serie', 8)), 'numero' => $c('numero', 8), 'fecha' => $c('fecha', 10), 'total' => $c('total', 12));
        $total = Verificacion::aCentimos(str_replace(',', '.', $form['total']));
        $fechaOk = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $form['fecha'], $fm) && checkdate((int) $fm[2], (int) $fm[3], (int) $fm[1]);
        if (!preg_match('/^\d{11}$/', $form['ruc']) || !in_array($form['tipo'], array('01', '03', '07', '08', '20'), true) || !preg_match('/^[A-Z0-9]{1,8}$/', $form['serie'])
            || !preg_match('/^\d{1,8}$/', $form['numero']) || !$fechaOk || $total === null) {
            $mensaje = 'Revise los datos: el RUC tiene 11 dígitos, la serie solo letras y números, el número solo dígitos, la fecha va como AAAA-MM-DD (por ejemplo 2026-11-13) y el total como 118.00.';
            http_response_code(422);
        } elseif (!Verificacion::dentroDelLimite($ip, 'web-datos', 20)) {
            $limitado = true;
            http_response_code(429);
        } else {
            $buscado = true;
            $f = Verificacion::porDatos($form['ruc'], $form['tipo'], $form['serie'], $form['numero'], $form['fecha'], $total);
            $resultado = $f ? Verificacion::publico($f) : null;
        }
    }
    if ($resultado !== null && !empty($resultado['verificacion'])) {
        $qrData = 'data:image/png;base64,' . base64_encode(Verificacion::qrPng($resultado['verificacion']['url']));
    }
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/views/verificar.php';
    exit;
} elseif ($ruta === '/robots.txt' && $metodo === 'GET') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nDisallow: /verificar\n";
    exit;
} elseif ($ruta === '/' && ($metodo === 'GET' || $metodo === 'HEAD')) {
    if (isset($_GET['contacto']) && $_GET['contacto'] === 'ok') {
        $estadoContacto = 'ok';
    }
} else {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>No encontrado</title><body><p>Página no encontrada. <a href="' . h($base . '/') . '">Ir al inicio</a></p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
$tokenForm = tokenForm();
include __DIR__ . '/views/inicio.php';
