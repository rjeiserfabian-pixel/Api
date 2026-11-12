<?php
/**
 * Prueba de seguridad y funcionamiento del PANEL DEL OPERADOR y de la asignacion de planes (solo linea de comandos).
 *
 *   php docs/probar_operador.php
 *
 * Crea empresas, un operador y planes de laboratorio, ataca el panel por HTTP (cabeceras, sesiones, CSRF, XSS, confusion de
 * sesiones entre portal y panel, fuerza bruta...) y recorre todas las funciones: empresas, usuarios, planes, solicitudes
 * (automaticas y con aprobacion), consumo y estado del worker. Al terminar borra todo lo que creo. Con Apache encendido.
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
$BASE = 'http://localhost/API_SUNAT';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $BASE = rtrim($m[1], '/');
    }
}
$OP = $BASE . '/operador';
$PO = $BASE . '/portal';
$API = $BASE . '/v1';
$RUC_A = '20000000011';
$RUC_B = '20000000012';
$RUC_C = '20000000013'; // la crea el panel durante la prueba
$CLAVE = 'Cl4ve-Segura-2026!';
$HB = $RAIZ . '/storage/worker.heartbeat';
$hbOriginal = is_file($HB) ? file_get_contents($HB) : null;

foreach (array($RUC_A, $RUC_B, $RUC_C) as $r) {
    if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($r))) {
        fwrite(STDERR, "ABORTADO: ya existe la empresa $r (de una corrida interrumpida). Borrela antes de repetir.\n");
        exit(3);
    }
}
$limpiar = function () use ($RUC_A, $RUC_B, $RUC_C, $HB, $hbOriginal) {
    try {
        foreach (array($RUC_A, $RUC_B, $RUC_C) as $ruc) {
            $e = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($ruc));
            if (!$e) {
                continue;
            }
            $id = (int) $e['id'];
            foreach (array('solicitudes_plan', 'uso', 'certificados', 'api_keys', 'usuarios_portal', 'auditoria', 'series', 'documentos', 'webhooks', 'eventos') as $t) {
                Db::ejecutar("DELETE FROM $t WHERE empresa_id = ?", array($id));
            }
            Db::ejecutar('DELETE FROM empresas WHERE id = ?', array($id));
        }
        Db::ejecutar("DELETE FROM planes WHERE nombre LIKE 'Lab%'");
        Db::ejecutar("DELETE FROM operadores WHERE email LIKE '%@lab-op.test'");
        Db::ejecutar("DELETE FROM auditoria WHERE empresa_id = 0 AND detalle LIKE '%lab-op.test%'");
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'login:%' OR clave LIKE 'oplogin:%'");
    } catch (Exception $x) {
        fwrite(STDERR, 'No se pudo limpiar: ' . $x->getMessage() . "\n");
    }
    if ($hbOriginal === null) {
        @unlink($HB);
    } else {
        file_put_contents($HB, $hbOriginal);
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

class Cli
{
    public $cookies = array();
    public $ua = 'ProbarOperador/1.0';
    public $origen = null;
    public $raiz;

    public function __construct($raiz)
    {
        $this->raiz = $raiz;
    }

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
        $cabR = array();
        $setc = array();
        curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $h,
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
        $cuerpo = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $GLOBALS['ULT'] = $http . ' ' . trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style).*?</(script|style)>#s', '', (string) $cuerpo))));
        foreach ($setc as $sc) {
            $p = explode(';', $sc);
            list($n, $v) = array_pad(explode('=', trim($p[0]), 2), 2, '');
            if ($v === '' || stripos($sc, 'expires=Thu, 01-Jan-1970') !== false) {
                unset($this->cookies[$n]);
            } else {
                $this->cookies[$n] = $v;
            }
        }
        return array('http' => $http, 'cab' => $cabR, 'set' => $setc, 'cuerpo' => (string) $cuerpo, 'loc' => isset($cabR['location']) ? $cabR['location'] : null);
    }

    public function get($ruta)
    {
        return $this->req('GET', $this->raiz . $ruta);
    }

    public function csrf($ruta = '/')
    {
        $r = $this->get($ruta);
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $r['cuerpo'], $m) ? $m[1] : '';
    }

    public function post($ruta, array $campos, $desde = '/')
    {
        $tok = $this->csrf($desde);
        return $this->req('POST', $this->raiz . $ruta, array_merge(array('_csrf' => $tok), $campos));
    }

    public function login($email, $clave)
    {
        $tok = $this->csrf('/login');
        return $this->req('POST', $this->raiz . '/login', array('_csrf' => $tok, 'email' => $email, 'clave' => $clave));
    }
}

function claveCli($salida)
{
    return preg_match('/^\s{2}(\S{14})\s*$/m', $salida, $m) ? $m[1] : null;
}
/** Contrasena temporal mostrada una sola vez en pantalla (se lee despues de la accion). */
function claveEnPantalla($html)
{
    return preg_match('#<code id="secreto-valor">(\S{14})</code>#', $html, $m) ? $m[1] : null;
}
function planId($nombre)
{
    $p = Db::uno('SELECT id FROM planes WHERE nombre = ?', array($nombre));
    return $p ? (int) $p['id'] : 0;
}
function planDe($ruc)
{
    $e = Db::uno('SELECT plan_id FROM empresas WHERE ruc = ?', array($ruc));
    return $e && $e['plan_id'] ? (int) $e['plan_id'] : 0;
}
function eid($ruc)
{
    return (int) Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($ruc))['id'];
}
/** Deja el portal de un usuario listo: entra con la clave temporal y la cambia. Devuelve el cliente con sesion. */
function entrarPortal($email, $temporal, $nueva)
{
    global $PO;
    $c = new Cli($PO);
    $c->login($email, $temporal);
    $c->post('/cuenta/clave', array('clave_actual' => $temporal, 'clave_nueva' => $nueva, 'clave_repetida' => $nueva), '/cuenta');
    return $c;
}

