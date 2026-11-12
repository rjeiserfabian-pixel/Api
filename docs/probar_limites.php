<?php
/**
 * Prueba de limites de velocidad, tope global y cuota mensual de los planes (solo linea de comandos).
 *
 *   php docs/probar_limites.php
 *
 * Crea una EMPRESA DE LABORATORIO propia (RUC 20000000009) con su certificado, llaves test y live y un plan temporal, y arranca
 * un MOTOR FALSO y un receptor de webhooks (puertos 8098 y 8099). Las llaves live solo se usan contra el motor falso: NO se envia
 * nada a SUNAT. Modifica .env durante la prueba y lo RESTAURA; al terminar borra todo lo que creo.
 * Tarda unos minutos (espera cambios de minuto para probar los limites por minuto). Con Apache encendido.
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
$TMP = __DIR__ . '/pruebas/tmp';
if (!is_dir($TMP)) {
    mkdir($TMP, 0755, true);
}
$base = 'http://localhost/API_SUNAT/v1';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $base = rtrim($m[1], '/');
    }
}
$RUC = '20000000009';

// PROTECCION: el worker real procesa TODA la cola; con un motor falso no debe haber trabajo real pendiente
$pend = (int) Db::uno("SELECT COUNT(*) AS n FROM documentos WHERE (estado = 'error' AND cuerpo IS NOT NULL AND proximo_intento IS NOT NULL) OR (estado = 'pendiente' AND tipo = '09' AND proximo_intento IS NOT NULL)")['n'];
$entr = (int) Db::uno("SELECT COUNT(*) AS n FROM entregas WHERE estado IN ('pendiente', 'enviando')")['n'];
if ($pend + $entr > 0 && !in_array('--ignorar-cola', $argv, true)) {
    fwrite(STDERR, "ABORTADO: la cola tiene trabajo real pendiente ($pend documentos, $entr avisos). Esta prueba usa un motor FALSO. Espere a que el worker lo termine.\n");
    exit(3);
}
if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($RUC))) {
    fwrite(STDERR, "ABORTADO: ya existe una empresa con RUC $RUC (de una corrida interrumpida). Borrela o revise antes de repetir.\n");
    exit(3);
}

$envReal = file_get_contents('.env');
$servidores = array();
$limpiar = function () use (&$servidores, $envReal, $RAIZ, $RUC) {
    file_put_contents($RAIZ . '/.env', $envReal);
    try {
        $e = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($RUC));
        if ($e) {
            $id = (int) $e['id'];
            Db::ejecutar('DELETE FROM entregas WHERE webhook_id IN (SELECT id FROM webhooks WHERE empresa_id = ?)', array($id));
            foreach (array('eventos', 'webhooks', 'documentos', 'series', 'uso', 'certificados', 'api_keys') as $t) {
                Db::ejecutar("DELETE FROM $t WHERE empresa_id = ?", array($id));
            }
            Db::ejecutar('DELETE FROM empresas WHERE id = ?', array($id));
        }
        Db::ejecutar("DELETE FROM planes WHERE nombre LIKE 'tmp_lim_%'");
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'k%' AND 0 = 1");
    } catch (Exception $x) {
        fwrite(STDERR, 'No se pudo limpiar: ' . $x->getMessage() . "\n");
    }
    foreach ($servidores as $p) {
        if (is_resource($p)) {
            $st = proc_get_status($p);
            if (stripos(PHP_OS, 'WIN') === 0 && !empty($st['pid'])) {
                @exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' >NUL 2>&1');
            }
            proc_terminate($p);
        }
    }
};
register_shutdown_function($limpiar);

function ponerEnv($extra)
{
    global $envReal;
    $env = preg_replace('/^(API_INTERNAL_URL|WEBHOOKS_PERMITIR_REDES_PRIVADAS|LIMITE_GLOBAL_SUNAT_MIN)=.*\R?/m', '', $envReal);
    file_put_contents('.env', $env . "\nAPI_INTERNAL_URL=http://127.0.0.1:8098\nWEBHOOKS_PERMITIR_REDES_PRIVADAS=1\n" . $extra);
}
ponerEnv('');
foreach (array(8098 => 'motor_falso.php', 8099 => 'receptor.php') as $puerto => $archivo) {
    $s = @fsockopen('127.0.0.1', $puerto, $en, $es, 1);
    if ($s) {
        fclose($s);
        continue;
    }
    $servidores[] = proc_open('php -S 127.0.0.1:' . $puerto . ' ' . escapeshellarg(__DIR__ . '/pruebas/' . $archivo), array(0 => array('pipe', 'r'), 1 => array('file', $TMP . '/servidor_' . $puerto . '.log', 'w'), 2 => array('file', $TMP . '/servidor_' . $puerto . '.log', 'a')), $pp);
}
usleep(1500000);
file_put_contents($TMP . '/fake_state.json', '{"modo":"ok"}');
@file_put_contents($TMP . '/receptor.log', '');
@file_put_contents($TMP . '/motor_llamadas.log', '');

$ok = 0;
$mal = 0;
function chk($nombre, $cond, $detalle = '')
{
    global $ok, $mal;
    if ($cond) {
        $ok++;
        echo "  [OK]   $nombre\n";
    } else {
        $mal++;
        echo "  [FALLA] $nombre" . ($detalle !== '' ? "  -> $detalle" : '') . "\n";
    }
    return (bool) $cond;
}
function api($key, $metodo, $ruta, $cuerpo = null, array $cab = array())
{
    global $base;
    $c = curl_init($base . $ruta);
    $h = array('Authorization: Bearer ' . $key, 'Accept: application/json');
    if ($cuerpo !== null) {
        $h[] = 'Content-Type: application/json';
    }
    $rc = array();
    curl_setopt_array($c, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => array_merge($h, $cab),
        CURLOPT_HEADERFUNCTION => function ($ch, $l) use (&$rc) {
            if (strpos($l, ':') !== false) {
                list($k, $v) = explode(':', $l, 2);
                $rc[strtolower(trim($k))] = trim($v);
            }
            return strlen($l);
        }));
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, is_string($cuerpo) ? $cuerpo : json_encode($cuerpo));
    }
    $r = curl_exec($c);
    $st = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return array('http' => $st, 'j' => json_decode((string) $r, true), 'cab' => $rc, 'raw' => (string) $r);
}
function admin($args)
{
    return (string) shell_exec('php bin/admin.php ' . $args . ' 2>&1');
}
function esperarMinuto($margen = 3)
{
    $s = (int) date('s');
    if ($s > 60 - $margen - 1 || $s < 1) {
        // casi en el limite: esperar al siguiente minuto completo
    }
    $falta = 60 - $s + 1;
    sleep($falta);
}
function inicioMinuto($necesito = 40)
{ // si quedan pocos segundos en el minuto, se espera al siguiente (para que los contadores no se partan en dos ventanas)
    if ((int) date('s') > 60 - $necesito) {
        sleep(61 - (int) date('s'));
    }
}
function worker()
{
    return (string) shell_exec('php bin/worker.php --una-vez 2>&1');
}
function recibidos()
{
    global $TMP;
    $o = array();
    foreach (@file($TMP . '/receptor.log') ?: array() as $l) {
        $j = json_decode($l, true);
        if ($j) {
            $o[] = $j;
        }
    }
    return $o;
}
function tiposRecibidos()
{
    return array_map(function ($x) {
        return json_decode($x['cuerpo'], true)['tipo'];
    }, recibidos());
}
function motor($modo)
{
    global $TMP;
    file_put_contents($TMP . '/fake_state.json', json_encode(array('modo' => $modo)));
}
function llamadasMotor()
{
    global $TMP;
    return count(@file($TMP . '/motor_llamadas.log') ?: array());
}
function boleta($serie, $numero = null)
{
    $v = array('tipo_documento_codigo' => '03', 'serie' => $serie, 'fecha_emision' => date('Y-m-d'), 'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 84.75, 'total_igv' => 15.25);
    if ($numero !== null) {
        $v['numero'] = $numero;
    }
    return array('cliente' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ'), 'venta' => $v,
        'items' => array(array('producto' => 'P', 'codigo_sunat' => '50202201', 'cantidad' => 1, 'codigo_unidad' => 'NIU', 'precio_base' => 84.75, 'tipo_igv_codigo' => '10')));
}
function guia($serie)
{
    return array('destinatario' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ'),
        'guia' => array('serie' => $serie, 'fecha_emision' => date('Y-m-d'), 'hora_emision' => '07:00:00', 'fecha_inicio_traslado' => date('Y-m-d'), 'fecha_entrega_bienes_transportista' => date('Y-m-d'), 'motivo_traslado_codigo' => '01', 'peso_total' => 5,
            'unidad_peso' => 'KGM', 'cantidad_bultos' => 1, 'direccion_partida' => 'AV. LIMA 123', 'ubigeo_partida' => '150101', 'direccion_llegada' => 'AV. X 456', 'ubigeo_llegada' => '150101', 'transporte_publico' => false),
        'vehiculos' => array(array('placa' => 'ABC123', 'principal' => true)), 'conductores' => array(array('tipo_documento' => '1', 'numero_documento' => '12345678', 'nombres' => 'J', 'apellidos' => 'P', 'licencia' => 'Q1', 'principal' => true)),
        'items' => array(array('codigo_producto' => 'c1', 'producto' => 'P', 'codigo_sunat' => '43211503', 'cantidad' => 1, 'codigo_unidad' => 'NIU')));
}
function azar()
{
    $c = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    return $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)];
}
function llave($salida)
{
    return preg_match('/sk_(test|live)_[a-f0-9]{40}/', $salida, $m) ? $m[0] : null;
}
function usoLive($key)
{
    $r = api($key, 'GET', '/uso');
    return $r['j']['data'];
}
function plan($nombre, $cuota, $sunat, $general, $wh = 3)
{
    admin('plan:crear --nombre=' . $nombre . ' --cuota=' . $cuota . ' --sunat-min=' . $sunat . ' --general-min=' . $general . ' --webhooks=' . $wh);
    admin('empresa:plan --ruc=' . $GLOBALS['RUC'] . ' --plan=' . $nombre);
}

echo "Prueba de limites y cuotas (empresa de laboratorio $RUC, motor falso)\n";
echo "\n== 0) Preparacion\n";
echo admin('empresa:crear --ruc=' . $RUC . ' --razon="EMPRESA DE LABORATORIO SAC" --nombre="LABORATORIO" --direccion="AV. PRUEBA 1" --ubigeo=150101 --dep=LIMA --prov=LIMA --dist=LIMA --sol-user=MODDATOS --sol-pass=moddatos') ? '' : '';
$kt = llave(admin('key:crear --ruc=' . $RUC . ' --entorno=test --nombre=lab-test-1'));
$kt2 = llave(admin('key:crear --ruc=' . $RUC . ' --entorno=test --nombre=lab-test-2'));
$kl = llave(admin('key:crear --ruc=' . $RUC . ' --entorno=live --nombre=lab-live'));
// certificado de laboratorio para la llave live (autofirmado, con el RUC)
$cfg = array('private_key_bits' => 2048);
foreach (array(getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf') as $c) {
    if ($c && is_file($c)) {
        $cfg['config'] = $c;
        break;
    }
}
$pk = openssl_pkey_new($cfg);
$csr = openssl_csr_new(array('countryName' => 'PE', 'organizationName' => 'LABORATORIO', 'organizationalUnitName' => $RUC, 'commonName' => 'LABORATORIO ' . $RUC), $pk, $cfg);
$x509 = openssl_csr_sign($csr, null, $pk, 30, $cfg);
openssl_x509_export($x509, $certPem);
openssl_pkey_export($pk, $keyPem, null, $cfg);
file_put_contents($TMP . '/lab_cert.pem', $certPem);
file_put_contents($TMP . '/lab_key.pem', $keyPem);
$sal = admin('cert:subir --ruc=' . $RUC . ' --entorno=live --cert=' . escapeshellarg($TMP . '/lab_cert.pem') . ' --key=' . escapeshellarg($TMP . '/lab_key.pem'));
chk('empresa, llaves test/live y certificado de laboratorio creados', $kt && $kt2 && $kl && strpos($sal, 'guardado') !== false, $sal);
if (!($kt && $kt2 && $kl)) {
    die("No se pudo preparar el laboratorio\n");
}
// SEGURIDAD: confirmar que el motor es el FALSO antes de usar la llave live
$antes = llamadasMotor();
$p = 'tmp_lim_' . mt_rand(1000, 9999);
plan($p, 'ilimitada', 'ilimitado', 'ilimitado');
$r = api($kt, 'POST', '/comprobantes', boleta('B' . azar(), '1'), array('Idempotency-Key: seg-' . mt_rand()));
if (llamadasMotor() <= $antes) {
    die("ABORTADO: la API no esta usando el motor falso (API_INTERNAL_URL). No se continua para no tocar SUNAT.\n");
}
chk('seguridad: la API esta usando el MOTOR FALSO (nada va a SUNAT)', true);

// ============================================================================================================
echo "\n== 1) Limite de velocidad por minuto (plan: 5 peticiones y 3 emisiones por minuto)\n";
$pl = 'tmp_lim_v' . mt_rand(100, 999);
plan($pl, 'ilimitada', 3, 5);
inicioMinuto(45);
$rem = array();
for ($i = 1; $i <= 5; $i++) {
    $r = api($kt, 'GET', '/me');
    $rem[] = isset($r['cab']['x-ratelimit-remaining']) ? (int) $r['cab']['x-ratelimit-remaining'] : -1;
    if ($i === 1) {
        chk('cada respuesta trae X-RateLimit-Limit (5) y X-RateLimit-Reset', $r['http'] === 200 && $r['cab']['x-ratelimit-limit'] === '5' && (int) $r['cab']['x-ratelimit-reset'] >= 1 && (int) $r['cab']['x-ratelimit-reset'] <= 60);
    }
}
chk('X-RateLimit-Remaining baja 4, 3, 2, 1, 0', $rem === array(4, 3, 2, 1, 0), json_encode($rem));
$r = api($kt, 'GET', '/me');
chk('la 6a peticion del minuto -> 429 limite_de_peticiones', $r['http'] === 429 && $r['j']['error']['code'] === 'limite_de_peticiones', $r['raw']);
chk('con Retry-After (1 a 60 s) y los datos del limite en "details"', isset($r['cab']['retry-after']) && (int) $r['cab']['retry-after'] >= 1 && (int) $r['cab']['retry-after'] <= 60 && $r['j']['error']['details']['limite_por_minuto'] === 5, json_encode($r['cab']));
chk('el mensaje dice cuanto esperar y el nombre del plan', strpos($r['j']['error']['message'], $pl) !== false && strpos($r['j']['error']['message'], 'segundos') !== false, $r['j']['error']['message']);
$r = api($kt, 'POST', '/comprobantes', '{}');
chk('las EMISIONES tienen su propio contador: con "general" agotado, emitir sigue permitido (422 por cuerpo vacio, no 429)', $r['http'] === 422 || $r['http'] === 400, 'HTTP ' . $r['http']);
$h = array();
for ($i = 2; $i <= 3; $i++) {
    $h[] = api($kt, 'POST', '/comprobantes', '{}')['http'];
}
$r = api($kt, 'POST', '/comprobantes', '{}');
chk('la 4a emision del minuto (limite 3) -> 429', $r['http'] === 429 && $r['j']['error']['details']['clase'] === 'sunat', 'HTTP ' . $r['http'] . ' previas ' . json_encode($h));
$r = api($kt, 'POST', '/series', array('tipo' => '03', 'serie' => 'B' . azar()));
chk('crear series cuenta como "general" (ya agotado) -> 429', $r['http'] === 429);
$otra = api($kl, 'GET', '/me');
chk('otra API key de la misma empresa (la live) tiene SU PROPIO contador', $otra['http'] === 200 && $otra['cab']['x-ratelimit-remaining'] === '4', 'HTTP ' . $otra['http']);
echo "   (esperando el siguiente minuto para comprobar que el contador se reinicia...)\n";
sleep(61 - (int) date('s'));
$r = api($kt, 'GET', '/me');
chk('al empezar el minuto siguiente el limite se reinicia', $r['http'] === 200 && $r['cab']['x-ratelimit-remaining'] === '4', 'HTTP ' . $r['http']);

echo "\n== 2) Tope GLOBAL de emisiones (protege a SUNAT y a los demas clientes)\n";
ponerEnv("LIMITE_GLOBAL_SUNAT_MIN=2\n");
plan($pl, 'ilimitada', 100, 100);
inicioMinuto(25);
$a1 = api($kt, 'POST', '/comprobantes', '{}')['http'];
$a2 = api($kt, 'POST', '/comprobantes', '{}')['http'];
$b = api($kt2, 'POST', '/comprobantes', '{}');
chk('con tope global de 2/min: tras 2 emisiones, la de OTRA llave -> 429 servicio_saturado', $a1 !== 429 && $a2 !== 429 && $b['http'] === 429 && $b['j']['error']['code'] === 'servicio_saturado' && isset($b['cab']['retry-after']), "$a1 $a2 " . $b['http'] . ' ' . $b['raw']);
$g = api($kt2, 'GET', '/me');
chk('las consultas (no emisiones) NO se afectan por el tope global', $g['http'] === 200);
ponerEnv('');
echo "   (esperando el siguiente minuto...)\n";
sleep(61 - (int) date('s'));

echo "\n== 3) Sin plan (o plan sin limites): sin restricciones\n";
admin('empresa:plan --ruc=' . $RUC . ' --plan=ninguno');
$r = api($kt, 'GET', '/me');
chk('empresa sin plan: no hay cabeceras de limite', $r['http'] === 200 && !isset($r['cab']['x-ratelimit-limit']));
$todo = true;
for ($i = 0; $i < 25; $i++) {
    if (api($kt, 'GET', '/me')['http'] !== 200) {
        $todo = false;
    }
}
chk('25 peticiones seguidas sin ningun 429', $todo);
$u = usoLive($kt);
chk('GET /uso: plan "sin_plan", cuota y limites en null', $u['plan']['nombre'] === 'sin_plan' && $u['plan']['cuota_mensual'] === null && $u['documentos_restantes'] === null);

// ============================================================================================================
echo "\n== 4) Cuota mensual: plan de 5 documentos (llave live + motor falso)\n";
$pc = 'tmp_lim_c' . mt_rand(100, 999);
plan($pc, 5, 10000, 10000, 2);
motor('ok');
@file_put_contents($TMP . '/receptor.log', '');
$w = api($kl, 'POST', '/webhooks', array('url' => 'http://127.0.0.1:8099/ok', 'eventos' => array('cuota.advertencia', 'cuota.agotada')));
chk('webhook (live) suscrito a los avisos de cuota', $w['http'] === 201);
$serieL = 'B' . azar();
api($kl, 'POST', '/series', array('tipo' => '03', 'serie' => $serieL, 'modo' => 'api'));
$serieT = 'B' . azar();
api($kt, 'POST', '/series', array('tipo' => '03', 'serie' => $serieT, 'modo' => 'api'));
for ($i = 0; $i < 3; $i++) {
    api($kt, 'POST', '/comprobantes', boleta($serieT), array('Idempotency-Key: t-' . mt_rand()));
}
$u = usoLive($kl);
chk('3 documentos con llave TEST (pruebas): NO consumen cuota', $u['documentos_usados'] === 0, json_encode($u));
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: l1-' . mt_rand()));
$id1 = $r['j']['data']['id'];
$k1 = 'l1-fijo-' . mt_rand();
chk('1er documento live aceptado', $r['http'] === 201 && $r['j']['data']['estado'] === 'aceptado' && $r['j']['data']['entorno'] === 'produccion', $r['raw']);
chk('la respuesta trae X-Quota-Limit 5 y X-Quota-Remaining 4', $r['cab']['x-quota-limit'] === '5' && $r['cab']['x-quota-remaining'] === '4', json_encode($r['cab']));
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: ' . $k1));
$r2 = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: ' . $k1));
$u = usoLive($kl);
chk('2 documentos distintos + 1 reintento con la misma Idempotency-Key = consume solo 2 (el reintento NO cobra otra vez)', $u['documentos_usados'] === 2 && $r2['http'] === 200, json_encode($u['documentos_usados']) . ' ' . $r2['http']);
motor('rechazo');
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: lr-' . mt_rand()));
motor('ok');
chk('SUNAT rechaza un documento: NO consume cuota', $r['j']['data']['estado'] === 'rechazado' && usoLive($kl)['documentos_usados'] === 2);
$rc = api($kl, 'POST', '/resumenes', array('resumen' => array('numero' => date('Ymd'), 'fecha_documentos' => date('Y-m-d'), 'fecha_resumen' => date('Y-m-d')),
    'comprobantes' => array(array('tipo_documento' => '03', 'serie' => $serieL, 'numero' => '1', 'cliente_numero_documento' => '12345678', 'cliente_tipo_documento' => '1', 'total_a_pagar' => 100, 'status' => '1'))), array('Idempotency-Key: rc-' . mt_rand()));
chk('un resumen diario NO consume cuota (no es un documento emitido)', $rc['http'] === 201 && usoLive($kl)['documentos_usados'] === 2, 'HTTP ' . $rc['http'] . ' usados ' . usoLive($kl)['documentos_usados']);
$g = api($kl, 'GET', '/documentos?limit=3');
chk('las consultas son gratis', $g['http'] === 200 && usoLive($kl)['documentos_usados'] === 2);
worker();
chk('aun no hay avisos de cuota (40 % usado)', tiposRecibidos() === array());
api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: l3-' . mt_rand()));
api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: l4-' . mt_rand()));
$u = usoLive($kl);
chk('4 documentos usados = 80 % (restan 1)', $u['documentos_usados'] === 4 && $u['documentos_restantes'] === 1 && $u['porcentaje'] == 80, json_encode($u));
worker();
$rec = recibidos();
chk('al 80 %: el webhook recibe "cuota.advertencia" (una sola vez)', tiposRecibidos() === array('cuota.advertencia'), json_encode(tiposRecibidos()));
if ($rec) {
    $d = json_decode($rec[0]['cuerpo'], true)['data'];
    chk('...con plan, usados, restantes y fecha de reinicio', $d['plan'] === $pc && $d['usados'] === 4 && $d['restantes'] === 1 && $d['cuota_mensual'] === 5 && !empty($d['reinicia']), json_encode($d));
}
api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: l5-' . mt_rand()));
worker();
chk('al 100 %: "cuota.agotada" (y la advertencia no se repite)', tiposRecibidos() === array('cuota.advertencia', 'cuota.agotada'), json_encode(tiposRecibidos()));
$ult = Db::uno('SELECT ultimo_numero AS u FROM series WHERE serie = ?', array($serieL))['u'];
$nd = (int) Db::uno('SELECT COUNT(*) AS n FROM documentos WHERE serie = ? AND estado = ?', array($serieL, 'aceptado'))['n'];
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: l6-' . mt_rand()));
chk('el 6o documento -> 402 cuota_agotada, con mensaje claro', $r['http'] === 402 && $r['j']['error']['code'] === 'cuota_agotada' && strpos($r['j']['error']['message'], 'Agoto los 5 documentos') !== false && strpos($r['j']['error']['message'], '/') !== false, $r['raw']);
chk('...con datos (plan, cuota, usados, reinicia) y X-Quota-Remaining 0', $r['j']['error']['details']['usados'] === 5 && !empty($r['j']['error']['details']['reinicia']) && $r['cab']['x-quota-remaining'] === '0', json_encode($r['j']['error']['details']));
chk('...sin gastar numero ni crear documento (nada se envio)', (int) Db::uno('SELECT ultimo_numero AS u FROM series WHERE serie = ?', array($serieL))['u'] === (int) $ult && (int) Db::uno('SELECT COUNT(*) AS n FROM documentos WHERE serie = ? AND estado = ?', array($serieL, 'aceptado'))['n'] === $nd);
$rep = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: ' . $k1));
chk('con la cuota agotada, repetir un documento YA emitido (misma clave) sigue funcionando', $rep['http'] === 200 && $rep['j']['data']['estado'] === 'aceptado');
$t = api($kt, 'POST', '/comprobantes', boleta($serieT), array('Idempotency-Key: t-despues-' . mt_rand()));
chk('las PRUEBAS (llave test) siguen funcionando con la cuota live agotada', $t['http'] === 201 && $t['j']['data']['estado'] === 'aceptado');
$cons = api($kl, 'GET', '/documentos?limit=3');
chk('las consultas siguen funcionando con la cuota agotada', $cons['http'] === 200);
$ser = api($kl, 'POST', '/series/reservar', array('tipo' => '03', 'serie' => $serieL));
chk('reservar numeros tambien (no cuenta como documento)', $ser['http'] === 201);

echo "\n== 5) Cambios de plan y de mes\n";
plan($pc, 100, 10000, 10000, 2);
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: amp-' . mt_rand()));
chk('al ampliar el plan (cuota 100) se puede emitir de inmediato', $r['http'] === 201 && usoLive($kl)['documentos_usados'] === 6 && usoLive($kl)['documentos_restantes'] === 94);
plan($pc, 5, 10000, 10000, 2);
Db::ejecutar("UPDATE uso SET periodo = '2000-01' WHERE empresa_id = (SELECT id FROM empresas WHERE ruc = ?)", array($RUC));
$u = usoLive($kl);
chk('nuevo mes: el consumo vuelve a 0 (lo anterior queda en el mes anterior)', $u['documentos_usados'] === 0 && $u['documentos_restantes'] === 5 && $u['periodo'] === date('Y-m'), json_encode($u));
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: mes-' . mt_rand()));
chk('...y se puede emitir otra vez', $r['http'] === 201 && usoLive($kl)['documentos_usados'] === 1);
$pz = 'tmp_lim_z' . mt_rand(100, 999);
plan($pz, 0, 10000, 10000, 2);
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: z-' . mt_rand()));
chk('plan con cuota 0 (ej. "Prueba"): produccion bloqueada con mensaje propio', $r['http'] === 402 && strpos($r['j']['error']['message'], 'no incluye emision en produccion') !== false, $r['raw']);
$t = api($kt, 'POST', '/comprobantes', boleta($serieT), array('Idempotency-Key: tz-' . mt_rand()));
chk('...pero las pruebas (test) funcionan', $t['http'] === 201);
plan($pc, 'ilimitada', 10000, 10000, 2);
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: ili-' . mt_rand()));
chk('cuota ilimitada: sin bloqueo y sin cabeceras X-Quota', $r['http'] === 201 && !isset($r['cab']['x-quota-limit']));

echo "\n== 6) Cuota con guias (ticket) y reintentos automaticos\n";
plan($pc, 50, 10000, 10000, 2);
Db::ejecutar("UPDATE uso SET documentos = 0, aviso80 = 0, aviso100 = 0 WHERE empresa_id = (SELECT id FROM empresas WHERE ruc = ?)", array($RUC));
Db::ejecutar("UPDATE documentos SET cuota = NULL WHERE empresa_id = (SELECT id FROM empresas WHERE ruc = ?)", array($RUC));
$sG = 'T' . azar();
api($kl, 'POST', '/series', array('tipo' => '09', 'serie' => $sG, 'modo' => 'api'));
$base0 = usoLive($kl)['documentos_usados'];
$g1 = api($kl, 'POST', '/guias', guia($sG), array('Idempotency-Key: g1-' . mt_rand()));
chk('guia enviada (queda "pendiente" de ticket): cuenta 1', $g1['j']['data']['estado'] === 'pendiente' && usoLive($kl)['documentos_usados'] === $base0 + 1, json_encode($g1['j']['data']['estado']));
file_put_contents($TMP . '/fake_state.json', json_encode(array('modo' => 'ok', 'ticket' => 'aceptado')));
Db::ejecutar("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE uid = ?", array($g1['j']['data']['id']));
worker();
chk('al aceptarse el ticket NO se cuenta otra vez (sigue +1)', Db::uno('SELECT estado FROM documentos WHERE uid = ?', array($g1['j']['data']['id']))['estado'] === 'aceptado' && usoLive($kl)['documentos_usados'] === $base0 + 1);
file_put_contents($TMP . '/fake_state.json', json_encode(array('modo' => 'ok', 'ticket' => 'en_proceso')));
$g2 = api($kl, 'POST', '/guias', guia($sG), array('Idempotency-Key: g2-' . mt_rand()));
chk('una 2a guia pendiente cuenta (+2 en total)', usoLive($kl)['documentos_usados'] === $base0 + 2);
file_put_contents($TMP . '/fake_state.json', json_encode(array('modo' => 'ok', 'ticket' => 'rechazado')));
Db::ejecutar("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE uid = ?", array($g2['j']['data']['id']));
worker();
chk('si SUNAT rechaza esa guia al resolver el ticket, se DEVUELVE la unidad (vuelve a +1)', Db::uno('SELECT estado FROM documentos WHERE uid = ?', array($g2['j']['data']['id']))['estado'] === 'rechazado' && usoLive($kl)['documentos_usados'] === $base0 + 1, json_encode(usoLive($kl)['documentos_usados']));
motor('html');
$antes = usoLive($kl)['documentos_usados'];
$kr = 'rr-' . mt_rand();
$r = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: ' . $kr));
chk('SUNAT falla (502): no consume cuota', $r['http'] === 502 && usoLive($kl)['documentos_usados'] === $antes);
motor('ok');
Db::ejecutar("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE idem_key = ?", array($kr));
worker();
chk('el worker lo reintenta solo y SUNAT lo acepta: consume exactamente 1', Db::uno('SELECT estado FROM documentos WHERE idem_key = ?', array($kr))['estado'] === 'aceptado' && usoLive($kl)['documentos_usados'] === $antes + 1);
$rep = api($kl, 'POST', '/comprobantes', boleta($serieL), array('Idempotency-Key: ' . $kr));
chk('y repetirlo despues con la misma clave no vuelve a cobrar', $rep['http'] === 200 && usoLive($kl)['documentos_usados'] === $antes + 1);

echo "\n== 7) Administracion por consola y topes de webhooks\n";
$sal = admin('uso:ver --ruc=' . $RUC);
chk('uso:ver muestra la empresa, su plan y el consumo del mes', strpos($sal, $RUC) !== false && strpos($sal, $pc) !== false && strpos($sal, date('Y-m')) !== false, $sal);
plan($pc, 50, 10000, 10000, 1);
$w2 = api($kl, 'POST', '/webhooks', array('url' => 'http://127.0.0.1:8099/ok'));
chk('el plan limita los webhooks (max 1; ya hay 1) -> 409 con el nombre del plan', $w2['http'] === 409 && strpos($w2['j']['error']['message'], $pc) !== false, $w2['raw']);
$sal = admin('plan:listar');
chk('plan:listar muestra los planes y cuantas empresas usan cada uno', strpos($sal, $pc) !== false && strpos($sal, 'Empresas sin plan') !== false);

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
