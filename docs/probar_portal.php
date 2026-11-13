<?php
/**
 * Prueba de seguridad y funcionamiento del PORTAL DE CLIENTES (solo linea de comandos).
 *
 *   php docs/probar_portal.php
 *
 * Crea dos empresas de laboratorio (RUC 20000000007 y 20000000008) con sus usuarios y ATACA el portal por HTTP: cabeceras, cookies,
 * CSRF, XSS, acceso entre empresas (IDOR), fuerza bruta, fijacion y expiracion de sesion, roles, confirmacion de contrasena,
 * subida de certificados, etc. Al terminar borra todo lo que creo. Con Apache encendido. No envia nada a SUNAT.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$RAIZ = dirname(__DIR__);
chdir($RAIZ);
date_default_timezone_set('America/Lima');
require 'libraries/env.php';
require 'v1/src/Db.php';
$BASE = 'http://localhost/API_SUNAT';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $BASE = rtrim($m[1], '/');
    }
}
$P = $BASE . '/portal';
$API = $BASE . '/v1';
$RUC_A = '20000000007';
$RUC_B = '20000000008';
$NUEVA = 'Cl4ve-Segura-2026!';

foreach (array($RUC_A, $RUC_B) as $r) {
    if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($r))) {
        fwrite(STDERR, "ABORTADO: ya existe la empresa $r (de una corrida interrumpida). Revise y borrela antes de repetir.\n");
        exit(3);
    }
}
$limpiar = function () use ($RUC_A, $RUC_B) {
    try {
        foreach (array($RUC_A, $RUC_B) as $ruc) {
            $e = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($ruc));
            if (!$e) {
                continue;
            }
            $id = (int) $e['id'];
            Db::ejecutar('DELETE FROM entregas WHERE webhook_id IN (SELECT id FROM webhooks WHERE empresa_id = ?)', array($id));
            foreach (array('eventos', 'webhooks', 'documentos', 'series', 'uso', 'certificados', 'api_keys', 'usuarios_portal', 'auditoria') as $t) {
                Db::ejecutar("DELETE FROM $t WHERE empresa_id = ?", array($id));
            }
            Db::ejecutar('DELETE FROM empresas WHERE id = ?', array($id));
        }
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'login:%'");
    } catch (Exception $x) {
        fwrite(STDERR, 'No se pudo limpiar: ' . $x->getMessage() . "\n");
    }
};
register_shutdown_function($limpiar);

$ok = 0;
$mal = 0;
function chk($n, $c, $d = '')
{
    global $ok, $mal;
    if ($c) {
        $ok++;
        echo "  [OK]   $n\n";
    } else {
        $mal++;
        echo "  [FALLA] $n" . ($d !== '' ? "  -> $d" : '') . "\n";
        if ($d === '' && !empty($GLOBALS['ULT'])) {
            echo '         (ultima respuesta: ' . substr($GLOBALS['ULT'], 0, 300) . ")\n";
        }
    }
    return (bool) $c;
}
function admin($a)
{
    return (string) shell_exec('php bin/admin.php ' . $a . ' 2>&1');
}

/** Cliente HTTP con jarra de cookies propia (permite probar fijacion, expiracion, etc.). */
class Cli
{
    public $cookies = array();
    public $ua = 'ProbarPortal/1.0';
    public $origen = null;

    public function req($metodo, $url, $campos = null, array $cab = array())
    {
        $ch = curl_init($url);
        $h = array_merge(array('User-Agent: ' . $this->ua), $cab);
        if ($this->cookies) {
            $pares = array();
            foreach ($this->cookies as $k => $v) {
                $pares[] = $k . '=' . $v;
            }
            $h[] = 'Cookie: ' . implode('; ', $pares);
        }
        if ($this->origen !== null) {
            $h[] = 'Origin: ' . $this->origen;
        }
        $cab = array();
        $setc = array();
        curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $h,
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$cab, &$setc) {
                if (strpos($l, ':') !== false) {
                    list($k, $v) = explode(':', $l, 2);
                    $k = strtolower(trim($k));
                    $cab[$k] = trim($v);
                    if ($k === 'set-cookie') {
                        $setc[] = trim($v);
                    }
                }
                return strlen($l);
            }));
        if ($campos !== null) {
            $hayArchivo = false;
            if (is_array($campos)) {
                foreach ($campos as $v) {
                    if ($v instanceof CURLFile) {
                        $hayArchivo = true;
                    }
                }
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, (is_array($campos) && !$hayArchivo) ? http_build_query($campos) : $campos);
        }
        $cuerpo = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $GLOBALS['ULT'] = $http . ' ' . trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<(script|style).*?<\/>/s', '', (string) $cuerpo))));
        foreach ($setc as $sc) {
            $p = explode(';', $sc);
            list($n, $v) = array_pad(explode('=', trim($p[0]), 2), 2, '');
            if ($v === '' || stripos($sc, 'expires=Thu, 01-Jan-1970') !== false) {
                unset($this->cookies[$n]);
            } else {
                $this->cookies[$n] = $v;
            }
        }
        return array('http' => $http, 'cab' => $cab, 'set' => $setc, 'cuerpo' => (string) $cuerpo, 'loc' => isset($cab['location']) ? $cab['location'] : null);
    }

    public function get($ruta)
    {
        global $P;
        return $this->req('GET', $P . $ruta);
    }

    public function csrf($ruta = '/')
    {
        $r = $this->get($ruta);
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['cuerpo'], $m) ? $m[1] : '';
    }

    /** POST con token CSRF tomado de la pagina $desde. */
    public function post($ruta, array $campos, $desde = '/', $tok = null)
    {
        global $P;
        if ($tok === null) {
            $tok = $this->csrf($desde);
        }
        return $this->req('POST', $P . $ruta, array_merge(array('_csrf' => $tok), $campos));
    }

    public function login($email, $clave)
    {
        $tok = $this->csrf('/login');
        return $this->req('POST', $GLOBALS['P'] . '/login', array('_csrf' => $tok, 'email' => $email, 'clave' => $clave));
    }
}