echo "Prueba del panel del operador y de los planes\n";

echo "\n== 0) Preparacion\n";
Db::asegurarEsquema();
foreach (array(array($RUC_A, 'LAB A SAC'), array($RUC_B, 'LAB B SAC')) as $e) {
    admin('empresa:crear --ruc=' . $e[0] . ' --razon="' . $e[1] . '" --nombre="LAB" --direccion="AV. PRUEBA 1" --ubigeo=150101 --dep=LIMA --prov=LIMA --dist=LIMA --sol-user=MODDATOS --sol-pass=moddatos');
}
$tmpOp = claveCli(admin('operador:crear --email=opa@lab-op.test --nombre="Operador Lab"'));
$tmpAdminA = claveCli(admin('usuario:crear --ruc=' . $RUC_A . ' --email=cliente-a@lab-op.test --nombre="Cliente A" --rol=admin'));
$tmpLectorA = claveCli(admin('usuario:crear --ruc=' . $RUC_A . ' --email=lector-a@lab-op.test --nombre="Lector A" --rol=lector'));
chk('empresas, operador y usuarios de laboratorio creados', $tmpOp && $tmpAdminA && $tmpLectorA && Db::uno("SELECT id FROM operadores WHERE email = 'opa@lab-op.test'"));
chk('la contrasena del operador se guarda solo como hash bcrypt', strpos(Db::uno("SELECT clave_hash FROM operadores WHERE email = 'opa@lab-op.test'")['clave_hash'], '$2y$') === 0);
chk('no se puede repetir el correo del operador', stripos(admin('operador:crear --email=opa@lab-op.test --nombre=X'), 'Ya existe') !== false);
chk('plan:crear por consola acepta --autoservicio', strpos(admin('plan:crear --nombre=LabCli --cuota=10 --autoservicio=1'), 'creado') !== false && (int) Db::uno("SELECT autoservicio AS a FROM planes WHERE nombre = 'LabCli'")['a'] === 1);

echo "\n== 1) Cabeceras, cookie y archivos internos\n";
$c = new Cli($OP);
$r = $c->get('/login');
$csp = isset($r['cab']['content-security-policy']) ? $r['cab']['content-security-policy'] : '';
chk('la pagina de acceso responde 200', $r['http'] === 200);
chk("CSP estricta (sin 'unsafe-inline', sin marcos)", strpos($csp, "default-src 'none'") !== false && strpos($csp, 'unsafe-inline') === false && strpos($csp, "frame-ancestors 'none'") !== false, $csp);
chk('X-Frame-Options, nosniff, no-referrer, no-store, noindex', $r['cab']['x-frame-options'] === 'DENY' && $r['cab']['x-content-type-options'] === 'nosniff' && $r['cab']['referrer-policy'] === 'no-referrer'
    && strpos($r['cab']['cache-control'], 'no-store') !== false && strpos($r['cab']['x-robots-tag'], 'noindex') !== false);
chk('no revela la version de PHP', !isset($r['cab']['x-powered-by']));
$ck = isset($r['set'][0]) ? $r['set'][0] : '';
chk('cookie PROPIA del panel: operador_sid, HttpOnly, SameSite=Strict, limitada a /operador/', strpos($ck, 'operador_sid=') === 0 && stripos($ck, 'HttpOnly') !== false && stripos($ck, 'SameSite=Strict') !== false && strpos($ck, 'path=' . parse_url($BASE, PHP_URL_PATH) . '/operador/') !== false, $ck);
chk('sin scripts, estilos ni eventos en linea', !preg_match('/<script(?![^>]*\ssrc=)/i', $r['cuerpo']) && !preg_match('/\sstyle\s*=/i', $r['cuerpo']) && !preg_match('/\son[a-z]+\s*=/i', $r['cuerpo']));
chk('su hoja de estilos se sirve', $c->req('GET', $OP . '/assets/operador.css')['http'] === 200);
foreach (array('/src/Operadores.php', '/src/Salud.php', '/views/_layout.php', '/index.php/x') as $p) {
    $x = $c->req('GET', $OP . $p);
    chk("archivo interno $p NO es accesible", $x['http'] === 403 || $x['http'] === 404 || ($x['http'] === 302 && strpos($x['cuerpo'], 'Resumen') === false), 'HTTP ' . $x['http']);
}
$bien = true;
foreach (array('/', '/empresas', '/empresas/nueva', '/empresas/1', '/usuarios', '/planes', '/solicitudes', '/actividad', '/cuenta') as $p) {
    $x = (new Cli($OP))->get($p);
    if ($x['http'] !== 302 || strpos((string) $x['loc'], '/operador/login') === false) {
        $bien = false;
        echo "      $p -> HTTP {$x['http']}\n";
    }
}
chk('9 paginas del panel redirigen al acceso si no hay sesion', $bien);
$posts = array('/empresas/crear', '/empresas/1/plan', '/empresas/1/estado', '/empresas/1/usuario', '/usuarios/reset', '/usuarios/estado', '/planes/guardar', '/solicitudes/resolver');
$bien = true;
foreach ($posts as $p) {
    $x = (new Cli($OP))->req('POST', $OP . $p, array('id' => 1));
    if ($x['http'] !== 403) {
        $bien = false;
        echo "      POST $p sin CSRF -> {$x['http']}\n";
    }
}
chk('8 acciones de escritura sin token CSRF -> 403', $bien);

