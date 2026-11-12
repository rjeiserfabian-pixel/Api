<?php
/**
 * Prueba de reintentos automaticos y webhooks (solo linea de comandos).
 *
 *   php docs/probar_reintentos.php --key=sk_test_xxxxx
 *
 * Arranca SOLA un motor falso (puerto 8098: imita a SUNAT, con caidas y rechazos a voluntad) y un receptor de webhooks
 * (puerto 8099), agrega temporalmente a .env  API_INTERNAL_URL  y  WEBHOOKS_PERMITIR_REDES_PRIVADAS=1  y lo RESTAURA al terminar.
 * No envia nada a SUNAT. Rechaza llaves sk_live_. Usa el worker real (bin/worker.php --una-vez).
 * Debe correrse con Apache encendido (la API real atiende las peticiones).
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
$SCR = __DIR__ . '/pruebas/tmp';
if (!is_dir($SCR)) {
    mkdir($SCR, 0755, true);
}
$key = '';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--key=(.*)$/', $a, $m)) {
        $key = $m[1];
    }
}
if (!preg_match('/^sk_test_[a-f0-9]{40}$/', $key)) {
    fwrite(STDERR, "Use una API key de PRUEBA: php docs/probar_reintentos.php --key=sk_test_...\n");
    exit(2);
}
$base = 'http://localhost/API_SUNAT/v1';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $base = rtrim($m[1], '/');
    }
}

// PROTECCION: esta prueba usa el worker REAL con un motor FALSO. El worker procesa TODA la cola de la base de datos, y la
// prueba borra los webhooks de la empresa. Si hay trabajo real pendiente, se niega a correr (para no marcar como aceptado,
// con un motor falso, un documento real, ni borrar webhooks reales).
$pend = (int) Db::uno("SELECT COUNT(*) AS n FROM documentos WHERE (estado = 'error' AND cuerpo IS NOT NULL AND proximo_intento IS NOT NULL) OR (estado = 'pendiente' AND tipo = '09' AND proximo_intento IS NOT NULL)")['n'];
$entr = (int) Db::uno("SELECT COUNT(*) AS n FROM entregas WHERE estado IN ('pendiente', 'enviando')")['n'];
$hooks = (int) Db::uno('SELECT COUNT(*) AS n FROM webhooks')['n'];
if ($pend + $entr > 0 && !in_array('--ignorar-cola', $argv, true)) {
    fwrite(STDERR, "ABORTADO: la cola tiene trabajo real pendiente ($pend documentos por reintentar/consultar, $entr avisos por enviar).\nEsta prueba usaria un motor FALSO sobre ese trabajo. Espere a que el worker lo termine, o use una base de pruebas vacia.\n(Para ignorar esto bajo su responsabilidad: --ignorar-cola)\n");
    exit(3);
}
if ($hooks > 0 && !in_array('--limpiar-webhooks', $argv, true)) {
    fwrite(STDERR, "ABORTADO: ya hay $hooks webhook(s) registrado(s) y la prueba los BORRARIA.\nUse una base de pruebas vacia, o agregue --limpiar-webhooks para autorizar que se borren.\n");
    exit(3);
}
$envReal = file_get_contents('.env');
register_shutdown_function(function () use ($envReal, $RAIZ, &$servidores) {
    file_put_contents($RAIZ . '/.env', $envReal);
    foreach ((array) $servidores as $p) {
        if (is_resource($p)) {
            $st = proc_get_status($p);
            if (stripos(PHP_OS, 'WIN') === 0 && !empty($st['pid'])) {
                @exec('taskkill /F /T /PID ' . (int) $st['pid'] . ' >NUL 2>&1'); // en Windows, proc_terminate no mata al proceso hijo
            }
            proc_terminate($p);
        }
    }
});
// las variables de prueba van SOLO mientras dura la prueba
$envOriginal = preg_replace('/^(API_INTERNAL_URL|WEBHOOKS_PERMITIR_REDES_PRIVADAS)=.*\R?/m', '', $envReal) . "\nAPI_INTERNAL_URL=http://127.0.0.1:8098\nWEBHOOKS_PERMITIR_REDES_PRIVADAS=1\n";
file_put_contents('.env', $envOriginal);
$servidores = array();
foreach (array(8098 => 'motor_falso.php', 8099 => 'receptor.php') as $puerto => $archivo) {
    $sock = @fsockopen('127.0.0.1', $puerto, $en, $es, 1);
    if ($sock) {
        fclose($sock);
        continue; // ya hay uno corriendo
    }
    $servidores[] = proc_open('php -S 127.0.0.1:' . $puerto . ' ' . escapeshellarg(__DIR__ . '/pruebas/' . $archivo), array(0 => array('pipe', 'r'), 1 => array('file', $SCR . '/servidor_' . $puerto . '.log', 'w'), 2 => array('file', $SCR . '/servidor_' . $puerto . '.log', 'a')), $pp);
}
usleep(1500000);
file_put_contents($SCR . '/fake_state.json', '{"modo":"ok"}');

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
function api($metodo, $ruta, $cuerpo = null, array $cab = array())
{
    global $base, $key;
    $c = curl_init($base . $ruta);
    $h = array('Authorization: Bearer ' . $key, 'Accept: application/json');
    if ($cuerpo !== null) {
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt_array($c, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => array_merge($h, $cab)));
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, json_encode($cuerpo));
    }
    $r = curl_exec($c);
    $st = curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return array('http' => $st, 'j' => json_decode((string) $r, true), 'raw' => (string) $r);
}
function motor($modo, array $extra = array())
{
    global $SCR;
    file_put_contents($SCR . '/fake_state.json', json_encode(array_merge(array('modo' => $modo), $extra)));
}
function ticketMotor($t)
{
    global $SCR;
    $e = json_decode(file_get_contents($SCR . '/fake_state.json'), true) ?: array();
    $e['ticket'] = $t;
    file_put_contents($SCR . '/fake_state.json', json_encode($e));
}
function worker()
{
    return (string) shell_exec('php bin/worker.php --una-vez 2>&1');
}
function recibidos()
{
    global $SCR;
    $o = array();
    foreach (@file($SCR . '/receptor.log') ?: array() as $l) {
        $o[] = json_decode($l, true);
    }
    return $o;
}
function limpiarReceptor()
{
    global $SCR;
    @file_put_contents($SCR . '/receptor.log', '');
}
function llamadasMotor($doc)
{
    global $SCR;
    $n = 0;
    foreach (@file($SCR . '/motor_llamadas.log') ?: array() as $l) {
        $j = json_decode($l, true);
        if ($j && $j['doc'] === $doc && in_array($j['script'], array('post.php', 'post_guia.php', 'post_retencion.php'), true)) {
            $n++;
        }
    }
    return $n;
}
function vencer($sql, $params = array())
{
    Db::ejecutar($sql, $params);
}
function azar()
{
    $c = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    return $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)];
}
function boleta($serie, $total = 84.75)
{
    return array('cliente' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ'),
        'venta' => array('tipo_documento_codigo' => '03', 'serie' => $serie, 'fecha_emision' => date('Y-m-d'), 'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => $total, 'total_igv' => round($total * 0.18, 2)),
        'items' => array(array('producto' => 'P', 'codigo_sunat' => '50202201', 'cantidad' => 1, 'codigo_unidad' => 'NIU', 'precio_base' => $total, 'tipo_igv_codigo' => '10')));
}
function nuevaSerie()
{
    for ($i = 0; $i < 50; $i++) { // 201 = se creo ahora (serie realmente nueva); 200 = ya existia, se descarta
        $s = 'B' . azar();
        $r = api('POST', '/series', array('tipo' => '03', 'serie' => $s, 'modo' => 'api'));
        if ($r['http'] === 201) {
            return $s;
        }
    }
    die("No se pudo crear una serie nueva para la prueba\n");
}
function emitir($serie, $idem)
{
    return api('POST', '/comprobantes', boleta($serie), array('Idempotency-Key: ' . $idem));
}
function crearWebhook($url, $eventos = null)
{
    $b = array('url' => $url);
    if ($eventos) {
        $b['eventos'] = $eventos;
    }
    $r = api('POST', '/webhooks', $b);
    return array(isset($r['j']['data']['id']) ? $r['j']['data']['id'] : null, isset($r['j']['data']['secreto']) ? $r['j']['data']['secreto'] : null, $r);
}
function limpiarWebhooks()
{
    $r = api('GET', '/webhooks');
    foreach ((isset($r['j']['data']['items']) ? $r['j']['data']['items'] : array()) as $w) {
        api('DELETE', '/webhooks/' . $w['id']);
    }
}
function docPorId($id)
{
    return Db::uno('SELECT * FROM documentos WHERE uid = ?', array($id));
}
function firmaValida($rec, $secreto)
{
    $cab = $rec['cab'];
    if (empty($cab['HTTP_X_WEBHOOK_SIGNATURE']) || empty($cab['HTTP_X_WEBHOOK_TIMESTAMP'])) {
        return false;
    }
    if (!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $cab['HTTP_X_WEBHOOK_SIGNATURE'], $m)) {
        return false;
    }
    if ($m[1] !== $cab['HTTP_X_WEBHOOK_TIMESTAMP'] || abs(time() - (int) $m[1]) > 300) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $m[1] . '.' . $rec['cuerpo'], $secreto), $m[2]);
}

$R = 'http://127.0.0.1:8099';
echo "Prueba de reintentos y webhooks (motor falso + receptor)\n";
$r = api('GET', '/me');
if ($r['http'] !== 200) {
    die("No se pudo autenticar\n");
}
limpiarWebhooks();
motor('ok');

// ============================================================================================================
echo "\n== 1) Aviso por webhook cuando un documento es aceptado\n";
limpiarReceptor();
list($wh, $sec) = crearWebhook($R . '/ok');
chk('registrar webhook hacia el receptor local (permitido solo con el permiso de desarrollo)', $wh !== null && strpos($sec, 'whsec_') === 0);
$serie = nuevaSerie();
$r = emitir($serie, 'a1-' . mt_rand());
$doc1 = isset($r['j']['data']['id']) ? $r['j']['data']['id'] : null;
chk('emitir boleta automatica -> aceptada (motor falso)', $r['http'] === 201 && $r['j']['data']['estado'] === 'aceptado', $r['raw']);
chk('el aviso NO se envia dentro de la peticion del cliente (lo hace el worker): receptor aun sin nada', count(recibidos()) === 0);
$ent = api('GET', "/webhooks/$wh/entregas");
chk('queda una entrega pendiente en cola', isset($ent['j']['data']['items'][0]) && $ent['j']['data']['items'][0]['estado'] === 'pendiente' && $ent['j']['data']['items'][0]['evento']['tipo'] === 'documento.aceptado', $ent['raw']);
worker();
$rec = recibidos();
chk('el worker entrega el aviso (1 sola peticion al receptor)', count($rec) === 1);
if (count($rec) === 1) {
    $ev = json_decode($rec[0]['cuerpo'], true);
    chk('firma HMAC-SHA256 valida con el secreto del webhook', firmaValida($rec[0], $sec));
    chk('firma INVALIDA si se usa otro secreto (no se puede falsificar)', !firmaValida($rec[0], 'whsec_otro'));
    chk('cabeceras de evento, entrega y marca de tiempo presentes', !empty($rec[0]['cab']['HTTP_X_WEBHOOK_EVENT']) && !empty($rec[0]['cab']['HTTP_X_WEBHOOK_ID']) && !empty($rec[0]['cab']['HTTP_X_WEBHOOK_DELIVERY']));
    chk('contenido: tipo, entorno y el documento (id, estado, enlaces)', $ev['tipo'] === 'documento.aceptado' && $ev['entorno'] === 'prueba' && $ev['data']['id'] === $doc1 && $ev['data']['estado'] === 'aceptado' && !empty($ev['data']['archivos']['xml']));
    chk('no incluye datos del cliente final (privacidad)', strpos($rec[0]['cuerpo'], 'JUAN PEREZ') === false && strpos($rec[0]['cuerpo'], '12345678') === false);
}
$ent = api('GET', "/webhooks/$wh/entregas");
chk('la entrega queda "entregado" con HTTP 200', $ent['j']['data']['items'][0]['estado'] === 'entregado' && $ent['j']['data']['items'][0]['ultimo_http'] === 200);
worker();
chk('un segundo ciclo del worker NO reenvia lo ya entregado', count(recibidos()) === 1);
$kRep = 'a1-rep-' . mt_rand();
$rep = emitir($serie, $kRep);
$rep2 = api('POST', '/comprobantes', boleta($serie), array('Idempotency-Key: ' . $kRep));
worker();
chk('una respuesta repetida por Idempotency-Key NO genera otro aviso', count(recibidos()) === 2, 'avisos: ' . count(recibidos())); // 1 (primera) + 1 (a1-repetido original)

echo "\n== 2) Filtro de eventos y rechazos\n";
limpiarWebhooks();
limpiarReceptor();
list($whRech, $secRech) = crearWebhook($R . '/ok', array('documento.rechazado'));
motor('ok');
emitir(nuevaSerie(), 'f1-' . mt_rand());
worker();
chk('webhook suscrito solo a "rechazado" NO recibe un "aceptado"', count(recibidos()) === 0);
motor('rechazo');
$r = emitir(nuevaSerie(), 'f2-' . mt_rand());
chk('SUNAT rechaza el documento (motor falso)', $r['j']['data']['estado'] === 'rechazado');
worker();
$rec = recibidos();
chk('y SI recibe el "documento.rechazado" con el motivo', count($rec) === 1 && json_decode($rec[0]['cuerpo'], true)['tipo'] === 'documento.rechazado' && strpos($rec[0]['cuerpo'], 'Rechazo de prueba') !== false);
motor('ok');
$mas = array();
for ($i = 0; $i < 4; $i++) {
    list($x) = crearWebhook($R . '/ok');
    $mas[] = $x;
}
list($sexto, , $rr) = crearWebhook($R . '/ok');
chk('limite de 5 webhooks por entorno (el sexto -> 409)', $sexto === null && $rr['http'] === 409, $rr['raw']);
$rv = api('POST', '/webhooks', array('url' => $R . '/ok', 'eventos' => array('documento.inventado')));
chk('evento inexistente al registrar -> 422', $rv['http'] === 422);

// ============================================================================================================
echo "\n== 3) Reintentos de ENTREGA cuando el receptor falla (espera creciente)\n";
limpiarWebhooks();
limpiarReceptor();
list($wf, $secf) = crearWebhook($R . '/error500');
$r = emitir(nuevaSerie(), 'e1-' . mt_rand());
$antes = time();
worker();
$ent = api('GET', "/webhooks/$wf/entregas")['j']['data']['items'][0];
chk('1er fallo (HTTP 500 del receptor): sigue "pendiente", intentos=1', $ent['estado'] === 'pendiente' && $ent['intentos'] === 1 && $ent['ultimo_http'] === 500, json_encode($ent));
$espera = strtotime($ent['proximo_intento']) - $antes;
chk('proximo intento en ~1 minuto', $espera >= 55 && $espera <= 70, "espera=$espera s");
worker();
chk('antes de que llegue la hora, el worker NO reintenta (sigue 1 peticion al receptor)', count(recibidos()) === 1);
$esperas = array();
for ($i = 2; $i <= 9; $i++) {
    vencer("UPDATE entregas SET proximo_intento = '2000-01-01 00:00:00' WHERE webhook_id = (SELECT id FROM webhooks WHERE uid = ?) AND estado = 'pendiente'", array($wf));
    $t0 = time();
    worker();
    $e = api('GET', "/webhooks/$wf/entregas")['j']['data']['items'][0];
    if ($e['estado'] === 'pendiente') {
        $esperas[] = strtotime($e['proximo_intento']) - $t0;
    }
}
$e = api('GET', "/webhooks/$wf/entregas")['j']['data']['items'][0];
chk('espera creciente entre intentos: 5 min, 15 min, 1 h, 3 h, 6 h, 12 h, 24 h', count($esperas) === 7 && abs($esperas[0] - 300) < 15 && abs($esperas[1] - 900) < 15 && abs($esperas[2] - 3600) < 15 && abs($esperas[6] - 86400) < 15, json_encode($esperas));
chk('tras 9 intentos fallidos queda "fallido" (se deja de insistir)', $e['estado'] === 'fallido' && $e['intentos'] === 9 && $e['proximo_intento'] === null, json_encode($e));
chk('el receptor recibio exactamente 9 peticiones (ni mas ni menos)', count(recibidos()) === 9, 'recibidas: ' . count(recibidos()));
worker();
chk('un "fallido" ya no se toca en los ciclos siguientes', count(recibidos()) === 9);
$re = api('POST', "/webhooks/$wf/entregas/" . $e['id'] . '/reenviar');
chk('se puede reenviar manualmente una entrega fallida', $re['http'] === 200 && $re['j']['data']['estado'] === 'pendiente', $re['raw']);
worker();
chk('...y se vuelve a intentar', count(recibidos()) === 10);

// flaky: falla 2 veces y luego funciona
limpiarWebhooks();
limpiarReceptor();
@unlink($SCR . '/flaky_t1.txt');
list($wk, $seck) = crearWebhook($R . '/flaky?n=2&id=t1');
emitir(nuevaSerie(), 'k1-' . mt_rand());
for ($i = 0; $i < 4; $i++) {
    vencer("UPDATE entregas SET proximo_intento = '2000-01-01 00:00:00' WHERE webhook_id = (SELECT id FROM webhooks WHERE uid = ?) AND estado = 'pendiente'", array($wk));
    worker();
}
$e = api('GET', "/webhooks/$wk/entregas")['j']['data']['items'][0];
chk('receptor inestable: falla 2 veces y a la 3a se entrega', $e['estado'] === 'entregado' && $e['intentos'] === 3, json_encode($e));
chk('una vez entregado no se vuelve a enviar (3 peticiones en total)', count(recibidos()) === 3, 'recibidas: ' . count(recibidos()));

echo "\n== 4) Receptor lento, redirecciones y respuestas enormes\n";
limpiarWebhooks();
limpiarReceptor();
list($wl) = crearWebhook($R . '/redirect');
emitir(nuevaSerie(), 'r1-' . mt_rand());
worker();
$e = api('GET', "/webhooks/$wl/entregas")['j']['data']['items'][0];
chk('una redireccion (302) NO se sigue y cuenta como fallo', $e['estado'] === 'pendiente' && $e['ultimo_http'] === 302 && count(recibidos()) === 1, json_encode($e));
limpiarWebhooks();
limpiarReceptor();
list($wg) = crearWebhook($R . '/grande');
emitir(nuevaSerie(), 'g1-' . mt_rand());
worker();
$e = api('GET', "/webhooks/$wg/entregas")['j']['data']['items'][0];
chk('una respuesta enorme (200 KB) con HTTP 200 se considera entregada y no agota memoria', $e['estado'] === 'entregado', json_encode($e));
limpiarWebhooks();
limpiarReceptor();
list($wz) = crearWebhook($R . '/lento');
emitir(nuevaSerie(), 'z1-' . mt_rand());
$t0 = microtime(true);
worker();
$dur = microtime(true) - $t0;
$e = api('GET', "/webhooks/$wz/entregas")['j']['data']['items'][0];
chk('receptor que tarda 15 s: se corta a los ~10 s y se reintentara', $dur < 14 && $e['estado'] === 'pendiente' && $e['ultimo_http'] === null && stripos($e['ultimo_error'], 'timed out') !== false, sprintf('%.1fs %s', $dur, json_encode($e)));
sleep(6); // el receptor (un solo hilo) termina de dormir antes de seguir

echo "\n== 5) Probar un webhook y proteccion SSRF tambien al ENVIAR\n";
limpiarWebhooks();
limpiarReceptor();
list($wp, $secp) = crearWebhook($R . '/ok');
$p = api('POST', "/webhooks/$wp/probar");
chk('POST /webhooks/{id}/probar envia un evento de prueba y dice si llego', $p['http'] === 200 && $p['j']['data']['entregado'] === true && $p['j']['data']['http'] === 200, $p['raw']);
$rec = recibidos();
chk('el evento de prueba llega firmado y con tipo "webhook.prueba"', count($rec) === 1 && firmaValida($rec[0], $secp) && json_decode($rec[0]['cuerpo'], true)['tipo'] === 'webhook.prueba');
limpiarWebhooks();
list($wq) = crearWebhook($R . '/error500');
$p = api('POST', "/webhooks/$wq/probar");
chk('probar contra un receptor roto -> entregado=false con el motivo', $p['http'] === 200 && $p['j']['data']['entregado'] === false && $p['j']['data']['http'] === 500, $p['raw']);
limpiarWebhooks();
limpiarReceptor();
list($ws) = crearWebhook($R . '/ok');
emitir(nuevaSerie(), 's1-' . mt_rand());
$sinPermiso = preg_replace('/^WEBHOOKS_PERMITIR_REDES_PRIVADAS=.*$/m', '', file_get_contents('.env'));
file_put_contents('.env', $sinPermiso);
worker();
file_put_contents('.env', $envOriginal);
$e = api('GET', "/webhooks/$ws/entregas")['j']['data']['items'][0];
chk('si el destino pasa a ser privado (DNS cambiado), NO se envia: la proteccion se vuelve a comprobar al enviar', count(recibidos()) === 0 && $e['estado'] === 'pendiente' && stripos($e['ultimo_error'], 'privada') !== false, json_encode($e) . ' recibidas=' . count(recibidos()));

echo "\n== 6) Entregas atascadas (el worker murio a mitad de un envio)\n";
vencer("UPDATE entregas SET estado = 'enviando', reclamado = ?, proximo_intento = NULL WHERE webhook_id = (SELECT id FROM webhooks WHERE uid = ?)", array(date('Y-m-d H:i:s', time() - 900), $ws));
limpiarReceptor();
worker();
$e = api('GET', "/webhooks/$ws/entregas")['j']['data']['items'][0];
chk('una entrega "enviando" abandonada hace 15 min se rescata y se envia', $e['estado'] === 'entregado' && count(recibidos()) === 1, json_encode($e));

// ============================================================================================================
echo "\n== 7) REINTENTOS AUTOMATICOS de envios fallidos (SUNAT/motor con problemas)\n";
limpiarWebhooks();
limpiarReceptor();
list($wr, $secr) = crearWebhook($R . '/ok');
$serie = nuevaSerie();
motor('html'); // SUNAT limitando: devuelve una pagina HTML
$clave = 'ra-' . mt_rand();
$r = emitir($serie, $clave);
chk('SUNAT falla -> el cliente recibe 502 motor_error', $r['http'] === 502 && $r['j']['error']['code'] === 'motor_error', $r['raw']);
$f = Db::uno("SELECT * FROM documentos WHERE idem_key = ?", array($clave));
chk('el documento queda en "error" CON su numero reservado (00000001)', $f && $f['estado'] === 'error' && $f['numero'] === '00000001');
chk('y guardado para reintentar: cuerpo y script presentes, SIN credenciales', $f['script'] === 'post.php' && strpos($f['cuerpo'], 'JUAN PEREZ') !== false && stripos($f['cuerpo'], 'sol_') === false && stripos($f['cuerpo'], 'moddatos') === false && strpos($f['cuerpo'], 'usu_secundario') === false);
$esp = strtotime($f['proximo_intento']) - time();
chk('programado para reintentarse en ~1 minuto (reintentos=1)', (int) $f['reintentos'] === 1 && $esp > 50 && $esp <= 62, "reintentos={$f['reintentos']} espera=$esp");
worker();
chk('antes de la hora, el worker NO lo toca', Db::uno('SELECT estado FROM documentos WHERE id = ?', array($f['id']))['estado'] === 'error');
chk('un fallo transitorio NO dispara aviso todavia (se va a reintentar solo)', count(recibidos()) === 0);
motor('ok'); // SUNAT se recupera
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE id = ?", array($f['id']));
$salida = worker();
$f2 = Db::uno('SELECT * FROM documentos WHERE id = ?', array($f['id']));
chk('el worker lo reintenta SOLO y SUNAT lo acepta, con el MISMO numero', $f2['estado'] === 'aceptado' && $f2['numero'] === '00000001', $f2['estado'] . ' ' . $f2['numero'] . "\n" . $salida);
chk('al quedar final se borra el cuerpo guardado (no se retienen datos del cliente)', $f2['cuerpo'] === null && $f2['proximo_intento'] === null);
worker();
$rec = recibidos();
chk('el cliente recibe el aviso "documento.aceptado" (se entera sin preguntar)', count($rec) === 1 && json_decode($rec[0]['cuerpo'], true)['tipo'] === 'documento.aceptado' && json_decode($rec[0]['cuerpo'], true)['data']['numero'] === '00000001');
$r = emitir($serie, $clave);
chk('si el cliente reintenta despues con la misma clave: recibe el resultado guardado, sin reenviar', $r['http'] === 200 && $r['j']['data']['estado'] === 'aceptado' && isset($r['j']['data']['id']));
$sig = emitir($serie, 'ra2-' . mt_rand());
chk('el siguiente documento es el 00000002 (el numero no se perdio ni se duplico)', $sig['j']['data']['numero'] === '00000002', $sig['raw']);

echo "\n== 8) Agotar los reintentos: aviso de error definitivo\n";
worker();
worker();
limpiarReceptor();
motor('html');
$serie = nuevaSerie();
$clave = 'rb-' . mt_rand();
emitir($serie, $clave);
$esperas = array();
for ($i = 1; $i <= 8; $i++) {
    $f = Db::uno('SELECT * FROM documentos WHERE idem_key = ?', array($clave));
    if ($f['proximo_intento'] === null) {
        break;
    }
    $esperas[] = strtotime($f['proximo_intento']) - time();
    vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE id = ?", array($f['id']));
    worker();
}
$f = Db::uno('SELECT * FROM documentos WHERE idem_key = ?', array($clave));
chk('espera creciente entre reintentos: 1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h', count($esperas) === 7 && abs($esperas[0] - 60) < 15 && abs($esperas[1] - 300) < 15 && abs($esperas[3] - 3600) < 15 && abs($esperas[6] - 43200) < 15, json_encode($esperas));
chk('al agotarse, se borran los datos del cliente guardados (privacidad)', $f['cuerpo'] === null);
chk('agotados (1 intento + 7 reintentos): estado "error" y SIN proximo intento', $f['estado'] === 'error' && $f['proximo_intento'] === null && (int) $f['reintentos'] === 8, json_encode(array($f['estado'], $f['proximo_intento'], $f['reintentos'])));
worker();
$rec = recibidos();
$tipos = array_map(function ($x) { return json_decode($x['cuerpo'], true)['tipo']; }, $rec);
chk('se avisa UNA vez: "documento.error" (necesita atencion)', $tipos === array('documento.error'), json_encode($tipos));
$llam = llamadasMotor($serie . '-00000001');
chk('el motor recibio exactamente 8 intentos (1 + 7), ni uno mas', $llam === 8, "llamadas=$llam");
motor('ok');
$r = emitir($serie, $clave);
chk('el cliente aun puede forzar el envio reintentando con la misma clave -> aceptado, mismo numero', $r['http'] === 201 && $r['j']['data']['estado'] === 'aceptado' && $r['j']['data']['numero'] === '00000001', $r['raw']);
worker();
$tipos = array_map(function ($x) { return json_decode($x['cuerpo'], true)['tipo']; }, recibidos());
chk('y se avisa "documento.aceptado"', end($tipos) === 'documento.aceptado', json_encode($tipos));

echo "\n== 9) Sin Idempotency-Key NO hay reintento automatico (evita documentos duplicados por reintento del cajero)\n";
limpiarReceptor();
motor('html');
$serie9 = 'B' . azar();
api('POST', '/series', array('tipo' => '03', 'serie' => $serie9, 'modo' => 'cliente'));
$b = boleta($serie9);
$b['venta']['numero'] = '777';
$r = api('POST', '/comprobantes', $b);
chk('numero manual SIN clave: SUNAT falla -> 502', $r['http'] === 502);
chk('no se guarda ninguna fila para reintentar', Db::uno('SELECT COUNT(*) AS n FROM documentos WHERE serie = ? AND numero_n = 777', array($serie9))['n'] == 0);
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE estado = 'error'");
motor('ok');
worker();
chk('el worker no envia nada por su cuenta', Db::uno('SELECT COUNT(*) AS n FROM documentos WHERE serie = ? AND numero_n = 777', array($serie9))['n'] == 0);
$r = api('POST', '/comprobantes', $b);
chk('el cliente reenvia el mismo numero: funciona', $r['http'] === 201 && $r['j']['data']['estado'] === 'aceptado');

echo "\n== 10) Cliente y worker a la vez sobre el mismo documento: se envia UNA sola vez\n";
worker();
worker();
limpiarReceptor();
$serie = nuevaSerie();
motor('html');
$clave = 'rc-' . mt_rand();
emitir($serie, $clave);
$f = Db::uno('SELECT * FROM documentos WHERE idem_key = ?', array($clave));
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE id = ?", array($f['id']));
motor('ok');
@unlink($SCR . '/motor_llamadas.log');
$procs = array();
$pipes = array();
for ($i = 0; $i < 3; $i++) {
    $procs[$i] = proc_open('php bin/worker.php --una-vez', array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes[$i]);
}
$mh = curl_multi_init();
$hs = array();
for ($i = 0; $i < 3; $i++) {
    $c = curl_init($base . '/comprobantes');
    curl_setopt_array($c, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_POSTFIELDS => json_encode(boleta($serie)),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $key, 'Idempotency-Key: ' . $clave)));
    curl_multi_add_handle($mh, $c);
    $hs[] = $c;
}
do {
    curl_multi_exec($mh, $a);
    curl_multi_select($mh, 0.1);
} while ($a > 0);
foreach ($procs as $i => $p) {
    stream_get_contents($pipes[$i][1]);
    proc_close($p);
}
$codigos = array();
foreach ($hs as $c) {
    $codigos[] = curl_getinfo($c, CURLINFO_HTTP_CODE);
}
$f = Db::uno('SELECT * FROM documentos WHERE idem_key = ?', array($clave));
chk('3 workers + 3 clientes (misma clave) al mismo tiempo: el documento queda aceptado', $f['estado'] === 'aceptado', $f['estado'] . ' http=' . implode(',', $codigos));
chk('y el motor/SUNAT lo recibio UNA sola vez (nunca duplicado)', llamadasMotor($serie . '-00000001') === 1, 'llamadas=' . llamadasMotor($serie . '-00000001'));
worker();
$tipos = array_map(function ($x) { return json_decode($x['cuerpo'], true)['tipo']; }, recibidos());
$nev = Db::uno("SELECT COUNT(*) AS n FROM eventos WHERE documento_id = ? AND tipo = 'documento.aceptado'", array($f['id']))['n'];
chk('y un solo evento "documento.aceptado" para ese documento (en la base y en el receptor)', $nev == 1 && $tipos === array('documento.aceptado'), "eventos=$nev recibidos=" . json_encode($tipos));

echo "\n== 11) Documento abandonado en \"procesando\" y empresa inactiva\n";
limpiarReceptor();
$serie = nuevaSerie();
motor('html');
$clave = 'rd-' . mt_rand();
emitir($serie, $clave);
$f = Db::uno('SELECT * FROM documentos WHERE idem_key = ?', array($clave));
vencer("UPDATE documentos SET estado = 'procesando', proximo_intento = NULL, actualizado = ? WHERE id = ?", array(date('Y-m-d H:i:s', time() - 1200), $f['id']));
motor('ok');
worker();
$g = Db::uno('SELECT * FROM documentos WHERE id = ?', array($f['id']));
chk('un "procesando" abandonado hace 20 min se rescata y se programa para reintentar', $g['estado'] === 'error' && $g['proximo_intento'] !== null);
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE id = ?", array($f['id']));
worker();
chk('...y se envia', Db::uno('SELECT estado FROM documentos WHERE id = ?', array($f['id']))['estado'] === 'aceptado');
$serie = nuevaSerie();
motor('html');
$clave = 'rd2-' . mt_rand();
emitir($serie, $clave);
$f = Db::uno('SELECT * FROM documentos WHERE idem_key = ?', array($clave));
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE id = ?", array($f['id']));
vencer("UPDATE empresas SET activo = 0 WHERE id = ?", array($f['empresa_id']));
motor('ok');
limpiarReceptor();
worker();
vencer("UPDATE empresas SET activo = 1 WHERE id = ?", array($f['empresa_id']));
$g = Db::uno('SELECT * FROM documentos WHERE id = ?', array($f['id']));
chk('empresa desactivada: NO se reenvia a SUNAT; queda en "error" sin mas reintentos', $g['estado'] === 'error' && $g['proximo_intento'] === null && llamadasMotor($serie . '-00000001') === 1, $g['estado']);

// ============================================================================================================
echo "\n== 12) Guias: consulta automatica del ticket (nunca se marca rechazada una guia en proceso)\n";
limpiarWebhooks();
limpiarReceptor();
list($wgu) = crearWebhook($R . '/ok');
$serieG = 'T' . azar();
api('POST', '/series', array('tipo' => '09', 'serie' => $serieG, 'modo' => 'api'));
motor('ok');
ticketMotor('en_proceso');
$gui = array('destinatario' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ'),
    'guia' => array('serie' => $serieG, 'fecha_emision' => date('Y-m-d'), 'hora_emision' => '07:00:00', 'fecha_inicio_traslado' => date('Y-m-d'), 'fecha_entrega_bienes_transportista' => date('Y-m-d'), 'motivo_traslado_codigo' => '01', 'peso_total' => 5, 'unidad_peso' => 'KGM',
        'cantidad_bultos' => 1, 'direccion_partida' => 'AV. LIMA 123', 'ubigeo_partida' => '150101', 'direccion_llegada' => 'AV. X 456', 'ubigeo_llegada' => '150101', 'transporte_publico' => false),
    'vehiculos' => array(array('placa' => 'ABC123', 'principal' => true)), 'conductores' => array(array('tipo_documento' => '1', 'numero_documento' => '12345678', 'nombres' => 'J', 'apellidos' => 'P', 'licencia' => 'Q1', 'principal' => true)),
    'items' => array(array('codigo_producto' => 'c1', 'producto' => 'P', 'codigo_sunat' => '43211503', 'cantidad' => 1, 'codigo_unidad' => 'NIU')));
$r = api('POST', '/guias', $gui, array('Idempotency-Key: g-' . mt_rand()));
$gid = $r['j']['data']['id'];
chk('la guia se envia y SUNAT devuelve un ticket -> "pendiente"', $r['http'] === 201 && $r['j']['data']['estado'] === 'pendiente' && !empty($r['j']['data']['ticket']), $r['raw']);
$g = docPorId($gid);
chk('queda programada la primera consulta del ticket (~1 min)', $g['proximo_intento'] !== null && (strtotime($g['proximo_intento']) - time()) > 50);
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE uid = ?", array($gid));
worker();
$g = docPorId($gid);
chk('ticket EN PROCESO (codigo 98): sigue "pendiente", NO "rechazada"', $g['estado'] === 'pendiente' && (int) $g['reintentos'] === 1 && $g['proximo_intento'] !== null, json_encode(array($g['estado'], $g['reintentos'])));
$esp = strtotime($g['proximo_intento']) - time();
chk('siguiente consulta en ~2 minutos', $esp > 105 && $esp <= 125, "espera=$esp");
ticketMotor('error_rest');
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE uid = ?", array($gid));
worker();
$g = docPorId($gid);
chk('error tecnico de SUNAT al consultar: sigue "pendiente" (no se marca rechazada)', $g['estado'] === 'pendiente' && (int) $g['reintentos'] === 2);
ticketMotor('oauth');
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE uid = ?", array($gid));
worker();
chk('credenciales GRE invalidas al consultar: sigue "pendiente" (no se marca rechazada)', docPorId($gid)['estado'] === 'pendiente');
ticketMotor('aceptado');
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE uid = ?", array($gid));
worker();
$g = docPorId($gid);
chk('ticket resuelto: la guia pasa a "aceptado" y se limpia la programacion', $g['estado'] === 'aceptado' && $g['proximo_intento'] === null && $g['codigo_sunat'] === '0', json_encode(array($g['estado'], $g['codigo_sunat'])));
worker();
$tipos = array_map(function ($x) { return json_decode($x['cuerpo'], true)['tipo'] . ':' . json_decode($x['cuerpo'], true)['data']['tipo']; }, recibidos());
chk('se avisa "documento.aceptado" de la guia (09), una sola vez', $tipos === array('documento.aceptado:09'), json_encode($tipos));
limpiarReceptor();
$r = api('POST', '/guias', $gui, array('Idempotency-Key: g2-' . mt_rand()));
$gid2 = $r['j']['data']['id'];
ticketMotor('rechazado');
vencer("UPDATE documentos SET proximo_intento = '2000-01-01 00:00:00' WHERE uid = ?", array($gid2));
worker();
chk('ticket rechazado por SUNAT (codigo numerico distinto de 98): la guia pasa a "rechazado" con el motivo', docPorId($gid2)['estado'] === 'rechazado' && docPorId($gid2)['mensaje_sunat'] === 'Rechazo de prueba');
$r = api('POST', '/tickets', array('tipo' => 'guia', 'ticket' => 'fake-ticket-1234567'));
ticketMotor('en_proceso');
$r = api('POST', '/tickets', array('tipo' => 'guia', 'ticket' => 'fake-ticket-1234567'));
chk('POST /tickets (consulta manual) tambien dice "pendiente" para una guia en proceso (antes decia "rechazado")', $r['http'] === 200 && $r['j']['data']['estado'] === 'pendiente', $r['raw']);

// ============================================================================================================
echo "\n== 13) Estado del worker\n";
worker();
$h = json_decode(file_get_contents($base . '/health'), true);
chk('GET /health informa worker "activo"', $h['data']['worker'] === 'activo', json_encode($h['data']));
file_put_contents('storage/worker.heartbeat', (string) (time() - 300));
$h = json_decode(file_get_contents($base . '/health'), true);
chk('y "detenido" si dejo de correr hace mas de 60 s', $h['data']['worker'] === 'detenido');
worker();

limpiarWebhooks();
motor('ok');
echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
