<?php
/**
 * Portal de clientes (punto de entrada unico). Paginas HTML generadas en el servidor: sin API de datos propia, sin tokens en el
 * navegador, sin scripts en linea. Cada consulta esta limitada a la empresa del usuario con sesion.
 */
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Certificados', 'Validador', 'Motor', 'Series', 'Planes', 'Documentos', 'Webhooks') as $c) {
    require_once $raiz . '/v1/src/' . $c . '.php';
}
foreach (array('Vista', 'Sesion', 'Usuarios', 'Suscripciones') as $c) {
    require_once __DIR__ . '/src/' . $c . '.php';
}
date_default_timezone_set('America/Lima');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $raiz . '/logs/php_errors.log');
error_reporting(E_ALL);

// ---------------------------------------------------------------- cabeceras de seguridad (en TODA respuesta)
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Content-Type: text/html; charset=utf-8');
header_remove('X-Powered-By');
if (Sesion::esHttps()) {
    header('Strict-Transport-Security: max-age=31536000');
}

Vista::$base = Sesion::base();

function terminar($status, $titulo, $mensaje)
{
    http_response_code($status);
    Vista::render('error', array('titulo' => $titulo, 'mensaje' => $mensaje), false);
    exit;
}

set_exception_handler(function ($e) {
    error_log('[portal] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    // Mensaje generico: nunca se muestran detalles internos
    Vista::render('error', array('titulo' => 'Algo salió mal', 'mensaje' => 'No pudimos completar la operación. Intente de nuevo; si persiste, contacte al administrador.'), false);
});

if (api_env('PORTAL_REQUIERE_HTTPS') === '1' && !Sesion::esHttps()) {
    $destino = (string) api_env('API_BASE_URL');
    if (stripos($destino, 'https://') === 0) { // solo se redirige a la direccion publica CONFIGURADA (nunca a la cabecera Host)
        header('Location: ' . rtrim($destino, '/') . '/portal/', true, 301);
        exit;
    }
    terminar(403, 'Se requiere HTTPS', 'Por seguridad, el portal solo funciona por una conexión segura (HTTPS).');
}

Db::asegurarEsquema();
Sesion::iniciar();

$metodo = $_SERVER['REQUEST_METHOD'];
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$ruta = '/' . trim((string) substr($path, strlen(Sesion::base())), '/');
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

function ir($ruta)
{
    header('Location: ' . Vista::url($ruta)); // siempre una ruta fija del portal, nunca un dato del usuario
    exit;
}

/** Renderiza una pagina con el marco del portal. */
function pagina($vista, array $datos = array(), $menu = true, $status = 200)
{
    global $usuario, $empresa, $entorno, $ruta;
    http_response_code($status);
    $base = array('csrf' => Sesion::token(), 'flash' => Sesion::tomarFlash(), 'secreto' => Sesion::tomarSecreto(), 'rutaActual' => $ruta);
    if ($menu) {
        $base += array('usuario' => $usuario, 'empresa' => $empresa, 'entorno' => $entorno);
    }
    Vista::render($vista, array_merge($base, $datos), $menu);
    exit;
}

// ---------------------------------------------------------------- CSRF en toda accion de escritura
if ($metodo === 'POST' && !Sesion::csrfValido()) {
    terminar(403, 'Solicitud no válida', 'La sesión de seguridad de este formulario venció o la solicitud no es válida. Vuelva a cargar la página e intente de nuevo.');
}

// ---------------------------------------------------------------- acceso
if ($ruta === '/login') {
    if (Sesion::usuarioId()) {
        ir('/');
    }
    if ($metodo === 'POST') {
        $email = isset($_POST['email']) ? (string) $_POST['email'] : '';
        $clave = isset($_POST['clave']) ? (string) $_POST['clave'] : '';
        $u = (strlen($email) <= 190 && strlen($clave) <= 128) ? Usuarios::autenticar($email, $clave, $ip) : null;
        if (!$u) {
            Sesion::flash('error', 'Correo o contraseña incorrectos, o cuenta bloqueada temporalmente.'); // mismo mensaje ante cualquier fallo
            pagina('login', array('email' => substr($email, 0, 190), 'expirada' => null), false, 401);
        }
        $u = Usuarios::porId($u['id']); // (la contrasena pudo re-cifrarse al autenticar)
        Sesion::entrar($u['id']);
        $_SESSION['ch'] = Sesion::huellaClave($u['clave_hash']);
        ir($u['debe_cambiar'] ? '/cuenta' : '/');
    }
    $exp = isset($_SESSION['expirada']) ? $_SESSION['expirada'] : null;
    unset($_SESSION['expirada']);
    pagina('login', array('email' => '', 'expirada' => $exp), false);
}

$usuario = Sesion::usuarioId() ? Usuarios::porId(Sesion::usuarioId()) : null;
$empresa = $usuario ? Db::uno('SELECT * FROM empresas WHERE id = ?', array($usuario['empresa_id'])) : null;
if (!$usuario || (int) $usuario['activo'] !== 1 || !$empresa || (int) $empresa['activo'] !== 1) {
    if ($usuario !== null || Sesion::usuarioId()) {
        Sesion::destruir();
    }
    ir('/login');
}
$entorno = (isset($_SESSION['entorno']) && $_SESSION['entorno'] === 'live') ? 'live' : 'test';
$ctx = array('empresa' => $empresa, 'key' => array('id' => 0, 'entorno' => $entorno)); // lo unico que usa el dominio: la empresa de la SESION
$empresaId = (int) $empresa['id'];
$esAdmin = $usuario['rol'] === 'admin';
if (isset($_SESSION['ch']) && !hash_equals($_SESSION['ch'], Sesion::huellaClave($usuario['clave_hash']))) {
    Sesion::destruir(); // la contrasena cambio desde otra sesion o el operador la restablecio
    ir('/login');
}

if ($ruta === '/logout' && $metodo === 'POST') {
    Auditoria::registrar($empresaId, $usuario['id'], 'logout', '');
    Sesion::destruir();
    ir('/login');
}
if ($usuario['debe_cambiar'] && !in_array($ruta, array('/cuenta', '/cuenta/clave'), true)) {
    ir('/cuenta');
}

function soloAdmin()
{
    global $esAdmin;
    if (!$esAdmin) {
        terminar(403, 'Acceso restringido', 'Su rol es de solo lectura: esta acción requiere un usuario administrador.');
    }
}

/** Pide la contrasena otra vez para acciones sensibles (protege ante una sesion abierta o robada). */
function confirmarOVolver($volverA)
{
    global $usuario;
    if (!Usuarios::confirmarClave($usuario, isset($_POST['clave_actual']) ? (string) $_POST['clave_actual'] : '')) {
        Sesion::flash('error', 'La contraseña de confirmación no es correcta.');
        ir($volverA);
    }
}

function leerArchivo($campo, $maximo = 102400)
{
    if (!isset($_FILES[$campo]) || !is_array($_FILES[$campo]) || $_FILES[$campo]['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$campo]['tmp_name'])) {
        throw new ApiError(422, 'archivo', 'No se recibió el archivo (' . $campo . ') o la subida falló.');
    }
    if ($_FILES[$campo]['size'] > $maximo) {
        throw new ApiError(422, 'archivo', 'El archivo es demasiado grande (máximo ' . (int) ($maximo / 1024) . ' KB).');
    }
    $c = file_get_contents($_FILES[$campo]['tmp_name']);
    if ($c === false || $c === '') {
        throw new ApiError(422, 'archivo', 'El archivo está vacío.');
    }
    return $c;
}

// ---------------------------------------------------------------- paginas
if ($ruta === '/entorno' && $metodo === 'POST') {
    $_SESSION['entorno'] = (isset($_POST['entorno']) && $_POST['entorno'] === 'live') ? 'live' : 'test';
    $permitidas = array('/', '/documentos', '/llaves', '/webhooks', '/certificado', '/credenciales', '/plan', '/cuenta', '/actividad');
    ir(in_array(isset($_POST['volver']) ? $_POST['volver'] : '', $permitidas, true) ? $_POST['volver'] : '/');
}

if ($ruta === '/' && $metodo === 'GET') {
    $desde = date('Y-m-d H:i:s', strtotime('-30 days'));
    $conteos = array();
    foreach (Db::consultar('SELECT estado, COUNT(*) AS n FROM documentos WHERE empresa_id = ? AND entorno = ? AND creado >= ? GROUP BY estado ORDER BY estado', array($empresaId, $entorno, $desde)) as $r) {
        $conteos[$r['estado']] = (int) $r['n'];
    }
    $recientes = array();
    foreach (Db::consultar('SELECT * FROM documentos WHERE empresa_id = ? AND entorno = ? ORDER BY id DESC LIMIT 8', array($empresaId, $entorno)) as $f) {
        $recientes[] = Documentos::formatear($f);
    }
    pagina('dashboard', array('titulo' => 'Resumen', 'seccion' => 'resumen', 'uso' => Planes::uso($ctx), 'conteos' => $conteos, 'recientes' => $recientes,
        'cert' => Certificados::estado($empresaId, $entorno),
        'nLlaves' => (int) Db::uno('SELECT COUNT(*) AS n FROM api_keys WHERE empresa_id = ? AND entorno = ? AND activo = 1', array($empresaId, $entorno))['n'],
        'nWebhooks' => (int) Db::uno('SELECT COUNT(*) AS n FROM webhooks WHERE empresa_id = ? AND entorno = ?', array($empresaId, $entorno))['n']));
}

if ($ruta === '/documentos' && $metodo === 'GET') {
    $tipos = array('01', '03', '07', '08', '20', '09', 'RC', 'RA', 'RR');
    $estados = array('aceptado', 'rechazado', 'pendiente', 'error', 'procesando', 'reservado', 'simulado');
    $f = array('tipo' => '', 'estado' => '', 'serie' => '', 'numero' => '');
    $where = 'empresa_id = ? AND entorno = ?';
    $p = array($empresaId, $entorno);
    if (isset($_GET['tipo']) && in_array($_GET['tipo'], $tipos, true)) {
        $f['tipo'] = $_GET['tipo'];
        $where .= ' AND tipo = ?';
        $p[] = $f['tipo'];
    }
    if (isset($_GET['estado']) && in_array($_GET['estado'], $estados, true)) {
        $f['estado'] = $_GET['estado'];
        $where .= ' AND estado = ?';
        $p[] = $f['estado'];
    }
    if (isset($_GET['serie']) && preg_match('/^[A-Za-z0-9]{1,16}$/', (string) $_GET['serie'])) {
        $f['serie'] = strtoupper($_GET['serie']);
        $where .= ' AND serie = ?';
        $p[] = $f['serie'];
    }
    if (isset($_GET['numero']) && preg_match('/^\d{1,8}$/', (string) $_GET['numero'])) {
        $f['numero'] = $_GET['numero'];
        $where .= ' AND numero_n = ?';
        $p[] = (int) $f['numero'];
    }
    $porPagina = 20;
    $total = (int) Db::uno("SELECT COUNT(*) AS n FROM documentos WHERE $where", $p)['n'];
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = isset($_GET['pagina']) && ctype_digit((string) $_GET['pagina']) ? min($paginas, max(1, (int) $_GET['pagina'])) : 1;
    $items = array();
    foreach (Db::consultar("SELECT * FROM documentos WHERE $where ORDER BY id DESC LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina), $p) as $fila) {
        $items[] = Documentos::formatear($fila);
    }
    pagina('documentos', array('titulo' => 'Documentos', 'seccion' => 'documentos', 'items' => $items, 'f' => $f, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas));
}

if (preg_match('#^/documentos/(doc_[a-f0-9]{20})$#', $ruta, $m) && $metodo === 'GET') {
    try {
        $d = Documentos::obtener($ctx, $m[1]); // ya filtra por la empresa de la sesion
    } catch (ApiError $e) {
        terminar(404, 'No encontrado', 'No existe ese documento.');
    }
    if (($d['entorno'] === 'produccion') !== ($entorno === 'live')) {
        terminar(404, 'No encontrado', 'Ese documento es de otro entorno. Cambie entre Pruebas y Producción (arriba a la derecha).');
    }
    pagina('documento', array('titulo' => 'Documento', 'seccion' => 'documentos', 'd' => $d));
}

// ---- llaves API
if ($ruta === '/llaves' && $metodo === 'GET') {
    pagina('llaves', array('titulo' => 'Llaves API', 'seccion' => 'llaves', 'esAdmin' => $esAdmin, 'maximo' => 10, 'apiUrl' => (api_env('API_BASE_URL') ? rtrim(api_env('API_BASE_URL'), '/') : 'http://localhost/' . basename($raiz)) . '/v1',
        'llaves' => Db::consultar('SELECT id, nombre, prefijo, entorno, activo, creado, ultimo_uso FROM api_keys WHERE empresa_id = ? ORDER BY id DESC', array($empresaId))));
}
if ($ruta === '/llaves/crear' && $metodo === 'POST') {
    soloAdmin();
    confirmarOVolver('/llaves');
    $nombre = trim(isset($_POST['nombre']) ? (string) $_POST['nombre'] : '');
    $ent = (isset($_POST['entorno']) && $_POST['entorno'] === 'live') ? 'live' : 'test';
    if (!preg_match('/^[\p{L}\p{N} ._\-]{1,60}$/u', $nombre)) {
        Sesion::flash('error', 'El nombre debe tener de 1 a 60 caracteres (letras, números, espacios, punto, guion).');
        ir('/llaves');
    }
    $activas = (int) Db::uno('SELECT COUNT(*) AS n FROM api_keys WHERE empresa_id = ? AND entorno = ? AND activo = 1', array($empresaId, $ent))['n'];
    if ($activas >= 10) {
        Sesion::flash('error', 'Ya tiene 10 llaves activas en ese entorno. Revoque alguna antes de crear otra.');
        ir('/llaves');
    }
    $llave = Auth::generarKey($ent);
    Db::ejecutar('INSERT INTO api_keys (empresa_id, nombre, prefijo, hash, entorno, activo, creado) VALUES (?,?,?,?,?,1,?)', array($empresaId, $nombre, substr($llave, 0, 12), Auth::hash($llave), $ent, Db::ahora()));
    Auditoria::registrar($empresaId, $usuario['id'], 'llave_creada', substr($llave, 0, 12) . '… (' . $ent . ') ' . $nombre);
    Sesion::secretoUnico('Su nueva llave API (' . ($ent === 'live' ? 'producción' : 'pruebas') . ')', $llave);
    ir('/llaves');
}
if ($ruta === '/llaves/revocar' && $metodo === 'POST') {
    soloAdmin();
    confirmarOVolver('/llaves');
    $id = isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0;
    $k = Db::uno('SELECT prefijo, entorno FROM api_keys WHERE id = ? AND empresa_id = ? AND activo = 1', array($id, $empresaId)); // solo de SU empresa
    if (!$k || Db::afectadas('UPDATE api_keys SET activo = 0 WHERE id = ? AND empresa_id = ?', array($id, $empresaId)) !== 1) {
        Sesion::flash('error', 'No se encontró esa llave.');
        ir('/llaves');
    }
    Auditoria::registrar($empresaId, $usuario['id'], 'llave_revocada', $k['prefijo'] . '… (' . $k['entorno'] . ')');
    Sesion::flash('ok', 'Llave revocada.');
    ir('/llaves');
}

// ---- webhooks
if ($ruta === '/webhooks' && $metodo === 'GET') {
    $plan = Planes::de($empresa);
    pagina('webhooks', array('titulo' => 'Webhooks', 'seccion' => 'webhooks', 'esAdmin' => $esAdmin, 'webhooks' => Webhooks::listar($ctx), 'eventos' => Webhooks::$eventos,
        'plan' => $plan['nombre'] === 'sin_plan' ? 'sin plan' : $plan['nombre'], 'maximo' => $plan['max_webhooks']));
}
if (in_array($ruta, array('/webhooks/crear', '/webhooks/eliminar', '/webhooks/probar', '/webhooks/reenviar'), true) && $metodo === 'POST') {
    soloAdmin();
    $id = isset($_POST['id']) ? (string) $_POST['id'] : '';
    try {
        if ($ruta === '/webhooks/crear') {
            $ev = isset($_POST['eventos']) && is_array($_POST['eventos']) ? array_values(array_filter($_POST['eventos'], 'is_string')) : array();
            if (!$ev) {
                throw new ApiError(422, 'validacion', 'Elija al menos un evento.');
            }
            $w = Webhooks::crear($ctx, array('url' => isset($_POST['url']) ? (string) $_POST['url'] : '', 'eventos' => $ev));
            Auditoria::registrar($empresaId, $usuario['id'], 'webhook_creado', $w['id'] . ' ' . substr($w['url'], 0, 150));
            Sesion::secretoUnico('Secreto del webhook (verifique con él la firma de los avisos)', $w['secreto']);
        } else {
            if (!preg_match('/^wh_[a-f0-9]{20}$/', $id)) {
                throw new ApiError(404, 'no_encontrado', 'Webhook no encontrado.');
            }
            if ($ruta === '/webhooks/eliminar') {
                Webhooks::eliminar($ctx, $id);
                Auditoria::registrar($empresaId, $usuario['id'], 'webhook_eliminado', $id);
                Sesion::flash('ok', 'Webhook eliminado.');
            } elseif ($ruta === '/webhooks/probar') {
                $r = Webhooks::probar($ctx, $id);
                Auditoria::registrar($empresaId, $usuario['id'], 'webhook_probado', $id . ($r['entregado'] ? ' ok' : ' fallo'));
                Sesion::flash($r['entregado'] ? 'ok' : 'error', $r['entregado'] ? 'Prueba entregada: su servidor respondió HTTP ' . (int) $r['http'] . '.' : 'La prueba falló: ' . ($r['error'] !== null ? $r['error'] : 'sin respuesta'));
            } else {
                $ent = isset($_POST['entrega']) ? (string) $_POST['entrega'] : '';
                if (!preg_match('/^ent_[a-f0-9]{20}$/', $ent)) {
                    throw new ApiError(404, 'no_encontrado', 'Entrega no encontrada.');
                }
                Webhooks::reenviar($ctx, $id, $ent);
                Sesion::flash('ok', 'La entrega volvió a la cola: se enviará en el próximo ciclo.');
                ir('/webhooks/entregas?id=' . $id);
            }
        }
    } catch (ApiError $e) {
        Sesion::flash('error', $e->getMessage());
    }
    ir('/webhooks');
}
if ($ruta === '/webhooks/entregas' && $metodo === 'GET') {
    $id = isset($_GET['id']) ? (string) $_GET['id'] : '';
    if (!preg_match('/^wh_[a-f0-9]{20}$/', $id)) {
        terminar(404, 'No encontrado', 'Webhook no encontrado.');
    }
    $propio = null;
    foreach (Webhooks::listar($ctx) as $w) { // solo los webhooks de su empresa y entorno
        if ($w['id'] === $id) {
            $propio = $w;
        }
    }
    if (!$propio) {
        terminar(404, 'No encontrado', 'Webhook no encontrado en este entorno.');
    }
    pagina('entregas', array('titulo' => 'Entregas', 'seccion' => 'webhooks', 'esAdmin' => $esAdmin, 'webhook' => $propio, 'entregas' => Webhooks::entregas($ctx, $id)));
}

// ---- certificado
if ($ruta === '/certificado' && $metodo === 'GET') {
    pagina('certificado', array('titulo' => 'Certificado digital', 'seccion' => 'certificado', 'esAdmin' => $esAdmin, 'cert' => Certificados::estado($empresaId, $entorno)));
}
if ($ruta === '/certificado/subir' && $metodo === 'POST') {
    soloAdmin();
    confirmarOVolver('/certificado');
    try {
        if (isset($_POST['modo']) && $_POST['modo'] === 'pem') {
            $certPem = leerArchivo('cert');
            $keyPem = leerArchivo('llave');
            $claveLlave = isset($_POST['clave_llave']) && $_POST['clave_llave'] !== '' ? (string) $_POST['clave_llave'] : null;
        } else {
            list($certPem, $keyPem) = Certificados::leerPfx(leerArchivo('pfx'), isset($_POST['clave_pfx']) ? (string) $_POST['clave_pfx'] : '');
            $claveLlave = null;
        }
        list($hoja, $keyLimpia, $info) = Certificados::validar($certPem, $keyPem, $empresa['ruc'], $entorno, $claveLlave); // mismas reglas que la API
        Certificados::guardar($empresaId, $entorno, $hoja, $keyLimpia, $info);
        Auditoria::registrar($empresaId, $usuario['id'], 'certificado_subido', ($entorno === 'live' ? 'produccion' : 'pruebas') . ' huella ' . substr($info['huella'], 0, 16));
        Sesion::flash('ok', 'Certificado guardado. Vence el ' . $info['valido_hasta'] . '.' . ($info['ruc_coincide'] ? '' : ' Aviso: no contiene su RUC; solo sirve para pruebas.'));
    } catch (ApiError $e) {
        Sesion::flash('error', $e->getMessage());
    }
    ir('/certificado');
}

// ---- credenciales SOL / GRE (solo escritura: nunca se muestran)
if ($ruta === '/credenciales' && $metodo === 'GET') {
    pagina('credenciales', array('titulo' => 'Credenciales SUNAT', 'seccion' => 'credenciales', 'esAdmin' => $esAdmin, 'sol_usuario' => (string) $empresa['sol_usuario'],
        'sol_clave' => !empty($empresa['sol_clave_cifrada']), 'gre_id' => !empty($empresa['gre_client_id']), 'gre_secreto' => !empty($empresa['gre_secret_cifrado'])));
}
if ($ruta === '/credenciales/guardar' && $metodo === 'POST') {
    soloAdmin();
    confirmarOVolver('/credenciales');
    $sets = array();
    $vals = array();
    $cambios = array();
    $campo = function ($n) {
        return isset($_POST[$n]) ? trim((string) $_POST[$n]) : '';
    };
    if ($campo('sol_usuario') !== '') {
        if (!preg_match('/^[A-Za-z0-9ñÑ_.\-]{1,30}$/u', $campo('sol_usuario'))) {
            Sesion::flash('error', 'El usuario SOL no es válido (hasta 30 letras, números, punto, guion o guion bajo).');
            ir('/credenciales');
        }
        $sets[] = 'sol_usuario = ?';
        $vals[] = $campo('sol_usuario');
        $cambios[] = 'usuario SOL';
    }
    if ($campo('sol_clave') !== '') {
        if (strlen($campo('sol_clave')) > 100) {
            Sesion::flash('error', 'La clave SOL es demasiado larga.');
            ir('/credenciales');
        }
        $sets[] = 'sol_clave_cifrada = ?';
        $vals[] = Crypto::cifrar($campo('sol_clave'));
        $cambios[] = 'clave SOL';
    }
    if ($campo('gre_client_id') !== '') {
        if (!preg_match('/^[A-Za-z0-9\-]{1,80}$/', $campo('gre_client_id'))) {
            Sesion::flash('error', 'El client_id de GRE no es válido.');
            ir('/credenciales');
        }
        $sets[] = 'gre_client_id = ?';
        $vals[] = $campo('gre_client_id');
        $cambios[] = 'GRE client_id';
    }
    if ($campo('gre_client_secret') !== '') {
        if (strlen($campo('gre_client_secret')) > 200) {
            Sesion::flash('error', 'El client_secret de GRE es demasiado largo.');
            ir('/credenciales');
        }
        $sets[] = 'gre_secret_cifrado = ?';
        $vals[] = Crypto::cifrar($campo('gre_client_secret'));
        $cambios[] = 'GRE client_secret';
    }
    if (!$sets) {
        Sesion::flash('error', 'No indicó ningún dato para cambiar.');
        ir('/credenciales');
    }
    $vals[] = $empresaId;
    Db::ejecutar('UPDATE empresas SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    Auditoria::registrar($empresaId, $usuario['id'], 'credenciales_actualizadas', implode(', ', $cambios)); // se registra QUE cambio, nunca los valores
    Sesion::flash('ok', 'Credenciales actualizadas: ' . implode(', ', $cambios) . '.');
    ir('/credenciales');
}

// ---- plan (el cliente puede pedir un cambio; los planes de autoservicio se activan al instante, los demas los aprueba el operador)
if ($ruta === '/plan' && $metodo === 'GET') {
    pagina('plan', array('titulo' => 'Mi plan', 'seccion' => 'plan', 'esAdmin' => $esAdmin, 'uso' => Planes::uso($ctx), 'planes' => Suscripciones::planesActivos(),
        'pendiente' => Suscripciones::pendienteDe($empresaId), 'planActualId' => $empresa['plan_id'] === null ? 0 : (int) $empresa['plan_id']));
}
if ($ruta === '/plan/solicitar' && $metodo === 'POST') {
    soloAdmin();
    confirmarOVolver('/plan');
    $planId = isset($_POST['plan_id']) && ctype_digit((string) $_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
    try {
        $r = Suscripciones::solicitar($empresa, $usuario, $planId, isset($_POST['nota']) ? (string) $_POST['nota'] : '');
        Sesion::flash('ok', $r === 'automatica' ? 'Listo: su plan ya cambió.' : 'Solicitud enviada. El administrador del servicio la revisará y la activará.');
    } catch (InvalidArgumentException $x) {
        Sesion::flash('error', $x->getMessage());
    }
    ir('/plan');
}
if ($ruta === '/plan/cancelar' && $metodo === 'POST') {
    soloAdmin();
    Suscripciones::cancelar($empresaId, $usuario['id']);
    Sesion::flash('ok', 'Solicitud cancelada.');
    ir('/plan');
}

// ---- actividad y cuenta
if ($ruta === '/actividad' && $metodo === 'GET') {
    soloAdmin();
    pagina('actividad', array('titulo' => 'Actividad', 'seccion' => 'actividad', 'eventos' => Auditoria::recientes($empresaId)));
}
if ($ruta === '/cuenta' && $metodo === 'GET') {
    pagina('cuenta', array('titulo' => 'Mi cuenta', 'seccion' => 'cuenta'));
}
if ($ruta === '/cuenta/clave' && $metodo === 'POST') {
    $nueva = isset($_POST['clave_nueva']) ? (string) $_POST['clave_nueva'] : '';
    $rep = isset($_POST['clave_repetida']) ? (string) $_POST['clave_repetida'] : '';
    confirmarOVolver('/cuenta');
    if ($nueva !== $rep) {
        Sesion::flash('error', 'La contraseña nueva y su repetición no coinciden.');
        ir('/cuenta');
    }
    $problema = Usuarios::revisarClave($nueva, $usuario['email']);
    if ($problema === null && password_verify($nueva, $usuario['clave_hash'])) {
        $problema = 'La contraseña nueva debe ser distinta de la actual.';
    }
    if ($problema !== null) {
        Sesion::flash('error', $problema);
        ir('/cuenta');
    }
    Usuarios::cambiarClave($usuario, $nueva);
    session_regenerate_id(true); // identificador nuevo tras un cambio de credenciales
    $_SESSION['ch'] = Sesion::huellaClave(Usuarios::porId($usuario['id'])['clave_hash']);
    Sesion::flash('ok', 'Contraseña actualizada.');
    ir('/');
}

terminar(404, 'Página no encontrada', 'La página que busca no existe.');
