<?php
require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
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

$empresa['modo'] = 0;
$venta['total_a_pagar'] = number_format($detalle[0]['cantidad'] * $detalle[0]['precio'], 2);
$venta['total_gravada'] = number_format( ($venta['total_a_pagar'] / 1.18) , 2);
$venta['total_igv'] = number_format( ($venta['total_a_pagar'] - $venta['total_gravada']) , 2);
$venta['total_exonerada'] = null;
$venta['total_inafecta'] = null;

$venta['fecha_emision'] = date("Y-m-d");
$venta['hora_emision'] = date("H:i:s");
$venta['fecha_vencimiento'] = null;

$detalle[0]['tipo_igv_codigo'] = 10;
$detalle[0]['precio_base'] = ($detalle[0]['precio']/1.18);
$detalle[0]['codigo_producto'] = 'c_1020';
$detalle[0]['codigo_sunat'] = '-';

$nombre_archivo = $empresa['ruc'].'-'.$venta['tipo_documento_codigo'].'-'.$venta['serie'].'-'.$venta['numero'];
crear_pdf($empresa, $cliente, $venta, $detalle, $nombre_archivo);

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
    
    $pdf->Output('files/pdf_firma/'. $nombre .'.pdf', 'F');    
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