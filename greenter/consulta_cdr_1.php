<?php

use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\SoapClient;

require 'vendor/autoload.php';

// URL CDR de Producción
$wsdlUrl = 'https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService?wsdl';
$soap = new SoapClient($wsdlUrl);
$soap->setCredentials('20533177078FABIAN75', 'Fabian75');

$service = new ConsultCdrService();
$service->setClient($soap);

$rucEmisor = '20533177078';
$tipoDocumento = '01'; // 01: Factura, 07: Nota de Crédito, 08: Nota de Débito
$serie = 'F001';
$correlativo = '304';
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

file_put_contents('R-20533177078-01-F001-304.zip', $result->getCdrZip());
var_dump($cdr);