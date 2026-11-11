<?php
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Sale\Document;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;

require __DIR__.'/vendor/autoload.php';
$see = require __DIR__.'/config.php';

$ticket = '1711689169134';
$statusResult = $see->getStatus($ticket);
if (!$statusResult->isSuccess()) {
    // Si hubo error al conectarse al servicio de SUNAT.
    var_dump($statusResult->getError());
    return;
}

echo $statusResult->getCdrResponse()->getDescription();
//exit;
// Guardar CDR
$name = '20000000001-RC-20200801-004';
file_put_contents('R-'.$name.'.zip', $statusResult->getCdrZip());