function claveTemporal($salida)
{
    return preg_match('/^\s{2}(\S{14})\s*$/m', $salida, $m) ? $m[1] : null;
}
function sinToken($html)
{
    return preg_replace('/value="[a-f0-9]{64}"/', 'value="T"', $html);
}

echo "Prueba de seguridad del portal de clientes\n";
echo "\n== 0) Preparacion (2 empresas de laboratorio y sus usuarios)\n";
foreach (array(array($RUC_A, 'LAB A SAC'), array($RUC_B, 'LAB B SAC')) as $e) {
    admin('empresa:crear --ruc=' . $e[0] . ' --razon="' . $e[1] . '" --nombre="LAB" --direccion="AV. PRUEBA 1" --ubigeo=150101 --dep=LIMA --prov=LIMA --dist=LIMA --sol-user=MODDATOS --sol-pass=moddatos');
}
$tmpAdminA = claveTemporal(admin('usuario:crear --ruc=' . $RUC_A . ' --email=admina@lab.test --nombre="Admin A" --rol=admin'));
$tmpLectorA = claveTemporal(admin('usuario:crear --ruc=' . $RUC_A . ' --email=lectora@lab.test --nombre="Lector A" --rol=lector'));
$tmpAdminB = claveTemporal(admin('usuario:crear --ruc=' . $RUC_B . ' --email=adminb@lab.test --nombre="Admin B" --rol=admin'));
chk('empresas y 3 usuarios creados (contrasenas temporales de 14 caracteres)', $tmpAdminA && $tmpLectorA && $tmpAdminB && strlen($tmpAdminA) === 14);
$eA = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($RUC_A));
$eB = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($RUC_B));
$idA = (int) $eA['id'];
$idB = (int) $eB['id'];
chk('las contrasenas se guardan solo como hash (nunca en claro)', Db::uno('SELECT clave_hash FROM usuarios_portal WHERE email = ?', array('admina@lab.test'))['clave_hash'] !== $tmpAdminA && strpos(Db::uno('SELECT clave_hash FROM usuarios_portal WHERE email = ?', array('admina@lab.test'))['clave_hash'], '$2y$') === 0);
$sal = admin('usuario:crear --ruc=' . $RUC_A . ' --email=admina@lab.test --nombre=X');
chk('no se puede repetir un correo', stripos($sal, 'Ya existe') !== false);

echo "\n== 1) Cabeceras, cookie y contenido de la pagina de acceso\n";
$c = new Cli();
$r = $c->get('/login');
$csp = isset($r['cab']['content-security-policy']) ? $r['cab']['content-security-policy'] : '';
chk('responde 200', $r['http'] === 200);
chk("CSP estricta: default-src 'none', solo scripts propios, sin 'unsafe-inline', sin marcos", strpos($csp, "default-src 'none'") !== false && strpos($csp, "script-src 'self'") !== false && strpos($csp, 'unsafe-inline') === false && strpos($csp, "frame-ancestors 'none'") !== false && strpos($csp, "form-action 'self'") !== false, $csp);
chk('anti-clickjacking (X-Frame-Options DENY), nosniff y Referrer-Policy', ($r['cab']['x-frame-options'] === 'DENY') && ($r['cab']['x-content-type-options'] === 'nosniff') && ($r['cab']['referrer-policy'] === 'no-referrer'));
chk('no se guarda en cache (no-store)', strpos($r['cab']['cache-control'], 'no-store') !== false);
chk('no revela la version de PHP (sin X-Powered-By)', !isset($r['cab']['x-powered-by']));
$ck = isset($r['set'][0]) ? $r['set'][0] : '';
chk('cookie de sesion: HttpOnly + SameSite=Strict + limitada a /portal/', stripos($ck, 'HttpOnly') !== false && stripos($ck, 'SameSite=Strict') !== false && strpos($ck, 'path=' . parse_url($BASE, PHP_URL_PATH) . '/portal/') !== false, $ck);
chk('sin scripts ni estilos EN LINEA (la CSP los bloquearia)', !preg_match('/<script(?![^>]*\ssrc=)/i', $r['cuerpo']) && !preg_match('/\sstyle\s*=/i', $r['cuerpo']) && !preg_match('/\son[a-z]+\s*=/i', $r['cuerpo']));
$css = $c->req('GET', $P . '/assets/portal.css');
chk('los archivos estaticos (css/js) se sirven', $css['http'] === 200);
foreach (array('/src/Usuarios.php', '/views/login.php', '/src/Sesion.php', '/index.php/x') as $p) {
    $x = $c->req('GET', $P . $p);
    chk("archivo interno $p NO es accesible", $x['http'] === 403 || $x['http'] === 404 || ($x['http'] === 302 && strpos($x['cuerpo'], 'Resumen') === false), 'HTTP ' . $x['http']);
}

echo "\n== 2) Sin sesion: todo esta protegido\n";
$todas = array('/', '/documentos', '/llaves', '/webhooks', '/certificado', '/credenciales', '/cuenta', '/actividad', '/webhooks/entregas?id=wh_aaaaaaaaaaaaaaaaaaaa');
$bien = true;
foreach ($todas as $p) {
    $x = (new Cli())->get($p);
    if ($x['http'] !== 302 || strpos((string) $x['loc'], '/portal/login') === false) {
        $bien = false;
        echo "      $p -> HTTP {$x['http']}\n";
    }
}
chk('9 paginas protegidas redirigen al login', $bien);
$x = (new Cli())->get('/logout');
chk('GET a una accion de escritura (/logout) no existe (404): no hay acciones por enlace', $x['http'] === 302 || $x['http'] === 404);
$x = (new Cli())->get('/llaves/crear');
chk('GET /llaves/crear tampoco', $x['http'] === 302 || $x['http'] === 404);

