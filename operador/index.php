<?php
/**
 * Panel del OPERADOR (dueno del servicio): empresas, usuarios, consumo, planes, solicitudes y estado del worker.
 * Aplicacion aparte del portal de clientes: cuentas propias (tabla operadores), cookie propia (operador_sid) y carpeta de
 * sesiones propia. Mismas defensas: CSP estricta, CSRF, bloqueo por fallos, sesiones cortas, confirmacion de contrasena.
 */
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Series', 'Planes') as $c) {
    require_once $raiz . '/v1/src/' . $c . '.php';
}
foreach (array('Vista', 'Sesion', 'Usuarios', 'Suscripciones', 'Cobros') as $c) {
    require_once $raiz . '/portal/src/' . $c . '.php';
}
foreach (array('Operadores', 'Salud') as $c) {
    require_once __DIR__ . '/src/' . $c . '.php';
}
date_default_timezone_set('America/Lima');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $raiz . '/logs/php_errors.log');
error_reporting(E_ALL);

Sesion::$nombre = 'operador_sid';
Sesion::$subcarpeta = 'sessions_operador';
Vista::$dir = __DIR__ . '/views';

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
header('X-Robots-Tag: noindex, nofollow');
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
    error_log('[operador] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    Vista::render('error', array('titulo' => 'Algo salió mal', 'mensaje' => 'No se pudo completar la operación. Revise logs/php_errors.log.'), false);
});

// Lista blanca opcional de IP (p. ej. OPERADOR_IPS_PERMITIDAS=200.10.10.5,127.0.0.1). Fuera de ella el panel "no existe".
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
$permitidas = trim((string) api_env('OPERADOR_IPS_PERMITIDAS'));
if ($permitidas !== '' && !in_array($ip, array_map('trim', explode(',', $permitidas)), true)) {
    http_response_code(404);
    echo 'No encontrado';
    exit;
}

if (api_env('PORTAL_REQUIERE_HTTPS') === '1' && !Sesion::esHttps()) {
    $destino = (string) api_env('API_BASE_URL');
    if (stripos($destino, 'https://') === 0) {
        header('Location: ' . rtrim($destino, '/') . '/operador/', true, 301);
        exit;
    }
    terminar(403, 'Se requiere HTTPS', 'Por seguridad, este panel solo funciona por una conexión segura (HTTPS).');
}

Db::asegurarEsquema();
Sesion::iniciar();

$metodo = $_SERVER['REQUEST_METHOD'];
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$ruta = '/' . trim((string) substr($path, strlen(Sesion::base())), '/');

function ir($ruta)
{
    header('Location: ' . Vista::url($ruta));
    exit;
}

function pagina($vista, array $datos = array(), $menu = true, $status = 200)
{
    global $operador, $ruta;
    http_response_code($status);
    $base = array('csrf' => Sesion::token(), 'flash' => Sesion::tomarFlash(), 'secreto' => Sesion::tomarSecreto(), 'rutaActual' => $ruta);
    if ($menu) {
        $base += array('operador' => $operador, 'worker' => Salud::worker(),
            'nSolicitudes' => (int) Db::uno("SELECT COUNT(*) AS n FROM solicitudes_plan WHERE estado = 'pendiente'")['n'],
            'nContactos' => (int) Db::uno("SELECT COUNT(*) AS n FROM contactos WHERE estado = 'nuevo'")['n'],
            'nCobros' => (int) Db::uno("SELECT COUNT(*) AS n FROM suscripciones WHERE estado = 'gracia' OR (estado = 'activa' AND vence <= ?)", array(Cobros::sumarDias(Db::ahora(), Ajustes::dias('dias_aviso'))))['n']);
    }
    Vista::render($vista, array_merge($base, $datos), $menu);
    exit;
}

if ($metodo === 'POST' && !Sesion::csrfValido()) {
    terminar(403, 'Solicitud no válida', 'El formulario venció o la solicitud no es válida. Recargue la página e intente de nuevo.');
}

// ---------------------------------------------------------------- acceso
if ($ruta === '/login') {
    if (Sesion::usuarioId() && isset($_SESSION['app']) && $_SESSION['app'] === 'operador') {
        ir('/');
    }
    if ($metodo === 'POST') {
        $email = isset($_POST['email']) ? (string) $_POST['email'] : '';
        $clave = isset($_POST['clave']) ? (string) $_POST['clave'] : '';
        $o = (strlen($email) <= 190 && strlen($clave) <= 128) ? Operadores::autenticar($email, $clave, $ip) : null;
        if (!$o) {
            Sesion::flash('error', 'Correo o contraseña incorrectos, o cuenta bloqueada temporalmente.');
            pagina('login', array('email' => substr($email, 0, 190), 'expirada' => null), false, 401);
        }
        Sesion::entrar($o['id']);
        $_SESSION['app'] = 'operador';
        $_SESSION['ch'] = Sesion::huellaClave($o['clave_hash']);
        ir($o['debe_cambiar'] ? '/cuenta' : '/');
    }
    $exp = isset($_SESSION['expirada']) ? $_SESSION['expirada'] : null;
    unset($_SESSION['expirada']);
    pagina('login', array('email' => '', 'expirada' => $exp), false);
}

