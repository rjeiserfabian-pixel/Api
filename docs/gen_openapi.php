<?php
/**
 * Genera docs/openapi.json (OpenAPI 3.0.3) de la API /v1.   Uso:  php docs/gen_openapi.php
 * Los ejemplos salen de docs/ejemplos.php (los mismos que verifica docs/probar.php contra la API real).
 */
require_once __DIR__ . '/ejemplos.php';
$ej = ej_cuerpos(ej_muestra());

function ref($n) { return array('$ref' => '#/components/schemas/' . $n); }
function obj(array $props, array $req = array(), $desc = null)
{
    $o = array('type' => 'object', 'properties' => $props);
    if ($req) { $o['required'] = $req; }
    if ($desc) { $o['description'] = $desc; }
    return $o;
}
function str($desc, $extra = array()) { return array_merge(array('type' => 'string', 'description' => $desc), $extra); }
function num($desc, $extra = array()) { return array_merge(array('type' => 'number', 'description' => $desc), $extra); }
function int_($desc, $extra = array()) { return array_merge(array('type' => 'integer', 'description' => $desc), $extra); }
function envelope($data) { return obj(array('ok' => array('type' => 'boolean', 'example' => true), 'data' => $data, 'request_id' => str('Identificador de la peticion (cite este valor al pedir soporte).', array('example' => 'req_5c9fdc752c96fc22'))), array('ok', 'data', 'request_id')); }
function resp($desc, $schema, $ejemplo = null, $cab = array())
{
    $m = array('schema' => $schema);
    if ($ejemplo !== null) { $m['example'] = $ejemplo; }
    $r = array('description' => $desc, 'content' => array('application/json' => $m));
    if ($cab) { $r['headers'] = $cab; }
    return $r;
}
function err($ref) { return array('$ref' => '#/components/responses/' . $ref); }

$docEj = array('id' => 'doc_0e7ded8531fd33aede36', 'tipo' => '01', 'serie' => 'F001', 'numero' => '94455099', 'entorno' => 'prueba', 'estado' => 'aceptado',
    'sunat' => array('codigo' => '0', 'descripcion' => 'La Factura numero F001-94455099, ha sido aceptada'), 'ticket' => null, 'hash' => 'DpL3Tb7gsOql6mMKZA61o3mQ7+Q=',
    'archivos' => array('xml' => 'https://tu-dominio.com/API_SUNAT/files/facturacion_electronica/FIRMA/20000000001-01-F001-94455099.xml',
        'cdr' => 'https://tu-dominio.com/API_SUNAT/files/facturacion_electronica/CDR/R-20000000001-01-F001-94455099.xml',
        'pdf' => 'https://tu-dominio.com/API_SUNAT/files/facturacion_electronica/PDF/20000000001-01-F001-94455099.pdf'), 'creado' => '2026-11-11 22:45:01');
$rechazadoEj = array_merge($docEj, array('estado' => 'rechazado', 'sunat' => array('codigo' => 'soap-env:Client.3267',
    'descripcion' => 'Fecha del pago unico o de las cuotas no puede ser anterior o igual a la fecha de emision del comprobante'), 'hash' => null, 'archivos' => new stdClass()));
$pendienteEj = array('id' => 'doc_d62d20ffb272192ca6b0', 'tipo' => 'RC', 'serie' => '20261111', 'numero' => '73064', 'entorno' => 'prueba', 'estado' => 'pendiente',
    'sunat' => array('codigo' => null, 'descripcion' => 'Recibido por SUNAT. Consulte el ticket para conocer el resultado.'), 'ticket' => '1794455238208',
    'hash' => null, 'archivos' => new stdClass(), 'creado' => '2026-11-11 22:45:21');
$simuladoEj = array('id' => 'doc_a1b2c3d4e5f60718293a', 'tipo' => '09', 'serie' => 'T001', 'numero' => '00000001', 'entorno' => 'prueba', 'estado' => 'simulado',
    'sunat' => array('codigo' => 'NO_ENVIADO', 'descripcion' => 'Modo prueba: la guia se genero y firmo pero NO se envio a SUNAT, porque la API de guias no tiene entorno beta. Use una llave live para emitirla de verdad.'),
    'ticket' => null, 'hash' => null, 'archivos' => array('xml' => 'https://tu-dominio.com/API_SUNAT/files/facturacion_electronica/GUIA/FIRMA/20000000001-09-T001-00000001.xml'), 'creado' => '2026-11-11 23:10:00');

function errEj($codigo, $msg, $det = null)
{
    $e = array('code' => $codigo, 'message' => $msg);
    if ($det) { $e['details'] = $det; }
    return array('ok' => false, 'error' => $e, 'request_id' => 'req_a8122150f2caeaa0');
}

$cabIdem = array('Idempotent-Replay' => array('description' => 'true cuando se devuelve el resultado guardado de una peticion repetida.', 'schema' => array('type' => 'string', 'enum' => array('true'))));
$params = array(
    'IdempotencyKey' => array('name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => array('type' => 'string', 'pattern' => '^[A-Za-z0-9._\\-]{1,64}$'),
        'description' => 'Clave unica por operacion (hasta 64 caracteres: letras, numeros, . _ -). Si la peticion se repite con la misma clave y el mismo cuerpo, se devuelve el resultado guardado (200 + cabecera Idempotent-Replay) sin volver a enviar a SUNAT. Con la misma clave y otro cuerpo responde 409. **Recomendado siempre** para poder reintentar tras un corte de red.', 'example' => 'venta-12345'),
    'Incluir' => array('name' => 'incluir', 'in' => 'query', 'required' => false, 'schema' => array('type' => 'string', 'enum' => array('archivos')),
        'description' => 'Con `archivos` la respuesta agrega `xml_base_64` y `cdr_base_64` (el XML firmado y la constancia de SUNAT) para guardarlos sin una segunda descarga.'),
);

$ok = function ($ref, $ejemplo, $desc = 'Procesado. El campo `estado` indica el resultado en SUNAT.') use ($cabIdem) {
    return array('201' => resp($desc, envelope(ref($ref)), array('ok' => true, 'data' => $ejemplo, 'request_id' => 'req_5c9fdc752c96fc22')),
        '200' => resp('Peticion repetida con la misma `Idempotency-Key`: devuelve el resultado guardado.', envelope(ref($ref)), array('ok' => true, 'data' => $ejemplo, 'request_id' => 'req_2b34bdbb48099d3b'), $cabIdem));
};
$erroresEmision = array('400' => err('R400'), '401' => err('R401'), '403' => err('R403'), '409' => err('R409'), '413' => err('R413'), '422' => err('R422'), '500' => err('R500'), '502' => err('R502'), '504' => err('R504'));

function post($tag, $id, $resumen, $desc, $schemaReq, $ejemplos, array $respuestas, $conIdem = true, array $extraParams = array())
{
    $ex = array();
    foreach ($ejemplos as $nombre => $e) {
        $ex[$nombre] = array('summary' => $e[0], 'value' => $e[1]);
    }
    $p = $extraParams;
    if ($conIdem) { array_unshift($p, array('$ref' => '#/components/parameters/IdempotencyKey')); }
    $op = array('tags' => array($tag), 'operationId' => $id, 'summary' => $resumen, 'description' => $desc, 'security' => array(array('bearerAuth' => array())),
        'requestBody' => array('required' => true, 'content' => array('application/json' => array('schema' => $schemaReq, 'examples' => $ex))), 'responses' => $respuestas);
    if ($p) { $op['parameters'] = $p; }
    return array('post' => $op);
}

// ---------------- Esquemas ----------------
$s = array();
$s['Estado'] = array('type' => 'string', 'enum' => array('aceptado', 'rechazado', 'pendiente', 'simulado', 'reservado', 'procesando', 'error'),
    'description' => "- `aceptado`: SUNAT aceptó el documento (tiene CDR).\n- `rechazado`: SUNAT lo rechazó; `sunat.codigo` y `sunat.descripcion` explican el motivo. **El HTTP sigue siendo 201**: el rechazo es un resultado de negocio, no un error de la API. Corrija los datos y envíe de nuevo.\n- `pendiente`: SUNAT lo recibió y devolvió un `ticket`; consulte el resultado con `POST /tickets`.\n- `simulado`: solo con llave `test` en guías de remisión: se generó y firmó pero **no se envió** (SUNAT no tiene entorno de pruebas para guías).\n- `reservado`: número apartado con `POST /series/reservar` que todavía no se envió a SUNAT.\n- `procesando`: la API lo está enviando, o un intento anterior se interrumpió; reintente con la **misma** `Idempotency-Key`.\n- `error`: el envío falló antes de recibir respuesta de SUNAT (por ejemplo, SUNAT caído). **El número queda guardado**. Si el envío llegó con `Idempotency-Key`, **la API lo reintenta sola** (1 min, 5 min, 15 min, 1 h, 3 h, 6 h y 12 h) y avisa por webhook; también puede reintentarlo usted con la misma clave.");
$s['Sunat'] = obj(array('codigo' => str('Código de SUNAT (`0` = aceptado) o un código interno.', array('nullable' => true, 'example' => '0')), 'descripcion' => str('Mensaje de SUNAT.', array('nullable' => true))));
$s['Archivos'] = obj(array('xml' => str('Enlace al XML firmado.', array('format' => 'uri')), 'cdr' => str('Enlace a la Constancia de Recepción (CDR) de SUNAT.', array('format' => 'uri')), 'pdf' => str('Enlace al PDF (si existe).', array('format' => 'uri')),
    'xml_base_64' => str('Solo con `?incluir=archivos`: ZIP con el XML firmado, en base64.'), 'cdr_base_64' => str('Solo con `?incluir=archivos`: ZIP con el CDR, en base64.')),
    array(), 'Los enlaces que aplican según el documento y su estado. Puede venir vacío (`{}`) si SUNAT lo rechazó o está pendiente.');
