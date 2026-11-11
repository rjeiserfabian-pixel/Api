<?php
require_once './libraries/dompdf/autoload.inc.php';
use Dompdf\Dompdf;
$dompdf = new Dompdf();

header('Access-Control-Allow-Origin: *');
header("HTTP/1.1");

require 'libraries/Numletras.php';
require 'libraries/Variables_diversas_model.php';
require_once ('libraries/qr/phpqrcode/qrlib.php');

$datos = file_get_contents("php://input");
$obj = json_decode($datos, true);

$empresa            = $obj['empresa'];
$proveedor          = $obj['proveedor'];
$lugar_operacion    = $obj['lugar_operacion'];
$venta              = $obj['venta'];

$total_igv          = ($venta['total_igv'] != null) ? $venta['total_igv'] : 0.0;
$total_gravada      = ($venta['total_gravada'] == null)     ? 0 : $venta['total_gravada'];
$total_exonerada    = ($venta['total_exonerada'] == null)   ? 0 : $venta['total_exonerada'];
$total_inafecta     = ($venta['total_inafecta'] == null)    ? 0 : $venta['total_inafecta'];    
$total_a_pagar      = number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv), 2, '.', '');
$venta['total_a_pagar'] = $total_a_pagar;


//var_dump($cliente);exit;

$detalle = array();
foreach ($obj['items'] as $value){
    $detalle[] = ($value);
}

$nombre_archivo = $empresa['ruc'].'-'.$venta['tipo_documento_codigo'].'-'.$venta['serie'].'-'.$venta['numero'];
//$obj_variables_diversas_model = new variables_diversas_model();

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Nepliano</title>
    <style>
        *{
            margin: 0;
            padding: 0;
        }
        #cabecera_izquierda{
            font-family: Arial, Helvetica, sans-serif;
            font-weight: bold;
            float: left;
            width: 53%;
            align: right;
            margin-top: 3;
            margin-right: 20;
            padding-top: 3;
            padding-left: 20px;
        }
        #cabecera_derecha{
            font-family: Arial, Helvetica, sans-serif;
            font-weight: bold;
            float: left;
            width: 39%;
            margin-top: 10;
            margin-right: 10;
            padding-top: 10;
            padding-bottom: 10;
            border: 1px solid #000;
            text-align: center;
            border-radius: 5px;
        }
        
        .chico_izquierda{
            float: left;
            align: right;
            width: 70%;
            text-align: right;
            padding-bottom: 10px;
        }
        
        .chico_derecha{
            float: left;
            align: right;
            width: 25%;
            text-align: right;
        }
        
        
        #div_letras{
            width: 45%;
            font-size: 16px;
            font-family: Arial, Helvetica, sans-serif;  
            position: fixed;
            left: 10;
            bottom: 200;
        }
        
        #div_totales{
            font-family: Arial, Helvetica, sans-serif;  
            align: right;
            border: 1px solid #000;
            width: 50%;
            position: fixed;
            left: 289;
            bottom: 60;
        }
        
        .tabla_cabecera{
          font-family: Arial, Helvetica, sans-serif;  
          font-size: 20px;
          padding-left: 20px;
        }
        
        .tabla_detalle{
            font-family: Arial, Helvetica, sans-serif;  
            font-size: 20px;
            padding-left: 30px;
            margin-left: 20px;
            width: 95%;
            border: 1px solid;
        }
        
        .imagen_qr{
          position: fixed;
          left: 20;
          bottom: 70;  
        }
        
        .footer {
          font-family: Arial, Helvetica, sans-serif;
          margin-left: 20px;
          margin-bottom: 20px;
          font-size: 20px;
          position: fixed;
          left: 0;
          bottom: 0;
          width: 95%;
          text-align: center;
          border: 1px solid #000;
        }
    </style>