echo "\n== 3) CSRF y origen\n";
$c = new Cli();
$tok = $c->csrf('/login');
$r = $c->req('POST', $P . '/login', array('email' => 'admina@lab.test', 'clave' => $tmpAdminA));
chk('POST /login SIN token CSRF -> 403', $r['http'] === 403);
$r = $c->req('POST', $P . '/login', array('_csrf' => str_repeat('a', 64), 'email' => 'admina@lab.test', 'clave' => $tmpAdminA));
chk('POST /login con token CSRF falso -> 403', $r['http'] === 403);
$c->origen = 'http://sitio-malicioso.example';
$r = $c->req('POST', $P . '/login', array('_csrf' => $tok, 'email' => 'admina@lab.test', 'clave' => $tmpAdminA));
chk('token valido pero Origin de OTRO sitio -> 403', $r['http'] === 403);
$c->origen = null;

echo "\n== 4) Credenciales incorrectas: sin pistas, sin XSS reflejado\n";
$c1 = new Cli();
$r1 = $c1->login('noexiste@lab.test', 'Alguna-Clave-123');
$c2 = new Cli();
$r2 = $c2->login('admina@lab.test', 'clave-incorrecta-1');
chk('correo inexistente y clave mala: ambos 401', $r1['http'] === 401 && $r2['http'] === 401);
chk('...con EL MISMO mensaje (no revela si el correo existe)', sinToken($r1['cuerpo']) !== '' && preg_replace('/value="[^"]*"/', 'value=""', sinToken($r1['cuerpo'])) === preg_replace('/value="[^"]*"/', 'value=""', sinToken($r2['cuerpo'])));
$c3 = new Cli();
$r3 = $c3->login('"><script>alert(1)</script>@x.test', 'x');
chk('un correo con codigo malicioso se muestra ESCAPADO (sin XSS reflejado)', strpos($r3['cuerpo'], '<script>alert(1)') === false);
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'login:%'");
Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL');

echo "\n== 5) Fuerza bruta: bloqueo de la cuenta\n";
$c = new Cli();
for ($i = 1; $i <= 5; $i++) {
    $c->login('admina@lab.test', 'intento-' . $i . '-Mal');
}
$r = $c->login('admina@lab.test', $tmpAdminA);
chk('tras 5 fallos, ni la contrasena CORRECTA entra (cuenta bloqueada 15 min)', $r['http'] === 401);
$u = Db::uno('SELECT bloqueado_hasta FROM usuarios_portal WHERE email = ?', array('admina@lab.test'));
chk('queda registrado el bloqueo y vence en ~15 min', $u['bloqueado_hasta'] !== null && strtotime($u['bloqueado_hasta']) - time() > 800 && strtotime($u['bloqueado_hasta']) - time() <= 905);
$au = Db::uno("SELECT COUNT(*) AS n FROM auditoria WHERE empresa_id = ? AND accion = 'cuenta_bloqueada'", array($idA))['n'];
chk('...y consta en la auditoria', (int) $au >= 1);
$sal = admin('usuario:reset --email=admina@lab.test');
$tmpAdminA = claveTemporal($sal);
chk('el operador la desbloquea por consola (usuario:reset genera una temporal nueva)', $tmpAdminA !== null);

