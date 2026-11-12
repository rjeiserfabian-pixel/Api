<?php
/**
 * API /v1 - punto de entrada unico.
 *
 *   GET  /v1/health                    estado del servicio (sin autenticacion)
 *   GET  /v1/me                        empresa y entorno de la API key
 *   GET  /v1/uso                       plan, cuota mensual y limites de velocidad
 *   POST /v1/comprobantes              factura (01), boleta (03), nota de credito (07), nota de debito (08)
 *   GET  /v1/series, POST /v1/series   series y correlativos (numeracion sincronizada con el sistema comercial)
 *   POST /v1/series/reservar           reserva numeros para usarlos antes de enviar a SUNAT
 *   GET  /v1/comprobantes/{tipo}/{serie}/{numero}   consulta por serie y numero
 *   POST /v1/resumenes                 resumen diario de boletas
 *   POST /v1/bajas                     comunicacion de baja
 *   POST /v1/reversiones               reversion
 *   POST /v1/retenciones               comprobante de retencion (20)
 *   POST /v1/guias                     guia de remision remitente (09)
 *   POST /v1/tickets                   consulta de ticket (guia | resumen | baja)
 *   GET  /v1/documentos[?tipo=&estado=&serie=&limit=&offset=]
 *   GET|POST /v1/webhooks, DELETE /v1/webhooks/{id}, POST /v1/webhooks/{id}/probar, GET /v1/webhooks/{id}/entregas, POST .../entregas/{id}/reenviar
 *   GET  /v1/documentos/{id}
 */
require_once __DIR__ . '/../libraries/env.php';
require_once __DIR__ . '/../libraries/errores.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Validador', 'Motor', 'Series', 'Planes', 'Documentos') as $c) {
    require_once __DIR__ . '/src/' . $c . '.php';
}
date_default_timezone_set('America/Lima');
@set_time_limit(130); // la emision puede esperar a SUNAT; el limite por defecto de PHP (30 s) cortaba la respuesta

set_exception_handler(function ($e) {
    if ($e instanceof ApiError) {
        Respuesta::error($e->status, $e->codigo, $e->getMessage(), $e->detalles, $e->cabeceras);
    }
    error_log('[v1] ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    Respuesta::error(500, 'error_interno', 'Error interno del servidor. Cite el request_id al pedir soporte.');
});

// ---- Ruta solicitada (relativa a /v1) ----
$path = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/v1';
$ruta = '/' . trim((string) substr($path, strlen($base)), '/');
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'OPTIONS') {
    http_response_code(204);
    header('Allow: GET, POST, OPTIONS');
    exit;
}

if ($ruta === '/health') {
    $latido = dirname(__DIR__) . '/storage/worker.heartbeat';
    $worker = 'sin_iniciar'; // activo: corrio hace menos de 60 s; detenido: dejo de correr
    if (is_file($latido)) {
        $worker = (time() - (int) @file_get_contents($latido)) <= 60 ? 'activo' : 'detenido';
    }
    Respuesta::ok(array('servicio' => 'api-sunat', 'version' => 'v1', 'hora' => date('c'), 'worker' => $worker));
}

