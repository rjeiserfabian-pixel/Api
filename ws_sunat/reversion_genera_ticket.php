<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);
require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP

$serie = $_GET['cod_7'];
$NomArch = $_GET['numero_documento'];

$ruta = '../files/facturacion_electronica/REVERSION/FIRMA/';

//enviar a Sunat       
//cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
//cod_2: Entorno:  0 Beta, 1 Produccion
//cod_3: ruc
//cod_4: usuario secundario USU(segun seha beta o producción)
//cod_5: usuario secundario PASSWORD(segun seha beta o producción)
//cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket  -- 4 getStatusCDR
//cod_7: tipo_documento-serie-numero
//cod_8: numero ticket

## =============================================================================

//creamos zip siempre queno recibo tickets
//echo "aa";
//echo $ruta.$NomArch . ".zip";exit;
if(($_GET['cod_6'] == 1) || ($_GET['cod_6'] == 2) ){
    ## Creación del archivo .ZIP
    $zip = new PclZip($ruta.$NomArch . ".zip");
    //var_dump($zip);exit;

    if(file_exists($ruta.$NomArch . ".zip")){
        $r = 1;
    }else{    
        $zip->add($ruta.$NomArch.".xml", PCLZIP_OPT_REMOVE_PATH, $ruta, PCLZIP_OPT_ADD_PATH, '');
    }

    //$zip->create($NomArch . ".xml");
    chmod($ruta.$NomArch . ".zip", 0777);
    //echo $ruta.$NomArch . ".zip";
    # ==============================================================================
    # Procedimiento para enviar comprobante a la SUNAT
}


switch ($_GET['cod_6']) {
    case 1:        
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($ruta.$NomArch . '.zip')) . '</contentFile>';
        break;
    case 2:        
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($ruta.$NomArch . '.zip')) . '</contentFile>';
        break;
    case 3:        
        $content = '<ticket>'.$_GET['cod_8'].'</ticket>';
        break;
    case 4:
        $documento = explode("-", $_GET['numero_documento']);
        $content= '<rucComprobante>'.$documento[0].'</rucComprobante>
        <tipoComprobante>'.$documento[1].'</tipoComprobante>
        <serieComprobante>'.$documento[2].'</serieComprobante>
        <numeroComprobante>'.$documento[3].'</numeroComprobante>';
        break;
}

switch ($_GET['cod_2']) {
    case 0:
        $url = 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService';
        break;
    case 1:
        $url = 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService';
        break;
    case 'R':
        $url = 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService?wsdl';
        break;
}

$xml_post_string='<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
<soapenv:Header>
    <wsse:Security>
        <wsse:UsernameToken>
            <wsse:Username>'.$_GET['cod_3'].$_GET['cod_4'].'</wsse:Username>
            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">' . $_GET['cod_5'] . '</wsse:Password>
        </wsse:UsernameToken>
    </wsse:Security>
</soapenv:Header>
<soapenv:Body>    
    <ser:sendSummary>
        <fileName>' . $NomArch . '.zip</fileName>
        <contentFile>' . base64_encode(file_get_contents($ruta.$NomArch . '.zip')) . '</contentFile>
    </ser:sendSummary>
</soapenv:Body>
</soapenv:Envelope>';
//echo $xml_post_string;exit;

$headers = array(
    "Content-type: text/xml;charset=\"utf-8\"",
    "Accept: text/xml",
    "Accept-Header: application/xml+",
    "Cache-Control: no-cache",
    "Pragma: no-cache",
    "SOAPAction: urn:sendBill",
    "Content-length: " . strlen($xml_post_string),
); //SOAPAction: your op URL

$ch = curl_init();
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//curl_setopt($ch, CURLOPT_USERPWD, $soapUser.":".$soapPassword); // username and password - declared at the top of the doc
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
curl_setopt($ch, CURLOPT_TIMEOUT, 3000);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// converting
$result = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

descargarRespone($NomArch, $result, $ruta_cdr);
$resultado = leerXmlResponse($NomArch, $ruta_cdr);
$ticket = xml2array ($resultado);
elminarArchivos($NomArch, $ruta, $ruta_cdr);

echo json_encode($ticket[0],JSON_NUMERIC_CHECK);
        
//Descargamos el Archivo Response
function descargarRespone($NomArch, $result, $ruta_cdr){
    $archivo = fopen($ruta_cdr.'C' . $NomArch . '.xml', 'w+');
    fputs($archivo, $result);
    fclose($archivo);
}

/* LEEMOS EL ARCHIVO XML */
function leerXmlResponse($NomArch, $ruta_cdr){
    $xml = simplexml_load_file($ruta_cdr.'C' . $NomArch . '.xml');
    foreach ($xml->xpath('//ticket') as $response) {

    }
    return $response;
}

function xml2array ($xmlObject, $out = array ()){
    foreach ( (array) $xmlObject as $index => $node )
        $out[$index] = ( is_object ( $node ) ) ? xml2array ( $node ) : $node;
    
    return $out;
}

function elminarArchivos($NomArch, $ruta, $ruta_cdr){
    /* Eliminamos el Archivo Response */
    $nombre = $ruta_cdr.'C' . $NomArch . '.xml';
    if (file_exists($nombre)) {
        unlink($nombre);
    }
    
    $nombre = $ruta_cdr.'R-' . $NomArch . '.zip';
    if (file_exists($nombre)) {
        unlink($nombre);
    }
    
    //$nombre = $ruta . $NomArch . '.zip';
    //if (file_exists($nombre)) {
    //    unlink($nombre);
    //}       
}

function carpeta_actual(){
    $archivo_actual = "http://" . $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
    $dir = explode('/', $archivo_actual);
    
    $cadena = '';
    for($i=0;  $i<(count($dir) - 2); $i++){
        $cadena .= $dir[$i]."/";
    }
    return substr($cadena, 0, -1);
}