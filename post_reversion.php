<?php
header('Access-Control-Allow-Origin: *');
header("HTTP/1.1");

require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
require 'libraries/efactura.php';

//require_once ('libraries/fpdf/fpdf.php');
require_once ('libraries/fpdf/multicell.php');
require_once ('libraries/qr/phpqrcode/qrlib.php');

$datos = file_get_contents("php://input");
$obj = json_decode($datos, true);

//echo $datos;exit;
//var_dump($datos);exit;
//var_dump($obj);exit;

$empresa        = $obj['empresa'];
$reversion      = $obj['reversion'];

$detalle = array();
foreach ($obj['documento_relacionados'] as $value){
    $detalle[] = ($value);
}

$nombre_archivo = $empresa['ruc'].'-RR-'.substr($reversion['fecha_resumen'], 0, 4).substr($reversion['fecha_resumen'], 5, 2).substr($reversion['fecha_resumen'], 8, 2).'-'.$reversion['correlativo_diario'];
$nombre = "files/facturacion_electronica/REVERSION/XML/".$nombre_archivo.".xml";

if(file_exists($nombre)){
    unlink($nombre);
    $nombre_base = "files/facturacion_electronica/FIRMA/".$nombre_archivo;
    if(file_exists($nombre_base.".xml")){
        unlink($nombre_base.".xml");
    }
    if(file_exists($nombre_base.".zip")){
        unlink($nombre_base.".zip");
    }    
}

$obj_variables_diversas_model = new variables_diversas_model();
$rpta = crear_xml($nombre, $empresa, $reversion, $detalle, $obj_variables_diversas_model);
firmar_xml($nombre_archivo.".xml", $empresa['modo']);
ws_sunat($empresa, $nombre_archivo, $obj_variables_diversas_model);

//$nombre = FCPATH."/files/facturacion_electronica/XML/".$nombre_archivo.".xml";
//$nombre = basename(dirname(__FILE__)) . "files/facturacion_electronica/XML/".$nombre_archivo.".xml";
function crear_xml($nombre, $empresa, $reversion, $detalle, $obj_variables_diversas_model){
    $xml = desarrollo_xml($empresa, $reversion, $detalle, $obj_variables_diversas_model);
    $archivo = fopen($nombre, "w+");
    fwrite($archivo, utf8_decode($xml));
    fclose($archivo);
}

function firmar_xml($name_file, $entorno, $baja = ''){
    $carpeta_baja = ($baja != '') ? 'BAJA/':'';
    $carpeta = "files/facturacion_electronica/REVERSION/$carpeta_baja";
    $dir = $carpeta."XML/".$name_file;
    //$dir = $name_file;
    $xmlstr = file_get_contents($dir);    

    $domDocument = new \DOMDocument();
    $domDocument->loadXML($xmlstr);
    $factura  = new Factura();    
    $xml = $factura->firmar($domDocument, '', $entorno);
    $content = $xml->saveXML();
    file_put_contents($carpeta."FIRMA/".$name_file, $content);
    //file_put_contents("xxxxarchivo_firmado_con_certificado".$name_file, $content);
}