$s['Documento'] = obj(array(
    'id' => str('Identificador del documento en esta API.', array('example' => 'doc_0e7ded8531fd33aede36')),
    'tipo' => str('Tipo SUNAT: `01` factura, `03` boleta, `07` nota de crédito, `08` nota de débito, `20` retención, `09` guía de remisión, `RC` resumen diario, `RA` baja, `RR` reversión.'),
    'serie' => str('Serie del documento (en `RC` es la fecha AAAAMMDD; en `RR` la fecha del resumen).', array('nullable' => true)),
    'numero' => str('Número del documento (en `RC`/`RR` el correlativo; en `RA` "número#correlativo").', array('nullable' => true)),
    'entorno' => array('type' => 'string', 'enum' => array('prueba', 'produccion')),
    'estado' => ref('Estado'), 'sunat' => ref('Sunat'),
    'ticket' => str('Ticket de SUNAT (resumen, baja, reversión, guía) o null.', array('nullable' => true)),
    'hash' => str('Valor resumen (hash) de la firma digital del XML.', array('nullable' => true)),
    'archivos' => ref('Archivos'), 'creado' => str('Fecha y hora (Lima) en que se registró.', array('example' => '2026-11-11 22:45:01'))),
    array('id', 'tipo', 'entorno', 'estado', 'sunat', 'archivos', 'creado'));
$s['ErrorDetalle'] = obj(array('campo' => str('Ruta del campo con problema.', array('example' => 'venta.serie')), 'mensaje' => str('Qué corregir.')), array('campo', 'mensaje'));
$s['Error'] = obj(array('ok' => array('type' => 'boolean', 'example' => false),
    'error' => obj(array('code' => str('Código estable para programar la respuesta (ver tabla en la descripción general).', array('example' => 'validacion')),
        'message' => str('Explicación legible.'), 'details' => array('type' => 'array', 'items' => ref('ErrorDetalle'), 'description' => 'Solo en `validacion`: un elemento por campo con error.')), array('code', 'message')),
    'request_id' => str('Cite este valor al pedir soporte.')), array('ok', 'error', 'request_id'));

$s['Cliente'] = obj(array('codigo_tipo_entidad' => array('type' => 'string', 'enum' => array('0', '1', '4', '6', '7', 'A'), 'description' => 'Tipo de documento del cliente (catálogo 06): `1` DNI, `6` RUC, `4` carnet de extranjería, `7` pasaporte, `0` sin documento, `A` cédula diplomática. **Una factura exige `6`.**'),
    'numero_documento' => str('DNI de 8 dígitos o RUC de 11.', array('example' => '20987654321')), 'razon_social_nombres' => str('Razón social o nombres.'), 'cliente_direccion' => str('Dirección (opcional).')),
    array('codigo_tipo_entidad', 'numero_documento', 'razon_social_nombres'));
$s['Item'] = obj(array(
    'codigo_producto' => str('Código interno del producto (opcional).'), 'producto' => str('Descripción.'),
    'codigo_sunat' => str('Código de producto SUNAT de **8 dígitos** (catálogo 25). SUNAT rechaza el comprobante si falta.', array('pattern' => '^\\d{8}$', 'example' => '50202201')),
    'cantidad' => num('Mayor a 0.', array('exclusiveMinimum' => 0)), 'codigo_unidad' => str('Unidad (catálogo 03): `NIU` unidad, `ZZ` servicio, `KGM` kilo...', array('example' => 'NIU')),
    'precio_base' => num('**Precio unitario SIN IGV.** Mayor o igual a 0.', array('minimum' => 0)),
    'tipo_igv_codigo' => str('Afectación del IGV (catálogo 07): `10` gravado, `20` exonerado, `30` inafecto, `40` exportación, `11`–`17` gratuito.', array('example' => '10')),
    'descuento_precio_base' => num('Descuento por unidad (opcional).'), 'bolsa' => array('type' => 'boolean', 'description' => 'Impuesto a la bolsa plástica (opcional).')),
    array('producto', 'codigo_sunat', 'cantidad', 'codigo_unidad', 'precio_base', 'tipo_igv_codigo'));
$s['Cuota'] = obj(array('monto' => num('Monto de la cuota.'), 'fecha_cuota' => str('AAAA-MM-DD. **Debe ser posterior a la fecha de emisión.**', array('format' => 'date'))), array('monto', 'fecha_cuota'));
$s['Venta'] = obj(array(
    'tipo_documento_codigo' => array('type' => 'string', 'enum' => array('01', '03', '07', '08'), 'description' => '`01` factura, `03` boleta, `07` nota de crédito, `08` nota de débito.'),
    'serie' => str('4 caracteres. Factura: empieza con `F`; boleta: `B`; notas: `F` o `B` (según lo que modifican).', array('example' => 'F001')),
    'numero' => str('Solo dígitos, máximo 8 (`150` y `00000150` son el mismo). **Opcional si la serie está en modo `api`**: omítalo y la API asigna el siguiente (exige `Idempotency-Key`). En modo `cliente` (por defecto) es obligatorio.', array('pattern' => '^\\d{1,8}$')),
    'fecha_emision' => str('AAAA-MM-DD. SUNAT rechaza fechas fuera de su plazo de envío.', array('format' => 'date')),
    'hora_emision' => str('HH:MM:SS', array('example' => '10:00:00')),
    'fecha_vencimiento' => str('AAAA-MM-DD (opcional, facturas y boletas).', array('format' => 'date')),
    'moneda_id' => array('type' => 'integer', 'enum' => array(1, 2, 3), 'description' => '1 soles (PEN), 2 dólares (USD), 3 euros (EUR).'),
    'forma_pago_id' => array('type' => 'integer', 'enum' => array(1, 2), 'description' => '1 contado, 2 crédito (exige `cuotas`).'),
    'total_gravada' => num('Suma de los valores de venta gravados con IGV (sin IGV).'), 'total_igv' => num('18 % de `total_gravada` (se valida con tolerancia de 0.02 salvo que haya `descuento_global`).'),
    'total_exonerada' => num('Opcional.', array('nullable' => true)), 'total_inafecta' => num('Opcional.', array('nullable' => true)), 'total_gratuita' => num('Opcional.', array('nullable' => true)),
    'total_gratuita_igv' => num('Opcional.', array('nullable' => true)), 'total_bolsa' => num('Opcional.', array('nullable' => true)), 'descuento_global' => num('Opcional.'),
    'orden_compra' => str('Opcional.'), 'nota' => str('Observaciones (opcional).'),
    'detraccion_codigo' => str('Opcional.'), 'detraccion_porcentaje' => num('Opcional.', array('nullable' => true)), 'percepcion_codigo' => str('Opcional.'), 'percepcion_porcentaje' => num('Opcional.', array('nullable' => true)), 'retencion_porcentaje' => num('Opcional.', array('nullable' => true)),
    'relacionado_serie' => str('**Solo notas (07/08):** serie del documento que se modifica.'), 'relacionado_numero' => str('**Solo notas:** número del documento que se modifica.'),
    'relacionado_tipo_documento' => array('type' => 'string', 'enum' => array('01', '03'), 'description' => '**Solo notas:** `01` factura o `03` boleta.'),
    'relacionado_motivo_codigo' => str('**Solo notas:** motivo, `01` a `13` (catálogos 09 y 10 de SUNAT; ej. nota de crédito `01` anulación de la operación, `06` devolución total, `07` devolución por ítem).')),
    array('tipo_documento_codigo', 'serie', 'fecha_emision', 'hora_emision', 'moneda_id', 'forma_pago_id'));
$s['ComprobanteRequest'] = obj(array('cliente' => ref('Cliente'), 'venta' => ref('Venta'), 'items' => array('type' => 'array', 'minItems' => 1, 'items' => ref('Item')),
    'cuotas' => array('type' => 'array', 'items' => ref('Cuota'), 'description' => 'Obligatorio si `forma_pago_id` = 2.'), 'guias_adjuntas' => array('type' => 'array', 'items' => array('type' => 'object'), 'description' => 'Opcional.'),
    'anticipos' => array('type' => 'array', 'items' => array('type' => 'object'), 'description' => 'Opcional.')), array('cliente', 'venta', 'items'),
    'No envíe `empresa`: el emisor, la clave SOL y el certificado salen de la configuración de su empresa en la API (cualquier `empresa` enviada se ignora).');

$s['ResumenRequest'] = obj(array('resumen' => obj(array('numero' => str('Fecha del resumen AAAAMMDD.', array('example' => '20261111')), 'correlativo' => str('Correlativo del día (1 a 5 dígitos). **Opcional**: si lo omite, la API asigna el siguiente del día (exige `Idempotency-Key`).'),
        'fecha_documentos' => str('Fecha de emisión de las boletas.', array('format' => 'date')), 'fecha_resumen' => str('Fecha en que se envía el resumen.', array('format' => 'date'))), array('numero', 'fecha_documentos', 'fecha_resumen')),
    'comprobantes' => array('type' => 'array', 'minItems' => 1, 'items' => obj(array('tipo_documento' => str('`03` boleta, `07`/`08` notas de boletas.'), 'serie' => str('Serie.'), 'numero' => str('Número.'),
        'cliente_numero_documento' => str('Documento del cliente.'), 'cliente_tipo_documento' => str('Tipo de documento del cliente.'), 'total_a_pagar' => num('Total.'), 'total_gravada' => num('Opcional.'),
        'total_igv' => num('Opcional.'), 'total_exonerada' => array('description' => 'Opcional.'), 'total_inafecta' => array('description' => 'Opcional.'), 'total_gratuita' => array('description' => 'Opcional.'),
        'status' => array('type' => 'string', 'enum' => array('1', '2', '3'), 'description' => '`1` emitida, `2` modificada, `3` **anulada** (así se anula una boleta).')),
        array('tipo_documento', 'serie', 'numero', 'cliente_numero_documento', 'cliente_tipo_documento', 'total_a_pagar', 'status')))), array('resumen', 'comprobantes'));