echo "\n== 2) Acceso del operador\n";
$c = new Cli($OP);
$tok = $c->csrf('/login');
chk('login con CSRF falso -> 403', $c->req('POST', $OP . '/login', array('_csrf' => str_repeat('a', 64), 'email' => 'opa@lab-op.test', 'clave' => $tmpOp))['http'] === 403);
$c->origen = 'http://sitio-malicioso.example';
chk('login con Origin de otro sitio -> 403', $c->req('POST', $OP . '/login', array('_csrf' => $tok, 'email' => 'opa@lab-op.test', 'clave' => $tmpOp))['http'] === 403);
$c->origen = null;
$r1 = (new Cli($OP))->login('noexiste@lab-op.test', 'Alguna-Clave-123');
$r2 = (new Cli($OP))->login('opa@lab-op.test', 'clave-incorrecta-1');
chk('correo inexistente y clave mala: 401 con el MISMO mensaje', $r1['http'] === 401 && $r2['http'] === 401
    && preg_replace('/value="[^"]*"/', '', $r1['cuerpo']) === preg_replace('/value="[^"]*"/', '', $r2['cuerpo']));
chk('un cliente (usuario del portal) NO puede entrar al panel del operador', (new Cli($OP))->login('cliente-a@lab-op.test', $tmpAdminA)['http'] === 401);
$r3 = (new Cli($OP))->login('"><script>alert(1)</script>@x.test', 'x');
chk('un correo con codigo se muestra escapado', strpos($r3['cuerpo'], '<script>alert(1)') === false);
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'oplogin:%'");
Db::ejecutar("UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL");
$c = new Cli($OP);
for ($i = 1; $i <= 5; $i++) {
    $c->login('opa@lab-op.test', 'intento-' . $i . '-Mal');
}
chk('tras 5 fallos ni la contrasena correcta entra (bloqueo de 15 min)', $c->login('opa@lab-op.test', $tmpOp)['http'] === 401);
$u = Db::uno("SELECT bloqueado_hasta FROM operadores WHERE email = 'opa@lab-op.test'");
chk('el bloqueo vence en ~15 min', $u['bloqueado_hasta'] !== null && strtotime($u['bloqueado_hasta']) - time() > 800 && strtotime($u['bloqueado_hasta']) - time() <= 905);
$tmpOp = claveCli(admin('operador:reset --email=opa@lab-op.test'));
chk('operador:reset por consola lo desbloquea con una clave temporal nueva', $tmpOp !== null);

