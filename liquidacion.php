<?php
require_once './libraries/dompdf/autoload.inc.php';
use Dompdf\Dompdf;

header('Access-Control-Allow-Origin: *');
header("HTTP/1.1");

require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
require 'libraries/efactura.php';


//require_once ('libraries/fpdf/fpdf.php');
//require_once ('libraries/fpdf/multicell.php');
require_once ('libraries/qr/phpqrcode/qrlib.php');

$datos = file_get_contents("php://input");
$obj = json_decode($datos, true);

//echo $datos;exit;
//var_dump($datos);exit;
//var_dump($obj);exit;

$empresa            = $obj['empresa'];
$proveedor          = $obj['proveedor'];
$lugar_operacion    = $obj['lugar_operacion'];
$venta              = $obj['venta'];
$cuotas             = isset($obj['cuotas']) ? $obj['cuotas'] : array();
$guias_adjuntas     = isset($obj['guias_adjuntas']) ? $obj['guias_adjuntas'] : array();

//var_dump($cliente);exit;

$venta['fecha_vencimiento'] = isset($venta['fecha_vencimiento'])    ? $venta['fecha_vencimiento']   : null;
$venta['total_exonerada']   = isset($venta['total_exonerada'])      ? $venta['total_exonerada']     : null;
$venta['total_inafecta']    = isset($venta['total_inafecta'])       ? $venta['total_inafecta']      : null;
$venta['total_exonerada']   = isset($venta['total_exonerada'])      ? $venta['total_exonerada']     : null;
$venta['total_inafecta']    = isset($venta['total_inafecta'])       ? $venta['total_inafecta']      : null;

$detalle = array();
foreach ($obj['items'] as $value){
    $detalle[] = ($value);
}

$nombre_archivo = $empresa['ruc'].'-'.$venta['tipo_documento_codigo'].'-'.$venta['serie'].'-'.$venta['numero'];
$nombre = "files/facturacion_electronica/XML/".$nombre_archivo.".xml";

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
$rpta = crear_xml($nombre, $empresa, $proveedor, $lugar_operacion, $venta, $detalle, $cuotas, $guias_adjuntas, $obj_variables_diversas_model);
firmar_xml($nombre_archivo.".xml", $empresa['modo']);
ws_sunat($empresa, $nombre_archivo, $obj_variables_diversas_model);

//crear_pdf($nombre, $empresa, $proveedor, $lugar_operacion, $venta, $detalle, $cuotas, $guias_adjuntas, $obj_variables_diversas_model);
//crear_pdf();

//if(($venta['tipo_documento_codigo'] == '01') || ($venta['tipo_documento_codigo'] == '03') || ($venta['tipo_documento_codigo'] == '07')){
//    crear_pdf($empresa, $proveedor, $venta, $detalle, $nombre_archivo, $cuotas, $guias_adjuntas, $obj_variables_diversas_model);
//}