$s['BajaRequest'] = obj(array('anulacion' => obj(array('correlativo_diario' => str('Correlativo del día, **distinto en cada envío** (1 a 5 dígitos). **Opcional**: si lo omite, la API asigna el siguiente del día (exige `Idempotency-Key`).'), 'fecha_comprobante' => str('Fecha de emisión del comprobante.', array('format' => 'date')),
    'tipo_documento_codigo' => array('type' => 'string', 'enum' => array('01', '07', '08'), 'description' => 'Solo facturas y notas. **Las boletas no se dan de baja: use un resumen diario con `status` = 3.**'),
    'serie' => str('Serie.'), 'numero' => str('Número.')), array('fecha_comprobante', 'tipo_documento_codigo', 'serie', 'numero'))), array('anulacion'),
    'Solo para comprobantes con menos de 7 días de emitidos.');
$s['ReversionRequest'] = obj(array('reversion' => obj(array('correlativo_diario' => str('Correlativo del día, distinto en cada envío. **Opcional**: si lo omite, la API asigna el siguiente del día (exige `Idempotency-Key`).'), 'fecha_resumen' => str('Fecha.', array('format' => 'date')), 'fecha_documentos' => str('Fecha.', array('format' => 'date'))),
    array('fecha_resumen', 'fecha_documentos')), 'documento_relacionados' => array('type' => 'array', 'minItems' => 1, 'items' => obj(array('tipo_documento_codigo' => str('Ej. `20` retención.'), 'serie' => str('Serie.'), 'numero' => str('Número.'), 'descripcion' => str('Motivo.')),
    array('tipo_documento_codigo', 'serie', 'numero', 'descripcion')))), array('reversion', 'documento_relacionados'));
$s['RetencionRequest'] = obj(array('proveedor' => obj(array('ruc' => str('RUC de 11 dígitos.'), 'razon_social' => str('Razón social.')), array('ruc', 'razon_social')),
    'retencion' => obj(array('serie' => str('Empieza con `R`.', array('example' => 'R001')), 'numero' => str('Solo dígitos, máximo 8. **Opcional si la serie está en modo `api`** (la API lo asigna; exige `Idempotency-Key`).'), 'fecha_de_emision' => str('AAAA-MM-DD', array('format' => 'date')),
        'codigo' => array('type' => 'string', 'enum' => array('01', '02'), 'description' => '`01` régimen de retención 3 %, `02` régimen especial 6 %.'), 'porcentaje' => num('Porcentaje.'), 'nota' => str('Opcional.'),
        'total_retenido' => num('Total retenido.'), 'total_facturado_menos_retenido' => num('Total pagado menos lo retenido.')), array('serie', 'fecha_de_emision', 'codigo', 'total_retenido', 'total_facturado_menos_retenido')),
    'documento_relacionados' => array('type' => 'array', 'minItems' => 1, 'items' => array('type' => 'object', 'description' => 'Comprobante sobre el que se retiene y su pago (ver ejemplo).'))), array('proveedor', 'retencion', 'documento_relacionados'),
    'El emisor es su empresa registrada (no se envía).');
$s['GuiaRequest'] = obj(array('destinatario' => obj(array('codigo_tipo_entidad' => str('Tipo de documento.'), 'numero_documento' => str('Documento.'), 'razon_social_nombres' => str('Nombre o razón social.')), array('numero_documento', 'razon_social_nombres')),
    'guia' => obj(array('serie' => str('Empieza con `T` (ej. T001).', array('example' => 'T001')), 'numero' => str('Solo dígitos, máximo 8. **Opcional si la serie está en modo `api`** (la API lo asigna; exige `Idempotency-Key`).'), 'fecha_emision' => str('AAAA-MM-DD', array('format' => 'date')), 'hora_emision' => str('HH:MM:SS'),
        'fecha_inicio_traslado' => str('AAAA-MM-DD', array('format' => 'date')), 'fecha_entrega_bienes_transportista' => str('AAAA-MM-DD', array('format' => 'date')), 'motivo_traslado_codigo' => str('Catálogo 20: `01` venta, `04` traslado entre establecimientos...'),
        'peso_total' => num('Peso bruto total.'), 'unidad_peso' => str('`KGM` kilos.'), 'cantidad_bultos' => int_('Bultos.'), 'direccion_partida' => str('Dirección de partida.'), 'ubigeo_partida' => str('6 dígitos.', array('pattern' => '^\\d{6}$')),
        'direccion_llegada' => str('Dirección de llegada.'), 'ubigeo_llegada' => str('6 dígitos.', array('pattern' => '^\\d{6}$')), 'transporte_publico' => array('type' => 'boolean'), 'observacion' => str('Opcional.')),
        array('serie', 'fecha_emision', 'hora_emision', 'fecha_inicio_traslado', 'motivo_traslado_codigo', 'peso_total', 'ubigeo_partida', 'ubigeo_llegada', 'direccion_partida', 'direccion_llegada')),
    'vehiculos' => array('type' => 'array', 'items' => obj(array('placa' => str('Placa.'), 'principal' => array('type' => 'boolean')))),
    'conductores' => array('type' => 'array', 'items' => array('type' => 'object')), 'items' => array('type' => 'array', 'minItems' => 1, 'items' => array('type' => 'object')),
    'documentos_relacionados' => array('type' => 'array', 'items' => array('type' => 'object')), 'transportista' => array('type' => 'object'), 'remitente' => array('type' => 'object', 'description' => 'Opcional: por defecto es su empresa.')),
    array('destinatario', 'guia', 'items'), 'Para emitir de verdad (llave `live`) la empresa necesita credenciales GRE (client_id y client_secret) registradas; con llave `test` no hacen falta porque no se envía.');
$s['TicketRequest'] = obj(array('tipo' => array('type' => 'string', 'enum' => array('guia', 'resumen', 'baja'), 'description' => 'Qué se consulta.'),
    'ticket' => str('Ticket devuelto al enviar (6 a 80 caracteres).'), 'correlativo' => str('**Solo `resumen`:** correlativo con que se envió.'), 'fecha_ticket' => str('**Solo `resumen`:** fecha del resumen AAAA-MM-DD.', array('format' => 'date'))), array('tipo', 'ticket'));
$s['TicketResultado'] = obj(array('estado' => ref('Estado'), 'sunat' => ref('Sunat'), 'ticket' => str('Ticket.', array('nullable' => true)), 'hash' => str('Hash.', array('nullable' => true)), 'archivos' => ref('Archivos')), array('estado', 'sunat'));
$s['TicketDetalle'] = obj(array('ticket' => str('Ticket consultado.'), 'detalle' => array('type' => 'object', 'description' => 'Respuesta de SUNAT tal cual la devuelve el motor.')), array('ticket', 'detalle'));
$s['Certificado'] = obj(array('entorno' => array('type' => 'string', 'enum' => array('prueba', 'produccion')), 'sujeto' => str('Titular del certificado.'), 'huella_sha256' => str('Huella SHA-256.'), 'serie' => str('Número de serie.'),
    'valido_desde' => str('Fecha y hora.'), 'valido_hasta' => str('Fecha y hora.'), 'dias_restantes' => int_('Días hasta el vencimiento (negativo si venció).'), 'vigente' => array('type' => 'boolean'),
    'por_vencer' => array('type' => 'boolean', 'description' => 'true si vence en 30 días o menos.')));
$s['CertificadoRequest'] = array('description' => 'Envíe **una** de las dos opciones.', 'oneOf' => array(
    obj(array('pfx_base64' => str('Archivo .pfx/.p12 en base64.'), 'password' => str('Contraseña del PFX.')), array('pfx_base64', 'password')),
    obj(array('cert_pem' => str('Certificado en PEM.'), 'key_pem' => str('Llave privada RSA en PEM.'), 'key_password' => str('Contraseña de la llave, si la tiene.')), array('cert_pem', 'key_pem'))));
$s['Me'] = obj(array('ruc' => str('RUC de la empresa.'), 'razon_social' => str('Razón social.'), 'entorno' => array('type' => 'string', 'enum' => array('prueba', 'produccion')), 'api_key' => str('Prefijo de la llave usada.', array('example' => 'sk_test_4891...')),
    'credenciales_sol' => array('type' => 'boolean', 'description' => 'Si hay clave SOL registrada.'), 'credenciales_gre' => array('type' => 'boolean', 'description' => 'Si hay credenciales GRE registradas.'), 'certificado' => array('allOf' => array(ref('Certificado')), 'nullable' => true)));
$s['Listado'] = obj(array('items' => array('type' => 'array', 'items' => ref('Documento')), 'total' => int_('Total que cumple el filtro.'), 'limit' => int_('Máximo por página.'), 'offset' => int_('Desde.')), array('items', 'total', 'limit', 'offset'));

