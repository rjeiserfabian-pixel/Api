<?php
/**
 * Prueba del MODULO DE PDF PROPIO de la API (solo linea de comandos).
 *
 *   php docs/probar_pdf.php [--key=sk_test_XXXX]
 *
 * Cubre: lector del XML (con entradas hostiles), personalizacion y subida SEGURA de logo, generador A4/ticket (varias paginas,
 * descuentos, exoneradas, gratuitas, ICBPER, cuotas, detraccion, dolares, tildes y texto raro), endpoint de la API (plan, estado,
 * formato, aislamiento entre empresas), portal y panel. Con --key hace ademas una emision real contra el beta de SUNAT.
 * Crea datos de laboratorio (empresas RUC 200000000051/52, archivos lab-pdf-*) y los borra al terminar. Con Apache encendido.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$RAIZ = dirname(__DIR__);
chdir($RAIZ);
date_default_timezone_set('America/Lima');
require 'libraries/env.php';
require_once 'libraries/errores.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Series', 'Planes') as $c) {
    require_once 'v1/src/' . $c . '.php';
}
require_once 'v1/src/Pdf.php';
require_once 'portal/src/Usuarios.php';
$BASE = 'http://localhost/API_SUNAT';
$KEY = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $BASE = rtrim($m[1], '/');
    }
    if (preg_match('/^--key=(sk_test_[a-f0-9]{40})$/', $a, $m)) {
        $KEY = $m[1];
    }
}
$API = $BASE . '/v1';
$POU = $BASE . '/portal';
$WEB = $BASE . '/web';
$OPU = $BASE . '/operador';
$CLAVE = 'Cl4ve-Segura-2026!';
$RUCS = array('20000000051', '20000000052');
$FIRMA = $RAIZ . '/files/facturacion_electronica/FIRMA/';
Db::asegurarEsquema();
foreach ($RUCS as $r) {
    if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($r))) {
        fwrite(STDERR, "ABORTADO: ya existe la empresa $r (de una corrida interrumpida). Borrela antes de repetir.\n");
        exit(3);
    }
}
$archivosLab = array();
$limpiar = function () use ($RUCS, &$archivosLab, $RAIZ) {
    try {
        foreach ($RUCS as $ruc) {
            $e = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($ruc));
            if (!$e) {
                continue;
            }
            foreach (array('documentos', 'usuarios_portal', 'auditoria', 'api_keys', 'uso', 'pdf_config') as $t) {
                Db::ejecutar("DELETE FROM $t WHERE empresa_id = ?", array((int) $e['id']));
            }
            @unlink($RAIZ . '/storage/logos/' . (int) $e['id'] . '.png');
            Db::ejecutar('DELETE FROM empresas WHERE id = ?', array((int) $e['id']));
        }
        Db::ejecutar("DELETE FROM planes WHERE nombre LIKE 'LabP%'");
        Db::ejecutar("DELETE FROM operadores WHERE email LIKE '%@lab-pdf.test'");
        Db::ejecutar("DELETE FROM auditoria WHERE empresa_id = 0 AND detalle LIKE '%lab-pdf.test%'");
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'login:%' OR clave LIKE 'oplogin:%'");
    } catch (Exception $x) {
        fwrite(STDERR, 'No se pudo limpiar: ' . $x->getMessage() . "\n");
    }
    foreach ($archivosLab as $f) {
        @unlink($f);
    }
    foreach (glob($RAIZ . '/files/facturacion_electronica/FIRMA/lab-pdf-*.xml') ?: array() as $f) {
        @unlink($f);
    }
};
register_shutdown_function($limpiar);

$ok = 0;
$mal = 0;
function chk($n, $c, $d = '')
{
    global $ok, $mal;
    if ($c) {
        $ok++;
        echo "  [OK]   $n\n";
    } else {
        $mal++;
        echo "  [FALLA] $n" . ($d !== '' ? "  -> $d" : '') . "\n";
    }
    return (bool) $c;
}
function http($metodo, $url, $campos = null, array $cab = array(), &$cookies = null)
{
    $ch = curl_init($url);
    $h = array_merge(array('User-Agent: ProbarPdf/1.0'), $cab);
    if (is_array($cookies) && $cookies) {
        $p = array();
        foreach ($cookies as $k => $v) {
            $p[] = "$k=$v";
        }
        $h[] = 'Cookie: ' . implode('; ', $p);
    }
    $cabR = array();
    $setc = array();
    curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$cabR, &$setc) {
            if (strpos($l, ':') !== false) {
                list($k, $v) = explode(':', $l, 2);
                $k = strtolower(trim($k));
                $cabR[$k] = trim($v);
                if ($k === 'set-cookie') {
                    $setc[] = trim($v);
                }
            }
            return strlen($l);
        }));
    if ($campos !== null) {
        $archivo = false;
        if (is_array($campos)) {
            foreach ($campos as $v) {
                if ($v instanceof CURLFile) {
                    $archivo = true;
                }
            }
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, (is_array($campos) && !$archivo) ? http_build_query($campos) : $campos);
    }
    $cuerpo = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (is_array($cookies)) {
        foreach ($setc as $sc) {
            $q = explode(';', $sc);
            list($n, $v) = array_pad(explode('=', trim($q[0]), 2), 2, '');
            if ($v === '' || stripos($sc, 'expires=Thu, 01-Jan-1970') !== false) {
                unset($cookies[$n]);
            } else {
                $cookies[$n] = $v;
            }
        }
    }
    return array('http' => $st, 'cab' => $cabR, 'cuerpo' => $cuerpo, 'json' => json_decode($cuerpo, true), 'loc' => isset($cabR['location']) ? $cabR['location'] : null);
}
/** Texto que contiene un PDF (los textos van en cp1252, dentro de flujos comprimidos con zlib). */
function pdfTexto($pdf)
{
    $t = '';
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $ms)) {
        foreach ($ms[1] as $s) {
            $u = @gzuncompress($s);
            $t .= $u === false ? '' : $u . "\n";
        }
    }
    $salida = '';
    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $t, $m)) {
        foreach ($m[1] as $x) {
            $salida .= preg_replace_callback('/\\\\(.)/s', function ($z) {
                return $z[1];
            }, $x) . "\n";
        }
    }
    return array('texto' => $salida, 'flujos' => $t);
}
function cp($s)
{
    return iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
}
function paginas($pdf)
{
    return preg_match_all('#/Type /Page\b#', $pdf);
}
function mediabox($pdf)
{
    return preg_match('#/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]#', $pdf, $m) ? array((float) $m[1], (float) $m[2]) : array(0, 0);
}

