<?php
/**
 * Verificador de la instalacion (solo linea de comandos): revisa que el sistema este bien configurado y seguro.
 *
 *   php bin/verificar.php                 # revision normal (desarrollo)
 *   php bin/verificar.php --produccion    # exige HTTPS, motor bloqueado, base de datos seria, respaldos, etc.
 *   php bin/verificar.php --sin-http      # no hace las pruebas por red (archivos sensibles bloqueados, motor protegido)
 *
 * Cada linea es [OK], [AVISO] (conviene corregirlo) o [ERROR] (hay que corregirlo antes de salir a produccion).
 * Termina con codigo 1 si hay algun [ERROR]. Ejecutelo despues de cada despliegue.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Certificados', 'Validador', 'Motor', 'Series', 'Planes') as $c) {
    require_once $raiz . '/v1/src/' . $c . '.php';
}
foreach (array('Usuarios', 'Suscripciones', 'Cobros') as $c) {
    require_once $raiz . '/portal/src/' . $c . '.php';
}
date_default_timezone_set('America/Lima');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$prod = in_array('--produccion', $argv, true);
$sinHttp = in_array('--sin-http', $argv, true);
$n = array('ok' => 0, 'aviso' => 0, 'error' => 0);

/** $nivelProd: nivel que toma el problema cuando se usa --produccion (en desarrollo siempre es "aviso"). */
function r($ok, $texto, $ayuda = '', $nivelProd = 'error')
{
    global $n, $prod;
    if ($ok) {
        $n['ok']++;
        echo "  [OK]     $texto\n";
        return true;
    }
    $nivel = $prod ? $nivelProd : 'aviso';
    $n[$nivel]++;
    echo '  [' . strtoupper($nivel) . str_repeat(' ', 7 - strlen($nivel)) . "] $texto\n";
    if ($ayuda !== '') {
        echo "           -> $ayuda\n";
    }
    return false;
}
/** Un problema que es error SIEMPRE (no depende de --produccion). */
function e($ok, $texto, $ayuda = '')
{
    global $n;
    if ($ok) {
        $n['ok']++;
        echo "  [OK]     $texto\n";
        return true;
    }
    $n['error']++;
    echo "  [ERROR]  $texto\n";
    if ($ayuda !== '') {
        echo "           -> $ayuda\n";
    }
    return false;
}
function http($url, $metodo = 'GET')
{
    $c = curl_init($url);
    curl_setopt_array($c, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false));
    curl_exec($c);
    $h = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $h;
}
function titulo($t)
{
    echo "\n== $t\n";
}

echo 'Verificacion de la instalacion' . ($prod ? ' (modo PRODUCCION)' : ' (modo desarrollo)') . "\n";

// ------------------------------------------------------------------------------------------------ PHP
titulo('1) PHP');
e(version_compare(PHP_VERSION, '7.2.0', '>='), 'PHP ' . PHP_VERSION . ' (se requiere 7.2 o mayor)');
foreach (array('openssl' => 'firma y cifrado', 'curl' => 'llamadas a SUNAT y al motor', 'mbstring' => 'textos', 'gd' => 'codigos QR de los PDF', 'zip' => 'respaldos', 'soap' => 'servicios SOAP de SUNAT', 'pdo' => 'base de datos') as $ext => $para) {
    e(extension_loaded($ext), "extension $ext ($para)", "Instale/active php-$ext");
}
$dsn = (string) api_env('DB_DSN', '');
$driver = $dsn === '' ? 'sqlite' : strtok($dsn, ':');
e(extension_loaded('pdo_' . $driver), "controlador de base de datos pdo_$driver");
r(!ini_get('display_errors') || in_array(strtolower((string) ini_get('display_errors')), array('0', 'off', 'stderr'), true), 'display_errors apagado (no mostrar errores a los visitantes)', 'En php.ini: display_errors = Off (el codigo ya los registra en logs/php_errors.log)');
r(!(bool) ini_get('expose_php'), 'expose_php apagado (no anunciar la version de PHP)', 'En php.ini: expose_php = Off', 'aviso');
$mem = trim((string) ini_get('memory_limit'));
$bytes = (int) $mem * (stripos($mem, 'G') !== false ? 1073741824 : (stripos($mem, 'M') !== false ? 1048576 : (stripos($mem, 'K') !== false ? 1024 : 1)));
r($mem === '-1' || $bytes >= 134217728, "memory_limit = $mem (se recomienda 128M o mas para generar PDF)", '', 'aviso');