//$nombre = FCPATH."/files/facturacion_electronica/XML/".$nombre_archivo.".xml";
//$nombre = basename(dirname(__FILE__)) . "files/facturacion_electronica/XML/".$nombre_archivo.".xml";
function crear_xml($nombre, $empresa, $proveedor, $lugar_operacion, $venta, $detalle, $cuotas, $guias_adjuntas, $obj_variables_diversas_model){
    $xml = desarrollo_xml($empresa, $proveedor, $lugar_operacion, $venta, $detalle, $cuotas, $guias_adjuntas, $obj_variables_diversas_model);
    $archivo = fopen($nombre, "w+");
    fwrite($archivo, utf8_decode($xml));
    fclose($archivo);
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

function desarrollo_xml($empresa, $proveedor, $lugar_operacion, $venta, $detalles, $cuotas, $guias_adjuntas, $obj_variables_diversas_model){
    $total_igv          = ($venta['total_igv'] != null) ? $venta['total_igv'] : 0.0;
    $total_gravada      = ($venta['total_gravada'] == null)     ? 0 : $venta['total_gravada'];
    $total_exonerada    = ($venta['total_exonerada'] == null)   ? 0 : $venta['total_exonerada'];
    $total_inafecta     = ($venta['total_inafecta'] == null)    ? 0 : $venta['total_inafecta'];    
    $total_a_pagar      = number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv), 2, '.', '');
    $impuesto_igv       = 0.18;
    
    //solo moneda soles, luego se puede modificar
    $venta['moneda_id'] = 1;
    $array_moneda = moneda($venta['moneda_id']);
    $codigo_moneda = $array_moneda[0];
    $descripcion_moneda = $array_moneda[1];
    
    $num = new Numletras();
    $totalVenta = explode(".", $total_a_pagar);
    $totalLetras = $num->num2letras($totalVenta[0]);
    $venta['total_letras'] = $totalLetras.' con '.$totalVenta[1].'/100 '.$descripcion_moneda; 
    
    $xml = '<SelfBilledInvoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:SelfBilledInvoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">';
    $xml .= '<ext:UBLExtensions>
                    <ext:UBLExtension>
                        <ext:ExtensionContent></ext:ExtensionContent>
                    </ext:UBLExtension>
                </ext:UBLExtensions>';
    $xml .= '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>
    <cbc:CustomizationID schemeAgencyName="PE:SUNAT">2.0</cbc:CustomizationID>
    <cbc:ID>'.$venta['serie'].'-'.$venta['numero'].'</cbc:ID>
    <cbc:IssueDate>'.$venta['fecha_emision'].'</cbc:IssueDate>
    <cbc:IssueTime>'.$venta['hora_emision'].'</cbc:IssueTime>
    <cbc:InvoiceTypeCode listAgencyName="PE:SUNAT" listID="0501" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01">04</cbc:InvoiceTypeCode>
    <cbc:Note languageLocaleID="1000">'.$venta['total_letras'].'</cbc:Note>
<cbc:DocumentCurrencyCode listAgencyName="United Nations Economic Commission for Europe" listID="ISO 4217 Alpha" listName="Currency">PEN</cbc:DocumentCurrencyCode>';

$xml .= '<cac:Signature>
        <cbc:ID>'.$empresa['ruc'].'</cbc:ID>
        <cac:SignatoryParty>
            <cac:PartyIdentification>
                <cbc:ID>'.$empresa['ruc'].'</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyName>
                <cbc:Name><![CDATA['.utf8_encode($empresa['razon_social']).']]></cbc:Name>
            </cac:PartyName>
        </cac:SignatoryParty>
        <cac:DigitalSignatureAttachment>
            <cac:ExternalReference>
                <cbc:URI>'.$empresa['ruc'].'</cbc:URI>
            </cac:ExternalReference>
        </cac:DigitalSignatureAttachment>
    </cac:Signature>';  

$xml .= '<cac:AccountingCustomerParty>
<cac:Party>
    <cac:PartyIdentification>
        <cbc:ID schemeAgencyName="PE:SUNAT" schemeID="6" schemeName="Documento de Identidad" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">20107974467</cbc:ID>
    </cac:PartyIdentification>
        <cac:PartyName>
            <cbc:Name>COMPANIA INTERNACIONAL DEL CAFE</cbc:Name>
        </cac:PartyName>
    <cac:PartyLegalEntity>
        <cbc:RegistrationName>COMPANIA INTERNACIONAL DEL CAFE SOCIEDAD ANONIMA CERRADA</cbc:RegistrationName>
        <cac:RegistrationAddress>
            <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">150131</cbc:ID>
            <cbc:CityName>LIMA</cbc:CityName>
            <cbc:CountrySubentity>LIMA</cbc:CountrySubentity>
            <cbc:District>SAN ISIDRO</cbc:District>
            <cac:AddressLine>
                <cbc:Line>CAL. AMADOR MERINO REYNA 465 702A ZONA DETRAS DEL WESTIN HOTEL</cbc:Line>
            </cac:AddressLine>
            <cac:Country>
                <cbc:IdentificationCode listAgencyName="United Nations Economic Commission for Europe" listID="ISO 3166-1" listName="Country">PE</cbc:IdentificationCode>
            </cac:Country>
        </cac:RegistrationAddress>
    </cac:PartyLegalEntity>
</cac:Party>
</cac:AccountingCustomerParty>';

