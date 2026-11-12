<?php
require_once __DIR__ . '/libraries/guard.php';
require_once __DIR__ . '/libraries/errores.php';

//echo "abc";exit;

header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

// Permitir encabezados específicos, como Content-Type y Authorization
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Opcional: responder inmediatamente a las solicitudes OPTIONS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

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
//

$empresa        = $obj['empresa'];
$cliente        = $obj['cliente'];

$venta          = $obj['venta'];
$cuotas         = isset($obj['cuotas']) ? $obj['cuotas'] : array();
$guias_adjuntas = isset($obj['guias_adjuntas']) ? $obj['guias_adjuntas'] : array();
$anticipos      = isset($obj['anticipos']) ? $obj['anticipos'] : array();
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
crear_xml($nombre, $empresa, $cliente, $venta, $detalle, $cuotas, $guias_adjuntas, $obj_variables_diversas_model, $anticipos);
firmar_xml($nombre_archivo.".xml", $empresa['modo']);
ws_sunat($empresa, $nombre_archivo, $obj_variables_diversas_model);

if(($venta['tipo_documento_codigo'] == '01') || ($venta['tipo_documento_codigo'] == '03') || ($venta['tipo_documento_codigo'] == '07')){
    //if(!isset($venta['total_gratuita']) || ($venta['total_gratuita'] == 0)){
        //crear_pdf($empresa, $cliente, $venta, $detalle, $nombre_archivo, $cuotas, $guias_adjuntas, $obj_variables_diversas_model);
    //}    
}

