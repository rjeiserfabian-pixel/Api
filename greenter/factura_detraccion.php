<?php

use Greenter\Model\Sale\Detraction;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\SaleDetail;

$invoice = new Invoice();
$invoice
    ->setUblVersion('2.1')
    ->setTipoOperacion('1001') // Catalogo 51: Detracción
    ->setTipoDoc('01')
    ->setSerie('F001')
    ->setCorrelativo('127')
    ->setFechaEmision(new DateTime())
    ->setFormaPago(new FormaPagoContado())
    ->setTipoMoneda('PEN')
    ->setClient($this->getClient())
    ->setCompany($this->getCompany())
    ->setMtoOperGravadas(800)
    ->setMtoIGV(144)
    ->setTotalImpuestos(144)
    ->setValorVenta(800)
    ->setSubTotal(944)
    ->setMtoImpVenta(944)
    ->setDetraccion(
    // MONEDA SIEMPRE EN SOLES
        (new Detraction())
            // Carnes y despojos comestibles
            ->setCodBienDetraccion('014') // catalog. 54
            // Deposito en cuenta
            ->setCodMedioPago('001') // catalog. 59
            ->setCtaBanco('0004-3342343243')
            ->setPercent(4.00)
            ->setMount(37.76)
    );

$detail = new SaleDetail();
$detail->setCodProducto('P001')
    ->setUnidad('ZZ')
    ->setDescripcion('PROD 1')
    ->setCantidad(4)
    ->setMtoValorUnitario(200)
    ->setMtoValorVenta(800)
    ->setMtoBaseIgv(800)
    ->setPorcentajeIgv(18)
    ->setIgv(144)
    ->setTipAfeIgv('10') // Catalogo 07 - Gravado
    ->setTotalImpuestos(144)
    ->setMtoPrecioUnitario(236)
;

$invoice->setDetails([$detail])
    ->setLegends([
        (new Legend())
            ->setCode('1000')
            ->setValue('SON NOVECIENTOS CUARENTA Y CUATRO CON OO/100 SOLES'),
        (new Legend())
            ->setCode('2006')
            ->setValue('Operación sujeta a detracción')
    ]);

///////////////////////////////

$result = $see->send($invoice);

// Guardar XML firmado digitalmente.
file_put_contents($invoice->getName().'.xml',
                  $see->getFactory()->getLastXml());

// Verificamos que la conexión con SUNAT fue exitosa.
if (!$result->isSuccess()) {
    // Mostrar error al conectarse a SUNAT.
    echo 'Codigo Error: '.$result->getError()->getCode();
    echo 'Mensaje Error: '.$result->getError()->getMessage();
    exit();
}

// Guardamos el CDR
file_put_contents('R-'.$invoice->getName().'.zip', $result->getCdrZip());

///////////////////////////////////////////////////////////////////////////////////////////////

$cdr = $result->getCdrResponse();
$code = (int)$cdr->getCode();

if ($code === 0) {
    echo 'ESTADO: ACEPTADA'.PHP_EOL;
    if (count($cdr->getNotes()) > 0) {
        echo 'OBSERVACIONES:'.PHP_EOL;
        // Corregir estas observaciones en siguientes emisiones.
        var_dump($cdr->getNotes());
    }  
} else if ($code >= 2000 && $code <= 3999) {
    echo 'ESTADO: RECHAZADA'.PHP_EOL;
} else {
    /* Esto no debería darse, pero si ocurre, es un CDR inválido que debería tratarse como un error-excepción. */
    /*code: 0100 a 1999 */
    echo 'Excepción';
}

echo $cdr->getDescription().PHP_EOL;