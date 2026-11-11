<?php
header('Access-Control-Allow-Origin: *');
header("HTTP/1.1");
date_default_timezone_set("America/Lima");

require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
require 'libraries/efactura.php';

$datos = file_get_contents("php://input");
$obj = json_decode($datos, true);

//echo $datos;exit;
//var_dump($datos);exit;
//var_dump($obj);exit;

$empresa        = $obj['empresa'];
$baja           = $obj['anulacion'];
$nombre_archivo = $empresa['ruc'].'-RA-'.date("Ymd").'-'.$baja['correlativo_diario'];

////////CREO XML
$xml = desarrollo_xml_baja($empresa, $baja, $baja['correlativo_diario']);

$nombre = "files/facturacion_electronica/BAJA/XML/".$nombre_archivo.".xml"; 
$archivo = fopen($nombre, "w+");
fwrite($archivo, $xml);
fclose($archivo);

firmar_xml($nombre_archivo.".xml", $empresa['modo'], 1);

//enviar a Sunat       
//cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
//cod_2: Entorno:  0 Beta, 1 Produccion
//cod_3: ruc
//cod_4: usuario secundario USU(segun seha beta o producción)
//cod_5: usuario secundario PASSWORD(segun seha beta o producción)
//cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket
//cod_7: serie de documento
//cod_8: numero ticket

//$ruta_dominio = "http://localhost/API_SUNAT";
$ruta_dominio = "https://facturaciondirecta.com/API_SUNAT";
$user_sec_usu = ($empresa['modo'] == 0) ? 'MODDATOS' : $empresa['usu_secundario_user'];
$user_sec_pass = ($empresa['modo'] == 0) ? 'moddatos' : $empresa['usu_secundario_password'];        
$ws = $ruta_dominio."/ws_sunat/index_baja.php?numero_documento=".$nombre_archivo."&cod_1=1&cod_2=".$empresa['modo']."&cod_3=".$empresa['ruc']."&cod_4=".$user_sec_usu."&cod_5=".$user_sec_pass."&cod_6=2";
//echo $ws;exit;


/**
* Note: This file may contain artifacts of previous malicious infection.
* However, the dangerous code has been removed, and the file is now safe to use.
*/

$info = json_decode($data, TRUE);

/////////GUARDO EN BBDD

//var_dump($info['ticket']);
echo json_encode(array('ticket' => $info['ticket'][0]), JSON_UNESCAPED_UNICODE);

function desarrollo_xml_baja($empresa, $baja, $correlativo){

        $fecha_baja_ini= str_replace("-","",$baja['fecha_baja']);

        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?><VoidedDocuments xmlns="urn:sunat:names:specification:ubl:peru:schema:xsd:VoidedDocuments-1" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:sac="urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1" xmlns:ds="http://www.w3.org/2000/09/xmldsig#">
                <ext:UBLExtensions>
                    <ext:UBLExtension>
                        <ext:ExtensionContent></ext:ExtensionContent>
                    </ext:UBLExtension>
                </ext:UBLExtensions>
                <cbc:UBLVersionID>2.0</cbc:UBLVersionID>
                <cbc:CustomizationID>1.0</cbc:CustomizationID>
                <cbc:ID>RA-'.$fecha_baja_ini.'-'.$correlativo.'</cbc:ID>
                <cbc:ReferenceDate>'.$baja['fecha_comprobante'].'</cbc:ReferenceDate>
                <cbc:IssueDate>'.$baja['fecha_baja'].'</cbc:IssueDate>
                <cac:Signature>
                    <cbc:ID>'.$empresa['ruc'].'</cbc:ID>
                    <cac:SignatoryParty>
                        <cac:PartyIdentification>
                            <cbc:ID>'.$empresa['ruc'].'</cbc:ID>
                        </cac:PartyIdentification>
                        <cac:PartyName>
                            <cbc:Name><![CDATA['.$empresa['razon_social'].']]></cbc:Name>
                        </cac:PartyName>
                    </cac:SignatoryParty>
                    <cac:DigitalSignatureAttachment>
                        <cac:ExternalReference>
                            <cbc:URI>'.$empresa['ruc'].'</cbc:URI>
                        </cac:ExternalReference>
                    </cac:DigitalSignatureAttachment>
                </cac:Signature>
                <cac:AccountingSupplierParty>
                    <cbc:CustomerAssignedAccountID>'.$empresa['ruc'].'</cbc:CustomerAssignedAccountID>
                    <cbc:AdditionalAccountID>6</cbc:AdditionalAccountID>
                    <cac:Party>
                        <cac:PartyLegalEntity>
                            <cbc:RegistrationName><![CDATA['.$empresa['razon_social'].']]></cbc:RegistrationName>
                        </cac:PartyLegalEntity>
                    </cac:Party>
                </cac:AccountingSupplierParty>
                <sac:VoidedDocumentsLine>
                    <cbc:LineID>1</cbc:LineID>
                    <cbc:DocumentTypeCode>'.$baja['tipo_documento_codigo'].'</cbc:DocumentTypeCode>
                    <sac:DocumentSerialID>'.$baja['serie'].'</sac:DocumentSerialID>
                    <sac:DocumentNumberID>'.$baja['numero'].'</sac:DocumentNumberID>
                    <sac:VoidReasonDescription>Anulacion de la Operacion</sac:VoidReasonDescription>
                </sac:VoidedDocumentsLine>
            </VoidedDocuments>';
        return $xml;
    }
    
function firmar_xml($name_file, $entorno, $baja = ''){
    $carpeta_baja = ($baja != '') ? 'BAJA/':'';
    $carpeta = "files/facturacion_electronica/$carpeta_baja";
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