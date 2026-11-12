<?php
header ('Content-type: text/html; charset=utf-8'); 
require 'see/lib/xmlseclibs-master/xmlseclibs.php';
class Factura{
	public function firmar(DOMDocument $domDocument, $ruc="", $modo, $rucEmpresa=""){
                //echo "modo:".$modo;
		$ReferenceNodeName = 'ExtensionContent';
//                $privateKey = file_get_contents('prueba/server_key.pem');
//		$publicKey = file_get_contents('prueba/server.pem');
                // Certificado propio de la empresa (si esta registrada en la API v1). Ver v1/src/Certificados.php
                $propio = null;
                if ($rucEmpresa !== "") {
                    require_once __DIR__ . "/../v1/src/Certificados.php";
                    $propio = Certificados::paraFirmar($rucEmpresa, $modo);
                }
                if ($propio !== null) {
                    $privateKey = $propio["key"];
                    $publicKey = $propio["cert"];
                } else {
                    $modo = ($modo == 1) ? 'produccion' : 'prueba';
                    $privateKey = file_get_contents("libraries/certificado_digital/$modo/server_key.pem");
                    $publicKey = file_get_contents("libraries/certificado_digital/$modo/server.pem");
                }
                                                                
//              $privateKey = file_get_contents(APPPATH ."libraries/certificado_digital/$modo/server_key.pem");
//		$publicKey = file_get_contents(APPPATH ."libraries/certificado_digital/$modo/server.pem");
                
//                $privateKey = file_get_contents("server_key.pem");
//		$publicKey  = file_get_contents("server.pem");

		$objSign = new XMLSecurityDSig($ruc);
		$objSign->setCanonicalMethod(XMLSecurityDSig::C14N);
		$objSign->addReference(
			$domDocument, 
			XMLSecurityDSig::SHA1, 
			array('http://www.w3.org/2000/09/xmldsig#enveloped-signature'),
			$options = array('force_uri' => true)
		);
		
		$objKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, array('type'=>'private'));
		$objKey->loadKey($privateKey);
		
		// Sign the XML file
		$Node = $domDocument->getElementsByTagName($ReferenceNodeName)->item(1);
		if (!($Node)) $Node = $domDocument->getElementsByTagName($ReferenceNodeName)->item(0);
		$objSign->sign($objKey, $Node);
		// Add the associated public key to the signature
		$objSign->add509Cert($publicKey);
		return $domDocument;
	}
}
//$xmlstr = file_get_contents("20602535933-01-F001-56.xml");
//$name_file = "20604051984-01-F001-131.xml";

?>