//AddressTypeCode
//01  Punto de venta
//02  Producción
//03  Extracción
//04  Explotación
//05  Otros
$xml .= '<cac:AccountingSupplierParty>
<cac:Party>
    <cac:PartyIdentification>
        <cbc:ID schemeAgencyName="PE:SUNAT" schemeID="1" schemeName="Documento de Identidad" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">'.$proveedor['numero_documento'].'</cbc:ID>
    </cac:PartyIdentification>
    <cac:PartyLegalEntity>
        <cbc:RegistrationName>'.$proveedor['nombres'].'</cbc:RegistrationName>
        <cac:RegistrationAddress>
            <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">'.$proveedor['ubigeo'].'</cbc:ID>
            <cbc:AddressTypeCode>01</cbc:AddressTypeCode>
            <cbc:CityName>'.$proveedor['provincia'].'</cbc:CityName>
            <cbc:CountrySubentity>'.$proveedor['departamento'].'</cbc:CountrySubentity>
            <cbc:District>'.$proveedor['distrito'].'</cbc:District>
            <cac:AddressLine>
                <cbc:Line>'.$proveedor['direccion'].'</cbc:Line>
            </cac:AddressLine>
            <cac:Country>
                <cbc:IdentificationCode listAgencyName="United Nations Economic Commission for Europe" listID="ISO 3166-1" listName="Country">PE</cbc:IdentificationCode>
            </cac:Country>
        </cac:RegistrationAddress>
    </cac:PartyLegalEntity>
</cac:Party>
</cac:AccountingSupplierParty>';

$xml .='<cac:DeliveryTerms>
    <cac:DeliveryLocation>
        <cbc:LocationTypeCode>01</cbc:LocationTypeCode>
        <cac:Address>
            <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">'.$lugar_operacion['ubigeo'].'</cbc:ID>
            <cbc:CityName>'.$lugar_operacion['provincia'].'</cbc:CityName>
            <cbc:CountrySubentity>'.$lugar_operacion['departamento'].'</cbc:CountrySubentity>
            <cbc:District>'.$lugar_operacion['distrito'].'</cbc:District>
            <cac:AddressLine><cbc:Line>'.$lugar_operacion['direccion'].'</cbc:Line></cac:AddressLine>
            <cac:Country>
                <cbc:IdentificationCode listAgencyName="United Nations Economic Commission for Europe" listID="ISO 3166-1" listName="Country">PE</cbc:IdentificationCode>
            </cac:Country>
        </cac:Address>
    </cac:DeliveryLocation>
</cac:DeliveryTerms>';

$xml .= '<cac:TaxTotal>
<cbc:TaxAmount currencyID="'. $codigo_moneda .'">'. $total_igv .'</cbc:TaxAmount>';
if($venta['total_gravada'] != null){
$xml .=  '<cac:TaxSubtotal>
    <cbc:TaxableAmount currencyID="'. $codigo_moneda .'">' .  number_format($venta['total_gravada'], 2, '.', '') . '</cbc:TaxableAmount>
    <cbc:TaxAmount currencyID="'. $codigo_moneda .'">' . $total_igv . '</cbc:TaxAmount>
    <cac:TaxCategory>
        <cac:TaxScheme>
            <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Codigo de tributos" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">1000</cbc:ID>
            <cbc:Name>IGV</cbc:Name>
            <cbc:TaxTypeCode>VAT</cbc:TaxTypeCode>
        </cac:TaxScheme>
    </cac:TaxCategory>
</cac:TaxSubtotal>';
};        
if($venta['total_exonerada'] != null){
$xml .=  '<cac:TaxSubtotal>
    <cbc:TaxableAmount currencyID="'. $codigo_moneda .'">' . $venta['total_exonerada'] . '</cbc:TaxableAmount>
    <cbc:TaxAmount currencyID="'. $codigo_moneda .'">0.00</cbc:TaxAmount>
    <cac:TaxCategory>
        <cac:TaxScheme>
            <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Codigo de tributos" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">9997</cbc:ID>
            <cbc:Name>EXO</cbc:Name>
            <cbc:TaxTypeCode>VAT</cbc:TaxTypeCode>
        </cac:TaxScheme>
    </cac:TaxCategory>
</cac:TaxSubtotal>';
        };                    
if($venta['total_inafecta'] != null){                                            
$xml .=  '<cac:TaxSubtotal>
    <cbc:TaxableAmount currencyID="'. $codigo_moneda .'">' . $venta['total_inafecta'] . '</cbc:TaxableAmount>
    <cbc:TaxAmount currencyID="'. $codigo_moneda .'">0.00</cbc:TaxAmount>
    <cac:TaxCategory>
        <cac:TaxScheme>
            <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Codigo de tributos" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">9998</cbc:ID>
            <cbc:Name>INA</cbc:Name>
            <cbc:TaxTypeCode>FRE</cbc:TaxTypeCode>
        </cac:TaxScheme>
    </cac:TaxCategory>
</cac:TaxSubtotal>';
};