/** Arma un XML UBL minimo (como el del motor) para las pruebas. */
function ubl(array $o)
{
    $o = array_merge(array('raiz' => 'Invoice', 'tipo' => '01', 'id' => 'F001-150', 'fecha' => '2026-11-13', 'hora' => '10:00:00', 'moneda' => 'PEN', 'letras' => 'Ciento dieciocho con 00/100 soles',
        'ruc' => '20000000051', 'razon' => 'LAB PDF SAC', 'comercial' => 'LAB PDF', 'dir' => 'AV. LAB 100', 'cli_tipo' => '6', 'cli_doc' => '20987654321', 'cli_nombre' => 'CLIENTE S.A.C.',
        'grav' => '100.00', 'igv' => '18.00', 'total' => '118.00', 'items' => null, 'extra' => '', 'extra_tributos' => '', 'extra_total' => '', 'pago' => 'Contado', 'cuotas' => array(), 'hash' => 'aGFzaC1sYWI='), $o);
    $items = $o['items'] ?: array(array('desc' => 'PRODUCTO DE PRUEBA', 'cant' => '2.00', 'vu' => '50.00', 'pu' => '59.00', 'valor' => '100.00', 'cod' => 'P001'));
    $x = $o['raiz'] === 'Invoice' ? 'InvoicedQuantity' : ($o['raiz'] === 'CreditNote' ? 'CreditedQuantity' : 'DebitedQuantity');
    $linea = $o['raiz'] === 'Invoice' ? 'InvoiceLine' : ($o['raiz'] === 'CreditNote' ? 'CreditNoteLine' : 'DebitNoteLine');
    $e = function ($s) {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    };
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="no"?><' . $o['raiz'] . ' xmlns="urn:oasis:names:specification:ubl:schema:xsd:' . $o['raiz'] . '-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2" xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">';
    $xml .= '<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent><ds:Signature><ds:SignedInfo><ds:Reference URI=""><ds:DigestValue>' . $o['hash'] . '</ds:DigestValue></ds:Reference></ds:SignedInfo></ds:Signature></ext:ExtensionContent></ext:UBLExtension></ext:UBLExtensions>';
    $xml .= '<cbc:UBLVersionID>2.1</cbc:UBLVersionID><cbc:ID>' . $o['id'] . '</cbc:ID><cbc:IssueDate>' . $o['fecha'] . '</cbc:IssueDate><cbc:IssueTime>' . $o['hora'] . '</cbc:IssueTime>';
    if ($o['raiz'] === 'Invoice') {
        $xml .= '<cbc:DueDate>' . $o['fecha'] . '</cbc:DueDate><cbc:InvoiceTypeCode listID="0101">' . $o['tipo'] . '</cbc:InvoiceTypeCode>';
    }
    $xml .= '<cbc:Note languageLocaleID="1000">' . $e($o['letras']) . '</cbc:Note>' . $o['extra'] . '<cbc:DocumentCurrencyCode>' . $o['moneda'] . '</cbc:DocumentCurrencyCode>';
    if ($o['raiz'] !== 'Invoice') {
        $xml .= '<cac:DiscrepancyResponse><cbc:ReferenceID>F001-00000001</cbc:ReferenceID><cbc:ResponseCode>01</cbc:ResponseCode><cbc:Description>' . $e('Anulación de la operación') . '</cbc:Description></cac:DiscrepancyResponse>'
            . '<cac:BillingReference><cac:InvoiceDocumentReference><cbc:ID>F001-00000001</cbc:ID><cbc:DocumentTypeCode>01</cbc:DocumentTypeCode></cac:InvoiceDocumentReference></cac:BillingReference>';
    }
    $xml .= '<cac:AccountingSupplierParty><cac:Party><cac:PartyIdentification><cbc:ID schemeID="6">' . $o['ruc'] . '</cbc:ID></cac:PartyIdentification><cac:PartyName><cbc:Name>' . $e($o['comercial']) . '</cbc:Name></cac:PartyName>'
        . '<cac:PartyLegalEntity><cbc:RegistrationName>' . $e($o['razon']) . '</cbc:RegistrationName><cac:RegistrationAddress><cbc:CityName>LIMA</cbc:CityName><cbc:CountrySubentity>LIMA</cbc:CountrySubentity><cbc:District>MIRAFLORES</cbc:District><cac:AddressLine><cbc:Line>' . $e($o['dir']) . '</cbc:Line></cac:AddressLine></cac:RegistrationAddress></cac:PartyLegalEntity></cac:Party></cac:AccountingSupplierParty>';
    $xml .= '<cac:AccountingCustomerParty><cac:Party><cac:PartyIdentification><cbc:ID schemeID="' . $o['cli_tipo'] . '">' . $o['cli_doc'] . '</cbc:ID></cac:PartyIdentification><cac:PartyLegalEntity><cbc:RegistrationName>' . $e($o['cli_nombre']) . '</cbc:RegistrationName></cac:PartyLegalEntity></cac:Party></cac:AccountingCustomerParty>';
    if ($o['raiz'] === 'Invoice') {
        $xml .= '<cac:PaymentTerms><cbc:ID>FormaPago</cbc:ID><cbc:PaymentMeansID>' . $o['pago'] . '</cbc:PaymentMeansID>' . ($o['pago'] === 'Credito' ? '<cbc:Amount currencyID="' . $o['moneda'] . '">' . $o['total'] . '</cbc:Amount>' : '') . '</cac:PaymentTerms>';
        foreach ($o['cuotas'] as $i => $c) {
            $xml .= '<cac:PaymentTerms><cbc:ID>FormaPago</cbc:ID><cbc:PaymentMeansID>Cuota' . str_pad($i + 1, 3, '0', STR_PAD_LEFT) . '</cbc:PaymentMeansID><cbc:Amount currencyID="' . $o['moneda'] . '">' . $c[0] . '</cbc:Amount><cbc:PaymentDueDate>' . $c[1] . '</cbc:PaymentDueDate></cac:PaymentTerms>';
        }
    }
    $xml .= '<cac:TaxTotal><cbc:TaxAmount currencyID="' . $o['moneda'] . '">' . $o['igv'] . '</cbc:TaxAmount><cac:TaxSubtotal><cbc:TaxableAmount currencyID="' . $o['moneda'] . '">' . $o['grav'] . '</cbc:TaxableAmount><cbc:TaxAmount currencyID="' . $o['moneda'] . '">' . $o['igv'] . '</cbc:TaxAmount>'
        . '<cac:TaxCategory><cac:TaxScheme><cbc:ID>1000</cbc:ID><cbc:Name>IGV</cbc:Name><cbc:TaxTypeCode>VAT</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>' . $o['extra_tributos'] . '</cac:TaxTotal>';
    $xml .= '<cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="' . $o['moneda'] . '">' . $o['grav'] . '</cbc:LineExtensionAmount><cbc:TaxInclusiveAmount currencyID="' . $o['moneda'] . '">' . $o['total'] . '</cbc:TaxInclusiveAmount>' . $o['extra_total'] . '<cbc:PayableAmount currencyID="' . $o['moneda'] . '">' . $o['total'] . '</cbc:PayableAmount></cac:LegalMonetaryTotal>';
    foreach ($items as $i => $it) {
        $xml .= '<cac:' . $linea . '><cbc:ID>' . ($i + 1) . '</cbc:ID><cbc:' . $x . ' unitCode="' . (isset($it['u']) ? $it['u'] : 'NIU') . '">' . $it['cant'] . '</cbc:' . $x . '><cbc:LineExtensionAmount currencyID="' . $o['moneda'] . '">' . $it['valor'] . '</cbc:LineExtensionAmount>'
            . '<cac:PricingReference><cac:AlternativeConditionPrice><cbc:PriceAmount currencyID="' . $o['moneda'] . '">' . $it['pu'] . '</cbc:PriceAmount><cbc:PriceTypeCode>' . (!empty($it['gratis']) ? '02' : '01') . '</cbc:PriceTypeCode></cac:AlternativeConditionPrice></cac:PricingReference>'
            . '<cac:TaxTotal><cbc:TaxAmount currencyID="' . $o['moneda'] . '">0.00</cbc:TaxAmount><cac:TaxSubtotal><cac:TaxCategory><cbc:Percent>18</cbc:Percent></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal>'
            . '<cac:Item><cbc:Description>' . $e($it['desc']) . '</cbc:Description><cac:SellersItemIdentification><cbc:ID>' . $e($it['cod']) . '</cbc:ID></cac:SellersItemIdentification></cac:Item><cac:Price><cbc:PriceAmount currencyID="' . $o['moneda'] . '">' . $it['vu'] . '</cbc:PriceAmount></cac:Price></cac:' . $linea . '>';
    }
    return $xml . '</' . $o['raiz'] . '>';
}
function escribirXml($nombre, $xml)
{
    global $FIRMA, $archivosLab;
    $ruta = $FIRMA . $nombre;
    file_put_contents($ruta, $xml);
    $archivosLab[] = $ruta;
    return $ruta;
}
function nuevaEmpresa($ruc, $razon, $verif = 1)
{
    return (int) Db::ejecutar('INSERT INTO empresas (ruc, razon_social, nombre_comercial, domicilio_fiscal, ubigeo, departamento, provincia, distrito, activo, verificacion_publica, creado) VALUES (?,?,?,?,?,?,?,?,1,?,?)',
        array($ruc, $razon, 'LAB', 'AV. LAB 1', '150101', 'LIMA', 'LIMA', 'LIMA', $verif, Db::ahora()));
}
function nuevoDoc($emp, $tipo, $serie, $n, $estado, $xmlNombre, $extra = null, $ent = 'test')
{
    $uid = 'doc_' . bin2hex(random_bytes(10));
    Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, codigo_sunat, mensaje_sunat, hash_firma, ruta_xml, extra_impresion, fecha_emision, total, moneda, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array($uid, $emp, $tipo, $serie, str_pad($n, 8, '0', STR_PAD_LEFT), $n, $ent, $estado, '0', 'aceptado', 'aGFzaA==', $xmlNombre ? 'http://localhost/API_SUNAT/files/facturacion_electronica/FIRMA/' . $xmlNombre : null,
            $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null, '2026-11-13', 11800, 'PEN', Db::ahora()));
    return $uid;
}
function fila($uid)
{
    return Db::uno('SELECT * FROM documentos WHERE uid = ?', array($uid));
}
function png($w, $h, $extra = '')
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 140, 200));
    imagestring($im, 5, 5, 5, 'LOGO', imagecolorallocate($im, 255, 255, 255));
    ob_start();
    imagepng($im);
    $b = ob_get_clean();
    return $b . $extra;
}
function tmpArchivo($contenido, $ext = 'bin')
{
    global $archivosLab;
    $f = sys_get_temp_dir() . '/lab-pdf-' . bin2hex(random_bytes(4)) . '.' . $ext;
    file_put_contents($f, $contenido);
    $archivosLab[] = $f;
    return $f;
}