// ------------------------------------------------------------------------------------------------ configuracion
titulo('2) Configuracion (.env)');
$archivoEnv = $raiz . '/.env';
e(is_file($archivoEnv), 'existe el archivo .env', 'Cree uno con: php bin/admin.php init');
$k = api_env('APP_KEY');
$raw = $k ? base64_decode($k, true) : false;
e($raw !== false && strlen($raw) === 32, 'APP_KEY valida (32 bytes en base64)', 'Genere una con: php bin/admin.php init. GUARDE UNA COPIA: si se pierde no se pueden descifrar las claves SOL ni los certificados');
$tok = (string) api_env('INTERNAL_TOKEN', '');
e(strlen($tok) >= 32, 'INTERNAL_TOKEN definido (32 caracteres o mas)', 'php bin/admin.php init lo genera');
r(api_env('BLOQUEAR_LEGACY') === '1', 'BLOQUEAR_LEGACY=1 (el motor solo responde a la API, no a visitantes)', 'Agregue BLOQUEAR_LEGACY=1 al .env', 'error');
$base = (string) api_env('API_BASE_URL', '');
r($base !== '' && stripos($base, 'https://') === 0, 'API_BASE_URL definida con https:// (' . ($base !== '' ? $base : 'vacia') . ')', 'Es la direccion publica; los enlaces a XML/CDR/PDF y los avisos la usan', 'error');
r(api_env('PORTAL_REQUIERE_HTTPS') === '1', 'PORTAL_REQUIERE_HTTPS=1 (el portal y el panel exigen HTTPS)', 'Agregue PORTAL_REQUIERE_HTTPS=1 al .env', 'error');
r(api_env('WEBHOOKS_PERMITIR_REDES_PRIVADAS') !== '1', 'webhooks hacia redes privadas NO permitidos', 'Quite WEBHOOKS_PERMITIR_REDES_PRIVADAS (solo es para desarrollo)', 'error');
r((string) api_env('OPERADOR_IPS_PERMITIDAS', '') !== '', 'el panel del operador esta limitado a sus IP (OPERADOR_IPS_PERMITIDAS)', 'Recomendado si entra siempre desde una IP fija', 'aviso');
r((string) api_env('SITIO_NOMBRE', '') !== '', 'nombre comercial definido (SITIO_NOMBRE)', 'Si no, el sitio muestra "API SUNAT"', 'aviso');
r((string) api_env('SITIO_URL', '') !== '' || (string) api_env('SITIO_URL_APP', '') !== '' || $base !== '', 'direcciones publicas del sitio definidas (SITIO_URL / SITIO_URL_APP)', 'Las usa el enlace de verificacion de documentos', 'aviso');
if (DIRECTORY_SEPARATOR === '/' && is_file($archivoEnv)) {
    $perm = fileperms($archivoEnv) & 0777;
    r(($perm & 0007) === 0, '.env no es legible por otros usuarios del servidor (permisos ' . decoct($perm) . ')', 'chmod 600 .env (y que pertenezca al usuario del servidor web)', 'error');
}
r(!api_env('ADMIN_USER') || (string) api_env('ADMIN_PASS_HASH', '') !== '', 'el cargador de certificados antiguo (upload_cert.php) no tiene usuario sin contrasena', 'Defina ADMIN_PASS_HASH o quite ADMIN_USER', 'error');

// ------------------------------------------------------------------------------------------------ base de datos
titulo('3) Base de datos');
try {
    Db::asegurarEsquema();
    e(true, 'conexion a la base de datos (' . Db::motor() . ') y esquema al dia');
    r(Db::motor() !== 'sqlite', 'motor de base de datos apto para produccion (' . Db::motor() . ')', 'SQLite sirve para empezar; para varios clientes use PostgreSQL (ver la guia de despliegue)', 'aviso');
    $nEmp = (int) Db::uno('SELECT COUNT(*) AS n FROM empresas')['n'];
    echo "           empresas: $nEmp\n";
} catch (Exception $x) {
    e(false, 'no se pudo conectar a la base de datos: ' . $x->getMessage());
}