$xml .=  '<cac:TaxSubtotal>
    <cbc:TaxableAmount currencyID="PEN">0.00</cbc:TaxableAmount>
    <cbc:TaxAmount currencyID="PEN">0.00</cbc:TaxAmount>
    <cac:TaxCategory>
        <cac:TaxScheme>
            <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Codigo de tributos" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">3000</cbc:ID>
            <cbc:Name>IR</cbc:Name>
            <cbc:TaxTypeCode>TOX</cbc:TaxTypeCode>
        </cac:TaxScheme>
    </cac:TaxCategory>
</cac:TaxSubtotal>';

$xml .=  '</cac:TaxTotal>';

$xml .=  '<cac:LegalMonetaryTotal>
            <cbc:LineExtensionAmount currencyID="'. $codigo_moneda .'">' . number_format(($total_gravada + $total_exonerada + $total_inafecta), 2, '.', '') . '</cbc:LineExtensionAmount>
            <cbc:TaxInclusiveAmount currencyID="'. $codigo_moneda .'">' . number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv), 2, '.', '') . '</cbc:TaxInclusiveAmount>
            <cbc:ChargeTotalAmount currencyID="'. $codigo_moneda .'">0.00</cbc:ChargeTotalAmount>
            <cbc:PrepaidAmount currencyID="'. $codigo_moneda .'">0.00</cbc:PrepaidAmount>
            <cbc:PayableAmount currencyID="'. $codigo_moneda .'">' . number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv), 2, '.', ''). '</cbc:PayableAmount>
        </cac:LegalMonetaryTotal>';

$i = 1;
$percent = $obj_variables_diversas_model->porcentaje_valor_igv;
foreach($detalles as $value){
$icbper             = 00.00;
$descuento          = 0;
$descuento_precio_base = 0;

$codigos        = $obj_variables_diversas_model->datos_codigo_tributo($value['tipo_igv_codigo']);
$priceAmount        = $obj_variables_diversas_model->priceAmount($value['precio_base'], $codigos['codigo_tributo'], $percent, $icbper, $descuento_precio_base);
$PriceTypeCode      = ($codigos['codigo_tributo'] == 9996) ? '02' : '01';

$taxAmount          = $obj_variables_diversas_model->taxAmount($value['cantidad'], $value['precio_base'], $codigos['codigo_tributo'], $percent, $descuento_precio_base);
$price_priceAmount  = $obj_variables_diversas_model->price_priceAmount($value['precio_base'], $codigos['codigo_tributo'], $descuento);

$xml .= '<cac:InvoiceLine>
<cbc:ID>'.$i.'</cbc:ID>  
<cbc:InvoicedQuantity unitCode="NIU">'. number_format($value['cantidad'], 2, '.', '') .'</cbc:InvoicedQuantity>
<cbc:LineExtensionAmount currencyID="' . $codigo_moneda . '">'. number_format(($value['cantidad'] * $value['precio_base']), 2, '.', '').'</cbc:LineExtensionAmount>
<cac:PricingReference>
    <cac:AlternativeConditionPrice>
        <cbc:PriceAmount currencyID="' . $codigo_moneda . '">' . abs(number_format($priceAmount, 6, '.', '')) .'</cbc:PriceAmount>
        <cbc:PriceTypeCode listName="Tipo de Precio" listAgencyName="PE:SUNAT" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo16">' . $PriceTypeCode . '</cbc:PriceTypeCode>
    </cac:AlternativeConditionPrice>
</cac:PricingReference>';

$xml .= '<cac:TaxTotal>
            <cbc:TaxAmount currencyID="' . $codigo_moneda . '">'. number_format(($taxAmount + $icbper * $value['cantidad']), 2, '.', '') .'</cbc:TaxAmount>
            <cac:TaxSubtotal>
                <cbc:TaxableAmount currencyID="' . $codigo_moneda . '">' . number_format(($value['precio_base'] - $descuento_precio_base) * $value['cantidad'] ,2, '.', '') . '</cbc:TaxableAmount>
                <cbc:TaxAmount currencyID="' . $codigo_moneda . '">'. number_format($taxAmount, 2, '.', '') .'</cbc:TaxAmount>
                <cac:TaxCategory>
                    <cbc:Percent>' . $percent * 100 . '</cbc:Percent>
                    <cbc:TaxExemptionReasonCode>' . $value['tipo_igv_codigo'] . '</cbc:TaxExemptionReasonCode>
                    <cac:TaxScheme>
                        <cbc:ID>'.$codigos['codigo_tributo'].'</cbc:ID>
                        <cbc:Name>'.$codigos['nombre'].'</cbc:Name>
                        <cbc:TaxTypeCode>'.$codigos['codigo_internacional'].'</cbc:TaxTypeCode>
                    </cac:TaxScheme>
                </cac:TaxCategory>
            </cac:TaxSubtotal>        
        </cac:TaxTotal>';
                        
    $xml .=     '<cac:Item>                                    
                    <cbc:Description><![CDATA[' . $value['producto'] . ']]></cbc:Description>
                    <cac:SellersItemIdentification>
                        <cbc:ID>' . $value['codigo_producto'] . '</cbc:ID>
                    </cac:SellersItemIdentification>
                    <cac:CommodityClassification>                                        
                        <cbc:ItemClassificationCode>' . $value['codigo_sunat'] . '</cbc:ItemClassificationCode>
                    </cac:CommodityClassification>
                </cac:Item>
                <cac:Price>
                    <cbc:PriceAmount currencyID="' . $codigo_moneda . '">' .  number_format($price_priceAmount, 6, '.', '') . '</cbc:PriceAmount>
                </cac:Price>
</cac:InvoiceLine>';
$i++;
}
$xml .= '</SelfBilledInvoice>';
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
        $url = $ruta_dominio."/ws_sunat/index.php?numero_documento=".$nombre_archivo."&cod_1=1&cod_2=".$empresa['modo']."&cod_3=".$empresa['ruc']."&cod_4=".$user_sec_usu."&cod_5=".$user_sec_pass."&cod_6=1";
        //echo $url;exit;
        
        //$respuesta = getFirma($nombre_archivo);
        //var_dump($respuesta);exit;
        
        $data = file_get_contents($url);
        $info = json_decode($data);

        $jsondata = array(
            'data'        =>  $info
        );
        echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);
    }

