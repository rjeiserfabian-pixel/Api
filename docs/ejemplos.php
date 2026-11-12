<?php
/**
 * Ejemplos de cuerpos JSON para la API /v1 (fuente unica).
 * Los usan: gen_openapi.php (documentacion), postman/gen_v1.php (coleccion) y docs/probar.php (prueba real).
 * Cada llamada recibe los "valores" a poner (fecha, numero...): reales, de Postman ({{fecha}}) o de muestra.
 */

function ej_cliente_ruc()
{
    return array('codigo_tipo_entidad' => '6', 'numero_documento' => '20987654321', 'razon_social_nombres' => 'CLIENTE S.A.C.', 'cliente_direccion' => 'AV. CLIENTE 456');
}

function ej_cliente_dni()
{
    return array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ GARCIA', 'cliente_direccion' => 'AV. CLIENTE 456');
}

function ej_item($producto = 'PRODUCTO DE EJEMPLO', $cantidad = 2, $precio = 50.00)
{
    return array('codigo_producto' => 'PROD001', 'producto' => $producto, 'codigo_sunat' => '50202201', 'cantidad' => $cantidad,
        'codigo_unidad' => 'NIU', 'precio_base' => $precio, 'tipo_igv_codigo' => '10');
}

function ej_cuerpos(array $v)
{
    $fecha = $v['fecha'];
    return array(
        'factura' => array(
            'cliente' => ej_cliente_ruc(),
            'venta' => array('tipo_documento_codigo' => '01', 'serie' => 'F001', 'numero' => $v['numero'], 'fecha_emision' => $fecha,
                'hora_emision' => '10:00:00', 'fecha_vencimiento' => $v['fecha_venc'], 'moneda_id' => 1, 'forma_pago_id' => 1,
                'total_gravada' => 100.00, 'total_igv' => 18.00, 'orden_compra' => 'OC-001', 'nota' => 'Observaciones opcionales'),
            'items' => array(ej_item()),
        ),
        'factura_credito' => array(
            'cliente' => ej_cliente_ruc(),
            'venta' => array('tipo_documento_codigo' => '01', 'serie' => 'F001', 'numero' => $v['numero_credito'], 'fecha_emision' => $fecha,
                'hora_emision' => '10:00:00', 'fecha_vencimiento' => $v['fecha_venc'], 'moneda_id' => 1, 'forma_pago_id' => 2,
                'total_gravada' => 100.00, 'total_igv' => 18.00),
            'cuotas' => array(array('monto' => 118.00, 'fecha_cuota' => $v['fecha_venc'])),
            'items' => array(ej_item()),
        ),
        'boleta' => array(
            'cliente' => ej_cliente_dni(),
            'venta' => array('tipo_documento_codigo' => '03', 'serie' => 'B001', 'numero' => $v['numero_boleta'], 'fecha_emision' => $fecha,
                'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 84.75, 'total_igv' => 15.25),
            'items' => array(ej_item('PRODUCTO DE EJEMPLO', 1, 84.75)),
        ),
        'nota_credito' => array(
            'cliente' => ej_cliente_ruc(),
            'venta' => array('tipo_documento_codigo' => '07', 'serie' => 'FC01', 'numero' => $v['numero_nota'], 'fecha_emision' => $fecha,
                'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 50.00, 'total_igv' => 9.00,
                'relacionado_serie' => 'F001', 'relacionado_numero' => $v['numero'], 'relacionado_tipo_documento' => '01', 'relacionado_motivo_codigo' => '01'),
            'items' => array(ej_item('DEVOLUCION PARCIAL', 1, 50.00)),
        ),
        'nota_debito' => array(
            'cliente' => ej_cliente_ruc(),
            'venta' => array('tipo_documento_codigo' => '08', 'serie' => 'FD01', 'numero' => $v['numero_nota'], 'fecha_emision' => $fecha,
                'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 20.00, 'total_igv' => 3.60,
                'relacionado_serie' => 'F001', 'relacionado_numero' => $v['numero'], 'relacionado_tipo_documento' => '01', 'relacionado_motivo_codigo' => '01'),
            'items' => array(array('codigo_producto' => 'SERV001', 'producto' => 'PENALIDAD POR INCUMPLIMIENTO', 'codigo_sunat' => '84111506',
                'cantidad' => 1, 'codigo_unidad' => 'NIU', 'precio_base' => 20.00, 'tipo_igv_codigo' => '10')),
        ),
        'resumen' => array(
            'resumen' => array('numero' => $v['fecha_compacta'], 'correlativo' => $v['correlativo'], 'fecha_documentos' => $fecha, 'fecha_resumen' => $fecha),
            'comprobantes' => array(array('tipo_documento' => '03', 'serie' => 'B001', 'numero' => $v['numero_boleta'],
                'cliente_numero_documento' => '12345678', 'cliente_tipo_documento' => '1', 'total_a_pagar' => 100.00,
                'total_gravada' => 84.75, 'total_igv' => 15.25, 'total_exonerada' => '', 'total_inafecta' => '', 'total_gratuita' => '', 'status' => '1')),
        ),
        'baja' => array(
            'anulacion' => array('correlativo_diario' => $v['correlativo'], 'fecha_comprobante' => $fecha, 'tipo_documento_codigo' => '01',
                'serie' => 'F001', 'numero' => $v['numero_credito']),
        ),
        'retencion' => array(
            'proveedor' => array('ruc' => '20987654321', 'razon_social' => 'PROVEEDOR S.A.C.'),
            'retencion' => array('serie' => 'R001', 'numero' => $v['numero_retencion'], 'fecha_de_emision' => $fecha, 'codigo' => '01', 'porcentaje' => 3,
                'nota' => 'Retencion segun regimen', 'total_retenido' => 35.40, 'total_facturado_menos_retenido' => 1144.60),
            'documento_relacionados' => array(array('relacionado_tipo_documento' => '01', 'relacionado_serie' => 'F001', 'relacionado_numero' => '00000001',
                'relacionado_fecha_emision' => $fecha, 'relacionado_monto_total' => 1180.00, 'pago_numero' => '1', 'pago_monto' => 1180.00,
                'pago_fecha' => $fecha, 'retencion_monto' => 35.40, 'retencion_fecha' => $fecha, 'moneda' => '1', 'moneda_tipo_cambio' => 1,
                'moneda_fecha_cambio' => $fecha)),
        ),
        'reversion' => array(
            'reversion' => array('correlativo_diario' => $v['correlativo'], 'fecha_resumen' => $fecha, 'fecha_documentos' => $fecha),
            'documento_relacionados' => array(array('tipo_documento_codigo' => '20', 'serie' => 'R001', 'numero' => $v['numero_retencion'],
                'descripcion' => 'ANULACION POR ERROR')),
        ),
        'guia' => array(
            'destinatario' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ GARCIA'),
            'guia' => array('serie' => 'T001', 'numero' => $v['numero_guia'], 'fecha_emision' => $fecha, 'hora_emision' => '07:00:00',
                'fecha_entrega_bienes_transportista' => $fecha, 'fecha_inicio_traslado' => $fecha, 'motivo_traslado_codigo' => '01',
                'peso_total' => 5.00, 'unidad_peso' => 'KGM', 'cantidad_bultos' => 1, 'direccion_partida' => 'AV. LIMA 123', 'ubigeo_partida' => '150101',
                'direccion_llegada' => 'AV. CLIENTE 456', 'ubigeo_llegada' => '150101', 'transporte_publico' => false, 'observacion' => ''),
            'vehiculos' => array(array('placa' => 'ABC123', 'principal' => true)),
            'conductores' => array(array('tipo_documento' => '1', 'numero_documento' => '12345678', 'nombres' => 'JUAN', 'apellidos' => 'PEREZ GARCIA',
                'licencia' => 'Q12345678', 'principal' => true)),
            'items' => array(array('codigo_producto' => 'cc1', 'producto' => 'PRODUCTO DE EJEMPLO', 'codigo_sunat' => '43211503', 'cantidad' => 1, 'codigo_unidad' => 'NIU')),
            'documentos_relacionados' => array(array('tipo_documento' => '03', 'serie' => 'B001', 'numero' => $v['numero_boleta'], 'ruc_emisor' => '20000000001')),
        ),
        'boleta_auto' => array(
            'cliente' => ej_cliente_dni(),
            'venta' => array('tipo_documento_codigo' => '03', 'serie' => $v['serie_auto'], 'fecha_emision' => $fecha,
                'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 84.75, 'total_igv' => 15.25),
            'items' => array(ej_item('PRODUCTO DE EJEMPLO', 1, 84.75)),
        ),
        'serie_api' => array('tipo' => '03', 'serie' => $v['serie_auto'], 'modo' => 'api', 'ultimo_numero' => 0),
        'serie_cliente' => array('tipo' => '03', 'serie' => 'B002', 'modo' => 'cliente', 'ultimo_numero' => 150),
        'reservar' => array('tipo' => '03', 'serie' => $v['serie_auto'], 'cantidad' => 2),
        'ticket_guia' => array('tipo' => 'guia', 'ticket' => $v['ticket_guia']),
        'ticket_resumen' => array('tipo' => 'resumen', 'ticket' => $v['ticket'], 'correlativo' => $v['correlativo'], 'fecha_ticket' => $fecha),
        'ticket_baja' => array('tipo' => 'baja', 'ticket' => $v['ticket']),
        'certificado_pfx' => array('pfx_base64' => $v['pfx_base64'], 'password' => 'contrasena-del-pfx'),
        'certificado_pem' => array('cert_pem' => "-----BEGIN CERTIFICATE-----\n...\n-----END CERTIFICATE-----", 'key_pem' => "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"),
    );
}

/** Valores de muestra para la documentacion. */
function ej_muestra()
{
    return array('fecha' => '2026-11-11', 'fecha_venc' => '2026-12-11', 'fecha_compacta' => '20261111', 'numero' => '00000001', 'numero_credito' => '00000002', 'numero_boleta' => '00000001',
        'numero_nota' => '00000001', 'numero_retencion' => '00000001', 'numero_guia' => '00000001', 'correlativo' => '1',
        'serie_auto' => 'B001', 'ticket' => '1794437855236', 'ticket_guia' => '50c6032c-a307-444d-8ffa-c86208ba91fa', 'pfx_base64' => 'MIIJ...(archivo .pfx en base64)');
}