// ------------------------------------------------------------------------------------------------ carpetas
titulo('4) Carpetas y espacio');
foreach (array('storage', 'logs', 'files/facturacion_electronica/XML', 'files/facturacion_electronica/CDR', 'files/facturacion_electronica/PDF', 'files/facturacion_electronica/qr') as $d) {
    $ruta = $raiz . '/' . $d;
    if (!is_dir($ruta)) {
        @mkdir($ruta, 0755, true);
    }
    e(is_dir($ruta) && is_writable($ruta), "carpeta $d existe y se puede escribir", "Dele permisos de escritura al usuario del servidor web (p. ej. chown -R www-data $d)");
}
$libre = @disk_free_space($raiz);
if ($libre !== false) {
    r($libre > 1073741824, 'espacio libre en disco: ' . round($libre / 1073741824, 1) . ' GB', 'Quedan menos de 1 GB: los comprobantes (XML/CDR/PDF) crecen todo el tiempo', 'error');
}
foreach (array('storage/.htaccess', 'logs/.htaccess', 'libraries/.htaccess', 'bin/.htaccess', 'v1/src/.htaccess') as $h) {
    e(is_file($raiz . '/' . $h), "bloqueo web presente: $h", 'Sin ese archivo, esa carpeta podria descargarse por web (en Nginx hay que bloquearla en su configuracion)');
}

// ------------------------------------------------------------------------------------------------ red
titulo('5) Archivos sensibles y motor (pruebas por red)');
if ($sinHttp) {
    echo "  (omitido por --sin-http)\n";
} else {
    $url = rtrim($base !== '' ? $base : Motor::urlInterna(), '/');
    $interna = rtrim(Motor::urlInterna(), '/');
    $prueba = $interna;
    echo "           probando contra $prueba\n";
    if (http($prueba . '/v1/health') !== 200) {
        r(false, 'la API responde en ' . $prueba . '/v1/health', 'Si usa otra direccion local defina API_INTERNAL_URL en .env. Se omiten las demas pruebas por red.', 'error');
    } else {
        e(true, 'la API responde (/v1/health)');
        foreach (array('/.env', '/storage/api.sqlite', '/logs/php_errors.log', '/bin/admin.php', '/bin/respaldo.php', '/libraries/env.php', '/v1/src/Db.php', '/portal/src/Usuarios.php', '/operador/src/Operadores.php', '/web/views/inicio.php', '/.git/config', '/composer.json') as $p) {
            $h = http($prueba . $p);
            e($h === 403 || $h === 404, "no se puede descargar $p (HTTP $h)", 'Apache debe leer los .htaccess (AllowOverride All); en Nginx bloquee esa ruta');
        }
        $h = http($prueba . '/post.php', 'POST');
        if (api_env('BLOQUEAR_LEGACY') === '1') {
            e($h === 403, "el motor (post.php) rechaza peticiones directas sin el token interno (HTTP $h)");
        } else {
            r(false, 'el motor (post.php) acepta peticiones directas (HTTP ' . $h . ')', 'Active BLOQUEAR_LEGACY=1', 'error');
        }
        if (stripos($url, 'https://') === 0 && $url !== $interna) {
            $h = http($url . '/v1/health');
            r($h === 200, "la direccion publica $url/v1/health responde por HTTPS (HTTP $h)", 'Revise el dominio, el certificado HTTPS y el proxy', 'error');
        }
    }
}

// ------------------------------------------------------------------------------------------------ servicio
titulo('6) Worker (reintentos, avisos y vencimientos)');
$hb = $raiz . '/storage/worker.heartbeat';
$edad = is_file($hb) ? time() - (int) trim((string) file_get_contents($hb)) : null;
r($edad !== null && $edad <= 120, $edad === null ? 'el worker nunca se ha iniciado' : ($edad <= 120 ? 'el worker esta activo (ultimo latido hace ' . $edad . ' s)' : 'el worker NO esta corriendo (ultimo latido hace ' . $edad . ' s)'), $edad === null || $edad > 600 ? 'Inicielo y dejelo como servicio: ver docs/DESPLIEGUE.md. Sin el no hay reintentos, webhooks ni vencimientos' : 'El latido es antiguo: revise logs/worker.log', 'error');

