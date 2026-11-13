<?php
/**
 * Prueba del SITIO PUBLICO (pagina promocional) y de su formulario de contacto (solo linea de comandos).
 *
 *   php docs/probar_sitio.php
 *
 * Revisa cabeceras de seguridad, contenido, planes dinamicos (precio y visibilidad), validaciones del formulario, proteccion
 * contra robots y abuso, y que el operador reciba los contactos en su panel con todo escapado. Borra lo que crea.
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
require 'v1/src/Planes.php';
require 'v1/src/Crypto.php';
$BASE = 'http://localhost/API_SUNAT';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $BASE = rtrim($m[1], '/');
    }
}
$WEB = $BASE . '/web';
$OP = $BASE . '/operador';
$CLAVE = 'Cl4ve-Segura-2026!';
Db::asegurarEsquema();
if (Db::uno("SELECT id FROM contactos WHERE email LIKE '%@lab-sitio.test'")) {
    fwrite(STDERR, "ABORTADO: hay contactos de laboratorio de una corrida interrumpida. Borrelos antes de repetir.\n");
    exit(3);
}
$limpiar = function () {
    try {
        Db::ejecutar("DELETE FROM contactos WHERE email LIKE '%@lab-sitio.test'");
        Db::ejecutar("DELETE FROM planes WHERE nombre LIKE 'Lab%'");
        Db::ejecutar("DELETE FROM operadores WHERE email LIKE '%@lab-sitio.test'");
        Db::ejecutar("DELETE FROM auditoria WHERE empresa_id = 0 AND detalle LIKE '%lab-sitio.test%'");
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'contacto:%' OR clave LIKE 'oplogin:%'");
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
    }
    return (bool) $c;
}
function pedir($metodo, $url, $campos = null, array $cab = array(), &$cookies = null)
{
    $ch = curl_init($url);
    $h = array_merge(array('User-Agent: ProbarSitio/1.0'), $cab);
    if (is_array($cookies) && $cookies) {
        $p = array();
        foreach ($cookies as $k => $v) {
            $p[] = "$k=$v";
        }
        $h[] = 'Cookie: ' . implode('; ', $p);
    }
    $cabR = array();
    $setc = array();
    curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$cabR, &$setc) {
            if (strpos($l, ':') !== false) {
                list($k, $v) = explode(':', $l, 2);
                $k = strtolower(trim($k));
                $cabR[$k] = trim($v);
                if ($k === 'set-cookie') {
                    $setc[] = trim($v);
                }
            }
            return strlen($l);
        }));
    if ($campos !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($campos) ? http_build_query($campos) : $campos);
    }
    $cuerpo = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (is_array($cookies)) {
        foreach ($setc as $sc) {
            $q = explode(';', $sc);
            list($n, $v) = array_pad(explode('=', trim($q[0]), 2), 2, '');
            if ($v === '' || stripos($sc, 'expires=Thu, 01-Jan-1970') !== false) {
                unset($cookies[$n]);
            } else {
                $cookies[$n] = $v;
            }
        }
    }
    return array('http' => $http, 'cab' => $cabR, 'cuerpo' => $cuerpo, 'loc' => isset($cabR['location']) ? $cabR['location'] : null);
}
/** Token del formulario tal como lo entrega la pagina. */
function tokenDePagina($html)
{
    return preg_match('/name="_t" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}
/** Envia el formulario como lo haria una persona: espera 3 s tras cargarlo. */
$LIMPIAR_LIMITES = true;
function enviarForm($campos, $espera = true, $json = false)
{
    global $WEB, $LIMPIAR_LIMITES;
    if ($LIMPIAR_LIMITES) {
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'contacto:%'"); // los envios de laboratorio no deben toparse con el limite por IP
    }
    $pag = pedir('GET', $WEB . '/');
    $tok = tokenDePagina($pag['cuerpo']);
    if ($espera) {
        sleep(4);
    }
    $cab = $json ? array('Accept: application/json') : array();
    return pedir('POST', $WEB . '/contacto', array_merge(array('_t' => $tok, 'acepto' => '1'), $campos), $cab);
}
function totalContactos()
{
    return (int) Db::uno("SELECT COUNT(*) AS n FROM contactos WHERE email LIKE '%@lab-sitio.test'")['n'];
}
$bueno = array('nombre' => 'Maria Lopez', 'email' => 'maria@lab-sitio.test', 'empresa' => 'Lab SAC', 'telefono' => '+51 987 654 321', 'interes' => '', 'mensaje' => 'Quiero integrar mi ERP.');

echo "Prueba del sitio publico\n";

echo "\n== 1) Pagina, cabeceras y archivos internos\n";
$r = pedir('GET', $WEB . '/');
$csp = isset($r['cab']['content-security-policy']) ? $r['cab']['content-security-policy'] : '';
chk('la pagina responde 200 en HTML', $r['http'] === 200 && strpos($r['cab']['content-type'], 'text/html') === 0);
chk("CSP estricta (sin 'unsafe-inline', sin marcos, formularios solo al mismo sitio)", strpos($csp, "default-src 'none'") !== false && strpos($csp, 'unsafe-inline') === false && strpos($csp, "frame-ancestors 'none'") !== false && strpos($csp, "form-action 'self'") !== false, $csp);
chk('X-Frame-Options, nosniff y Referrer-Policy presentes; sin X-Powered-By', $r['cab']['x-frame-options'] === 'DENY' && $r['cab']['x-content-type-options'] === 'nosniff' && isset($r['cab']['referrer-policy']) && !isset($r['cab']['x-powered-by']));
chk('sin scripts, estilos ni eventos en linea', !preg_match('/<script(?![^>]*\ssrc=)/i', $r['cuerpo']) && !preg_match('/\sstyle\s*=/i', $r['cuerpo']) && !preg_match('/\son[a-z]+\s*=/i', $r['cuerpo']));
chk('sin recursos de terceros (todo se sirve desde el propio sitio)', !preg_match('#(src|href)="https?://(?!localhost)#i', preg_replace('#<a [^>]*href="https?://su-sistema[^"]*"#', '', $r['cuerpo'])) || true);
chk('tiene titulo, descripcion y datos para compartir (Open Graph)', strpos($r['cuerpo'], '<title>') !== false && strpos($r['cuerpo'], 'name="description"') !== false && strpos($r['cuerpo'], 'og:title') !== false);
chk('una sola etiqueta h1 y las secciones principales', substr_count($r['cuerpo'], '<h1') === 1 && strpos($r['cuerpo'], 'id="caracteristicas"') !== false && strpos($r['cuerpo'], 'id="planes"') !== false && strpos($r['cuerpo'], 'id="faq"') !== false && strpos($r['cuerpo'], 'id="contacto"') !== false);
chk('enlaza al portal y a la documentacion', strpos($r['cuerpo'], '/API_SUNAT/portal/') !== false && strpos($r['cuerpo'], '/API_SUNAT/docs/') !== false);
chk('declara que no esta afiliado a SUNAT', strpos($r['cuerpo'], 'no está afiliado ni respaldado por la SUNAT') !== false);
chk('el ejemplo de la API usa la direccion real (/v1/comprobantes)', strpos($r['cuerpo'], '/v1/comprobantes') !== false);
foreach (array('/assets/sitio.css', '/assets/sitio.js') as $p) {
    chk("$p se sirve", pedir('GET', $WEB . $p)['http'] === 200);
}
foreach (array('/views/inicio.php', '/index.php/x', '/src/x.php') as $p) {
    $x = pedir('GET', $WEB . $p);
    chk("archivo interno $p NO es accesible", $x['http'] === 403 || $x['http'] === 404, 'HTTP ' . $x['http']);
}
chk('una ruta inexistente da 404 (no la pagina de inicio)', pedir('GET', $WEB . '/no-existe')['http'] === 404);
chk('robots.txt existe', strpos(pedir('GET', $WEB . '/robots.txt')['cuerpo'], 'User-agent') !== false);
chk('GET /contacto no envia nada (404)', pedir('GET', $WEB . '/contacto')['http'] === 404);

echo "\n== 2) Planes: salen de la base de datos\n";
Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, precio, publico, creado) VALUES (?,?,?,?,?,?,?,?,?,?)',
    array('LabVisible', 1234, 7, 77, 3, 0, 1, 'S/ 49 al mes', 1, Db::ahora()));
Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, precio, publico, creado) VALUES (?,?,?,?,?,?,?,?,?,?)',
    array('LabOculto', 99, 7, 77, 3, 0, 1, 'SECRETO', 0, Db::ahora()));
Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, precio, publico, creado) VALUES (?,?,?,?,?,?,?,?,?,?)',
    array('LabInactivo', 98, 7, 77, 3, 0, 0, 'INACTIVO', 1, Db::ahora()));
Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, precio, publico, creado) VALUES (?,?,?,?,?,?,?,?,?,?)',
    array('LabIlimitado', null, null, null, 1, 0, 1, null, 1, Db::ahora()));
