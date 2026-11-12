<?php
require_once __DIR__ . '/libraries/guard.php';
header('Access-Control-Allow-Origin: *');
header("HTTP/1.1");
date_default_timezone_set("America/Lima");

require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
require 'libraries/efactura.php';

$datos = file_get_contents("php://input");
$obj = json_decode($datos, true);

$empresa    = $obj['empresa'];
$resumen    = $obj['resumen'];
$comprobantes = $obj['comprobantes'];

//$anulaciones_dia = $baja['anulacion_por_dia'];
$nombre_archivo = $empresa['ruc'].'-RC-'.$resumen['numero'].'-'.$resumen['correlativo'];

$xml = desarrollo_xml_resumen($empresa, $resumen, $comprobantes);

$nombre = "files/facturacion_electronica/RESUMEN/XML/".$nombre_archivo.".xml"; 
$archivo = fopen($nombre, "w+");
fwrite($archivo, $xml);
fclose($archivo);
$carpeta = "files/facturacion_electronica/RESUMEN/";
firmar_xml($carpeta, $nombre_archivo.".xml", $empresa['modo'], 1);
//comprimir($carpeta, $nombre_archivo);

//enviar a Sunat       
//cod_1: Entorno:  0 Beta, 1 Produccion
//cod_2: ruc
//cod_3: usuario secundario USU(segun seha beta o producción)
//cod_4: usuario secundario PASSWORD(segun seha beta o producción)

$obj_variables_diversas_model = new variables_diversas_model();
$ruta_dominio   = $obj_variables_diversas_model->carpeta_actual();
$user_sec_usu = ($empresa['modo'] == 0) ? 'MODDATOS' : $empresa['usu_secundario_user'];
$user_sec_pass = ($empresa['modo'] == 0) ? 'moddatos' : $empresa['usu_secundario_password'];        
$ws = $ruta_dominio."/ws_sunat/index_resumen.php?numero_documento=".$nombre_archivo."&cod_1=".$empresa['modo']."&cod_2=".$empresa['ruc']."&cod_3=".$user_sec_usu."&cod_4=".$user_sec_pass;
//echo $ws;exit;

$data = file_get_contents($ws);
echo json_encode(array('ticket' => $data), JSON_UNESCAPED_UNICODE);

