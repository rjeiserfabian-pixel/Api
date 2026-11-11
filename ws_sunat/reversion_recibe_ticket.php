<?php
error_reporting(E_ERROR | E_WARNING | E_PARSE);
require('lib/pclzip.lib.php'); // Librería que comprime archivos en .ZIP

$serie = $_GET['cod_7'];
$NomArch = $_GET['cod_8'];//numero de ticket

$ruta = '../files/facturacion_electronica/REVERSION/FIRMA/';
$ruta_cdr = '../files/facturacion_electronica/REVERSION/CDR/';

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
        $metodo = 'sendBill';   //hacer facturas o boletas
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($ruta.$NomArch . '.zip')) . '</contentFile>';
        break;
    case 2:
        $metodo = 'sendSummary';    //enviar anulación
        $content = '<fileName>' . $NomArch . '.zip</fileName><contentFile>' . base64_encode(file_get_contents($ruta.$NomArch . '.zip')) . '</contentFile>';
        break;
    case 3:
        $metodo = 'getStatus';  //metodo recibie ticket de anulacion
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
$archivo = fopen('C' . $NomArch . '.xml', 'w+');
fputs($archivo, $result);
fclose($archivo);
//LEEMOS EL ARCHIVO XML
$xml = simplexml_load_file('C' . $NomArch . '.xml');
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
    file_put_contents($ruta_cdr. $NomArch . '.zip', base64_decode($response));
    
    chmod($ruta_cdr . $NomArch . '.zip', 0777);
    $response = '';
    
    //extraemos archivo zip a xml
    $zip = new ZipArchive;
    if ($zip->open($ruta_cdr . $NomArch . '.zip') === TRUE) {
        $zip->extractTo($ruta_cdr);
        $nombre_cdr = $zip->statIndex(1)['name'];
        $zip->close();
        
        chmod($ruta_cdr . $nombre_cdr, 0777);
        $ruta_general = carpeta_actual()."/files/facturacion_electronica/REVERSION/";
        
        //echo "nombre_cdr:".$nombre_cdr."<br>";
        //echo "ruta_cdr:".$ruta_cdr;exit;
        
        $resultado              = leerCDR_XML($nombre_cdr, $ruta_cdr);
        $resultado['ruta_cdr']  = $ruta_general.'CDR/'.$nombre_cdr;
    }
}
//Eliminamos el Archivo Response
//unlink('C' . $NomArch . '.xml');
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