// ---------------- Respuestas de error reutilizables ----------------
$resp = array(
    'R400' => resp('Petición mal formada: cuerpo vacío (`cuerpo_vacio`), JSON inválido (`json_invalido`) o `Idempotency-Key` inválida (`idempotency_key_invalida`).', ref('Error'), errEj('json_invalido', 'El cuerpo debe ser un objeto JSON valido: Syntax error')),
    'R401' => resp('Falta la API key o es inválida/revocada (`no_autenticado`).', ref('Error'), errEj('no_autenticado', 'Falta la API key o es invalida. Envie: Authorization: Bearer sk_test_...')),
    'R403' => resp('La empresa está desactivada (`empresa_inactiva`).', ref('Error'), errEj('empresa_inactiva', 'La empresa asociada a esta API key esta desactivada.')),
    'R404' => resp('No existe la ruta o el recurso (`ruta_no_encontrada`, `no_encontrado`, `certificado_no_registrado`, `serie_no_registrada`).', ref('Error'), errEj('no_encontrado', 'Documento no encontrado.')),
    'R405' => resp('Método HTTP no permitido en esa ruta (`metodo_no_permitido`). La cabecera `Allow` indica los permitidos.', ref('Error'), errEj('metodo_no_permitido', 'Use GET en /v1/me')),
    'R409' => resp('Conflicto: `documento_duplicado` (ya existe ese tipo/serie/número aceptado o pendiente) `idempotencia_conflicto` (la `Idempotency-Key` ya se usó con otro cuerpo), `peticion_en_curso` / `documento_en_proceso` (ya se está procesando), `numero_no_reservado` (serie en modo `api`: el número no fue reservado), `serie_no_automatica` (reservar en una serie que numera su sistema), `serie_agotada` o `limite_webhooks` (el plan limita los webhooks por entorno).', ref('Error'),
        errEj('documento_duplicado', 'Ya existe 01 F001-94455099 con estado aceptado.')),
    'R413' => resp('El cuerpo supera 3 MB (`cuerpo_muy_grande`).', ref('Error'), errEj('cuerpo_muy_grande', 'El cuerpo supera 3 MB.')),
    'R422' => resp('Los datos no son válidos (`validacion`, con `details` por campo) o la URL de un webhook no es segura (`webhook_url_invalida`) o falta un dato de numeración (`numero_requerido`, `idempotency_key_requerida`: la numeración automática exige `Idempotency-Key`) o hay un problema con el certificado (`certificado_requerido`, `certificado_vencido`, `certificado_invalido`, `llave_invalida`, `llave_no_corresponde`, `certificado_ruc_no_coincide`, `certificado_no_vigente`). **No se envió nada a SUNAT.**',
        ref('Error'), errEj('validacion', 'Los datos enviados no son validos.', array(array('campo' => 'venta.serie', 'mensaje' => 'la serie de una factura son 4 caracteres y empieza con F (ej. F001)'), array('campo' => 'items.0.codigo_sunat', 'mensaje' => 'es obligatorio: codigo de producto SUNAT de 8 digitos (catalogo 25, ej. 50202201)')))),
    'R500' => resp('Error interno (`error_interno`). Cite el `request_id`.', ref('Error'), errEj('error_interno', 'Error interno del servidor. Cite el request_id al pedir soporte.')),
    'R402' => resp('Cuota mensual de documentos agotada (`cuota_agotada`). `details` indica plan, cuota, usados y fecha de reinicio. **No se envio nada a SUNAT.** Repetir un documento ya emitido (misma `Idempotency-Key`), las pruebas (llave `test`) y las consultas siguen funcionando.',
        ref('Error'), errEj('cuota_agotada', 'Agoto los 500 documentos de su plan Basico este mes. La cuota se reinicia el 01/12/2026. Contacte a su proveedor para ampliar su plan.', array('plan' => 'Basico', 'cuota_mensual' => 500, 'usados' => 500, 'reinicia' => '2026-12-01T00:00:00-05:00')),
        array('X-Quota-Limit' => array('description' => 'Cuota mensual del plan.', 'schema' => array('type' => 'integer')), 'X-Quota-Remaining' => array('description' => 'Documentos que le quedan (0).', 'schema' => array('type' => 'integer')))),
    'R429' => resp('Demasiadas peticiones: supero el limite por minuto de su plan (`limite_de_peticiones`) o el servicio esta saturado de emisiones (`servicio_saturado`). Espere los segundos indicados en `Retry-After` y reintente. No se perdio nada.',
        ref('Error'), errEj('limite_de_peticiones', 'Supero el limite de 20 emisiones y consultas de ticket por minuto de su plan Basico. Espere 14 segundos y reintente.', array('clase' => 'sunat', 'limite_por_minuto' => 20, 'reintentar_en_segundos' => 14)),
        array('Retry-After' => array('description' => 'Segundos que debe esperar antes de reintentar.', 'schema' => array('type' => 'integer')))),
    'R504' => resp('SUNAT no respondió a tiempo (`tiempo_agotado`). No se perdió nada: reintente en unos minutos (con la misma `Idempotency-Key` si es una emisión).', ref('Error'), errEj('tiempo_agotado', 'SUNAT no respondio a tiempo. Intente de nuevo en unos minutos.')),
    'R502' => resp('El motor o SUNAT no respondieron (`motor_no_disponible`, `motor_error`). Reintente con la misma `Idempotency-Key`.', ref('Error'), errEj('motor_no_disponible', 'El motor de facturacion no respondio. Intente nuevamente.')),
);

$seg = array(array('bearerAuth' => array()));
$paths = array();
$paths['/health'] = array('get' => array('tags' => array('Sistema'), 'operationId' => 'health', 'summary' => 'Estado del servicio', 'description' => 'No requiere autenticación. Úselo para monitoreo.', 'security' => array(),
    'responses' => array('200' => resp('Servicio activo.', envelope(obj(array('servicio' => array('type' => 'string'), 'version' => array('type' => 'string'), 'hora' => array('type' => 'string', 'format' => 'date-time'), 'worker' => array('type' => 'string', 'enum' => array('activo', 'detenido', 'sin_iniciar'), 'description' => 'Proceso en segundo plano (reintentos y webhooks): `activo` si corrió hace menos de 60 s.'))))))));
$paths['/me'] = array('get' => array('tags' => array('Sistema'), 'operationId' => 'me', 'summary' => 'Empresa, entorno y certificado de la API key', 'description' => 'Útil para comprobar que la llave funciona y a qué entorno apunta (`test` → SUNAT beta, `live` → producción).', 'security' => $seg,
    'responses' => array('200' => resp('Datos de la empresa.', envelope(ref('Me'))), '401' => err('R401'), '403' => err('R403'), '405' => err('R405'))));
$paths['/certificado'] = array(
    'get' => array('tags' => array('Certificado'), 'operationId' => 'certificadoEstado', 'summary' => 'Estado del certificado digital', 'description' => 'Devuelve vigencia, huella y días restantes del certificado de **este entorno**. Nunca devuelve la llave privada.', 'security' => $seg,
        'responses' => array('200' => resp('Certificado registrado.', envelope(ref('Certificado'))), '401' => err('R401'), '404' => err('R404'))),
    'post' => array('tags' => array('Certificado'), 'operationId' => 'certificadoSubir', 'summary' => 'Registrar o reemplazar el certificado digital',
        'description' => "Cada empresa firma con su propio certificado. Con llave `test` se registra el de pruebas (opcional: si no hay, se usa uno compartido). Con llave `live` se registra el de **producción, obligatorio** para emitir.\n\nSe valida que sea RSA, que la llave corresponda al certificado, que esté vigente y —en producción— que contenga el RUC de la empresa. La llave privada se guarda **cifrada**.",
        'security' => $seg, 'requestBody' => array('required' => true, 'content' => array('application/json' => array('schema' => ref('CertificadoRequest'), 'examples' => array(
            'pfx' => array('summary' => 'Archivo PFX', 'value' => $ej['certificado_pfx']), 'pem' => array('summary' => 'PEM', 'value' => $ej['certificado_pem']))))),
        'responses' => array('200' => resp('Certificado guardado.', envelope(ref('Certificado'))), '400' => err('R400'), '401' => err('R401'), '413' => err('R413'), '422' => err('R422'), '500' => err('R500'))));

$paths['/comprobantes'] = post('Comprobantes', 'emitirComprobante', 'Emitir factura, boleta, nota de crédito o nota de débito',
    "Valida los datos, firma el XML con el certificado de su empresa, lo envía a SUNAT y devuelve el resultado.\n\n**Cómo leer la respuesta:** el HTTP es `201` cuando se procesó, y `estado` dice qué pasó (`aceptado` / `rechazado`). Un rechazo de SUNAT **no es un error HTTP**.\n\nUse `Idempotency-Key` para poder reintentar con seguridad. Si el mismo tipo-serie-número ya fue aceptado, responde `409 documento_duplicado`.",
    ref('ComprobanteRequest'), array('factura' => array('Factura al contado', $ej['factura']), 'factura_credito' => array('Factura al crédito con cuotas', $ej['factura_credito']),
        'boleta' => array('Boleta a DNI', $ej['boleta']), 'nota_credito' => array('Nota de crédito (anulación)', $ej['nota_credito']), 'nota_debito' => array('Nota de débito', $ej['nota_debito'])),
    $ok('Documento', $docEj) + $erroresEmision, true, array(array('$ref' => '#/components/parameters/Incluir')));
$paths['/comprobantes']['post']['responses']['201']['content']['application/json']['examples'] = array(
    'aceptado' => array('summary' => 'Aceptado por SUNAT', 'value' => array('ok' => true, 'data' => $docEj, 'request_id' => 'req_5c9fdc752c96fc22')),
    'rechazado' => array('summary' => 'Rechazado por SUNAT', 'value' => array('ok' => true, 'data' => $rechazadoEj, 'request_id' => 'req_92993a546c654698')));
unset($paths['/comprobantes']['post']['responses']['201']['content']['application/json']['example']);

$paths['/resumenes'] = post('Resúmenes y bajas', 'enviarResumen', 'Resumen diario de boletas', 'Informa a SUNAT las boletas del día (y es la vía para **anular boletas**, con `status` = 3). SUNAT devuelve un ticket: `estado` queda `pendiente`; consulte el resultado con `POST /tickets` (`tipo` = `resumen`).',
    ref('ResumenRequest'), array('resumen' => array('Resumen con una boleta', $ej['resumen'])), $ok('Documento', $pendienteEj) + $erroresEmision);