function desarrollo_xml_resumen($empresa, $resumen, $comprobantes){
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?><SummaryDocuments xmlns="urn:sunat:names:specification:ubl:peru:schema:xsd:SummaryDocuments-1" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:sac="urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1">
                <ext:UBLExtensions>
                    <ext:UBLExtension>
                        <ext:ExtensionContent></ext:ExtensionContent>
                    </ext:UBLExtension>
                </ext:UBLExtensions>
                <cbc:UBLVersionID>2.0</cbc:UBLVersionID>
                <cbc:CustomizationID>1.1</cbc:CustomizationID>
                <cbc:ID>RC-'.$resumen['numero'].'-'.$resumen['correlativo'].'</cbc:ID>
                <cbc:ReferenceDate>'.$resumen['fecha_documentos'].'</cbc:ReferenceDate>
                <cbc:IssueDate>'.$resumen['fecha_resumen'].'</cbc:IssueDate>
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
        foreach ($comprobantes as $documento){
            $total_gravada  = (!isset($documento['total_gravada']) || ($documento['total_gravada'] == "")) ? 0 : $documento['total_gravada'];
            $total_igv      = (!isset($documento['total_igv']) || ($documento['total_igv'] == "")) ? 0 : $documento['total_igv'];
            $i++;
            $xml .= '<sac:SummaryDocumentsLine>
                <cbc:LineID>' . $i . '</cbc:LineID>
                <cbc:DocumentTypeCode>'.$documento['tipo_documento'].'</cbc:DocumentTypeCode>
                <cbc:ID>'.$documento['serie'].'-'.$documento['numero'].'</cbc:ID>
                <cac:AccountingCustomerParty>
                    <cbc:CustomerAssignedAccountID>'.$documento['cliente_numero_documento'].'</cbc:CustomerAssignedAccountID>
                    <cbc:AdditionalAccountID>'.$documento['cliente_tipo_documento'].'</cbc:AdditionalAccountID>
                </cac:AccountingCustomerParty>';
            
            if($documento['tipo_documento'] == '07'){
                $xml .= '<cac:BillingReference>
                <cac:InvoiceDocumentReference>
                <cbc:ID>B001-1</cbc:ID>
                <cbc:DocumentTypeCode>03</cbc:DocumentTypeCode>
                </cac:InvoiceDocumentReference>
                </cac:BillingReference>';
            }
            
            $xml .= '<cac:Status>
                <cbc:ConditionCode>'.$documento['status'].'</cbc:ConditionCode>
                </cac:Status>
                <sac:TotalAmount currencyID="PEN">'.$documento['total_a_pagar'].'</sac:TotalAmount>';
                    
            //if(($documento['total_gravada'] != "") && ($documento['total_gravada'] > 0)){
            $xml .= '<sac:BillingPayment>
                    <cbc:PaidAmount currencyID="PEN">'.$total_gravada.'</cbc:PaidAmount>
                    <cbc:InstructionID>01</cbc:InstructionID>
                </sac:BillingPayment>';
            //}

            if(($documento['total_exonerada'] != "") && ($documento['total_exonerada'] > 0)){
            $xml .= '<sac:BillingPayment>
                    <cbc:PaidAmount currencyID="PEN">'.$documento['total_exonerada'].'</cbc:PaidAmount>
                    <cbc:InstructionID>02</cbc:InstructionID>
                </sac:BillingPayment>';
            }
            
            if(($documento['total_inafecta'] != "") && ($documento['total_inafecta'] > 0)){
            $xml .= '<sac:BillingPayment>
                    <cbc:PaidAmount currencyID="PEN">'.$documento['total_inafecta'].'</cbc:PaidAmount>
                    <cbc:InstructionID>03</cbc:InstructionID>
                </sac:BillingPayment>';
            }
            
            if(($documento['total_gratuita'] != "") && ($documento['total_gratuita'] > 0)){
            $xml .= '<sac:BillingPayment>
                    <cbc:PaidAmount currencyID="PEN">'.$documento['total_gratuita'].'</cbc:PaidAmount>
                    <cbc:InstructionID>05</cbc:InstructionID>
                </sac:BillingPayment>';
            }
            
            //if(($documento['total_igv'] != "") && ($documento['total_igv'] > 0)){
            $xml .= '<cac:TaxTotal>
                <cbc:TaxAmount currencyID="PEN">'.$total_igv.'</cbc:TaxAmount>
                <cac:TaxSubtotal>
                    <cbc:TaxAmount currencyID="PEN">'.$total_igv.'</cbc:TaxAmount>
                    <cac:TaxCategory>
                        <cbc:Percent>18.00</cbc:Percent>
                        <cac:TaxScheme>
                            <cbc:ID>1000</cbc:ID>
                            <cbc:Name>IGV</cbc:Name>
                            <cbc:TaxTypeCode>VAT</cbc:TaxTypeCode>
                        </cac:TaxScheme>
                    </cac:TaxCategory>
                </cac:TaxSubtotal>
                </cac:TaxTotal>';
            //}
            $xml .= '</sac:SummaryDocumentsLine>';
        }                        
        $xml .= '</SummaryDocuments>';
        return $xml;
}
    
function firmar_xml($carpeta, $name_file, $entorno, $baja = ''){    
    $dir = $carpeta."XML/".$name_file;
    $xmlstr = file_get_contents($dir);    

    $domDocument = new \DOMDocument();
    $domDocument->loadXML($xmlstr);
    $factura  = new Factura();    
    $xml = $factura->firmar($domDocument, '', $entorno, substr(basename($name_file), 0, 11));
    $content = $xml->saveXML();
    file_put_contents($carpeta."FIRMA/".$name_file, $content);
}

//function comprimir($carpeta, $nombre_archivo){
//    $zip = new ZipArchive();
//    if($zip->open($carpeta."FIRMA/".$nombre_archivo.".zip", ZipArchive::CREATE) === true){
//        $zip->addFile($carpeta."FIRMA/".$nombre_archivo.".xml", $nombre_archivo.".xml");
//    }    
//}
