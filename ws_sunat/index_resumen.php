<?php
require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP
error_reporting(E_ERROR | E_WARNING | E_PARSE);

$ruta = "../files/facturacion_electronica/RESUMEN/FIRMA/";
$ruta_cdr = "../files/facturacion_electronica/RESUMEN/CDR/";

$NomArch = $_GET['numero_documento'];
//enviar a Sunat       
//cod_1: Entorno:  0 Beta, 1 Produccion
//cod_2: ruc
//cod_3: usuario secundario USU(segun seha beta o producción)
//cod_4: usuario secundario PASSWORD(segun seha beta o producción)
## =============================================================================

## Creación del archivo .ZIP
//chmod($ruta.$NomArch . ".zip", 0777);
$zip = new PclZip($ruta.$NomArch . ".zip");
if(file_exists($ruta.$NomArch . ".zip")){    
    $r = 1;
}else{        
    $zip->add($ruta.$NomArch.".xml", PCLZIP_OPT_REMOVE_PATH, $ruta, PCLZIP_OPT_ADD_PATH, '');    
} 
//exit;
//$zip->create($NomArch . ".xml");

//echo $ruta.$NomArch . ".zip";
# ==============================================================================
# Procedimiento para enviar comprobante a la SUNAT

class feedSoap extends SoapClient {

    public $XMLStr = "";

    public function setXMLStr($value) {
        $this->XMLStr = $value;
    }

    public function getXMLStr() {
        return $this->XMLStr;
    }

    public function __doRequest($request, $location, $action, $version, $one_way = 0) {
        $request = $this->XMLStr;
        $dom = new DOMDocument('1.0');
        try {
            $dom->loadXML($request);
        } catch (DOMException $e) {
            die($e->code);
        }
        $request = $dom->saveXML();
        //Solicitud
        return parent::__doRequest($request, $location, $action, $version, $one_way = 0);
    }

    public function SoapClientCall($SOAPXML) {
        return $this->setXMLStr($SOAPXML);
    }

}

function soapCall($wsdlURL, $callFunction = "", $XMLString) {
    $client = new feedSoap($wsdlURL, array('trace' => true));
    $reply = $client->SoapClientCall($XMLString);
    //echo "REQUEST:\n" . $client->__getFunctions() . "\n";
    $client->__call("$callFunction", array(), array());
    //$request = prettyXml($client->__getLastRequest());
    //echo highlight_string($request, true) . "<br/>\n";
    return $client->__getLastResponse();
}

switch ($_GET['cod_1']) {
    case 0:
        $wsdlURL = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
        break;
    case 1:
        $wsdlURL = 'billService.wsdl';
        break;
}

//Estructura del XML para la conexión
$XMLString = '<?xml version="1.0" encoding="UTF-8"?>
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
    <soapenv:Header>
        <wsse:Security>
            <wsse:UsernameToken>
                <wsse:Username>'.$_GET['cod_2'].$_GET['cod_3'].'</wsse:Username>
                <wsse:Password>'.$_GET['cod_4'].'</wsse:Password>
            </wsse:UsernameToken>
        </wsse:Security>
    </soapenv:Header>
    <soapenv:Body>
        <ser:sendSummary>
            <fileName>'. $NomArch .'.zip</fileName>
            <contentFile>'. base64_encode(file_get_contents($ruta.$NomArch . '.zip')) .'</contentFile>
        </ser:sendSummary>
    </soapenv:Body>
</soapenv:Envelope>';
//echo $XMLString;exit;

$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);

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