echo "\n== 6) Entrada, fijacion de sesion y cambio de contrasena obligatorio\n";
$c = new Cli();
$c->get('/login');
$sidAntes = isset($c->cookies['portal_sid']) ? $c->cookies['portal_sid'] : '';
$r = $c->login('admina@lab.test', $tmpAdminA);
$sidDespues = isset($c->cookies['portal_sid']) ? $c->cookies['portal_sid'] : '';
chk('login correcto -> redirige a /cuenta (debe cambiar la contrasena temporal)', $r['http'] === 302 && strpos((string) $r['loc'], '/portal/cuenta') !== false, 'HTTP ' . $r['http'] . ' ' . $r['loc']);
chk('el identificador de sesion CAMBIA al entrar (anti fijacion de sesion)', $sidAntes !== '' && $sidDespues !== '' && $sidAntes !== $sidDespues);
$viejo = new Cli();
$viejo->cookies['portal_sid'] = $sidAntes;
chk('el identificador anterior ya no sirve', $viejo->get('/')['http'] === 302);
$r = $c->get('/documentos');
chk('con clave temporal, cualquier otra pagina redirige a /cuenta', $r['http'] === 302 && strpos((string) $r['loc'], '/portal/cuenta') !== false);
$h = $c->post('/cuenta/clave', array('clave_actual' => $tmpAdminA, 'clave_nueva' => 'corta1', 'clave_repetida' => 'corta1'), '/cuenta');
$p1 = $c->get('/cuenta');
chk('contrasena nueva demasiado corta -> rechazada', strpos($p1['cuerpo'], 'al menos 10') !== false);
$h = $c->post('/cuenta/clave', array('clave_actual' => $tmpAdminA, 'clave_nueva' => 'sololetrasminusculas', 'clave_repetida' => 'sololetrasminusculas'), '/cuenta');
chk('una sola clase de caracteres -> rechazada', strpos($c->get('/cuenta')['cuerpo'], 'dos tipos') !== false);
$h = $c->post('/cuenta/clave', array('clave_actual' => $tmpAdminA, 'clave_nueva' => 'admina@lab.test', 'clave_repetida' => 'admina@lab.test'), '/cuenta');
$h = $c->post('/cuenta/clave', array('clave_actual' => 'no-es-la-actual-1', 'clave_nueva' => $NUEVA, 'clave_repetida' => $NUEVA), '/cuenta');
chk('contrasena actual incorrecta -> rechazada', strpos($c->get('/cuenta')['cuerpo'], 'confirmación no es correcta') !== false);
$h = $c->post('/cuenta/clave', array('clave_actual' => $tmpAdminA, 'clave_nueva' => $NUEVA, 'clave_repetida' => 'otra-distinta-123'), '/cuenta');
chk('nueva y repeticion distintas -> rechazada', strpos($c->get('/cuenta')['cuerpo'], 'no coinciden') !== false);
$sidPrevio = $c->cookies['portal_sid'];
$h = $c->post('/cuenta/clave', array('clave_actual' => $tmpAdminA, 'clave_nueva' => $NUEVA, 'clave_repetida' => $NUEVA), '/cuenta');
chk('contrasena valida -> aceptada y se vuelve al panel', $h['http'] === 302);
chk('el identificador de sesion se renueva tras cambiar la contrasena', $c->cookies['portal_sid'] !== $sidPrevio);
$d = $c->get('/');
chk('el panel ya abre (200)', $d['http'] === 200 && strpos($d['cuerpo'], 'Resumen') !== false);
$nuevo = new Cli();
$nuevo->login('admina@lab.test', $tmpAdminA);
chk('la contrasena temporal anterior ya no entra', $nuevo->get('/')['http'] === 302 && strpos((string) $nuevo->get('/')['loc'], 'login') !== false);
// lector y admin B
$cL = new Cli();
$cL->login('lectora@lab.test', $tmpLectorA);
$cL->post('/cuenta/clave', array('clave_actual' => $tmpLectorA, 'clave_nueva' => $NUEVA, 'clave_repetida' => $NUEVA), '/cuenta');
$cB = new Cli();
$cB->login('adminb@lab.test', $tmpAdminB);
$cB->post('/cuenta/clave', array('clave_actual' => $tmpAdminB, 'clave_nueva' => $NUEVA, 'clave_repetida' => $NUEVA), '/cuenta');
chk('el lector y el admin de la otra empresa tambien quedan listos', $cL->get('/')['http'] === 200 && $cB->get('/')['http'] === 200);

echo "\n== 7) XSS: lo que viene de la base de datos o del cliente se muestra escapado\n";
$cadena = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
Db::ejecutar('UPDATE empresas SET razon_social = ? WHERE id = ?', array('EMPRESA ' . $cadena, $idA));
Db::ejecutar('UPDATE usuarios_portal SET nombre = ? WHERE email = ?', array('Nombre ' . $cadena, 'admina@lab.test'));
$uidDoc = 'doc_' . bin2hex(random_bytes(10));
Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, codigo_sunat, mensaje_sunat, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
    array($uidDoc, $idA, '03', 'B001', '00000001', 1, 'test', 'rechazado', '2000', 'Rechazo ' . $cadena, Db::ahora()));
$paginas = array('/', '/documentos', '/documentos/' . $uidDoc, '/cuenta');
$limpio = true;
foreach ($paginas as $p) {
    $x = $c->get($p);
    if (strpos($x['cuerpo'], '<script>alert') !== false || strpos($x['cuerpo'], '<img src=x') !== false) {
        $limpio = false;
        echo "      SIN ESCAPAR en $p\n";
    }
}
$x = $c->get('/documentos/' . $uidDoc);
chk('razon social, nombre de usuario y mensaje de SUNAT con <script>/<img> salen escapados en todas las paginas', $limpio && strpos($x['cuerpo'], '&lt;script&gt;') !== false);
$x = $c->post('/llaves/crear', array('nombre' => '<b>llave</b>', 'entorno' => 'test', 'clave_actual' => $NUEVA), '/llaves');
chk('un nombre de llave con HTML se rechaza', strpos($c->get('/llaves')['cuerpo'], 'El nombre debe tener') !== false);
$x = $c->get('/documentos?serie=' . urlencode('"><script>x</script>') . '&tipo=' . urlencode('<x>'));
chk('filtros con codigo malicioso: se ignoran, no se reflejan', strpos($x['cuerpo'], '<script>x') === false && $x['http'] === 200);

echo "\n== 8) Aislamiento entre empresas (IDOR): B intenta tocar lo de A\n";
// llave y webhook de A
$c->post('/llaves/crear', array('nombre' => 'Llave de A', 'entorno' => 'test', 'clave_actual' => $NUEVA), '/llaves');
$kA = Db::uno('SELECT id FROM api_keys WHERE empresa_id = ? ORDER BY id DESC LIMIT 1', array($idA));
$c->post('/webhooks/crear', array('url' => 'https://example.com/hook-a', 'eventos' => array('documento.aceptado')), '/webhooks');
$wA = Db::uno('SELECT uid FROM webhooks WHERE empresa_id = ?', array($idA));
$x = $cB->get('/documentos/' . $uidDoc);
chk('B abre el documento de A por su id -> 404', $x['http'] === 404 && strpos($x['cuerpo'], 'Rechazo') === false);
$x = $cB->post('/llaves/revocar', array('id' => (int) $kA['id'], 'clave_actual' => $NUEVA), '/llaves');
chk('B revoca la llave de A -> no pasa', Db::uno('SELECT activo FROM api_keys WHERE id = ?', array($kA['id']))['activo'] == 1);
$x = $cB->get('/webhooks/entregas?id=' . $wA['uid']);
chk('B mira las entregas del webhook de A -> 404', $x['http'] === 404);
$cB->post('/webhooks/eliminar', array('id' => $wA['uid']), '/webhooks');
chk('B elimina el webhook de A -> sigue existiendo', (int) Db::uno('SELECT COUNT(*) AS n FROM webhooks WHERE uid = ?', array($wA['uid']))['n'] === 1);
$cB->post('/webhooks/probar', array('id' => $wA['uid']), '/webhooks');
chk('B prueba el webhook de A -> no se envia nada (no hay eventos de prueba de A)', (int) Db::uno("SELECT COUNT(*) AS n FROM eventos WHERE empresa_id = ? AND tipo = 'webhook.prueba'", array($idA))['n'] === 0);
$pagB = $cB->get('/llaves')['cuerpo'];
chk('la lista de llaves de B no muestra nada de A', strpos($pagB, 'Llave de A') === false);
$pagB = $cB->get('/documentos')['cuerpo'];
chk('la lista de documentos de B no muestra los de A', strpos($pagB, 'B001') === false);
$cB->req('POST', $P . '/llaves/revocar', array('_csrf' => $cB->csrf('/llaves'), 'id' => '1 OR 1=1', 'clave_actual' => $NUEVA));
chk('un id manipulado (inyeccion SQL) no revoca nada de nadie', (int) Db::uno('SELECT COUNT(*) AS n FROM api_keys WHERE activo = 1 AND empresa_id = ?', array($idA))['n'] >= 1);