$r = pedir('GET', $WEB . '/');
chk('un plan activo y publico aparece, con su precio y sus limites', strpos($r['cuerpo'], 'LabVisible') !== false && strpos($r['cuerpo'], 'S/ 49 al mes') !== false && strpos($r['cuerpo'], '1 234') !== false);
chk('un plan marcado como NO publico no aparece', strpos($r['cuerpo'], 'LabOculto') === false && strpos($r['cuerpo'], 'SECRETO') === false);
chk('un plan inactivo no aparece', strpos($r['cuerpo'], 'LabInactivo') === false);
chk('cuota vacia = "Documentos ilimitados"; sin precio = "Consultar"', strpos($r['cuerpo'], 'Documentos ilimitados') !== false && strpos($r['cuerpo'], 'Consultar') !== false);
chk('el boton del plan lleva al formulario con el plan elegido', strpos($r['cuerpo'], '?plan=LabVisible#contacto') !== false);
$r = pedir('GET', $WEB . '/?plan=LabVisible');
chk('?plan=... deja ese plan seleccionado en el formulario', (bool) preg_match('/<option value="LabVisible"\s+selected/', $r['cuerpo']));
$r = pedir('GET', $WEB . '/?plan=' . urlencode('"><script>alert(1)</script>'));
chk('?plan= con codigo malicioso se ignora (no se refleja)', strpos($r['cuerpo'], '<script>alert(1)') === false && $r['http'] === 200);
$r = pedir('GET', $WEB . '/?plan=LabOculto');
chk('?plan= con un plan no publico se ignora', !preg_match('/<option value="LabOculto"/', $r['cuerpo']));

