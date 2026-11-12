<?php
/**
 * Prueba de humo de la API /v1 (solo linea de comandos).
 * Ejecuta los ejemplos de la documentacion contra una instalacion real y verifica los resultados esperados.
 *
 *   php docs/probar.php --key=sk_test_xxxxx [--base=http://localhost/API_SUNAT/v1]
 *
 * SEGURIDAD: rechaza llaves sk_live_ (produccion) para no emitir documentos reales por accidente.
 * Usar despues de cada cambio: si todo sale OK, la API sigue funcionando.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/ejemplos.php';
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
    fwrite(STDERR, "Use una API key de PRUEBA: php docs/probar.php --key=sk_test_...\n(Las llaves sk_live_ se rechazan a proposito: emitirian documentos reales.)\n");
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

function http($metodo, $ruta, $cuerpo = null, array $cab = array(), $auth = true)
{
    global $base, $key;
    $h = array('Accept: application/json');
    if ($auth) {
        $h[] = 'Authorization: Bearer ' . $key;
    }
    if ($cuerpo !== null) {
        $h[] = 'Content-Type: application/json';
    }
    $ch = curl_init($base . $ruta);
    $respCab = array();
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 150,
        CURLOPT_HTTPHEADER => array_merge($h, $cab),
        CURLOPT_HEADERFUNCTION => function ($c, $linea) use (&$respCab) {
            if (strpos($linea, ':') !== false) {
                list($k, $v) = explode(':', $linea, 2);
                $respCab[strtolower(trim($k))] = trim($v);
            }
            return strlen($linea);
        },
    ));
    if ($cuerpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($cuerpo) ? $cuerpo : json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    }
    $raw = curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('status' => $st, 'cab' => $respCab, 'json' => json_decode((string) $raw, true), 'raw' => (string) $raw);
}

function est($r)
{
    return isset($r['json']['data']['estado']) ? $r['json']['data']['estado'] : (isset($r['json']['error']['code']) ? $r['json']['error']['code'] : substr($r['raw'], 0, 120));
}

// ---- valores reales ----
$n = time() % 100000000;
$v = array(
    'fecha' => date('Y-m-d'), 'fecha_venc' => date('Y-m-d', strtotime('+30 days')), 'fecha_compacta' => date('Ymd'), 'numero' => (string) $n, 'numero_credito' => (string) ($n + 1),
    'numero_boleta' => (string) ($n + 2), 'numero_nota' => (string) ($n + 3), 'numero_retencion' => (string) ($n + 4), 'numero_guia' => (string) ($n + 5),
    'correlativo' => (string) (time() % 99999), 'serie_auto' => 'BZZZ', 'ticket' => '1794437855236', 'ticket_guia' => '00000000-0000-0000-0000-000000000000', 'pfx_base64' => '',
);
$c = ej_cuerpos($v);

echo "Probando $base\n\n== Sistema\n";
$r = http('GET', '/health', null, array(), false);
chequear('GET /health responde 200 sin autenticacion', $r['status'] === 200 && !empty($r['json']['ok']), "HTTP {$r['status']}");
chequear('respuesta trae X-Request-Id', isset($r['cab']['x-request-id']));

$r = http('GET', '/me', null, array(), false);
chequear('sin API key -> 401 no_autenticado', $r['status'] === 401 && est($r) === 'no_autenticado', "HTTP {$r['status']}");
$r = http('GET', '/me');
if (!chequear('GET /me con API key -> 200', $r['status'] === 200, "HTTP {$r['status']} " . $r['raw'])) {
    echo "\nNo se puede continuar sin autenticar.\n";
    exit(1);
}
$me = $r['json']['data'];
if (!chequear('la llave es de entorno prueba', $me['entorno'] === 'prueba', 'entorno=' . $me['entorno'])) {
    exit(2);
}
echo "  empresa: {$me['ruc']} {$me['razon_social']}\n";
$r = http('GET', '/nada');
chequear('ruta inexistente -> 404', $r['status'] === 404);
$r = http('POST', '/me', '{}');
chequear('metodo incorrecto -> 405 con cabecera Allow', $r['status'] === 405 && isset($r['cab']['allow']));

echo "\n== Validacion (no llega a SUNAT)\n";
$r = http('POST', '/comprobantes', '{"venta":{"tipo_documento_codigo":"01","serie":"B001"}}');
chequear('datos incompletos -> 422 validacion con detalle por campo', $r['status'] === 422 && est($r) === 'validacion' && !empty($r['json']['error']['details']));
$r = http('POST', '/comprobantes', '{no es json');
chequear('JSON invalido -> 400', $r['status'] === 400);
$r = http('POST', '/bajas', array('anulacion' => array('correlativo_diario' => '1', 'fecha_comprobante' => $v['fecha'], 'tipo_documento_codigo' => '03', 'serie' => 'B001', 'numero' => '1')));
chequear('baja de una boleta -> 422 (se anulan por resumen)', $r['status'] === 422);
$r = http('POST', '/tickets', array('tipo' => 'guia'));
chequear('consulta de ticket sin ticket -> 422', $r['status'] === 422);

echo "\n== Comprobantes (SUNAT beta)\n";
$idem = 'smoke-' . $n;
$r = http('POST', '/comprobantes', $c['factura'], array('Idempotency-Key: ' . $idem));
chequear('factura -> 201 aceptada por SUNAT', $r['status'] === 201 && est($r) === 'aceptado', 'HTTP ' . $r['status'] . ' ' . est($r));
$idFactura = isset($r['json']['data']['id']) ? $r['json']['data']['id'] : null;
if ($r['status'] === 201) {
    chequear('trae enlaces a xml, cdr y pdf', !empty($r['json']['data']['archivos']['xml']) && !empty($r['json']['data']['archivos']['cdr']));
}
$r = http('POST', '/comprobantes', $c['factura'], array('Idempotency-Key: ' . $idem));
chequear('mismo envio con la misma Idempotency-Key -> 200 Idempotent-Replay', $r['status'] === 200 && isset($r['cab']['idempotent-replay']), 'HTTP ' . $r['status']);
$otro = $c['factura'];
$otro['items'][0]['producto'] = 'OTRO PRODUCTO';
$r = http('POST', '/comprobantes', $otro, array('Idempotency-Key: ' . $idem));
chequear('misma llave con otro cuerpo -> 409 idempotencia_conflicto', $r['status'] === 409 && est($r) === 'idempotencia_conflicto');
$r = http('POST', '/comprobantes', $c['factura']);
chequear('mismo documento sin llave -> 409 documento_duplicado', $r['status'] === 409 && est($r) === 'documento_duplicado');

$r = http('POST', '/comprobantes', $c['factura_credito']);
chequear('factura al credito con cuotas -> aceptada', $r['status'] === 201 && est($r) === 'aceptado', est($r));
$r = http('POST', '/comprobantes', $c['boleta']);
chequear('boleta -> aceptada', $r['status'] === 201 && est($r) === 'aceptado', est($r));
$r = http('POST', '/comprobantes', $c['nota_credito']);
chequear('nota de credito -> aceptada', $r['status'] === 201 && est($r) === 'aceptado', est($r));
$r = http('POST', '/comprobantes', $c['nota_debito']);
chequear('nota de debito -> aceptada', $r['status'] === 201 && est($r) === 'aceptado', est($r));
$r = http('POST', '/retenciones', $c['retencion']);
chequear('comprobante de retencion -> aceptado', $r['status'] === 201 && est($r) === 'aceptado', est($r));

echo "\n== Resumen, baja y reversion (devuelven ticket)\n";
$r = http('POST', '/resumenes', $c['resumen']);
chequear('resumen diario -> 201 pendiente con ticket', $r['status'] === 201 && est($r) === 'pendiente' && !empty($r['json']['data']['ticket']), est($r));
$r = http('POST', '/bajas', $c['baja']);
chequear('baja de factura -> 201 pendiente con ticket', $r['status'] === 201 && est($r) === 'pendiente' && !empty($r['json']['data']['ticket']), est($r));
$r = http('POST', '/reversiones', $c['reversion']);
chequear('reversion -> 201 pendiente con ticket', $r['status'] === 201 && est($r) === 'pendiente' && !empty($r['json']['data']['ticket']), est($r));

echo "\n== Guia de remision\n";
$r = http('POST', '/guias', $c['guia']);
chequear('guia con llave de prueba -> simulada, NO se envia a SUNAT', $r['status'] === 201 && est($r) === 'simulado', est($r));

echo "\n== Historial\n";
$r = http('GET', '/documentos?limit=5');
chequear('GET /documentos -> lista con total', $r['status'] === 200 && isset($r['json']['data']['total']) && $r['json']['data']['total'] >= 1);
$r = http('GET', '/documentos?tipo=01&estado=aceptado&limit=3');
chequear('filtro tipo=01&estado=aceptado', $r['status'] === 200);
if ($idFactura) {
    $r = http('GET', '/documentos/' . $idFactura);
    chequear('GET /documentos/{id} devuelve la factura', $r['status'] === 200 && $r['json']['data']['id'] === $idFactura && $r['json']['data']['estado'] === 'aceptado');
}
$r = http('GET', '/documentos/doc_00000000000000000000');
chequear('id inexistente -> 404', $r['status'] === 404);

echo "\n== Certificado\n";
$r = http('GET', '/certificado');
chequear('GET /certificado -> 200 o 404 (sin certificado propio)', in_array($r['status'], array(200, 404), true), 'HTTP ' . $r['status']);
$r = http('POST', '/certificado', '{"x":1}');
chequear('POST /certificado sin datos -> 422', $r['status'] === 422);

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
