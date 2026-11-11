<?php
// Genera API_SUNAT.postman_collection.json  (php postman/gen.php)
// Los secretos NO van aqui: se usan variables de Postman ({{usuario_sol}}, {{clave_sol}}, ...).

function empresa($extra = array()) {
  return array_merge(array(
    "ruc" => "{{ruc}}", "razon_social" => "MI EMPRESA S.A.C.", "nombre_comercial" => "MI EMPRESA",
    "domicilio_fiscal" => "AV. LIMA 123", "ubigeo" => "150101",
    "departamento" => "LIMA", "provincia" => "LIMA", "distrito" => "LIMA",
    "modo" => 0,
    "usu_secundario_produccion_user" => "{{usuario_sol}}",
    "usu_secundario_produccion_password" => "{{clave_sol}}"
  ), $extra);
}
$empFact = empresa(array("cuenta_detraccion" => ""));
$empBaja = array("ruc"=>"{{ruc}}","razon_social"=>"MI EMPRESA S.A.C.","modo"=>0,"usu_secundario_user"=>"{{usuario_sol}}","usu_secundario_password"=>"{{clave_sol}}");
$empRev  = array("ruc"=>"{{ruc}}","razon_social"=>"MI EMPRESA S.A.C.","modo"=>0,"usu_secundario_produccion_user"=>"{{usuario_sol}}","usu_secundario_produccion_password"=>"{{clave_sol}}");

$cliRuc = array("codigo_tipo_entidad"=>"6","numero_documento"=>"20987654321","razon_social_nombres"=>"CLIENTE S.A.C.","cliente_direccion"=>"AV. CLIENTE 456");
$cliDni = array("codigo_tipo_entidad"=>"1","numero_documento"=>"12345678","razon_social_nombres"=>"JUAN PEREZ GARCIA","cliente_direccion"=>"AV. CLIENTE 456");
$base = array("fecha_emision"=>"{{fecha}}","hora_emision"=>"10:00:00","moneda_id"=>1,"forma_pago_id"=>1,"numero"=>"{{numero}}");
$nulls = array("total_exonerada"=>null,"total_inafecta"=>null);
$tail = array("cuotas"=>array(),"guias_adjuntas"=>array(),"anticipos"=>array());