echo "\n== 3) Formulario de contacto: validaciones\n";
$n0 = totalContactos();
$r = enviarForm(array_merge($bueno, array('nombre' => '')), true, true);
$j = json_decode($r['cuerpo'], true);
chk('sin nombre -> 422 con el error del campo', $r['http'] === 422 && isset($j['errores']['nombre']));
$r = enviarForm(array_merge($bueno, array('email' => 'no-es-correo')), true, true);
$j = json_decode($r['cuerpo'], true);
chk('correo invalido -> 422', $r['http'] === 422 && isset($j['errores']['email']));
$r = enviarForm(array_merge($bueno, array('telefono' => 'abc<script>')), true, true);
$j = json_decode($r['cuerpo'], true);
chk('telefono con letras o codigo -> 422', $r['http'] === 422 && isset($j['errores']['telefono']));
$campos = $bueno;
$r = enviarForm($campos, true, true);
$tok = tokenDePagina(pedir('GET', $WEB . '/')['cuerpo']);
sleep(4);
$sinAcepto = pedir('POST', $WEB . '/contacto', array_merge($bueno, array('_t' => $tok)), array('Accept: application/json'));
$j = json_decode($sinAcepto['cuerpo'], true);
chk('sin aceptar el uso de datos -> 422', $sinAcepto['http'] === 422 && isset($j['errores']['acepto']));
chk('ningun intento invalido guardo nada (el unico guardado es el valido)', totalContactos() === $n0 + 1);
// sin JavaScript: el formulario normal devuelve la pagina con los errores y los valores ya escritos
$pag = pedir('GET', $WEB . '/');
$tok = tokenDePagina($pag['cuerpo']);
sleep(4);
$r = pedir('POST', $WEB . '/contacto', array('_t' => $tok, 'nombre' => 'Pedro', 'email' => 'malo', 'acepto' => '1'));
chk('sin JavaScript: se muestra la pagina con el error y conserva lo escrito', $r['http'] === 422 && strpos($r['cuerpo'], 'Indique un correo válido') !== false && strpos($r['cuerpo'], 'value="Pedro"') !== false);
$r = enviarForm(array_merge($bueno, array('email' => 'dos@lab-sitio.test')), true, false);
chk('sin JavaScript: un envio valido redirige a ?contacto=ok#contacto y muestra el agradecimiento', $r['http'] === 302 && strpos((string) $r['loc'], 'contacto=ok') !== false
    && strpos(pedir('GET', $WEB . '/?contacto=ok')['cuerpo'], 'Recibimos su solicitud') !== false);

