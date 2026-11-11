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

## =============================================================================

//creamos zip siempre queno recibo tickets
//echo "aa";
//echo $ruta.$NomArch . ".zip";exit;

if(($_GET['cod_6'] == 1) || ($_GET['cod_6'] == 2) ){
    if (ob_get_length()) ob_clean();
    
    $rutaZip = $ruta.$NomArch.".zip";
    $rutaXml = $ruta.$NomArch.".xml";
    
    // BORRAR ZIP SI EXISTE (OBLIGATORIO)
    if (file_exists($rutaZip)) {
        unlink($rutaZip);
    }
    
    // VALIDAR XML
    if (!file_exists($rutaXml) || filesize($rutaXml) == 0) {
        die("XML inválido o vacío: ".$rutaXml);
    }
    
    // CREAR ZIP DESDE CERO
    $zip = new PclZip($rutaZip);
    $result = $zip->add(
        $rutaXml,
        PCLZIP_OPT_REMOVE_PATH,
        $ruta
    );
    
    if ($result == 0) {
        die("ZIP ERROR: " . $zip->errorInfo(true));
    }
    
    chmod($rutaZip, 0777);
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

switch ($_GET['cod_2']) {
    case 0:
        $url = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService';
        //$url = 'https://e-beta.sunat.gob.pe/ol-ti-itemision-otroscpe-gem-beta/billService';
        break;
    case 1:
        //$url = 'https://e-factura.sunat.gob.pe/ol-ti-itemisionotroscpe-gem/billService';
        $url = 'https://e-factura.sunat.gob.pe/ol-ti-itcpfegem/billService?wsdl';
        break; 
    case 2:
        $wsdlURL = 'URL_OSE';
        break;
}

//Estructura del XML para la conexión
$xml_post_string = '<?xml version="1.0" encoding="UTF-8"?>
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

// PHP cURL  for https connection with auth
$ch = curl_init();
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//curl_setopt($ch, CURLOPT_USERPWD, $soapUser.":".$soapPassword); // username and password - declared at the top of the doc
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
curl_setopt($ch, CURLOPT_TIMEOUT, 33000);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// converting
$result = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
  
//echo $result;exit;
//echo $httpcode;
//echo $response;
//exit;


// =============================================================================
// ===== INICIO: BLOQUE PARA CAPTURAR ERRORES (SOAP FAULT) =====================
// =============================================================================

// Verificamos si la respuesta de SUNAT es un error (SOAP Fault)
if (strpos($result, 'faultcode') !== false && strpos($result, 'faultstring') !== false) {
    // La respuesta es un error, procedemos a leerla.
    
    // SimpleXML puede tener problemas con los prefijos 'soap-env:', así que los podemos reemplazar o usar xpath.
    // Para simplificar, vamos a parsear el XML directamente.
    libxml_use_internal_errors(true); // Suprime advertencias de malformación de XML si las hubiera.
    $xml_error = simplexml_load_string($result);

    if ($xml_error !== false) {
        // Buscamos los nodos faultcode y faultstring dentro del cuerpo del SOAP
        $faultcode = (string) $xml_error->xpath('//faultcode')[0];
        $faultstring = (string) $xml_error->xpath('//faultstring')[0];

        $respuesta_error = array(
            'error'                         => "true", // Agregamos una bandera para saber que es un error
            'respuesta_sunat_codigo'        => trim($faultcode),
            'respuesta_sunat_descripcion'   => trim($faultstring)
        );

    } else {
        // Si no se pudo parsear el XML, devolvemos el error en crudo.
        $respuesta_error = array(
            'error'                         => "true",
            'respuesta_sunat_codigo'        => 'PARSE_ERROR',
            'respuesta_sunat_descripcion'   => 'No se pudo leer la respuesta de error de SUNAT.',
            'raw_response'                  => $result
        );
    }
    
    // Devolvemos el error en formato JSON y terminamos la ejecución del script.
    echo json_encode($respuesta_error);
    exit; // ¡Muy importante! Detenemos el script aquí.
}

// =============================================================================
// ===== FIN: BLOQUE PARA CAPTURAR ERRORES (SOAP FAULT) ========================
// =============================================================================





//Realizamos la llamada a nuestra función
$ruta_cdr = '../files/facturacion_electronica/CDR/';


descargarRespone($NomArch, $result, $ruta_cdr);
$response = leerXmlResponse($NomArch, $ruta_cdr);
descargarCDR_ZIP($NomArch, $response, $ruta_cdr);
obtenerCDR_XML($NomArch, $ruta_cdr);
$respuesta = leerCDR_XML($NomArch, $ruta, $ruta_cdr);
elminarArchivos($NomArch, $ruta, $ruta_cdr);

echo json_encode($respuesta);EXIT;

//Descargamos el Archivo Response
function descargarRespone($NomArch, $result, $ruta_cdr){
    $archivo = fopen($ruta_cdr.'C' . $NomArch . '.xml', 'w+');
    fputs($archivo, $result);
    fclose($archivo);
}

/* LEEMOS EL ARCHIVO XML */
function leerXmlResponse($NomArch, $ruta_cdr){
    $xml = simplexml_load_file($ruta_cdr.'C' . $NomArch . '.xml');
    foreach ($xml->xpath('//applicationResponse') as $response) {

    }
    return $response;
}

/* AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN) */
function descargarCDR_ZIP($NomArch, $response, $ruta_cdr){
    $cdr = base64_decode($response);
    $archivo = fopen($ruta_cdr.'R-' . $NomArch . '.zip', 'w+');
    fputs($archivo, $cdr);
    fclose($archivo);
    chmod($ruta_cdr.'R-' . $NomArch . '.zip', 0777);
}

function obtenerCDR_XML($NomArch, $ruta_cdr){
    $archive = new PclZip($ruta_cdr.'R-' . $NomArch . '.zip');
    if ($archive->extract(PCLZIP_OPT_PATH, $ruta_cdr) == 0) {
        die("Error : " . $archive->errorInfo(true));
    } else {
        //chmod($ruta.'R-' . $NomArch . '.xml', 0777);
    }
}

function leerCDR_XML($NomArch, $ruta, $ruta_cdr){
    $resultado = array();
    //$ruta_general = "https://" . $_SERVER["SERVER_NAME"]. "/API_SUNAT/files/facturacion_electronica/";
    $ruta_general = carpeta_actual()."/files/facturacion_electronica/";
    //echo "abc";exit;        
    if(file_exists($ruta_cdr.'R-' . $NomArch . '.xml')){            
        $library = new SimpleXMLElement($ruta_cdr.'R-' . $NomArch . '.xml', null, true);

        $ns = $library->getDocNamespaces();
        $ext1 = $library->children($ns['cac']);
        $ext2 = $ext1->DocumentResponse;
        $ext3 = $ext2->children($ns['cac']);            
        $ext4 = $ext3->children($ns['cbc']);
        
        $codigo_hash = getFirma($NomArch, $ruta);
        $codigo_hash = xml2array($codigo_hash);
        $codigo_hash = $codigo_hash[0];
        
        $xml_base_64 = base64_encode(file_get_contents($ruta.$NomArch . '.zip'));
        $cdr_base_64 = base64_encode(file_get_contents($ruta_cdr.'R-' . $NomArch . '.zip'));

        $resultado = array(
            'error'                         => "false",
            
            'respuesta_sunat_codigo'        => trim($ext4->ResponseCode),
            'respuesta_sunat_descripcion'   => trim($ext4->Description),
            
            'ruta_xml'  => $ruta_general.'FIRMA/'.$NomArch.'.xml',
            'ruta_cdr'  => $ruta_general.'CDR/R-' .$NomArch.'.xml',
            'ruta_pdf'  => $ruta_general.'PDF/'.$NomArch.'.pdf',
            
            'codigo_hash'   =>  $codigo_hash,
            'xml_base_64'   =>  $xml_base_64,
            'cdr_base_64'   =>  $cdr_base_64
        );
    }
    return $resultado;
}

function xml2array ( $xmlObject, $out = array () )
{
    foreach ( (array) $xmlObject as $index => $node )
        $out[$index] = ( is_object ( $node ) ) ? xml2array ( $node ) : $node;

    return $out;
}


function getFirma($NomArch, $ruta){
    $xml    = simplexml_load_file($ruta. $NomArch . '.xml');
    foreach ($xml->xpath('//ds:DigestValue') as $response) {

    }
    return $response;
}

function elminarArchivos($NomArch, $ruta, $ruta_cdr){
    /* Eliminamos el Archivo Response */
    unlink($ruta_cdr.'C' . $NomArch . '.xml');
    unlink($ruta_cdr.'R-' . $NomArch . '.zip');
    unlink($ruta . $NomArch . '.zip');
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