<?php
/**
 * Genera postman/API_SUNAT_v1.postman_collection.json   Uso:  php postman/gen_v1.php
 * Los cuerpos salen de docs/ejemplos.php (los mismos que verifica docs/probar.php).
 * La API key NO va en el archivo: se pone en la variable {{api_key}} dentro de Postman.
 */
require_once __DIR__ . '/../docs/ejemplos.php';

$tok = array('fecha' => '{{fecha}}', 'fecha_venc' => '{{fecha_venc}}', 'fecha_compacta' => '{{fecha_compacta}}', 'numero' => '{{numero}}', 'numero_credito' => '{{numero_credito}}',
    'numero_boleta' => '{{numero_boleta}}', 'numero_nota' => '{{numero_nota}}', 'numero_retencion' => '{{numero_retencion}}', 'numero_guia' => '{{numero_guia}}',
    'correlativo' => '{{correlativo}}', 'serie_auto' => '{{serie_auto}}', 'ticket' => '{{ticket}}', 'ticket_guia' => '{{ticket_guia}}', 'pfx_base64' => '{{pfx_base64}}');
$c = ej_cuerpos($tok);

function t($linea) { return $linea; }

function pedir($nombre, $metodo, $ruta, $cuerpo, array $o = array())
{
    $o = array_merge(array('auth' => true, 'headers' => array(), 'status' => 200, 'ok' => true, 'estado' => null, 'code' => null, 'guardar' => null, 'replay' => false, 'desc' => '', 'status_alt' => null, 'extra' => array()), $o);
    $h = array();
    if ($cuerpo !== null) { $h[] = array('key' => 'Content-Type', 'value' => 'application/json'); }
    foreach ($o['headers'] as $k => $v) { $h[] = array('key' => $k, 'value' => $v); }
    $tests = array('const j = pm.response.json();');
    if ($o['status_alt']) {
        $tests[] = 'pm.test("HTTP ' . implode(' o ', $o['status_alt']) . '", () => pm.expect([' . implode(',', $o['status_alt']) . ']).to.include(pm.response.code));';
    } else {
        $tests[] = 'pm.test("HTTP ' . $o['status'] . '", () => pm.response.to.have.status(' . $o['status'] . '));';
        $tests[] = 'pm.test("ok = ' . ($o['ok'] ? 'true' : 'false') . '", () => pm.expect(j.ok).to.eql(' . ($o['ok'] ? 'true' : 'false') . '));';
    }
    $tests[] = 'pm.test("trae request_id", () => pm.expect(j.request_id).to.be.a("string"));';
    if ($o['estado']) { $tests[] = 'pm.test("estado = ' . $o['estado'] . '", () => pm.expect(j.data.estado).to.eql("' . $o['estado'] . '"));'; }
    if ($o['code']) { $tests[] = 'pm.test("error.code = ' . $o['code'] . '", () => pm.expect(j.error.code).to.eql("' . $o['code'] . '"));'; }
    if ($o['replay']) { $tests[] = 'pm.test("cabecera Idempotent-Replay", () => pm.expect(pm.response.headers.get("Idempotent-Replay")).to.eql("true"));'; }
    if ($o['guardar']) { $tests[] = 'if (j.ok && j.data && j.data.' . $o['guardar'][1] . ') { pm.collectionVariables.set("' . $o['guardar'][0] . '", j.data.' . $o['guardar'][1] . '); }'; }
    foreach ($o['extra'] as $linea) {
        $tests[] = $linea;
    }
    $r = array('name' => $nombre, 'event' => array(array('listen' => 'test', 'script' => array('type' => 'text/javascript', 'exec' => $tests))),
        'request' => array('method' => $metodo, 'header' => $h, 'url' => array('raw' => '{{base_url}}' . $ruta, 'host' => array('{{base_url}}'), 'path' => array_values(array_filter(explode('/', explode('?', $ruta)[0])))),
            'description' => $o['desc']));
    if (strpos($ruta, '?') !== false) {
        $r['request']['url']['query'] = array();
        parse_str(explode('?', $ruta)[1], $q);
        foreach ($q as $k => $v) { $r['request']['url']['query'][] = array('key' => $k, 'value' => $v); }
    }
    if (!$o['auth']) { $r['request']['auth'] = array('type' => 'noauth'); }
    if ($cuerpo !== null) {
        $r['request']['body'] = array('mode' => 'raw', 'raw' => is_string($cuerpo) ? $cuerpo : json_encode($cuerpo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 'options' => array('raw' => array('language' => 'json')));
    }
    return $r;
}

$idem = array('Idempotency-Key' => '{{idem}}');
$carpetas = array(
    array('name' => '1. Sistema', 'item' => array(
        pedir('00 Iniciar tanda de numeros (ejecutar primero)', 'GET', '/health', null, array('auth' => false, 'desc' => 'Genera fecha, numeros y correlativos nuevos para esta tanda. Ejecute esta peticion al empezar para no repetir numeros de comprobantes.')),
        pedir('GET /me', 'GET', '/me', null, array('desc' => 'Empresa, entorno (debe decir "prueba" con llave sk_test) y certificado.')),
        pedir('GET /uso (plan, cuota y limites)', 'GET', '/uso', null, array('extra' => array('pm.test("informa el plan y el consumo del mes", () => pm.expect(j.data.documentos_usados).to.be.a("number"));'), 'desc' => 'Su plan, documentos usados este mes (llave live), restantes, fecha de reinicio y limites por minuto. Consultarlo es gratis.')),
        pedir('GET /health (sin autenticacion)', 'GET', '/health', null, array('auth' => false)),
    )),
    array('name' => '2. Comprobantes', 'item' => array(
        pedir('Factura (con Idempotency-Key)', 'POST', '/comprobantes', $c['factura'], array('status' => 201, 'estado' => 'aceptado', 'headers' => $idem, 'guardar' => array('doc_id', 'id'), 'desc' => 'Factura al contado. La Idempotency-Key permite reintentar sin duplicar.')),
        pedir('Factura - repetir la MISMA peticion (replay)', 'POST', '/comprobantes', $c['factura'], array('status' => 200, 'estado' => 'aceptado', 'headers' => $idem, 'replay' => true, 'desc' => 'Mismo cuerpo y misma Idempotency-Key: devuelve el resultado guardado sin volver a enviar a SUNAT.')),
        pedir('Factura - mismo documento sin llave (409)', 'POST', '/comprobantes', $c['factura'], array('status' => 409, 'ok' => false, 'code' => 'documento_duplicado')),
        pedir('Factura al credito con cuotas', 'POST', '/comprobantes', $c['factura_credito'], array('status' => 201, 'estado' => 'aceptado')),
        pedir('Boleta a DNI', 'POST', '/comprobantes', $c['boleta'], array('status' => 201, 'estado' => 'aceptado')),
        pedir('Nota de credito', 'POST', '/comprobantes', $c['nota_credito'], array('status' => 201, 'estado' => 'aceptado', 'desc' => 'Modifica la factura emitida arriba (relacionado_numero).')),
        pedir('Nota de debito', 'POST', '/comprobantes', $c['nota_debito'], array('status' => 201, 'estado' => 'aceptado')),
        pedir('Factura con ?incluir=archivos (XML y CDR en base64)', 'POST', '/comprobantes?incluir=archivos', array_merge($c['factura'], array('venta' => array_merge($c['factura']['venta'], array('numero' => '{{numero_nota}}', 'serie' => 'F002')))), array('status' => 201, 'estado' => 'aceptado', 'desc' => 'Usa la serie F002 para no chocar con la factura anterior.')),
    )),
    array('name' => '2b. Series y numeración', 'item' => array(
        pedir('Registrar una serie en modo api (la API numera)', 'POST', '/series', $c['serie_api'], array('status_alt' => array(200, 201), 'desc' => 'Crea una serie con nombre aleatorio para esta tanda. Con modo "api" la API asigna los numeros; con "cliente" su sistema comercial numera y la API aprende el ultimo.')),
        pedir('Listar series y siguiente número', 'GET', '/series', null, array('desc' => 'Muestra ultimo_numero, siguiente_numero y reservas_abiertas de cada serie de este entorno.')),
        pedir('Boleta con número automático', 'POST', '/comprobantes', $c['boleta_auto'], array('status' => 201, 'estado' => 'aceptado', 'headers' => array('Idempotency-Key' => '{{idem_auto}}'),
            'extra' => array('pm.test("numero asignado por la API", () => pm.expect(j.data.numero).to.be.a("string"));'), 'desc' => 'Sin venta.numero: la API asigna el siguiente. Exige Idempotency-Key.')),
        pedir('Repetir la misma boleta (reintento): mismo número', 'POST', '/comprobantes', $c['boleta_auto'], array('status' => 200, 'estado' => 'aceptado', 'headers' => array('Idempotency-Key' => '{{idem_auto}}'), 'replay' => true,
            'desc' => 'Mismo cuerpo y misma Idempotency-Key: devuelve el mismo documento y numero, sin consumir otro.')),
        pedir('Reservar 2 números (para enviarlos después)', 'POST', '/series/reservar', $c['reservar'], array('status' => 201,
            'extra' => array('pm.test("2 numeros reservados", () => pm.expect(j.data.numeros.length).to.eql(2));', 'pm.collectionVariables.set("reserva_numero", j.data.numeros[0]);'),
            'desc' => 'Escenario: su sistema comercial entrega la boleta ahora (con este numero) y la envia a SUNAT despues. El numero reservado se guarda en {{reserva_numero}}.')),
        pedir('Consultar el número reservado (aún no enviado)', 'GET', '/comprobantes/03/{{serie_auto}}/{{reserva_numero}}', null, array('status' => 200, 'estado' => 'reservado')),
        pedir('Enviar a SUNAT la boleta con el número reservado', 'POST', '/comprobantes', array_merge($c['boleta_auto'], array('venta' => array_merge($c['boleta_auto']['venta'], array('numero' => '{{reserva_numero}}')))),
            array('status' => 201, 'estado' => 'aceptado', 'desc' => 'Mismo numero que se reservo: se completa esa reserva (no se crea otro documento).')),
        pedir('Consultar por serie y número (ya aceptada)', 'GET', '/comprobantes/03/{{serie_auto}}/{{reserva_numero}}', null, array('status' => 200, 'estado' => 'aceptado')),
        pedir('Número que no se reservó en una serie "api" (409)', 'POST', '/comprobantes', array_merge($c['boleta_auto'], array('venta' => array_merge($c['boleta_auto']['venta'], array('numero' => '99999999')))),
            array('status' => 409, 'ok' => false, 'code' => 'numero_no_reservado')),
    )),
    array('name' => '3. Retencion', 'item' => array(
        pedir('Comprobante de retencion', 'POST', '/retenciones', $c['retencion'], array('status' => 201, 'estado' => 'aceptado')),
    )),
    array('name' => '4. Resumen, baja y reversion', 'item' => array(
        pedir('Resumen diario de boletas', 'POST', '/resumenes', $c['resumen'], array('status' => 201, 'estado' => 'pendiente', 'guardar' => array('ticket', 'ticket'), 'desc' => 'Devuelve un ticket (se guarda en {{ticket}}).')),
        pedir('Baja de una factura', 'POST', '/bajas', $c['baja'], array('status' => 201, 'estado' => 'pendiente', 'desc' => 'Solo facturas y notas. Las boletas se anulan con resumen diario (status 3).')),
        pedir('Reversion', 'POST', '/reversiones', $c['reversion'], array('status' => 201, 'estado' => 'pendiente')),
    )),
    array('name' => '5. Guia de remision', 'item' => array(
        pedir('Guia de remision remitente', 'POST', '/guias', $c['guia'], array('status' => 201, 'estado' => 'simulado', 'desc' => 'Con llave sk_test NO se envia a SUNAT (simulado). Con sk_live se emite de verdad. Con sk_live requiere credenciales GRE de la empresa.')),
    )),
    array('name' => '6. Consultas de ticket', 'item' => array(
        pedir('Ticket de resumen', 'POST', '/tickets', $c['ticket_resumen'], array('status_alt' => array(200, 504), 'desc' => 'Usa el ticket guardado en {{ticket}}. Si SUNAT no responde en 25 s devuelve 504 tiempo_agotado: reintente.')),
        pedir('Ticket de baja', 'POST', '/tickets', $c['ticket_baja'], array('status_alt' => array(200, 504))),
        pedir('Ticket de guia', 'POST', '/tickets', $c['ticket_guia'], array('status_alt' => array(200, 422, 502, 504), 'desc' => 'Pegue en {{ticket_guia}} el ticket de una guia enviada con llave live.')),
    )),
    array('name' => '7. Historial', 'item' => array(
        pedir('Listar documentos', 'GET', '/documentos?limit=10', null),
        pedir('Listar solo facturas aceptadas', 'GET', '/documentos?tipo=01&estado=aceptado&limit=5', null),
        pedir('Detalle del documento ({{doc_id}})', 'GET', '/documentos/{{doc_id}}', null, array('desc' => 'Use el id devuelto al emitir (se guarda solo tras emitir la factura).')),
    )),
    array('name' => '7b. Webhooks (avisos automáticos)', 'item' => array(
        pedir('Registrar un webhook', 'POST', '/webhooks', array('url' => '{{webhook_url}}', 'eventos' => array('documento.aceptado', 'documento.rechazado', 'documento.error')), array('status' => 201, 'guardar' => array('webhook_id', 'id'),
            'extra' => array('pm.test("el secreto se entrega UNA vez", () => pm.expect(j.data.secreto).to.be.a("string"));'),
            'desc' => 'La URL debe ser publica (HTTPS en produccion). La respuesta trae el secreto whsec_... una sola vez: guardelo para verificar la firma de los avisos. Cambie la variable webhook_url por su servidor.')),
        pedir('Listar webhooks (no muestra el secreto)', 'GET', '/webhooks', null, array('status' => 200)),
        pedir('Enviar un evento de prueba', 'POST', '/webhooks/{{webhook_id}}/probar', null, array('status' => 200,
            'extra' => array('pm.test("dice si su servidor respondio 2xx", () => pm.expect(j.data.entregado).to.be.a("boolean"));'),
            'desc' => 'Envia ahora un evento webhook.prueba firmado. Con la URL de ejemplo (example.com) dara entregado=false: es lo esperado; con su servidor debe dar true.')),
        pedir('Historial de entregas', 'GET', '/webhooks/{{webhook_id}}/entregas', null, array('status' => 200)),
        pedir('Destino inseguro (red local) se rechaza', 'POST', '/webhooks', array('url' => 'http://169.254.169.254/latest/meta-data/'), array('status' => 422, 'ok' => false, 'code' => 'webhook_url_invalida',
            'desc' => 'Proteccion SSRF: no se aceptan direcciones privadas, locales ni de metadatos de nube.')),
        pedir('Eliminar el webhook', 'DELETE', '/webhooks/{{webhook_id}}', null, array('status' => 200)),
    )),
    array('name' => '8. Certificado digital', 'item' => array(
        pedir('Estado del certificado', 'GET', '/certificado', null, array('status_alt' => array(200, 404))),
        pedir('Registrar certificado (PFX)', 'POST', '/certificado', $c['certificado_pfx'], array('status_alt' => array(200, 422), 'desc' => 'Ponga el PFX en base64 en {{pfx_base64}} y su contrasena en el cuerpo. Con sk_test registra el de pruebas; con sk_live el de produccion.')),
    )),
    array('name' => '9. Errores esperados', 'item' => array(
        pedir('Sin API key (401)', 'GET', '/me', null, array('auth' => false, 'status' => 401, 'ok' => false, 'code' => 'no_autenticado')),
        pedir('Datos invalidos (422)', 'POST', '/comprobantes', array('venta' => array('tipo_documento_codigo' => '01', 'serie' => 'B001', 'numero' => 'abc')), array('status' => 422, 'ok' => false, 'code' => 'validacion', 'desc' => 'No llega a SUNAT: details lista cada campo con su correccion.')),
        pedir('Ruta inexistente (404)', 'GET', '/nada', null, array('status' => 404, 'ok' => false, 'code' => 'ruta_no_encontrada')),
        pedir('Baja de una boleta (422)', 'POST', '/bajas', array('anulacion' => array('correlativo_diario' => '1', 'fecha_comprobante' => '{{fecha}}', 'tipo_documento_codigo' => '03', 'serie' => 'B001', 'numero' => '1')), array('status' => 422, 'ok' => false, 'code' => 'validacion')),
    )),
);

$iniciar = array(
    "function lima() { return new Date(Date.now() - 5 * 3600 * 1000); }",
    "function iniciar() {",
    "  const d = lima(), iso = d.toISOString(), n = Math.floor(Date.now() / 1000) % 100000000, s = (k, v) => pm.collectionVariables.set(k, String(v));",
    "  s('fecha', iso.slice(0, 10)); s('fecha_compacta', iso.slice(0, 10).replace(/-/g, ''));",
    "  s('fecha_venc', new Date(d.getTime() + 30 * 86400000).toISOString().slice(0, 10));",
    "  s('numero', n); s('numero_credito', n + 1); s('numero_boleta', n + 2); s('numero_nota', n + 3); s('numero_retencion', n + 4); s('numero_guia', n + 5);",
    "  s('correlativo', Math.floor(Date.now() / 1000) % 99999); s('idem', 'postman-' + n); s('lote', n);",
    "  const abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; let sa = 'B'; for (let i = 0; i < 3; i++) { sa += abc[Math.floor(Math.random() * abc.length)]; }",
    "  s('serie_auto', sa); s('idem_auto', 'postman-auto-' + n);",
    "}",
    "if (pm.info.requestName.indexOf('00 ') === 0 || !pm.collectionVariables.get('lote')) { iniciar(); }",
);

$vars = array();
foreach (array('base_url' => 'http://localhost/API_SUNAT/v1', 'api_key' => '', 'lote' => '', 'fecha' => '', 'fecha_venc' => '', 'fecha_compacta' => '', 'numero' => '', 'numero_credito' => '', 'numero_boleta' => '',
    'numero_nota' => '', 'numero_retencion' => '', 'numero_guia' => '', 'correlativo' => '', 'idem' => '', 'webhook_url' => 'https://example.com/webhooks/sunat', 'webhook_id' => '', 'serie_auto' => '', 'idem_auto' => '', 'reserva_numero' => '', 'ticket' => '', 'ticket_guia' => '', 'pfx_base64' => '', 'doc_id' => '') as $k => $v) {
    $vars[] = array('key' => $k, 'value' => $v);
}
$col = array(
    'info' => array('name' => 'API SUNAT v1', 'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
        'description' => "API de facturacion electronica SUNAT.\n\n1. Pegue su API key de PRUEBA (sk_test_...) en la variable de coleccion api_key (no la guarde en el repositorio).\n2. Ajuste base_url si no es local.\n3. Ejecute primero '00 Iniciar tanda de numeros' y luego el resto (o la carpeta completa con Runner).\n\nCon sk_test todo va al beta de SUNAT (sin efectos reales). NO use una llave sk_live con esta coleccion: emitiria documentos reales.\n\nDocumentacion completa: docs/openapi.json"),
    'auth' => array('type' => 'bearer', 'bearer' => array(array('key' => 'token', 'value' => '{{api_key}}', 'type' => 'string'))),
    'event' => array(array('listen' => 'prerequest', 'script' => array('type' => 'text/javascript', 'exec' => $iniciar))),
    'item' => $carpetas, 'variable' => $vars,
);
$json = json_encode($col, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) { fwrite(STDERR, 'JSON invalido: ' . json_last_error_msg() . "\n"); exit(1); }
if (preg_match('/sk_(test|live)_[a-f0-9]{40}/', $json)) { fwrite(STDERR, "Se colo una API key en la coleccion.\n"); exit(1); }
file_put_contents(__DIR__ . '/API_SUNAT_v1.postman_collection.json', $json);
$n = 0;
foreach ($carpetas as $f) { $n += count($f['item']); }
echo "API_SUNAT_v1.postman_collection.json: $n peticiones en " . count($carpetas) . " carpetas.\n";
