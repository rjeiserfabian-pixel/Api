<?php
/**
 * Verificacion publica de documentos emitidos por el servicio.
 *
 * Cada documento tiene un ENLACE de verificacion con un codigo firmado (HMAC con una clave derivada de APP_KEY):
 *
 *     <sitio>/verificar/20123456789-01-F001-150-K7M2Q9XA4BHD
 *                       RUC emisor  tipo serie nro  codigo de 12 caracteres (60 bits, imposible de adivinar)
 *
 * No hay que guardar nada para generarlo: se calcula con los datos del documento, asi funciona tambien para los emitidos antes de
 * existir esta funcion y se puede imprimir en el PDF antes de enviarlo a SUNAT. Sin el codigo correcto no se obtiene ningun dato
 * (no se pueden recorrer numeros de factura ajenos). Quien no tiene el enlace puede consultar con RUC, tipo, serie, numero,
 * fecha y total: TODOS deben coincidir.
 *
 * Se muestra lo minimo: emisor, tipo, numero, fecha, total y el estado segun SUNAT. Nunca datos del cliente comprador.
 * La validez tributaria la confirma SUNAT: esta consulta informa lo que SUNAT respondio al emitir.
 * IMPORTANTE: APP_KEY no debe cambiar una vez impresos documentos (los codigos dejarian de verificar).
 */
require_once __DIR__ . '/Crypto.php';

class Verificacion
{
    const ALFABETO = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'; // base32 de Crockford: sin I, L, O, U (no se confunden al copiar)
    public static $nombres = array('01' => 'Factura electrónica', '03' => 'Boleta de venta electrónica', '07' => 'Nota de crédito electrónica',
        '08' => 'Nota de débito electrónica', '20' => 'Comprobante de retención electrónico', '09' => 'Guía de remisión electrónica');

    // ------------------------------------------------------------------ codigo y enlace
    public static function token($ruc, $tipo, $serie, $numeroN, $entorno)
    {
        $hex = Crypto::firmar('verif|' . $ruc . '|' . $tipo . '|' . strtoupper($serie) . '|' . (int) $numeroN . '|' . ($entorno === 'live' ? 'L' : 'T'));
        $bits = '';
        foreach (str_split(hex2bin(substr($hex, 0, 16))) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= self::ALFABETO[bindec(substr($bits, $i * 5, 5))];
        }
        return $out;
    }

    public static function slug($ruc, $tipo, $serie, $numeroN, $entorno)
    {
        return $ruc . '-' . $tipo . '-' . strtoupper($serie) . '-' . (int) $numeroN . '-' . self::token($ruc, $tipo, $serie, $numeroN, $entorno);
    }

    /** Descompone un enlace/codigo. Devuelve null si no tiene la forma correcta. */
    public static function analizar($slug)
    {
        $s = strtoupper(trim((string) $slug));
        if (!preg_match('/^(\d{11})-(01|03|07|08|20|09)-([A-Z0-9]{1,8})-(\d{1,8})-([0-9A-Z]{12})$/', $s, $m)) {
            return null;
        }
        return array('ruc' => $m[1], 'tipo' => $m[2], 'serie' => $m[3], 'n' => (int) $m[4], 'token' => strtr($m[5], array('O' => '0', 'I' => '1', 'L' => '1')));
    }

    /** Enlace de verificacion de una fila de documentos (necesita el RUC del emisor). */
    public static function slugDeFila(array $d, $ruc)
    {
        if (!in_array($d['tipo'], array('01', '03', '07', '08', '20', '09'), true) || empty($d['serie']) || !isset($d['numero_n']) && !isset($d['numero'])) {
            return null;
        }
        $n = isset($d['numero_n']) && $d['numero_n'] !== null ? $d['numero_n'] : ltrim((string) $d['numero'], '0');
        return self::slug($ruc, $d['tipo'], $d['serie'], $n, $d['entorno']);
    }

