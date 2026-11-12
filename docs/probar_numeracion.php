<?php
/**
 * Prueba de la numeracion de la API /v1 (solo linea de comandos).
 *
 *   php docs/probar_numeracion.php --key=sk_test_xxxxx [--base=http://localhost/API_SUNAT/v1]
 *
 * Usa series con nombre aleatorio (no toca sus series reales) y SUNAT beta. Rechaza llaves sk_live_.
 * Cubre: modo "api" (asignacion automatica atomica), reservas, modo "cliente" (sincronizacion), reintentos
 * sin duplicar, liberacion de numeros rechazados, concurrencia, y correlativos diarios.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
date_default_timezone_set('America/Lima');

$o = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m)) {
        $o[$m[1]] = $m[2];
    }
}
$base = rtrim(isset($o['base']) ? $o['base'] : 'http://localhost/API_SUNAT/v1', '/');
$key = isset($o['key']) ? $o['key'] : (string) getenv('API_KEY');
if (!preg_match('/^sk_test_[a-f0-9]{40}$/', $key)) {
    fwrite(STDERR, "Use una API key de PRUEBA: php docs/probar_numeracion.php --key=sk_test_...\n");
    exit(2);
}

$ok = 0;
$mal = 0;
function chequear($nombre, $cond, $detalle = '')
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

function http($metodo, $ruta, $cuerpo = null, array $cab = array())
{
    global $base, $key;
    $ch = curl_init($base . $ruta);
    $rc = array();
    $h = array('Accept: application/json', 'Authorization: Bearer ' . $key);
    if ($cuerpo !== null) {
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150, CURLOPT_HTTPHEADER => array_merge($h, $cab),
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$rc) {
            if (strpos($l, ':') !== false) {
                list($k, $v) = explode(':', $l, 2);
                $rc[strtolower(trim($k))] = trim($v);
            }
            return strlen($l);
        }));
    if ($cuerpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('status' => $st, 'cab' => $rc, 'json' => json_decode((string) $raw, true), 'raw' => (string) $raw);
}

function d($r, $campo)
{
    return isset($r['json']['data'][$campo]) ? $r['json']['data'][$campo] : null;
}

function cod($r)
{
    return isset($r['json']['error']['code']) ? $r['json']['error']['code'] : substr($r['raw'], 0, 100);
}

function azar($pref)
{
    $c = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    return $pref . $c[mt_rand(0, strlen($c) - 1)] . $c[mt_rand(0, strlen($c) - 1)] . $c[mt_rand(0, strlen($c) - 1)];
}

$hoy = date('Y-m-d');
$venc = date('Y-m-d', strtotime('+30 days'));
function boleta($serie, $numero = null)
{
    global $hoy;
    $v = array('tipo_documento_codigo' => '03', 'serie' => $serie, 'fecha_emision' => $hoy, 'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 84.75, 'total_igv' => 15.25);
    if ($numero !== null) {
        $v['numero'] = $numero;
    }
    return array('cliente' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ'), 'venta' => $v,
        'items' => array(array('producto' => 'PRODUCTO', 'codigo_sunat' => '50202201', 'cantidad' => 1, 'codigo_unidad' => 'NIU', 'precio_base' => 84.75, 'tipo_igv_codigo' => '10')));
}

function factura($serie, $cuotaFecha, $numero = null)
{
    global $hoy;
    $v = array('tipo_documento_codigo' => '01', 'serie' => $serie, 'fecha_emision' => $hoy, 'hora_emision' => '10:00:00', 'fecha_vencimiento' => $cuotaFecha, 'moneda_id' => 1, 'forma_pago_id' => 2, 'total_gravada' => 100, 'total_igv' => 18);
    if ($numero !== null) {
        $v['numero'] = $numero;
    }
    return array('cliente' => array('codigo_tipo_entidad' => '6', 'numero_documento' => '20987654321', 'razon_social_nombres' => 'CLIENTE S.A.C.'), 'venta' => $v,
        'cuotas' => array(array('monto' => 118, 'fecha_cuota' => $cuotaFecha)),
        'items' => array(array('producto' => 'PRODUCTO', 'codigo_sunat' => '50202201', 'cantidad' => 2, 'codigo_unidad' => 'NIU', 'precio_base' => 50, 'tipo_igv_codigo' => '10')));
}

$corrida = substr(md5(uniqid('', true)), 0, 8);
$sB = azar('B');
$sC = azar('B');
$sD = azar('B');
$sF = azar('F');
$sR = azar('R');
$sT = azar('T');

echo "Probando la numeracion en $base  (series de esta corrida: $sB, $sC, $sD, $sF, $sR, $sT)\n";

echo "\n== Registro de series\n";
$r = http('POST', '/series', array('tipo' => '03', 'serie' => $sB, 'modo' => 'api'));
chequear('crear serie en modo api -> 201, ultimo 0, siguiente 00000001', $r['status'] === 201 && d($r, 'ultimo_numero') === 0 && d($r, 'siguiente_numero') === '00000001', "HTTP {$r['status']} " . $r['raw']);
$r = http('POST', '/series', array('tipo' => '03', 'serie' => $sB, 'modo' => 'api'));
chequear('registrarla otra vez -> 200 (no duplica)', $r['status'] === 200);
$r = http('POST', '/series', array('tipo' => '03', 'serie' => 'F123'));
chequear('serie con prefijo equivocado para el tipo -> 422', $r['status'] === 422);
$r = http('POST', '/series', array('tipo' => '03', 'serie' => azar('B'), 'modo' => 'robot'));
chequear('modo invalido -> 422', $r['status'] === 422);
$r = http('POST', '/series', array('tipo' => '03', 'serie' => azar('B'), 'ultimo_numero' => -5));
chequear('ultimo_numero negativo -> 422', $r['status'] === 422);
$r = http('GET', '/series');
$ok1 = false;
foreach ((array) d($r, 'items') as $s) {
    if ($s['serie'] === $sB && $s['modo'] === 'api') {
        $ok1 = true;
    }
}
chequear('GET /series la lista', $r['status'] === 200 && $ok1);

echo "\n== Numeracion automatica (modo api)\n";
$r = http('POST', '/comprobantes', boleta($sB));
chequear('sin numero y sin Idempotency-Key -> 422 idempotency_key_requerida', $r['status'] === 422 && cod($r) === 'idempotency_key_requerida', cod($r));
$r = http('POST', '/comprobantes', boleta(azar('B')), array('Idempotency-Key: n-' . $corrida . '-x'));
chequear('serie no registrada, sin numero -> 422 numero_requerido', $r['status'] === 422 && cod($r) === 'numero_requerido', cod($r));
$nums = array();
for ($i = 1; $i <= 3; $i++) {
    $r = http('POST', '/comprobantes', boleta($sB), array("Idempotency-Key: a-$corrida-$i"));
    $nums[$i] = d($r, 'numero');
    chequear("boleta automatica #$i -> numero " . str_pad($i, 8, '0', STR_PAD_LEFT) . ' y SUNAT la acepta', $r['status'] === 201 && $nums[$i] === str_pad($i, 8, '0', STR_PAD_LEFT) && d($r, 'estado') === 'aceptado', "HTTP {$r['status']} " . d($r, 'numero') . ' ' . d($r, 'estado') . ' ' . cod($r));
}
$r = http('POST', '/comprobantes', boleta($sB), array("Idempotency-Key: a-$corrida-2"));
chequear('reintento con la misma Idempotency-Key -> mismo numero, sin consumir otro', $r['status'] === 200 && isset($r['cab']['idempotent-replay']) && d($r, 'numero') === '00000002', "HTTP {$r['status']} " . d($r, 'numero'));
$r = http('GET', '/series');
$u = null;
foreach ((array) d($r, 'items') as $s) {
    if ($s['serie'] === $sB) {
        $u = $s['ultimo_numero'];
    }
}
chequear('el contador quedo en 3', $u === 3, 'ultimo=' . json_encode($u));

echo "\n== Reservar y enviar despues (el sistema comercial entrega la boleta antes de enviarla a SUNAT)\n";
$r = http('POST', '/series/reservar', array('tipo' => '03', 'serie' => $sB, 'cantidad' => 2));
$res = d($r, 'numeros');
chequear('reservar 2 numeros -> 00000004 y 00000005', $r['status'] === 201 && $res === array('00000004', '00000005'), json_encode($res));
$r = http('GET', "/comprobantes/03/$sB/4");
$idReserva = d($r, 'id');
chequear('consultar el 4 antes de enviarlo -> estado "reservado"', $r['status'] === 200 && d($r, 'estado') === 'reservado', "HTTP {$r['status']} " . $r['raw']);
$r = http('GET', '/series');
$ab = null;
foreach ((array) d($r, 'items') as $s) {
    if ($s['serie'] === $sB) {
        $ab = $s['reservas_abiertas'];
    }
}
chequear('la serie informa 2 reservas abiertas', $ab === 2, json_encode($ab));
$r = http('POST', '/comprobantes', boleta($sB, '00000004'));
chequear('enviar a SUNAT despues con ese numero reservado -> aceptado', $r['status'] === 201 && d($r, 'estado') === 'aceptado' && d($r, 'numero') === '00000004', "HTTP {$r['status']} " . d($r, 'estado') . ' ' . cod($r));
chequear('es el MISMO documento que se reservo (no se crea otro)', d($r, 'id') === $idReserva, d($r, 'id') . ' vs ' . $idReserva);
$r1 = http('GET', "/comprobantes/03/$sB/4");
$r2 = http('GET', "/comprobantes/03/$sB/00000004");
chequear('consultar por serie-numero: "4" y "00000004" dan lo mismo (aceptado)', d($r1, 'id') === $idReserva && d($r2, 'id') === $idReserva && d($r1, 'estado') === 'aceptado');
$r = http('POST', '/comprobantes', boleta($sB, '00000004'));
chequear('enviarlo de nuevo -> 409 documento_duplicado', $r['status'] === 409 && cod($r) === 'documento_duplicado', cod($r));
$r = http('POST', '/comprobantes', boleta($sB, '00000050'));
chequear('numero NO reservado en una serie "api" -> 409 numero_no_reservado', $r['status'] === 409 && cod($r) === 'numero_no_reservado', cod($r));
$r = http('GET', "/documentos?serie=$sB&numero=5");
chequear('filtro ?serie=&numero= encuentra la reserva restante', $r['status'] === 200 && d($r, 'total') === 1 && $r['json']['data']['items'][0]['estado'] === 'reservado');
$r = http('GET', "/comprobantes/03/$sB/99");
chequear('numero que no existe -> 404', $r['status'] === 404);

echo "\n== Concurrencia: 10 peticiones simultaneas reservando a la vez\n";
$mh = curl_multi_init();
$hs = array();
for ($i = 0; $i < 10; $i++) {
    $ch = curl_init($base . '/series/reservar');
    curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_POSTFIELDS => json_encode(array('tipo' => '03', 'serie' => $sB, 'cantidad' => 1)),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $key)));
    curl_multi_add_handle($mh, $ch);
    $hs[] = $ch;
}
do {
    curl_multi_exec($mh, $activo);
    curl_multi_select($mh, 0.2);
} while ($activo > 0);
$todos = array();
$codigos = array();
foreach ($hs as $ch) {
    $j = json_decode(curl_multi_getcontent($ch), true);
    $codigos[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (isset($j['data']['numeros'][0])) {
        $todos[] = (int) $j['data']['numeros'][0];
    }
    curl_multi_remove_handle($mh, $ch);
}
sort($todos);
chequear('las 10 respondieron 201', count(array_filter($codigos, function ($c) { return $c === 201; })) === 10, implode(',', $codigos));
chequear('10 numeros distintos y consecutivos (6 a 15), sin repetir ni saltar', $todos === range(6, 15), implode(',', $todos));

echo "\n== Modo cliente: el sistema comercial numera y la API se sincroniza\n";
$r = http('POST', '/series', array('tipo' => '03', 'serie' => $sC, 'modo' => 'cliente', 'ultimo_numero' => 100));
chequear('serie modo cliente con ultimo_numero 100 -> 201', $r['status'] === 201 && d($r, 'ultimo_numero') === 100, $r['raw']);
$r = http('POST', '/comprobantes', boleta($sC, '150'));
chequear('el sistema comercial envia el 150 (se acepta aunque salte)', $r['status'] === 201 && d($r, 'estado') === 'aceptado', "HTTP {$r['status']} " . d($r, 'estado') . ' ' . cod($r));
$r = http('GET', '/series');
$x = null;
foreach ((array) d($r, 'items') as $s) {
    if ($s['serie'] === $sC) {
        $x = $s;
    }
}
chequear('la API aprendio: ultimo 150, siguiente 00000151', $x && $x['ultimo_numero'] === 150 && $x['siguiente_numero'] === '00000151', json_encode($x));
$r = http('POST', '/comprobantes', boleta($sC, '00000150'));
chequear('"00000150" es el mismo documento que "150" -> 409 duplicado', $r['status'] === 409 && cod($r) === 'documento_duplicado', cod($r));
$r = http('POST', '/series', array('tipo' => '03', 'serie' => $sC, 'ultimo_numero' => 10));
chequear('intentar bajar el contador a 10 no lo baja (sigue en 150)', d($r, 'ultimo_numero') === 150, $r['raw']);
$r = http('POST', '/series/reservar', array('tipo' => '03', 'serie' => $sC));
chequear('reservar en una serie modo cliente -> 409 serie_no_automatica', $r['status'] === 409 && cod($r) === 'serie_no_automatica', cod($r));
$r = http('POST', '/series', array('tipo' => '03', 'serie' => $sC, 'modo' => 'api'));
chequear('pasarla a modo api conserva el contador (150)', d($r, 'modo') === 'api' && d($r, 'ultimo_numero') === 150, $r['raw']);
$r = http('POST', '/comprobantes', boleta($sC), array("Idempotency-Key: c-$corrida-1"));
chequear('la siguiente automatica es la 00000151 (continua donde quedo el sistema comercial)', $r['status'] === 201 && d($r, 'numero') === '00000151' && d($r, 'estado') === 'aceptado', d($r, 'numero') . ' ' . d($r, 'estado') . ' ' . cod($r));

echo "\n== Un rechazo de SUNAT libera el numero automatico\n";
$r = http('POST', '/series', array('tipo' => '01', 'serie' => $sF, 'modo' => 'api'));
$r = http('POST', '/comprobantes', factura($sF, $hoy), array("Idempotency-Key: f-$corrida-1"));
chequear('factura mal armada (cuota el mismo dia) -> SUNAT la rechaza (3267)', $r['status'] === 201 && d($r, 'estado') === 'rechazado' && d($r, 'numero') === '00000001', d($r, 'estado') . ' ' . d($r, 'numero') . ' ' . cod($r));
$r = http('GET', '/series');
$x = null;
foreach ((array) d($r, 'items') as $s) {
    if ($s['serie'] === $sF) {
        $x = $s;
    }
}
chequear('el contador volvio a 0 (el numero rechazado no se pierde)', $x && $x['ultimo_numero'] === 0, json_encode($x));
$r = http('POST', '/comprobantes', factura($sF, $venc), array("Idempotency-Key: f-$corrida-2"));
$idFactura = d($r, 'id');
chequear('la factura corregida reutiliza el 00000001 y SUNAT la acepta', $r['status'] === 201 && d($r, 'numero') === '00000001' && d($r, 'estado') === 'aceptado', d($r, 'estado') . ' ' . d($r, 'numero') . ' ' . cod($r));
$r = http('GET', "/comprobantes/01/$sF/1");
chequear('consultar F-1 devuelve el documento aceptado (el mas reciente)', d($r, 'estado') === 'aceptado' && d($r, 'id') === $idFactura);

echo "\n== Retencion y guia con numeracion automatica\n";
http('POST', '/series', array('tipo' => '20', 'serie' => $sR, 'modo' => 'api'));
$ret = array('proveedor' => array('ruc' => '20987654321', 'razon_social' => 'PROVEEDOR S.A.C.'),
    'retencion' => array('serie' => $sR, 'fecha_de_emision' => $hoy, 'codigo' => '01', 'porcentaje' => 3, 'nota' => 'Retencion', 'total_retenido' => 35.40, 'total_facturado_menos_retenido' => 1144.60),
    'documento_relacionados' => array(array('relacionado_tipo_documento' => '01', 'relacionado_serie' => 'F001', 'relacionado_numero' => '00000001', 'relacionado_fecha_emision' => $hoy, 'relacionado_monto_total' => 1180.00,
        'pago_numero' => '1', 'pago_monto' => 1180.00, 'pago_fecha' => $hoy, 'retencion_monto' => 35.40, 'retencion_fecha' => $hoy, 'moneda' => '1', 'moneda_tipo_cambio' => 1, 'moneda_fecha_cambio' => $hoy)));
$r = http('POST', '/retenciones', $ret, array("Idempotency-Key: r-$corrida-1"));
chequear('retencion sin numero -> 00000001 aceptada', $r['status'] === 201 && d($r, 'numero') === '00000001' && d($r, 'estado') === 'aceptado', d($r, 'estado') . ' ' . d($r, 'numero') . ' ' . cod($r));
http('POST', '/series', array('tipo' => '09', 'serie' => $sT, 'modo' => 'api'));
$gui = array('destinatario' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ'),
    'guia' => array('serie' => $sT, 'fecha_emision' => $hoy, 'hora_emision' => '07:00:00', 'fecha_entrega_bienes_transportista' => $hoy, 'fecha_inicio_traslado' => $hoy, 'motivo_traslado_codigo' => '01', 'peso_total' => 5, 'unidad_peso' => 'KGM',
        'cantidad_bultos' => 1, 'direccion_partida' => 'AV. LIMA 123', 'ubigeo_partida' => '150101', 'direccion_llegada' => 'AV. CLIENTE 456', 'ubigeo_llegada' => '150101', 'transporte_publico' => false),
    'vehiculos' => array(array('placa' => 'ABC123', 'principal' => true)),
    'conductores' => array(array('tipo_documento' => '1', 'numero_documento' => '12345678', 'nombres' => 'JUAN', 'apellidos' => 'PEREZ', 'licencia' => 'Q12345678', 'principal' => true)),
    'items' => array(array('codigo_producto' => 'c1', 'producto' => 'PRODUCTO', 'codigo_sunat' => '43211503', 'cantidad' => 1, 'codigo_unidad' => 'NIU')));
$r = http('POST', '/guias', $gui, array("Idempotency-Key: g-$corrida-1"));
chequear('guia sin numero (llave de prueba: simulada, no se envia) -> 00000001', $r['status'] === 201 && d($r, 'numero') === '00000001' && d($r, 'estado') === 'simulado', d($r, 'estado') . ' ' . d($r, 'numero') . ' ' . cod($r));

echo "\n== Correlativos diarios (resumen, baja, reversion): se asignan solos\n";
$rc = array('resumen' => array('numero' => date('Ymd'), 'fecha_documentos' => $hoy, 'fecha_resumen' => $hoy),
    'comprobantes' => array(array('tipo_documento' => '03', 'serie' => $sB, 'numero' => '00000001', 'cliente_numero_documento' => '12345678', 'cliente_tipo_documento' => '1', 'total_a_pagar' => 100, 'total_gravada' => 84.75, 'total_igv' => 15.25, 'total_exonerada' => '', 'total_inafecta' => '', 'total_gratuita' => '', 'status' => '1')));
$r1 = http('POST', '/resumenes', $rc, array("Idempotency-Key: rc-$corrida-1"));
$r2 = http('POST', '/resumenes', $rc, array("Idempotency-Key: rc-$corrida-2"));
$n1 = (int) d($r1, 'numero');
$n2 = (int) d($r2, 'numero');
chequear('dos resumenes sin correlativo -> reciben correlativos consecutivos', $r1['status'] === 201 && $r2['status'] === 201 && $n1 > 0 && $n2 === $n1 + 1, "$n1, $n2 " . cod($r1) . ' ' . cod($r2));
chequear('SUNAT los recibe (estado pendiente, con ticket)', d($r1, 'estado') === 'pendiente' && d($r1, 'ticket') !== null && d($r2, 'estado') === 'pendiente', d($r1, 'estado') . ' ' . d($r2, 'estado'));
$baja = array('anulacion' => array('fecha_comprobante' => $hoy, 'tipo_documento_codigo' => '01', 'serie' => $sF, 'numero' => '00000001'));
$r = http('POST', '/bajas', $baja, array("Idempotency-Key: ra-$corrida-1"));
chequear('baja sin correlativo -> se asigna y SUNAT la recibe', $r['status'] === 201 && d($r, 'estado') === 'pendiente' && (int) d($r, 'numero') > 0, d($r, 'estado') . ' ' . d($r, 'numero') . ' ' . cod($r));
$rev = array('reversion' => array('fecha_resumen' => $hoy, 'fecha_documentos' => $hoy),
    'documento_relacionados' => array(array('tipo_documento_codigo' => '20', 'serie' => $sR, 'numero' => '00000001', 'descripcion' => 'ANULACION POR ERROR')));
$r = http('POST', '/reversiones', $rev, array("Idempotency-Key: rr-$corrida-1"));
chequear('reversion sin correlativo -> se asigna y SUNAT la recibe', $r['status'] === 201 && d($r, 'estado') === 'pendiente', d($r, 'estado') . ' ' . d($r, 'numero') . ' ' . cod($r));

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
