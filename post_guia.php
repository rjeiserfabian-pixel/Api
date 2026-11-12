<?php
require_once __DIR__ . '/libraries/guard.php';
require_once __DIR__ . '/libraries/errores.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

header('HTTP/1.1');

require 'libraries/efactura.php';

// =========================================================
// Parseo de entrada JSON
// =========================================================
$datos = file_get_contents('php://input');
$obj   = json_decode($datos, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($obj)) {
    http_response_code(400);
    echo json_encode([
        'data' => [
            'error' => 'true',
            'respuesta_sunat_codigo' => 'JSON_INVALIDO',
            'respuesta_sunat_descripcion' => 'El cuerpo enviado no es un JSON valido: ' . json_last_error_msg()
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$required_sections = ['empresa', 'remitente', 'destinatario', 'guia', 'items'];
foreach ($required_sections as $section) {
    if (!isset($obj[$section])) {
        http_response_code(400);
        echo json_encode([
            'data' => [
                'error' => 'true',
                'respuesta_sunat_codigo' => 'JSON_INCOMPLETO',
                'respuesta_sunat_descripcion' => 'Falta la seccion requerida: ' . $section
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$empresa               = $obj['empresa'];
$remitente             = $obj['remitente'];
$destinatario          = $obj['destinatario'];
$guia                  = $obj['guia'];
$transportista         = isset($obj['transportista'])          ? $obj['transportista']          : array();
$vehiculos             = isset($obj['vehiculos'])              ? $obj['vehiculos']              : array();
$conductores           = isset($obj['conductores'])            ? $obj['conductores']            : array();
$documentos_relacionados = isset($obj['documentos_relacionados']) ? $obj['documentos_relacionados'] : array();
$evento                = isset($obj['evento'])                 ? $obj['evento']                 : array();

$items = array();
if (!is_array($obj['items']) || count($obj['items']) == 0) {
    http_response_code(400);
    echo json_encode([
        'data' => [
            'error' => 'true',
            'respuesta_sunat_codigo' => 'ITEMS_INVALIDOS',
            'respuesta_sunat_descripcion' => 'Debe enviar al menos un item en la guia.'
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
foreach ($obj['items'] as $value) {
    $items[] = $value;
}

// =========================================================
// Nombre del archivo y rutas (carpetas separadas GUIA/)
// =========================================================
$nombre_archivo = $empresa['ruc'] . '-' . $guia['tipo_documento_codigo'] . '-' . $guia['serie'] . '-' . $guia['numero'];

$carpeta_base = 'files/facturacion_electronica/GUIA/';

// Crear carpetas si no existen
foreach (['XML', 'FIRMA', 'CDR'] as $sub) {
    $dir = $carpeta_base . $sub;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

$nombre_xml = $carpeta_base . 'XML/' . $nombre_archivo . '.xml';

// Limpiar archivos previos del mismo documento
if (file_exists($nombre_xml)) {
    unlink($nombre_xml);
}
foreach (['xml', 'zip'] as $ext) {
    $f = $carpeta_base . 'FIRMA/' . $nombre_archivo . '.' . $ext;
    if (file_exists($f)) unlink($f);
}

// =========================================================
// Generar → Firmar → Enviar a SUNAT
// =========================================================
crear_xml($nombre_xml, $empresa, $remitente, $destinatario, $guia, $transportista, $vehiculos, $conductores, $items, $documentos_relacionados, $evento);
firmar_xml($nombre_archivo . '.xml', $empresa['modo']);

// La API de guias (GRE) de SUNAT NO tiene entorno beta: solo existe un servidor y lo que
// se envia queda emitido de verdad. Por eso solo se envia con modo = 1 (produccion).
// Con cualquier otro valor se genera y firma el XML, pero NO se envia.
if ((string) $empresa['modo'] !== '1') {
    $base = carpeta_actual() . '/files/facturacion_electronica/GUIA/';
    echo json_encode(array('data' => array(
        'error'                       => 'false',
        'simulado'                    => true,
        'respuesta_sunat_codigo'      => 'NO_ENVIADO',
        'respuesta_sunat_descripcion' => 'Modo prueba (modo distinto de 1): la guia se genero y firmo pero NO se envio a SUNAT, porque la API de guias no tiene entorno beta. Use modo 1 para emitirla de verdad.',
        'ruta_xml'                    => $base . 'FIRMA/' . $nombre_archivo . '.xml',
    )), JSON_UNESCAPED_UNICODE);
    exit;
}

ws_sunat_guia($empresa, $nombre_archivo);

// =========================================================
// FUNCIÓN: Crear XML y guardarlo en disco
// =========================================================
function crear_xml($nombre, $empresa, $remitente, $destinatario, $guia, $transportista, $vehiculos, $conductores, $items, $documentos_relacionados, $evento) {
    $xml     = desarrollo_xml($empresa, $remitente, $destinatario, $guia, $transportista, $vehiculos, $conductores, $items, $documentos_relacionados, $evento);
    $archivo = fopen($nombre, 'w+');
    fwrite($archivo, utf8_decode($xml));
    fclose($archivo);
}

// =========================================================
// FUNCIÓN: Firmar el XML con el certificado digital
// =========================================================
function firmar_xml($name_file, $entorno) {
    $carpeta = 'files/facturacion_electronica/GUIA/';
    $dir     = $carpeta . 'XML/' . $name_file;
    $xmlstr  = file_get_contents($dir);

    $domDocument = new \DOMDocument();
    $domDocument->loadXML($xmlstr);
    $factura = new Factura();
    $xml     = $factura->firmar($domDocument, '', $entorno, substr(basename($name_file), 0, 11));
    $content = $xml->saveXML();
    file_put_contents($carpeta . 'FIRMA/' . $name_file, $content);
}

// =========================================================
// FUNCIÓN: Enviar XML firmado a SUNAT (NUEVO REST + OAuth 2.0)
// =========================================================
function ws_sunat_guia($empresa, $nombre_archivo) {
    $ruta_dominio  = carpeta_actual();
    // Para GRE OAuth, siempre debemos usar el usuario real si estamos usando el Client ID real.
    $user_sec_usu  = $empresa['usu_secundario_produccion_user'];
    $user_sec_pass = $empresa['usu_secundario_produccion_password'];
    $client_id     = isset($empresa['gre_client_id'])     ? $empresa['gre_client_id']     : '';
    $client_secret = isset($empresa['gre_client_secret']) ? $empresa['gre_client_secret'] : '';

    $url = $ruta_dominio . '/ws_sunat/index_guia.php'
         . '?numero_documento=' . $nombre_archivo
         . '&cod_2=' . $empresa['modo']
         . '&cod_3=' . $empresa['ruc']
         . '&cod_4=' . $user_sec_usu
         . '&cod_5=' . $user_sec_pass
         . '&cod_6=1'
         . '&cod_9='  . urlencode($client_id)
         . '&cod_10=' . urlencode($client_secret);

    $data = file_get_contents($url);
    $info = json_decode($data);

    $jsondata = array('data' => $info);
    echo json_encode($jsondata, JSON_UNESCAPED_UNICODE);
}

// =========================================================
// FUNCIÓN: Obtener ruta base actual (igual patrón que post.php)
// =========================================================
function carpeta_actual() {
    $archivo_actual = 'http://' . $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
    $dir            = explode('/', $archivo_actual);
    $cadena         = '';
    for ($i = 0; $i < (count($dir) - 1); $i++) {
        $cadena .= $dir[$i] . '/';
    }
    return substr($cadena, 0, -1);
}

// =========================================================
// FUNCIÓN PRINCIPAL: Generar XML UBL 2.1 DespatchAdvice
// =========================================================
function desarrollo_xml($empresa, $remitente, $destinatario, $guia, $transportista, $vehiculos, $conductores, $items, $documentos_relacionados, $evento) {

    $es_evento = isset($evento['tipo_evento']) && ($evento['tipo_evento'] != null) && ($evento['tipo_evento'] != '');

    // --------------------------------------------------
    // Cabecera del XML — DespatchAdvice UBL 2.1
    // --------------------------------------------------
    $xml = '<?xml version="1.0" encoding="ISO-8859-1" standalone="no"?>'
         . '<DespatchAdvice'
         . ' xmlns="urn:oasis:names:specification:ubl:schema:xsd:DespatchAdvice-2"'
         . ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
         . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"'
         . ' xmlns:ccts="urn:un:unece:uncefact:documentation:2"'
         . ' xmlns:ds="http://www.w3.org/2000/09/xmldsig#"'
         . ' xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"'
         . ' xmlns:qdt="urn:oasis:names:specification:ubl:schema:xsd:QualifiedDatatypes-2"'
         . ' xmlns:udt="urn:un:unece:uncefact:data:specification:UnqualifiedDataTypesSchemaModule:2"'
         . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';

    // Extensiones UBL (necesarias para la firma digital)
    $xml .= '<ext:UBLExtensions>
                <ext:UBLExtension>
                    <ext:ExtensionContent></ext:ExtensionContent>
                </ext:UBLExtension>
             </ext:UBLExtensions>';

    $xml .= '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>';
    $xml .= '<cbc:CustomizationID>2.0</cbc:CustomizationID>';
    $xml .= '<cbc:ID>' . $guia['serie'] . '-' . $guia['numero'] . '</cbc:ID>';
    $xml .= '<cbc:IssueDate>' . $guia['fecha_emision'] . '</cbc:IssueDate>';
    $hora_emision = isset($guia['hora_emision']) && $guia['hora_emision'] != '' ? $guia['hora_emision'] : date('H:i:s');
    $xml .= '<cbc:IssueTime>' . $hora_emision . '</cbc:IssueTime>';
    $xml .= '<cbc:DespatchAdviceTypeCode listID="0" listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01">' . $guia['tipo_documento_codigo'] . '</cbc:DespatchAdviceTypeCode>';

    if (isset($guia['observacion']) && ($guia['observacion'] != '')) {
        $xml .= '<cbc:Note>' . $guia['observacion'] . '</cbc:Note>';
    }

    // --------------------------------------------------
    // Documentos relacionados (Facturas, Boletas, etc.)
    // --------------------------------------------------
    foreach ($documentos_relacionados as $doc) {
        $ruc_emisor_doc = isset($doc['ruc_emisor']) && $doc['ruc_emisor'] != '' ? $doc['ruc_emisor'] : $empresa['ruc'];
        $xml .= '<cac:AdditionalDocumentReference>
                    <cbc:ID>' . $doc['serie'] . '-' . $doc['numero'] . '</cbc:ID>
                    <cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01">' . $doc['tipo_documento'] . '</cbc:DocumentTypeCode>
                    <cac:IssuerParty>
                        <cac:PartyIdentification>
                            <cbc:ID schemeID="6" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">' . $ruc_emisor_doc . '</cbc:ID>
                        </cac:PartyIdentification>
                    </cac:IssuerParty>
                 </cac:AdditionalDocumentReference>';
    }

    // --------------------------------------------------
    // Guía por Evento — datos adicionales del evento
    // --------------------------------------------------
    if ($es_evento) {
        $xml .= '<cac:AdditionalDocumentReference>
                    <cbc:ID>' . $evento['guia_relacionada_serie'] . '-' . $evento['guia_relacionada_numero'] . '</cbc:ID>
                    <cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo01">' . $evento['guia_relacionada_tipo'] . '</cbc:DocumentTypeCode>
                    <cbc:DocumentType>' . $evento['tipo_evento'] . '</cbc:DocumentType>';
        if (isset($evento['descripcion']) && ($evento['descripcion'] != '')) {
            $xml .= '<cbc:DocumentStatusCode listAgencyName="PE:SUNAT" listName="Motivo de evento"><![CDATA[' . $evento['descripcion'] . ']]></cbc:DocumentStatusCode>';
        }
        $xml .= '</cac:AdditionalDocumentReference>';
    }

    // --------------------------------------------------
    // Firma Digital (nodo Signature — se reemplaza al firmar)
    // --------------------------------------------------
    $xml .= '<cac:Signature>
                <cbc:ID>' . $empresa['ruc'] . '</cbc:ID>
                <cac:SignatoryParty>
                    <cac:PartyIdentification>
                        <cbc:ID>' . $empresa['ruc'] . '</cbc:ID>
                    </cac:PartyIdentification>
                    <cac:PartyName>
                        <cbc:Name><![CDATA[' . $empresa['razon_social'] . ']]></cbc:Name>
                    </cac:PartyName>
                </cac:SignatoryParty>
                <cac:DigitalSignatureAttachment>
                    <cac:ExternalReference>
                        <cbc:URI>' . $empresa['ruc'] . '</cbc:URI>
                    </cac:ExternalReference>
                </cac:DigitalSignatureAttachment>
             </cac:Signature>';

    // --------------------------------------------------
    // Remitente / Empresa Emisora (DespatchSupplierParty)
    // --------------------------------------------------
    $xml .= '<cac:DespatchSupplierParty>
                <cac:Party>
                    <cac:PartyIdentification>
                        <cbc:ID schemeID="' . $remitente['codigo_tipo_entidad'] . '" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">' . $remitente['numero_documento'] . '</cbc:ID>
                    </cac:PartyIdentification>
                    <cac:PartyLegalEntity>
                        <cbc:RegistrationName><![CDATA[' . $remitente['razon_social_nombres'] . ']]></cbc:RegistrationName>
                        <cac:RegistrationAddress>
                            <cbc:ID schemeName="Ubigeos" schemeAgencyName="PE:INEI">' . $empresa['ubigeo'] . '</cbc:ID>
                            <cbc:AddressTypeCode listAgencyName="PE:SUNAT" listName="Establecimientos anexos">0000</cbc:AddressTypeCode>
                            <cbc:CityName>' . $empresa['provincia'] . '</cbc:CityName>
                            <cbc:CountrySubentity>' . $empresa['departamento'] . '</cbc:CountrySubentity>
                            <cbc:District>' . $empresa['distrito'] . '</cbc:District>
                            <cac:AddressLine>
                                <cbc:Line>' . $empresa['domicilio_fiscal'] . '</cbc:Line>
                            </cac:AddressLine>
                            <cac:Country>
                                <cbc:IdentificationCode listID="ISO 3166-1" listAgencyName="United Nations Economic Commission for Europe" listName="Country">PE</cbc:IdentificationCode>
                            </cac:Country>
                        </cac:RegistrationAddress>
                    </cac:PartyLegalEntity>
                </cac:Party>
             </cac:DespatchSupplierParty>';

    // --------------------------------------------------
    // Destinatario (DeliveryCustomerParty)
    // --------------------------------------------------
    $xml .= '<cac:DeliveryCustomerParty>
                <cac:Party>
                    <cac:PartyIdentification>
                        <cbc:ID schemeID="' . $destinatario['codigo_tipo_entidad'] . '" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">' . $destinatario['numero_documento'] . '</cbc:ID>
                    </cac:PartyIdentification>
                    <cac:PartyLegalEntity>
                        <cbc:RegistrationName><![CDATA[' . $destinatario['razon_social_nombres'] . ']]></cbc:RegistrationName>
                    </cac:PartyLegalEntity>
                </cac:Party>
             </cac:DeliveryCustomerParty>';

    // --------------------------------------------------
    // Transportista (CarrierParty) - requerido cuando la modalidad es transporte publico
    // --------------------------------------------------
    if (isset($guia['transporte_publico']) && $guia['transporte_publico'] === true && !empty($transportista)) {
        $xml .= '<cac:CarrierParty>
                    <cac:PartyIdentification>
                        <cbc:ID schemeID="6" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">' . $transportista['ruc'] . '</cbc:ID>
                    </cac:PartyIdentification>
                    <cac:PartyLegalEntity>
                        <cbc:RegistrationName><![CDATA[' . $transportista['razon_social'] . ']]></cbc:RegistrationName>
                    </cac:PartyLegalEntity>
                 </cac:CarrierParty>';
    }

    // --------------------------------------------------
    // Shipment — Contiene vehículos, conductores y direcciones
    // --------------------------------------------------
    $xml .= '<cac:Shipment>';
    $xml .= '<cbc:ID>1</cbc:ID>';

    // Motivo de traslado
    $xml .= '<cbc:HandlingCode listAgencyName="PE:SUNAT" listName="Motivo de traslado" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo20">' . $guia['motivo_traslado_codigo'] . '</cbc:HandlingCode>';

    // Información del peso total
    $xml .= '<cbc:GrossWeightMeasure unitCode="' . $guia['unidad_peso'] . '">' . number_format($guia['peso_total'], 3, '.', '') . '</cbc:GrossWeightMeasure>';

    // Cantidad de bultos
    if (isset($guia['cantidad_bultos']) && ($guia['cantidad_bultos'] > 0)) {
        $xml .= '<cbc:TotalTransportHandlingUnitQuantity>' . $guia['cantidad_bultos'] . '</cbc:TotalTransportHandlingUnitQuantity>';
    }

    // Indicador de partición del envío (requerido siempre por SUNAT)
    $split = (isset($guia['transporte_publico']) && ($guia['transporte_publico'] === true)) ? 'true' : 'false';
    $xml .= '<cbc:SplitConsignmentIndicator>' . $split . '</cbc:SplitConsignmentIndicator>';

    // Modalidad de traslado catalogo 18: 01=Publico, 02=Privado
    $modalidad = (isset($guia['transporte_publico']) && ($guia['transporte_publico'] === true)) ? '01' : '02';

    // ShipmentStage — contiene transportMeans, conductores y periodo
    $xml .= '<cac:ShipmentStage>';
    $xml .= '<cbc:ID>1</cbc:ID>';
    $xml .= '<cbc:TransportModeCode listAgencyName="PE:SUNAT" listName="Modalidad de traslado" listURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo18">' . $modalidad . '</cbc:TransportModeCode>';
    $xml .= '<cac:TransitPeriod>
                <cbc:StartDate>' . $guia['fecha_inicio_traslado'] . '</cbc:StartDate>
             </cac:TransitPeriod>';

    // Vehículo principal dentro de ShipmentStage → TransportMeans
    if (!empty($vehiculos)) {
        $placa_principal = $vehiculos[0]['placa'];
        foreach ($vehiculos as $v) {
            if (isset($v['principal']) && $v['principal']) { $placa_principal = $v['placa']; break; }
        }
        $xml .= '<cac:TransportMeans>
                    <cac:RoadTransport>
                        <cbc:LicensePlateID>' . $placa_principal . '</cbc:LicensePlateID>
                    </cac:RoadTransport>
                 </cac:TransportMeans>';
    }

    $fecha_entrega_transportista = isset($guia['fecha_entrega_bienes_transportista']) && $guia['fecha_entrega_bienes_transportista'] != '' ? $guia['fecha_entrega_bienes_transportista'] : $guia['fecha_emision'];
    $xml .= '<cac:LoadingTransportEvent>
                <cbc:OccurrenceDate>' . $fecha_entrega_transportista . '</cbc:OccurrenceDate>
             </cac:LoadingTransportEvent>';

    // Conductores dentro de ShipmentStage
    foreach ($conductores as $conductor) {
        $nombre_conductor = isset($conductor['nombres']) && $conductor['nombres'] != ''
            ? $conductor['nombres']
            : (isset($conductor['nombre_completo']) ? $conductor['nombre_completo'] : '');
        $apellido_conductor = isset($conductor['apellidos']) && $conductor['apellidos'] != ''
            ? $conductor['apellidos']
            : '';

        if ($apellido_conductor == '' && isset($conductor['nombre_completo'])) {
            $partes_nombre = preg_split('/\s+/', trim($conductor['nombre_completo']));
            if (count($partes_nombre) >= 3) {
                $apellido_conductor = implode(' ', array_slice($partes_nombre, -2));
                $nombre_conductor = implode(' ', array_slice($partes_nombre, 0, -2));
            }
        }

        $cargo_conductor = (isset($conductor['principal']) && $conductor['principal']) ? 'Principal' : 'Secundario';
        $xml .= '<cac:DriverPerson>
                    <cbc:ID schemeID="' . $conductor['tipo_documento'] . '" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">' . $conductor['numero_documento'] . '</cbc:ID>
                    <cbc:FirstName><![CDATA[' . $nombre_conductor . ']]></cbc:FirstName>
                    <cbc:FamilyName><![CDATA[' . $apellido_conductor . ']]></cbc:FamilyName>
                    <cbc:JobTitle>' . $cargo_conductor . '</cbc:JobTitle>
                    <cac:IdentityDocumentReference>
                        <cbc:ID>' . $conductor['licencia'] . '</cbc:ID>
                    </cac:IdentityDocumentReference>
                 </cac:DriverPerson>';
    }

    $xml .= '</cac:ShipmentStage>';

    // Dirección de llegada (destino) y Punto de Partida
    $xml .= '<cac:Delivery>
                <cac:DeliveryAddress>
                    <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">' . $guia['ubigeo_llegada'] . '</cbc:ID>
                    <cac:AddressLine>
                        <cbc:Line><![CDATA[' . $guia['direccion_llegada'] . ']]></cbc:Line>
                    </cac:AddressLine>
                </cac:DeliveryAddress>
                <cac:Despatch>
                    <cac:DespatchAddress>
                        <cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">' . $guia['ubigeo_partida'] . '</cbc:ID>
                        <cac:AddressLine>
                            <cbc:Line><![CDATA[' . $guia['direccion_partida'] . ']]></cbc:Line>
                        </cac:AddressLine>
                    </cac:DespatchAddress>
                </cac:Despatch>
             </cac:Delivery>';

    // TransportHandlingUnit — usa TransportEquipment para la placa
    $unidad = 1;
    foreach ($vehiculos as $vehiculo) {
        $xml .= '<cac:TransportHandlingUnit>
                    <cbc:ID>' . $unidad . '</cbc:ID>
                    <cac:TransportEquipment>
                        <cbc:ID>' . $vehiculo['placa'] . '</cbc:ID>
                    </cac:TransportEquipment>
                 </cac:TransportHandlingUnit>';
        $unidad++;
    }



    $xml .= '</cac:Shipment>';

    // --------------------------------------------------
    // Ítems — DespatchLine
    // --------------------------------------------------
    $i = 1;
    foreach ($items as $item) {
        $xml .= '<cac:DespatchLine>
                    <cbc:ID>' . $i . '</cbc:ID>
                    <cbc:DeliveredQuantity unitCode="' . $item['codigo_unidad'] . '">' . number_format($item['cantidad'], 2, '.', '') . '</cbc:DeliveredQuantity>
                    <cac:OrderLineReference>
                        <cbc:LineID>' . $i . '</cbc:LineID>
                    </cac:OrderLineReference>
                    <cac:Item>
                        <cbc:Description><![CDATA[' . $item['producto'] . ']]></cbc:Description>
                        <cac:SellersItemIdentification>
                            <cbc:ID>' . $item['codigo_producto'] . '</cbc:ID>
                        </cac:SellersItemIdentification>';

        if (isset($item['codigo_sunat']) && ($item['codigo_sunat'] != '')) {
            $xml .= '<cac:CommodityClassification>
                        <cbc:ItemClassificationCode>' . $item['codigo_sunat'] . '</cbc:ItemClassificationCode>
                     </cac:CommodityClassification>';
        }

        $xml .= '    </cac:Item>
                 </cac:DespatchLine>';
        $i++;
    }

    $xml .= '</DespatchAdvice>';

    return $xml;
}
