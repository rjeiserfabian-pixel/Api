<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP
//NOMBRE DE ARCHIVO A PROCESAR.
//$NomArch = '20604051984-01-F001-131';
//$ruta = '../files/facturacion_electronica/FIRMA/';

$serie = $_GET['cod_7'];
$NomArch = $_GET['numero_documento'];
//$ruta = ($_GET['cod_6'] == '0') ? '../files/facturacion_electronica/FIRMA/' : '../files/facturacion_electronica/BAJA/FIRMA/' ;
switch ($_GET['cod_6']) {
    case 1:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'sendBill';   //hacer facturas o boletas
        break;
    case 2:
        $ruta = '../files/facturacion_electronica/RESUMEN/FIRMA/';
        $metodo = 'sendSummary';    //enviar anulación
        break;
    case 3:
        $ruta = '../files/facturacion_electronica/RESUMEN/CDR/';
        $metodo = 'getStatus';  //metodo recibie ticket de anulacion
        break;
    case 4:
        $ruta = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'getStatusCdr';  //pregunta estado CDR        
        break;
}

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

switch ($_GET['cod_2']) {
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
             <wsse:Username>'.$_GET['cod_3'].$_GET['cod_4'].'</wsse:Username>
             <wsse:Password>'.$_GET['cod_5'].'</wsse:Password>
         </wsse:UsernameToken>
     </wsse:Security>
 </soapenv:Header>
 <soapenv:Body>
     <ser:'.$metodo.'>'.$content.'</ser:'. $metodo .'>
 </soapenv:Body>
</soapenv:Envelope>';
//echo $XMLString;exit;

//echo 'SOAP de envío al servidor de SUNAT con el método sendBill.';
//echo $XMLString;
//Realizamos la llamada a nuestra función
$error_mensaje = '';
$error_existe = 0;
$result = '';
$ruta_cdr = '';
$ruta_xml = '';

$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);
//var_dump($result);exit;

try {        
    
    switch ($_GET['cod_6']) {
        case '1':           
            $archivo = fopen('C' . $NomArch . '.xml', 'w+');
            fputs($archivo, $result);
            fclose($archivo);
            /* LEEMOS EL ARCHIVO XML */
            $xml = simplexml_load_file('C' . $NomArch . '.xml');
            foreach ($xml->xpath('//applicationResponse') as $response) {

            }
            /* AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN) */
            $cdr = base64_decode($response);
            $archivo = fopen($ruta.'R-' . $NomArch . '.zip', 'w+');
            fputs($archivo, $cdr);
            fclose($archivo);
            chmod($ruta.'R-' . $NomArch . '.zip', 0777);
            $archive = new PclZip($ruta.'R-' . $NomArch . '.zip');
            if ($archive->extract() == 0) {
                die("Error : " . $archive->errorInfo(true));
            } else {
                chmod($ruta.'R-' . $NomArch . '.zip', 0777);
            }
            /* Eliminamos el Archivo Response */
            unlink('C' . $NomArch . '.xml');
            break;
        case '2':
            $xml = simplexml_load_string($result);
            foreach ($xml->xpath('//ticket') as $response) {

            }                        
            $result = $response;
            break;
        case '3':
            //Respuestas del ticket
            //0 = Procesó correctamente
            //98 = En proceso
            //99 = Proceso con errores                        
            $archivo = fopen('C' . $NomArch . '.xml', 'w+');
            fputs($archivo, $result);
            fclose($archivo);
            //LEEMOS EL ARCHIVO XML
            $xml = simplexml_load_file('C' . $NomArch . '.xml');
            foreach ($xml->xpath('//content') as $response) {

            }            
            //AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN)
            $ruta = '../files/facturacion_electronica/RESUMEN/CDR/';
            $cdr = base64_decode($response);
            $archivo = fopen($ruta.'R-' . $NomArch . '.zip', 'w+');
            fputs($archivo, $cdr);
            fclose($archivo);
            chmod($ruta.'R-' . $NomArch . '.zip', 0777);
            $archive = new PclZip($ruta.'R-' . $NomArch . '.zip');
            $response = '';
            if ($archive->extract(PCLZIP_OPT_PATH, $ruta) == 0) {
                die("Error : " . $archive->errorInfo(true));
            } else {
                //chmod($ruta.'R-' . $NomArch . '.xml', 0777);                
                $resultado = leerCDR_XML($NomArch, $ruta);
                $result = $resultado['ResponseCode'];
                $mensaje = $resultado['Description'];

                $ruta_general = carpeta_actual()."/files/facturacion_electronica/RESUMEN/";
                $ruta_cdr   = $ruta_general.'CDR/R-'.$NomArch.'.xml';
                $ruta_xml   = $ruta_general.'FIRMA/'.$NomArch.'.xml';

            }
            //Eliminamos el Archivo Response
            unlink('C' . $NomArch . '.xml');            
            break;
        case '4':
            $result = ($result);
            break;
    }
} catch (Exception $e) {
    $error_existe = 1;
    $error_mensaje = $e->getMessage();
}

function leerCDR_XML($NomArch, $ruta){
    $resultado = array();
    //echo "abc";exit;        
    if(file_exists($ruta.'R-' . $NomArch . '.xml')){            
        $library = new SimpleXMLElement($ruta.'R-' . $NomArch . '.xml', null, true);

        $ns = $library->getDocNamespaces();
        $ext1 = $library->children($ns['cac']);
        $ext2 = $ext1->DocumentResponse;
        $ext3 = $ext2->children($ns['cac']);            
        $ext4 = $ext3->children($ns['cbc']);

        $resultado = array(
            'ResponseCode' => trim($ext4->ResponseCode),
            'Description' => trim($ext4->Description)
        );
    }
    return $resultado;
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
//////////////////////////////////////////////////////////////////////////////
$jsondata = array(
    'param_ver'     =>  $result,
    'mensaje'       =>  $mensaje,
    'ruta_cdr'      =>  $ruta_cdr,
    'ruta_xml'      =>  $ruta_xml,
    'success'       =>  true,    
    'error_mensaje' =>  $error_mensaje,
    'error_existe'  =>  $error_existe
);
if($_GET['cod_6'] == 2){
    $jsondata = array_merge($jsondata, array('ticket' => $result));
}

echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);