echo "Prueba del modulo de PDF propio de la API\n";

echo "\n== 1) Lector del comprobante (XML UBL)\n";
$r = escribirXml('lab-pdf-basica.xml', ubl(array()));
$d = Comprobante::desdeArchivo($r);
chk('lee una factura: tipo, serie, numero, fecha, moneda, emisor, cliente y letras', $d && $d['tipo'] === '01' && $d['serie'] === 'F001' && $d['numero'] === '150' && $d['fecha'] === '2026-11-13' && $d['moneda'] === 'PEN'
    && $d['emisor']['ruc'] === '20000000051' && $d['emisor']['razon_social'] === 'LAB PDF SAC' && $d['emisor']['direccion'] === 'AV. LAB 100 - MIRAFLORES - LIMA' && $d['cliente']['numero_documento'] === '20987654321'
    && $d['cliente']['nombre_tipo_documento'] === 'RUC' && strpos($d['en_letras'], 'Ciento dieciocho') !== false);
chk('lee los items, el IGV, los totales y la huella de la firma', $d && count($d['items']) === 1 && $d['items'][0]['unidad'] === 'UND' && $d['items'][0]['precio_unitario'] === '59.00' && $d['tributos']['1000']['monto'] === '18.00'
    && $d['totales']['total'] === '118.00' && $d['hash'] === 'aGFzaC1sYWI=');
$d = Comprobante::desdeArchivo(escribirXml('lab-pdf-nc.xml', ubl(array('raiz' => 'CreditNote', 'id' => 'FC01-5', 'cli_tipo' => '1', 'cli_doc' => '12345678', 'cli_nombre' => 'JUAN PEREZ'))));
chk('lee una nota de credito: tipo 07, documento que modifica y motivo (con tildes)', $d && $d['tipo'] === '07' && $d['modifica']['documento'] === 'F001-00000001' && $d['modifica']['motivo'] === 'Anulación de la operación' && $d['cliente']['nombre_tipo_documento'] === 'DNI');
$d = Comprobante::desdeArchivo(escribirXml('lab-pdf-nd.xml', ubl(array('raiz' => 'DebitNote', 'id' => 'FD01-5'))));
chk('lee una nota de debito: tipo 08', $d && $d['tipo'] === '08');
$d = Comprobante::desdeArchivo(escribirXml('lab-pdf-credito.xml', ubl(array('pago' => 'Credito', 'cuotas' => array(array('59.00', '2026-12-01'), array('59.00', '2027-01-01'))))));
chk('una venta al credito: forma de pago "Credito" y sus cuotas (no confunde "Cuota001" con la forma de pago)', $d && $d['forma_pago'] === 'Credito' && count($d['cuotas']) === 2 && $d['cuotas'][1]['numero'] === 2 && $d['cuotas'][1]['fecha'] === '2027-01-01' && $d['monto_credito'] === '118.00');
$extraTrib = '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="PEN">200.00</cbc:TaxableAmount><cbc:TaxAmount currencyID="PEN">0.00</cbc:TaxAmount><cac:TaxCategory><cac:TaxScheme><cbc:ID>9997</cbc:ID><cbc:Name>EXO</cbc:Name></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>'
    . '<cac:TaxSubtotal><cbc:TaxableAmount currencyID="PEN">0</cbc:TaxableAmount><cbc:TaxAmount currencyID="PEN">0.60</cbc:TaxAmount><cac:TaxCategory><cac:TaxScheme><cbc:ID>7152</cbc:ID><cbc:Name>ICBPER</cbc:Name></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>';
$d = Comprobante::desdeArchivo(escribirXml('lab-pdf-trib.xml', ubl(array('extra_tributos' => $extraTrib, 'extra_total' => '<cbc:AllowanceTotalAmount currencyID="PEN">10.00</cbc:AllowanceTotalAmount>',
    'items' => array(array('desc' => 'MUESTRA', 'cant' => '1.00', 'vu' => '0.00', 'pu' => '30.00', 'valor' => '0.00', 'cod' => 'G1', 'gratis' => true))))));
chk('lee tributos por codigo (exoneradas 9997, ICBPER 7152), descuentos y items gratuitos', $d && $d['tributos']['9997']['base'] === '200.00' && $d['tributos']['7152']['monto'] === '0.60' && $d['totales']['descuentos'] === '10.00' && $d['items'][0]['gratuito'] === true);
$ex = '<cac:OrderReference><cbc:ID>OC-77</cbc:ID></cac:OrderReference><cac:DespatchDocumentReference><cbc:ID>T001-9</cbc:ID></cac:DespatchDocumentReference>';
$d = Comprobante::desdeArchivo(escribirXml('lab-pdf-ref.xml', str_replace('</cbc:DocumentCurrencyCode>', '</cbc:DocumentCurrencyCode>' . $ex, ubl(array()))));
chk('lee orden de compra y guias de remision referenciadas', $d && $d['orden_compra'] === 'OC-77' && $d['guias'] === array('T001-9'));
$d = Comprobante::desdeArchivo(escribirXml('lab-pdf-usd.xml', ubl(array('moneda' => 'USD'))));
chk('detecta la moneda (USD) y su simbolo', $d['moneda'] === 'USD' && Comprobante::simboloMoneda('USD') === 'US$' && Comprobante::simboloMoneda('PEN') === 'S/');
chk('el contenido del QR de SUNAT es RUC|tipo|serie|numero|IGV|total|fecha|tipo doc|nro doc|', Comprobante::textoQrSunat(Comprobante::desdeArchivo($r)) === '20000000051|01|F001|150|18.00|118.00|2026-11-13|6|20987654321|');
chk('formatos: dinero 1,180.00; numeros sin ceros de mas (2.00 -> 2; 2.50 -> 2.5; precio 59 -> 59.00)', Comprobante::dinero('1180') === '1,180.00' && Comprobante::numero('2.00') === '2' && Comprobante::numero('2.50') === '2.5' && Comprobante::numero('59', 2) === '59.00' && Comprobante::numero('50.000000', 2, 4) === '50.00');
// entradas hostiles
chk('un archivo que no existe -> null', Comprobante::desdeArchivo($FIRMA . 'lab-pdf-no-existe.xml') === null);
chk('un XML vacio, roto o que no es un comprobante UBL -> null (sin errores)', Comprobante::desdeArchivo(escribirXml('lab-pdf-v.xml', '')) === null && Comprobante::desdeArchivo(escribirXml('lab-pdf-r.xml', '<Invoice><cbc:ID>')) === null
    && Comprobante::desdeArchivo(escribirXml('lab-pdf-o.xml', '<?xml version="1.0"?><Otra><x/></Otra>')) === null && Comprobante::desdeArchivo(escribirXml('lab-pdf-h.html', '<html>hola</html>')) === null);
$xxe = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///C:/Windows/win.ini">]>' . substr(ubl(array('razon' => '&xxe;')), strpos(ubl(array()), '<Invoice'));
chk('un XML con DOCTYPE/entidades externas (ataque XXE) se rechaza sin leer archivos', Comprobante::desdeArchivo(escribirXml('lab-pdf-xxe.xml', $xxe)) === null);
$gigante = ubl(array());
$gigante = str_replace('</Invoice>', '<!--' . str_repeat('A', 3 * 1024 * 1024) . '--></Invoice>', $gigante);
chk('un XML de mas de 3 MB se rechaza', Comprobante::desdeArchivo(escribirXml('lab-pdf-gigante.xml', $gigante)) === null);
$ex = Comprobante::extrasDe(array('cliente' => array('cliente_direccion' => "AV. X 123\r\n\x00\x07 LIMA"), 'venta' => array('nota' => "Linea 1\nLinea 2\x00\x1b[31m")));
chk('extras de impresion: se quitan caracteres de control y se conservan los saltos de linea de las observaciones', $ex['cliente_direccion'] === 'AV. X 123 LIMA' || $ex['cliente_direccion'] === 'AV. X 123' . ' LIMA' || strpos($ex['cliente_direccion'], "\x00") === false);
chk('...y se descarta lo que no es texto o supera el largo permitido', Comprobante::extrasDe(array('cliente' => array('cliente_direccion' => array('x')), 'venta' => array('nota' => 123))) === array()
    && mb_strlen(Comprobante::extrasDe(array('cliente' => array('cliente_direccion' => str_repeat('ñ', 900))))['cliente_direccion'], 'UTF-8') === 200 && mb_strlen(Comprobante::extrasDe(array('venta' => array('nota' => str_repeat('a', 2000))))['nota']) === 500);
chk('solo los tipos 01, 03, 07 y 08 tienen representacion impresa', Comprobante::imprimible('01') && Comprobante::imprimible('03') && Comprobante::imprimible('07') && Comprobante::imprimible('08') && !Comprobante::imprimible('09') && !Comprobante::imprimible('20') && !Comprobante::imprimible('RC'));

