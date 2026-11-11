<?php
header('Access-Control-Allow-Origin: *');
header("HTTP/1.1");
date_default_timezone_set("America/Lima");

$datos = file_get_contents("php://input");
$obj = json_decode($datos, true);

$empresa        = $obj['empresa'];
$anulaciones    = $obj['anulacion'];

//enviar a Sunat       
//cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
//cod_2: Entorno:  0 Beta, 1 Produccion
//cod_3: ruc
//cod_4: usuario secundario USU(segun seha beta o producción)
//cod_5: usuario secundario PASSWORD(segun seha beta o producción)
//cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket
//cod_7: serie de documento
//cod_8: numero ticket

//$ruta_dominio = "http://localhost/API_SUNAT";
require_once __DIR__ . '/libraries/env.php';
require_once __DIR__ . '/libraries/Variables_diversas_model.php';
$obj_variables_diversas_model = new variables_diversas_model();
$ruta_dominio = $obj_variables_diversas_model->carpeta_actual();
$user_sec_usu = ($empresa['modo'] == 0) ? 'MODDATOS' : $empresa['usu_secundario_user'];
$user_sec_pass = ($empresa['modo'] == 0) ? 'moddatos' : $empresa['usu_secundario_password'];


$url = $ruta_dominio."/ws_sunat/reversion_recibe_ticket.php?cod_1=1&cod_2=".$empresa['modo']."&cod_3=".$empresa['ruc']."&cod_4=".$user_sec_usu."&cod_5=".$user_sec_pass."&cod_6=3&cod_7=ABC&cod_8=".$anulaciones['ticket'];

//echo $url;exit;

$data = file_get_contents($url);        
$info = json_decode($data, TRUE);
echo json_encode($info, JSON_UNESCAPED_UNICODE);