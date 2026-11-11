<?php
header('Access-Control-Allow-Origin: *');
header("HTTP/1.1");
date_default_timezone_set("America/Lima");

require 'libraries/Variables_diversas_model.php';

$datos = file_get_contents("php://input");
$obj = json_decode($datos, true);

$empresa        = $obj['empresa'];
$anulaciones    = $obj['anulacion'];

$corelativo = $anulaciones['corelativo'];

$fecha_anulacion = substr($anulaciones['fecha_ticket'], 0, 4).substr($anulaciones['fecha_ticket'], 5, 2).substr($anulaciones['fecha_ticket'], 8, 2);
$nombre_archivo = $empresa['ruc'].'-RC-'.$fecha_anulacion.'-'.$corelativo;

//enviar a Sunat       
//cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
//cod_2: Entorno:  0 Beta, 1 Produccion
//cod_3: ruc
//cod_4: usuario secundario USU(segun seha beta o producción)
//cod_5: usuario secundario PASSWORD(segun seha beta o producción)
//cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket
//cod_7: serie de documento
//cod_8: numero ticket

$obj_variables_diversas_model = new variables_diversas_model();
$ruta_dominio   = $obj_variables_diversas_model->carpeta_actual();

$user_sec_usu = ($empresa['modo'] == 0) ? 'MODDATOS' : $empresa['usu_secundario_user'];
$user_sec_pass = ($empresa['modo'] == 0) ? 'moddatos' : $empresa['usu_secundario_password'];
$url = $ruta_dominio."/ws_sunat/index_resumen_ticket.php?numero_documento=".$nombre_archivo."&cod_1=1&cod_2=".$empresa['modo']."&cod_3=".$empresa['ruc']."&cod_4=".$user_sec_usu."&cod_5=".$user_sec_pass."&cod_6=3&cod_7=ABC&cod_8=".$anulaciones['ticket'];
//echo $url;exit;

$data = file_get_contents($url);        
$info = json_decode($data, TRUE);
//var_dump($info);exit;
$respuesta_codigo = '';
$respuesta_mensaje = '';

$jsondata = array(
    'success'       =>  true,
    'codigo'        =>  $respuesta_codigo,    
    'error_existe'  =>  $info['error_existe'],
    'ruta_cdr'      =>  $info['ruta_cdr'],
    'ruta_xml'      =>  $info['ruta_xml'],    
    'message'       =>  $info['param_ver'],
    'mensaje'       =>  $info['mensaje']
);
echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);