$permitidos = array(
    '/me' => 'GET', '/uso' => 'GET', '/comprobantes' => 'POST', '/resumenes' => 'POST', '/bajas' => 'POST',
    '/reversiones' => 'POST', '/retenciones' => 'POST', '/guias' => 'POST', '/tickets' => 'POST', '/documentos' => 'GET', '/certificado' => 'GET|POST',
    '/series' => 'GET|POST', '/series/reservar' => 'POST', '/webhooks' => 'GET|POST',
);
$esDetalle = preg_match('#^/documentos/(doc_[a-f0-9]{20})$#', $ruta, $m);
$esBusqueda = preg_match('#^/comprobantes/(01|03|07|08|20|09)/([A-Za-z0-9]{4})/(\d{1,8})$#', $ruta, $mb);
$rutasWebhook = array(
    array('#^/webhooks/(wh_[a-f0-9]{20})$#', 'DELETE', 'eliminar'),
    array('#^/webhooks/(wh_[a-f0-9]{20})/probar$#', 'POST', 'probar'),
    array('#^/webhooks/(wh_[a-f0-9]{20})/entregas$#', 'GET', 'entregas'),
    array('#^/webhooks/(wh_[a-f0-9]{20})/entregas/(ent_[a-f0-9]{20})/reenviar$#', 'POST', 'reenviar'),
);
$esWebhook = null;
$mw = array();
foreach ($rutasWebhook as $rw) {
    if (preg_match($rw[0], $ruta, $mw)) {
        $esWebhook = $rw;
        break;
    }
}
if (!$esDetalle && !$esBusqueda && !$esWebhook && !isset($permitidos[$ruta])) {
    Respuesta::error(404, 'ruta_no_encontrada', "No existe $metodo /v1$ruta");
}
$esperado = $esWebhook ? $esWebhook[1] : (($esDetalle || $esBusqueda) ? 'GET' : $permitidos[$ruta]);
if (!in_array($metodo, explode('|', $esperado), true)) {
    $lista = str_replace('|', ', ', $esperado);
    Respuesta::error(405, 'metodo_no_permitido', "Use $lista en /v1$ruta", null, array('Allow' => $lista));
}

$ctx = Auth::autenticar();
$empresa = $ctx['empresa'];
Db::asegurarEsquema(); // tras actualizar el codigo, crea lo que falte (tablas o columnas nuevas)

// Limite de velocidad del plan: "sunat" (emitir y consultar tickets; caras) o "general" (todo lo demas)
$esEmision = $metodo === 'POST' && in_array($ruta, array('/comprobantes', '/resumenes', '/bajas', '/reversiones', '/retenciones', '/guias', '/tickets'), true);
Planes::limitar($ctx, $esEmision ? 'sunat' : 'general');

if ($ruta === '/me') {
    Respuesta::ok(array(
        'ruc' => $empresa['ruc'], 'razon_social' => $empresa['razon_social'],
        'entorno' => $ctx['key']['entorno'] === 'live' ? 'produccion' : 'prueba',
        'api_key' => $ctx['key']['prefijo'] . '...',
        'credenciales_sol' => !empty($empresa['sol_usuario']), 'credenciales_gre' => !empty($empresa['gre_client_id']),
        'certificado' => Certificados::estado($empresa['id'], $ctx['key']['entorno']),
    ));
}
if ($ruta === '/uso') {
    Respuesta::ok(Planes::uso($ctx));
}
if ($ruta === '/certificado' && $metodo === 'GET') {
    $e = Certificados::estado($empresa['id'], $ctx['key']['entorno']);
    if (!$e) {
        throw new ApiError(404, 'certificado_no_registrado', 'Esta empresa no tiene certificado registrado para este entorno.' . ($ctx['key']['entorno'] === 'test' ? ' En pruebas se usa un certificado compartido.' : ''));
    }
    Respuesta::ok($e);
}
if ($esDetalle) {
    Respuesta::ok(Documentos::obtener($ctx, $m[1]));
}
if ($esBusqueda) {
    Respuesta::ok(Documentos::buscar($ctx, $mb[1], strtoupper($mb[2]), $mb[3]));
}
if ($esWebhook) {
    switch ($esWebhook[2]) {
        case 'eliminar':
            Webhooks::eliminar($ctx, $mw[1]);
            Respuesta::ok(array('id' => $mw[1], 'eliminado' => true));
        case 'probar':
            Respuesta::ok(Webhooks::probar($ctx, $mw[1]));
        case 'entregas':
            Respuesta::ok(array('items' => Webhooks::entregas($ctx, $mw[1])));
        case 'reenviar':
            Respuesta::ok(Webhooks::reenviar($ctx, $mw[1], $mw[2]));
    }
}
if ($ruta === '/webhooks' && $metodo === 'GET') {
    Respuesta::ok(array('items' => Webhooks::listar($ctx)));
}
if ($ruta === '/series' && $metodo === 'GET') {
    Respuesta::ok(array('items' => Series::listar($ctx)));
}
if ($ruta === '/documentos') {
    Respuesta::ok(Documentos::listar($ctx));
}