$c = new Cli($OP);
$c->get('/login');
$sidAntes = isset($c->cookies['operador_sid']) ? $c->cookies['operador_sid'] : '';
$r = $c->login('opa@lab-op.test', $tmpOp);
$sidDespues = $c->cookies['operador_sid'];
chk('login correcto -> a /cuenta (debe cambiar la clave temporal)', $r['http'] === 302 && strpos((string) $r['loc'], '/operador/cuenta') !== false);
chk('el identificador de sesion cambia al entrar (anti fijacion)', $sidAntes !== '' && $sidAntes !== $sidDespues);
$v = new Cli($OP);
$v->cookies['operador_sid'] = $sidAntes;
chk('el identificador anterior no sirve', $v->get('/')['http'] === 302);
chk('con clave temporal todo redirige a /cuenta', strpos((string) $c->get('/planes')['loc'], '/operador/cuenta') !== false);
$c->post('/cuenta/clave', array('clave_actual' => $tmpOp, 'clave_nueva' => 'sololetrasminusculas', 'clave_repetida' => 'sololetrasminusculas'), '/cuenta');
chk('politica: una sola clase de caracteres -> rechazada', strpos($c->get('/cuenta')['cuerpo'], 'dos tipos') !== false);
$c->post('/cuenta/clave', array('clave_actual' => $tmpOp, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
chk('contrasena valida -> aceptada; el panel abre', $c->get('/')['http'] === 200);
chk('la clave temporal anterior ya no entra', (new Cli($OP))->login('opa@lab-op.test', $tmpOp)['http'] === 401);
$op = $c;

echo "\n== 3) Un cliente no puede usar su sesion en el panel (ni al reves)\n";
$cA = entrarPortal('cliente-a@lab-op.test', $tmpAdminA, $CLAVE);
chk('el cliente A ya esta dentro de su portal', $cA->get('/')['http'] === 200);
$falso = new Cli($OP);
$falso->cookies['operador_sid'] = $cA->cookies['portal_sid'];
chk('presentar la sesion del PORTAL como cookie del panel -> rechazada', $falso->get('/')['http'] === 302 && $falso->get('/empresas')['http'] === 302);
$falso2 = new Cli($PO);
$falso2->cookies['portal_sid'] = $op->cookies['operador_sid'];
chk('presentar la sesion del PANEL como cookie del portal -> rechazada', $falso2->get('/')['http'] === 302);
chk('el cliente tampoco entra a /operador con su propia cookie', $cA->req('GET', $OP . '/empresas')['http'] === 302);

echo "\n== 4) Estado del worker\n";
file_put_contents($HB, (string) time());
$p = $op->get('/');
chk('latido reciente -> "Worker activo"', strpos($p['cuerpo'], 'Worker activo') !== false);
file_put_contents($HB, (string) (time() - 300));
chk('latido de hace 5 min -> "Worker lento"', strpos($op->get('/')['cuerpo'], 'Worker lento') !== false);
file_put_contents($HB, (string) (time() - 3600));
$p = $op->get('/');
chk('latido de hace 1 h -> "Worker DETENIDO" con instrucciones', strpos($p['cuerpo'], 'Worker DETENIDO') !== false && strpos($p['cuerpo'], 'php bin/worker.php') !== false);
@unlink($HB);
chk('sin archivo de latido -> "Worker sin iniciar"', strpos($op->get('/empresas')['cuerpo'], 'Worker sin iniciar') !== false);
file_put_contents($HB, (string) time());
// trabajo vencido en cola
$eA = eid($RUC_A);
Db::ejecutar("INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, cuerpo, proximo_intento, reintentos, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
    array('doc_' . bin2hex(random_bytes(8)), $eA, '03', 'B777', '00000001', 1, 'test', 'error', '{}', date('Y-m-d H:i:s', time() - 1800), 1, Db::ahora()));
$p = $op->get('/');
chk('un documento con reintento vencido se refleja en las colas', preg_match('/Reintentos programados: <strong>(\d+)<\/strong> \(vencidos: ([1-9]\d*)\)/', $p['cuerpo']) === 1 && strpos($p['cuerpo'], 'Trabajo vencido esperando') !== false);

echo "\n== 5) Planes (crear, validar, editar)\n";
$op->post('/planes/guardar', array('nombre' => 'LabAuto', 'cuota_mensual' => '100', 'limite_sunat_min' => '5', 'limite_general_min' => '20', 'max_webhooks' => '2', 'autoservicio' => '1'), '/planes');
chk('plan de autoservicio creado', planId('LabAuto') > 0 && (int) Db::uno("SELECT autoservicio AS a FROM planes WHERE nombre = 'LabAuto'")['a'] === 1);
$op->post('/planes/guardar', array('nombre' => 'LabPago', 'cuota_mensual' => '1000', 'limite_sunat_min' => '30', 'limite_general_min' => '90', 'max_webhooks' => '3'), '/planes');
chk('plan con aprobacion creado (autoservicio apagado)', planId('LabPago') > 0 && (int) Db::uno("SELECT autoservicio AS a FROM planes WHERE nombre = 'LabPago'")['a'] === 0);
$op->post('/planes/guardar', array('nombre' => 'LabIlim', 'cuota_mensual' => '', 'limite_sunat_min' => '', 'limite_general_min' => '', 'max_webhooks' => '5'), '/planes');
$pi = Db::uno("SELECT * FROM planes WHERE nombre = 'LabIlim'");
chk('campos vacios = ilimitado / sin limite (NULL)', $pi && $pi['cuota_mensual'] === null && $pi['limite_sunat_min'] === null && $pi['limite_general_min'] === null);
$n0 = (int) Db::uno('SELECT COUNT(*) AS n FROM planes')['n'];
$op->post('/planes/guardar', array('nombre' => 'LabAuto', 'max_webhooks' => '2'), '/planes');
chk('nombre repetido -> rechazado', strpos($op->get('/planes')['cuerpo'], 'Ya existe un plan') !== false && (int) Db::uno('SELECT COUNT(*) AS n FROM planes')['n'] === $n0);
foreach (array(array('LabX', array('cuota_mensual' => '-5')), array('LabX', array('cuota_mensual' => 'abc')), array('LabX', array('limite_sunat_min' => '0')), array('LabX', array('max_webhooks' => '0')),
    array('LabX', array('max_webhooks' => '500')), array('<b>LabX</b>', array()), array('LabX', array('cuota_mensual' => '99999999999'))) as $caso) {
    $op->post('/planes/guardar', array_merge(array('nombre' => $caso[0], 'max_webhooks' => '2'), $caso[1]), '/planes');
}
chk('numeros negativos, texto, cero, fuera de rango y nombres con HTML -> ninguno se crea', (int) Db::uno('SELECT COUNT(*) AS n FROM planes')['n'] === $n0 && planId('LabX') === 0);
$idAuto = planId('LabAuto');
$op->post('/planes/guardar', array('id' => $idAuto, 'nombre' => 'LabAuto', 'cuota_mensual' => '150', 'limite_sunat_min' => '5', 'limite_general_min' => '20', 'max_webhooks' => '2', 'autoservicio' => '1', 'activo' => '1'), '/planes');
chk('editar un plan existente (cuota 100 -> 150)', (int) Db::uno('SELECT cuota_mensual AS c FROM planes WHERE id = ?', array($idAuto))['c'] === 150);
$op->post('/planes/guardar', array('id' => 999999, 'nombre' => 'LabFantasma', 'max_webhooks' => '2'), '/planes');
chk('editar un plan inexistente -> rechazado (no crea)', planId('LabFantasma') === 0);

echo "\n== 6) Asignar plan a una empresa (manual)\n";
$op->post('/empresas/' . $eA . '/plan', array('plan_id' => planId('LabPago')), '/empresas/' . $eA);
chk('el operador asigna LabPago a la empresa A', planDe($RUC_A) === planId('LabPago'));
$pl = Planes::de(Db::uno('SELECT * FROM empresas WHERE id = ?', array($eA)));
chk('el motor de limites ya aplica el plan nuevo (cuota 1000, 30 emisiones/min)', $pl['nombre'] === 'LabPago' && $pl['cuota_mensual'] === 1000 && $pl['limite_sunat_min'] === 30);
$act = $cA->get('/actividad');
chk('el cliente ve el cambio en su actividad, indicando que fue el operador', strpos($act['cuerpo'], 'plan_cambiado') !== false && strpos($act['cuerpo'], 'operador opa@lab-op.test') !== false);
$op->post('/empresas/' . $eA . '/plan', array('plan_id' => 'ninguno', 'clave_actual' => ''), '/empresas/' . $eA);
chk('quitar el plan SIN contrasena -> no pasa (eliminaria todos los limites)', planDe($RUC_A) === planId('LabPago'));
$op->post('/empresas/' . $eA . '/plan', array('plan_id' => 'ninguno', 'clave_actual' => $CLAVE), '/empresas/' . $eA);
chk('con la contrasena, queda sin plan', planDe($RUC_A) === 0);
$op->post('/empresas/' . $eA . '/plan', array('plan_id' => '999999'), '/empresas/' . $eA);
$op->post('/empresas/' . $eA . '/plan', array('plan_id' => '1 OR 1=1'), '/empresas/' . $eA);
chk('un plan inexistente o un id manipulado no cambia nada', planDe($RUC_A) === 0);
chk('empresa inexistente -> 404', $op->req('POST', $OP . '/empresas/9999999/plan', array('_csrf' => $op->csrf('/'), 'plan_id' => $idAuto))['http'] === 404);
$op->post('/empresas/' . $eA . '/plan', array('plan_id' => planId('LabAuto')), '/empresas/' . $eA);
chk('le deja LabAuto para las pruebas siguientes', planDe($RUC_A) === planId('LabAuto'));
$op->post('/planes/guardar', array('id' => $idAuto, 'nombre' => 'LabAuto', 'max_webhooks' => '2'), '/planes'); // sin el casillero "activo"
chk('no se puede DESACTIVAR un plan con empresas (quedarian sin ningun limite)', strpos($op->get('/planes')['cuerpo'], 'No se puede desactivar') !== false && (int) Db::uno('SELECT activo AS a FROM planes WHERE id = ?', array($idAuto))['a'] === 1);

echo "\n== 7) El cliente pide un plan (portal): automatico vs con aprobacion\n";
$pagPlan = $cA->get('/plan');
chk('el portal lista los planes activos y marca "inmediata" / "con aprobacion"', $pagPlan['http'] === 200 && strpos($pagPlan['cuerpo'], 'LabPago') !== false && strpos($pagPlan['cuerpo'], 'inmediata') !== false && strpos($pagPlan['cuerpo'], 'con aprobación') !== false);
$cA->post('/plan/solicitar', array('plan_id' => planId('LabIlim'), 'clave_actual' => 'contrasena-equivocada-1'), '/plan');
chk('pedir un plan con la contrasena equivocada -> no se registra nada', (int) Db::uno('SELECT COUNT(*) AS n FROM solicitudes_plan WHERE empresa_id = ?', array($eA))['n'] === 0);
Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL');
$cA->post('/plan/solicitar', array('plan_id' => planId('LabCli'), 'clave_actual' => $CLAVE), '/plan');
chk('plan de AUTOSERVICIO: se asigna al instante, sin intervencion del operador', planDe($RUC_A) === planId('LabCli') && Db::uno("SELECT estado FROM solicitudes_plan WHERE empresa_id = ? ORDER BY id DESC LIMIT 1", array($eA))['estado'] === 'automatica');
$cA->post('/plan/solicitar', array('plan_id' => planId('LabPago'), 'clave_actual' => $CLAVE, 'nota' => 'Necesito mas documentos'), '/plan');
$pend = Db::uno("SELECT * FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($eA));
chk('plan CON APROBACION: queda pendiente y el plan actual NO cambia', $pend && planDe($RUC_A) === planId('LabCli'));
chk('el portal muestra la solicitud pendiente', strpos($cA->get('/plan')['cuerpo'], 'solicitud pendiente') !== false);
$cA->post('/plan/solicitar', array('plan_id' => planId('LabCli'), 'clave_actual' => $CLAVE), '/plan');
chk('pedir el plan que ya tiene -> error, sin cambios', strpos($cA->get('/plan')['cuerpo'], 'Ya tiene ese plan') !== false);
$cA->post('/plan/solicitar', array('plan_id' => '999999', 'clave_actual' => $CLAVE), '/plan');
chk('pedir un plan inexistente -> error', strpos($cA->get('/plan')['cuerpo'], 'no esta disponible') !== false);
$cL = new Cli($PO);
$cL->login('lector-a@lab-op.test', $tmpLectorA);
$cL->post('/cuenta/clave', array('clave_actual' => $tmpLectorA, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
chk('un usuario "lector" ve los planes pero no puede pedirlos (403)', $cL->get('/plan')['http'] === 200 && $cL->post('/plan/solicitar', array('plan_id' => planId('LabIlim'), 'clave_actual' => $CLAVE), '/plan')['http'] === 403);
// el cliente cambia de idea: cancela
$cA->post('/plan/cancelar', array(), '/plan');
chk('el cliente puede cancelar su solicitud pendiente', (int) Db::uno("SELECT COUNT(*) AS n FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($eA))['n'] === 0);
$cA->post('/plan/solicitar', array('plan_id' => planId('LabPago'), 'clave_actual' => $CLAVE), '/plan');
$cA->post('/plan/solicitar', array('plan_id' => planId('LabIlim'), 'clave_actual' => $CLAVE), '/plan');
$pendientes = Db::consultar("SELECT plan_id FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($eA));
chk('una solicitud nueva reemplaza a la pendiente anterior (solo hay una)', count($pendientes) === 1 && (int) $pendientes[0]['plan_id'] === planId('LabIlim'));

echo "\n== 8) El operador resuelve solicitudes\n";
$sol = $op->get('/solicitudes');
chk('el panel muestra la solicitud pendiente y el aviso en el menu', strpos($sol['cuerpo'], 'LabIlim') !== false && strpos($sol['cuerpo'], 'cliente-a@lab-op.test') !== false && strpos($sol['cuerpo'], 'badge espera') !== false);
$sid = (int) Db::uno("SELECT id FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($eA))['id'];
$op->post('/solicitudes/resolver', array('id' => $sid, 'accion' => 'rechazar'), '/solicitudes');
chk('rechazar: el plan no cambia y la solicitud queda "rechazada"', planDe($RUC_A) === planId('LabCli') && Db::uno('SELECT estado FROM solicitudes_plan WHERE id = ?', array($sid))['estado'] === 'rechazada');
chk('...el cliente lo ve en su actividad', strpos($cA->get('/actividad')['cuerpo'], 'solicitud_rechazada') !== false);
$op->post('/solicitudes/resolver', array('id' => $sid, 'accion' => 'aprobar'), '/solicitudes');
chk('una solicitud ya resuelta no se puede volver a resolver', planDe($RUC_A) === planId('LabCli') && strpos($op->get('/solicitudes')['cuerpo'], 'ya no esta pendiente') !== false);
$cA->post('/plan/solicitar', array('plan_id' => planId('LabPago'), 'clave_actual' => $CLAVE), '/plan');
$sid = (int) Db::uno("SELECT id FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($eA))['id'];
$op->post('/solicitudes/resolver', array('id' => $sid, 'accion' => 'aprobar'), '/solicitudes');
chk('aprobar: el plan se activa al instante', planDe($RUC_A) === planId('LabPago') && Db::uno('SELECT estado, resuelto_por FROM solicitudes_plan WHERE id = ?', array($sid))['estado'] === 'aprobada');
chk('...y el historial muestra quien la aprobo', strpos($op->get('/solicitudes')['cuerpo'], 'opa@lab-op.test') !== false);
$cA->post('/plan/solicitar', array('plan_id' => planId('LabCli'), 'clave_actual' => $CLAVE), '/plan');
$cA->post('/plan/solicitar', array('plan_id' => planId('LabIlim'), 'clave_actual' => $CLAVE), '/plan');
$op->post('/empresas/' . $eA . '/plan', array('plan_id' => planId('LabAuto')), '/empresas/' . $eA);
chk('si el operador asigna un plan directamente, la solicitud pendiente se cancela', (int) Db::uno("SELECT COUNT(*) AS n FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($eA))['n'] === 0);

echo "\n== 9) Consumo del mes\n";
$periodo = date('Y-m');
Db::ejecutar('DELETE FROM uso WHERE empresa_id = ?', array($eA));
Db::ejecutar('INSERT INTO uso (empresa_id, periodo, documentos, aviso80, aviso100) VALUES (?,?,?,0,0)', array($eA, $periodo, 135)); // LabAuto: cuota 150 -> 90 %
Db::ejecutar('INSERT INTO uso (empresa_id, periodo, documentos, aviso80, aviso100) VALUES (?,?,?,0,0)', array($eA, date('Y-m', strtotime('-1 month')), 77));
$p = $op->get('/');
chk('el resumen lista a la empresa cerca de su cuota (90 %)', strpos($p['cuerpo'], 'LAB A SAC') !== false && strpos($p['cuerpo'], '135 / 150') !== false && strpos($p['cuerpo'], '90 %') !== false);
$p = $op->get('/empresas/' . $eA);
chk('la ficha muestra consumo del mes, % y meses anteriores', strpos($p['cuerpo'], '135 / 150') !== false && strpos($p['cuerpo'], date('Y-m', strtotime('-1 month')) . ': 77') !== false && strpos($p['cuerpo'], 'progress') !== false);
$p = $op->get('/empresas?q=2000000001');
chk('el listado muestra consumo y plan, y la busqueda filtra', strpos($p['cuerpo'], 'LAB A SAC') !== false && strpos($p['cuerpo'], '135 / 150') !== false && strpos($p['cuerpo'], 'LAB B SAC') !== true);
chk('...y LAB B (sin plan) aparece marcada "sin plan"', strpos($op->get('/empresas?q=' . $RUC_B)['cuerpo'], 'sin plan') !== false);
chk('buscar un RUC que no existe -> lista vacia', strpos($op->get('/empresas?q=99999999999')['cuerpo'], 'No hay empresas') !== false);
$x = $op->get('/empresas?q=' . urlencode('"><script>x</script>') . '&%5B%5D=1');
chk('una busqueda con codigo malicioso no se refleja', $x['http'] === 200 && strpos($x['cuerpo'], '<script>x') === false);

echo "\n== 10) Alta de un cliente nuevo desde el panel\n";
$campos = array('ruc' => $RUC_C, 'razon' => 'LAB C SAC', 'nombre' => 'LABC', 'direccion' => 'JR. LAB 3', 'ubigeo' => '150101', 'dep' => 'LIMA', 'prov' => 'LIMA', 'dist' => 'LIMA',
    'plan_id' => planId('LabPago'), 'email' => 'nuevo-c@lab-op.test', 'nombre_usuario' => 'Nuevo C');
$op->post('/empresas/crear', array_merge($campos, array('clave_actual' => 'contrasena-equivocada-1')), '/empresas/nueva');
chk('con la contrasena equivocada -> no se crea', !Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($RUC_C)));
Db::ejecutar("UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL");
foreach (array(array('ruc' => '123'), array('ubigeo' => 'abc'), array('razon' => ''), array('plan_id' => 999999), array('email' => 'no-es-correo'), array('ruc' => $RUC_A), array('email' => 'cliente-a@lab-op.test')) as $mala) {
    $op->post('/empresas/crear', array_merge($campos, $mala, array('clave_actual' => $CLAVE)), '/empresas/nueva');
}
chk('RUC corto, ubigeo invalido, razon vacia, plan inexistente, correo malo, RUC o correo repetidos -> ninguno crea la empresa', !Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($RUC_C)));
$r = $op->post('/empresas/crear', array_merge($campos, array('clave_actual' => $CLAVE)), '/empresas/nueva');
$pagC = $op->get('/empresas/' . eid($RUC_C));
$tmpC = claveEnPantalla($pagC['cuerpo']);
chk('con datos validos: empresa creada con su plan', $r['http'] === 302 && (bool) Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($RUC_C)) && planDe($RUC_C) === planId('LabPago'));
chk('el primer usuario administrador se crea y su clave temporal se muestra UNA vez', $tmpC !== null && claveEnPantalla($op->get('/empresas/' . eid($RUC_C))['cuerpo']) === null);
$cC = new Cli($PO);
$rC = $cC->login('nuevo-c@lab-op.test', (string) $tmpC);
chk('ese usuario entra al portal con la clave temporal y se le obliga a cambiarla', $rC['http'] === 302 && strpos((string) $rC['loc'], '/portal/cuenta') !== false);
$fc = Db::uno('SELECT sol_usuario, sol_clave_cifrada FROM empresas WHERE ruc = ?', array($RUC_C));
chk('la empresa nace SIN credenciales SUNAT (las carga el cliente desde su portal)', $fc['sol_usuario'] === null && $fc['sol_clave_cifrada'] === null);
chk('el alta consta en la actividad de la empresa', strpos($pagC['cuerpo'], 'empresa_creada') !== false);

echo "\n== 11) Usuarios: crear, restablecer clave, desactivar\n";
$eB = eid($RUC_B);
$op->post('/empresas/' . $eB . '/usuario', array('email' => 'dos@lab-op.test', 'nombre' => 'Dos', 'rol' => 'lector', 'clave_actual' => 'contrasena-equivocada-1'), '/empresas/' . $eB);
chk('crear usuario con la contrasena equivocada -> no se crea', Usuarios_existe('dos@lab-op.test') === false);
Db::ejecutar("UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL");
$op->post('/empresas/' . $eB . '/usuario', array('email' => 'dos@lab-op.test', 'nombre' => 'Dos', 'rol' => 'lector', 'clave_actual' => $CLAVE), '/empresas/' . $eB);
$tmpDos = claveEnPantalla($op->get('/empresas/' . $eB)['cuerpo']);
chk('usuario creado con rol lector y clave temporal visible una vez', Usuarios_existe('dos@lab-op.test') && $tmpDos !== null && Db::uno("SELECT rol FROM usuarios_portal WHERE email = 'dos@lab-op.test'")['rol'] === 'lector');
$op->post('/empresas/' . $eB . '/usuario', array('email' => 'dos@lab-op.test', 'nombre' => 'Dos', 'rol' => 'admin', 'clave_actual' => $CLAVE), '/empresas/' . $eB);
chk('correo repetido -> rechazado', strpos($op->get('/empresas/' . $eB)['cuerpo'], 'Ya existe un usuario') !== false);
$op->post('/empresas/' . $eB . '/usuario', array('email' => '"><script>x</script>', 'nombre' => 'X', 'rol' => 'admin', 'clave_actual' => $CLAVE), '/empresas/' . $eB);
chk('correo con codigo -> rechazado', strpos($op->get('/empresas/' . $eB)['cuerpo'], '<script>x') === false);
// sesiones: un usuario con sesion abierta y el operador le restablece la clave
$cDos = entrarPortal('dos@lab-op.test', $tmpDos, $CLAVE);
chk('el usuario B entra y cambia su clave', $cDos->get('/')['http'] === 200);
$otraSesion = new Cli($PO);
$otraSesion->login('dos@lab-op.test', $CLAVE);
chk('...tiene una segunda sesion abierta en otro dispositivo', $otraSesion->get('/')['http'] === 200);
$cDos->post('/cuenta/clave', array('clave_actual' => $CLAVE, 'clave_nueva' => 'Otra-Clave-Distinta-77', 'clave_repetida' => 'Otra-Clave-Distinta-77'), '/cuenta');
chk('al cambiar su contrasena, la sesion actual sigue valida', $cDos->get('/')['http'] === 200);
chk('...y la OTRA sesion abierta se cierra sola (contrasena cambiada)', $otraSesion->get('/')['http'] === 302);
$uDos = Db::uno("SELECT id FROM usuarios_portal WHERE email = 'dos@lab-op.test'");
$op->post('/usuarios/reset', array('id' => $uDos['id'], 'clave_actual' => 'contrasena-equivocada-1'), '/usuarios');
chk('restablecer con la contrasena equivocada -> no pasa (su sesion sigue)', $cDos->get('/')['http'] === 200);
Db::ejecutar("UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL");
$op->post('/usuarios/reset', array('id' => $uDos['id'], 'clave_actual' => $CLAVE), '/usuarios');
$tmpDos2 = claveEnPantalla($op->get('/usuarios')['cuerpo']);
chk('restablecer: clave temporal nueva visible una vez', $tmpDos2 !== null && $tmpDos2 !== $tmpDos);
chk('...cierra la sesion que el usuario tenia abierta', $cDos->get('/')['http'] === 302);
chk('...la clave anterior ya no entra y la temporal si (con cambio obligatorio)', (new Cli($PO))->login('dos@lab-op.test', 'Otra-Clave-Distinta-77')['http'] === 401 && (new Cli($PO))->login('dos@lab-op.test', $tmpDos2)['http'] === 302);
$op->post('/usuarios/estado', array('id' => $uDos['id'], 'activo' => '0', 'clave_actual' => $CLAVE), '/usuarios');
chk('desactivar: ya no puede ingresar', (new Cli($PO))->login('dos@lab-op.test', $tmpDos2)['http'] === 401);
$op->post('/usuarios/estado', array('id' => $uDos['id'], 'activo' => '1', 'clave_actual' => $CLAVE), '/usuarios');
chk('reactivar: vuelve a ingresar', (new Cli($PO))->login('dos@lab-op.test', $tmpDos2)['http'] === 302);
chk('usuario inexistente -> 404', $op->req('POST', $OP . '/usuarios/reset', array('_csrf' => $op->csrf('/usuarios'), 'id' => 99999999, 'clave_actual' => $CLAVE))['http'] === 404);
$lista = $op->get('/usuarios?q=lab-op');
chk('la lista de usuarios filtra y muestra empresa, rol y estado', strpos($lista['cuerpo'], 'cliente-a@lab-op.test') !== false && strpos($lista['cuerpo'], 'LAB B SAC') !== false);

echo "\n== 12) Desactivar una empresa corta API y portal\n";
$k = admin('key:crear --ruc=' . $RUC_B . ' --entorno=test --nombre=lab');
preg_match('/sk_test_[a-f0-9]{40}/', $k, $mk);
$llave = isset($mk[0]) ? $mk[0] : '';
$api = function () use ($API, $llave) {
    $c = curl_init($API . '/me');
    curl_setopt_array($c, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $llave), CURLOPT_TIMEOUT => 30));
    curl_exec($c);
    $h = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $h;
};
chk('antes: la llave de la empresa B funciona', $llave !== '' && $api() === 200);
$cDos = new Cli($PO);
$cDos->login('dos@lab-op.test', $tmpDos2);
$cDos->post('/cuenta/clave', array('clave_actual' => $tmpDos2, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
chk('...y su usuario esta dentro del portal', $cDos->get('/')['http'] === 200);
$op->post('/empresas/' . $eB . '/estado', array('activo' => '0', 'clave_actual' => 'contrasena-equivocada-1'), '/empresas/' . $eB);
chk('desactivar con la contrasena equivocada -> no pasa', $api() === 200);
Db::ejecutar("UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL");
$op->post('/empresas/' . $eB . '/estado', array('activo' => '0', 'clave_actual' => $CLAVE), '/empresas/' . $eB);
chk('desactivada: la API responde 403 de inmediato', $api() === 403);
chk('...su sesion del portal se cierra y no puede volver a entrar', $cDos->get('/')['http'] === 302 && (new Cli($PO))->login('dos@lab-op.test', $CLAVE)['http'] === 401);
$op->post('/empresas/' . $eB . '/estado', array('activo' => '1', 'clave_actual' => $CLAVE), '/empresas/' . $eB);
chk('reactivada: la API vuelve a funcionar', $api() === 200);

echo "\n== 13) XSS en el panel\n";
$cad = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
Db::ejecutar('UPDATE empresas SET razon_social = ?, nombre_comercial = ? WHERE id = ?', array('EMP ' . $cad, 'NC ' . $cad, $eA));
Db::ejecutar('UPDATE usuarios_portal SET nombre = ? WHERE email = ?', array('Nom ' . $cad, 'cliente-a@lab-op.test'));
Db::ejecutar('UPDATE planes SET nombre = ? WHERE id = ?', array('LabAuto', $idAuto));
$limpio = true;
foreach (array('/', '/empresas', '/empresas/' . $eA, '/usuarios', '/solicitudes', '/actividad', '/planes') as $pg) {
    $x = $op->get($pg);
    if (strpos($x['cuerpo'], '<script>alert') !== false || strpos($x['cuerpo'], '<img src=x') !== false) {
        $limpio = false;
        echo "      SIN ESCAPAR en $pg\n";
    }
}
chk('razon social, nombre y datos con <script>/<img> salen escapados en las 7 paginas', $limpio && strpos($op->get('/empresas/' . $eA)['cuerpo'], '&lt;script&gt;') !== false);
Db::ejecutar('UPDATE empresas SET razon_social = ?, nombre_comercial = ? WHERE id = ?', array('LAB A SAC', 'LAB', $eA));

echo "\n== 14) Actividad y auditoria\n";
$act = $op->get('/actividad');
chk('el panel muestra accesos, fallos y bloqueos del operador', strpos($act['cuerpo'], 'op_login_ok') !== false && strpos($act['cuerpo'], 'op_login_fallido') !== false && strpos($act['cuerpo'], 'op_bloqueado') !== false);
chk('...y los cambios de plan, altas y usuarios de las empresas', strpos($act['cuerpo'], 'plan_cambiado') !== false && strpos($act['cuerpo'], 'empresa_creada') !== false && strpos($act['cuerpo'], 'usuario_creado') !== false);
$todo = $act['cuerpo'] . $op->get('/empresas/' . $eA)['cuerpo'] . $op->get('/usuarios')['cuerpo'];
chk('ninguna pagina contiene contrasenas, claves temporales ni hashes bcrypt', strpos($todo, $CLAVE) === false && strpos($todo, (string) $tmpOp) === false && strpos($todo, '$2y$') === false);

echo "\n== 15) Sesion del operador: expiracion y cierre\n";
$sid = $op->cookies['operador_sid'];
$arch = $RAIZ . '/storage/sessions_operador/sess_' . $sid;
$ses = @file_get_contents($arch);
chk('las sesiones del panel viven en su PROPIA carpeta (storage/sessions_operador)', $ses !== false && strpos($ses, 'uid|') !== false && !is_file($RAIZ . '/storage/sessions/sess_' . $sid));
file_put_contents($arch, preg_replace('/t_ultimo\|i:\d+;/', 't_ultimo|i:' . (time() - 3600) . ';', $ses));
$x = $op->get('/planes');
chk('inactividad > 30 min: expira y pide ingresar', $x['http'] === 302 && strpos((string) $x['loc'], 'login') !== false);
$op = new Cli($OP);
$op->login('opa@lab-op.test', $CLAVE);
$op->ua = 'Otro-Navegador/9.9';
chk('cambio de navegador con la misma cookie -> sesion invalida', $op->get('/')['http'] === 302);
$op = new Cli($OP);
$op->login('opa@lab-op.test', $CLAVE);
$sid = $op->cookies['operador_sid'];
$op->post('/logout', array(), '/');
$copia = new Cli($OP);
$copia->cookies['operador_sid'] = $sid;
chk('tras salir, la cookie copiada ya no sirve', $copia->get('/')['http'] === 302 && $op->get('/')['http'] === 302);

echo "\n== 16) Limite de intentos por IP (al final: afecta a los demas accesos)\n";
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'oplogin:%'");
$flood = new Cli($OP);
for ($i = 0; $i < 21; $i++) {
    $flood->login('inexistente' . $i . '@lab-op.test', 'Alguna-Clave-123');
}
chk('tras 20 fallos desde una IP, ni el operador legitimo entra desde ella', (new Cli($OP))->login('opa@lab-op.test', $CLAVE)['http'] === 401);
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'oplogin:%'");
Db::ejecutar('UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL');
chk('(al vencer la ventana vuelve a funcionar)', (new Cli($OP))->login('opa@lab-op.test', $CLAVE)['http'] === 302);

function Usuarios_existe($email)
{
    return (bool) Db::uno('SELECT id FROM usuarios_portal WHERE email = ?', array($email));
}

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