$operador = (Sesion::usuarioId() && isset($_SESSION['app']) && $_SESSION['app'] === 'operador') ? Operadores::porId(Sesion::usuarioId()) : null;
if (!$operador || (int) $operador['activo'] !== 1 || !isset($_SESSION['ch']) || !hash_equals($_SESSION['ch'], Sesion::huellaClave($operador['clave_hash']))) {
    if (Sesion::usuarioId()) {
        Sesion::destruir();
    }
    ir('/login');
}

if ($ruta === '/logout' && $metodo === 'POST') {
    Sesion::destruir();
    ir('/login');
}
if ($operador['debe_cambiar'] && !in_array($ruta, array('/cuenta', '/cuenta/clave'), true)) {
    ir('/cuenta');
}

// ---------------------------------------------------------------- utilidades
/** Anota en la actividad de la empresa (visible para ella) que el operador hizo algo. */
function auditar($empresaId, $accion, $detalle)
{
    global $operador;
    Auditoria::registrar($empresaId, null, $accion, $detalle . ' [operador ' . $operador['email'] . ']');
}

function confirmarOVolver($volverA)
{
    global $operador;
    if (!Operadores::confirmarClave($operador, isset($_POST['clave_actual']) ? (string) $_POST['clave_actual'] : '')) {
        Sesion::flash('error', 'La contraseña de confirmación no es correcta.');
        ir($volverA);
    }
}

function idPost($campo)
{
    return (isset($_POST[$campo]) && ctype_digit((string) $_POST[$campo]) && strlen((string) $_POST[$campo]) < 10) ? (int) $_POST[$campo] : 0;
}

function textoPost($campo, $max)
{
    $v = isset($_POST[$campo]) && is_string($_POST[$campo]) ? trim($_POST[$campo]) : '';
    return (preg_match('/[\x00-\x1F\x7F]/', $v) || mb_strlen($v, 'UTF-8') > $max) ? false : $v;
}

/** Entero opcional: vacio = null (ilimitado). false si no es valido. */
function enteroOpcional($campo, $min = 0, $max = 2000000000)
{
    $v = isset($_POST[$campo]) ? trim((string) $_POST[$campo]) : '';
    if ($v === '') {
        return null;
    }
    if (!ctype_digit($v) || strlen($v) > 10 || (int) $v < $min || (int) $v > $max) {
        return false;
    }
    return (int) $v;
}

function empresaPorId($id)
{
    return Db::uno('SELECT * FROM empresas WHERE id = ?', array((int) $id));
}

