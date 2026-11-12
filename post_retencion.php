<?php
require_once __DIR__ . '/libraries/guard.php';
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

$emisor                 = $obj['emisor'];
$proveedor              = $obj['proveedor'];
$retencion              = $obj['retencion'];

$array_documento_relacionado = array();
foreach ($obj['documento_relacionados'] as $value){
    $array_documento_relacionado[] = ($value);
}

$nombre_archivo = $emisor['ruc'].'-20-'.$retencion['serie'].'-'.$retencion['numero'];
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
$rpta = crear_xml($nombre, $emisor, $proveedor, $array_documento_relacionado, $retencion);

firmar_xml($nombre_archivo.".xml", $emisor['modo']);
ws_sunat($emisor, $nombre_archivo, $obj_variables_diversas_model);

//$nombre = FCPATH."/files/facturacion_electronica/XML/".$nombre_archivo.".xml";
//$nombre = basename(dirname(__FILE__)) . "files/facturacion_electronica/XML/".$nombre_archivo.".xml";
function crear_xml($nombre, $emisor, $proveedor, $array_documento_relacionado, $retencion){
    $xml = desarrollo_xml($emisor, $proveedor, $array_documento_relacionado, $retencion);
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
    $xml = $factura->firmar($domDocument, '', $entorno, substr(basename($name_file), 0, 11));
    $content = $xml->saveXML();
    file_put_contents($carpeta."FIRMA/".$name_file, $content);
}

