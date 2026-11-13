<?php
/**
 * Lee un comprobante electronico (XML UBL 2.1 firmado: factura, boleta, nota de credito/debito) y lo convierte en un arreglo
 * sencillo con todo lo que hace falta para IMPRIMIRLO. El XML es la fuente de verdad (es lo que SUNAT recibio): asi se puede
 * reimprimir cualquier documento, incluso los emitidos mucho antes, sin guardar nada mas.
 *
 * Lo que no viaja en el XML (direccion del cliente, observaciones) se guarda aparte al emitir ("extra_impresion").
 * Defensivo: ignora lo que no entiende y nunca falla por un dato opcional ausente.
 */
class Comprobante
{
    public static $unidades = array('NIU' => 'UND', 'ZZ' => 'SERV', 'KGM' => 'KG', 'LTR' => 'LT', 'MTR' => 'M', 'BX' => 'CAJA', 'PK' => 'PAQ', 'DZN' => 'DOC', 'GRM' => 'GR',
        'MTK' => 'M2', 'MTQ' => 'M3', 'HUR' => 'HORA', 'DAY' => 'DIA', 'MON' => 'MES', 'BG' => 'BOLSA', 'BO' => 'BOT', 'CEN' => 'CIENTO', 'SET' => 'JGO', 'PR' => 'PAR',
        'KT' => 'KIT', 'GLL' => 'GAL', 'LBR' => 'LB', 'ONZ' => 'OZ', 'CMT' => 'CM', 'FOT' => 'PIE', 'INH' => 'PULG', 'TNE' => 'TN', 'MIL' => 'MILLAR', 'BJ' => 'BALDE', 'RL' => 'ROLLO');
    public static $tiposDocumento = array('0' => 'S/D', '1' => 'DNI', '4' => 'C.E.', '6' => 'RUC', '7' => 'PASAPORTE', 'A' => 'C.DIPL.', 'B' => 'DOC.PAIS', 'C' => 'TIN', 'D' => 'IN', '-' => 'S/D');
    public static $nombresTipo = array('01' => 'FACTURA ELECTRÓNICA', '03' => 'BOLETA DE VENTA ELECTRÓNICA', '07' => 'NOTA DE CRÉDITO ELECTRÓNICA', '08' => 'NOTA DE DÉBITO ELECTRÓNICA');
    public static $nombresTributo = array('1000' => 'IGV', '1016' => 'IVAP', '2000' => 'ISC', '7152' => 'ICBPER', '9995' => 'Exportación', '9996' => 'Gratuitas', '9997' => 'Exoneradas', '9998' => 'Inafectas', '9999' => 'Otros tributos');

    /**
     * Datos que NO viajan en el XML pero hacen falta para imprimir (direccion del cliente, observaciones). Solo estos campos, con limite
     * de largo y sin caracteres de control. Se guardan al emitir en documentos.extra_impresion.
     */
    public static function extrasDe(array $cuerpo)
    {
        $limpio = function ($v, $max, $lineas = false) {
            if (!is_string($v)) {
                return null;
            }
            $v = trim($lineas ? str_replace("\r\n", "\n", $v) : $v);
            $v = preg_replace($lineas ? '/[\x00-\x09\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/', '', $v);
            $v = mb_substr($v, 0, $max, 'UTF-8');
            return $v !== '' ? $v : null;
        };
        $e = array();
        $a = $limpio(isset($cuerpo['cliente']['cliente_direccion']) ? $cuerpo['cliente']['cliente_direccion'] : null, 200);
        $n = $limpio(isset($cuerpo['venta']['nota']) ? $cuerpo['venta']['nota'] : null, 500, true);
        if ($a !== null) {
            $e['cliente_direccion'] = $a;
        }
        if ($n !== null) {
            $e['nota'] = $n;
        }
        return $e;
    }

    public static function guardarExtras($uid, array $cuerpo)
    {
        try {
            $e = self::extrasDe($cuerpo);
            if ($e) {
                Db::ejecutar('UPDATE documentos SET extra_impresion = ? WHERE uid = ?', array(json_encode($e, JSON_UNESCAPED_UNICODE), $uid));
            }
        } catch (Exception $x) {
            error_log('[v1] no se pudieron guardar los datos de impresion de ' . $uid . ': ' . $x->getMessage());
        }
    }