// ---------------------------------------------------------------- resumen
if ($ruta === '/' && $metodo === 'GET') {
    try {
        Cobros::procesarVencimientos(); // el worker lo hace; aqui se hace tambien para que lo que se ve este al dia
    } catch (Exception $x) {
        error_log('[operador] vencimientos: ' . $x->getMessage());
    }
    $periodo = Planes::periodo();
    $cerca = Db::consultar('SELECT e.id, e.ruc, e.razon_social, p.nombre AS plan, p.cuota_mensual AS cuota, u.documentos AS usados
        FROM empresas e JOIN planes p ON p.id = e.plan_id JOIN uso u ON u.empresa_id = e.id AND u.periodo = ?
        WHERE e.activo = 1 AND p.cuota_mensual > 0 AND u.documentos * 100 >= p.cuota_mensual * 80 ORDER BY (u.documentos * 1.0 / p.cuota_mensual) DESC LIMIT 15', array($periodo));
    pagina('resumen', array('titulo' => 'Resumen', 'seccion' => 'resumen', 'periodo' => $periodo, 'colas' => Salud::colas(),
        'nEmpresas' => (int) Db::uno('SELECT COUNT(*) AS n FROM empresas WHERE activo = 1')['n'],
        'nInactivas' => (int) Db::uno('SELECT COUNT(*) AS n FROM empresas WHERE activo = 0')['n'],
        'nSinPlan' => (int) Db::uno('SELECT COUNT(*) AS n FROM empresas WHERE activo = 1 AND plan_id IS NULL')['n'],
        'nUsuarios' => (int) Db::uno('SELECT COUNT(*) AS n FROM usuarios_portal WHERE activo = 1')['n'],
        'docsMes' => (int) Db::uno('SELECT COALESCE(SUM(documentos), 0) AS n FROM uso WHERE periodo = ?', array($periodo))['n'],
        'cerca' => $cerca,
        'sinUsuarios' => (int) Db::uno('SELECT COUNT(*) AS n FROM empresas e WHERE e.activo = 1 AND NOT EXISTS (SELECT 1 FROM usuarios_portal u WHERE u.empresa_id = e.id AND u.activo = 1)')['n']));
}

// ---------------------------------------------------------------- empresas
if ($ruta === '/empresas' && $metodo === 'GET') {
    $q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 60, 'UTF-8') : '';
    $periodo = Planes::periodo();
    $sql = 'SELECT e.id, e.ruc, e.razon_social, e.activo, e.plan_id, p.nombre AS plan, p.cuota_mensual AS cuota,
        (SELECT documentos FROM uso u WHERE u.empresa_id = e.id AND u.periodo = ?) AS usados,
        (SELECT COUNT(*) FROM usuarios_portal x WHERE x.empresa_id = e.id AND x.activo = 1) AS nusuarios
        FROM empresas e LEFT JOIN planes p ON p.id = e.plan_id';
    $par = array($periodo);
    if ($q !== '') {
        $sql .= ' WHERE e.ruc LIKE ? OR LOWER(e.razon_social) LIKE LOWER(?)';
        $par[] = '%' . $q . '%';
        $par[] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY e.id DESC LIMIT 200';
    pagina('empresas', array('titulo' => 'Empresas', 'seccion' => 'empresas', 'q' => $q, 'periodo' => $periodo, 'empresas' => Db::consultar($sql, $par)));
}

if ($ruta === '/empresas/nueva' && $metodo === 'GET') {
    pagina('empresa_nueva', array('titulo' => 'Nuevo cliente', 'seccion' => 'empresas', 'planes' => Suscripciones::planesActivos(), 'f' => array()));
}
if ($ruta === '/empresas/crear' && $metodo === 'POST') {
    $f = array();
    foreach (array('ruc' => 11, 'razon' => 150, 'nombre' => 150, 'direccion' => 200, 'ubigeo' => 6, 'dep' => 50, 'prov' => 50, 'dist' => 50, 'email' => 190, 'nombre_usuario' => 120) as $c => $max) {
        $f[$c] = textoPost($c, $max);
    }
    $planId = isset($_POST['plan_id']) && ctype_digit((string) $_POST['plan_id']) ? (int) $_POST['plan_id'] : 0;
    $volver = function ($msg) use ($f) {
        Sesion::flash('error', $msg);
        pagina('empresa_nueva', array('titulo' => 'Nuevo cliente', 'seccion' => 'empresas', 'planes' => Suscripciones::planesActivos(), 'f' => array_map('strval', $f)), true, 422);
    };
    foreach ($f as $c => $v) {
        if ($v === false) {
            $volver('Hay un dato demasiado largo o con caracteres no permitidos (' . $c . ').');
        }
    }
    if (!Operadores::confirmarClave($operador, isset($_POST['clave_actual']) ? (string) $_POST['clave_actual'] : '')) {
        $volver('La contraseña de confirmación no es correcta.');
    }
    if (!preg_match('/^\d{11}$/', $f['ruc'])) {
        $volver('El RUC debe tener 11 dígitos.');
    }
    if (!preg_match('/^\d{6}$/', $f['ubigeo'])) {
        $volver('El ubigeo debe tener 6 dígitos.');
    }
    foreach (array('razon', 'direccion', 'dep', 'prov', 'dist') as $c) {
        if ($f[$c] === '') {
            $volver('Complete todos los datos obligatorios de la empresa.');
        }
    }
    if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($f['ruc']))) {
        $volver('Ya existe una empresa con ese RUC.');
    }
    if ($f['email'] !== '' && (!filter_var($f['email'], FILTER_VALIDATE_EMAIL) || Usuarios::porEmail($f['email']))) {
        $volver('El correo del administrador no es válido o ya está en uso.');
    }
    if ($planId > 0 && !Db::uno('SELECT id FROM planes WHERE id = ? AND activo = 1', array($planId))) {
        $volver('El plan elegido no existe o está inactivo.');
    }
    $empresaId = Db::ejecutar('INSERT INTO empresas (ruc, razon_social, nombre_comercial, domicilio_fiscal, ubigeo, departamento, provincia, distrito, activo, creado) VALUES (?,?,?,?,?,?,?,?,1,?)',
        array($f['ruc'], $f['razon'], $f['nombre'] !== '' ? $f['nombre'] : $f['razon'], $f['direccion'], $f['ubigeo'], $f['dep'], $f['prov'], $f['dist'], Db::ahora()));
    auditar($empresaId, 'empresa_creada', $f['ruc']);
    if ($planId > 0) {
        Suscripciones::asignar($empresaId, $planId, 'operador ' . $operador['email']);
    }
    if ($f['email'] !== '') {
        $r = Usuarios::crear($empresaId, $f['email'], $f['nombre_usuario'] !== '' ? $f['nombre_usuario'] : $f['email'], 'admin');
        auditar($empresaId, 'usuario_creado', strtolower($f['email']));
        Sesion::secretoUnico('Contraseña TEMPORAL de ' . strtolower($f['email']) . ' (deberá cambiarla al ingresar)', $r['clave']);
    }
    Sesion::flash('ok', 'Cliente creado. Sus credenciales SUNAT y su certificado los carga él mismo desde el portal.');
    ir('/empresas/' . $empresaId);
}