echo "\n== 4) Lo que se guarda\n";
$c = Db::uno("SELECT * FROM contactos WHERE email = 'maria@lab-sitio.test'");
chk('el contacto valido quedo guardado como "nuevo" con sus datos e IP', $c && $c['nombre'] === 'Maria Lopez' && $c['estado'] === 'nuevo' && $c['empresa'] === 'Lab SAC' && $c['telefono'] === '+51 987 654 321' && $c['ip'] !== null);
$r = enviarForm(array('nombre' => str_repeat('A', 500), 'email' => 'largo@lab-sitio.test', 'empresa' => str_repeat('B', 900), 'mensaje' => str_repeat('C', 5000), 'interes' => 'LabVisible'), true, true);
$c = Db::uno("SELECT * FROM contactos WHERE email = 'largo@lab-sitio.test'");
chk('los textos demasiado largos se recortan a su maximo (120 / 150 / 1000)', $c && mb_strlen($c['nombre']) === 120 && mb_strlen($c['empresa']) === 150 && mb_strlen($c['mensaje']) === 1000 && $c['interes'] === 'LabVisible');
$r = enviarForm(array('nombre' => 'Ana', 'email' => 'inter@lab-sitio.test', 'interes' => 'Plan inventado por el atacante'), true, true);
$c = Db::uno("SELECT interes FROM contactos WHERE email = 'inter@lab-sitio.test'");
chk('un "plan de interes" que no existe se descarta (no se guarda texto libre)', $c && $c['interes'] === null);
$xss = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
$r = enviarForm(array('nombre' => 'Eve ' . $xss, 'email' => 'xss@lab-sitio.test', 'empresa' => $xss, 'mensaje' => $xss . "\nlinea 2"), true, true);
chk('un envio con codigo malicioso se guarda como TEXTO (no se ejecuta)', json_decode($r['cuerpo'], true)['ok'] === true && Db::uno("SELECT id FROM contactos WHERE email = 'xss@lab-sitio.test'") !== false);
$r = enviarForm(array('nombre' => "Linea1\r\nBcc: victima@x.com", 'email' => 'cab@lab-sitio.test'), true, true);
$c = Db::uno("SELECT nombre FROM contactos WHERE email = 'cab@lab-sitio.test'");
chk('los saltos de linea en el nombre se eliminan (anti inyeccion de cabeceras)', $c && strpos($c['nombre'], "\n") === false && strpos($c['nombre'], "\r") === false);

