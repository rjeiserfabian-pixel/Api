<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);

require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP
// NOMBRE DE ARCHIVO A PROCESAR.
//$NomArch = '20604051984-01-F001-131';
//$ruta = '../files/facturacion_electronica/FIRMA/';

$ruta = ($_GET['cod_6'] == '0') ? '../files/facturacion_electronica/FIRMA/' : '../files/facturacion_electronica/BAJA/FIRMA/' ;
//echo $ruta = '';
$NomArch = $_GET['numero_documento'];
//$NomArch = '20604051984-RA-20201228-1';

//cod_1: = 1 factura, boletas, = 9 es para guias
//cod_2: = 0 Beta, 1 Produccion
//cod_3: ruc
//cod_4: usuario secundario USU(segun seha beta o producción)
//cod_5: usuario secundario PASSWORD(segun seha beta o producción)
//cod_6: accion. 0 facturar, 1 anular
//cod_7: numero ticket
## =============================================================================
## Creación del archivo .ZIP
$zip = new PclZip($ruta.$NomArch . ".zip");

if(file_exists($ruta.$NomArch . ".zip")){
    $r = 1;
}else{
    $zip->add($ruta.$NomArch.".xml", PCLZIP_OPT_REMOVE_PATH, $ruta, PCLZIP_OPT_ADD_PATH, '');
}

//$zip->create($NomArch . ".xml");
chmod($ruta.$NomArch . ".zip", 0777);

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

if($_GET['cod_1'] == 1){
    //FACTURAS
    //$wsdlURL = ($_GET['cod_2'] == 1) ? 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService?wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'billService.wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService?wsdl';
}elseif($_GET['cod_1'] == 9){
    //GUIAS
    $wsdlURL = ($_GET['cod_2'] == 1) ? 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService?wsdl' : 'https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService?wsdl';
}

//Estructura del XML para la conexión
$XMLString = '
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
        <ser:getStatus>
            <ticket>'.$_GET['cod_7'].'</ticket>
        </ser:getStatus>
    </soapenv:Body>
</soapenv:Envelope>';
//echo $XMLString;

//echo 'SOAP de envío al servidor de SUNAT con el método sendBill.';
//echo $XMLString;
//Realizamos la llamada a nuestra función
$error_mensaje = '';
$error_existe = 0;
$result = '';
try {
    $result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);
    echo '<span style="color: #000000;">' . $result . '</span>';
    //Descargamos el Archivo Response
    
    //para la respuesta del ticket vamos a registrarla en un XML
    $archivo = fopen('C' . $NomArch . '.xml', 'w+');
    fputs($archivo, $result);
    fclose($archivo);
    
    /* LEEMOS EL ARCHIVO XML */
//    $xml = simplexml_load_file('C' . $NomArch . '.xml');
//    foreach ($xml->xpath('//applicationResponse') as $response) {
//
//    }
//    /* AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN) */
//    $cdr = base64_decode($response);
//    $archivo = fopen($ruta.'R-' . $NomArch . '.zip', 'w+');
//    fputs($archivo, $cdr);
//    fclose($archivo);
//    chmod($ruta.'R-' . $NomArch . '.zip', 0777);
//    $archive = new PclZip($ruta.'R-' . $NomArch . '.zip');
//    if ($archive->extract() == 0) {
//        die("Error : " . $archive->errorInfo(true));
//    } else {
//        chmod('R-' . $NomArch . '.xml', 0777);
//    }
//    /* Eliminamos el Archivo Response */
//    unlink('C' . $NomArch . '.xml');    
} catch (Exception $e) {
    $error_existe = 1;
    $error_mensaje = $e->getMessage();
}
//////////////////////////////////////////////////////////////////////////////
$jsondata = array(
    'success'       =>  true,
    'message'       =>  $result,
    'error_mensaje' =>  $error_mensaje,
    'error_existe'  =>  $error_existe
);
echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);