if (preg_match('#^/empresas/(\d{1,9})$#', $ruta, $m) && $metodo === 'GET') {
    $e = empresaPorId($m[1]);
    if (!$e) {
        terminar(404, 'No encontrada', 'No existe esa empresa.');
    }
    $periodo = Planes::periodo();
    $uso = array();
    foreach (Suscripciones::consumo($e['id'], 6) as $u) {
        $uso[] = $u;
    }
    $cert = Db::consultar('SELECT entorno, sujeto, valido_hasta FROM certificados WHERE empresa_id = ?', array($e['id']));
    pagina('empresa', array('titulo' => $e['razon_social'], 'seccion' => 'empresas', 'e' => $e, 'plan' => Planes::de($e), 'usado' => Planes::usado($e['id']), 'periodo' => $periodo,
        'planes' => Suscripciones::planesActivos(), 'consumo' => $uso, 'pendiente' => Suscripciones::pendienteDe($e['id']),
        'usuarios' => Db::consultar('SELECT * FROM usuarios_portal WHERE empresa_id = ? ORDER BY id', array($e['id'])),
        'certs' => $cert,
        'nLlaves' => (int) Db::uno('SELECT COUNT(*) AS n FROM api_keys WHERE empresa_id = ? AND activo = 1', array($e['id']))['n'],
        'nDocs' => (int) Db::uno('SELECT COUNT(*) AS n FROM documentos WHERE empresa_id = ?', array($e['id']))['n'],
        'actividad' => Auditoria::recientes($e['id'], 15),
        'sus' => Cobros::suscripcion($e['id']), 'pagos' => Db::consultar('SELECT g.*, p.nombre AS plan FROM pagos g LEFT JOIN planes p ON p.id = g.plan_id WHERE g.empresa_id = ? ORDER BY g.id DESC LIMIT 12', array($e['id'])),
        'planesTodos' => Db::consultar('SELECT * FROM planes WHERE activo = 1 ORDER BY (precio_mensual IS NULL), precio_mensual, id'),
        'pre' => array('plan_id' => isset($_GET['plan_id']) && ctype_digit((string) $_GET['plan_id']) ? (int) $_GET['plan_id'] : 0, 'periodo' => (isset($_GET['periodo']) && $_GET['periodo'] === 'anual') ? 'anual' : 'mensual')));
}
if (preg_match('#^/empresas/(\d{1,9})/plan$#', $ruta, $m) && $metodo === 'POST') {
    $e = empresaPorId($m[1]);
    if (!$e) {
        terminar(404, 'No encontrada', 'No existe esa empresa.');
    }
    $sel = isset($_POST['plan_id']) ? (string) $_POST['plan_id'] : '';
    try {
        if ($sel === 'ninguno') {
            confirmarOVolver('/empresas/' . $e['id']); // quitar el plan elimina TODOS los limites: pide contrasena
            $nom = Suscripciones::asignar($e['id'], null, 'operador ' . $operador['email']);
        } elseif (ctype_digit($sel)) {
            $nom = Suscripciones::asignar($e['id'], (int) $sel, 'operador ' . $operador['email']);
        } else {
            throw new InvalidArgumentException('Elija un plan.');
        }
        Db::ejecutar("UPDATE solicitudes_plan SET estado = 'cancelada', resuelto = ?, resuelto_por = ? WHERE empresa_id = ? AND estado = 'pendiente'", array(Db::ahora(), 'reemplazada por asignación del operador', $e['id']));
        Sesion::flash('ok', 'Plan asignado: ' . $nom . '. Aplica de inmediato.');
    } catch (InvalidArgumentException $x) {
        Sesion::flash('error', $x->getMessage());
    }
    ir('/empresas/' . $e['id']);
}
if (preg_match('#^/empresas/(\d{1,9})/estado$#', $ruta, $m) && $metodo === 'POST') {
    $e = empresaPorId($m[1]);
    if (!$e) {
        terminar(404, 'No encontrada', 'No existe esa empresa.');
    }
    confirmarOVolver('/empresas/' . $e['id']);
    $act = (isset($_POST['activo']) && $_POST['activo'] === '1') ? 1 : 0;
    Db::ejecutar('UPDATE empresas SET activo = ? WHERE id = ?', array($act, $e['id']));
    auditar($e['id'], $act ? 'empresa_activada' : 'empresa_desactivada', $e['ruc']);
    Sesion::flash('ok', $act ? 'Empresa activada.' : 'Empresa desactivada: su API y su portal dejaron de funcionar de inmediato.');
    ir('/empresas/' . $e['id']);
}
if (preg_match('#^/empresas/(\d{1,9})/usuario$#', $ruta, $m) && $metodo === 'POST') {
    $e = empresaPorId($m[1]);
    if (!$e) {
        terminar(404, 'No encontrada', 'No existe esa empresa.');
    }
    confirmarOVolver('/empresas/' . $e['id']);
    $email = textoPost('email', 190);
    $nombre = textoPost('nombre', 120);
    $rol = isset($_POST['rol']) && $_POST['rol'] === 'lector' ? 'lector' : 'admin';
    if ($email === false || $nombre === false) {
        Sesion::flash('error', 'Datos no válidos.');
        ir('/empresas/' . $e['id']);
    }
    try {
        $r = Usuarios::crear($e['id'], $email, $nombre !== '' ? $nombre : $email, $rol);
        auditar($e['id'], 'usuario_creado', strtolower($email) . ' (' . $rol . ')');
        Sesion::secretoUnico('Contraseña TEMPORAL de ' . strtolower($email) . ' (deberá cambiarla al ingresar)', $r['clave']);
        Sesion::flash('ok', 'Usuario creado. Entréguele esta contraseña por un canal seguro.');
    } catch (InvalidArgumentException $x) {
        Sesion::flash('error', $x->getMessage());
    }
    ir('/empresas/' . $e['id']);
}