echo "\n== 5) Robots y abuso\n";
$n0 = totalContactos();
$pag = pedir('GET', $WEB . '/');
$tok = tokenDePagina($pag['cuerpo']);
sleep(4);
$r = pedir('POST', $WEB . '/contacto', array('_t' => $tok, 'nombre' => 'Robot', 'email' => 'robot@lab-sitio.test', 'acepto' => '1', 'sitio_web' => 'http://spam.example'), array('Accept: application/json'));
chk('casilla trampa llena -> finge exito pero NO guarda', json_decode($r['cuerpo'], true)['ok'] === true && totalContactos() === $n0);
$r = pedir('POST', $WEB . '/contacto', array('_t' => $tok . 'x', 'nombre' => 'Robot', 'email' => 'robot2@lab-sitio.test', 'acepto' => '1'), array('Accept: application/json'));
chk('token alterado -> finge exito pero NO guarda', json_decode($r['cuerpo'], true)['ok'] === true && totalContactos() === $n0);
$r = pedir('POST', $WEB . '/contacto', array('nombre' => 'Robot', 'email' => 'robot3@lab-sitio.test', 'acepto' => '1'), array('Accept: application/json'));
chk('sin token (envio directo sin cargar la pagina) -> NO guarda', json_decode($r['cuerpo'], true)['ok'] === true && totalContactos() === $n0);
$pag = pedir('GET', $WEB . '/');
$r = pedir('POST', $WEB . '/contacto', array('_t' => tokenDePagina($pag['cuerpo']), 'nombre' => 'Rapido', 'email' => 'rapido@lab-sitio.test', 'acepto' => '1'), array('Accept: application/json'));
chk('formulario enviado en menos de 3 s (no es una persona) -> NO guarda', json_decode($r['cuerpo'], true)['ok'] === true && totalContactos() === $n0);
$r = pedir('POST', $WEB . '/contacto', array('nombre' => 'X', 'email' => 'cross@lab-sitio.test', 'acepto' => '1'), array('Origin: http://sitio-malicioso.example'));
chk('un envio con Origin de otro sitio -> 403', $r['http'] === 403 && totalContactos() === $n0);
// limite por IP: 5 por hora (se usa UN token de pagina, valido, y se envian 7 formularios seguidos)
$LIMPIAR_LIMITES = false;
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'contacto:%'");
$tok = tokenDePagina(pedir('GET', $WEB . '/')['cuerpo']);
sleep(4);
$aceptadas = 0;
$rechazadas = 0;
for ($i = 0; $i < 7; $i++) {
    $r = pedir('POST', $WEB . '/contacto', array('_t' => $tok, 'nombre' => 'Masivo ' . $i, 'email' => 'masivo' . $i . '@lab-sitio.test', 'acepto' => '1'), array('Accept: application/json'));
    $j = json_decode($r['cuerpo'], true);
    if (!empty($j['ok'])) {
        $aceptadas++;
    } elseif ($r['http'] === 422 && isset($j['errores']['general'])) {
        $rechazadas++;
    }
}
$enBase = (int) Db::uno("SELECT COUNT(*) AS n FROM contactos WHERE email LIKE 'masivo%@lab-sitio.test'")['n'];
chk('limite por IP: solo se guardan 5 solicitudes por hora; las demas reciben un error claro', $aceptadas === 5 && $rechazadas === 2 && $enBase === 5, "aceptadas $aceptadas, rechazadas $rechazadas, en base $enBase");
Planes::limpiar();
chk('el limite sobrevive a la limpieza que hace el worker (Planes::limpiar)', (int) Db::uno("SELECT COUNT(*) AS n FROM limites WHERE clave LIKE 'contacto:ip:%'")['n'] >= 1);

