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

$obj_variables_diversas_model = new variables_diversas_model();
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
$dompdf->stream("neple.pdf", array("Attachment" => false));
?>