// ---------------------------------------------------------------- usuarios
if ($ruta === '/usuarios' && $metodo === 'GET') {
    $q = isset($_GET['q']) && is_string($_GET['q']) ? mb_substr(trim($_GET['q']), 0, 60, 'UTF-8') : '';
    $sql = 'SELECT u.*, e.ruc, e.razon_social FROM usuarios_portal u JOIN empresas e ON e.id = u.empresa_id';
    $par = array();
    if ($q !== '') {
        $sql .= ' WHERE LOWER(u.email) LIKE LOWER(?) OR e.ruc LIKE ? OR LOWER(e.razon_social) LIKE LOWER(?)';
        $par = array('%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }
    pagina('usuarios', array('titulo' => 'Usuarios', 'seccion' => 'usuarios', 'q' => $q, 'usuarios' => Db::consultar($sql . ' ORDER BY u.id DESC LIMIT 300', $par)));
}
if ($ruta === '/usuarios/reset' && $metodo === 'POST') {
    $u = Usuarios::porId(idPost('id'));
    if (!$u) {
        terminar(404, 'No encontrado', 'No existe ese usuario.');
    }
    $volver = (isset($_POST['volver']) && $_POST['volver'] === 'empresa') ? '/empresas/' . $u['empresa_id'] : '/usuarios';
    confirmarOVolver($volver);
    $clave = Usuarios::restablecer($u['email']); // ya la deja activa y desbloqueada; el cliente cambia la clave al entrar
    auditar($u['empresa_id'], 'clave_restablecida', $u['email']);
    Sesion::secretoUnico('Contraseña TEMPORAL de ' . $u['email'] . ' (deberá cambiarla al ingresar)', $clave);
    Sesion::flash('ok', 'Contraseña restablecida; sus sesiones abiertas se cerraron.');
    ir($volver);
}
if ($ruta === '/usuarios/estado' && $metodo === 'POST') {
    $u = Usuarios::porId(idPost('id'));
    if (!$u) {
        terminar(404, 'No encontrado', 'No existe ese usuario.');
    }
    $volver = (isset($_POST['volver']) && $_POST['volver'] === 'empresa') ? '/empresas/' . $u['empresa_id'] : '/usuarios';
    confirmarOVolver($volver);
    $act = (isset($_POST['activo']) && $_POST['activo'] === '1') ? 1 : 0;
    Db::ejecutar('UPDATE usuarios_portal SET activo = ? WHERE id = ?', array($act, $u['id']));
    auditar($u['empresa_id'], $act ? 'usuario_activado' : 'usuario_desactivado', $u['email']);
    Sesion::flash('ok', $act ? 'Usuario activado.' : 'Usuario desactivado: ya no puede ingresar.');
    ir($volver);
}

// ---------------------------------------------------------------- contactos (formulario del sitio publico)
if ($ruta === '/contactos' && $metodo === 'GET') {
    $solo = (isset($_GET['estado']) && $_GET['estado'] === 'todos') ? false : true;
    $lista = Db::consultar('SELECT * FROM contactos' . ($solo ? " WHERE estado = 'nuevo'" : '') . ' ORDER BY id DESC LIMIT 200');
    pagina('contactos', array('titulo' => 'Contactos', 'seccion' => 'contactos', 'contactos' => $lista, 'solo' => $solo));
}
if ($ruta === '/contactos/estado' && $metodo === 'POST') {
    $nuevo = (isset($_POST['estado']) && $_POST['estado'] === 'nuevo') ? 'nuevo' : 'atendido';
    Db::ejecutar('UPDATE contactos SET estado = ? WHERE id = ?', array($nuevo, idPost('id')));
    ir('/contactos' . ((isset($_POST['ver']) && $_POST['ver'] === 'todos') ? '?estado=todos' : ''));
}

// ---------------------------------------------------------------- planes
// ---------------------------------------------------------------- cobros: suscripciones, pagos, pruebas gratis y ajustes
function metodoPago()
{
    $m = isset($_POST['metodo']) ? (string) $_POST['metodo'] : '';
    return array_key_exists($m, Cobros::$metodos) ? $m : false;
}
if (preg_match('#^/empresas/(\d{1,9})/pago$#', $ruta, $m) && $metodo === 'POST') {
    $e = empresaPorId($m[1]);
    if (!$e) {
        terminar(404, 'No encontrada', 'No existe esa empresa.');
    }
    confirmarOVolver('/empresas/' . $e['id']);
    $monto = Dinero::aCentimos(isset($_POST['monto']) ? (string) $_POST['monto'] : '');
    $met = metodoPago();
    $periodoPago = (isset($_POST['periodo']) && $_POST['periodo'] === 'anual') ? 'anual' : 'mensual';
    $ref = textoPost('referencia', 80);
    $nota = textoPost('nota', 200);
    try {
        if ($monto === null || $monto <= 0) {
            throw new InvalidArgumentException('El monto no es válido (ejemplo: 99.90).');
        }
        if ($met === false || $ref === false || $nota === false) {
            throw new InvalidArgumentException('Método, referencia o nota no válidos.');
        }
        $r = Cobros::registrarPago($e['id'], idPost('plan_id'), $periodoPago, $monto, $met, $ref, $nota, $operador['email']);
        auditar($e['id'], 'pago_operador', Dinero::formato($monto) . ' ' . $periodoPago);
        Sesion::flash('ok', $r['duplicado'] ? 'Ese pago (mismo método y referencia) ya estaba registrado: no se duplicó.' : 'Pago registrado. Plan ' . $r['plan'] . ' activo hasta el ' . date('d/m/Y', strtotime($r['vence'])) . '.');
    } catch (InvalidArgumentException $x) {
        Sesion::flash('error', $x->getMessage());
    }
    ir('/empresas/' . $e['id']);
}
if (preg_match('#^/empresas/(\d{1,9})/prueba$#', $ruta, $m) && $metodo === 'POST') {
    $e = empresaPorId($m[1]);
    if (!$e) {
        terminar(404, 'No encontrada', 'No existe esa empresa.');
    }
    confirmarOVolver('/empresas/' . $e['id']);
    $dias = enteroOpcional('dias', 1, 365);
    try {
        if ($dias === false) {
            throw new InvalidArgumentException('Los días de prueba deben estar entre 1 y 365.');
        }
        $r = Cobros::iniciarPrueba($e['id'], idPost('plan_id'), $dias, true, $operador['email']);
        auditar($e['id'], 'prueba_operador', $r['plan'] . ' ' . $r['dias'] . ' dias');
        Sesion::flash('ok', 'Prueba gratis iniciada: plan ' . $r['plan'] . ' hasta el ' . date('d/m/Y', strtotime($r['vence'])) . '.');
    } catch (InvalidArgumentException $x) {
        Sesion::flash('error', $x->getMessage());
    }
    ir('/empresas/' . $e['id']);
}
if ($ruta === '/cobros' && $metodo === 'GET') {
    Cobros::procesarVencimientos();
    $estado = isset($_GET['estado']) && in_array($_GET['estado'], array('prueba', 'activa', 'gracia', 'vencida', 'porvencer'), true) ? $_GET['estado'] : '';
    $sql = 'SELECT s.*, e.ruc, e.razon_social, e.activo AS empresa_activa, p.nombre AS plan FROM suscripciones s JOIN empresas e ON e.id = s.empresa_id LEFT JOIN planes p ON p.id = s.plan_id';
    $par = array();
    if ($estado === 'porvencer') {
        $sql .= " WHERE s.estado = 'activa' AND s.vence <= ?";
        $par[] = Cobros::sumarDias(Db::ahora(), Ajustes::dias('dias_aviso'));
    } elseif ($estado !== '') {
        $sql .= ' WHERE s.estado = ?';
        $par[] = $estado;
    }
    $cuentas = array();
    foreach (Db::consultar('SELECT estado, COUNT(*) AS n FROM suscripciones GROUP BY estado') as $c) {
        $cuentas[$c['estado']] = (int) $c['n'];
    }
    pagina('cobros', array('titulo' => 'Cobros', 'seccion' => 'cobros', 'estado' => $estado, 'cuentas' => $cuentas,
        'subs' => Db::consultar($sql . ' ORDER BY s.vence LIMIT 300', $par),
        'porVencer' => (int) Db::uno("SELECT COUNT(*) AS n FROM suscripciones WHERE estado = 'activa' AND vence <= ?", array(Cobros::sumarDias(Db::ahora(), Ajustes::dias('dias_aviso'))))['n'],
        'sinSuscripcion' => (int) Db::uno('SELECT COUNT(*) AS n FROM empresas e WHERE e.activo = 1 AND NOT EXISTS (SELECT 1 FROM suscripciones s WHERE s.empresa_id = e.id)')['n']));
}
if ($ruta === '/pagos' && $metodo === 'GET') {
    $mes = date('Y-m');
    $pagos = Db::consultar('SELECT g.*, e.ruc, e.razon_social, p.nombre AS plan FROM pagos g JOIN empresas e ON e.id = g.empresa_id LEFT JOIN planes p ON p.id = g.plan_id ORDER BY g.id DESC LIMIT 200');
    $total = (int) Db::uno("SELECT COALESCE(SUM(monto), 0) AS n FROM pagos WHERE creado >= ?", array($mes . '-01 00:00:00'))['n'];
    pagina('pagos', array('titulo' => 'Pagos', 'seccion' => 'cobros', 'pagos' => $pagos, 'totalMes' => $total, 'mes' => $mes));
}
if ($ruta === '/ajustes' && $metodo === 'GET') {
    pagina('ajustes', array('titulo' => 'Ajustes', 'seccion' => 'ajustes', 'a' => Ajustes::todos(), 'planes' => Suscripciones::planesActivos()));
}
if ($ruta === '/ajustes/guardar' && $metodo === 'POST') {
    $gracia = enteroOpcional('dias_gracia', 0, 60);
    $aviso = enteroOpcional('dias_aviso', 1, 90);
    $dest = isset($_POST['plan_vencido_id']) ? (string) $_POST['plan_vencido_id'] : '';
    // texto de varias lineas: se permiten saltos de linea; se quitan los demas caracteres de control
    $instr = isset($_POST['instrucciones_pago']) && is_string($_POST['instrucciones_pago']) ? trim(str_replace("\r\n", "\n", $_POST['instrucciones_pago'])) : '';
    $instr = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/', '', $instr);
    if (mb_strlen($instr, 'UTF-8') > 600) {
        $instr = false;
    }
    $falla = null;
    if ($gracia === false || $gracia === null) {
        $falla = 'Los días de gracia deben ser un número entre 0 y 60.';
    } elseif ($aviso === false || $aviso === null) {
        $falla = 'Los días de aviso antes de vencer deben ser un número entre 1 y 90.';
    } elseif ($dest !== '' && (!ctype_digit($dest) || !Db::uno('SELECT id FROM planes WHERE id = ? AND activo = 1', array((int) $dest)))) {
        $falla = 'El plan de destino no existe o está inactivo.';
    } elseif ($instr === false) {
        $falla = 'Las instrucciones de pago son demasiado largas (máximo 600) o tienen caracteres no permitidos.';
    }
    if ($falla !== null) {
        Sesion::flash('error', $falla);
        ir('/ajustes');
    }
    Ajustes::set('dias_gracia', (string) $gracia);
    Ajustes::set('dias_aviso', (string) $aviso);
    Ajustes::set('plan_vencido_id', $dest);
    Ajustes::set('instrucciones_pago', $instr);
    Sesion::flash('ok', 'Ajustes guardados.');
    ir('/ajustes');
}

// ---------------------------------------------------------------- planes
if ($ruta === '/planes' && $metodo === 'GET') {
    $planes = Db::consultar('SELECT p.*, (SELECT COUNT(*) FROM empresas e WHERE e.plan_id = p.id) AS nempresas FROM planes p ORDER BY p.activo DESC, (p.cuota_mensual IS NULL), p.cuota_mensual, p.id');
    pagina('planes', array('titulo' => 'Planes', 'seccion' => 'planes', 'planes' => $planes));
}
if ($ruta === '/planes/guardar' && $metodo === 'POST') {
    $id = idPost('id');
    $nombre = textoPost('nombre', 60);
    $cuota = enteroOpcional('cuota_mensual');
    $sunat = enteroOpcional('limite_sunat_min', 1);
    $gral = enteroOpcional('limite_general_min', 1);
    $wh = enteroOpcional('max_webhooks', 1, 100);
    $auto = isset($_POST['autoservicio']) && $_POST['autoservicio'] === '1' ? 1 : 0;
    $activo = isset($_POST['activo']) && $_POST['activo'] === '1' ? 1 : 0;
    $publico = isset($_POST['publico']) && $_POST['publico'] === '1' ? 1 : 0;
    $incluyePdf = isset($_POST['incluye_pdf']) && $_POST['incluye_pdf'] === '1' ? 1 : 0;
    $precio = textoPost('precio', 40);
    $pm = isset($_POST['precio_mensual']) ? trim((string) $_POST['precio_mensual']) : '';
    $precioMensual = $pm === '' ? null : Dinero::aCentimos($pm);
    $descAnual = enteroOpcional('descuento_anual', 0, 90);
    $diasPrueba = enteroOpcional('dias_prueba', 0, 365);
    $falla = null;
    if ($nombre === false || !preg_match('/^[\p{L}\p{N} ._\-]{1,60}$/u', (string) $nombre)) {
        $falla = 'El nombre debe tener de 1 a 60 caracteres (letras, números, espacios, punto, guion).';
    } elseif ($precio === false) {
        $falla = 'El precio es demasiado largo (maximo 40 caracteres) o tiene caracteres no permitidos.';
    } elseif ($pm !== '' && $precioMensual === null) {
        $falla = 'El precio mensual no es válido (ejemplo: 99.90; máximo S/ 999,999.99).';
    } elseif ($descAnual === false || $diasPrueba === false) {
        $falla = 'El descuento anual debe estar entre 0 y 90 %, y los días de prueba entre 0 y 365.';
    } elseif ($cuota === false || $sunat === false || $gral === false || $wh === false || $wh === null) {
        $falla = 'Los números deben ser enteros positivos (déjelos vacíos para "ilimitado"; webhooks: de 1 a 100).';
    } elseif (($dup = Db::uno('SELECT id FROM planes WHERE nombre = ?', array($nombre))) && (int) $dup['id'] !== $id) {
        $falla = 'Ya existe un plan con ese nombre.';
    }
    $actual = $id > 0 ? Db::uno('SELECT * FROM planes WHERE id = ?', array($id)) : null;
    if ($falla === null && $id > 0 && !$actual) {
        $falla = 'El plan no existe.';
    }
    if ($falla === null && $actual && !$activo && (int) $actual['activo'] === 1) {
        // un plan inactivo se trata como "sin plan" (sin limites): por eso no se deja desactivar mientras haya empresas en el
        if ((int) Db::uno('SELECT COUNT(*) AS n FROM empresas WHERE plan_id = ?', array($id))['n'] > 0) {
            $falla = 'No se puede desactivar: hay empresas con este plan. Páselas primero a otro plan.';
        }
    }
    if ($falla !== null) {
        Sesion::flash('error', $falla);
        ir('/planes');
    }
    if ($actual) {
        Db::ejecutar('UPDATE planes SET nombre = ?, cuota_mensual = ?, limite_sunat_min = ?, limite_general_min = ?, max_webhooks = ?, autoservicio = ?, activo = ?, precio = ?, publico = ?, precio_mensual = ?, descuento_anual = ?, dias_prueba = ?, incluye_pdf = ? WHERE id = ?',
            array($nombre, $cuota, $sunat, $gral, $wh, $auto, $activo, $precio !== '' ? $precio : null, $publico, $precioMensual, (int) $descAnual, (int) $diasPrueba, $incluyePdf, $id));
        Sesion::flash('ok', 'Plan "' . $nombre . '" actualizado. Aplica de inmediato a sus empresas.');
    } else {
        Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, precio, publico, precio_mensual, descuento_anual, dias_prueba, incluye_pdf, creado) VALUES (?,?,?,?,?,?,1,?,?,?,?,?,?,?)',
            array($nombre, $cuota, $sunat, $gral, $wh, $auto, $precio !== '' ? $precio : null, $publico, $precioMensual, (int) $descAnual, (int) $diasPrueba, $incluyePdf, Db::ahora()));
        Sesion::flash('ok', 'Plan "' . $nombre . '" creado.');
    }
    ir('/planes');
}

