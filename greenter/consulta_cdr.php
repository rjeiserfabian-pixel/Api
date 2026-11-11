<?php
use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\SoapClient;

require 'vendor/autoload.php';

$rucEmisor      = $_GET['ruc'];
$tipoDocumento  = $_GET['tipo']; // 01: Factura, 07: Nota de Crédito, 08: Nota de Débito
$serie          = $_GET['serie'];
$correlativo    = $_GET['numero'];
$user_sec_usu   = $_GET['user_sec_usu'];
$user_sec_pass  = $_GET['user_sec_pass'];


// URL CDR de Producción
$wsdlUrl = 'https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService?wsdl';
$soap = new SoapClient($wsdlUrl);
$soap->setCredentials($rucEmisor.$user_sec_usu, $user_sec_pass);

$service = new ConsultCdrService();
$service->setClient($soap);

$result = $service->getStatusCdr($rucEmisor, $tipoDocumento, $serie, $correlativo);

if (!$result->isSuccess()) {
    var_dump($result->getError());
    return;
}

$cdr = $result->getCdrResponse();
if ($cdr === null) {
    echo 'CDR no encontrado, el comprobante no ha sido comunicado a SUNAT.';
    return;
}

$ruta = '../files/facturacion_electronica/FIRMA/';
file_put_contents($ruta."R-$rucEmisor-$tipoDocumento-$serie-$correlativo.zip", $result->getCdrZip());