function crear_pdf(){
    ob_start();?>
<!DOCTYPE html>
<html>
<head>
    <title>Nepliano</title>
</head>
<body>
    <h1>Hola mundo recon11</h1>
</body>
</html>
<?php
$html = ob_get_clean();
$dompdf = new Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('letter');
$dompdf->render();
$dompdf->stream("neple.pdf", array("Attachment" => true));
}

function GetImgQr($venta, $empresa, $tipo_documento, $cliente)  {
    $textoQR = '';
    $textoQR .= $empresa['ruc']."|";//RUC EMPRESA

    $textoQR .= $tipo_documento."|";//TIPO DE DOCUMENTO 
    $textoQR .= $venta['serie']."|";//SERIE
    $textoQR .= $venta['numero']."|";//NUMERO
    $textoQR .= $venta['total_igv']."|";//MTO TOTAL IGV
    $textoQR .= $venta['total_a_pagar']."|";//MTO TOTAL DEL COMPROBANTE
    $textoQR .= $venta['fecha_emision']."|";//FECHA DE EMISION 

    //tipo de cliente     
    $textoQR .= $cliente['codigo_tipo_entidad']."|";//TIPO DE DOCUMENTO ADQUIRENTE 
    $textoQR .= $cliente['numero_documento']."|";//NUMERO DE DOCUMENTO ADQUIRENTE 

    $nombreQR = $venta['tipo_documento_codigo'].'-'.$venta['serie'].'-'.$venta['numero'];
    QRcode::png($textoQR, "files/facturacion_electronica/qr/".$nombreQR.".png", QR_ECLEVEL_L, 10, 2);

    return "files/facturacion_electronica/qr/{$nombreQR}.png";
}

function getFirma($NomArch){
    $ruta   = 'files/facturacion_electronica/FIRMA/';
    $xml    = simplexml_load_file($ruta. $NomArch . '.xml');
    foreach ($xml->xpath('//ds:DigestValue') as $response) {

    }
    return $response;
}