    // ------------------------------------------------------------------ direcciones
    private static function base()
    {
        if (PHP_SAPI === 'cli') {
            return 'http://localhost/' . basename(dirname(__DIR__, 2));
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (api_env('PORTAL_PROXY_CONFIABLE') === '1' && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $host = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') ? $_SERVER['HTTP_HOST'] : 'localhost';
        return ($https ? 'https://' : 'http://') . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    }

    /** Direccion de la pagina publica (SITIO_URL; si no, API_BASE_URL + /web; si no, la de esta peticion + /web). */
    public static function urlSitio()
    {
        $s = api_env('SITIO_URL');
        if ($s) {
            return rtrim($s, '/');
        }
        $b = api_env('API_BASE_URL');
        return rtrim($b ? $b : self::base(), '/') . '/web';
    }

    public static function url($slug)
    {
        return self::urlSitio() . '/verificar/' . $slug;
    }

    /** Direccion base de la API (hasta /v1, sin barra final). */
    public static function urlApi()
    {
        $b = api_env('API_BASE_URL');
        return rtrim($b ? $b : self::base(), '/') . '/v1';
    }

    /** Direccion de la imagen QR de ese enlace, en la API. */
    public static function urlQr($slug)
    {
        return self::urlApi() . '/verificar/' . $slug . '/qr';
    }

    public static function enlaces($slug)
    {
        return array('codigo' => $slug, 'url' => self::url($slug), 'qr' => self::urlQr($slug));
    }

    // ------------------------------------------------------------------ busqueda
    private static function fila($ruc, $tipo, $serie, $n, $entorno)
    {
        return Db::uno("SELECT d.*, e.ruc, e.razon_social, e.verificacion_publica FROM documentos d JOIN empresas e ON e.id = d.empresa_id
            WHERE e.ruc = ? AND d.tipo = ? AND UPPER(d.serie) = ? AND d.numero_n = ? AND d.entorno = ?
            ORDER BY CASE WHEN d.estado = 'aceptado' THEN 0 ELSE 1 END, d.id DESC LIMIT 1", array($ruc, $tipo, strtoupper($serie), (int) $n, $entorno));
    }

    /** Por el enlace/codigo. Devuelve la fila o null (codigo mal formado, firma incorrecta o documento inexistente: siempre lo mismo). */
    public static function porEnlace($slug)
    {
        $a = self::analizar($slug);
        if (!$a) {
            return null;
        }
        $fila = null;
        foreach (array('live', 'test') as $ent) { // se calculan los dos siempre (mismo tiempo de respuesta)
            $ok = hash_equals(self::token($a['ruc'], $a['tipo'], $a['serie'], $a['n'], $ent), $a['token']);
            if ($ok && $fila === null) {
                $fila = self::fila($a['ruc'], $a['tipo'], $a['serie'], $a['n'], $ent);
            }
        }
        return $fila ? $fila : null;
    }

    /** Por datos: RUC, tipo, serie, numero, fecha (AAAA-MM-DD) y total (centimos). TODOS deben coincidir con lo guardado. */
    public static function porDatos($ruc, $tipo, $serie, $numero, $fecha, $totalCentimos)
    {
        $serie = strtoupper(trim((string) $serie));
        if (!preg_match('/^\d{11}$/', (string) $ruc) || !in_array($tipo, array('01', '03', '07', '08', '20'), true) || !preg_match('/^[A-Z0-9]{1,8}$/', $serie)
            || !preg_match('/^\d{1,8}$/', (string) $numero) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $fecha) || !is_int($totalCentimos) || $totalCentimos < 0) {
            return null;
        }
        foreach (array('live', 'test') as $ent) {
            $f = self::fila($ruc, $tipo, $serie, (int) $numero, $ent);
            if ($f && $f['fecha_emision'] === $fecha && $f['total'] !== null && (int) $f['total'] === $totalCentimos) {
                return $f;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ lo que se muestra
    /**
     * Informacion PUBLICA del documento (nunca datos del comprador). null si la empresa no habilito la verificacion publica
     * (se trata igual que "no existe").
     */
    public static function publico(array $d)
    {
        if ((int) $d['verificacion_publica'] !== 1) {
            return null;
        }
        $estado = in_array($d['estado'], array('aceptado', 'rechazado', 'pendiente'), true) ? $d['estado'] : 'no_validado';
        $produccion = $d['entorno'] === 'live';
        $slug = self::slugDeFila($d, $d['ruc']);
        return array(
            'estado' => $estado,
            'valido' => $estado === 'aceptado' && $produccion,
            'entorno' => $produccion ? 'produccion' : 'prueba',
            'emisor' => array('ruc' => $d['ruc'], 'razon_social' => $d['razon_social']),
            'tipo' => $d['tipo'], 'tipo_nombre' => isset(self::$nombres[$d['tipo']]) ? self::$nombres[$d['tipo']] : $d['tipo'],
            'serie' => $d['serie'], 'numero' => $d['numero'],
            'fecha_emision' => $d['fecha_emision'],
            'total' => $d['total'] === null ? null : number_format(((int) $d['total']) / 100, 2, '.', ''),
            'moneda' => $d['moneda'],
            'sunat' => $estado === 'no_validado' ? null : array('codigo' => $d['codigo_sunat'], 'descripcion' => $d['mensaje_sunat']),
            'hash' => $d['hash_firma'],
            'verificacion' => $slug ? self::enlaces($slug) : null,
        );
    }

    // ------------------------------------------------------------------ datos para la consulta manual
    /** Lee fecha, total y moneda del XML firmado (lo que SUNAT recibio) y los guarda. Un fallo aqui NUNCA afecta al documento. */
    public static function capturar($uid)
    {
        try {
            $d = Db::uno('SELECT * FROM documentos WHERE uid = ?', array($uid));
            if (!$d || empty($d['ruta_xml']) || ($d['fecha_emision'] !== null && $d['total'] !== null)) {
                return;
            }
            // El documento apunta a su XML por URL; se lee del mismo archivo en disco (solo dentro de files/facturacion_electronica)
            if (!preg_match('#/files/facturacion_electronica/((?:FIRMA|XML)/[0-9A-Za-z._\-]+\.xml)$#', (string) parse_url($d['ruta_xml'], PHP_URL_PATH), $mx)) {
                return;
            }
            $ruta = dirname(__DIR__, 2) . '/files/facturacion_electronica/' . $mx[1];
            if (!is_file($ruta)) {
                return;
            }
            $xml = (string) file_get_contents($ruta, false, null, 0, 800000);
            $fecha = preg_match('#<cbc:IssueDate>(\d{4}-\d{2}-\d{2})</cbc:IssueDate>#', $xml, $m) ? $m[1] : null;
            $total = null;
            $moneda = null;
            if (preg_match('#<cbc:PayableAmount[^>]*currencyID="([A-Z]{3})"[^>]*>\s*([0-9]+(?:\.[0-9]+)?)\s*</cbc:PayableAmount>#', $xml, $m)
                || preg_match('#<cbc:TotalInvoiceAmount[^>]*currencyID="([A-Z]{3})"[^>]*>\s*([0-9]+(?:\.[0-9]+)?)\s*</cbc:TotalInvoiceAmount>#', $xml, $m)) {
                $moneda = $m[1];
                $total = (int) round(((float) $m[2]) * 100);
            }
            if ($fecha !== null || $total !== null) {
                Db::ejecutar('UPDATE documentos SET fecha_emision = COALESCE(fecha_emision, ?), total = COALESCE(total, ?), moneda = COALESCE(moneda, ?) WHERE id = ?', array($fecha, $total, $moneda, $d['id']));
            }
        } catch (Exception $e) {
            error_log('[v1] no se pudieron guardar los datos de verificacion de ' . $uid . ': ' . $e->getMessage());
        }
    }

    /** Total como el cliente lo escribe ("118", "118.00", 118.5) a centimos; null si no es un monto valido. */
    public static function aCentimos($v)
    {
        if (is_int($v) || is_float($v)) {
            $v = number_format($v, 2, '.', '');
        }
        if (!is_string($v) || !preg_match('/^\d{1,9}(\.\d{1,2})?$/', trim($v))) {
            return null;
        }
        $p = explode('.', trim($v));
        return (int) $p[0] * 100 + (isset($p[1]) ? (int) str_pad($p[1], 2, '0') : 0);
    }

    // ------------------------------------------------------------------ QR y limites
    /** Imagen PNG del codigo QR de un texto (la libreria del motor; se escribe a un temporal para no tocar las cabeceras HTTP). */
    public static function qrPng($texto, $tamano = 6)
    {
        require_once dirname(__DIR__, 2) . '/libraries/qr/phpqrcode/qrlib.php';
        $f = tempnam(sys_get_temp_dir(), 'qrv');
        QRcode::png($texto, $f, QR_ECLEVEL_M, $tamano, 2);
        $b = (string) file_get_contents($f);
        @unlink($f);
        return $b;
    }

    /** Cuenta una consulta de esta IP; devuelve false si ya excedio el limite en la ventana (el campo "ventana" de limites se mide en minutos). */
    public static function dentroDelLimite($ip, $clase, $max, $minutos = 10)
    {
        $v = (int) floor(time() / ($minutos * 60));
        $n = Planes::incrementar('verif:' . $clase . ':' . $ip . ':' . $v, ($v + 1) * $minutos);
        return $n <= $max;
    }
}