$paths['/bajas'] = post('Resúmenes y bajas', 'enviarBaja', 'Comunicación de baja', 'Anula **facturas y notas** (no boletas) con menos de 7 días. SUNAT devuelve un ticket (`estado` = `pendiente`). Use un `correlativo_diario` distinto en cada envío.',
    ref('BajaRequest'), array('baja' => array('Baja de una factura', $ej['baja'])), $ok('Documento', $pendienteEj) + $erroresEmision);
$paths['/reversiones'] = post('Resúmenes y bajas', 'enviarReversion', 'Reversión', 'Revierte comprobantes de retención o percepción. Devuelve un ticket (`estado` = `pendiente`).',
    ref('ReversionRequest'), array('reversion' => array('Reversión de una retención', $ej['reversion'])), $ok('Documento', $pendienteEj) + $erroresEmision);
$paths['/retenciones'] = post('Retenciones', 'emitirRetencion', 'Comprobante de retención (20)', 'Emite un comprobante de retención. Su empresa es la agente de retención.',
    ref('RetencionRequest'), array('retencion' => array('Retención del 3 %', $ej['retencion'])), $ok('Documento', $docEj) + $erroresEmision);
$paths['/guias'] = post('Guías de remisión', 'emitirGuia', 'Guía de remisión remitente (09)',
    "**Importante:** SUNAT no tiene entorno de pruebas para guías de remisión. Con una llave `test` la guía se genera y firma pero **no se envía** (`estado` = `simulado`). Con una llave `live` **se emite de verdad y no se puede borrar**.\n\nCon llave `live` requiere credenciales GRE de la empresa. Si SUNAT devuelve un ticket, `estado` = `pendiente`: consulte con `POST /tickets` (`tipo` = `guia`).",
    ref('GuiaRequest'), array('guia' => array('Guía por venta', $ej['guia'])), $ok('Documento', $simuladoEj) + $erroresEmision);
$paths['/tickets'] = post('Consultas', 'consultarTicket', 'Consultar el resultado de un ticket', 'Para `guia` devuelve el estado normalizado (`aceptado`/`rechazado`/`pendiente`) y guarda el CDR. Para `resumen` y `baja` devuelve la respuesta de SUNAT en `detalle`. **Nota:** el servicio de consulta de tickets de SUNAT puede tardar o no responder; la API espera como máximo 25 segundos y responde `504 tiempo_agotado` (reintente en unos minutos).',
    ref('TicketRequest'), array('guia' => array('Ticket de guía', $ej['ticket_guia']), 'resumen' => array('Ticket de resumen', $ej['ticket_resumen']), 'baja' => array('Ticket de baja', $ej['ticket_baja'])),
    array('201' => resp('Resultado de la consulta.', envelope(array('oneOf' => array(ref('TicketResultado'), ref('TicketDetalle'))))), '400' => err('R400'), '401' => err('R401'), '422' => err('R422'), '502' => err('R502'), '504' => err('R504'), '500' => err('R500')), false);
$paths['/tickets']['post']['responses']['200'] = $paths['/tickets']['post']['responses']['201'];
unset($paths['/tickets']['post']['responses']['201']);

$paths['/documentos'] = array('get' => array('tags' => array('Historial'), 'operationId' => 'listarDocumentos', 'summary' => 'Listar documentos emitidos', 'description' => 'Historial de la empresa **en el entorno de la llave usada** (las pruebas no se mezclan con producción), del más reciente al más antiguo.', 'security' => $seg,
    'parameters' => array(
        array('name' => 'tipo', 'in' => 'query', 'schema' => array('type' => 'string'), 'description' => 'Filtra por tipo (`01`, `03`, `07`, `08`, `20`, `09`, `RC`, `RA`, `RR`).'),
        array('name' => 'estado', 'in' => 'query', 'schema' => ref('Estado'), 'description' => 'Filtra por estado.'), array('name' => 'serie', 'in' => 'query', 'schema' => array('type' => 'string'), 'description' => 'Filtra por serie.'), array('name' => 'numero', 'in' => 'query', 'schema' => array('type' => 'string', 'pattern' => '^\\d{1,8}$'), 'description' => 'Filtra por número (`150` y `00000150` son el mismo).'),
        array('name' => 'limit', 'in' => 'query', 'schema' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20), 'description' => 'Máximo por página.'),
        array('name' => 'offset', 'in' => 'query', 'schema' => array('type' => 'integer', 'minimum' => 0, 'default' => 0), 'description' => 'Desde qué posición.')),
    'responses' => array('200' => resp('Página de documentos.', envelope(ref('Listado'))), '401' => err('R401'), '403' => err('R403'), '405' => err('R405'))));
