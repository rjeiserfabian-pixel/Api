<?php
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Sale\Document;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;

require __DIR__.'/vendor/autoload.php';
$see = require __DIR__.'/config.php';

$company = new Company();
$company->setRuc('20000000001')
    ->setRazonSocial('EMPRESA SAC')
    ->setNombreComercial('EMPRESA')
    ->setAddress((new Address())
        ->setUbigueo('150101')
        ->setDepartamento('LIMA')
        ->setProvincia('LIMA')
        ->setDistrito('LIMA')
        ->setUrbanizacion('Albar')
        ->setDireccion('AV ROSALES'));      

$detail = new SummaryDetail();
$detail->setTipoDoc('07') // Nota de Credito
    ->setSerieNro('B002-4')
    ->setDocReferencia((new Document()) // Documento relacionado (Boleta)
        ->setTipoDoc('03')
        ->setNroDoc('B001-1'))
    ->setEstado('1') // Emisión
    ->setClienteTipo('1') // Tipo documento identidad: DNI
    ->setClienteNro('00000000') // Nro de documento identidad
    ->setTotal(200)
    ->setMtoOperGravadas(40)
    ->setMtoOperExoneradas(50)
    ->setMtoOperInafectas(100)
    ->setMtoIGV(7.2)
    ->setMtoISC(2.8);

$detail2 = new SummaryDetail();
$detail2->setTipoDoc('03') // Boleta
    ->setSerieNro('B002-2')
    ->setEstado('3') // Anulación
    ->setClienteTipo('1')
    ->setClienteNro('00000000')
    ->setTotal(119)
    ->setMtoOperGravadas(20)
    ->setMtoOperInafectas(24.4)
    ->setMtoOperExoneradas(50)
    ->setMtoOtrosCargos(21)
    ->setMtoIGV(3.6);

$resumen = new Summary();
$resumen->setFecGeneracion(new \DateTime('2024-03-29')) // Fecha de emisión de las boletas.
    ->setFecResumen(new \DateTime('2024-03-29')) // Fecha de envío del resumen diario.
    ->setCorrelativo('004') // Correlativo, necesario para diferenciar de otros Resumen diario del mismo día.
    ->setCompany($company)
    ->setDetails([$detail, $detail2]);

$result = $see->send($resumen);
// Guardar XML
file_put_contents($resumen->getName().'.xml',
                  $see->getFactory()->getLastXml());

if (!$result->isSuccess()) {
    // Si hubo error al conectarse al servicio de SUNAT.
    var_dump($result->getError());
    exit();
}

$ticket = $result->getTicket();
echo 'Ticket : '.$ticket.PHP_EOL;