// ---------------------------------------------------------------- solicitudes de cambio de plan
if ($ruta === '/solicitudes' && $metodo === 'GET') {
    $pend = Db::consultar("SELECT s.*, e.ruc, e.razon_social, e.plan_id AS plan_actual, p.nombre AS plan, a.nombre AS plan_anterior, u.email AS usuario
        FROM solicitudes_plan s JOIN empresas e ON e.id = s.empresa_id JOIN planes p ON p.id = s.plan_id LEFT JOIN planes a ON a.id = e.plan_id LEFT JOIN usuarios_portal u ON u.id = s.usuario_id
        WHERE s.estado = 'pendiente' ORDER BY s.id");
    $hist = Db::consultar("SELECT s.*, e.ruc, e.razon_social, p.nombre AS plan FROM solicitudes_plan s JOIN empresas e ON e.id = s.empresa_id JOIN planes p ON p.id = s.plan_id
        WHERE s.estado <> 'pendiente' ORDER BY s.id DESC LIMIT 40");
    pagina('solicitudes', array('titulo' => 'Solicitudes', 'seccion' => 'solicitudes', 'pendientes' => $pend, 'historial' => $hist));
}
if ($ruta === '/solicitudes/resolver' && $metodo === 'POST') {
    $aprobar = isset($_POST['accion']) && $_POST['accion'] === 'aprobar';
    try {
        Suscripciones::resolver(idPost('id'), $aprobar, $operador['email']);
        Sesion::flash('ok', $aprobar ? 'Solicitud aprobada: el plan ya está activo.' : 'Solicitud rechazada.');
    } catch (InvalidArgumentException $x) {
        Sesion::flash('error', $x->getMessage());
    }
    ir('/solicitudes');
}

// ---------------------------------------------------------------- actividad y cuenta
if ($ruta === '/actividad' && $metodo === 'GET') {
    $ev = Db::consultar('SELECT a.*, e.ruc, e.razon_social, u.email FROM auditoria a LEFT JOIN empresas e ON e.id = a.empresa_id LEFT JOIN usuarios_portal u ON u.id = a.usuario_id ORDER BY a.id DESC LIMIT 150');
    pagina('actividad', array('titulo' => 'Actividad', 'seccion' => 'actividad', 'eventos' => $ev));
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
    $problema = Usuarios::revisarClave($nueva, $operador['email']);
    if ($problema === null && password_verify($nueva, $operador['clave_hash'])) {
        $problema = 'La contraseña nueva debe ser distinta de la actual.';
    }
    if ($problema !== null) {
        Sesion::flash('error', $problema);
        ir('/cuenta');
    }
    Operadores::cambiarClave($operador, $nueva);
    session_regenerate_id(true);
    $_SESSION['ch'] = Sesion::huellaClave(Operadores::porId($operador['id'])['clave_hash']);
    Sesion::flash('ok', 'Contraseña actualizada.');
    ir('/');
}

terminar(404, 'Página no encontrada', 'La página que busca no existe.');