    /** Tipos para los que hay representacion impresa. */
    public static function imprimible($tipo)
    {
        return in_array((string) $tipo, array('01', '03', '07', '08'), true);
    }

    /** @return array|null  null si el archivo no existe, es muy grande o no es un comprobante UBL valido. */
    public static function desdeArchivo($ruta, array $extra = array())
    {
        if (!is_file($ruta) || filesize($ruta) > 3 * 1024 * 1024) {
            return null;
        }
        $xml = (string) file_get_contents($ruta);
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) { // nunca se procesan entidades externas
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok || !$dom->documentElement) {
            return null;
        }
        return self::leer($dom, $extra);
    }

    private static function leer(DOMDocument $dom, array $extra)
    {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
        $xp->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        $raiz = $dom->documentElement->localName;
        if (!in_array($raiz, array('Invoice', 'CreditNote', 'DebitNote'), true)) {
            return null;
        }
        // texto del primer nodo de una consulta (relativa a $ctx)
        $t = function ($q, $ctx = null) use ($xp) {
            $n = $xp->query($q, $ctx);
            return ($n && $n->length) ? trim($n->item(0)->textContent) : null;
        };
        $atr = function ($q, $a, $ctx = null) use ($xp) {
            $n = $xp->query($q, $ctx);
            return ($n && $n->length && $n->item(0)->attributes->getNamedItem($a)) ? trim($n->item(0)->attributes->getNamedItem($a)->nodeValue) : null;
        };

        $tipo = $raiz === 'CreditNote' ? '07' : ($raiz === 'DebitNote' ? '08' : (string) $t('/*/cbc:InvoiceTypeCode'));
        if (!self::imprimible($tipo)) {
            return null;
        }
        $id = (string) $t('/*/cbc:ID');
        $partes = explode('-', $id, 2);
        $d = array(
            'tipo' => $tipo, 'nombre_tipo' => self::$nombresTipo[$tipo], 'serie' => $partes[0], 'numero' => isset($partes[1]) ? $partes[1] : '',
            'fecha' => $t('/*/cbc:IssueDate'), 'hora' => $t('/*/cbc:IssueTime'), 'vencimiento' => $t('/*/cbc:DueDate'),
            'moneda' => $t('/*/cbc:DocumentCurrencyCode') ?: 'PEN',
            'orden_compra' => $t('/*/cac:OrderReference/cbc:ID'),
            'hash' => $t('/*/ext:UBLExtensions//ds:Signature//ds:DigestValue'),
            'leyendas' => array(), 'guias' => array(), 'cuotas' => array(), 'anticipos' => array(),
        );
        // leyendas (monto en letras, detraccion, etc.)
        foreach ($xp->query('/*/cbc:Note') as $n) {
            $cod = $n->attributes->getNamedItem('languageLocaleID') ? $n->attributes->getNamedItem('languageLocaleID')->nodeValue : '';
            if ($cod === '1000') {
                $d['en_letras'] = trim($n->textContent);
            } else {
                $d['leyendas'][] = trim(preg_replace('/\s+/', ' ', $n->textContent));
            }
        }
        foreach ($xp->query('/*/cac:DespatchDocumentReference/cbc:ID') as $n) {
            $d['guias'][] = trim($n->textContent);
        }
        foreach ($xp->query('/*/cac:AdditionalDocumentReference') as $n) {
            $d['anticipos'][] = array('id' => $t('cbc:ID', $n), 'tipo' => $t('cbc:DocumentTypeCode', $n));
        }

        // emisor y cliente
        $e = $xp->query('/*/cac:AccountingSupplierParty/cac:Party')->item(0);
        $d['emisor'] = array(
            'ruc' => $t('cac:PartyIdentification/cbc:ID', $e), 'razon_social' => $t('cac:PartyLegalEntity/cbc:RegistrationName', $e),
            'comercial' => $t('cac:PartyName/cbc:Name', $e),
            'direccion' => self::direccion($t, $e),
        );
        $c = $xp->query('/*/cac:AccountingCustomerParty/cac:Party')->item(0);
        $ctd = (string) $atr('cac:PartyIdentification/cbc:ID', 'schemeID', $c);
        $d['cliente'] = array(
            'tipo_documento' => $ctd, 'nombre_tipo_documento' => isset(self::$tiposDocumento[$ctd]) ? self::$tiposDocumento[$ctd] : 'DOC.',
            'numero_documento' => $t('cac:PartyIdentification/cbc:ID', $c),
            'nombre' => $t('cac:PartyLegalEntity/cbc:RegistrationName', $c) ?: $t('cac:PartyName/cbc:Name', $c),
            'direccion' => (isset($extra['cliente_direccion']) && $extra['cliente_direccion'] !== '') ? $extra['cliente_direccion'] : self::direccion($t, $c),
        );
        $d['nota'] = isset($extra['nota']) ? $extra['nota'] : null;

        // nota de credito / debito: documento que se modifica y motivo
        if ($tipo === '07' || $tipo === '08') {
            $d['modifica'] = array(
                'documento' => $t('/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID') ?: $t('/*/cac:DiscrepancyResponse/cbc:ReferenceID'),
                'tipo' => $t('/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:DocumentTypeCode'),
                'motivo_codigo' => $t('/*/cac:DiscrepancyResponse/cbc:ResponseCode'), 'motivo' => $t('/*/cac:DiscrepancyResponse/cbc:Description'),
            );
        }

        // forma de pago, cuotas y detraccion
        $d['forma_pago'] = null;
        $d['detraccion'] = null;
        foreach ($xp->query('/*/cac:PaymentTerms') as $pt) {
            $ptId = $t('cbc:ID', $pt);
            $medio = $t('cbc:PaymentMeansID', $pt);
            if (stripos((string) $medio, 'Cuota') === 0) { // las cuotas tambien llevan ID "FormaPago": se reconocen por "CuotaNNN"
                $d['cuotas'][] = array('numero' => (int) preg_replace('/\D/', '', $medio), 'monto' => $t('cbc:Amount', $pt), 'fecha' => $t('cbc:PaymentDueDate', $pt));
            } elseif ($ptId === 'FormaPago') {
                $d['forma_pago'] = $medio;
                $d['monto_credito'] = $t('cbc:Amount', $pt);
            } elseif ($ptId === 'Detraccion') {
                $d['detraccion'] = array('codigo' => $medio, 'porcentaje' => $t('cbc:PaymentPercent', $pt), 'monto' => $t('cbc:Amount', $pt));
            }
        }
        if ($d['detraccion']) {
            $d['detraccion']['cuenta'] = $t('/*/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID');
        }

        // totales por tributo y montos
        $d['tributos'] = array();
        foreach ($xp->query('/*/cac:TaxTotal/cac:TaxSubtotal') as $st) {
            $cod = (string) $t('cac:TaxCategory/cac:TaxScheme/cbc:ID', $st);
            $d['tributos'][$cod] = array('codigo' => $cod, 'nombre' => $t('cac:TaxCategory/cac:TaxScheme/cbc:Name', $st), 'base' => $t('cbc:TaxableAmount', $st), 'monto' => $t('cbc:TaxAmount', $st));
        }
        $d['totales'] = array();
        foreach (array('LineExtensionAmount' => 'valor_venta', 'TaxInclusiveAmount' => 'con_impuestos', 'AllowanceTotalAmount' => 'descuentos', 'ChargeTotalAmount' => 'cargos',
            'PrepaidAmount' => 'anticipos', 'PayableAmount' => 'total') as $tag => $k) {
            $d['totales'][$k] = $t('/*/cac:LegalMonetaryTotal/cbc:' . $tag);
        }
        $d['descuento_global'] = null;
        foreach ($xp->query('/*/cac:AllowanceCharge') as $ac) {
            $d['descuento_global'] = array('cargo' => strtolower((string) $t('cbc:ChargeIndicator', $ac)) === 'true', 'monto' => $t('cbc:Amount', $ac), 'base' => $t('cbc:BaseAmount', $ac));
        }

        // items
        $d['items'] = array();
        $tagLinea = $raiz === 'CreditNote' ? 'CreditNoteLine' : ($raiz === 'DebitNote' ? 'DebitNoteLine' : 'InvoiceLine');
        $tagCant = $raiz === 'CreditNote' ? 'CreditedQuantity' : ($raiz === 'DebitNote' ? 'DebitedQuantity' : 'InvoicedQuantity');
        foreach ($xp->query('/*/cac:' . $tagLinea) as $ln) {
            $unidad = (string) $atr('cbc:' . $tagCant, 'unitCode', $ln);
            $it = array(
                'numero' => $t('cbc:ID', $ln), 'cantidad' => $t('cbc:' . $tagCant, $ln), 'unidad' => isset(self::$unidades[$unidad]) ? self::$unidades[$unidad] : $unidad,
                'codigo' => $t('cac:Item/cac:SellersItemIdentification/cbc:ID', $ln), 'descripcion' => $t('cac:Item/cbc:Description', $ln),
                'valor_unitario' => $t('cac:Price/cbc:PriceAmount', $ln), 'valor_venta' => $t('cbc:LineExtensionAmount', $ln),
                'precio_unitario' => null, 'gratuito' => false, 'igv_porcentaje' => null, 'descuento' => $t('cac:AllowanceCharge/cbc:Amount', $ln),
            );
            foreach ($xp->query('cac:PricingReference/cac:AlternativeConditionPrice', $ln) as $pr) {
                $codp = $t('cbc:PriceTypeCode', $pr);
                if ($codp === '01') {
                    $it['precio_unitario'] = $t('cbc:PriceAmount', $pr);
                } elseif ($codp === '02') {
                    $it['gratuito'] = true;
                    $it['precio_unitario'] = $t('cbc:PriceAmount', $pr);
                }
            }
            $pc = $t('cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent', $ln);
            $it['igv_porcentaje'] = $pc;
            $d['items'][] = $it;
        }
        return $d;
    }

    private static function direccion($t, $nodo)
    {
        if (!$nodo) {
            return null;
        }
        $l = $t('cac:PartyLegalEntity/cac:RegistrationAddress/cac:AddressLine/cbc:Line', $nodo);
        $zona = array();
        foreach (array('cbc:District', 'cbc:CityName', 'cbc:CountrySubentity') as $q) {
            $v = $t('cac:PartyLegalEntity/cac:RegistrationAddress/' . $q, $nodo);
            if ($v !== null && $v !== '' && !in_array($v, $zona, true)) {
                $zona[] = $v;
            }
        }
        $r = trim((string) $l . ($zona ? ' - ' . implode(' - ', $zona) : ''), ' -');
        return $r !== '' ? $r : null;
    }

    // ------------------------------------------------------------------ formatos
    public static function dinero($v, $dec = 2)
    {
        return ($v === null || $v === '') ? '' : number_format((float) $v, $dec, '.', ',');
    }

    /** Cantidades y precios: sin ceros de mas (2.00 -> 2; 2.50 -> 2.5) pero con un minimo de decimales. */
    public static function numero($v, $min = 0, $max = 4)
    {
        if ($v === null || $v === '') {
            return '';
        }
        $s = number_format((float) $v, $max, '.', ',');
        if (strpos($s, '.') !== false) {
            $s = rtrim($s, '0');
            $p = strpos($s, '.');
            $dec = strlen($s) - $p - 1;
            if ($dec < $min) {
                $s .= str_repeat('0', $min - $dec);
            }
            $s = rtrim($s, '.');
        }
        return $s;
    }

    public static function simboloMoneda($m)
    {
        $s = array('PEN' => 'S/', 'USD' => 'US$', 'EUR' => 'EUR');
        return isset($s[$m]) ? $s[$m] : (string) $m;
    }

    /** Contenido del QR estandar de SUNAT (RUC|tipo|serie|numero|IGV|total|fecha|tipo doc adquirente|nro doc adquirente|). */
    public static function textoQrSunat(array $d)
    {
        $igv = isset($d['tributos']['1000']) ? $d['tributos']['1000']['monto'] : '0.00';
        return implode('|', array($d['emisor']['ruc'], $d['tipo'], $d['serie'], $d['numero'], self::dinero($igv, 2) === '' ? '0.00' : number_format((float) $igv, 2, '.', ''),
            number_format((float) $d['totales']['total'], 2, '.', ''), $d['fecha'], $d['cliente']['tipo_documento'], $d['cliente']['numero_documento'])) . '|';
    }
}
