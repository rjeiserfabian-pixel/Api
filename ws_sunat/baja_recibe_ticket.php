<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);
require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP

$NomArch = $_GET['cod_8'];//numero de ticket

$ruta = '../files/facturacion_electronica/BAJA/FIRMA/';
$ruta_cdr = '../files/facturacion_electronica/BAJA/CDR/';

//enviar a Sunat       
//cod_1: Entorno:  0 Beta, 1 Produccion
//cod_2: ruc
//cod_3: usuario secundario USU(segun seha beta o producción)
//cod_4: usuario secundario PASSWORD(segun seha beta o producción)
//cod_5: numero ticket

## =============================================================================
switch ($_GET['cod_1']) {
    case 0:
        $url = 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService';
        break;
    case 1:
        $url = 'https://e-factura.sunat.gob.pe/ol-ti-itemision-otroscpe-gem/billService';
        break;
}

//$xml_post_string='<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:SOAP-ENV="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">;

$xml_post_string = '<?xml version="1.0" encoding="UTF-8"?>
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
    <ser:getStatus>
        <ticket>'.$_GET['cod_5'].'</ticket>
    </ser:getStatus>
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

//echo $result;exit;

//Respuestas del ticket
//0 = Procesó correctamente
//98 = En proceso
//99 = Proceso con errores                        
$archivo = fopen('CV' . $NomArch . '.xml', 'w+');
fputs($archivo, $result);
fclose($archivo);
//LEEMOS EL ARCHIVO XML
$xml = simplexml_load_file('CV' . $NomArch . '.xml');
foreach ($xml->xpath('//statusCode') as $response_status) {

}                

foreach ($xml->xpath('//content') as $response) {

}            

if(($response_status != "0") && ($response_status != "99")){
    //var_dump($response_status);exit;
    //$xml = simplexml_load_string($response_status);
    //var_dump($xml);exit;

    $resultado['statusCode']  = $response_status;
    $resultado['content']  = $response;
    $resultado['neple']  = "abc";
}else{
    //AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN)
    //echo $ruta_cdr . $NomArch . '.zip';exit;
    
    file_put_contents($ruta_cdr. $NomArch . '.zip', base64_decode($response));
    
    chmod($ruta_cdr . $NomArch . '.zip', 0777);
    $response = '';
    
    //extraemos archivo zip a xml
    $zip = new ZipArchive;
    if ($zip->open($ruta_cdr . $NomArch . '.zip') === TRUE) {
        $zip->extractTo($ruta_cdr);
        $nombre_cdr = $zip->statIndex(0)['name'];
        $nombre_cdr = ($nombre_cdr === "dummy/") ? $zip->statIndex(1)['name'] : $nombre_cdr;
        $zip->close();
        
        chmod($ruta_cdr . $nombre_cdr, 0777);
        $ruta_general = carpeta_actual()."/files/facturacion_electronica/BAJA/";
        
        $resultado              = leerCDR_XML($nombre_cdr, $ruta_cdr);
        $resultado['ruta_cdr']  = $ruta_general.'CDR/'.$nombre_cdr;
    }
    //chmod($ruta_cdr . $nombre_cdr, 0777);
    
}
//Eliminamos el Archivo Response
//unlink('CV' . $NomArch . '.xml');
//unlink($ruta_cdr . $NomArch . '.zip');

$jsondata = $resultado;

function leerCDR_XML($nombre_cdr, $ruta){
    $resultado = array();
    if(file_exists($ruta . $nombre_cdr)){
        $library = new SimpleXMLElement($ruta . $nombre_cdr, null, true);

        $ns = $library->getDocNamespaces();
        $ext1 = $library->children($ns['cac']);
        $ext2 = $ext1->DocumentResponse;
        $ext3 = $ext2->children($ns['cac']);
        $ext4 = $ext3->children($ns['cbc']);

        $resultado = array(
            'respuesta_sunat_codigo' => trim($ext4->ResponseCode),
            'respuesta_sunat_descripcion' => trim($ext4->Description)
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

echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);