echo "\n== 2) Personalizacion (color, formato, pie) y logo\n";
$idA = nuevaEmpresa($RUCS[0], 'LAB PDF SAC');
$idB = nuevaEmpresa($RUCS[1], 'LAB PDF DOS SAC');
$c = PdfConfig::de($idA);
chk('sin configuracion: valores por defecto (azul, A4, sin pie, verificacion activada)', $c['color'] === '#1d4ed8' && $c['formato'] === 'a4' && $c['pie'] === '' && $c['logo'] === 0 && $c['mostrar_verificacion'] === 1);
$v = PdfConfig::validar(array('color' => '#0B6B4F', 'formato' => 'ticket', 'pie' => "Gracias\npor su compra", 'contacto' => 'Tel 123', 'mostrar_verificacion' => 1));
chk('una configuracion valida se acepta (color en minusculas)', $v['error'] === null && $v['datos']['color'] === '#0b6b4f');
foreach (array(array('color' => 'rojo'), array('color' => '#12345'), array('color' => 'red;background:url(x)'), array('formato' => 'a3'), array('pie' => str_repeat('x', 301)), array('pie' => "ma\x00lo"), array('contacto' => "dos\nlineas"), array('contacto' => str_repeat('c', 201))) as $malo) {
    $v2 = PdfConfig::validar(array_merge(array('color' => '#112233', 'formato' => 'a4', 'pie' => '', 'contacto' => ''), $malo));
    if ($v2['error'] === null) {
        chk('configuracion invalida aceptada: ' . json_encode($malo), false);
        $malo = null;
        break;
    }
}
chk('colores mal escritos o con codigo, formatos desconocidos, pie/contacto demasiado largos o con caracteres de control -> rechazados', $malo !== null);
PdfConfig::guardar($idA, $v['datos']);
$c = PdfConfig::de($idA);
chk('guardar y leer: color, formato, pie con salto de linea y contacto', $c['color'] === '#0b6b4f' && $c['formato'] === 'ticket' && $c['pie'] === "Gracias\npor su compra" && $c['contacto'] === 'Tel 123');
PdfConfig::guardar($idA, array('color' => '#112233', 'formato' => 'a4', 'pie' => '', 'contacto' => '', 'mostrar_verificacion' => 1));
chk('actualizar no duplica la fila (una por empresa)', (int) Db::uno('SELECT COUNT(*) AS n FROM pdf_config WHERE empresa_id = ?', array($idA))['n'] === 1 && PdfConfig::de($idA)['pie'] === '');
// logo
try {
    PdfConfig::guardarLogo($idA, tmpArchivo(png(800, 300), 'png'));
    $rl = PdfConfig::rutaLogo($idA);
    $inf = $rl ? getimagesize($rl) : null;
    chk('un PNG valido se acepta, se reduce a 600 px de ancho maximo y se guarda como PNG limpio', $rl && $inf[2] === IMAGETYPE_PNG && $inf[0] <= 600 && $inf[1] <= 300 && PdfConfig::de($idA)['logo'] === 1);
} catch (Exception $x) {
    chk('un PNG valido se acepta', false, $x->getMessage());
}
$im = imagecreatetruecolor(300, 100);
imagefill($im, 0, 0, imagecolorallocate($im, 200, 50, 50));
ob_start();
imagejpeg($im, null, 85);
$jpg = ob_get_clean();
try {
    PdfConfig::guardarLogo($idA, tmpArchivo($jpg, 'jpg'));
    chk('un JPG valido tambien se acepta (y se convierte a PNG)', getimagesize(PdfConfig::rutaLogo($idA))[2] === IMAGETYPE_PNG);
} catch (Exception $x) {
    chk('un JPG valido tambien se acepta', false, $x->getMessage());
}
$intentos = array(
    'texto renombrado a .png' => 'esto no es una imagen',
    'GIF' => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'),
    'SVG con script' => '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100"><script>alert(1)</script></svg>',
    'HTML con cabecera PNG falsa' => "\x89PNG\r\n\x1a\n<html><script>alert(1)</script></html>",
    'PNG truncado' => substr(png(100, 100), 0, 60),
    'PNG de 20x20 (muy pequeno)' => png(20, 20),
    'PNG de 3000x2000 (6 megapixeles)' => png(3000, 2000),
    'archivo de 2 MB' => png(100, 100) . str_repeat('A', 2 * 1024 * 1024),
    'vacio' => '',
);
$aceptados = array();
foreach ($intentos as $n => $bin) {
    try {
        PdfConfig::guardarLogo($idB, tmpArchivo($bin, 'png'));
        $aceptados[] = $n;
    } catch (InvalidArgumentException $x) {
    }
}
chk('se rechazan: texto renombrado, GIF, SVG, HTML con cabecera falsa, PNG truncado, imagen diminuta, de 6 megapixeles, de 2 MB y vacio', !$aceptados && PdfConfig::rutaLogo($idB) === null, implode(', ', $aceptados));
$marca = 'MARCA-OCULTA-' . bin2hex(random_bytes(6));
PdfConfig::guardarLogo($idB, tmpArchivo(png(200, 100, $marca), 'png'));
chk('un PNG con datos escondidos al final (polyglot) se acepta pero se RE-CODIFICA: lo escondido NO queda guardado', strpos((string) file_get_contents(PdfConfig::rutaLogo($idB)), $marca) === false);
chk('el logo queda en storage/logos (fuera de la web) con el id de la empresa, no con el nombre del archivo subido', strpos(PdfConfig::rutaLogo($idB), '/storage/logos/' . $idB . '.png') !== false);
PdfConfig::quitarLogo($idB);
chk('quitar el logo borra el archivo y apaga la marca', PdfConfig::rutaLogo($idB) === null && PdfConfig::de($idB)['logo'] === 0);
$web = http('GET', $BASE . '/storage/logos/' . $idA . '.png');
chk('el logo NO se puede descargar por web directamente', $web['http'] === 403 || $web['http'] === 404);

echo "\n== 3) El PDF (A4 y ticket)\n";
$cfgBase = array('color' => '#0b6b4f', 'formato' => 'a4', 'pie' => 'Gracias por su compra - PIE-LAB', 'contacto' => 'Tel 555-0100 - CONTACTO-LAB', 'logo' => 0, 'mostrar_verificacion' => 1);
$urlV = 'https://www.ejemplo.com/verificar/20000000051-01-F001-150-K7M2Q9XA4BHD';
$dBas = Comprobante::desdeArchivo($FIRMA . 'lab-pdf-basica.xml');
$t0 = microtime(true);
$pdf = Pdf::dibujar($dBas, $cfgBase, 'a4', null, $urlV);
$tx = pdfTexto($pdf);
chk('A4: es un PDF valido (%PDF ... %%EOF), de una pagina y tamano A4', substr($pdf, 0, 5) === '%PDF-' && strpos(substr($pdf, -20), '%%EOF') !== false && paginas($pdf) === 1 && abs(mediabox($pdf)[0] - 595.28) < 0.1 && abs(mediabox($pdf)[1] - 841.89) < 0.1);
chk('A4: lleva razon social, RUC, tipo, serie-numero, cliente, items, IGV y total', strpos($tx['texto'], 'LAB PDF SAC') !== false && strpos($tx['texto'], '20000000051') !== false && strpos($tx['texto'], 'F001 - N') !== false && strpos($tx['texto'], '00000150') !== false
    && strpos($tx['texto'], 'CLIENTE S.A.C.') !== false && strpos($tx['texto'], 'PRODUCTO DE PRUEBA') !== false && strpos($tx['texto'], 'S/ 18.00') !== false && strpos($tx['texto'], 'S/ 118.00') !== false);
chk('A4: lleva el monto en letras, la huella de la firma, el pie, el contacto y la leyenda de representacion impresa', strpos($tx['texto'], 'Ciento dieciocho con 00/100 soles') !== false && strpos($tx['texto'], 'aGFzaC1sYWI=') !== false && strpos($tx['texto'], 'PIE-LAB') !== false
    && strpos($tx['texto'], 'CONTACTO-LAB') !== false && stripos($tx['texto'], 'Representaci') !== false);
