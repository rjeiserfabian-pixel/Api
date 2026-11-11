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
        $ruta = '../files/facturacion_electronica/BAJA/FIRMA/';
        $metodo = 'sendSummary';    //enviar anulación
        break;
    case 3:
        $ruta = '../files/facturacion_electronica/BAJA/RPTA/';
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
//cod_9: 0: sin OSE //  1: con OSE
//cod_10: Entorno OSE:  0 Beta, 1 Produccion
//cod_11: WS OSE
## =============================================================================

//creamos zip siempre queno recibo tickets
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

if($_GET['cod_1'] == 1){
    //FACTURAS
    //$wsdlURL = ($_GET['cod_2'] == 1) ? 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService?wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'billService.wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
}elseif($_GET['cod_1'] == 9){
    //GUIAS
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService?wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService?wsdl';
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

function getBetween($string, $start = "", $end = ""){
    if (strpos($string, $start)) { // required if $start not exist in $string
        $startCharCount = strpos($string, $start) + strlen($start);
        $firstSubStr = substr($string, $startCharCount, strlen($string));
        $endCharCount = strpos($firstSubStr, $end);
        if ($endCharCount == 0) {
            $endCharCount = strlen($firstSubStr);
        }
        return substr($firstSubStr, 0, $endCharCount);
    } else {
        return '';
    }
}

function buscoTicket($result){
    $library = new SimpleXMLElement($result);
    $ns = $library->getDocNamespaces();
    $ext1 = $library->children($ns['soapenv']);
    $ext2 = $ext1->Body;            
    $ext3 = $ext2->children($ns['ser']);
    $ext4 = $ext3->sendSummaryResponse;        
    $ext5 = $ext4->children();
    $ticket = $ext5->ticket;

    //var_dump($ext5);exit;
    return ($ticket[0]);
}

//echo 'SOAP de envío al servidor de SUNAT con el método sendBill.';
//echo $XMLString;
//Realizamos la llamada a nuestra función
$error_mensaje = '';
$error_existe = 0;
$result = '';

$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);    

try {        
    
    //$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);    
    //echo $result;exit;
    //echo "cc";exit;
    //echo "abcd";exit;
    
    //echo '<span style="color: #000000;">' . $result . '</span>';
    //Descargamos el Archivo Response
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
            $result = buscoTicket($result);    
            break;
        case '3':
            //Respuestas del ticket
            //0 = Procesó correctamente
            //98 = En proceso
            //99 = Proceso con errores
            $archivo = fopen('C' . $NomArch . '.xml', 'w+');
            fputs($archivo, $result);
            fclose($archivo);
            /* LEEMOS EL ARCHIVO XML */
            $xml = simplexml_load_file('C' . $NomArch . '.xml');
            foreach ($xml->xpath('//content') as $response) {

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
                chmod('R-' . $NomArch . '.xml', 0777);
            }
            /* Eliminamos el Archivo Response */
            //unlink('C' . $NomArch . '.xml');
            break;
        case '4':
            $result = ($result);    
            break;
    } 
} catch (Exception $e) {
    $error_existe = 1;
    $error_mensaje = $e->getMessage();
}
//////////////////////////////////////////////////////////////////////////////
$jsondata = array(
    'param_ver'     =>  $result,
    'success'       =>  true,    
    'error_mensaje' =>  $error_mensaje,
    'error_existe'  =>  $error_existe
);
if($_GET['cod_6'] == 2){
    $jsondata = array_merge($jsondata, array('ticket' => $result));
}

echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);