function desarrollo_xml($empresa, $reversion, $detalle, $obj_variables_diversas_model){
    $xml = '<VoidedDocuments xmlns="urn:sunat:names:specification:ubl:peru:schema:xsd:VoidedDocuments-1" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:sac="urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1" xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
    <ext:UBLExtensions>
        <ext:UBLExtension>
            <ext:ExtensionContent></ext:ExtensionContent>
        </ext:UBLExtension>
    </ext:UBLExtensions>
    <cbc:UBLVersionID>2.0</cbc:UBLVersionID>
    <cbc:CustomizationID>1.0</cbc:CustomizationID>
    <cbc:ID>RR-'.substr($reversion['fecha_resumen'], 0, 4).substr($reversion['fecha_resumen'], 5, 2).substr($reversion['fecha_resumen'], 8, 2).'-'.$reversion['correlativo_diario'].'</cbc:ID>
    <cbc:ReferenceDate>'.$reversion['fecha_documentos'].'</cbc:ReferenceDate>
    <cbc:IssueDate>'.$reversion['fecha_resumen'].'</cbc:IssueDate>
    <cac:Signature>
        <cbc:ID>'.$empresa['ruc'].'</cbc:ID>
        <cac:SignatoryParty>
            <cac:PartyIdentification>
                <cbc:ID>'.$empresa['ruc'].'</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyName>
                <cbc:Name><![CDATA['. utf8_decode($empresa['razon_social']).']]></cbc:Name>
            </cac:PartyName>
        </cac:SignatoryParty>
        <cac:DigitalSignatureAttachment>
            <cac:ExternalReference>
                <cbc:URI>Conector</cbc:URI>
            </cac:ExternalReference>
        </cac:DigitalSignatureAttachment>
    </cac:Signature>
    <cac:AccountingSupplierParty>
        <cbc:CustomerAssignedAccountID>'.$empresa['ruc'].'</cbc:CustomerAssignedAccountID>
        <cbc:AdditionalAccountID>6</cbc:AdditionalAccountID>
        <cac:Party>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName><![CDATA['. utf8_decode($empresa['razon_social']).']]></cbc:RegistrationName>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:AccountingSupplierParty>';

    $i = 0;
    foreach ($detalle as $documento){
    $i++;
    $xml .= '<sac:VoidedDocumentsLine>
            <cbc:LineID>'.$i.'</cbc:LineID>
            <cbc:DocumentTypeCode>'.$documento['tipo_documento_codigo'].'</cbc:DocumentTypeCode>
            <sac:DocumentSerialID>'.$documento['serie'].'</sac:DocumentSerialID>
            <sac:DocumentNumberID>'.$documento['numero'].'</sac:DocumentNumberID>
            <sac:VoidReasonDescription><![CDATA['.$documento['descripcion'].']]></sac:VoidReasonDescription>
        </sac:VoidedDocumentsLine>';
        }
    
    $xml .= '</VoidedDocuments>';
    return $xml;
}

function moneda($moneda_id){
    $codigo_moneda = 'PEN';
    $descripcion = 'soles';
    switch ($moneda_id) {
        case 1:
            $codigo_moneda = 'PEN';
            $descripcion = 'soles';
            break;
        case 2:
            $codigo_moneda = 'USD';
            $descripcion = 'dolares';
            break;
        case 3:
            $codigo_moneda = 'EUR';
            $descripcion = 'euros';
            break;
    }
    return array($codigo_moneda, $descripcion);
}

function ws_sunat($empresa, $nombre_archivo, $obj_variables_diversas_model){
    //enviar a Sunat
    //cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
    //cod_2: Entorno:  0 Beta, 1 Produccion
    //cod_3: ruc
    //cod_4: usuario secundario USU(segun seha beta o producción)
    //cod_5: usuario secundario PASSWORD(segun seha beta o producción)
    //cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket
    //cod_7: serie de documento
    //cod_8: numero ticket

    $ruta_dominio   = $obj_variables_diversas_model->carpeta_actual();
    $user_sec_usu   = ($empresa['modo'] == 1) ? $empresa['usu_secundario_produccion_user'] : 'MODDATOS';
    $user_sec_pass  = ($empresa['modo'] == 1) ? $empresa['usu_secundario_produccion_password'] : 'moddatos';

    $url = $ruta_dominio."/ws_sunat/reversion_genera_ticket.php?numero_documento=".$nombre_archivo."&cod_1=1&cod_2=".$empresa['modo']."&cod_3=".$empresa['ruc']."&cod_4=".$user_sec_usu."&cod_5=".$user_sec_pass."&cod_6=1";
    //echo $url;exit;
    $data = file_get_contents($url);
    echo json_encode(array('ticket' => $data), JSON_UNESCAPED_UNICODE);
}