$paths['/documentos/{id}'] = array('get' => array('tags' => array('Historial'), 'operationId' => 'obtenerDocumento', 'summary' => 'Detalle de un documento', 'security' => $seg,
    'parameters' => array(array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^doc_[a-f0-9]{20}$'), 'example' => 'doc_0e7ded8531fd33aede36')),
    'responses' => array('200' => resp('El documento.', envelope(ref('Documento')), array('ok' => true, 'data' => $docEj, 'request_id' => 'req_x')), '401' => err('R401'), '404' => err('R404'))));

// ---------------- Series y numeracion ----------------
$tiposSerie = array('01', '03', '07', '08', '20', '09');
$s['SerieRequest'] = obj(array(
    'tipo' => array('type' => 'string', 'enum' => $tiposSerie, 'description' => 'Tipo de documento: `01` factura, `03` boleta, `07` nota de crédito, `08` nota de débito, `20` retención, `09` guía.'),
    'serie' => str('4 caracteres; el prefijo depende del tipo: factura `F`, boleta `B`, notas `F` o `B`, retención `R`, guía `T`.', array('example' => 'B001')),
    'modo' => array('type' => 'string', 'enum' => array('cliente', 'api'), 'default' => 'cliente', 'description' => 'Quién numera: `cliente` (su sistema comercial; la API aprende el último número) o `api` (la API asigna).'),
    'ultimo_numero' => int_('Último número que ya usó en esa serie, para continuar desde allí. **Solo puede subir, nunca bajar** (evita repetir números).', array('minimum' => 0, 'maximum' => 99999999))), array('tipo', 'serie'));
$s['Serie'] = obj(array('tipo' => str('Tipo de documento.'), 'serie' => str('Serie.'), 'modo' => array('type' => 'string', 'enum' => array('cliente', 'api')),
    'entorno' => array('type' => 'string', 'enum' => array('prueba', 'produccion')), 'ultimo_numero' => int_('Mayor número usado o reservado hasta ahora.'),
    'siguiente_numero' => str('El próximo número (8 dígitos). En modo `cliente` es una sugerencia para su sistema.', array('example' => '00000151')),
    'reservas_abiertas' => int_('Números reservados que todavía no se enviaron a SUNAT.')));
$s['ListadoSeries'] = obj(array('items' => array('type' => 'array', 'items' => ref('Serie'))), array('items'));
$s['ReservaRequest'] = obj(array('tipo' => str('Tipo de documento.'), 'serie' => str('Serie (debe estar en modo `api`).'), 'cantidad' => int_('Cuántos números reservar (1 a 100; por defecto 1).', array('minimum' => 1, 'maximum' => 100, 'default' => 1))), array('tipo', 'serie'));
$s['Reserva'] = obj(array('tipo' => str('Tipo.'), 'serie' => str('Serie.'), 'numeros' => array('type' => 'array', 'items' => array('type' => 'string'), 'example' => array('00000004', '00000005')), 'aviso' => str('Recordatorio de uso.')), array('tipo', 'serie', 'numeros'));

$serieEj = array('tipo' => '03', 'serie' => 'B001', 'modo' => 'api', 'entorno' => 'prueba', 'ultimo_numero' => 3, 'siguiente_numero' => '00000004', 'reservas_abiertas' => 0);
$paths['/series'] = array(
    'get' => array('tags' => array('Series y numeración'), 'operationId' => 'listarSeries', 'summary' => 'Listar series y su siguiente número',
        'description' => 'Las series de **este entorno** (las de prueba y las de producción son independientes). Las series que su sistema comercial usa en modo `cliente` aparecen solas después del primer envío.', 'security' => $seg,
        'responses' => array('200' => resp('Series.', envelope(ref('ListadoSeries')), array('ok' => true, 'data' => array('items' => array($serieEj)), 'request_id' => 'req_x')), '401' => err('R401'), '403' => err('R403'))),
    'post' => array('tags' => array('Series y numeración'), 'operationId' => 'guardarSerie', 'summary' => 'Registrar o actualizar una serie',
        'description' => "Define quién numera la serie y desde dónde continúa. Si la serie ya existe, actualiza su modo; el contador **solo sube** (nunca baja) y nunca queda por debajo de lo ya usado.\n\nPara **migrar** desde su sistema comercial, registre la serie con `ultimo_numero` = el último número que ya emitió.",
        'security' => $seg, 'requestBody' => array('required' => true, 'content' => array('application/json' => array('schema' => ref('SerieRequest'), 'examples' => array(
            'api' => array('summary' => 'La API numera la serie', 'value' => $ej['serie_api']), 'cliente' => array('summary' => 'Su sistema numera; continúa desde el 150', 'value' => $ej['serie_cliente']))))),
        'responses' => array('201' => resp('Serie creada.', envelope(ref('Serie')), array('ok' => true, 'data' => $serieEj, 'request_id' => 'req_x')), '200' => resp('Serie actualizada.', envelope(ref('Serie'))),
            '400' => err('R400'), '401' => err('R401'), '422' => err('R422'), '500' => err('R500'))));
$paths['/series/reservar'] = post('Series y numeración', 'reservarNumeros', 'Reservar números para enviarlos después',
    "Aparta números consecutivos de una serie en modo `api`. Úselo cuando su sistema comercial debe **entregar el comprobante al cliente antes de enviarlo a SUNAT**: imprima el número reservado y envíe el comprobante después con `POST /comprobantes` usando **ese mismo** `venta.numero`.\n\nMientras no se envíe, el documento figura como `reservado` (`GET /comprobantes/{tipo}/{serie}/{numero}`). La reserva es atómica: peticiones simultáneas nunca reciben el mismo número.",
    ref('ReservaRequest'), array('reservar' => array('Reservar 2 números de la serie B001', $ej['reservar'])),
    array('201' => resp('Números reservados.', envelope(ref('Reserva')), array('ok' => true, 'data' => array('tipo' => '03', 'serie' => 'B001', 'numeros' => array('00000004', '00000005'), 'aviso' => 'Reservados. Use cada numero al enviar el comprobante.'), 'request_id' => 'req_x')),
        '400' => err('R400'), '401' => err('R401'), '404' => err('R404'), '409' => err('R409'), '422' => err('R422'), '500' => err('R500')), false);
$paths['/comprobantes/{tipo}/{serie}/{numero}'] = array('get' => array('tags' => array('Series y numeración'), 'operationId' => 'consultarPorNumero', 'summary' => 'Consultar un documento por tipo, serie y número',
    'description' => 'Devuelve el documento más reciente con ese tipo-serie-número en este entorno: `reservado`, `aceptado`, `rechazado`, etc. `150` y `00000150` son el mismo. Responde `404` si todavía no se envió ni se reservó.', 'security' => $seg,
    'parameters' => array(
        array('name' => 'tipo', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'enum' => $tiposSerie), 'example' => '03'),
        array('name' => 'serie', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^[A-Za-z0-9]{4}$'), 'example' => 'B001'),
        array('name' => 'numero', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^\\d{1,8}$'), 'example' => '4')),
    'responses' => array('200' => resp('El documento.', envelope(ref('Documento'))), '401' => err('R401'), '404' => err('R404'))));
$paths['/comprobantes']['post']['requestBody']['content']['application/json']['examples']['boleta_auto'] = array(
    'summary' => 'Boleta con número automático (serie en modo api; exige Idempotency-Key)', 'value' => $ej['boleta_auto']);
// ---------------- Webhooks ----------------
$eventosWh = array('documento.aceptado', 'documento.rechazado', 'documento.error', 'cuota.advertencia', 'cuota.agotada');
$s['WebhookRequest'] = obj(array('url' => str('Dirección **pública** que recibirá los avisos (HTTPS en producción; sin usuario/clave; no se siguen redirecciones).', array('example' => 'https://midominio.com/webhooks/sunat', 'maxLength' => 500)),
    'eventos' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => $eventosWh), 'description' => 'Eventos a recibir. Si se omite, todos.')), array('url'));
$s['Webhook'] = obj(array('id' => str('Identificador.', array('example' => 'wh_0123456789abcdef0123')), 'url' => str('Destino.'), 'eventos' => array('type' => 'array', 'items' => array('type' => 'string', 'enum' => $eventosWh)),
    'activo' => array('type' => 'boolean'), 'entorno' => array('type' => 'string', 'enum' => array('prueba', 'produccion')), 'creado' => str('Fecha de creación.'),
    'secreto' => str('**Solo al crear**: secreto para verificar la firma de los avisos. Guárdelo; no se vuelve a mostrar.', array('example' => 'whsec_...')), 'aviso' => str('Recordatorio sobre el secreto (solo al crear).')), array('id', 'url', 'eventos', 'activo'));
$s['ListadoWebhooks'] = obj(array('items' => array('type' => 'array', 'items' => ref('Webhook'))), array('items'));
$s['Entrega'] = obj(array('id' => str('Identificador de la entrega.', array('example' => 'ent_0123456789abcdef0123')), 'evento' => obj(array('id' => str('Id del evento (úselo para ignorar repetidos).'), 'tipo' => str('Tipo de evento.'))),
    'estado' => array('type' => 'string', 'enum' => array('pendiente', 'enviando', 'entregado', 'fallido'), 'description' => '`pendiente`: en cola o esperando el siguiente reintento; `entregado`: su servidor respondió 2xx; `fallido`: se agotaron los intentos.'),
    'intentos' => int_('Intentos realizados.'), 'proximo_intento' => str('Cuándo se reintentará.', array('nullable' => true)), 'ultimo_http' => int_('Último código HTTP recibido de su servidor.', array('nullable' => true)),
    'ultimo_error' => str('Motivo del último fallo.', array('nullable' => true)), 'entregado_en' => str('Cuándo se entregó.', array('nullable' => true)), 'creado' => str('Creada.')));
$s['ListadoEntregas'] = obj(array('items' => array('type' => 'array', 'items' => ref('Entrega'))), array('items'));
$s['PruebaWebhook'] = obj(array('entrega' => str('Id de la entrega de prueba.'), 'entregado' => array('type' => 'boolean'), 'http' => int_('Código HTTP de su servidor.', array('nullable' => true)), 'error' => str('Motivo si falló.', array('nullable' => true))), array('entregado'));
$s['EventoWebhook'] = obj(array('id' => str('Id único del evento (puede llegar repetido: use este campo para ignorar duplicados).', array('example' => 'evt_0123456789abcdef0123')),
    'tipo' => array('type' => 'string', 'enum' => array_merge($eventosWh, array('webhook.prueba'))), 'entorno' => array('type' => 'string', 'enum' => array('prueba', 'produccion')), 'creado' => str('Fecha y hora (Lima).'),
    'data' => array('allOf' => array(ref('Documento')), 'description' => 'El documento (sin datos del cliente final). En `webhook.prueba`: `{"mensaje": "..."}`.')), array('id', 'tipo', 'entorno', 'creado', 'data'));

$whEj = array('id' => 'wh_0123456789abcdef0123', 'url' => 'https://midominio.com/webhooks/sunat', 'eventos' => $eventosWh, 'activo' => true, 'entorno' => 'prueba', 'creado' => '2026-11-12 10:00:00');
$paths['/webhooks'] = array(
    'get' => array('tags' => array('Webhooks'), 'operationId' => 'listarWebhooks', 'summary' => 'Listar webhooks', 'description' => 'Los webhooks de **este entorno**. Nunca incluye el secreto.', 'security' => $seg,
        'responses' => array('200' => resp('Webhooks.', envelope(ref('ListadoWebhooks')), array('ok' => true, 'data' => array('items' => array($whEj)), 'request_id' => 'req_x')), '401' => err('R401'), '403' => err('R403'))),
    'post' => array('tags' => array('Webhooks'), 'operationId' => 'crearWebhook', 'summary' => 'Registrar un webhook',
        'description' => "La API avisará a esta URL cuando un documento llegue a un estado final (`documento.aceptado`, `documento.rechazado`, `documento.error`). Hasta 5 por entorno.\n\nLa respuesta incluye el **secreto** (`whsec_…`) **una sola vez**: úselo para verificar la firma de cada aviso. Antes de depender de él, pruebe el destino con `POST /webhooks/{id}/probar`.",
        'security' => $seg, 'requestBody' => array('required' => true, 'content' => array('application/json' => array('schema' => ref('WebhookRequest'), 'examples' => array(
            'todos' => array('summary' => 'Todos los eventos', 'value' => array('url' => 'https://midominio.com/webhooks/sunat')),
            'solo_problemas' => array('summary' => 'Solo rechazos y errores', 'value' => array('url' => 'https://midominio.com/webhooks/sunat', 'eventos' => array('documento.rechazado', 'documento.error')))))))
        , 'responses' => array('201' => resp('Webhook creado (con el secreto).', envelope(ref('Webhook')), array('ok' => true, 'data' => array_merge($whEj, array('secreto' => 'whsec_0123456789abcdef0123456789abcdef0123456789abcdef', 'aviso' => 'Guarde este secreto ahora: no se vuelve a mostrar.')), 'request_id' => 'req_x')),
            '400' => err('R400'), '401' => err('R401'), '409' => err('R409'), '422' => err('R422'), '500' => err('R500'))));
$paths['/webhooks/{id}'] = array('delete' => array('tags' => array('Webhooks'), 'operationId' => 'eliminarWebhook', 'summary' => 'Eliminar un webhook', 'description' => 'Elimina el webhook y sus entregas pendientes.', 'security' => $seg,
    'parameters' => array(array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^wh_[a-f0-9]{20}$'), 'example' => 'wh_0123456789abcdef0123')),
    'responses' => array('200' => resp('Eliminado.', envelope(obj(array('id' => str('Id.'), 'eliminado' => array('type' => 'boolean')))), array('ok' => true, 'data' => array('id' => 'wh_0123456789abcdef0123', 'eliminado' => true), 'request_id' => 'req_x')), '401' => err('R401'), '404' => err('R404'))));
$paths['/webhooks/{id}/probar'] = array('post' => array('tags' => array('Webhooks'), 'operationId' => 'probarWebhook', 'summary' => 'Enviar un evento de prueba',
    'description' => 'Envía **ahora** un evento `webhook.prueba` firmado (un solo intento, sin reintentos) y dice si su servidor respondió 2xx.', 'security' => $seg,
    'parameters' => array(array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^wh_[a-f0-9]{20}$'), 'example' => 'wh_0123456789abcdef0123')),
    'responses' => array('200' => resp('Resultado del envío de prueba (también cuando su servidor falla: mire `entregado`).', envelope(ref('PruebaWebhook')), array('ok' => true, 'data' => array('entrega' => 'ent_0123456789abcdef0123', 'entregado' => true, 'http' => 200, 'error' => null), 'request_id' => 'req_x')), '401' => err('R401'), '404' => err('R404'))));
$paths['/webhooks/{id}/entregas'] = array('get' => array('tags' => array('Webhooks'), 'operationId' => 'listarEntregas', 'summary' => 'Historial de entregas de un webhook',
    'description' => 'Las últimas entregas (más recientes primero), con su estado, intentos y el último error: sirve para depurar su servidor.', 'security' => $seg,
    'parameters' => array(array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^wh_[a-f0-9]{20}$')),
        array('name' => 'estado', 'in' => 'query', 'schema' => array('type' => 'string', 'enum' => array('pendiente', 'enviando', 'entregado', 'fallido')), 'description' => 'Filtra por estado.'),
        array('name' => 'limit', 'in' => 'query', 'schema' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20))),
    'responses' => array('200' => resp('Entregas.', envelope(ref('ListadoEntregas'))), '401' => err('R401'), '404' => err('R404'))));
$paths['/webhooks/{id}/entregas/{entregaId}/reenviar'] = array('post' => array('tags' => array('Webhooks'), 'operationId' => 'reenviarEntrega', 'summary' => 'Reenviar una entrega',
    'description' => 'Vuelve a poner en cola una entrega `fallido` o `entregado` (por ejemplo, después de arreglar su servidor). Se envía en el próximo ciclo del worker.', 'security' => $seg,
    'parameters' => array(array('name' => 'id', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^wh_[a-f0-9]{20}$')),
        array('name' => 'entregaId', 'in' => 'path', 'required' => true, 'schema' => array('type' => 'string', 'pattern' => '^ent_[a-f0-9]{20}$'))),
    'responses' => array('200' => resp('En cola.', envelope(obj(array('id' => str('Entrega.'), 'estado' => str('pendiente'), 'aviso' => str('Nota.')))), array('ok' => true, 'data' => array('id' => 'ent_0123456789abcdef0123', 'estado' => 'pendiente', 'aviso' => 'Se enviara en el proximo ciclo del worker.'), 'request_id' => 'req_x')), '401' => err('R401'), '404' => err('R404'))));
// ---------------- Plan, limites y cuota ----------------
$s['Uso'] = obj(array(
    'plan' => obj(array('nombre' => str('Nombre del plan (`sin_plan` si no tiene uno asignado: sin limites).', array('example' => 'Basico')),
        'cuota_mensual' => int_('Documentos por mes. `null` = ilimitada; `0` = no incluye produccion.', array('nullable' => true, 'example' => 500)),
        'limite_emisiones_por_minuto' => int_('Emisiones y consultas de ticket por minuto y por API key. `null` = sin limite.', array('nullable' => true, 'example' => 20)),
        'limite_peticiones_por_minuto' => int_('Demas peticiones (consultas, series, webhooks...) por minuto y por API key. `null` = sin limite.', array('nullable' => true, 'example' => 120)),
        'max_webhooks' => int_('Webhooks permitidos por entorno.', array('example' => 2))), array('nombre', 'max_webhooks')),
    'periodo' => str('Mes de la cuota (AAAA-MM, hora de Lima).', array('example' => '2026-11')),
    'documentos_usados' => int_('Documentos emitidos y aceptados este mes con llave `live`.', array('example' => 120)),
    'documentos_restantes' => int_('Lo que le queda este mes (`null` si es ilimitada).', array('nullable' => true, 'example' => 380)),
    'porcentaje' => num('Porcentaje de la cuota consumido.', array('nullable' => true, 'example' => 24)),
    'reinicia' => str('Cuando se reinicia la cuota (primer dia del mes siguiente).', array('format' => 'date-time')),
    'nota' => str('Que consume cuota.')), array('plan', 'periodo', 'documentos_usados', 'reinicia'));
$paths['/uso'] = array('get' => array('tags' => array('Sistema'), 'operationId' => 'uso', 'summary' => 'Plan, consumo del mes y limites',
    'description' => 'Su plan, cuantos documentos lleva este mes, cuantos le quedan y cuando se reinicia la cuota, y sus limites de velocidad. Consultar el uso es gratis y no consume cuota. **El consumo es el de produccion (`live`) aunque use una llave de prueba.**', 'security' => $seg,
    'responses' => array('200' => resp('Plan y consumo.', envelope(ref('Uso')), array('ok' => true, 'data' => array('plan' => array('nombre' => 'Basico', 'cuota_mensual' => 500, 'limite_emisiones_por_minuto' => 20, 'limite_peticiones_por_minuto' => 120, 'max_webhooks' => 2),
        'periodo' => '2026-11', 'documentos_usados' => 120, 'documentos_restantes' => 380, 'porcentaje' => 24, 'reinicia' => '2026-12-01T00:00:00-05:00', 'nota' => 'Solo los documentos emitidos y aceptados con una llave de produccion (live) consumen cuota.'), 'request_id' => 'req_x')),
        '401' => err('R401'), '403' => err('R403'), '429' => err('R429'))));

// Toda operacion autenticada puede responder 429; las emisiones de documentos que cuentan, ademas 402
foreach ($paths as $rutaP => $opsP) {
    foreach ($opsP as $metodoP => $opP) {
        if (!empty($opP['security']) && !isset($paths[$rutaP][$metodoP]['responses']['429'])) {
            $paths[$rutaP][$metodoP]['responses']['429'] = err('R429');
        }
    }
}
foreach (array('/comprobantes', '/retenciones', '/guias') as $rutaE) {
    $paths[$rutaE]['post']['responses']['402'] = err('R402');
}
$descripcion = <<<'MD'
API REST para emitir **comprobantes electrónicos a SUNAT** (facturas, boletas, notas, retenciones, guías, resúmenes y bajas) sin manejar XML, firmas ni servicios web de SUNAT: usted envía un JSON y recibe el resultado.

## Primeros pasos
1. Obtenga su **API key** (se la entrega el administrador). Hay dos tipos:
   - `sk_test_…` → **pruebas**: envía al **beta** de SUNAT. No tiene efectos tributarios.
   - `sk_live_…` → **producción**: documentos reales ante SUNAT.
2. Envíela en cada petición: `Authorization: Bearer sk_test_…`
3. Compruebe con `GET /me`.
4. Emita su primera factura con `POST /comprobantes`.

## Autenticación y entornos
El **entorno lo decide la llave**, no el JSON. Las credenciales SOL y el certificado digital de su empresa están registrados en la API: **no se envían en las peticiones**. Para producción la empresa debe registrar su certificado (`POST /certificado` con llave `live`).

## Cómo leer las respuestas
Toda respuesta es `{ "ok": true, "data": … }` o `{ "ok": false, "error": { "code", "message", "details" } }`, y siempre incluye `request_id` (también en la cabecera `X-Request-Id`).

- **HTTP 201**: la API procesó el documento. Mire `data.estado`: `aceptado`, `rechazado`, `pendiente` o `simulado`. **Que SUNAT rechace un documento no es un error HTTP**: `data.sunat.descripcion` explica el motivo.
- **HTTP 4xx**: la petición no se procesó (datos inválidos, sin autenticar, duplicado…). No se envió nada a SUNAT.
- **HTTP 5xx (500, 502, 504)**: fallo temporal del servicio o de SUNAT. Reintente con la misma `Idempotency-Key`.

## Reintentos seguros: `Idempotency-Key`
Envíe en cada emisión una cabecera `Idempotency-Key` única por operación (por ejemplo, el id de su venta). Si no recibe respuesta (corte de red), repita **la misma petición con la misma clave**: recibirá el resultado guardado (`200` + `Idempotent-Replay: true`) sin riesgo de duplicar el documento ante SUNAT. Además, un tipo-serie-número ya aceptado o pendiente responde `409 documento_duplicado`.

## Numeración y sincronización con su sistema comercial
Cada serie (por empresa y por entorno) tiene un **dueño**:

- **`cliente`** (por defecto): su sistema comercial numera y envía `venta.numero` como siempre. La API **aprende** el último número usado (`GET /series`): no importa si salta o llega desordenado.
- **`api`**: la API numera. Puede **omitir `numero`** (se asigna solo, de forma atómica, sin repetir) o **reservar números** con `POST /series/reservar`, imprimirlos en el comprobante y **enviarlos a SUNAT después** con ese mismo número.

Garantías:
- **Sin duplicados ni saltos por reintento:** la numeración automática exige `Idempotency-Key`; si el envío falla (SUNAT caído, corte de red) y usted reintenta con la misma clave, se **reutiliza el mismo número**.
- Si SUNAT **rechaza** un documento numerado automáticamente, su número se libera y el documento corregido lo reutiliza.
- Los números se comparan como enteros: `150` y `00000150` son el mismo documento.
- Resúmenes, bajas y reversiones usan un **correlativo por día** que se asigna solo si lo omite.
- Cada entorno tiene sus propias series: **registre también las de producción** (con `ultimo_numero` = el último que ya usó) antes de emitir con una llave `live`.

**Consultar por serie y número** (por ejemplo, un cliente que recibió la boleta antes de que se enviara a SUNAT): `GET /comprobantes/{tipo}/{serie}/{numero}` responde `reservado`, `aceptado`, etc., o `404` si aún no existe.

## Reintentos automáticos y webhooks
**Reintentos.** Si envía un documento con `Idempotency-Key` y SUNAT (o el motor) falla por un problema **transitorio**, el documento queda en estado `error` conservando su número y la API **lo reenvía sola** con espera creciente: 1 min, 5 min, 15 min, 1 h, 3 h, 6 h y 12 h (8 intentos en total). Nunca se duplica: cada intento usa el mismo número, y si usted reintenta a la vez, solo uno de los dos envía. Un rechazo de SUNAT **no** se reintenta (hay que corregir los datos). Sin `Idempotency-Key` no hay reintentos automáticos: un reintento ajeno podría duplicar una venta que usted ya repitió con otro número.

**Webhooks.** Registre una URL (`POST /webhooks`) y la API le avisa cuando un documento llega a un estado final, sin que usted tenga que consultar:

| Evento | Cuándo |
|---|---|
| `documento.aceptado` | SUNAT aceptó el documento (también al resolverse un reintento o el ticket de una guía) |
| `documento.rechazado` | SUNAT lo rechazó (`data.sunat` explica el motivo) |
| `documento.error` | Se agotaron los reintentos (o el fallo no es reintentable): necesita atención |
| `cuota.advertencia` | Su cuota mensual llegó al 80 % (`data`: plan, usados, restantes, porcentaje y fecha de reinicio; no es un documento) |
| `cuota.agotada` | Su cuota mensual llegó al 100 % |

Cada aviso es un `POST` JSON `{ id, tipo, entorno, creado, data: <Documento> }`. **No incluye datos del cliente final**, solo el estado y los enlaces. Su servidor debe responder **2xx** en menos de 10 segundos; si no, la API reintenta (1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h, 24 h) y después marca la entrega como `fallido` (puede reenviarla con `POST /webhooks/{id}/entregas/{id}/reenviar`). Como puede haber reintentos, **el mismo evento puede llegar más de una vez: use `id` del evento para ignorar repetidos**.

**Verifique la firma** de cada aviso (cabecera `X-Webhook-Signature: t=<unix>,v1=<hex>`): `hex = HMAC_SHA256(secreto, "<unix>.<cuerpo exacto>")`. Rechace avisos con firma inválida o con `t` de hace más de 5 minutos.

```php
$cuerpo = file_get_contents('php://input');
[$t, $v1] = [substr(explode(',', $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])[0], 2), substr(explode(',', $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'])[1], 3)];
$ok = abs(time() - $t) < 300 && hash_equals(hash_hmac('sha256', $t . '.' . $cuerpo, $SECRETO_WHSEC), $v1);
```

Por seguridad, la URL debe ser **pública** (no se aceptan direcciones privadas, locales ni de metadatos de nube), con **HTTPS en producción**, sin usuario/clave y sin redirecciones. El **secreto** (`whsec_…`) se muestra una sola vez al crear el webhook. Todo esto lo ejecuta un proceso aparte (`php bin/worker.php`); `GET /health` indica si está `activo`.

## Planes, límites y cuota
Su plan define dos cosas distintas:

**1. Límite de velocidad (peticiones por minuto).** Protege el servicio y a SUNAT; no se factura. Se mide **por API key** y por minuto, en dos clases:
- **Emisiones** (`POST /comprobantes`, `/resumenes`, `/bajas`, `/reversiones`, `/retenciones`, `/guias`, `/tickets`): son las caras, porque dependen de SUNAT.
- **Demás peticiones** (consultas, series, webhooks…): baratas, con un límite mayor.

Cada respuesta trae `X-RateLimit-Limit`, `X-RateLimit-Remaining` y `X-RateLimit-Reset` (segundos hasta el siguiente minuto). Si se excede: **`429`** con la cabecera **`Retry-After`** (segundos). Además existe un **tope global de emisiones** (para que ningún cliente degrade a los demás): si el servicio está saturado recibirá `429 servicio_saturado`; espere y reintente. **Un 429 no pierde nada**: reintente con la misma `Idempotency-Key`.

**2. Cuota mensual de documentos (lo que contrata).** Consumen cuota solo los documentos **emitidos y aceptados por SUNAT** (o recibidos y en espera de su ticket, como una guía) **con llave `live`**: factura, boleta, notas, retención y guía. **No consumen:** las pruebas (llave `test`), las consultas, las reservas de números, los **rechazos** de SUNAT, los resúmenes/bajas/reversiones, ni repetir un documento con la misma `Idempotency-Key`. Si SUNAT rechaza una guía al resolver su ticket, la unidad se devuelve. La cuota es **mensual** (mes calendario, hora de Lima).

Consulte su consumo con `GET /uso`; las respuestas de emisión (`live`) traen `X-Quota-Limit` y `X-Quota-Remaining`. Al **80 %** y al **100 %** recibe un aviso por webhook (`cuota.advertencia`, `cuota.agotada`). Al agotarse la cuota, emitir en producción responde **`402 cuota_agotada`** (con la fecha de reinicio; no se envía nada a SUNAT); las pruebas, las consultas y repetir documentos ya emitidos siguen funcionando.

## Flujos frecuentes
- **Anular una factura** (menos de 7 días): `POST /bajas` → ticket → `POST /tickets`. O emita una **nota de crédito** (motivo `01`).
- **Anular una boleta**: no existe baja para boletas; envíela en un **resumen diario** con `status` = 3 (`POST /resumenes`).
- **Boletas del día**: `POST /resumenes` (SUNAT lo exige para boletas).

## Códigos de error
| `code` | HTTP | Significado |
|---|---|---|
| `no_autenticado` | 401 | Falta la API key o es inválida/revocada |
| `empresa_inactiva` | 403 | Empresa desactivada |
| `ruta_no_encontrada`, `no_encontrado`, `certificado_no_registrado` | 404 | No existe |
| `metodo_no_permitido` | 405 | Método HTTP incorrecto (cabecera `Allow`) |
| `cuerpo_vacio`, `json_invalido`, `idempotency_key_invalida` | 400 | Petición mal formada |
| `documento_duplicado`, `idempotencia_conflicto` | 409 | Ya existe / clave ya usada con otro cuerpo |
| `cuerpo_muy_grande` | 413 | Más de 3 MB |
| `validacion` | 422 | Datos inválidos: `details` lista cada campo y cómo corregirlo |
| `certificado_requerido`, `certificado_vencido`, `certificado_invalido`, `llave_invalida`, `llave_no_corresponde`, `certificado_ruc_no_coincide`, `certificado_no_vigente` | 422 | Problema con el certificado digital |
| `motor_no_disponible`, `motor_error` | 502 | El motor o SUNAT no respondieron: reintente |
| `tiempo_agotado` | 504 | SUNAT no respondió a tiempo: reintente en unos minutos |
| `limite_de_peticiones`, `servicio_saturado` | 429 | Demasiadas peticiones por minuto, o servicio saturado de emisiones: espere `Retry-After` segundos |
| `cuota_agotada` | 402 | Cuota mensual de documentos agotada (o plan sin producción) |
| `error_interno` | 500 | Error inesperado: cite el `request_id` |

## Limitaciones conocidas
- Un número **reservado** que nunca se envía queda como hueco en la serie (todavía no hay una operación para liberarlo).
- El límite de velocidad usa ventanas de un minuto: una ráfaga justo en el cambio de minuto puede acercarse al doble del límite durante unos segundos.
- La cuota se comprueba antes de emitir: con muchas emisiones simultáneas justo al final de la cuota puede excederse por unos pocos documentos.
- La consulta de tickets depende del servicio de SUNAT, que puede demorar o no responder (`504 tiempo_agotado`).
- Los tickets de **guías** se consultan solos hasta resolverse; los de **resumen, baja y reversión** se siguen consultando con `POST /tickets` (todavía no se resuelven solos).
- Los avisos se entregan **al menos una vez** (pueden repetirse) y sin garantía de orden entre documentos distintos.
MD;

$spec = array(
    'openapi' => '3.0.3',
    'info' => array('title' => 'API SUNAT - Facturación electrónica', 'version' => '1.0.0', 'description' => $descripcion),
    'servers' => array(array('url' => 'https://tu-dominio.com/API_SUNAT/v1', 'description' => 'Producción (reemplace por su dominio)'), array('url' => 'http://localhost/API_SUNAT/v1', 'description' => 'Local')),
    'tags' => array(array('name' => 'Sistema'), array('name' => 'Comprobantes'), array('name' => 'Resúmenes y bajas'), array('name' => 'Retenciones'), array('name' => 'Guías de remisión'),
        array('name' => 'Consultas'), array('name' => 'Series y numeración'), array('name' => 'Webhooks'), array('name' => 'Historial'), array('name' => 'Certificado')),
    'paths' => $paths,
    'components' => array('securitySchemes' => array('bearerAuth' => array('type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'sk_test_… / sk_live_…', 'description' => 'API key. `sk_test_` = pruebas (SUNAT beta), `sk_live_` = producción.')),
        'parameters' => $params, 'schemas' => $s, 'responses' => $resp),
);

// ---- Autovalidacion: todos los $ref existen, operationId unicos, ejemplos validos ----
$json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$arr = json_decode($json, true);
$errores = array();
$ids = array();
$visitar = function ($n, $ruta) use (&$visitar, $arr, &$errores) {
    if (!is_array($n)) { return; }
    if (isset($n['$ref'])) {
        $p = explode('/', substr($n['$ref'], 2));
        $x = $arr;
        foreach ($p as $k) { if (!is_array($x) || !array_key_exists($k, $x)) { $errores[] = "ref rota {$n['$ref']} en $ruta"; return; } $x = $x[$k]; }
    }
    foreach ($n as $k => $v) { $visitar($v, $ruta . '/' . $k); }
};
$visitar($arr, '');
foreach ($arr['paths'] as $ruta => $ops) {
    foreach ($ops as $m => $op) {
        if (isset($ids[$op['operationId']])) { $errores[] = 'operationId repetido ' . $op['operationId']; }
        $ids[$op['operationId']] = 1;
        if (empty($op['responses'])) { $errores[] = "sin respuestas $m $ruta"; }
        foreach (isset($op['parameters']) ? $op['parameters'] : array() as $pa) {
            if (isset($pa['in']) && $pa['in'] === 'path' && empty($pa['required'])) { $errores[] = "param path sin required en $ruta"; }
        }
    }
}
if ($errores) { fwrite(STDERR, "ERRORES:\n - " . implode("\n - ", $errores) . "\n"); exit(1); }
file_put_contents(__DIR__ . '/openapi.json', $json . "\n");
echo 'openapi.json generado: ' . count($arr['paths']) . ' rutas, ' . count($ids) . ' operaciones, ' . count($arr['components']['schemas']) . " esquemas. Referencias y operationId verificados.\n";