//$nombre = FCPATH."/files/facturacion_electronica/XML/".$nombre_archivo.".xml";
//$nombre = basename(dirname(__FILE__)) . "files/facturacion_electronica/XML/".$nombre_archivo.".xml";
function crear_xml($nombre, $empresa, $cliente, $venta, $detalle, $cuotas, $guias_adjuntas, $obj_variables_diversas_model, $anticipos){
    $xml = desarrollo_xml($empresa, $cliente, $venta, $detalle, $cuotas, $guias_adjuntas, $obj_variables_diversas_model, $anticipos);
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

function desarrollo_xml($empresa, $cliente, $venta, $detalles, $cuotas, $guias_adjuntas, $obj_variables_diversas_model, $anticipos){
    
    $total_igv          = ($venta['total_igv'] != null) ? $venta['total_igv'] : 0.0;
    $total_gravada      = ($venta['total_gravada'] == null)     ? 0 : $venta['total_gravada'];
    $total_exonerada    = ($venta['total_exonerada'] == null)   ? 0 : $venta['total_exonerada'];
    $total_inafecta     = ($venta['total_inafecta'] == null)    ? 0 : $venta['total_inafecta'];
    $total_bolsa        = (isset($venta['total_bolsa']) && ($venta['total_bolsa'] != null)) ? $venta['total_bolsa'] : 0;
    $total_gratuito     = (isset($venta['total_gratuita']) && ($venta['total_gratuita'] != null)) ? $venta['total_gratuita'] : 0;
    $total_gratuita_igv = (isset($venta['total_gratuita_igv']) && ($venta['total_gratuita_igv'] != null)) ? $venta['total_gratuita_igv'] : 0;
    $total_a_pagar      = number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv + $total_bolsa), 2, '.', '');
    $impuesto_igv       = 0.18;
    
    $array_moneda = moneda($venta['moneda_id']);
    $codigo_moneda = $array_moneda[0];
    $descripcion_moneda = $array_moneda[1];    

    $venta['total_letras'] = total_letras($total_a_pagar, $total_gratuito, $descripcion_moneda);
    
    $linea_inicio   = '';
    $linea_fin   = '';
    $tag_total_pago = '';
    $dato_nc = '';
    $linea = '';
    $cantidad = '';
    $PrepaidAmount = 0;
    
    $retencion = (isset($venta['retencion_porcentaje']) && ($venta['retencion_porcentaje'] != '') && ($venta['retencion_porcentaje'] != null)) ? number_format(($total_a_pagar * $venta['retencion_porcentaje'] * (0.01)), 2, '.', '') : 0;
    
    //catalogo 51
    $tipo_operacion = (isset($venta['percepcion_porcentaje']) && ($venta['percepcion_porcentaje'] != '') && ($venta['percepcion_porcentaje'] != null)) ? "2001" : "0101";

    $tipo_operacion = "0101";
    if(isset($venta['percepcion_porcentaje']) && ($venta['percepcion_porcentaje'] != '') && ($venta['percepcion_porcentaje'] != null)) 
        $tipo_operacion = "2001";
    if(isset($venta['detraccion_porcentaje']) && ($venta['detraccion_porcentaje'] != '') && ($venta['detraccion_porcentaje'] != null)) 
        $tipo_operacion = "1001";

    
    switch ($venta['tipo_documento_codigo']) {
        case '01':
            $linea_inicio   = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ccts="urn:un:unece:uncefact:documentation:2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:qdt="urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2" xmlns:udt="urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
            $linea_fin   = 'Invoice';
            $InvoiceTypeCode = '<cbc:InvoiceTypeCode listID="' . $tipo_operacion . '" listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01" name="Tipo de Operacion" listSchemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo51">' . $venta['tipo_documento_codigo'] . '</cbc:InvoiceTypeCode>';
            $tag_total_pago = 'LegalMonetaryTotal';
            $linea      = 'InvoiceLine';
            $cantidad   = 'InvoicedQuantity';
            break;

        case '03':
            $linea_inicio   = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ccts="urn:un:unece:uncefact:documentation:2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:qdt="urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2" xmlns:udt="urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
            $linea_fin   = 'Invoice';
            $InvoiceTypeCode = '<cbc:InvoiceTypeCode listID="' . $tipo_operacion . '" listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01" name="Tipo de Operacion" listSchemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo51">' . $venta['tipo_documento_codigo'] . '</cbc:InvoiceTypeCode>';
            $tag_total_pago = 'LegalMonetaryTotal';
            $linea      = 'InvoiceLine';
            $cantidad   = 'InvoicedQuantity';
            break;

        case '07':
            $linea_inicio   = '<CreditNote xmlns="urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">';
            $linea_fin   = 'CreditNote';
            $InvoiceTypeCode = '';

            $dato_nc = '<cac:DiscrepancyResponse>
                <cbc:ReferenceID>'.$venta['relacionado_serie'].'-'.$venta['relacionado_numero'].'</cbc:ReferenceID>
                <cbc:ResponseCode>'.$venta['relacionado_motivo_codigo'].'</cbc:ResponseCode>
                <cbc:Description>'.$obj_variables_diversas_model->tipo_nota_credito($venta['relacionado_motivo_codigo']).'</cbc:Description>
            </cac:DiscrepancyResponse>
            <cac:BillingReference>
                <cac:InvoiceDocumentReference>
                    <cbc:ID>'.$venta['relacionado_serie'].'-'.$venta['relacionado_numero'].'</cbc:ID>
                    <cbc:DocumentTypeCode>'.$venta['relacionado_tipo_documento'].'</cbc:DocumentTypeCode>
                </cac:InvoiceDocumentReference>
            </cac:BillingReference>';
            $tag_total_pago = 'LegalMonetaryTotal';
            
            $linea      = 'CreditNoteLine';
            $cantidad   = 'CreditedQuantity';
            break;

            case '08':
            $linea_inicio   = '<DebitNote xmlns="urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ccts="urn:un:unece:uncefact:documentation:2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:qdt="urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2" xmlns:udt="urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
            $linea_fin   = 'DebitNote';
            $InvoiceTypeCode = '';
            $dato_nc = '<cac:DiscrepancyResponse>
                <cbc:ReferenceID>'.$venta['relacionado_serie'].'-'.$venta['relacionado_numero'].'</cbc:ReferenceID>
                <cbc:ResponseCode>'.$venta['relacionado_motivo_codigo'].'</cbc:ResponseCode>
                <cbc:Description>'.$obj_variables_diversas_model->tipo_nota_credito($venta['relacionado_motivo_codigo']).'</cbc:Description>
            </cac:DiscrepancyResponse>
            <cac:BillingReference>
                <cac:InvoiceDocumentReference>
                    <cbc:ID>'.$venta['relacionado_serie'].'-'.$venta['relacionado_numero'].'</cbc:ID>
                    <cbc:DocumentTypeCode>'.$venta['relacionado_tipo_documento'].'</cbc:DocumentTypeCode>
                </cac:InvoiceDocumentReference>
            </cac:BillingReference>';
            $tag_total_pago = 'RequestedMonetaryTotal';
            
            $linea      = 'DebitNoteLine';
            $cantidad   = 'DebitedQuantity';
            break;
    }    

    $xml =  '<?xml version="1.0" encoding="ISO-8859-1" standalone="no"?>'.$linea_inicio.'<ext:UBLExtensions>
                    <ext:UBLExtension>
                        <ext:ExtensionContent></ext:ExtensionContent>
                    </ext:UBLExtension>
                </ext:UBLExtensions>
                <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
                <cbc:CustomizationID>2.0</cbc:CustomizationID>
                <cbc:ID>'.$venta['serie'].'-'.$venta['numero'].'</cbc:ID>
                <cbc:IssueDate>'.$venta['fecha_emision'].'</cbc:IssueDate>
                <cbc:IssueTime>'.$venta['hora_emision'].'</cbc:IssueTime>';
                if(($venta['fecha_vencimiento'] != null) && (($venta['tipo_documento_codigo'] == '01') || ($venta['tipo_documento_codigo'] == '03'))) {
                    $xml .= '<cbc:DueDate>' . $venta['fecha_vencimiento'] . '</cbc:DueDate>';
                };
    $xml .= $InvoiceTypeCode;


    $xml_note = '<cbc:Note languageLocaleID="1000">'.$venta['total_letras'].'</cbc:Note>';
    if(isset($venta['percepcion_porcentaje']) && ($venta['percepcion_porcentaje'] != '') && ($venta['percepcion_porcentaje'] != null)){
        //Para percepciones
        $xml_note = '<cbc:Note languageLocaleID="2000">COMPROBANTE DE PERCEPCIÓN</cbc:Note>';    
    }

    if(isset($venta['detraccion_porcentaje']) && ($venta['detraccion_porcentaje'] != '') && ($venta['detraccion_porcentaje'] != null)){
        $xml_note = '<cbc:Note languageLocaleID="2006"><![CDATA[ Operación sujeta a detracción Cta Cte Banco de la Nación ]]>'.$empresa['cuenta_detraccion'].'</cbc:Note>';
    }

    $xml .= $xml_note;
    
    $xml .='<cbc:DocumentCurrencyCode listID="ISO 4217 Alpha" listName="Currency" listAgencyName="United Nations Economic Commission for Europe">'. $codigo_moneda .'</cbc:DocumentCurrencyCode>'.$dato_nc;
    
    /////--Orden de compra  -- INICIO
    if((isset($venta['orden_compra'])) && ($venta['orden_compra'] != "") && ($venta['orden_compra'] != null) && (($venta['tipo_documento_codigo'] == "01") || ($venta['tipo_documento_codigo'] == "03"))) {
        $xml .= '<cac:OrderReference>
        <cbc:ID>'.$venta['orden_compra'].'</cbc:ID>
        </cac:OrderReference>';
    };
    /////--Orden de compra  -- FIN
    
    foreach ($guias_adjuntas as $value_guias){
            $xml .= '<cac:DespatchDocumentReference>
            <cbc:ID>' . $value_guias['guia_serie'] . '-' . $value_guias['guia_numero'] . '</cbc:ID>
            <cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01">' . $value_guias['guia_codigo_documento'] . '</cbc:DocumentTypeCode>
            </cac:DespatchDocumentReference>';
    }

    //--Para anticipos INICIO--
    $ij = 1;
    foreach ($anticipos as $value_anticipos){
        //codigo documento relacionado (Catalogo 12 Sunat)
        if(substr($value_anticipos['serie'], 0, 1) == 'F'){
            $codigo_documento_relacionado = '02';
        }elseif(substr($value_anticipos['serie'], 0, 1) == 'B'){
            $codigo_documento_relacionado = '03';
        }
        $xml .= '<cac:AdditionalDocumentReference>
                <cbc:ID schemeID="01">'.$value_anticipos['serie'].'-'.$value_anticipos['numero'].'</cbc:ID>
                <cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Documento Relacionado" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo12">'.$codigo_documento_relacionado.'</cbc:DocumentTypeCode>
                <cbc:DocumentType>ANTICIPO</cbc:DocumentType>
                <cbc:DocumentStatusCode listAgencyName="PE:SUNAT" listName="Anticipo">'.$ij.'</cbc:DocumentStatusCode>
                <cac:IssuerParty>
                        <cac:PartyIdentification>
                                <cbc:ID schemeAgencyName="PE:SUNAT" schemeID="'.$cliente['codigo_tipo_entidad'].'" schemeName="Documento de Identidad" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">'.$cliente['numero_documento'].'</cbc:ID>
                        </cac:PartyIdentification>
                </cac:IssuerParty>
            </cac:AdditionalDocumentReference>';
        $ij ++;
    }
    //--Para anticipos FIN--                                        
    
    $xml .= '<cac:Signature>
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
            <cac:Party>
                <cac:PartyIdentification>
                    <cbc:ID schemeID="6">'.$empresa['ruc'].'</cbc:ID>
                </cac:PartyIdentification>
                <cac:PartyName>
                    <cbc:Name><![CDATA['.$empresa['nombre_comercial'].']]></cbc:Name>
                </cac:PartyName>
                <cac:PartyLegalEntity>
                    <cbc:RegistrationName><![CDATA['.$empresa['razon_social'].']]></cbc:RegistrationName>
                    <cac:RegistrationAddress>
                        <cbc:ID schemeName="Ubigeos" schemeAgencyName="PE:INEI">'.$empresa['ubigeo'].'</cbc:ID>
                        <cbc:AddressTypeCode listAgencyName="PE:SUNAT" listName="Establecimientos anexos">0000</cbc:AddressTypeCode>
                        <cbc:CityName>'.$empresa['provincia'].'</cbc:CityName>
                        <cbc:CountrySubentity>'.$empresa['departamento'].'</cbc:CountrySubentity>
                        <cbc:District>'.$empresa['distrito'].'</cbc:District>
                        <cac:AddressLine>
                            <cbc:Line>'.$empresa['domicilio_fiscal'].'</cbc:Line>
                        </cac:AddressLine>
                        <cac:Country>
                            <cbc:IdentificationCode listID="ISO 3166-1" listAgencyName="United Nations Economic Commission for Europe" listName="Country">PE</cbc:IdentificationCode>
                        </cac:Country>
                    </cac:RegistrationAddress>
                </cac:PartyLegalEntity>
            </cac:Party>
        </cac:AccountingSupplierParty>
        <cac:AccountingCustomerParty>
            <cac:Party>
                <cac:PartyIdentification>
                    <cbc:ID schemeID="'.$cliente['codigo_tipo_entidad'].'" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">'.$cliente['numero_documento'].'</cbc:ID>
                </cac:PartyIdentification>
                <cac:PartyLegalEntity>
                    <cbc:RegistrationName><![CDATA['.$cliente['razon_social_nombres'].']]></cbc:RegistrationName>
                </cac:PartyLegalEntity>
            </cac:Party>
        </cac:AccountingCustomerParty>';
    
        ////////////////////////////////////////DETRACCION --- INICO
        $detraccion = 0;
        if(isset($venta['detraccion_porcentaje']) && ($venta['detraccion_porcentaje'] != '') && ($venta['detraccion_porcentaje'] != null) && ($venta['detraccion_porcentaje'] > 0)){
            $detraccion = number_format($total_a_pagar * $venta['detraccion_porcentaje']*(0.01), 0, '.', '');                        

            $xml .= '<cac:PaymentMeans>
                        <cbc:ID>Detraccion</cbc:ID>
                        <cbc:PaymentMeansCode>001</cbc:PaymentMeansCode>
                        <cac:PayeeFinancialAccount>
                            <cbc:ID>'.$empresa['cuenta_detraccion'].'</cbc:ID>
                        </cac:PayeeFinancialAccount>
                    </cac:PaymentMeans>
                    <cac:PaymentTerms>
                        <cbc:ID>Detraccion</cbc:ID>
                        <cbc:PaymentMeansID schemeName="Codigo de detraccion" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo54">'.$venta['detraccion_codigo'].'</cbc:PaymentMeansID>
                        <cbc:PaymentPercent>'.$venta['detraccion_porcentaje'].'</cbc:PaymentPercent>
                        <cbc:Amount currencyID="'.$codigo_moneda.'">'. $detraccion .'</cbc:Amount>
                    </cac:PaymentTerms>';
        }
        ////////////////////////////////////////DETRACCION --- FIN    

        /////////////Forma de pago  --  INICIO   - solo para facturas y boletas.
        //facturas 01, boletas 03
        if(($venta['tipo_documento_codigo'] == '01') || ($venta['tipo_documento_codigo'] == '03') || (($venta['tipo_documento_codigo'] == '07') && $venta['tipo_documento_codigo'] == '13')){
            if($venta['forma_pago_id'] == 1){
                $xml .= '<cac:PaymentTerms>
                            <cbc:ID>FormaPago</cbc:ID>
                            <cbc:PaymentMeansID>Contado</cbc:PaymentMeansID>
                        </cac:PaymentTerms>';
            }
            if($venta['forma_pago_id'] == 2){
                $xml .= '<cac:PaymentTerms>
                        <cbc:ID>FormaPago</cbc:ID>
                        <cbc:PaymentMeansID>Credito</cbc:PaymentMeansID>
                        <cbc:Amount currencyID="'.$codigo_moneda.'">' . number_format(($total_a_pagar - $detraccion - $retencion), 2, '.', '') . '</cbc:Amount>
                    </cac:PaymentTerms>';

                $contar_cuota = 1;
                foreach($cuotas as $value_cuotas){
                    $xml .= '<cac:PaymentTerms>
                                <cbc:ID>FormaPago</cbc:ID>
                                <cbc:PaymentMeansID>Cuota00'.$contar_cuota.'</cbc:PaymentMeansID>
                                <cbc:Amount currencyID="'.$codigo_moneda.'">' . number_format($value_cuotas['monto'], 2, '.', '') . '</cbc:Amount>
                                <cbc:PaymentDueDate>' . $value_cuotas['fecha_cuota'] . '</cbc:PaymentDueDate>
                            </cac:PaymentTerms>';
                    $contar_cuota ++;
                }
            }
        }
        /////////////Forma de pago  --  FIN            
        
        ////////////////////////////////////////PERCEPCION --- INICO
        $percepcion = 0;
        if(isset($venta['percepcion_porcentaje']) && ($venta['percepcion_porcentaje'] != '') && ($venta['percepcion_porcentaje'] != null) && ($venta['percepcion_porcentaje'] > 0)){
            $percepcion = number_format($total_a_pagar * $venta['percepcion_porcentaje']*(0.01), 2, '.', '');
            
            $xml .= '<cac:PaymentTerms>
                        <cbc:ID>Percepcion</cbc:ID>
                        <cbc:Amount currencyID="'.$codigo_moneda.'">'. number_format(($total_a_pagar + $percepcion), 2, '.', '') .'</cbc:Amount>
                    </cac:PaymentTerms>';
                
            $xml .= '<cac:AllowanceCharge>
                        <cbc:ChargeIndicator>true</cbc:ChargeIndicator>
                        <cbc:AllowanceChargeReasonCode listAgencyName="PE:SUNAT" listName="Cargo/descuento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo53">'.$venta['percepcion_codigo'].'</cbc:AllowanceChargeReasonCode>
                        <cbc:MultiplierFactorNumeric>'.($venta['percepcion_porcentaje'] * (0.01)).'</cbc:MultiplierFactorNumeric>
                        <cbc:Amount currencyID="'. $codigo_moneda .'">'. $percepcion .'</cbc:Amount>
                        <cbc:BaseAmount currencyID="'. $codigo_moneda .'">'. $total_a_pagar .'</cbc:BaseAmount>
                    </cac:AllowanceCharge>';
        }                        
        ////////////////////////////////////////PERCEPCION --- FIN    

        //--Para anticipos INICIO--
        $anticipo_igv = 0;
        $anticipo_grabada = 0;
                    
        $ij = 1;
        foreach ($anticipos as $value_anticipos){
            $xml .= '<cac:PrepaidPayment>
                        <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Anticipo">'.$ij.'</cbc:ID>
                        <cbc:PaidAmount currencyID="'. $codigo_moneda .'">'.($value_anticipos['total_base'] + $value_anticipos['total_igv']).'</cbc:PaidAmount>
                    </cac:PrepaidPayment>';
            $ij ++;
            $anticipo_grabada   += $value_anticipos['total_base'];
            $anticipo_igv       += $value_anticipos['total_igv'];            
        }
        $anticipo_total = $anticipo_grabada + $anticipo_igv;
        //--Para anticipos FIN-- 
        
        if( count($anticipos) > 0){
            $xml .= '<cac:AllowanceCharge>
            <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
            <cbc:AllowanceChargeReasonCode listAgencyName="PE:SUNAT" listName="Cargo/descuento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo53">04</cbc:AllowanceChargeReasonCode>
            <cbc:MultiplierFactorNumeric>1.00</cbc:MultiplierFactorNumeric>
            <cbc:Amount currencyID="'. $codigo_moneda .'">'. $anticipo_grabada .'</cbc:Amount>
            <cbc:BaseAmount currencyID="'. $codigo_moneda .'">'. $anticipo_grabada .'</cbc:BaseAmount>
            </cac:AllowanceCharge>';
        }
        
        ////////////////////////////DESCUENTO GLOBAL
        $descuento_global = isset($venta['descuento_global']) ? $venta['descuento_global']: 0;
        if(isset($venta['descuento_global']) && ($venta['descuento_global'] != '') && ($venta['descuento_global'] > 0)){
            //se recalcula el IGV
            $total_igv = number_format((($total_gravada -$venta['descuento_global'])*$impuesto_igv), 2, '.', '');
            
            $descuento_global = $venta['descuento_global'];
            $xml .= '<cac:AllowanceCharge>
                        <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
                        <cbc:AllowanceChargeReasonCode listAgencyName="PE:SUNAT" listName="Cargo/descuento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo53">02</cbc:AllowanceChargeReasonCode>
                        <cbc:MultiplierFactorNumeric>' . number_format(($venta['descuento_global']/($total_gravada + $total_exonerada + $total_inafecta)), 5, '.', '') . '</cbc:MultiplierFactorNumeric>
                        <cbc:Amount currencyID="' . $codigo_moneda . '">' . number_format($venta['descuento_global'], 2, '.', '') . '</cbc:Amount>
                        <cbc:BaseAmount currencyID="' . $codigo_moneda . '">' . number_format(($total_gravada + $total_exonerada + $total_inafecta), 2, '.', '') . '</cbc:BaseAmount>
                    </cac:AllowanceCharge>';
        }
        ////////////////////////////////////////////

        ////////////////////////////////////////RETENCION            
        if(isset($venta['retencion_porcentaje']) && ($venta['retencion_porcentaje'] != '') && ($venta['retencion_porcentaje'] != null) && ($venta['retencion_porcentaje'] > 0)){
            $xml .= '<cac:AllowanceCharge> 
                <cbc:ChargeIndicator>false</cbc:ChargeIndicator> 
                <cbc:AllowanceChargeReasonCode listAgencyName="PE:SUNAT" listName="Cargo/descuento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo53">62</cbc:AllowanceChargeReasonCode> 
                <cbc:MultiplierFactorNumeric>' . $venta['retencion_porcentaje'] * (0.01) . '</cbc:MultiplierFactorNumeric>
                <cbc:Amount currencyID="' . $codigo_moneda . '">' . number_format(( ($total_a_pagar - ($PrepaidAmount* (1 + $impuesto_igv))) * $venta['retencion_porcentaje'] * (0.01)), 2, '.', '') . '</cbc:Amount>
                <cbc:BaseAmount currencyID="' . $codigo_moneda . '">' . number_format(($total_a_pagar - ($PrepaidAmount* (1 + $impuesto_igv))), 2, '.', '') . '</cbc:BaseAmount>
            </cac:AllowanceCharge>';
        }
        ////////////////////////////////////////

    $xml .=  '<cac:TaxTotal>
                <cbc:TaxAmount currencyID="'. $codigo_moneda .'">'. ($total_igv - $anticipo_igv + $total_bolsa) .'</cbc:TaxAmount>';
        if($venta['total_gravada'] != null){                                            
        $xml .=  '<cac:TaxSubtotal>
                    <cbc:TaxableAmount currencyID="'. $codigo_moneda .'">' .  number_format(($venta['total_gravada'] - $descuento_global - $anticipo_grabada), 2, '.', '') . '</cbc:TaxableAmount>
                    <cbc:TaxAmount currencyID="'. $codigo_moneda .'">' . ($total_igv - $anticipo_igv) . '</cbc:TaxAmount>
                    <cac:TaxCategory>
                        <cac:TaxScheme>
                            <cbc:ID schemeName="Codigo de tributos" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">1000</cbc:ID>
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
                            <cbc:ID schemeName="Codigo de tributos" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">9997</cbc:ID>
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
                            <cbc:ID schemeName="Codigo de tributos" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">9998</cbc:ID>
                            <cbc:Name>INA</cbc:Name>
                            <cbc:TaxTypeCode>FRE</cbc:TaxTypeCode>
                        </cac:TaxScheme>
                    </cac:TaxCategory>
                </cac:TaxSubtotal>';
        };

        if(isset($total_gratuito) && ($total_gratuito != null)){
            $xml .=  '<cac:TaxSubtotal>
                        <cbc:TaxableAmount currencyID="'. $codigo_moneda .'">' . $total_gratuito . '</cbc:TaxableAmount>
                        <cbc:TaxAmount currencyID="'. $codigo_moneda .'">' . $total_gratuita_igv . '</cbc:TaxAmount>
                        <cac:TaxCategory>
                            <cac:TaxScheme>
                                <cbc:ID schemeName="Codigo de tributos" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">9996</cbc:ID>
                                <cbc:Name>GRA</cbc:Name>
                                <cbc:TaxTypeCode>FRE</cbc:TaxTypeCode>
                            </cac:TaxScheme>
                        </cac:TaxCategory>
                    </cac:TaxSubtotal>';
        };        

        if(isset($venta['total_bolsa']) && ($venta['total_bolsa'] != null)){
            $xml .= '<cac:TaxSubtotal>
                    <cbc:TaxAmount currencyID="'.$codigo_moneda.'">' . $venta['total_bolsa'] . '</cbc:TaxAmount>
                        <cac:TaxCategory>
                            <cac:TaxScheme>
                                <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Codigo de tributos" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">7152</cbc:ID>
                                <!--  ICBPER: Nombre de ICBPER  -->
                                <cbc:Name>ICBPER</cbc:Name>
                                <!--  ICBPER: Codigo Internacional de ICBPER  -->
                                <cbc:TaxTypeCode>OTH</cbc:TaxTypeCode>
                            </cac:TaxScheme>
                        </cac:TaxCategory>
                    </cac:TaxSubtotal>';            
        };        
    $xml .=  '</cac:TaxTotal>';        
    $xml .=  '<cac:'.$tag_total_pago.'>                                
                <cbc:LineExtensionAmount currencyID="'. $codigo_moneda .'">' . number_format(($total_gravada + $total_exonerada + $total_inafecta - $descuento_global), 2, '.', '') . '</cbc:LineExtensionAmount>
                <cbc:TaxInclusiveAmount currencyID="'. $codigo_moneda .'">' . number_format(($total_gravada + $total_exonerada + $total_inafecta - $descuento_global + $total_igv + $total_bolsa), 2, '.', '') . '</cbc:TaxInclusiveAmount>
                <cbc:ChargeTotalAmount currencyID="'. $codigo_moneda .'">0.00</cbc:ChargeTotalAmount>
                <cbc:PrepaidAmount currencyID="'. $codigo_moneda .'">' . $anticipo_total . '</cbc:PrepaidAmount>
                <cbc:PayableAmount currencyID="'. $codigo_moneda .'">' . number_format(($total_gravada + $total_exonerada + $total_inafecta - $descuento_global - $anticipo_total + $total_igv + $total_bolsa), 2, '.', ''). '</cbc:PayableAmount>
            </cac:'.$tag_total_pago.'>';
    $i = 1;            
    $percent = $obj_variables_diversas_model->porcentaje_valor_igv;
    
    foreach($detalles as $value){
        $codigos                = $obj_variables_diversas_model->datos_codigo_tributo($value['tipo_igv_codigo']);
        $descuento              = 0;
        $descuento_precio_base  = (isset($value['descuento_precio_base']) && ($value['descuento_precio_base'] != '') && ($value['descuento_precio_base'] > 0)) ? $value['descuento_precio_base'] : 0;
        $bolsa_binario          = isset($value['bolsa']) ? $value['bolsa'] : false;
        
        $priceAmount            = $obj_variables_diversas_model->priceAmount($value['precio_base'], $codigos['codigo_tributo'], $percent, $bolsa_binario, $obj_variables_diversas_model->valor_bolsa_plastica, $descuento_precio_base);
        $PriceTypeCode          = ($codigos['codigo_tributo'] == 9996) ? '02' : '01';
        $taxAmount              = $obj_variables_diversas_model->taxAmount($value['cantidad'], $value['precio_base'], $codigos['codigo_tributo'], $percent, $descuento_precio_base);
        $tax_by_product         = $obj_variables_diversas_model->tax_by_product($value['cantidad'], $value['precio_base'], $codigos['codigo_tributo'], $percent, $descuento_precio_base, $total_gratuita_igv);
        $price_priceAmount      = $obj_variables_diversas_model->price_priceAmount($value['precio_base'], $codigos['codigo_tributo'], $descuento);
        $total_bolsa            = (isset($value['bolsa']) && ($value['bolsa'] === true)) ? ($value['cantidad'] * $obj_variables_diversas_model->valor_bolsa_plastica) : 0;
        
        //sale del catalgo16
        //PriceAmount precio unitario (precio base x (1 + IGV)) + impuesto por 1 bolsa. (en caso no se pague IGV sera 1 + 0).                

        $xml .= '<cac:'.$linea.'>
                <cbc:ID>'.$i.'</cbc:ID>
                <cbc:'.$cantidad.' unitCode="'.$value['codigo_unidad'].'">'. number_format($value['cantidad'], 2, '.', '') .'</cbc:'.$cantidad.'>
                <cbc:LineExtensionAmount currencyID="' . $codigo_moneda . '">'. number_format($value['cantidad'] * (($value['precio_base'] - $descuento_precio_base)), 2, '.', '').'</cbc:LineExtensionAmount>
                <cac:PricingReference>
                    <cac:AlternativeConditionPrice>
                        <cbc:PriceAmount currencyID="' . $codigo_moneda . '">' . abs(number_format($priceAmount, 6, '.', '')) .'</cbc:PriceAmount>
                        <cbc:PriceTypeCode listName="Tipo de Precio" listAgencyName="PE:SUNAT" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo16">' . $PriceTypeCode . '</cbc:PriceTypeCode>
                    </cac:AlternativeConditionPrice>
                </cac:PricingReference>';
                
        
        //////////////-----INICIO --- Descuento ITEM
        if(isset($value['descuento_precio_base']) && ($value['descuento_precio_base'] != '') && ($value['descuento_precio_base'] > 0)){
        $xml .= '<cac:AllowanceCharge>
                    <cbc:ChargeIndicator>false</cbc:ChargeIndicator>
                    <cbc:AllowanceChargeReasonCode listAgencyName="PE:SUNAT" listName="Cargo/descuento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo53">00</cbc:AllowanceChargeReasonCode>
                    <cbc:MultiplierFactorNumeric>'.number_format((($value['descuento_precio_base'] * $value['cantidad'])/($value['precio_base'] * $value['cantidad'])),5, '.', '')  .'</cbc:MultiplierFactorNumeric>
                    <cbc:Amount currencyID="'.$codigo_moneda.'">'. number_format(($value['descuento_precio_base'] * $value['cantidad']), 2, '.', '') . '</cbc:Amount>
                    <cbc:BaseAmount currencyID="'.$codigo_moneda.'">'. number_format(($value['precio_base'] * $value['cantidad']), 2, '.', '')  .'</cbc:BaseAmount>
                </cac:AllowanceCharge>';
        }
        //////////////-----FIN --- Descuento ITEM

        $xml .=     '<cac:TaxTotal>
                        <cbc:TaxAmount currencyID="' . $codigo_moneda . '">'. number_format(($taxAmount + $total_bolsa), 2, '.', '') .'</cbc:TaxAmount>
                        <cac:TaxSubtotal>
                            <cbc:TaxableAmount currencyID="' . $codigo_moneda . '">' . number_format(($value['precio_base'] - $descuento_precio_base) * $value['cantidad'] ,2, '.', '') . '</cbc:TaxableAmount>
                            <cbc:TaxAmount currencyID="' . $codigo_moneda . '">'. number_format($tax_by_product, 2, '.', '') .'</cbc:TaxAmount>
                            <cac:TaxCategory>
                                <cbc:Percent>' . $percent * 100 . '</cbc:Percent>
                                <cbc:TaxExemptionReasonCode>' . $value['tipo_igv_codigo'] . '</cbc:TaxExemptionReasonCode>
                                <cac:TaxScheme>
                                    <cbc:ID>'.$codigos['codigo_tributo'].'</cbc:ID>
                                    <cbc:Name>'.$codigos['nombre'].'</cbc:Name>
                                    <cbc:TaxTypeCode>'.$codigos['codigo_internacional'].'</cbc:TaxTypeCode>
                                </cac:TaxScheme>
                            </cac:TaxCategory>
                        </cac:TaxSubtotal>';

        if(isset($value['bolsa']) && ($value['bolsa'] === true)){        
        $xml .=     '<cac:TaxSubtotal>
                        <cbc:TaxAmount currencyID="' . $codigo_moneda . '">' . ($value['cantidad'] * $obj_variables_diversas_model->valor_bolsa_plastica) . '</cbc:TaxAmount>
                        <cbc:BaseUnitMeasure unitCode="NIU">' . $value['cantidad'] . '</cbc:BaseUnitMeasure>
                        <cac:TaxCategory>
                            <cbc:PerUnitAmount currencyID="' . $codigo_moneda . '">' . $obj_variables_diversas_model->valor_bolsa_plastica . '</cbc:PerUnitAmount>
                            <cac:TaxScheme>
                                <cbc:ID schemeAgencyName="PE:SUNAT" schemeName="Codigo de tributos" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo05">7152</cbc:ID>
                                <cbc:Name>ICBPER</cbc:Name>
                                <cbc:TaxTypeCode>OTH</cbc:TaxTypeCode>
                            </cac:TaxScheme>
                        </cac:TaxCategory>
                    </cac:TaxSubtotal>';
        }
        $xml .= '</cac:TaxTotal>';
            
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
            </cac:'.$linea.'>
            ';
        $i++;
    }
    $xml .=  '</'.$linea_fin.'>';
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