echo "\n== 9) Roles: el lector solo mira\n";
$x = $cL->get('/llaves');
chk('el lector ve las llaves pero SIN formularios de crear/revocar', $x['http'] === 200 && strpos($x['cuerpo'], '/llaves/crear') === false && strpos($x['cuerpo'], 'solo lectura') !== false);
$x = $cL->post('/llaves/crear', array('nombre' => 'hack', 'entorno' => 'test', 'clave_actual' => $NUEVA), '/llaves');
chk('...y si fuerza la peticion, el servidor la rechaza (403)', $x['http'] === 403);
$antes = (int) Db::uno('SELECT COUNT(*) AS n FROM webhooks WHERE empresa_id = ?', array($idA))['n'];
$x = $cL->post('/webhooks/crear', array('url' => 'https://example.com/lector', 'eventos' => array('documento.aceptado')), '/webhooks');
chk('el lector no puede crear webhooks (403) ni se crea nada', $x['http'] === 403 && (int) Db::uno('SELECT COUNT(*) AS n FROM webhooks WHERE empresa_id = ?', array($idA))['n'] === $antes);
$x = $cL->post('/certificado/subir', array('modo' => 'pfx', 'clave_actual' => $NUEVA), '/certificado');
chk('ni subir certificados (403)', $x['http'] === 403);
$x = $cL->post('/credenciales/guardar', array('sol_usuario' => 'HACK', 'clave_actual' => $NUEVA), '/credenciales');
chk('ni cambiar credenciales SUNAT (403)', $x['http'] === 403 && Db::uno('SELECT sol_usuario FROM empresas WHERE id = ?', array($idA))['sol_usuario'] === 'MODDATOS');
chk('ni ver la actividad (403)', $cL->get('/actividad')['http'] === 403);

echo "\n== 10) Llaves API: confirmacion de contrasena, se muestra UNA vez, solo se guarda el hash\n";
$antes = (int) Db::uno('SELECT COUNT(*) AS n FROM api_keys WHERE empresa_id = ?', array($idA))['n'];
$c->post('/llaves/crear', array('nombre' => 'Sin clave', 'entorno' => 'test', 'clave_actual' => ''), '/llaves');
$c->post('/llaves/crear', array('nombre' => 'Clave mala', 'entorno' => 'test', 'clave_actual' => 'no-es-esta-123'), '/llaves');
chk('crear una llave SIN contrasena o con una incorrecta -> no se crea', (int) Db::uno('SELECT COUNT(*) AS n FROM api_keys WHERE empresa_id = ?', array($idA))['n'] === $antes);
Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE email = ?', array('admina@lab.test'));
$r = $c->post('/llaves/crear', array('nombre' => 'Sistema de ventas', 'entorno' => 'test', 'clave_actual' => $NUEVA), '/llaves');
$pag = $c->get('/llaves');
preg_match('/sk_test_[a-f0-9]{40}/', $pag['cuerpo'], $m);
$llave = isset($m[0]) ? $m[0] : null;
chk('con la contrasena correcta se crea y la llave completa aparece en pantalla', $r['http'] === 302 && $llave !== null);
chk('la pagina que la muestra no se guarda en cache', strpos($pag['cab']['cache-control'], 'no-store') !== false);
$pag2 = $c->get('/llaves');
chk('al recargar, la llave completa YA NO se muestra (solo el prefijo)', strpos($pag2['cuerpo'], (string) $llave) === false && strpos($pag2['cuerpo'], substr((string) $llave, 0, 12)) !== false);
$fila = Db::uno('SELECT hash, prefijo FROM api_keys WHERE empresa_id = ? AND nombre = ?', array($idA, 'Sistema de ventas'));
chk('en la base solo existe el hash de la llave (no la llave)', $fila && $fila['hash'] === hash('sha256', $llave) && Db::uno('SELECT COUNT(*) AS n FROM api_keys WHERE hash = ?', array($llave))['n'] == 0);
$m = (new Cli())->req('GET', $API . '/me', null, array('Authorization: Bearer ' . $llave));
chk('la llave creada en el portal FUNCIONA en la API (GET /v1/me)', $m['http'] === 200 && strpos($m['cuerpo'], $RUC_A) !== false);
$idLlave = Db::uno('SELECT id FROM api_keys WHERE hash = ?', array(hash('sha256', $llave)))['id'];
$c->post('/llaves/revocar', array('id' => (int) $idLlave, 'clave_actual' => $NUEVA), '/llaves');
$m = (new Cli())->req('GET', $API . '/me', null, array('Authorization: Bearer ' . $llave));
chk('al revocarla en el portal, la API la rechaza de inmediato (401)', $m['http'] === 401);
for ($i = 0; $i < 12; $i++) {
    $c->post('/llaves/crear', array('nombre' => 'Extra ' . $i, 'entorno' => 'test', 'clave_actual' => $NUEVA), '/llaves');
}
chk('tope de 10 llaves activas por entorno', (int) Db::uno("SELECT COUNT(*) AS n FROM api_keys WHERE empresa_id = ? AND entorno = 'test' AND activo = 1", array($idA))['n'] === 10);
$kLive = $c->post('/llaves/crear', array('nombre' => 'Produccion', 'entorno' => 'live', 'clave_actual' => $NUEVA), '/llaves');
$pl = $c->get('/llaves');
chk('tambien se pueden crear llaves de produccion (sk_live_)', preg_match('/sk_live_[a-f0-9]{40}/', $pl['cuerpo']) === 1);