function req($name, $path, $body, $desc, $pre) {
  return array("name"=>$name,
    "event"=>array(array("listen"=>"prerequest","script"=>array("type"=>"text/javascript","exec"=>$pre))),
    "request"=>array("method"=>"POST","header"=>array(array("key"=>"Content-Type","value"=>"application/json")),
      "body"=>array("mode"=>"raw","raw"=>json_encode($body, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"options"=>array("raw"=>array("language"=>"json"))),
      "url"=>array("raw"=>"{{base_url}}/$path","host"=>array("{{base_url}}"),"path"=>array($path)),"description"=>$desc));
}
// fecha en hora de Lima + numero correlativo de 8 digitos unico por envio
$pre = array(
  "const d=new Date(Date.now()-5*3600*1000),iso=d.toISOString();",
  "pm.collectionVariables.set('fecha',iso.slice(0,10));",
  "pm.collectionVariables.set('fecha_compacta',iso.slice(0,10).replace(/-/g,''));",
  "pm.collectionVariables.set('correlativo',String(Math.floor(Date.now()/1000)%100000));",
  "pm.collectionVariables.set('numero',String(Math.floor(Date.now()/1000)%100000000).padStart(8,'0'));"
);

$it10 = array("codigo_producto"=>"PROD001","producto"=>"PRODUCTO DE EJEMPLO","codigo_sunat"=>"50202201","cantidad"=>2,"codigo_unidad"=>"NIU","precio_base"=>50.00,"tipo_igv_codigo"=>"10","descuento_precio_base"=>0,"bolsa"=>false);
function item1($cod, $prod, $sunat, $precio) {
  return array("codigo_producto"=>$cod,"producto"=>$prod,"codigo_sunat"=>$sunat,"cantidad"=>1,"codigo_unidad"=>"NIU","precio_base"=>$precio,"tipo_igv_codigo"=>"10");
}

$items = array();

$items[] = req("01 Factura", "post.php", array_merge(array("empresa"=>$empFact,"cliente"=>$cliRuc,
  "venta"=>array_merge(array("tipo_documento_codigo"=>"01","serie"=>"F001","fecha_vencimiento"=>"{{fecha}}","total_gravada"=>100.00,"total_igv"=>18.00), $base, $nulls,
    array("total_gratuita"=>null,"total_gratuita_igv"=>null,"total_bolsa"=>null,"orden_compra"=>"OC-001","nota"=>"Observaciones opcionales","detraccion_codigo"=>"","detraccion_porcentaje"=>null,"percepcion_codigo"=>"","percepcion_porcentaje"=>null,"retencion_porcentaje"=>null)),
  "items"=>array($it10)), $tail), "Factura gravada al contado.", $pre);

$items[] = req("03 Boleta", "post.php", array_merge(array("empresa"=>$empFact,"cliente"=>$cliDni,
  "venta"=>array_merge(array("tipo_documento_codigo"=>"03","serie"=>"B001","total_gravada"=>84.75,"total_igv"=>15.25), $base, $nulls),
  "items"=>array(item1("PROD001","PRODUCTO DE EJEMPLO","50202201",84.75))), $tail), "Boleta de venta a DNI.", $pre);

$items[] = req("07 Nota de Credito", "post.php", array_merge(array("empresa"=>$empFact,"cliente"=>$cliRuc,
  "venta"=>array_merge(array("tipo_documento_codigo"=>"07","serie"=>"FC01","total_gravada"=>50.00,"total_igv"=>9.00,"relacionado_serie"=>"F001","relacionado_numero"=>"00000001","relacionado_tipo_documento"=>"01","relacionado_motivo_codigo"=>"01"), $base, $nulls),
  "items"=>array(item1("PROD001","DEVOLUCION PARCIAL","50202201",50.00))), $tail), "Referencia a la factura F001-00000001. Motivos 01 a 10.", $pre);

$items[] = req("08 Nota de Debito", "post.php", array_merge(array("empresa"=>$empFact,"cliente"=>$cliRuc,
  "venta"=>array_merge(array("tipo_documento_codigo"=>"08","serie"=>"FD01","total_gravada"=>20.00,"total_igv"=>3.60,"relacionado_serie"=>"F001","relacionado_numero"=>"00000001","relacionado_tipo_documento"=>"01","relacionado_motivo_codigo"=>"01"), $base, $nulls),
  "items"=>array(item1("SERV001","PENALIDAD POR INCUMPLIMIENTO","84111506",20.00))), $tail), "Referencia a la factura F001-00000001.", $pre);

$items[] = req("04 Liquidacion de Compra", "liquidacion.php", array(
  "empresa"=>$empFact,
  "proveedor"=>array("numero_documento"=>"12345678","nombres"=>"AGRICULTORA ROSA FLORES","direccion"=>"CALLE CAMPO 123","ubigeo"=>"120101","departamento"=>"JUNIN","provincia"=>"HUANCAYO","distrito"=>"HUANCAYO"),
  "lugar_operacion"=>array("direccion"=>"CALLE CAMPO 123","ubigeo"=>"120101","departamento"=>"JUNIN","provincia"=>"HUANCAYO","distrito"=>"HUANCAYO"),
  "venta"=>array_merge(array("tipo_documento_codigo"=>"04","serie"=>"P001","fecha_emision"=>"{{fecha}}","hora_emision"=>"10:00:00","numero"=>"{{numero}}","forma_pago_id"=>1,"total_gravada"=>200.00,"total_igv"=>36.00,"nota"=>"Compra de productos agricolas"), $nulls),
  "items"=>array(array("codigo_producto"=>"AG001","producto"=>"MAIZ AMARILLO DURO","codigo_sunat"=>"10011000","cantidad"=>100,"codigo_unidad"=>"KGM","precio_base"=>2.00,"tipo_igv_codigo"=>"10")),
  "cuotas"=>array(),"guias_adjuntas"=>array()), "Compra a persona natural sin RUC.", $pre);

$items[] = req("20 Comprobante de Retencion", "post_retencion.php", array(
  "emisor"=>empresa(array("urbanizacion"=>"URBANIZACION")),
  "proveedor"=>array("ruc"=>"20987654321","razon_social"=>"PROVEEDOR S.A.C."),
  "retencion"=>array("serie"=>"R001","numero"=>"{{numero}}","fecha_de_emision"=>"{{fecha}}","codigo"=>"01","porcentaje"=>3,"nota"=>"Retencion segun regimen","total_retenido"=>35.40,"total_facturado_menos_retenido"=>1144.60),
  "documento_relacionados"=>array(array("relacionado_tipo_documento"=>"01","relacionado_serie"=>"F001","relacionado_numero"=>"00000001","relacionado_fecha_emision"=>"{{fecha}}","relacionado_monto_total"=>1180.00,"pago_numero"=>"1","pago_monto"=>1180.00,"pago_fecha"=>"{{fecha}}","retencion_monto"=>35.40,"retencion_fecha"=>"{{fecha}}","moneda"=>"1","moneda_tipo_cambio"=>1,"moneda_fecha_cambio"=>"{{fecha}}"))
  ), "Retencion 3% (codigo 01) o 6% (codigo 02).", $pre);

$items[] = req("RA Comunicacion de Baja", "baja.php", array("empresa"=>$empBaja,"anulacion"=>array("correlativo_diario"=>"{{correlativo}}","fecha_comprobante"=>"{{fecha}}","tipo_documento_codigo"=>"01","serie"=>"F001","numero"=>"{{numero_factura}}")),
  "Solo FACTURAS (01) con menos de 7 dias; las boletas se anulan por resumen diario (status 3). Pon en {{numero_factura}} el numero de una factura ya aceptada. Devuelve un ticket. El correlativo debe ser nuevo cada vez.", $pre);

$items[] = req("RC Resumen Diario de Boletas", "resumen.php", array("empresa"=>$empBaja,
  "resumen"=>array("numero"=>"{{fecha_compacta}}","correlativo"=>"{{correlativo}}","fecha_documentos"=>"{{fecha}}","fecha_resumen"=>"{{fecha}}"),
  "comprobantes"=>array(array("tipo_documento"=>"03","serie"=>"B001","numero"=>"00000001","cliente_numero_documento"=>"12345678","cliente_tipo_documento"=>"1","total_a_pagar"=>118.00,"total_gravada"=>100.00,"total_igv"=>18.00,"total_exonerada"=>"","total_inafecta"=>"","total_gratuita"=>"","status"=>"1"))),
  "status: 1 emitido, 2 anulado, 3 error.", $pre);

$items[] = req("Consultar ticket (resumen o baja)", "resumen_ticket.php", array("empresa"=>$empBaja,"anulacion"=>array("corelativo"=>"{{correlativo}}","fecha_ticket"=>"{{fecha}}","ticket"=>"{{ticket}}")),
  "Consulta el resultado del ticket devuelto por baja o resumen. Pon el ticket en la variable {{ticket}}.", $pre);

$items[] = req("RR Reversion", "post_reversion.php", array("empresa"=>$empRev,
  "reversion"=>array("correlativo_diario"=>"{{correlativo}}","fecha_resumen"=>"{{fecha}}","fecha_documentos"=>"{{fecha}}"),
  "documento_relacionados"=>array(array("tipo_documento_codigo"=>"20","serie"=>"R001","numero"=>"{{numero_factura}}","descripcion"=>"ANULACION POR ERROR"))), "Reversion.", $pre);

$items[] = req("09 Guia de Remision Remitente", "post_guia.php", array(
  "empresa"=>empresa(array("urbanizacion"=>"","modo"=>"0","gre_client_id"=>"{{gre_client_id}}","gre_client_secret"=>"{{gre_client_secret}}")),
  "remitente"=>array("codigo_tipo_entidad"=>"6","numero_documento"=>"{{ruc}}","razon_social_nombres"=>"MI EMPRESA S.A.C."),
  "destinatario"=>array("codigo_tipo_entidad"=>"1","numero_documento"=>"12345678","razon_social_nombres"=>"JUAN PEREZ GARCIA"),
  "guia"=>array("tipo_documento_codigo"=>"09","serie"=>"T001","numero"=>"{{numero}}","fecha_emision"=>"{{fecha}}","hora_emision"=>"07:00:00","fecha_entrega_bienes_transportista"=>"{{fecha}}","fecha_inicio_traslado"=>"{{fecha}}","motivo_traslado_codigo"=>"01","peso_total"=>5.00,"unidad_peso"=>"KGM","cantidad_bultos"=>1,"direccion_partida"=>"AV. LIMA 123","ubigeo_partida"=>"150101","direccion_llegada"=>"AV. CLIENTE 456","ubigeo_llegada"=>"150101","transporte_publico"=>false,"observacion"=>""),
  "transportista"=>new stdClass,
  "vehiculos"=>array(array("placa"=>"ABC123","principal"=>true)),
  "conductores"=>array(array("tipo_documento"=>"1","numero_documento"=>"12345678","nombres"=>"JUAN","apellidos"=>"PEREZ GARCIA","licencia"=>"Q12345678","principal"=>true)),
  "items"=>array(array("codigo_producto"=>"cc1","producto"=>"PRODUCTO DE EJEMPLO","codigo_sunat"=>"43211503","cantidad"=>1,"codigo_unidad"=>"NIU")),
  "documentos_relacionados"=>array(array("tipo_documento"=>"03","serie"=>"B001","numero"=>"00000005","ruc_emisor"=>"{{ruc}}")),
  "evento"=>new stdClass), "Requiere credenciales GRE (client_id y client_secret) generadas en el portal de SUNAT.", $pre);

$col = array("info"=>array("name"=>"API SUNAT - Referencia (beta)","schema"=>"https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
  "description"=>"Casos de referencia (modo beta, empresa.modo=0). Tras cada cambio de codigo, reenviar: si SUNAT sigue aceptandolos, no se rompio nada. Completar en Postman las variables usuario_sol, clave_sol, gre_client_id y gre_client_secret; no guardarlas en el repositorio."),
 "item"=>$items,
 "variable"=>array(
   array("key"=>"base_url","value"=>"http://localhost/API_SUNAT"),array("key"=>"ruc","value"=>"20000000001"),
   array("key"=>"usuario_sol","value"=>"MODDATOS"),array("key"=>"clave_sol","value"=>"moddatos"),
   array("key"=>"gre_client_id","value"=>""),array("key"=>"gre_client_secret","value"=>""),
   array("key"=>"ticket","value"=>""),array("key"=>"numero_factura","value"=>"00000001"),array("key"=>"correlativo","value"=>"1"),array("key"=>"numero","value"=>""),array("key"=>"fecha","value"=>""),array("key"=>"fecha_compacta","value"=>"")));
file_put_contents(__DIR__."/API_SUNAT.postman_collection.json", json_encode($col, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
echo "ok: ".count($items)." peticiones\n";