function crear_pdf($empresa, $cliente, $venta, $detalle, $nombre, $cuotas, $guias_adjuntas, $obj_variables_diversas_model){
    $total_igv          = ($venta['total_igv'] != null) ? $venta['total_igv'] : 0.0;
    $total_gravada      = ($venta['total_gravada'] == null)     ? 0 : $venta['total_gravada'];
    $total_exonerada    = ($venta['total_exonerada'] == null)   ? 0 : $venta['total_exonerada'];
    $total_inafecta     = ($venta['total_inafecta'] == null)    ? 0 : $venta['total_inafecta'];    
    $total_a_pagar      = number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv), 2, '.', '');
    $total_a_pagar_sf   = $total_gravada + $total_exonerada + $total_inafecta + $total_igv;
    $impuesto_igv       = 0.18;
    $venta['total_a_pagar'] = $total_a_pagar;
  
    $array_moneda = $obj_variables_diversas_model->monedas($venta['moneda_id']);
    
	$descuento_global = isset($venta['descuento_global']) ? $venta['descuento_global'] : 0;
    if(isset($venta['descuento_global']) && ($venta['descuento_global'] != '') && ($venta['descuento_global'] > 0)){
        $descuento_global = $venta['descuento_global'];
        $total_igv = number_format((($total_gravada -$venta['descuento_global'])*$impuesto_igv), 2, '.', '');    
    }
    
    $detraccion = 0;
    $detraccion_sf = 0;
    if(isset($venta['detraccion_codigo']) && ($venta['detraccion_codigo'] != '')){    
        $detraccion = number_format($venta['detraccion_porcentaje'] * $total_a_pagar * (0.01) ,0);
        $detraccion_sf = $venta['detraccion_porcentaje'] * $total_a_pagar * (0.01);
    }
        
    $num = new Numletras();    
    $formatea = number_format(($total_a_pagar_sf - $detraccion_sf), 2, '.','');
    $totalVenta = explode(".", $formatea);
    
    $totalLetras = $num->num2letras($totalVenta[0]);
    $totalLetras = 'Son: '.$totalLetras.' con '.$totalVenta[1].'/100 ' . $array_moneda['moneda'];

    $pdf = new PDF_MC_Table();
    $pdf->SetMargins(8, 8, 2);
    $pdf->AddPage();
    $pdf->SetFont('Arial','',12);
    
    switch ($venta['tipo_documento_codigo']) {
        case '01':
            $tipo_documento = 'FACTURA';
            break;
        case '03':
            $tipo_documento = 'BOLETA';
            break;
        case '07':
            $tipo_documento = 'NOTA DE CREDITO';
            break;
    }

    $pdf->SetFont('Arial','',18);
    $pdf->Cell(90,12,utf8_decode($tipo_documento." ELECTRÓNICA"), 0, 1, 'L');    
    $pdf->SetFont('Arial','',12);
    $pdf->Cell(74,5,"RUC: ".$empresa["ruc"],0,1,'L');
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(74, 5, utf8_decode($empresa["razon_social"]), 0, 1, 'L');
    $pdf->SetFont('Arial','',10);
    $pdf->MultiCell(74,5, utf8_decode($empresa["domicilio_fiscal"]));            
    $forma_pago = ($venta['forma_pago_id'] == 1) ? 'contado' : 'crédito';
    $pdf->Cell(74, 5, 'Forma de pago: '. utf8_decode($forma_pago), 0, 1, 'L');    

    //    $pdf->SetFont('Arial','',9);
    //    $pdf->Cell(74, 6, $empresa["nombre_comercial"], 0, 1, 'C');

    $tamano_x = 80;
    $tamano_y = 35; 
    $pdf->Image('logo.PNG',120,8,$tamano_x,$tamano_y);
    $pdf->Ln($tamano_y);
    
    $pdf->SetY(45);
        
    switch ($cliente['codigo_tipo_entidad']) {
        case '1':
            $tipo_documento_cliente = 'DNI';
            break;        
        case '6':
            $tipo_documento_cliente = 'RUC';
            break;        
    }        
    $pdf->SetFont('Arial','B',10);
    $pdf->Cell(14, 5, 'Cliente:',0,0,'L');
    $pdf->SetFont('Arial','',10);
    $pdf->MultiCell(120,5, utf8_decode($cliente["razon_social_nombres"]));
    $pdf->Cell(120, 5,utf8_decode($tipo_documento_cliente . ": ". $cliente['numero_documento']),0,1,'L');    
    if(isset($cliente['cliente_direccion'])){
        $pdf->Cell(120, 5,utf8_decode($cliente['cliente_direccion']),0,1,'L');
    }
    $pdf->Cell(120, 5,'Moneda: '. utf8_decode($array_moneda['moneda']), 0, 1, 'L');
    
    $pdf->SetY(50);
    $pdf->SetX(130);
    $pdf->SetFont('Arial','B',13);
    $pdf->Cell(74,7,$venta["serie"]."-".$venta["numero"],0,1,'L');
    
    $aumento = 0;
    if($venta['tipo_documento_codigo'] == 7){
        $pdf->SetY(55);
        $pdf->SetX(130);
        $pdf->SetFont('Arial','',11);
        $pdf->Cell(74,7,"Documento Adjunto: ".$venta["relacionado_serie"]."-".$venta["relacionado_numero"],0, 1, 'L');
        
        $pdf->SetY(60);
        $pdf->SetX(130);
        $motivo = $obj_variables_diversas_model->tipo_nota_credito($venta['relacionado_motivo_codigo']);
        $pdf->Cell(74,7,"Motivo:: ".utf8_decode($motivo), 0, 1, 'L');
        $aumento += 10;
    }
    
    $pdf->SetY(55 + $aumento);
    $pdf->SetX(130);
    $pdf->SetFont('Arial','',10);    
    $pdf->Cell(74,7,"Fecha/hora emision: ".$venta["fecha_emision"]." / ".$venta["hora_emision"],0,0,'L');
    $pdf->SetY(60 + $aumento);
    $pdf->SetX(130);    
    $pdf->Cell(74,7,"Vendedor: Juan Perez",0,0,'L');
    $pdf->Ln(8);
        
    $pdf->Cell(15,7,"Cant.", 0,0,'L');
    $pdf->Cell(15,7,"U.M.", 0,0,'L');
    $pdf->Cell(107,7,"Producto", 0,0,'L');
    $pdf->Cell(28,7,"Precio Unitario", 0,0,'R');
    
    ///////INICIO-DESCUENTO
    $hay_descuento = 0;
    $total_descuento_item = 0;
    foreach ($detalle as $item){
        if(isset($item['descuento_precio_base']) && ($item['descuento_precio_base'] != '') && ($item['descuento_precio_base'] > 0)){
            $hay_descuento = 1;
            $total_descuento_item += ($item['descuento_precio_base'] * $item['cantidad']);
        }
    }
    
    if($hay_descuento == 1){
        $pdf->Cell(10,7,"Descuento", 0,0,'L');    
    }
    //////FIN-DESCUENTO
    
    $pdf->Cell(25,7,"Total", 0,0,'R');
    $pdf->Ln(6);
        
    $impuesto = 1.18;
    
    //NO hay descuento
    if($hay_descuento == 0){
        $pdf->SetWidths(Array(15,15,110,25,25));
        $pdf->SetLineHeight(5);
        foreach ($detalle as $item){
            $pdf->Row(Array(
                $item['cantidad'],
                $item['codigo_unidad'],
                utf8_decode($item['producto']),
                number_format($item['precio_base'], 3, '.', '') ,
                number_format(($item['cantidad']*($item['precio_base']*$impuesto)), 3)
            ));
        }    
    }
    
    //SI hay descuento
    if($hay_descuento == 1){
        $pdf->SetWidths(Array(15,15,110,25,15,25));
        $pdf->SetLineHeight(5);
        $descuento_item = 0;
        foreach ($detalle as $item){
            if(isset($item['descuento_precio_base']) && ($item['descuento_precio_base'] != '') && ($item['descuento_precio_base'] > 0)){
                $descuento_item = $item['descuento_precio_base'];
            }
            $pdf->Row(Array(
                $item['cantidad'],
                $item['codigo_unidad'],
                utf8_decode($item['producto']),
                number_format($item['precio_base'], 3, '.', '') ,
                number_format($item['descuento_precio_base'], 3, '.', '') ,
                number_format(($item['cantidad']*(($item['precio_base']-$descuento_item)*$impuesto)), 3)
            ));
        }    
    }
    
    
    if(isset($venta['total_gravada']) && ($venta['total_gravada'] != '') && ($venta['total_gravada'] > 0)){
        $pdf->Ln(7);
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(150, 6, "", 0, 0, 'R');
        $pdf->Cell(20, 6, "Gravada: ", 0, 0, 'L');
        $pdf->Cell(20, 6, $array_moneda['simbolo'] . " " .  number_format(($total_gravada + $total_descuento_item), 2, '.', ''), 0, 0, 'R');
    }
    
    if(isset($venta['descuento_global']) && ($venta['descuento_global'] != '') && ($venta['descuento_global'] > 0)){
        $pdf->Ln();
        $pdf->Cell(150, 6, "", 0, 0, 'R');
        $pdf->Cell(20, 6, "Descuento: ", 0, 0, 'L');
        $pdf->Cell(20, 6, $array_moneda['simbolo'] . " " .  number_format(($venta['descuento_global']), 2, '.', ''), 0, 0, 'R');
    }
    $pdf->Ln(5);
    
    if($total_descuento_item > 0){
        $pdf->Cell(150,6,"",0,0,'R');
        $pdf->Cell(20,6,"Descuento:",0,0,'L');
        $pdf->Cell(20,6,  number_format($total_descuento_item, 2, '.', '') ,'B',0,'R');
        $pdf->Ln(5);
    }
    
    if(isset($venta['total_igv']) && ($venta['total_igv'] != '') && ($venta['total_igv'] > 0)){
        $pdf->Cell(150,6,"",0,0,'R');
        $pdf->Cell(20,8,"IGV: 18% ",0,0,'L');
        $pdf->Cell(20,8, $array_moneda['simbolo'] . " " . number_format((($total_gravada - $descuento_global) * $impuesto_igv), 2, '.', '') ,0,0,'R');
        $pdf->Ln(5);
    }
    
    if(isset($venta['total_gratuita']) && ($venta['total_gratuita'] != '') && ($venta['total_gratuita'] > 0)){
        $pdf->Cell(150, 8, "", 0, 0, 'R');
        $pdf->Cell(20, 6, "Gratuita:", 0, 0, 'L');
        $pdf->Cell(20, 6, $array_moneda['simbolo'] . " " . number_format(($venta['total_gratuita']), 2, '.', ''), 0, 0, 'R');
        $pdf->Ln(5);
    }
    
    if(isset($venta['total_gratuita_igv']) && ($venta['total_gratuita_igv'] != '') && ($venta['total_gratuita_igv'] > 0)){
        $pdf->Cell(135,6,"",0,0,'R');
        $pdf->Cell(30,8,"IGV Gratuita: 18% ",0,0,'L');
        $pdf->Cell(25,8, $array_moneda['simbolo'] . " " . number_format($venta['total_gratuita_igv'], 2, '.', '') ,0,0,'R');
        $pdf->Ln(5);
    }
    
    $pdf->Ln(5);
    $pdf->Cell(150, 6,"",0,0,'R');
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->Cell(20, 6,"Total:",0,0,'L');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(20, 6, $array_moneda['simbolo'] . " " . number_format(($total_gravada + $total_exonerada + $total_inafecta - $descuento_global + $total_igv), 2, '.', ''),0,1,'R');
    $pdf->Ln(5);
    
    if(isset($venta['detraccion_codigo']) && ($venta['detraccion_codigo'] != '')){
        $pdf->Cell(140,6,"",0,0,'R');
        $pdf->Cell(25,6,utf8_decode("Detracción: "),0,0,'L');
        $pdf->Cell(25,6,$array_moneda['simbolo'] . " " . number_format($venta['detraccion_porcentaje'] * $total_a_pagar * (0.01),0).".00", 0,0,'R');
        $pdf->Ln(5);
        
        $pdf->Cell(140,6,"",0,0,'R');
        $pdf->Cell(25,6, "Neto a pagar: ",0,0,'L');
        $pdf->Cell(25,6,$array_moneda['simbolo'] . " " . number_format($total_a_pagar - ($venta['detraccion_porcentaje'] * $total_a_pagar * (0.01)) ,0).".00" , 0,0,'R');
        $pdf->Ln(5);
    }
    
    if(isset($venta['percepcion_codigo']) && ($venta['percepcion_codigo'] != '')){
        $pdf->Cell(140,6,"",0,0,'R');
        $pdf->Cell(25,6,utf8_decode("Percepción: "),0,0,'L');
        $pdf->Cell(25,6,$array_moneda['simbolo'] . " " . number_format($venta['percepcion_porcentaje'] * $total_a_pagar * (0.01),0).".00", 0,0,'R');
        $pdf->Ln(5);
        
        $pdf->Cell(140,6,"",0,0,'R');
        $pdf->Cell(25,6, "Neto a pagar: ",0,0,'L');
        $pdf->Cell(25,6,$array_moneda['simbolo'] . " " . number_format($total_a_pagar + ($venta['percepcion_porcentaje'] * $total_a_pagar * (0.01)) ,0).".00" , 0,0,'R');
        $pdf->Ln(5);
    }

    if(isset($venta['retencion_porcentaje'])){
        $pdf->Cell(140,6,"",0,0,'R');
        $pdf->Cell(25,6,utf8_decode("Retención: "),0,0,'L');
        $pdf->Cell(25,6,$array_moneda['simbolo'] . " " . number_format($venta['retencion_porcentaje'] * $total_a_pagar * (0.01),0).".00", 0,0,'R');
        $pdf->Ln(5);
        
        $pdf->Cell(140,6,"",0,0,'R');
        $pdf->Cell(25,6, "Neto a pagar: ",0,0,'L');
        $pdf->Cell(25,6,$array_moneda['simbolo'] . " " . number_format($total_a_pagar - ($venta['retencion_porcentaje'] * $total_a_pagar * (0.01)) ,0).".00" , 0,0,'R');
        $pdf->Ln(5);
    }
    
    $pdf->MultiCell(0,5, utf8_decode($totalLetras));
    
    $pdf->Ln(85);
    if(isset($empresa['cuenta_detraccion']) && ($empresa['cuenta_detraccion'] != '')){
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(38, 6, utf8_decode("Cuenta Detracción: "), 0, 0, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(20, 6, $empresa['cuenta_detraccion'], 0, 1, 'L');
    }
    
    $pdf->Ln(1);
    
    if(isset($venta['nota'])){
        $pdf->MultiCell(0,10, utf8_decode($venta['nota']));
    }

    if(($cuotas != array()) && (count($cuotas) > 0)){
        $pdf->Cell(20,10, 'CUOTAS', 0, 0, 'L');
        $pdf->Ln(5);
        $pdf->Cell(10,10, 'N.', 0, 0, 'L');
        $pdf->Cell(25,10, 'Fecha', 0, 0, 'L');
        $pdf->Cell(25,10, 'Monto', 0, 0, 'L');        
        $pdf->Ln(5);
    }
    
    $i = 1;
    foreach ($cuotas as $values){            
        $pdf->Cell(10,10, $i, 0, 0, 'L');
        $pdf->Cell(25,10, $values['fecha_cuota'], 0, 0, 'L');
        $pdf->Cell(25,10, $values['monto'], 0, 0, 'L');        
        $pdf->Ln(5);
        $i++;
    }
    
    /////////////////////////////////////////////////////
    /// order de compra
    if(isset($venta['orden_compra'])){
        $pdf->Ln(7);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(100, 6, "", 0, 0, 'R');
        $pdf->Cell(40, 6, "Orden de compra: ", 0, 0, 'L');
        $pdf->SetFont('Arial', '', 11);
        $pdf->Cell(40, 6, $venta['orden_compra'], 0, 0, 'R');
    }
    
    /////////////GUIAS ADJUNTAS
    //$aumento = 1;
    if(count($guias_adjuntas) > 0){
        $pdf->SetY(185 + $aumento);
        $pdf->SetX(140);
        $pdf->SetFont('Arial','B',10);
        $pdf->Cell(74, 7, utf8_decode("Guias de Remisión:"), 0, 0, 'L');    
        $pdf->Ln(2);

        $pdf->SetFont('Arial','',10);
        $i = 1;
        $contador_y = 1;
        $contador_x = 1;        
        foreach ($guias_adjuntas as $valores){
            $xx = '';
            if(($i % 3) == 0 ){
                $contador_y += 3;
                $contador_x = 0;
            }

            $pdf->SetY(190 + $aumento + $contador_y + $contador_y);
            $pdf->SetX(140 + $contador_x);

            $pdf->Cell(25,10, $valores['guia_serie']."-".$valores['guia_numero'], 0, 0, 'L');

            $contador_x += 20;
            $i++;
        }        
    }        
    //////////////////////////////////////////////////////    
    
    $rutaqr = GetImgQr($venta, $empresa, $tipo_documento, $cliente);            
    $pdf->Image($rutaqr, 80, 240, 40, 40);    
    $respuesta  = getFirma($nombre);                
    
    $pdf->SetY(230);
    $pdf->Cell(190,8, $respuesta,0,1,'C');
    
    $pdf->Output('files/facturacion_electronica/PDF/'. $nombre .'.pdf', 'F');    
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

function total_letras($total_a_pagar, $total_gratuito, $descripcion_moneda){
    if($total_gratuito > 0){
        $venta['total_letras'] = "CERO CON 00/100 SOLES";
    }else{
        $num = new Numletras();
        $totalVenta = explode(".", $total_a_pagar);
        $totalLetras = $num->num2letras($totalVenta[0]);    
        $venta['total_letras'] = $totalLetras.' con '.$totalVenta[1].'/100 '.$descripcion_moneda;       
    }    
    return $venta['total_letras'];
}