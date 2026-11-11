<?php
declare(strict_types=1);

/*
 |------------------------------------------------------------
 | CONFIGURACIÓN GENERAL
 |------------------------------------------------------------
 */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once 'lib/pclzip.lib.php';


$cod6  = $_GET['cod_6'] ?? null;
$NomArch = $_GET['numero_documento'] ?? null;

switch ((int)$cod6) {
    case 1:
        $ruta   = '../files/facturacion_electronica/FIRMA/';
        $metodo = 'sendBill';
        break;

    case 2:
        $ruta   = '../files/facturacion_electronica/BAJA/FIRMA/';
        $metodo = 'sendSummary';
        break;

    default:
        echo json_encode([
            'success' => false,
            'mensaje' => 'Tipo no válido'
        ]);
        exit;
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
if(($_GET['cod_6'] == 1) || ($_GET['cod_6'] == 2) ){
    ## Creación del archivo .ZIP
    $zip = new PclZip($ruta.$NomArch . ".zip");

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

    #[\ReturnTypeWillChange]
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
//$result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);    

//echo $result;exit;

try{
    $result = soapCall($wsdlURL, $callFunction = "sendBill", $XMLString);    
    //echo json_encode($result);EXIT;
}catch (Exception $e) {
    $resultado = array('error' => $e->getMessage());
    echo json_encode($resultado);EXIT;
}

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
        $archivo = fopen($ruta.'R-' . $NomArch . '.ZIP', 'w+');
        fputs($archivo, $cdr);
        fclose($archivo);
        chmod($ruta.'R-' . $NomArch . '.ZIP', 0777);
        $archive = new PclZip($ruta.'R-' . $NomArch . '.zip');
        if ($archive->extract() == 0) {
            die("Error : " . $archive->errorInfo(true));
        } else {
            chmod($ruta.'R-' . $NomArch . '.ZIP', 0777);
            unlink($ruta . $NomArch . '.ZIP');
        }
        /* Eliminamos el Archivo Response */
        //unlink('C' . $NomArch . '.xml');
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
        foreach ($xml->xpath('//statusCode') as $response_status) {

        }                
        
        foreach ($xml->xpath('//content') as $response) {

        }            

        if($response_status != "0"){            
            //var_dump($response_status);exit;
            //$xml = simplexml_load_string($response_status);
            //var_dump($xml);exit;

            $resultado['statusCode']  = $response_status;
            $resultado['content']  = $response;
            $resultado['neple']  = "abc";
        }else{
            //AQUI DESCARGAMOS EL ARCHIVO CDR(CONSTANCIA DE RECEPCIÓN)
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
                chmod($ruta.'R-' . $NomArch . '.xml', 0777);
                $ruta_general = carpeta_actual()."/files/facturacion_electronica/BAJA/";
                
                $resultado              = leerCDR_XML($NomArch, $ruta);                
                $resultado['ruta_xml']  = $ruta_general.'FIRMA/'.$NomArch.'.xml';
                $resultado['ruta_cdr']  = $ruta_general.'CDR/R-' .$NomArch.'.xml';            
            }
        }        
        //Eliminamos el Archivo Response
        //unlink('C' . $NomArch . '.xml');            
        break;
    case '4':
        $result = ($result);
        break;
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
            'respuesta_sunat_codigo' => trim($ext4->ResponseCode),
            'respuesta_sunat_descripcion' => trim($ext4->Description)
        );
    }
    return $resultado;
}
//////////////////////////////////////////////////////////////////////////////
if($_GET['cod_6'] == 2){
    $jsondata = array(
        'param_ver'     =>  $result,
        'mensaje'       =>  $mensaje,
        'success'       =>  true,    
        'error_mensaje' =>  $error_mensaje,
        'error_existe'  =>  $error_existe
    );
    $jsondata = array_merge($jsondata, array('ticket' => $result));
}else{
    $jsondata = $resultado;    
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

function crear_pdf($empresa,$anulaciones,$nombre_archivo_pdf,$nombre_archivo,$obj_variables_diversas_model)
{
    
    $folder = 'files/facturacion_electronica/BAJA/PDF/';
    if (!file_exists($folder)) {
        mkdir($folder, 0777, true);
    }
    
    $nombre =  $nombre_archivo;
    $tipo_documento = $obj_variables_diversas_model->tipo_documento($anulaciones['tipo_documento_codigo']);
    $pdf =  new PDF_MC_Table();
    $pdf->SetMargins(5, 5, 5);
    $pdf->SetFont('Arial','',12);
    $pdf->AddPage();
    $tamano_x = 30;
    $tamano_y = 25;
    
    $ruta_foto = 'logo.PNG';
    
    $pdf->Image($ruta_foto,7,7,$tamano_x,$tamano_y);
    $pdf->Ln($tamano_y);
    
    $pdf->SetDrawColor(236, 112, 99); // color de linea 
    $pdf->SetFont('Arial','',12);
    $pdf->SetXY(115,10);

    $pdf->Cell(80, 8, 'RUC ' . $empresa["ruc"], 'LRT', 1, 'C');
    $pdf->SetX(115);

    $pdf->Cell(80, 5, utf8_decode('COMUNICACION BAJA'), 'LR', 1, 'C',0)  ; 
    
    $pdf->SetX(115);
    $pdf->SetFillColor( 236, 112, 99 ); // color de fondo
       
    $pdf->SetTextColor( 0,0,0); // color de fondo
    
    $pdf->SetX(115);
    $pdf->Cell(80, 8,$nombre_archivo_pdf, 'LRB', 1, 'C');
    $pdf->SetX(115);
    $pdf->SetFillColor( 236, 112, 99 ); // color de fondo
    $pdf->SetTextColor( 255,255,255 ); // color de fondo
    $pdf->Cell(80, 5,"Numero Ticket: ". $anulaciones["ticket"], 'LR', 1, 'C',1);
    $pdf->SetTextColor( 0,0,0); // color de fondo
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(120, 5, '', 0, 1);
    $pdf->Cell(120, 5, $empresa["razon_social"], 0, 1, 'L');
    $pdf->SetFont('Arial','',8);
    $pdf->Cell(120, 5,mb_convert_encoding("Direccion: ","UTF-8", "auto") . mb_convert_encoding($empresa["domicilio_fiscal"], "UTF-8", "auto") , 0, 1, 'L');            
    
    $pdf->SetFont('Arial','B',7);     
    $pdf->Cell(35, 7, utf8_decode("F.EMISION DOCUMENTO:"),0,0,'L');
    $pdf->SetFont('Arial','',7);   

    $pdf->Cell(74, 7, mb_convert_encoding($anulaciones["fecha_documento"], "UTF-8", "auto"), 0, 1, 'L');
    
    $pdf->SetFont('Arial','B',7);     
    $pdf->Cell(35, 7, utf8_decode("F.BAJA DOCUMENTO:"),0,0,'L');
    $pdf->SetFont('Arial','',7); 
    $pdf->Cell(120, 7, mb_convert_encoding($anulaciones["fecha_anulacion"], "UTF-8", "auto"), 0, 1, 'L');

    $pdf->SetTextColor( 255,255,255 ); // color de texto blanco 
    $pdf->SetFillColor( 236, 112, 99 ); // color de fondo
    //$pdf->SetDrawColor(106,108,108); // color de linea
    $pdf->SetFont('Arial','B',8);
    $pdf->Cell(120, 3, '', 0, 1);
    $pdf->Cell(25, 8, "F.DOC ", 'LTB', 0, 'L',1);    
    $pdf->Cell(45, 8, "TIPO DOCUMENTO", 'LTB', 0, 'L',1);
    $pdf->Cell(30, 8, "SERIE", 'LRTB', 0, 'L',1);    
    $pdf->Cell(30, 8, "NUMERO ", 'LRTB', 0, 'L',1);
    $pdf->Cell(60, 8, "MOTIVO ", 'RTB', 1, 'L',1);
    
    $pdf->SetTextColor( 0,0,0); // color de negro
    $pdf->SetFont('Arial','',7);
     
    $pdf->Cell(25, 5, $anulaciones['fecha_documento'], 0, 0, 'L');
    $pdf->Cell(45, 5, $tipo_documento, 0, 0, 'L');
    $pdf->Cell(30, 5, $anulaciones['serie'], 0, 0, 'L');
    $pdf->Cell(30, 5, $anulaciones['numero'], 0, 0, 'L');          
    $pdf->MultiCell(60, 5, $anulaciones['motivo'], 0,'L');
    $valor = $pdf->GetY();
    $pdf->line(5,$valor ,195,$valor );
    
    $pdf->Output('files/facturacion_electronica/BAJA/PDF/'. $nombre .'.pdf', 'F'); 
}

echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);