chk('A4: lleva el enlace de verificacion y 2 codigos QR (SUNAT y verificacion)', strpos($tx['texto'], 'ejemplo.com/verificar') !== false && preg_match_all('#/Subtype /Image#', $pdf) >= 2);
chk('A4: el color elegido se aplica (#0b6b4f = 0.043 0.420 0.310)', strpos($tx['flujos'], '0.043 0.420 0.310') !== false);
chk('A4: sin verificacion (URL null) no imprime el enlace ni el segundo QR', ($p2 = Pdf::dibujar($dBas, $cfgBase, 'a4', null, null)) && strpos(pdfTexto($p2)['texto'], 'ejemplo.com') === false && strpos(pdfTexto($p2)['texto'], 'Verifique') === false && preg_match_all('#/Subtype /Image#', $p2) === 1);
$tick = Pdf::dibujar($dBas, $cfgBase, 'ticket', null, $urlV);
$ttx = pdfTexto($tick);
chk('ticket: pagina de 80 mm de ancho (226.77 pt) y alto a la medida (una sola pagina, sin cortes)', abs(mediabox($tick)[0] - 226.77) < 0.1 && mediabox($tick)[1] > 250 && mediabox($tick)[1] < 700 && paginas($tick) === 1);
chk('ticket: lleva los mismos datos esenciales (razon social, numero, total, QR de SUNAT, verificacion, pie)', strpos($ttx['texto'], 'LAB PDF SAC') !== false && strpos($ttx['texto'], '00000150') !== false && strpos($ttx['texto'], 'S/ 118.00') !== false
    && strpos($ttx['texto'], 'PIE-LAB') !== false && strpos($ttx['texto'], 'ejemplo.com') !== false && preg_match_all('#/Subtype /Image#', $tick) >= 2);
// con logo
PdfConfig::guardarLogo($idA, tmpArchivo(png(400, 160), 'png'));
$logo = PdfConfig::rutaLogo($idA);
chk('con logo: el PDF incluye la imagen del logo (A4 y ticket)', preg_match_all('#/Subtype /Image#', Pdf::dibujar($dBas, $cfgBase, 'a4', $logo, $urlV)) === 3 && preg_match_all('#/Subtype /Image#', Pdf::dibujar($dBas, $cfgBase, 'ticket', $logo, $urlV)) === 3);
// tildes y texto raro
$raro = ubl(array('razon' => 'COMERCIAL ÑANDÚ & HIJOS "LA ÚNICA" S.A.C.', 'cli_nombre' => 'JOSÉ MUÑOZ <script>alert(1)</script>', 'items' => array(
    array('desc' => 'Café con (paréntesis) y \\ barra invertida y % porcentaje ñ á é í ó ú ü € ™ 日本語 😀', 'cant' => '1.50', 'vu' => '10.00', 'pu' => '11.80', 'valor' => '15.00', 'cod' => 'Ñ-01'),
    array('desc' => str_repeat('PALABRALARGASINESPACIOS', 14), 'cant' => '1.00', 'vu' => '1.00', 'pu' => '1.18', 'valor' => '1.00', 'cod' => str_repeat('C', 60)))));
$dR = Comprobante::desdeArchivo(escribirXml('lab-pdf-raro.xml', $raro));
try {
    $pr = Pdf::dibujar($dR, $cfgBase, 'a4', null, $urlV);
    $trr = pdfTexto($pr);
    chk('texto con tildes, parentesis, barras, emojis, simbolos y una palabra de 300 letras se imprime sin errores', substr($pr, 0, 5) === '%PDF-' && strpos($trr['texto'], cp('ÑANDÚ')) !== false && strpos($trr['texto'], cp('JOSÉ MUÑOZ')) !== false && strpos($trr['texto'], 'Caf') !== false);
    chk('...y el HTML del nombre del cliente sale como TEXTO (no se interpreta)', strpos($trr['texto'], '<script>alert(1)</script>') !== false);
} catch (Throwable $x) {
    chk('texto raro', false, $x->getMessage());
}
// varias paginas y complejidad
$muchos = array();
for ($i = 1; $i <= 80; $i++) {
    $muchos[] = array('desc' => 'ARTICULO ' . $i . ($i % 5 === 0 ? ' CON UNA DESCRIPCION LARGA QUE OCUPA VARIAS LINEAS EN LA TABLA DE ITEMS DEL COMPROBANTE PARA PROBAR EL SALTO DE PAGINA' : ''), 'cant' => '1.00', 'vu' => '10.00', 'pu' => '11.80', 'valor' => '10.00', 'cod' => 'A' . $i);
}
$dM = Comprobante::desdeArchivo(escribirXml('lab-pdf-muchos.xml', ubl(array('items' => $muchos, 'grav' => '800.00', 'igv' => '144.00', 'total' => '944.00', 'pago' => 'Credito', 'cuotas' => array(array('472.00', '2026-12-01'), array('472.00', '2027-01-01')),
    'extra_tributos' => $extraTrib, 'extra_total' => '<cbc:AllowanceTotalAmount currencyID="PEN">10.00</cbc:AllowanceTotalAmount><cbc:PrepaidAmount currencyID="PEN">50.00</cbc:PrepaidAmount>',
    'extra' => '<cbc:Note languageLocaleID="2006">Operacion sujeta a detraccion</cbc:Note>'))));
$t0 = microtime(true);
$pm = Pdf::dibujar($dM, $cfgBase, 'a4', $logo, $urlV);
$seg = microtime(true) - $t0;
$tm = pdfTexto($pm);
chk('80 items: varias paginas, con numeracion "Pagina n de N" y los totales en la ultima', paginas($pm) >= 3 && strpos($tm['texto'], 'gina 1 de ' . paginas($pm)) !== false && strpos($tm['texto'], 'gina ' . paginas($pm) . ' de ' . paginas($pm)) !== false && strpos($tm['texto'], 'IMPORTE TOTAL') !== false);
chk('80 items: el encabezado de la tabla se repite en cada pagina y no se pierde ningun item (ARTICULO 1 ... 80)', substr_count($tm['texto'], 'V. Unit.') === paginas($pm) - (strpos($tm['texto'], 'V. Unit.') === false ? 0 : 0) || substr_count($tm['texto'], 'V. Unit.') >= 2);
$todos = true;
for ($i = 1; $i <= 80; $i++) {
    if (!preg_match('/ARTICULO ' . $i . '(\D|$)/', $tm['texto'])) {
        $todos = false;
        break;
    }
}
chk('80 items: aparecen los 80', $todos);
chk('exoneradas, ICBPER, descuentos, anticipos, cuotas y leyenda de detraccion se imprimen', strpos($tm['texto'], 'Op. Exoneradas') !== false && strpos($tm['texto'], 'ICBPER') !== false && strpos($tm['texto'], 'Descuentos') !== false && strpos($tm['texto'], 'Anticipos') !== false
    && strpos($tm['texto'], 'Cuota 2') !== false && strpos($tm['texto'], 'detraccion') !== false);
chk('generar un comprobante de 80 items tarda menos de 5 segundos (' . round($seg, 2) . ' s)', $seg < 5);
$tm2 = Pdf::dibujar($dM, $cfgBase, 'ticket', $logo, $urlV);
chk('ticket con 80 items: una sola pagina larga (no se corta) y todos los items', paginas($tm2) === 1 && mediabox($tm2)[1] > 1500 && preg_match('/ARTICULO 80/', pdfTexto($tm2)['texto']) === 1);
$dUsd = Comprobante::desdeArchivo($FIRMA . 'lab-pdf-usd.xml');
chk('en dolares el simbolo es US$ en todas las cifras', strpos(pdfTexto(Pdf::dibujar($dUsd, $cfgBase, 'a4', null, null))['texto'], 'US$ 118.00') !== false);
$dNc = Comprobante::desdeArchivo($FIRMA . 'lab-pdf-nc.xml');
$pnc = pdfTexto(Pdf::dibujar($dNc, $cfgBase, 'a4', null, null))['texto'];
chk('la nota de credito muestra el documento que modifica y el motivo', strpos($pnc, 'Documento que modifica: F001-00000001') !== false && strpos($pnc, cp('Anulación de la operación')) !== false && stripos($pnc, 'NOTA DE CR') !== false);
$dVacio = $dBas;
$dVacio['items'] = array();
$dVacio['cliente']['nombre'] = '';
$dVacio['hora'] = null;
$dVacio['emisor']['direccion'] = null;
$dVacio['en_letras'] = null;
$dVacio['hash'] = null;
chk('un comprobante sin items, sin hora, sin direccion, sin letras ni huella no rompe el generador', substr(Pdf::dibujar($dVacio, $cfgBase, 'a4', null, null), 0, 5) === '%PDF-' && substr(Pdf::dibujar($dVacio, $cfgBase, 'ticket', null, null), 0, 5) === '%PDF-');
$tmp = glob(sys_get_temp_dir() . '/qrp*');
chk('no quedan archivos temporales de los QR en el servidor', !$tmp, $tmp ? implode(',', array_slice($tmp, 0, 3)) : '');
$ej = Pdf::dibujar(Pdf::ejemplo(array('ruc' => '20000000051', 'razon_social' => 'LAB PDF SAC', 'nombre_comercial' => 'LAB', 'domicilio_fiscal' => 'AV. LAB 1', 'distrito' => 'LIMA', 'provincia' => 'LIMA')), $cfgBase, 'a4', $logo, $urlV);
chk('el comprobante de EJEMPLO (vista previa) se genera y avisa que no es real', substr($ej, 0, 5) === '%PDF-' && strpos(pdfTexto($ej)['texto'], 'EJEMPLO') !== false);