echo "\n== 11) Webhooks desde el portal\n";
foreach (Db::consultar('SELECT uid FROM webhooks WHERE empresa_id = ?', array($idA)) as $w) {
    $c->post('/webhooks/eliminar', array('id' => $w['uid']), '/webhooks');
}
$c->post('/webhooks/crear', array('url' => 'http://127.0.0.1/hook', 'eventos' => array('documento.aceptado')), '/webhooks');
chk('una URL hacia la red local se rechaza (proteccion SSRF tambien en el portal)', strpos($c->get('/webhooks')['cuerpo'], 'privada o reservada') !== false && (int) Db::uno('SELECT COUNT(*) AS n FROM webhooks WHERE empresa_id = ?', array($idA))['n'] === 0);
$c->post('/webhooks/crear', array('url' => 'https://example.com/hook', 'eventos' => array()), '/webhooks');
chk('sin ningun evento elegido -> error claro', strpos($c->get('/webhooks')['cuerpo'], 'al menos un evento') !== false);
$c->post('/webhooks/crear', array('url' => 'https://example.com/hook', 'eventos' => array('documento.aceptado', 'documento.rechazado')), '/webhooks');
$pag = $c->get('/webhooks');
preg_match('/whsec_[a-f0-9]{48}/', $pag['cuerpo'], $m);
chk('webhook publico creado: el secreto whsec_ se muestra una vez', isset($m[0]));
chk('al recargar el secreto ya no aparece', strpos($c->get('/webhooks')['cuerpo'], (string) $m[0]) === false);
chk('en la base el secreto esta CIFRADO (no en claro)', strpos(Db::uno('SELECT secreto_cifrado FROM webhooks WHERE empresa_id = ?', array($idA))['secreto_cifrado'], 'whsec_') === false);
$w = Db::uno('SELECT uid FROM webhooks WHERE empresa_id = ?', array($idA));
$ent = $c->get('/webhooks/entregas?id=' . $w['uid']);
chk('la pagina de entregas abre', $ent['http'] === 200);
$c->get('/webhooks/entregas?id=' . urlencode("wh_x' OR '1'='1"));
chk('un id de webhook malformado -> 404', $c->get('/webhooks/entregas?id=' . urlencode("wh_x' OR '1'='1"))['http'] === 404);