function desarrollo_xml($emisor, $proveedor, $array_documento_relacionado, $retencion){
    $xml = '<?xml version="1.0" encoding="UTF-8"?>
    <Retention xmlns="urn:sunat:names:specification:ubl:peru:schema:xsd:Retention-1" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ccts="urn:un:unece:uncefact:documentation:2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" xmlns:qdt="urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2" xmlns:sac="urn:sunat:names:specification:ubl:peru:schema:xsd:SunatAggregateComponents-1" xmlns:udt="urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <ext:UBLExtensions>
        <ext:UBLExtension>
            <ext:ExtensionContent/>
        </ext:UBLExtension>
    </ext:UBLExtensions>

    <cbc:UBLVersionID>2.0</cbc:UBLVersionID>
    <cbc:CustomizationID>1.0</cbc:CustomizationID>

    <cac:Signature>
        <cbc:ID>'.$emisor['ruc'].'</cbc:ID>
        <cac:SignatoryParty>
            <cac:PartyIdentification>
                <cbc:ID>'.$emisor['ruc'].'</cbc:ID>
            </cac:PartyIdentification>
            <cac:PartyName>
                <cbc:Name>'.$emisor['razon_social'].'</cbc:Name>
            </cac:PartyName>
        </cac:SignatoryParty>
        <cac:DigitalSignatureAttachment>
            <cac:ExternalReference>
                <cbc:URI>'.$emisor['ruc'].'</cbc:URI>
            </cac:ExternalReference>
        </cac:DigitalSignatureAttachment>
    </cac:Signature>

    <cbc:ID>'.$retencion['serie'].'-'.$retencion['numero'].'</cbc:ID>
    <cbc:IssueDate>'.$retencion['fecha_de_emision'].'</cbc:IssueDate>

    <cac:AgentParty>
        <cac:PartyIdentification>
            <cbc:ID schemeID="6">'.$emisor['ruc'].'</cbc:ID>
        </cac:PartyIdentification>
        <cac:PartyName>
            <cbc:Name>'.$emisor['nombre_comercial'].'</cbc:Name>
        </cac:PartyName>
        <cac:PostalAddress>
            <cbc:ID>'.$emisor['ubigeo'].'</cbc:ID>
            <cbc:StreetName>'.$emisor['domicilio_fiscal'].'</cbc:StreetName>
            <cbc:CitySubdivisionName>'.$emisor['urbanizacion'].'</cbc:CitySubdivisionName>
            <cbc:CityName>'.$emisor['provincia'].'</cbc:CityName>
            <cbc:CountrySubentity>'.$emisor['departamento'].'</cbc:CountrySubentity>
            <cbc:District>'.$emisor['distrito'].'</cbc:District>
            <cac:Country>
                <cbc:IdentificationCode>PE</cbc:IdentificationCode>
            </cac:Country>
        </cac:PostalAddress>
        <cac:PartyLegalEntity>
            <cbc:RegistrationName>'.$emisor['razon_social'].'</cbc:RegistrationName>
        </cac:PartyLegalEntity>
    </cac:AgentParty>
    <cac:ReceiverParty>
        <cac:PartyIdentification>
            <cbc:ID schemeID="6">'.$proveedor['ruc'].'</cbc:ID>
        </cac:PartyIdentification>
        <cac:PartyLegalEntity>
            <cbc:RegistrationName>'.$proveedor['razon_social'].'</cbc:RegistrationName>
        </cac:PartyLegalEntity>
    </cac:ReceiverParty>

    <sac:SUNATRetentionSystemCode>'.$retencion['codigo'].'</sac:SUNATRetentionSystemCode>
    <sac:SUNATRetentionPercent>'.$retencion['porcentaje'].'</sac:SUNATRetentionPercent>
    <cbc:Note>'.$retencion['nota'].'</cbc:Note>
    <cbc:TotalInvoiceAmount currencyID="PEN">'.$retencion['total_retenido'].'</cbc:TotalInvoiceAmount>
    <sac:SUNATTotalPaid currencyID="PEN">'.$retencion['total_facturado_menos_retenido'].'</sac:SUNATTotalPaid>';

    foreach($array_documento_relacionado as $documento_relacionado){
        $codigo_moneda = "";
        switch ($documento_relacionado['moneda']) {
            case "1":
                $codigo_moneda = "PEN";
                break;
            case "2":
                $codigo_moneda = "USD";
                break;
            case "3":
                $codigo_moneda = "EUR";
                break;
        }
        
        $retencion_monto = ($codigo_moneda == "1") ? $documento_relacionado['retencion_monto'] : ($documento_relacionado['retencion_monto'] * $documento_relacionado['moneda_tipo_cambio']);
        $total_menos_retencion = ($codigo_moneda == "1") ? ($documento_relacionado['pago_monto'] - $documento_relacionado['retencion_monto']) : (($documento_relacionado['pago_monto'] * $documento_relacionado['moneda_tipo_cambio']) - $retencion_monto);
    
    $xml .= '<sac:SUNATRetentionDocumentReference>
        <cbc:ID schemeID="'.$documento_relacionado['relacionado_tipo_documento'].'">'.$documento_relacionado['relacionado_serie'].'-'.$documento_relacionado['relacionado_numero'].'</cbc:ID>
        <cbc:IssueDate>'.$documento_relacionado['relacionado_fecha_emision'].'</cbc:IssueDate>
        <cbc:TotalInvoiceAmount currencyID="'.$codigo_moneda.'">'.$documento_relacionado['relacionado_monto_total'].'</cbc:TotalInvoiceAmount>
        <cac:Payment>
            <cbc:ID>'.$documento_relacionado['pago_numero'].'</cbc:ID>
            <cbc:PaidAmount currencyID="'.$codigo_moneda.'">'.$documento_relacionado['pago_monto'].'</cbc:PaidAmount>
            <cbc:PaidDate>'.$documento_relacionado['pago_fecha'].'</cbc:PaidDate>
        </cac:Payment>
        <sac:SUNATRetentionInformation>
            <sac:SUNATRetentionAmount currencyID="PEN">'.$retencion_monto.'</sac:SUNATRetentionAmount>
            <sac:SUNATRetentionDate>'.$documento_relacionado['retencion_fecha'].'</sac:SUNATRetentionDate>
            <sac:SUNATNetTotalPaid currencyID="PEN">'.$total_menos_retencion.'</sac:SUNATNetTotalPaid>';

            if($documento_relacionado['moneda'] != "1"){
    $xml .= '<cac:ExchangeRate>
                <cbc:SourceCurrencyCode>'.$codigo_moneda.'</cbc:SourceCurrencyCode>
                <cbc:TargetCurrencyCode>PEN</cbc:TargetCurrencyCode>
                <cbc:CalculationRate>'.$documento_relacionado['moneda_tipo_cambio'].'</cbc:CalculationRate>
                <cbc:Date>'.$documento_relacionado['moneda_fecha_cambio'].'</cbc:Date>
            </cac:ExchangeRate>';
            }
    $xml  .='</sac:SUNATRetentionInformation>
    </sac:SUNATRetentionDocumentReference>';
    }
    
    $xml .= '</Retention>';
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
    $url = $ruta_dominio."/ws_sunat/index_retencion.php?numero_documento=".$nombre_archivo."&cod_1=1&cod_2=".$empresa['modo']."&cod_3=".$empresa['ruc']."&cod_4=".$user_sec_usu."&cod_5=".$user_sec_pass."&cod_6=1";
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
    $impuesto_igv       = 0.18;
    $venta['total_a_pagar'] = $total_a_pagar;
  
    $array_moneda = $obj_variables_diversas_model->monedas($venta['moneda_id']);
    
	$descuento_global = isset($venta['descuento_global']) ? $venta['descuento_global'] : 0;
    if(isset($venta['descuento_global']) && ($venta['descuento_global'] != '') && ($venta['descuento_global'] > 0)){
        $descuento_global = $venta['descuento_global'];
        $total_igv = number_format((($total_gravada -$venta['descuento_global'])*$impuesto_igv), 2, '.', '');    
    }
    
    $detraccion = 0;
    if(isset($venta['detraccion_codigo']) && ($venta['detraccion_codigo'] != '')){    
        $detraccion = number_format($venta['detraccion_porcentaje'] * $total_a_pagar * (0.01) ,0);
    }
        
    $num = new Numletras();    
    $totalVenta = explode(".", number_format(($total_a_pagar - $detraccion), 2, '.',''));
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
    $pdf->Cell(120, 5,utf8_decode($cliente['cliente_direccion']),0,1,'L');
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
    $pdf->MultiCell(0,10, utf8_decode($venta['nota']));

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