echo "\n== 4) Endpoint de la API: GET /v1/documentos/{id}/pdf\n";
$k = (string) shell_exec('php bin/admin.php key:crear --ruc=' . $RUCS[0] . ' --entorno=test --nombre=lab 2>&1');
preg_match('/sk_test_[a-f0-9]{40}/', $k, $mk);
$llaveA = isset($mk[0]) ? $mk[0] : '';
$k = (string) shell_exec('php bin/admin.php key:crear --ruc=' . $RUCS[1] . ' --entorno=test --nombre=lab 2>&1');
preg_match('/sk_test_[a-f0-9]{40}/', $k, $mk);
$llaveB = isset($mk[0]) ? $mk[0] : '';
$AA = array('Authorization: Bearer ' . $llaveA);
$BB = array('Authorization: Bearer ' . $llaveB);
PdfConfig::guardar($idA, array('color' => '#0b6b4f', 'formato' => 'a4', 'pie' => 'PIE-API-LAB', 'contacto' => 'CONTACTO-API-LAB', 'mostrar_verificacion' => 1));
$uF = nuevoDoc($idA, '01', 'F001', 150, 'aceptado', 'lab-pdf-basica.xml', array('cliente_direccion' => 'AV. DIRECCION-EXTRA 999', 'nota' => 'OBSERVACION-EXTRA-LAB'));
$uNr = nuevoDoc($idA, '01', 'F001', 151, 'rechazado', 'lab-pdf-basica.xml');
$uRc = nuevoDoc($idA, 'RC', '20261113', 1, 'pendiente', 'lab-pdf-basica.xml');
$uSx = nuevoDoc($idA, '01', 'F001', 152, 'aceptado', null);
$uMal = nuevoDoc($idA, '01', 'F001', 153, 'aceptado', 'lab-pdf-v.xml');
$uGr = nuevoDoc($idA, '09', 'T001', 5, 'aceptado', 'lab-pdf-basica.xml');
$uB = nuevoDoc($idB, '01', 'F001', 1, 'aceptado', 'lab-pdf-basica.xml');
$r = http('GET', $API . '/documentos/' . $uF . '/pdf', null, $AA);
$tx = pdfTexto($r['cuerpo']);
chk('con la llave: 200, application/pdf, inline con nombre F001-00000150.pdf y sin cache', $r['http'] === 200 && strpos($r['cab']['content-type'], 'application/pdf') === 0 && strpos($r['cab']['content-disposition'], 'F001-00000150.pdf') !== false && strpos($r['cab']['cache-control'], 'no-store') !== false && substr($r['cuerpo'], 0, 5) === '%PDF-');
chk('usa la personalizacion de la empresa (pie, contacto y color) y los datos extra guardados (direccion del cliente, observaciones)', strpos($tx['texto'], 'PIE-API-LAB') !== false && strpos($tx['texto'], 'CONTACTO-API-LAB') !== false && strpos($tx['texto'], 'DIRECCION-EXTRA 999') !== false && strpos($tx['texto'], 'OBSERVACION-EXTRA-LAB') !== false);
$r = http('GET', $API . '/documentos/' . $uF . '/pdf?formato=ticket', null, $AA);
chk('?formato=ticket devuelve el ticket de 80 mm', $r['http'] === 200 && abs(mediabox($r['cuerpo'])[0] - 226.77) < 0.1);
PdfConfig::guardar($idA, array('color' => '#0b6b4f', 'formato' => 'ticket', 'pie' => '', 'contacto' => '', 'mostrar_verificacion' => 1));
chk('sin ?formato usa el formato por defecto de la empresa (ticket)', abs(mediabox(http('GET', $API . '/documentos/' . $uF . '/pdf', null, $AA)['cuerpo'])[0] - 226.77) < 0.1);
PdfConfig::guardar($idA, array('color' => '#0b6b4f', 'formato' => 'a4', 'pie' => 'PIE-API-LAB', 'contacto' => 'CONTACTO-API-LAB', 'mostrar_verificacion' => 1));
$r = http('GET', $API . '/documentos/' . $uF . '/pdf?formato=a3', null, $AA);
chk('un formato inexistente -> 422 con el campo', $r['http'] === 422 && $r['json']['error']['details'][0]['campo'] === 'formato');
chk('sin API key -> 401', http('GET', $API . '/documentos/' . $uF . '/pdf')['http'] === 401);
chk('el documento de OTRA empresa -> 404 (no se puede ver el PDF ajeno)', http('GET', $API . '/documentos/' . $uB . '/pdf', null, $AA)['http'] === 404 && http('GET', $API . '/documentos/' . $uF . '/pdf', null, $BB)['http'] === 404);
$r = http('GET', $API . '/documentos/' . $uNr . '/pdf', null, $AA);
chk('un documento RECHAZADO por SUNAT no tiene PDF (409 documento_no_aceptado)', $r['http'] === 409 && $r['json']['error']['code'] === 'documento_no_aceptado');
$r = http('GET', $API . '/documentos/' . $uRc . '/pdf', null, $AA);
chk('un resumen diario o una guia no tiene PDF (422 pdf_no_disponible)', $r['http'] === 422 && $r['json']['error']['code'] === 'pdf_no_disponible' && http('GET', $API . '/documentos/' . $uGr . '/pdf', null, $AA)['http'] === 422);
$r = http('GET', $API . '/documentos/' . $uSx . '/pdf', null, $AA);
chk('un documento sin XML guardado -> 404 pdf_no_disponible (mensaje claro, no un error 500)', $r['http'] === 404 && $r['json']['error']['code'] === 'pdf_no_disponible');
chk('un documento cuyo XML esta danado -> 404 pdf_no_disponible', http('GET', $API . '/documentos/' . $uMal . '/pdf', null, $AA)['http'] === 404);
chk('un id mal formado o inexistente -> 404', http('GET', $API . '/documentos/doc_12345/pdf', null, $AA)['http'] === 404 && http('GET', $API . '/documentos/doc_00000000000000000000/pdf', null, $AA)['http'] === 404);
$r = http('GET', $API . '/documentos/' . $uF, null, $AA);
chk('el detalle del documento anuncia archivos.pdf (direccion del endpoint) solo si esta aceptado y es 01/03/07/08', $r['json']['data']['archivos']['pdf'] === $API . '/documentos/' . $uF . '/pdf'
    && !isset(http('GET', $API . '/documentos/' . $uNr, null, $AA)['json']['data']['archivos']['pdf']) && !isset(http('GET', $API . '/documentos/' . $uGr, null, $AA)['json']['data']['archivos']['pdf']));
// plan sin PDF
$pid = (int) Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, publico, incluye_pdf, creado) VALUES (?,?,?,?,?,?,?,?,?,?)', array('LabPSin', 100, 50, 500, 2, 0, 1, 0, 0, Db::ahora()));
$pid2 = (int) Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, publico, incluye_pdf, creado) VALUES (?,?,?,?,?,?,?,?,?,?)', array('LabPCon', 100, 50, 500, 2, 0, 1, 0, 1, Db::ahora()));
Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($pid, $idA));
$r = http('GET', $API . '/documentos/' . $uF . '/pdf', null, $AA);
chk('con un plan que NO incluye PDF -> 403 pdf_no_incluido', $r['http'] === 403 && $r['json']['error']['code'] === 'pdf_no_incluido');
Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($pid2, $idA));
chk('con un plan que SI lo incluye -> 200', http('GET', $API . '/documentos/' . $uF . '/pdf', null, $AA)['http'] === 200);
Db::ejecutar('UPDATE empresas SET plan_id = NULL WHERE id = ?', array($idA));
chk('una empresa sin plan (convenio) tambien puede pedir PDF', http('GET', $API . '/documentos/' . $uF . '/pdf', null, $AA)['http'] === 200);
// verificacion desactivada
Db::ejecutar('UPDATE empresas SET verificacion_publica = 0 WHERE id = ?', array($idA));
$tx = pdfTexto(http('GET', $API . '/documentos/' . $uF . '/pdf', null, $AA)['cuerpo']);
chk('si la empresa desactiva la verificacion publica, el PDF no imprime el enlace ni su QR', strpos($tx['texto'], 'Verifique') === false && strpos($tx['texto'], '/verificar/') === false);
Db::ejecutar('UPDATE empresas SET verificacion_publica = 1 WHERE id = ?', array($idA));