</head>
<body>
    <div id="cabecera_izquierda">
        <img src="https://facturaciondirecta.com/API_SUNAT/logocoinca.jpg"/><br>
        <spam><?php echo $empresa['razon_social']?></spam><br><br>
        <spam><?php echo $empresa['domicilio_fiscal']?></spam><br>
        <spam><?php echo $empresa['distrito']." ".$empresa['provincia']." ".$empresa['departamento']?></spam>
    </div>
    <div id="cabecera_derecha">
        LIQUIDACIÓN DE COMPRA ELECTRÓNICA<br>
        RUC:<?php echo $empresa['ruc']."<br>"?>
        <?php echo $venta['serie']." ".$venta['numero']?>
    </div>
    <div style="clear: left;"></div>
    <br><br>
    <table class="tabla_cabecera">
        <tr>
            <td>Fecha de Emisión:</td>
            <td><?php echo $venta['fecha_emision'];?></td>
        </tr>
        <tr>
            <td>Señor (es):</td>
            <td><?php echo $proveedor['nombres'];?></td>
        </tr>
        <tr>
            <td>DNI:</td>
            <td><?php echo $proveedor['numero_documento'];?></td>
        </tr>
        <tr>
            <td>Dirección del vendedor:</td>
            <td><?php echo $proveedor['direccion']." - ".$proveedor['departamento']." ".$proveedor['provincia']. " ".$proveedor['distrito'];?></td>
        </tr>
        <tr>
            <td>Lugar de la operación:</td>
            <td><?php echo $lugar_operacion['direccion']." - ".$lugar_operacion['departamento']." ".$lugar_operacion['provincia']. " ".$lugar_operacion['distrito'];?></td>
        </tr>
        <tr>
            <td>Tipo de Moneda:</td>
            <?php
            $moneda = '';
            $moneda = ($venta['moneda_id'] == 2) ? 'dólares' : 'soles';
            ?>
            <td><? echo $moneda;?></td>
        </tr>
        <tr>
            <td>Observaciones:</td>
            <td><?php echo $venta['nota']?></td>
        </tr>
    </table>
    
    <br><br>
    <table class="tabla_detalle">
        <tr>
            <td>Cantidad</td>
            <td>Unidad Medida</td>
            <td>Código</td>
            <td>Descripción</td>
            <td>Valor Unitario</td>
        </tr>
        <?php
        foreach($detalle as $value){?>
        <tr>
            <td><?php echo $value['cantidad']?></td>
            <td><?php echo $value['codigo_unidad']?></td>
            <td><?php echo $value['codigo_producto']?></td>
            <td><?php echo $value['producto']?></td>
            <td><?php echo $value['precio_base']?></td>
        </tr>
        <?php
        }
        ?>
    </table>    
    
    <?php
    $tipo_documento = '04';
    $rutaqr = GetImgQr($venta, $empresa, $tipo_documento, $proveedor);
    
    $descripcion_moneda = 'soles';
    $num = new Numletras();
    $totalVenta = explode(".", $total_a_pagar);
    $totalLetras = $num->num2letras($totalVenta[0]);
    $monto_leras = $totalLetras.' con '.$totalVenta[1].'/100 '.$descripcion_moneda;
    ?>
    <div class="imagen_qr">
        <img width="120" src="https://facturaciondirecta.com/API_SUNAT/<?php echo $rutaqr?>"/><br>
    </div>
    
    <div id="div_letras"><?php echo $monto_leras;?></div>
    <div id="div_totales">
        <div class="chico_izquierda">Total Valor de Venta del Producto</div>
        <div class="chico_derecha"><?php echo number_format(($total_gravada + $total_exonerada + $total_inafecta), 2, '.', '');?></div>
        <div style="clear: left;"></div>
        
        <div class="chico_izquierda">IGV (18%):</div>
        <div class="chico_derecha"><?php echo number_format(($total_igv), 2, '.', '');?></div>
        <div style="clear: left;"></div>
        
        <div class="chico_izquierda">Total de Venta del Producto Comprado</div>
        <div class="chico_derecha"><?php echo number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv), 2, '.', '')?></div>
        <div style="clear: left;"></div>
        
        <div class="chico_izquierda">IGV-Crédito:</div>
        <div class="chico_derecha"></div>
        <div style="clear: left;"></div>
        
        <div class="chico_izquierda">IR-Retención:</div>
        <div class="chico_derecha"></div>
        <div style="clear: left;"></div>
        
        <div class="chico_izquierda">Anticipos:</div>
        <div class="chico_derecha"></div>
        <div style="clear: left;"></div>
        
        <div class="chico_izquierda"><b>Importe Total Neto:</b></div>
        <div class="chico_derecha"><?php echo number_format(($total_gravada + $total_exonerada + $total_inafecta + $total_igv), 2, '.', '')?></div>
        <div style="clear: left;"></div>
    </div>
    <div class="footer">
      <p>Esta es una representación impresa de la liquidación de Compra Electrónica. Puede verificarla en SUNAT.</p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

function GetImgQr($venta, $empresa, $tipo_documento, $proveedor)  {
    $textoQR = '';
    $textoQR .= $empresa['ruc']."|";//RUC EMPRESA

    $textoQR .= $tipo_documento."|";//TIPO DE DOCUMENTO 
    $textoQR .= $venta['serie']."|";//SERIE
    $textoQR .= $venta['numero']."|";//NUMERO
    $textoQR .= $venta['total_igv']."|";//MTO TOTAL IGV
    $textoQR .= $venta['total_a_pagar']."|";//MTO TOTAL DEL COMPROBANTE
    $textoQR .= $venta['fecha_emision']."|";//FECHA DE EMISION 

    //tipo de proveedor     
    $textoQR .= "01|";//TIPO DE DOCUMENTO ADQUIRENTE 
    $textoQR .= $proveedor['numero_documento']."|";//NUMERO DE DOCUMENTO ADQUIRENTE 

    $nombreQR = '04-'.$venta['serie'].'-'.$venta['numero'];
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

$options = $dompdf->getOptions();
$options->set(array('isRemoteEnabled' => true));
$dompdf->setOptions($options);

$dompdf->loadHtml($html);
$dompdf->setPaper('letter');
$dompdf->render();

//$dompdf->stream("neple.pdf", array("Attachment" => true));

$output = $dompdf->output();
//file_put_contents($nombre_archivo.'_2.pdf', $output);
file_put_contents('files/facturacion_electronica/PDF/'.$nombre_archivo.'.pdf', $output);
?>