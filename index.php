<?php
require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
require 'libraries/efactura.php';

require_once ('libraries/fpdf/fpdf.php');
require_once ('libraries/qr/phpqrcode/qrlib.php');

header('Access-Control-Allow-Origin: *');

$array = $_POST['datosJSON'];
$array_llegada = json_decode($array);

$empresa    = get_object_vars($array_llegada->empresa);
$cliente    = get_object_vars($array_llegada->cliente);
$venta      = get_object_vars($array_llegada->venta);

$detalle = array();
foreach ($array_llegada->items as $value){
    $detalle[] = get_object_vars($value);
}

$nombre_archivo = $empresa['ruc'].'-'.$venta['tipo_documento_codigo'].'-'.$venta['serie'].'-'.$venta['numero'];
$nombre = "files/facturacion_electronica/XML/".$nombre_archivo.".xml";

if(file_exists($nombre)){
    unlink($nombre);  
}

$rpta = crear_xml($nombre, $empresa, $cliente, $venta, $detalle);
firmar_xml($nombre_archivo.".xml", $empresa['modo']);
ws_sunat($empresa, $nombre_archivo);

crear_pdf($empresa, $cliente, $venta, $detalle, $nombre_archivo);

//$nombre = FCPATH."/files/facturacion_electronica/XML/".$nombre_archivo.".xml";
//$nombre = basename(dirname(__FILE__)) . "files/facturacion_electronica/XML/".$nombre_archivo.".xml";
function crear_xml($nombre, $empresa, $cliente, $venta, $detalle){
    $xml = desarrollo_xml($empresa, $cliente, $venta, $detalle);
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

function desarrollo_xml($empresa, $cliente, $venta, $detalles){
    $num = new Numletras();
    $totalVenta = explode(".",  $venta['total_a_pagar']);
    $totalLetras = $num->num2letras($totalVenta[0]);
    $venta['total_letras'] = $totalLetras.' con '.$totalVenta[1].'/100 soles';

    $codigo_moneda = 'PEN';
    
    $linea_inicio       = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ccts="urn:un:unece:uncefact:documentation:2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:qdt="urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2" xmlns:udt="urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
    $linea_fin          = 'Invoice';
    $InvoiceTypeCode    = '<cbc:InvoiceTypeCode listID="0101" listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01" name="Tipo de Operacion" listSchemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo51">' . $venta['tipo_documento_codigo'] . '</cbc:InvoiceTypeCode>';
    $tag_total_pago     = 'LegalMonetaryTotal';

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
                if($venta['fecha_vencimiento'] != null) {
                    $xml .= '<cbc:DueDate>' . $venta['fecha_vencimiento'] . '</cbc:DueDate>';
                };
    $xml .= $InvoiceTypeCode.'<cbc:Note languageLocaleID="1000">'.$venta['total_letras'].'</cbc:Note>
            <cbc:DocumentCurrencyCode listID="ISO 4217 Alpha" listName="Currency" listAgencyName="United Nations Economic Commission for Europe">'. $codigo_moneda .'</cbc:DocumentCurrencyCode>';

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

    /////////////Forma de pago  --  INICIO
    $xml .= '<cac:PaymentTerms>
                <cbc:ID>FormaPago</cbc:ID>
                <cbc:PaymentMeansID>Contado</cbc:PaymentMeansID>
            </cac:PaymentTerms>';
    /////////////Forma de pago  --  FIN        

    $total_igv = ($venta['total_igv'] != null) ? $venta['total_igv'] : 0.0;
    $xml .=  '<cac:TaxTotal>
                <cbc:TaxAmount currencyID="'. $codigo_moneda .'">'. $total_igv .'</cbc:TaxAmount>';
        if($venta['total_gravada'] != null){                                            
        $xml .=  '<cac:TaxSubtotal>
                    <cbc:TaxableAmount currencyID="'. $codigo_moneda .'">' . $venta['total_gravada'] . '</cbc:TaxableAmount>
                    <cbc:TaxAmount currencyID="'. $codigo_moneda .'">' . $total_igv . '</cbc:TaxAmount>
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
    $xml .=  '</cac:TaxTotal>';
        
    $total_gravada      = ($venta['total_gravada'] == null)     ? 0 : $venta['total_gravada'];
    $total_exonerada    = ($venta['total_exonerada'] == null)   ? 0 : $venta['total_exonerada'];
    $total_inafecta     = ($venta['total_inafecta'] == null)    ? 0 : $venta['total_inafecta'];    
    $xml .=  '<cac:'.$tag_total_pago.'>                                
                <cbc:LineExtensionAmount currencyID="'. $codigo_moneda .'">' . number_format(($total_gravada + $total_exonerada + $total_inafecta), 2, '.', '') . '</cbc:LineExtensionAmount>
                <cbc:TaxInclusiveAmount currencyID="'. $codigo_moneda .'">' . number_format($venta['total_a_pagar'], 2, '.', '') . '</cbc:TaxInclusiveAmount>
                <cbc:AllowanceTotalAmount currencyID="'. $codigo_moneda .'">0.00</cbc:AllowanceTotalAmount>
                <cbc:ChargeTotalAmount currencyID="'. $codigo_moneda .'">0.00</cbc:ChargeTotalAmount>
                <cbc:PrepaidAmount currencyID="'. $codigo_moneda .'">0.00</cbc:PrepaidAmount>
                <cbc:PayableAmount currencyID="'. $codigo_moneda .'">' . number_format($venta['total_a_pagar'], 2, '.', ''). '</cbc:PayableAmount>
            </cac:'.$tag_total_pago.'>';
    $i = 1;
        
    $obj_variables_diversas_model = new variables_diversas_model();
    $percent = $obj_variables_diversas_model->porcentaje_valor_igv;        
    
    foreach($detalles as $value){
        $icbper             = 00.00;
        $codigos            = $obj_variables_diversas_model->datos_codigo_tributo($value['tipo_igv_codigo']);
        $descuento          = 0;
        
        $priceAmount        = $obj_variables_diversas_model->priceAmount($value['precio_base'], $codigos['codigo_tributo'], $percent, $icbper, $descuento);
        $PriceTypeCode      = ($codigos['codigo_tributo'] == 9996) ? '02' : '01';
        $taxAmount          = $obj_variables_diversas_model->taxAmount($value['cantidad'], $value['precio_base'], $codigos['codigo_tributo'], $percent, $descuento);
        $price_priceAmount  = $obj_variables_diversas_model->price_priceAmount($value['precio_base'], $codigos['codigo_tributo'], $descuento);
        
        //sale del catalgo16
        //PriceAmount precio unitario (precio base x (1 + IGV)) + impuesto por 1 bolsa. (en caso no se pague IGV sera 1 + 0).        

        $xml .= '<cac:InvoiceLine>
                <cbc:ID>'.$i.'</cbc:ID>
                <cbc:InvoicedQuantity unitCode="NIU">'. number_format($value['cantidad'], 2, '.', '') .'</cbc:InvoicedQuantity>
                <cbc:LineExtensionAmount currencyID="' . $codigo_moneda . '">'. number_format($value['cantidad'] * ($value['precio_base']), 2, '.', '').'</cbc:LineExtensionAmount>
                <cac:PricingReference>
                    <cac:AlternativeConditionPrice>
                        <cbc:PriceAmount currencyID="' . $codigo_moneda . '">' . abs(number_format($priceAmount, 6, '.', '')) .'</cbc:PriceAmount>
                        <cbc:PriceTypeCode listName="Tipo de Precio" listAgencyName="PE:SUNAT" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo16">' . $PriceTypeCode . '</cbc:PriceTypeCode>
                    </cac:AlternativeConditionPrice>
                </cac:PricingReference>';        

        $xml .=     '<cac:TaxTotal>
                        <cbc:TaxAmount currencyID="' . $codigo_moneda . '">'. number_format(($taxAmount + $icbper * $value['cantidad']), 2, '.', '') .'</cbc:TaxAmount>
                        <cac:TaxSubtotal>
                            <cbc:TaxableAmount currencyID="' . $codigo_moneda . '">' . number_format(($value['precio_base']) * $value['cantidad'] ,2, '.', '') . '</cbc:TaxableAmount>
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
                        <cbc:PriceAmount currencyID="' . $codigo_moneda . '">' . abs($price_priceAmount) . '</cbc:PriceAmount>
                    </cac:Price>
            </cac:InvoiceLine>
            ';
        $i++;
    }
    $xml .=  '</'.$linea_fin.'>';
    return $xml;
}

function ws_sunat($empresa, $nombre_archivo){
        //enviar a Sunat
        //cod_1: Select web Service: 1 factura, boletas --- 9 es para guias
        //cod_2: Entorno:  0 Beta, 1 Produccion
        //cod_3: ruc
        //cod_4: usuario secundario USU(segun seha beta o producción)
        //cod_5: usuario secundario PASSWORD(segun seha beta o producción)
        //cod_6: Accion:   1 enviar documento a Sunat --  2 enviar a anular  --  3 enviar ticket
        //cod_7: serie de documento
        //cod_8: numero ticket
        //$ruta_dominio = "https://grupofact.com/API_SUNAT/ws";
        $ruta_dominio = "https://facturacionintegral.com/aplicaciones_sistemas/API_SUNAT/";
        $user_sec_usu = ($empresa['modo'] == 1) ? $empresa['usu_secundario_produccion_user'] : 'MODDATOS';
        $user_sec_pass = ($empresa['modo'] == 1) ? $empresa['usu_secundario_produccion_password'] : 'moddatos';
        $url = $ruta_dominio."/ws_sunat/index.php?numero_documento=".$nombre_archivo."&cod_1=1&cod_2=".$empresa['modo']."&cod_3=".$empresa['ruc']."&cod_4=".$user_sec_usu."&cod_5=".$user_sec_pass."&cod_6=1";
        //echo $url;exit;
        $data = file_get_contents($url);
        $info = json_decode($data, TRUE);
        
        $respuesta_codigo = '';
        $respuesta_mensaje = '';
//        if($info['error_existe'] == 0){
//            $respuesta_sunat = $this->leerRespuestaSunat($nombre_archivo.".xml");
//            if($respuesta_sunat != null){
//                $this->ventas_model->modificar($venta_id, $respuesta_sunat);
//                $this->ventas_model->errores_sunat($respuesta_sunat['respuesta_sunat_codigo'], $venta_id);
//            }
//            //var_dump($respuesta_sunat);
//            $respuesta_mensaje = ($respuesta_sunat != null) ? $respuesta_sunat['respuesta_sunat_descripcion']: '';
//            $respuesta_codigo = ($respuesta_sunat != null) ? $respuesta_sunat['respuesta_sunat_codigo']: '';
//        }
        $jsondata = array(
            'success'       =>  true,
            'codigo'        =>  $respuesta_codigo,
            'error_existe'  =>  $info['error_existe'],
            'message'       =>  $respuesta_mensaje.$info['error_mensaje']
        );
        echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);
    }
    
function crear_pdf($empresa, $cliente, $venta, $detalle, $nombre){
  
    $num = new Numletras();    
    $totalVenta = explode(".", $venta['total_a_pagar']);
    $totalLetras = $num->num2letras($totalVenta[0]);
    $totalLetras = 'Son: '.$totalLetras.' con '.$totalVenta[1].'/100 soles';    
  
    $fijo = 233 + 10;
    $ancho = 8.4;
    $numero_filas = count($detalle);        
    $total_y = $fijo + $ancho * $numero_filas; 

    $pdf = new FPDF('P', 'mm', array(80, $total_y));
    $pdf->SetMargins(2, 2, 2);
    $pdf->AddPage();

    $tamano_x = 60;
    $tamano_y = 40; 
    //$ruta_foto = FCPATH."images/empresas/".$empresa['foto'];                
    $ruta_foto = 'logo.PNG';

    $pdf->Image($ruta_foto,10,0,$tamano_x,$tamano_y);
    $pdf->Ln($tamano_y);
    
    $pdf->SetFont('Arial','',12);
    $pdf->Cell(74, 6, $empresa["nombre_comercial"], 'B', 1, 'C');

    $pdf->SetFont('Arial','',9);
    $pdf->Cell(74, 6, $empresa["razon_social"], 0, 1, 'C');

    $pdf->Cell(74,6,"RUC: ".$empresa["ruc"],0,1,'C');
    $pdf->MultiCell(74,5, utf8_decode($empresa["domicilio_fiscal"]));
    $pdf->Cell(74,1,"-----------------------------------------------------------------------------",0,0,'C');
    $pdf->Ln(4);
    
    switch ($venta['tipo_documento_codigo']) {
        case '01':
            $tipo_documento = 'FACTURA';
            break;        
        case '03':
            $tipo_documento = 'BOLETA';
            break;        
    }        

    $pdf->Cell(74,7,utf8_decode($tipo_documento." DE VENTA ELECTRÓNICA"),0,0,'L');
    $pdf->Ln(5);
    $pdf->Cell(74,7,$venta["serie"]."-".$venta["numero"],0,0,'L');
    $pdf->Ln(5);
    $pdf->Cell(74,7,"Fecha/hora emision:".$venta["fecha_emision"],0,0,'L');
    $pdf->Ln(5);
    $pdf->Cell(74,7,"Vendedor: Juan Perez",0,0,'L');
    $pdf->Ln(5);
    $pdf->Cell(74,1,"-----------------------------------------------------------------------------",0,0,'C');        
    $pdf->Ln(4);
    
    switch ($cliente['codigo_tipo_entidad']) {
        case '1':
            $tipo_documento_cliente = 'DNI';
            break;        
        case '6':
            $tipo_documento_cliente = 'RUC';
            break;        
    }    
    
    $pdf->MultiCell(74,5, utf8_decode("Cliente: " . $cliente["razon_social_nombres"]));
    $pdf->Cell(74, 7,utf8_decode($tipo_documento_cliente . ": ". $cliente['numero_documento']),0,1,'L');
    $pdf->Cell(74,1,"-----------------------------------------------------------------------------",0,1,'C');
    
    $pdf->Cell(16,7,"Productos",'B',0,'L');
    $pdf->Cell(45,7,"",0,0,'L');
    $pdf->Cell(9,7,"Total",'B',0,'R');
    $pdf->Ln(4);
        
    $impuesto = 1.18;
    $pdf->Cell(60,10,utf8_decode($detalle[0]['producto']),0,0,'L');
    $pdf->Cell(10,10,number_format(($detalle[0]['cantidad']*($detalle[0]['precio_base']*$impuesto)), 2),0,0,'R');
    $pdf->Ln(4);
    $pdf->Cell(12,10,number_format($detalle[0]['precio_base']*$impuesto,2)." x ".$detalle[0]['cantidad'],0,0,'L');
    $pdf->Ln(4);
    
    $pdf->Ln(4);
    $pdf->Cell(74,1,"-----------------------------------------------------------------------------",0,1,'C');
    
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(30, 7, "", 0, 0, 'R');
    $pdf->Cell(20, 7, "Gravada: ", 0, 0, 'L');
    $pdf->Cell(20, 7, "S/. " . $venta['total_gravada'], 0, 0, 'R');
    $pdf->Ln(6);
    
    $pdf->Cell(30,7,"",0,0,'R');
    $pdf->Cell(20,7,"IGV: 18% ",0,0,'L');
    $pdf->Cell(20,7,"S/. ".$venta['total_igv'],0,0,'R');
    $pdf->Ln(7);

    $pdf->Cell(30,7,"",0,0,'R');
    $pdf->Cell(20,7,"Total:",0,0,'L');
    $pdf->Cell(20,7,"S/. ".$venta['total_a_pagar'],0,1,'R');
    $pdf->Ln(4);
    
    $pdf->MultiCell(0,5, utf8_decode($totalLetras));        
    $rutaqr = GetImgQr($venta, $empresa, $tipo_documento, $cliente);
    
    $respuesta  = getFirma($nombre);
    $pdf->Cell(1,10,"",0,1,'C');
    $pdf->Cell(70,10,$respuesta,0,1,'C');
    
    $pdf->Image($rutaqr, 21, 170, 40, 40);
    
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
    QRcode::png($textoQR, "files/qr/".$nombreQR.".png", QR_ECLEVEL_L, 10, 2);

    return "files/qr/{$nombreQR}.png";
}

function getFirma($NomArch){
    $ruta   = 'files/facturacion_electronica/FIRMA/';
    $xml    = simplexml_load_file($ruta. $NomArch . '.xml');
    foreach ($xml->xpath('//ds:DigestValue') as $response) {

    }
    return $response;
}