// ---- Emision (POST) ----
$b = Documentos::cuerpo();
unset($b['empresa']); // las credenciales y datos del emisor salen de la base, no del cliente

switch ($ruta) {
    case '/webhooks':
        $v = new Validador();
        $v->requerido($b, 'url');
        $v->lanzarSiHayErrores();
        Respuesta::ok(Webhooks::crear($ctx, $b), 201);

    case '/series':
        $v = new Validador();
        $v->requerido($b, 'tipo');
        $v->requerido($b, 'serie');
        $v->lanzarSiHayErrores();
        $modo = isset($b['modo']) ? $b['modo'] : null;
        $ultimo = array_key_exists('ultimo_numero', $b) ? $b['ultimo_numero'] : null;
        list($vista, $creada) = Series::guardar($ctx, (string) $b['tipo'], strtoupper((string) $b['serie']), $modo, $ultimo);
        Respuesta::ok($vista, $creada ? 201 : 200);

    case '/series/reservar':
        $v = new Validador();
        $v->requerido($b, 'tipo');
        $v->requerido($b, 'serie');
        $cant = isset($b['cantidad']) ? $b['cantidad'] : 1;
        if (!is_int($cant) || $cant < 1 || $cant > 100) {
            $v->error('cantidad', 'entero entre 1 y 100');
        }
        $v->lanzarSiHayErrores();
        Series::validarSerie((string) $b['tipo'], strtoupper((string) $b['serie']));
        $filas = Series::reservar($ctx, (string) $b['tipo'], strtoupper((string) $b['serie']), $cant);
        $numeros = array();
        foreach ($filas as $f) {
            $numeros[] = $f['numero'];
        }
        Respuesta::ok(array('tipo' => (string) $b['tipo'], 'serie' => strtoupper((string) $b['serie']), 'numeros' => $numeros,
            'aviso' => 'Reservados. Use cada numero al enviar el comprobante (POST /comprobantes con ese venta.numero); su estado es "reservado" hasta entonces.'), 201);

    case '/certificado':
        // Opcion A: {"pfx_base64": "...", "password": "..."}   Opcion B: {"cert_pem": "...", "key_pem": "...", "key_password": "..."}
        $v = new Validador();
        $esPfx = isset($b['pfx_base64']);
        if ($esPfx) {
            $v->requerido($b, 'password');
        } else {
            $v->requerido($b, 'cert_pem');
            $v->requerido($b, 'key_pem');
        }
        $v->lanzarSiHayErrores();
        if ($esPfx) {
            $bin = base64_decode((string) $b['pfx_base64'], true);
            if ($bin === false || $bin === '') {
                throw new ApiError(422, 'certificado_invalido', 'pfx_base64 no es base64 valido.');
            }
            list($certPem, $keyPem) = Certificados::leerPfx($bin, $b['password']);
            $clave = null;
        } else {
            $certPem = $b['cert_pem'];
            $keyPem = $b['key_pem'];
            $clave = isset($b['key_password']) ? $b['key_password'] : null;
        }
        list($hoja, $keyLimpia, $info) = Certificados::validar($certPem, $keyPem, $empresa['ruc'], $ctx['key']['entorno'], $clave);
        Certificados::guardar($empresa['id'], $ctx['key']['entorno'], $hoja, $keyLimpia, $info);
        $d = Certificados::estado($empresa['id'], $ctx['key']['entorno']);
        if (!$info['ruc_coincide']) {
            $d['aviso'] = 'El certificado no contiene el RUC de la empresa; se acepta solo en pruebas. Para produccion debe estar emitido a nombre de la empresa.';
        }
        Respuesta::ok($d); // 200: el certificado se reemplaza por el nuevo

    case '/comprobantes':
        $auto = !isset($b['venta']['numero']) || $b['venta']['numero'] === '';
        Validador::comprobante($b, $auto);
        if ($auto) {
            Series::exigirAutomatica($ctx, (string) $b['venta']['tipo_documento_codigo'], (string) $b['venta']['serie']);
        }
        foreach (array('total_exonerada', 'total_inafecta', 'total_gratuita', 'total_gratuita_igv', 'total_bolsa', 'fecha_vencimiento') as $c) {
            if (!isset($b['venta'][$c])) {
                $b['venta'][$c] = null;
            }
        }
        foreach (array('cuotas', 'guias_adjuntas', 'anticipos') as $c) {
            if (!isset($b[$c])) {
                $b[$c] = array();
            }
        }
        $d = Documentos::emitir($ctx, 'post.php', $b, $b['venta']['tipo_documento_codigo'], $b['venta']['serie'], $auto ? null : $b['venta']['numero'],
            function (array &$cu, $num) {
                $cu['venta']['numero'] = $num;
            });
        break;

    case '/resumenes':
        $v = new Validador();
        $auto = !isset($b['resumen']['correlativo']) || $b['resumen']['correlativo'] === '';
        $v->patron($b, 'resumen.numero', '/^\d{8}$/', 'fecha del resumen AAAAMMDD');
        if (!$auto) {
            $v->patron($b, 'resumen.correlativo', '/^\d{1,5}$/', 'solo digitos, maximo 5');
        }
        $v->fecha($b, 'resumen.fecha_documentos');
        $v->fecha($b, 'resumen.fecha_resumen');
        $c = Validador::obtener($b, 'comprobantes');
        if (!is_array($c) || !$c) {
            $v->error('comprobantes', 'debe enviar al menos un comprobante');
        } else {
            foreach ($c as $i => $doc) {
                foreach (array('tipo_documento', 'serie', 'numero', 'cliente_numero_documento', 'cliente_tipo_documento', 'total_a_pagar') as $campo) {
                    if (!isset($doc[$campo]) || $doc[$campo] === '') {
                        $v->error("comprobantes.$i.$campo", 'es obligatorio');
                    }
                }
                if (!isset($doc['status']) || !in_array((string) $doc['status'], array('1', '2', '3'), true)) {
                    $v->error("comprobantes.$i.status", 'use 1 (emitido), 2 (modificado) o 3 (anulado)');
                }
            }
        }
        $v->lanzarSiHayErrores();
        if ($auto) {
            Series::asegurarDiaria($ctx, 'RC', $b['resumen']['numero']);
        }
        $d = Documentos::emitir($ctx, 'resumen.php', $b, 'RC', $b['resumen']['numero'], $auto ? null : $b['resumen']['correlativo'],
            function (array &$cu, $num) {
                $cu['resumen']['correlativo'] = $num;
            });
        break;

    case '/bajas':
        $v = new Validador();
        $auto = !isset($b['anulacion']['correlativo_diario']) || $b['anulacion']['correlativo_diario'] === '';
        if (!$auto) {
            $v->patron($b, 'anulacion.correlativo_diario', '/^\d{1,5}$/', 'solo digitos, maximo 5');
        }
        $v->fecha($b, 'anulacion.fecha_comprobante');
        if ((string) Validador::obtener($b, 'anulacion.tipo_documento_codigo') === '03') {
            $v->error('anulacion.tipo_documento_codigo', 'las boletas no se anulan con baja: envielas en un resumen diario con status 3');
        } else {
            $v->enLista($b, 'anulacion.tipo_documento_codigo', array('01', '07', '08'));
        }
        $v->patron($b, 'anulacion.serie', '/^[FB][A-Z0-9]{3}$/', 'serie del comprobante (ej. F001)');
        $v->patron($b, 'anulacion.numero', '/^\d{1,8}$/', 'solo digitos');
        $v->lanzarSiHayErrores();
        $hoy = date('Ymd'); // el motor nombra la baja con la fecha de hoy
        if ($auto) {
            Series::asegurarDiaria($ctx, 'RA', $hoy);
        }
        $d = Documentos::emitir($ctx, 'baja.php', $b, 'RA', $hoy, $auto ? null : $b['anulacion']['correlativo_diario'],
            function (array &$cu, $num) {
                $cu['anulacion']['correlativo_diario'] = $num;
            });
        break;

    case '/reversiones':
        $v = new Validador();
        $auto = !isset($b['reversion']['correlativo_diario']) || $b['reversion']['correlativo_diario'] === '';
        if (!$auto) {
            $v->patron($b, 'reversion.correlativo_diario', '/^\d{1,5}$/', 'solo digitos, maximo 5');
        }
        $v->fecha($b, 'reversion.fecha_resumen');
        $v->fecha($b, 'reversion.fecha_documentos');
        $dr = Validador::obtener($b, 'documento_relacionados');
        if (!is_array($dr) || !$dr) {
            $v->error('documento_relacionados', 'debe enviar al menos un documento');
        } else {
            foreach ($dr as $i => $doc) {
                foreach (array('tipo_documento_codigo', 'serie', 'numero', 'descripcion') as $campo) {
                    if (!isset($doc[$campo]) || $doc[$campo] === '') {
                        $v->error("documento_relacionados.$i.$campo", 'es obligatorio');
                    }
                }
            }
        }
        $v->lanzarSiHayErrores();
        $ymd = str_replace('-', '', $b['reversion']['fecha_resumen']);
        if ($auto) {
            Series::asegurarDiaria($ctx, 'RR', $ymd);
        }
        $d = Documentos::emitir($ctx, 'post_reversion.php', $b, 'RR', $ymd, $auto ? null : $b['reversion']['correlativo_diario'],
            function (array &$cu, $num) {
                $cu['reversion']['correlativo_diario'] = $num;
            });
        break;

    case '/retenciones':
        $v = new Validador();
        $v->patron($b, 'proveedor.ruc', '/^\d{11}$/', 'RUC de 11 digitos');
        $v->requerido($b, 'proveedor.razon_social');
        $v->patron($b, 'retencion.serie', '/^R[A-Z0-9]{3}$/', 'la serie empieza con R (ej. R001)');
        $auto = !isset($b['retencion']['numero']) || $b['retencion']['numero'] === '';
        if (!$auto) {
            $v->patron($b, 'retencion.numero', '/^\d{1,8}$/', 'solo digitos');
        }
        $v->fecha($b, 'retencion.fecha_de_emision');
        $v->enLista($b, 'retencion.codigo', array('01', '02'));
        $v->numero($b, 'retencion.total_retenido', 0);
        $v->numero($b, 'retencion.total_facturado_menos_retenido', 0);
        $dr = Validador::obtener($b, 'documento_relacionados');
        if (!is_array($dr) || !$dr) {
            $v->error('documento_relacionados', 'debe enviar al menos un documento relacionado');
        }
        $v->lanzarSiHayErrores();
        if ($auto) {
            Series::exigirAutomatica($ctx, '20', (string) $b['retencion']['serie']);
        }
        $d = Documentos::emitir($ctx, 'post_retencion.php', $b, '20', $b['retencion']['serie'], $auto ? null : $b['retencion']['numero'],
            function (array &$cu, $num) {
                $cu['retencion']['numero'] = $num;
            });
        break;

    case '/guias':
        $v = new Validador();
        $v->patron($b, 'guia.serie', '/^[TV][A-Z0-9]{3}$/', 'la serie empieza con T (ej. T001)');
        $auto = !isset($b['guia']['numero']) || $b['guia']['numero'] === '';
        if (!$auto) {
            $v->patron($b, 'guia.numero', '/^\d{1,8}$/', 'solo digitos');
        }
        $v->fecha($b, 'guia.fecha_emision');
        $v->fecha($b, 'guia.fecha_inicio_traslado');
        $v->patron($b, 'guia.hora_emision', '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', 'formato HH:MM:SS');
        $v->requerido($b, 'guia.motivo_traslado_codigo');
        $v->numero($b, 'guia.peso_total', 0);
        $v->patron($b, 'guia.ubigeo_partida', '/^\d{6}$/', 'ubigeo de 6 digitos');
        $v->patron($b, 'guia.ubigeo_llegada', '/^\d{6}$/', 'ubigeo de 6 digitos');
        $v->requerido($b, 'guia.direccion_partida');
        $v->requerido($b, 'guia.direccion_llegada');
        $v->requerido($b, 'destinatario.numero_documento');
        $v->requerido($b, 'destinatario.razon_social_nombres');
        $it = Validador::obtener($b, 'items');
        if (!is_array($it) || !$it) {
            $v->error('items', 'debe enviar al menos un item');
        }
        $v->lanzarSiHayErrores();
        if (!isset($b['guia']['tipo_documento_codigo'])) {
            $b['guia']['tipo_documento_codigo'] = '09';
        }
        if (!isset($b['remitente'])) {
            $b['remitente'] = array('codigo_tipo_entidad' => '6', 'numero_documento' => $empresa['ruc'], 'razon_social_nombres' => $empresa['razon_social']);
        }
        foreach (array('transportista', 'evento') as $c) {
            if (!isset($b[$c])) {
                $b[$c] = new stdClass();
            }
        }
        foreach (array('vehiculos', 'conductores', 'documentos_relacionados') as $c) {
            if (!isset($b[$c])) {
                $b[$c] = array();
            }
        }
        if ($auto) {
            Series::exigirAutomatica($ctx, '09', (string) $b['guia']['serie']);
        }
        $d = Documentos::emitir($ctx, 'post_guia.php', $b, '09', $b['guia']['serie'], $auto ? null : $b['guia']['numero'],
            function (array &$cu, $num) {
                $cu['guia']['numero'] = $num;
            });
        break;

    case '/tickets':
        $v = new Validador();
        $v->enLista($b, 'tipo', array('guia', 'resumen', 'baja'));
        $v->requerido($b, 'ticket');
        $tk = (string) Validador::obtener($b, 'ticket');
        if ($tk !== '' && !preg_match('/^[A-Za-z0-9-]{6,80}$/', $tk)) {
            $v->error('ticket', 'formato de ticket invalido');
        }
        if (Validador::obtener($b, 'tipo') === 'resumen') {
            $v->patron($b, 'correlativo', '/^\d{1,8}$/', 'correlativo del resumen');
            $v->fecha($b, 'fecha_ticket');
        }
        $v->lanzarSiHayErrores();
        $emp = Motor::empresa($empresa, $ctx['key']['entorno']);
        if ($b['tipo'] === 'guia') {
            $res = Motor::llamar('post_guia_ticket.php', array('empresa' => $emp, 'num_ticket' => $tk), 25);
            $n = Motor::normalizar('post_guia_ticket.php', $res);
            foreach (array('xml', 'cdr', 'pdf') as $k) {
                if (isset($n['archivos'][$k])) {
                    $n['archivos'][$k] = Motor::urlPublica($n['archivos'][$k]);
                }
            }
            unset($n['archivos']['xml_base_64'], $n['archivos']['cdr_base_64']);
            Respuesta::ok($n);
        }
        if ($b['tipo'] === 'resumen') {
            $res = Motor::llamar('resumen_ticket.php', array('empresa' => $emp, 'anulacion' => array('corelativo' => $b['correlativo'], 'fecha_ticket' => $b['fecha_ticket'], 'ticket' => $tk)), 25);
        } else {
            $res = Motor::llamar('baja_envia_ticket.php', array('empresa' => $emp, 'anulacion' => array('ticket' => $tk)), 25);
        }
        if ($res['json'] === null) {
            error_log('[v1] consulta de ticket sin JSON: ' . substr($res['raw'], 0, 600));
            throw new ApiError(502, 'motor_error', 'SUNAT no respondio la consulta del ticket (request ' . Respuesta::id() . '). Intente de nuevo en unos minutos.');
        }
        Respuesta::ok(array('ticket' => $tk, 'detalle' => $res['json']));
        break;
}

Planes::cabecerasCuota($ctx);
Respuesta::ok($d, 201);