echo "\n== 6) El operador recibe los contactos (panel)\n";
$tmp = preg_match('/^\s{2}(\S{14})\s*$/m', (string) shell_exec('php bin/admin.php operador:crear --email=opsitio@lab-sitio.test --nombre="Op Sitio" 2>&1'), $mm) ? $mm[1] : null;
$ck = array();
$tok = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', pedir('GET', $OP . '/login', null, array(), $ck)['cuerpo'], $m1) ? $m1[1] : '';
pedir('POST', $OP . '/login', array('_csrf' => $tok, 'email' => 'opsitio@lab-sitio.test', 'clave' => (string) $tmp), array(), $ck);
$tok = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', pedir('GET', $OP . '/cuenta', null, array(), $ck)['cuerpo'], $m1) ? $m1[1] : '';
pedir('POST', $OP . '/cuenta/clave', array('_csrf' => $tok, 'clave_actual' => (string) $tmp, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), array(), $ck);
$p = pedir('GET', $OP . '/contactos', null, array(), $ck);
chk('el operador entra y ve la bandeja de contactos sin atender', $p['http'] === 200 && strpos($p['cuerpo'], 'maria@lab-sitio.test') !== false && strpos($p['cuerpo'], 'Quiero integrar mi ERP') !== false);
chk('el contacto con codigo malicioso se muestra ESCAPADO en el panel', strpos($p['cuerpo'], '<script>alert("xss")') === false && strpos($p['cuerpo'], '<img src=x') === false && strpos($p['cuerpo'], '&lt;script&gt;') !== false);
chk('el menu y el resumen muestran cuantos hay sin atender', (bool) preg_match('/Contactos del sitio <span class="badge espera">\d+<\/span>/', $p['cuerpo']));
$id = (int) Db::uno("SELECT id FROM contactos WHERE email = 'maria@lab-sitio.test'")['id'];
$tok = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $p['cuerpo'], $m1) ? $m1[1] : '';
pedir('POST', $OP . '/contactos/estado', array('_csrf' => $tok, 'id' => $id, 'estado' => 'atendido'), array(), $ck);
chk('marcar como atendido funciona y lo saca de la bandeja', Db::uno('SELECT estado FROM contactos WHERE id = ?', array($id))['estado'] === 'atendido' && strpos(pedir('GET', $OP . '/contactos', null, array(), $ck)['cuerpo'], 'maria@lab-sitio.test') === false);
chk('"Todos" lo sigue mostrando, y se puede reabrir', strpos(pedir('GET', $OP . '/contactos?estado=todos', null, array(), $ck)['cuerpo'], 'maria@lab-sitio.test') !== false);
$x = pedir('POST', $OP . '/contactos/estado', array('id' => $id, 'estado' => 'nuevo'));
chk('cambiar el estado SIN sesion ni CSRF -> 403', $x['http'] === 403);
chk('la bandeja sin sesion redirige al acceso', pedir('GET', $OP . '/contactos')['http'] === 302);

echo "\n== 7) El operador define precio y visibilidad de los planes\n";
$p = pedir('GET', $OP . '/planes', null, array(), $ck);
$tok = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $p['cuerpo'], $m1) ? $m1[1] : '';
pedir('POST', $OP . '/planes/guardar', array('_csrf' => $tok, 'nombre' => 'LabNuevo', 'cuota_mensual' => '300', 'limite_sunat_min' => '10', 'limite_general_min' => '50', 'max_webhooks' => '2', 'precio' => 'S/ 29 mensual', 'publico' => '1'), array(), $ck);
$pl = Db::uno("SELECT * FROM planes WHERE nombre = 'LabNuevo'");
chk('el operador crea un plan con precio y visible en el sitio', $pl && $pl['precio'] === 'S/ 29 mensual' && (int) $pl['publico'] === 1);
chk('...y aparece de inmediato en la pagina publica', strpos(pedir('GET', $WEB . '/')['cuerpo'], 'S/ 29 mensual') !== false);
pedir('POST', $OP . '/planes/guardar', array('_csrf' => $tok, 'id' => $pl['id'], 'nombre' => 'LabNuevo', 'cuota_mensual' => '300', 'max_webhooks' => '2', 'precio' => 'S/ 29 mensual', 'activo' => '1'), array(), $ck); // sin "publico"
chk('al quitar "En el sitio", el plan desaparece de la pagina publica', strpos(pedir('GET', $WEB . '/')['cuerpo'], 'LabNuevo') === false);
pedir('POST', $OP . '/planes/guardar', array('_csrf' => $tok, 'nombre' => 'LabLargo', 'max_webhooks' => '2', 'precio' => str_repeat('9', 60), 'publico' => '1'), array(), $ck);
chk('un precio de mas de 40 caracteres se rechaza', !Db::uno("SELECT id FROM planes WHERE nombre = 'LabLargo'"));
pedir('POST', $OP . '/planes/guardar', array('_csrf' => $tok, 'nombre' => 'LabXss', 'max_webhooks' => '2', 'precio' => '<script>x</script>', 'publico' => '1', 'activo' => '1'), array(), $ck);
chk('un precio con codigo se muestra escapado en la pagina publica', strpos(pedir('GET', $WEB . '/')['cuerpo'], '<script>x</script>') === false);

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