echo "\n== 12) Certificado digital\n";
$x = $c->post('/certificado/subir', array('modo' => 'pem', 'clave_actual' => $NUEVA), '/certificado');
chk('subir sin archivos -> error claro (no rompe)', strpos($c->get('/certificado')['cuerpo'], 'No se recibió el archivo') !== false);
// archivo con basura, y archivo demasiado grande
$tmp1 = tempnam(sys_get_temp_dir(), 'cert');
file_put_contents($tmp1, 'esto no es un certificado');
$tok = $c->csrf('/certificado');
$x = $c->req('POST', $P . '/certificado/subir', array('_csrf' => $tok, 'modo' => 'pfx', 'clave_pfx' => 'x', 'clave_actual' => $NUEVA, 'pfx' => new CURLFile($tmp1, 'application/octet-stream', 'cert.pfx')));
chk('un .pfx falso se rechaza sin guardar nada', strpos($c->get('/certificado')['cuerpo'], 'No se pudo abrir el PFX') !== false && (int) Db::uno('SELECT COUNT(*) AS n FROM certificados WHERE empresa_id = ?', array($idA))['n'] === 0);
file_put_contents($tmp1, str_repeat('A', 150000));
$tok = $c->csrf('/certificado');
$x = $c->req('POST', $P . '/certificado/subir', array('_csrf' => $tok, 'modo' => 'pfx', 'clave_pfx' => 'x', 'clave_actual' => $NUEVA, 'pfx' => new CURLFile($tmp1, 'application/octet-stream', 'grande.pfx')));
chk('un archivo de 150 KB (> limite de 100 KB) se rechaza', strpos($c->get('/certificado')['cuerpo'], 'demasiado grande') !== false);
// certificado real autofirmado (con el RUC) en modo PEM
$cfg = array('private_key_bits' => 2048);
foreach (array(getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf') as $cn) {
    if ($cn && is_file($cn)) {
        $cfg['config'] = $cn;
        break;
    }
}
$pk = openssl_pkey_new($cfg);
$csr = openssl_csr_new(array('countryName' => 'PE', 'organizationName' => 'LAB A', 'organizationalUnitName' => $RUC_A, 'commonName' => 'LAB A ' . $RUC_A), $pk, $cfg);
$x509 = openssl_csr_sign($csr, null, $pk, 30, $cfg);
openssl_x509_export($x509, $certPem);
openssl_pkey_export($pk, $keyPem, null, $cfg);
$tmpC = tempnam(sys_get_temp_dir(), 'c');
$tmpK = tempnam(sys_get_temp_dir(), 'k');
file_put_contents($tmpC, $certPem);
file_put_contents($tmpK, $keyPem);
$tok = $c->csrf('/certificado');
$x = $c->req('POST', $P . '/certificado/subir', array('_csrf' => $tok, 'modo' => 'pem', 'clave_actual' => 'contrasena-equivocada-1', 'cert' => new CURLFile($tmpC, 'text/plain', 'c.pem'), 'llave' => new CURLFile($tmpK, 'text/plain', 'k.pem')));
chk('subir el certificado con la contrasena del portal INCORRECTA -> no se guarda', (int) Db::uno('SELECT COUNT(*) AS n FROM certificados WHERE empresa_id = ?', array($idA))['n'] === 0);
Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE email = ?', array('admina@lab.test'));
$tok = $c->csrf('/certificado');
$x = $c->req('POST', $P . '/certificado/subir', array('_csrf' => $tok, 'modo' => 'pem', 'clave_actual' => $NUEVA, 'cert' => new CURLFile($tmpC, 'text/plain', 'c.pem'), 'llave' => new CURLFile($tmpK, 'text/plain', 'k.pem')));
$pag = $c->get('/certificado');
chk('certificado valido (PEM) -> guardado; la pagina muestra titular, vigencia y huella', (int) Db::uno('SELECT COUNT(*) AS n FROM certificados WHERE empresa_id = ?', array($idA))['n'] === 1 && strpos($pag['cuerpo'], 'LAB A ' . $RUC_A) !== false && strpos($pag['cuerpo'], 'Huella') !== false);
chk('la llave privada NO aparece en ninguna pagina y en la base esta cifrada', strpos($pag['cuerpo'], 'PRIVATE KEY') === false && strpos(Db::uno('SELECT key_cifrada FROM certificados WHERE empresa_id = ?', array($idA))['key_cifrada'], 'PRIVATE KEY') === false);
// un certificado de OTRO RUC como PRODUCCION debe rechazarse (mismas reglas que la API)
$c->post('/entorno', array('entorno' => 'live', 'volver' => '/certificado'), '/certificado');
$pk2 = openssl_pkey_new($cfg);
$csr2 = openssl_csr_new(array('countryName' => 'PE', 'organizationName' => 'OTRA', 'organizationalUnitName' => '20999999999', 'commonName' => 'OTRA 20999999999'), $pk2, $cfg);
$x2 = openssl_csr_sign($csr2, null, $pk2, 30, $cfg);
openssl_x509_export($x2, $cert2);
openssl_pkey_export($pk2, $key2, null, $cfg);
file_put_contents($tmpC, $cert2);
file_put_contents($tmpK, $key2);
$tok = $c->csrf('/certificado');
$x = $c->req('POST', $P . '/certificado/subir', array('_csrf' => $tok, 'modo' => 'pem', 'clave_actual' => $NUEVA, 'cert' => new CURLFile($tmpC, 'text/plain', 'c.pem'), 'llave' => new CURLFile($tmpK, 'text/plain', 'k.pem')));
chk('un certificado de OTRO RUC como PRODUCCION se rechaza', strpos($c->get('/certificado')['cuerpo'], 'no pertenece al RUC') !== false && (int) Db::uno("SELECT COUNT(*) AS n FROM certificados WHERE empresa_id = ? AND entorno = 'live'", array($idA))['n'] === 0);
@unlink($tmp1);
@unlink($tmpC);
@unlink($tmpK);

echo "\n== 13) Credenciales SUNAT (solo escritura)\n";
$pag = $c->get('/credenciales');
chk('muestra el usuario SOL y solo "registrada" para la clave (nunca el valor)', strpos($pag['cuerpo'], 'MODDATOS') !== false && strpos($pag['cuerpo'], 'moddatos') === false && strpos($pag['cuerpo'], 'registrada') !== false);
$c->post('/credenciales/guardar', array('sol_usuario' => '', 'sol_clave' => '', 'clave_actual' => $NUEVA), '/credenciales');
chk('enviar el formulario vacio -> error claro', strpos($c->get('/credenciales')['cuerpo'], 'ningún dato') !== false);
$c->post('/credenciales/guardar', array('sol_usuario' => '<b>x</b>', 'clave_actual' => $NUEVA), '/credenciales');
chk('un usuario SOL con caracteres raros se rechaza', Db::uno('SELECT sol_usuario FROM empresas WHERE id = ?', array($idA))['sol_usuario'] === 'MODDATOS');
$c->post('/credenciales/guardar', array('sol_usuario' => 'NUEVOUSER', 'sol_clave' => 'NuevaClaveSOL-Secreta1', 'gre_client_id' => 'abc-123', 'gre_client_secret' => 'SecretoGRE-Muy-Privado', 'clave_actual' => $NUEVA), '/credenciales');
$fila = Db::uno('SELECT * FROM empresas WHERE id = ?', array($idA));
chk('se actualizan las 4 credenciales', $fila['sol_usuario'] === 'NUEVOUSER' && $fila['gre_client_id'] === 'abc-123');
chk('la clave SOL y el secreto GRE se guardan CIFRADOS', strpos($fila['sol_clave_cifrada'], 'NuevaClaveSOL') === false && strpos($fila['gre_secret_cifrado'], 'SecretoGRE') === false && $fila['sol_clave_cifrada'] !== '');
$pag = $c->get('/credenciales');
chk('despues de guardar, la pagina no muestra los secretos', strpos($pag['cuerpo'], 'NuevaClaveSOL') === false && strpos($pag['cuerpo'], 'SecretoGRE') === false);
$act = $c->get('/actividad');
chk('la auditoria registra QUE cambio pero NUNCA los valores', strpos($act['cuerpo'], 'credenciales_actualizadas') !== false && strpos($act['cuerpo'], 'NuevaClaveSOL') === false && strpos($act['cuerpo'], 'SecretoGRE') === false && strpos($act['cuerpo'], 'clave SOL') !== false);

echo "\n== 14) Entorno Pruebas/Produccion y redirecciones\n";
$r = $c->post('/entorno', array('entorno' => 'live', 'volver' => 'https://sitio-malicioso.example/robar'), '/');
chk('el selector no permite redirigir a un sitio externo (open redirect): vuelve al inicio', $r['http'] === 302 && strpos((string) $r['loc'], 'sitio-malicioso') === false && strpos((string) $r['loc'], '/portal/') !== false, (string) $r['loc']);
$d = $c->get('/');
chk('en produccion el panel lo indica claramente', strpos($d['cuerpo'], 'PRODUCCIÓN') !== false);
$c->post('/entorno', array('entorno' => 'test', 'volver' => '/'), '/');
$x = $c->get('/documentos/' . $uidDoc);
chk('el documento de pruebas se abre en pruebas', $x['http'] === 200);
$c->post('/entorno', array('entorno' => 'live', 'volver' => '/'), '/');
chk('...y en produccion no aparece (son entornos separados)', $c->get('/documentos/' . $uidDoc)['http'] === 404);
$c->post('/entorno', array('entorno' => 'test', 'volver' => '/'), '/');

echo "\n== 15) Expiracion y cierre de sesion\n";
$sid = $c->cookies['portal_sid'];
$arch = $RAIZ . '/storage/sessions/sess_' . $sid;
$ses = @file_get_contents($arch);
chk('las sesiones se guardan en storage/sessions (fuera del acceso web)', $ses !== false && strpos($ses, 'uid|') !== false);
$x = (new Cli())->req('GET', $BASE . '/storage/sessions/sess_' . $sid);
chk('...y esa carpeta no es accesible por web', $x['http'] === 403 || $x['http'] === 404);
file_put_contents($arch, preg_replace('/t_ultimo\|i:\d+;/', 't_ultimo|i:' . (time() - 3600) . ';', $ses));
$x = $c->get('/documentos');
chk('inactividad > 30 min: la sesion expira y pide ingresar de nuevo', $x['http'] === 302 && strpos((string) $x['loc'], 'login') !== false);
$x = $c->get('/login');
chk('...avisando el motivo', strpos($x['cuerpo'], 'Su sesión terminó') !== false);
$c = new Cli();
$c->login('admina@lab.test', $NUEVA);
$sid = $c->cookies['portal_sid'];
$arch = $RAIZ . '/storage/sessions/sess_' . $sid;
$ses = file_get_contents($arch);
file_put_contents($arch, preg_replace('/t_inicio\|i:\d+;/', 't_inicio|i:' . (time() - 30000) . ';', $ses));
chk('duracion maxima > 8 h (aunque haya actividad): expira', $c->get('/')['http'] === 302);
$c = new Cli();
$c->login('admina@lab.test', $NUEVA);
$c->ua = 'Otro-Navegador/9.9';
chk('si cambia el navegador (User-Agent) con la misma cookie, la sesion se invalida', $c->get('/')['http'] === 302);
$c = new Cli();
$c->login('admina@lab.test', $NUEVA);
$sid = $c->cookies['portal_sid'];
$c->post('/logout', array(), '/');
$copia = new Cli();
$copia->cookies['portal_sid'] = $sid;
chk('al salir, la cookie robada/copiada ya no sirve', $copia->get('/')['http'] === 302 && $c->get('/')['http'] === 302);

echo "\n== 16) Auditoria visible solo para administradores\n";
$c = new Cli();
$c->login('admina@lab.test', $NUEVA);
$act = $c->get('/actividad');
chk('el admin ve sus accesos y cambios (login_ok, llave_creada, webhook_creado, certificado_subido...)', strpos($act['cuerpo'], 'login_ok') !== false && strpos($act['cuerpo'], 'llave_creada') !== false && strpos($act['cuerpo'], 'webhook_creado') !== false && strpos($act['cuerpo'], 'certificado_subido') !== false);
chk('...incluidos los intentos fallidos y el bloqueo', strpos($act['cuerpo'], 'login_fallido') !== false && strpos($act['cuerpo'], 'cuenta_bloqueada') !== false);
chk('la actividad de A no incluye datos de B', strpos($act['cuerpo'], 'adminb@lab.test') === false);
$todo = $act['cuerpo'] . $c->get('/llaves')['cuerpo'];
chk('ninguna pagina contiene contrasenas ni llaves completas', strpos($todo, $NUEVA) === false && preg_match('/sk_(test|live)_[a-f0-9]{40}/', $todo) === 0);

echo "\n== 17) Limite de intentos por IP (se hace al final: afecta a los demas accesos)\n";
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'login:%'");
$flood = new Cli();
for ($i = 0; $i < 21; $i++) {
    $flood->login('inexistente' . $i . '@lab.test', 'Alguna-Clave-123');
}
require_once 'v1/src/Planes.php';
Planes::limpiar(); // lo que hace el worker cada ciclo: NO debe borrar los contadores de login vigentes
$r = (new Cli())->login('adminb@lab.test', $NUEVA);
chk('tras 20 fallos desde una misma IP, ni un usuario legitimo entra desde ella (se frena el ataque)', $r['http'] === 401);
chk('...y el limite sobrevive a la limpieza que hace el worker (Planes::limpiar)', (int) Db::uno("SELECT COUNT(*) AS n FROM limites WHERE clave LIKE 'login:ip:%'")['n'] >= 1);
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'login:%'");
Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL');
$r = (new Cli())->login('adminb@lab.test', $NUEVA);
chk('(al vencer la ventana, vuelve a funcionar)', $r['http'] === 302);

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