// ------------------------------------------------------------------------------------------------ negocio
titulo('7) Operacion');
try {
    $ops = (int) Db::uno('SELECT COUNT(*) AS n FROM operadores WHERE activo = 1')['n'];
    r($ops > 0, "hay $ops operador(es) activo(s) para el panel", 'Cree uno: php bin/admin.php operador:crear --email=... --nombre=...', 'error');
    $dest = Cobros::planAlVencer();
    r($dest !== null, $dest ? 'plan de destino al vencer: ' . $dest['nombre'] : 'no hay plan de destino al vencer configurado', 'Panel del operador > Ajustes. Sin el, un cliente vencido conserva su plan', 'aviso');
    $sin = (int) Db::uno('SELECT COUNT(*) AS n FROM empresas WHERE activo = 1 AND plan_id IS NULL')['n'];
    r($sin === 0, $sin === 0 ? 'todas las empresas activas tienen plan' : "$sin empresa(s) activa(s) sin plan (sin limites)", 'Asigne un plan desde el panel del operador', 'aviso');
    $live = Db::consultar("SELECT e.ruc, e.razon_social FROM empresas e WHERE e.activo = 1 AND EXISTS (SELECT 1 FROM api_keys k WHERE k.empresa_id = e.id AND k.entorno = 'live' AND k.activo = 1) AND NOT EXISTS (SELECT 1 FROM certificados c WHERE c.empresa_id = e.id AND c.entorno = 'live')");
    r(!$live, $live ? count($live) . ' empresa(s) con llave de produccion pero SIN certificado propio: ' . $live[0]['ruc'] : 'toda empresa con llave de produccion tiene su certificado', 'Sin certificado no puede emitir en produccion', 'aviso');
    $por = Db::consultar("SELECT e.ruc, c.valido_hasta FROM certificados c JOIN empresas e ON e.id = c.empresa_id WHERE c.entorno = 'live' AND c.valido_hasta IS NOT NULL AND c.valido_hasta < ? ORDER BY c.valido_hasta", array(date('Y-m-d H:i:s', strtotime('+30 days'))));
    r(!$por, $por ? count($por) . ' certificado(s) de produccion vencen en menos de 30 dias (' . $por[0]['ruc'] . ': ' . substr($por[0]['valido_hasta'], 0, 10) . ')' : 'ningun certificado de produccion vence en los proximos 30 dias', 'Avise al cliente para que suba el renovado', 'aviso');
} catch (Exception $x) {
    r(false, 'no se pudo revisar la operacion: ' . $x->getMessage(), '', 'aviso');
}
// respaldos
$dirResp = rtrim(str_replace('\\', '/', (string) api_env('RESPALDO_DIR', $raiz . '/storage/respaldos')), '/');
$ultimo = null;
foreach (is_dir($dirResp) ? scandir($dirResp) : array() as $d) {
    if (preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $d, $m) && is_file($dirResp . '/' . $d . '/manifiesto.json')) {
        $ultimo = max((int) $ultimo, mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]));
    }
}
r($ultimo !== null && time() - $ultimo < 2 * 86400, $ultimo === null ? 'no hay respaldos en ' . $dirResp : 'ultimo respaldo: ' . date('Y-m-d H:i', $ultimo) . ' (hace ' . round((time() - $ultimo) / 3600) . ' h)', 'Programe: php bin/respaldo.php crear --clave=... --retener=14 (ver docs/DESPLIEGUE.md) y copie los respaldos FUERA del servidor', 'error');

echo "\nResumen: {$n['ok']} correctas, {$n['aviso']} avisos, {$n['error']} errores.\n";
if ($n['error'] > 0) {
    echo "Corrija los [ERROR] antes de salir a produccion.\n";
}
exit($n['error'] > 0 ? 1 : 0);
