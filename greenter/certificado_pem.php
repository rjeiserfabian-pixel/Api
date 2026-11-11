<?php
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;

require 'vendor/autoload.php';
//$pfx = file_get_contents('certificado.p12');
$pfx = file_get_contents('certificado.pfx');
$password = '123456';

$certificate = new X509Certificate($pfx, $password);
$pem = $certificate->export(X509ContentType::PEM);
    
file_put_contents('certificate.pem', $pem);