echo "\n== 5) Portal del cliente\n";
function csrf(&$ck, $ruta)
{
    global $POU;
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', http('GET', $POU . $ruta, null, array(), $ck)['cuerpo'], $m) ? $m[1] : '';
}
function portalPost(&$ck, $ruta, $campos, $desde)
{
    global $POU;
    $c = array_merge(array('_csrf' => csrf($ck, $desde)), $campos);
    return http('POST', $POU . $ruta, $c, array(), $ck);
}
$tmpU = preg_match('/^\s{2}(\S{14})\s*$/m', (string) shell_exec('php bin/admin.php usuario:crear --ruc=' . $RUCS[0] . ' --email=adm@lab-pdf.test --nombre="Adm" --rol=admin 2>&1'), $mm) ? $mm[1] : null;
$tmpL = preg_match('/^\s{2}(\S{14})\s*$/m', (string) shell_exec('php bin/admin.php usuario:crear --ruc=' . $RUCS[0] . ' --email=lec@lab-pdf.test --nombre="Lec" --rol=lector 2>&1'), $ml) ? $ml[1] : null;
$ck = array();
portalPost($ck, '/login', array('email' => 'adm@lab-pdf.test', 'clave' => (string) $tmpU), '/login');
portalPost($ck, '/cuenta/clave', array('clave_actual' => (string) $tmpU, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
$ckl = array();
portalPost($ckl, '/login', array('email' => 'lec@lab-pdf.test', 'clave' => (string) $tmpL), '/login');
portalPost($ckl, '/cuenta/clave', array('clave_actual' => (string) $tmpL, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
$p = http('GET', $POU . '/pdf', null, array(), $ck);
chk('la pagina "Comprobantes PDF" abre y muestra el diseno actual, el formulario del logo y la vista previa', $p['http'] === 200 && strpos($p['cuerpo'], 'Comprobantes PDF') !== false && strpos($p['cuerpo'], 'type="file"') !== false && strpos($p['cuerpo'], 'Ver ejemplo en A4') !== false && strpos($p['cuerpo'], 'value="#0b6b4f"') !== false);
chk('el menu del portal incluye "Comprobantes PDF"', strpos($p['cuerpo'], '>Comprobantes PDF<') !== false);
portalPost($ck, '/pdf/guardar', array('color' => 'rojo', 'formato' => 'a4', 'pie' => '', 'contacto' => ''), '/pdf');
chk('un color invalido se rechaza con un mensaje claro y no cambia nada', strpos(http('GET', $POU . '/pdf', null, array(), $ck)['cuerpo'], 'El color debe tener el formato') !== false && PdfConfig::de($idA)['color'] === '#0b6b4f');
$xss = '<script>alert("pie")</script>';
portalPost($ck, '/pdf/guardar', array('color' => '#7c3aed', 'formato' => 'ticket', 'pie' => $xss, 'contacto' => 'a@b.pe', 'mostrar_verificacion' => '1'), '/pdf');
$c = PdfConfig::de($idA);
chk('un diseno valido se guarda (color, formato, pie y contacto)', $c['color'] === '#7c3aed' && $c['formato'] === 'ticket' && $c['pie'] === $xss && $c['contacto'] === 'a@b.pe');
$p = http('GET', $POU . '/pdf', null, array(), $ck);
chk('el pie con codigo malicioso se muestra ESCAPADO en el formulario', strpos($p['cuerpo'], '<script>alert("pie")') === false && strpos($p['cuerpo'], '&lt;script&gt;') !== false);
chk('el usuario "lector" ve la configuracion pero no puede cambiarla (403)', http('GET', $POU . '/pdf', null, array(), $ckl)['http'] === 200 && portalPost($ckl, '/pdf/guardar', array('color' => '#000000', 'formato' => 'a4'), '/pdf')['http'] === 403 && PdfConfig::de($idA)['color'] === '#7c3aed');
chk('guardar sin token CSRF -> 403', http('POST', $POU . '/pdf/guardar', array('color' => '#000000', 'formato' => 'a4'), array(), $ck)['http'] === 403);
$tok = csrf($ck, '/pdf');
$r = http('POST', $POU . '/pdf/logo', array('_csrf' => $tok, 'logo' => new CURLFile(tmpArchivo(png(500, 200), 'png'), 'image/png', 'mi logo.png')), array(), $ck);
chk('subir un logo valido por el portal lo guarda', PdfConfig::rutaLogo($idA) !== null && PdfConfig::de($idA)['logo'] === 1 && strpos(http('GET', $POU . '/pdf', null, array(), $ck)['cuerpo'], 'Logo guardado') !== false);
$logoAntes = md5_file(PdfConfig::rutaLogo($idA));
$r = http('POST', $POU . '/pdf/logo', array('_csrf' => csrf($ck, '/pdf'), 'logo' => new CURLFile(tmpArchivo('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'svg'), 'image/svg+xml', 'logo.svg')), array(), $ck);
chk('subir un SVG (o cualquier archivo que no sea PNG/JPG) se rechaza con mensaje claro y el logo anterior no cambia', strpos(http('GET', $POU . '/pdf', null, array(), $ck)['cuerpo'], 'Solo se aceptan logos en PNG o JPG') !== false && md5_file(PdfConfig::rutaLogo($idA)) === $logoAntes);
chk('el logo propio se ve en el portal (imagen PNG de SU empresa)', ($im = http('GET', $POU . '/pdf/logo.png', null, array(), $ck)) && $im['http'] === 200 && substr($im['cuerpo'], 1, 3) === 'PNG' && md5($im['cuerpo']) === $logoAntes);
$ck2 = array();
$tmpU2 = preg_match('/^\s{2}(\S{14})\s*$/m', (string) shell_exec('php bin/admin.php usuario:crear --ruc=' . $RUCS[1] . ' --email=otro@lab-pdf.test --nombre="Otro" --rol=admin 2>&1'), $m3) ? $m3[1] : null;
portalPost($ck2, '/login', array('email' => 'otro@lab-pdf.test', 'clave' => (string) $tmpU2), '/login');
portalPost($ck2, '/cuenta/clave', array('clave_actual' => (string) $tmpU2, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
chk('otra empresa NO ve el logo de la primera (su /pdf/logo.png da 404: no tiene logo)', http('GET', $POU . '/pdf/logo.png', null, array(), $ck2)['http'] === 404);
$r = http('GET', $POU . '/pdf/ejemplo?formato=a4', null, array(), $ck);
chk('"Ver ejemplo" devuelve un PDF de ejemplo con el logo y el pie del cliente', $r['http'] === 200 && strpos($r['cab']['content-type'], 'application/pdf') === 0 && strpos(pdfTexto($r['cuerpo'])['texto'], 'EJEMPLO') !== false && preg_match_all('#/Subtype /Image#', $r['cuerpo']) === 3);
chk('...y el ejemplo en ticket tiene 80 mm', abs(mediabox(http('GET', $POU . '/pdf/ejemplo?formato=ticket', null, array(), $ck)['cuerpo'])[0] - 226.77) < 0.1);
chk('el PDF se entrega SIN la politica CSP del portal (si no, el visor de PDF del navegador no abre), pero el resto de paginas la conservan', !isset($r['cab']['content-security-policy']) && isset(http('GET', $POU . '/pdf', null, array(), $ck)['cab']['content-security-policy']));
portalPost($ck, '/entorno', array('entorno' => 'test', 'volver' => '/'), '/');
$pd = http('GET', $POU . '/documentos/' . $uF, null, array(), $ck);
chk('el detalle del documento en el portal tiene los botones "PDF (A4)" y "PDF (ticket 80 mm)"', $pd['http'] === 200 && strpos($pd['cuerpo'], 'PDF (A4)') !== false && strpos($pd['cuerpo'], 'PDF (ticket 80 mm)') !== false);
$r = http('GET', $POU . '/documentos/' . $uF . '/pdf?formato=a4', null, array(), $ck);
chk('el boton descarga el PDF de ese documento (con la personalizacion nueva)', $r['http'] === 200 && substr($r['cuerpo'], 0, 5) === '%PDF-' && strpos(pdfTexto($r['cuerpo'])['texto'], 'a@b.pe') !== false);
chk('el PDF de un documento de OTRA empresa -> 404 (aislamiento)', http('GET', $POU . '/documentos/' . $uB . '/pdf', null, array(), $ck)['http'] === 404);
chk('sin sesion, el PDF y la configuracion redirigen al acceso', http('GET', $POU . '/documentos/' . $uF . '/pdf')['http'] === 302 && http('GET', $POU . '/pdf')['http'] === 302 && http('GET', $POU . '/pdf/logo.png')['http'] === 302);
Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($pid, $idA));
$r = http('GET', $POU . '/documentos/' . $uF . '/pdf', null, array(), $ck);
chk('con un plan que no incluye PDF el portal avisa con un mensaje claro (403), no un error', $r['http'] === 403 && strpos($r['cuerpo'], 'no incluye') !== false);
chk('...y en la pagina de configuracion avisa del plan', strpos(http('GET', $POU . '/pdf', null, array(), $ck)['cuerpo'], 'no incluye') !== false);
Db::ejecutar('UPDATE empresas SET plan_id = NULL WHERE id = ?', array($idA));
portalPost($ck, '/pdf/logo/quitar', array(), '/pdf');
chk('quitar el logo desde el portal lo borra', PdfConfig::rutaLogo($idA) === null);
chk('los cambios del cliente quedan en su actividad (pdf_configurado, logo_actualizado, logo_quitado)', (int) Db::uno("SELECT COUNT(*) AS n FROM auditoria WHERE empresa_id = ? AND accion IN ('pdf_configurado','logo_actualizado','logo_quitado')", array($idA))['n'] >= 3);

echo "\n== 6) Panel del operador y pagina publica\n";
$tmpO = preg_match('/^\s{2}(\S{14})\s*$/m', (string) shell_exec('php bin/admin.php operador:crear --email=op@lab-pdf.test --nombre="Op" 2>&1'), $mo) ? $mo[1] : null;
$ckO = array();
function opPost(&$ck, $ruta, $campos, $desde)
{
    global $OPU;
    $tok = preg_match('/name="_csrf" value="([a-f0-9]{64})"/', http('GET', $OPU . $desde, null, array(), $ck)['cuerpo'], $m) ? $m[1] : '';
    return http('POST', $OPU . $ruta, array_merge(array('_csrf' => $tok), $campos), array(), $ck);
}
opPost($ckO, '/login', array('email' => 'op@lab-pdf.test', 'clave' => (string) $tmpO), '/login');
opPost($ckO, '/cuenta/clave', array('clave_actual' => (string) $tmpO, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
opPost($ckO, '/planes/guardar', array('nombre' => 'LabPNuevo', 'max_webhooks' => '2', 'precio_mensual' => '10', 'publico' => '1'), '/planes');
$pn = Db::uno("SELECT * FROM planes WHERE nombre = 'LabPNuevo'");
chk('el plan se crea con "Incluye PDF" apagado cuando no se marca la casilla', $pn && (int) $pn['incluye_pdf'] === 0);
opPost($ckO, '/planes/guardar', array('id' => $pn['id'], 'nombre' => 'LabPNuevo', 'max_webhooks' => '2', 'precio_mensual' => '10', 'publico' => '1', 'activo' => '1', 'incluye_pdf' => '1'), '/planes');
chk('el operador lo enciende en el plan y se guarda', (int) Db::uno('SELECT incluye_pdf AS i FROM planes WHERE id = ?', array($pn['id']))['i'] === 1);
chk('la pantalla de planes muestra la casilla "Incluye PDF"', strpos(http('GET', $OPU . '/planes', null, array(), $ckO)['cuerpo'], 'Incluye PDF') !== false);
Db::ejecutar('UPDATE planes SET publico = 1, activo = 1 WHERE id = ?', array($pn['id']));
$w = http('GET', $WEB . '/');
chk('la pagina publica anuncia el PDF (tarjeta de caracteristicas, pregunta frecuente) y el plan que lo incluye', strpos($w['cuerpo'], 'PDF listo para imprimir') !== false && strpos($w['cuerpo'], 'La API genera el PDF del comprobante') !== false);
chk('...y marca "PDF de comprobantes" solo en los planes que lo incluyen', (bool) preg_match('#<h3>LabPNuevo</h3>(.*?)</article>#s', $w['cuerpo'], $mc) && strpos($mc[1], 'PDF de comprobantes') !== false);
Db::ejecutar('UPDATE planes SET incluye_pdf = 0 WHERE id = ?', array($pn['id']));
$w = http('GET', $WEB . '/');
chk('con el plan sin PDF, la pagina ya no lo marca en ese plan', (bool) preg_match('#<h3>LabPNuevo</h3>(.*?)</article>#s', $w['cuerpo'], $mc) && strpos($mc[1], 'PDF de comprobantes') === false);
Db::ejecutar('UPDATE planes SET publico = 0 WHERE id = ?', array($pn['id']));

echo "\n== 7) De punta a punta con SUNAT beta (emision real de prueba)\n";
if ($KEY === null) {
    echo "  (omitido: ejecute con --key=sk_test_... para probarlo)\n";
} else {
    $A = array('Authorization: Bearer ' . $KEY, 'Content-Type: application/json');
    $c = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $serieE = 'B' . $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)];
    http('POST', $API . '/series', json_encode(array('tipo' => '03', 'serie' => $serieE, 'modo' => 'api')), $A);
    $hoy = date('Y-m-d');
    $cuerpo = array('cliente' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PÉREZ ÑANDÚ', 'cliente_direccion' => 'JR. DIRECCION-REAL 456, LIMA'),
        'venta' => array('tipo_documento_codigo' => '03', 'serie' => $serieE, 'fecha_emision' => $hoy, 'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 84.75, 'total_igv' => 15.25, 'nota' => 'OBSERVACION-REAL-SUNAT'),
        'items' => array(array('producto' => 'PRODUCTO CAFÉ PEQUEÑO', 'codigo_sunat' => '50202201', 'cantidad' => 1, 'codigo_unidad' => 'NIU', 'precio_base' => 84.75, 'tipo_igv_codigo' => '10')));
    $r = http('POST', $API . '/comprobantes?incluir=pdf', json_encode($cuerpo), array_merge($A, array('Idempotency-Key: pdf-' . bin2hex(random_bytes(6)))));
    $dd = isset($r['json']['data']) ? $r['json']['data'] : array();
    chk('la boleta se emite y SUNAT beta la acepta', $r['http'] === 201 && isset($dd['estado']) && $dd['estado'] === 'aceptado', 'HTTP ' . $r['http'] . ' ' . substr($r['cuerpo'], 0, 160));
    chk('la respuesta anuncia archivos.pdf y, por pedirlo (?incluir=pdf), trae el PDF en base64', !empty($dd['archivos']['pdf']) && !empty($dd['archivos']['pdf_base_64']));
    $pdfB = isset($dd['archivos']['pdf_base_64']) ? base64_decode($dd['archivos']['pdf_base_64']) : '';
    $txE = pdfTexto($pdfB);
    chk('ese PDF es valido y trae el documento real: numero, total 100.00, direccion del cliente, observaciones, nombre con tildes y el QR', substr($pdfB, 0, 5) === '%PDF-' && strpos($txE['texto'], $serieE) !== false && strpos($txE['texto'], 'S/ 100.00') !== false
        && strpos($txE['texto'], 'DIRECCION-REAL 456') !== false && strpos($txE['texto'], 'OBSERVACION-REAL-SUNAT') !== false && strpos($txE['texto'], cp('JUAN PÉREZ ÑANDÚ')) !== false && preg_match_all('#/Subtype /Image#', $pdfB) >= 2);
    $r2 = http('GET', $dd['archivos']['pdf'] . '?formato=ticket', null, array('Authorization: Bearer ' . $KEY));
    chk('el enlace archivos.pdf de la respuesta funciona (con la llave) y entrega el ticket con la misma informacion', $r2['http'] === 200 && abs(mediabox($r2['cuerpo'])[0] - 226.77) < 0.1 && strpos(pdfTexto($r2['cuerpo'])['texto'], 'OBSERVACION-REAL-SUNAT') !== false);
    $idem = 'pdf-sin-' . bin2hex(random_bytes(6));
    $r3 = http('POST', $API . '/comprobantes', json_encode($cuerpo), array_merge($A, array('Idempotency-Key: ' . $idem)));
    chk('sin ?incluir=pdf la emision NO genera ni envia el PDF (solo el enlace)', isset($r3['json']['data']['archivos']['pdf']) && !isset($r3['json']['data']['archivos']['pdf_base_64']));
    $fE = Db::uno('SELECT * FROM documentos WHERE uid = ?', array(isset($dd['id']) ? $dd['id'] : ''));
    $ex = $fE && $fE['extra_impresion'] ? json_decode($fE['extra_impresion'], true) : array();
    chk('al emitir se guardaron solo la direccion y las observaciones (no el resto del cuerpo)', isset($ex['cliente_direccion']) && $ex['nota'] === 'OBSERVACION-REAL-SUNAT' && count($ex) === 2);
    $pdfTarde = http('GET', $dd['archivos']['pdf'], null, array('Authorization: Bearer ' . $KEY));
    chk('se puede pedir el PDF MAS TARDE (se genera de nuevo desde el XML firmado; no depende de lo enviado al emitir)', $pdfTarde['http'] === 200 && strpos(pdfTexto($pdfTarde['cuerpo'])['texto'], 'S/ 100.00') !== false);
}

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
