<?php
/**
 * Certificado digital por empresa (uno para "test" y otro para "live").
 * La llave privada se guarda cifrada (AES-256-GCM, igual que la clave SOL). El certificado (publico) en claro.
 */
require_once __DIR__ . '/../../libraries/env.php';
require_once __DIR__ . '/Respuesta.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Crypto.php';

class Certificados
{
    private static function fecha($ts)
    {
        $z = new DateTimeZone('America/Lima');
        $d = new DateTime('@' . $ts);
        $d->setTimezone($z);
        return $d->format('Y-m-d H:i:s');
    }

    /** Extrae el PEM (certificado hoja + llave sin clave) de un PFX/P12. */
    public static function leerPfx($binario, $password)
    {
        $c = array();
        if (!@openssl_pkcs12_read($binario, $c, (string) $password) || empty($c['cert']) || empty($c['pkey'])) {
            throw new ApiError(422, 'certificado_invalido', 'No se pudo abrir el PFX. Verifique el archivo y su contrasena.');
        }
        return array($c['cert'], $c['pkey']);
    }

    /**
     * Valida y normaliza. Devuelve array(cert_pem, key_pem_sin_clave, info).
     * En produccion ("live") exige que el certificado contenga el RUC de la empresa.
     */
    public static function validar($certPem, $keyPem, $ruc, $entorno, $clave = null)
    {
        if (!preg_match('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) $certPem, $m)) {
            throw new ApiError(422, 'certificado_invalido', 'No se encontro un certificado en formato PEM.');
        }
        $hoja = str_replace("\r", '', $m[0]) . "\n";
        $x509 = @openssl_x509_read($hoja);
        if ($x509 === false) {
            throw new ApiError(422, 'certificado_invalido', 'El certificado no es valido.');
        }
        $pk = @openssl_pkey_get_private((string) $keyPem, (string) $clave);
        if ($pk === false) {
            throw new ApiError(422, 'llave_invalida', 'La llave privada no es valida' . ($clave === null ? ' (si tiene contrasena, envie key_password).' : ' o la contrasena es incorrecta.'));
        }
        $det = openssl_pkey_get_details($pk);
        if (!$det || $det['type'] !== OPENSSL_KEYTYPE_RSA) {
            throw new ApiError(422, 'llave_invalida', 'La llave debe ser RSA (SUNAT firma con RSA-SHA1).');
        }
        if (!openssl_x509_check_private_key($x509, $pk)) {
            throw new ApiError(422, 'llave_no_corresponde', 'La llave privada no corresponde a ese certificado.');
        }
        $info = openssl_x509_parse($x509);
        $ahora = time();
        if ($info['validTo_time_t'] < $ahora) {
            throw new ApiError(422, 'certificado_vencido', 'El certificado vencio el ' . self::fecha($info['validTo_time_t']) . '.');
        }
        if ($info['validFrom_time_t'] > $ahora) {
            throw new ApiError(422, 'certificado_no_vigente', 'El certificado aun no es vigente (desde ' . self::fecha($info['validFrom_time_t']) . ').');
        }
        $contieneRuc = strpos(json_encode($info['subject']), (string) $ruc) !== false;
        if ($entorno === 'live' && !$contieneRuc) {
            throw new ApiError(422, 'certificado_ruc_no_coincide', "El certificado no pertenece al RUC $ruc. Para produccion debe estar emitido a nombre de la empresa.");
        }
        $keyLimpia = self::llaveSinClave($pk, (string) $keyPem, $clave);

        $sujeto = isset($info['subject']['CN']) ? (is_array($info['subject']['CN']) ? implode(' ', $info['subject']['CN']) : $info['subject']['CN']) : '';
        return array($hoja, $keyLimpia, array(
            'sujeto' => $sujeto,
            'serie' => isset($info['serialNumberHex']) ? $info['serialNumberHex'] : '',
            'huella' => openssl_x509_fingerprint($x509, 'sha256'),
            'valido_desde' => self::fecha($info['validFrom_time_t']),
            'valido_hasta' => self::fecha($info['validTo_time_t']),
            'ruc_coincide' => $contieneRuc,
        ));
    }

    /** Extrae el PEM de la llave sin contrasena (Windows no siempre puede re-exportar sin openssl.cnf). */
    private static function llaveSinClave($pk, $keyPem, $clave)
    {
        if ($clave === null || $clave === '') {
            if (preg_match('/-----BEGIN (RSA )?PRIVATE KEY-----.+?-----END (RSA )?PRIVATE KEY-----/s', $keyPem, $mm)) {
                return str_replace("", '', $mm[0]) . "
";
            }
        }
        $out = '';
        if (@openssl_pkey_export($pk, $out) && $out) {
            return $out;
        }
        foreach (array(getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf') as $cfg) {
            if ($cfg && is_file($cfg) && @openssl_pkey_export($pk, $out, null, array('config' => $cfg)) && $out) {
                return $out;
            }
        }
        throw new ApiError(422, 'llave_invalida', 'No se pudo procesar la llave privada. Envielala sin contrasena (openssl rsa -in llave.pem -out llave_sin_clave.pem) o use un PFX.');
    }

    public static function guardar($empresaId, $entorno, $certPem, $keyPem, array $info)
    {
        $previo = Db::uno('SELECT id FROM certificados WHERE empresa_id = ? AND entorno = ?', array($empresaId, $entorno));
        $cifrada = Crypto::cifrar($keyPem);
        if ($previo) {
            Db::ejecutar('UPDATE certificados SET cert_pem = ?, key_cifrada = ?, sujeto = ?, serie_cert = ?, huella = ?, valido_desde = ?, valido_hasta = ?, creado = ? WHERE id = ?',
                array($certPem, $cifrada, $info['sujeto'], $info['serie'], $info['huella'], $info['valido_desde'], $info['valido_hasta'], Db::ahora(), $previo['id']));
        } else {
            Db::ejecutar('INSERT INTO certificados (empresa_id, entorno, cert_pem, key_cifrada, sujeto, serie_cert, huella, valido_desde, valido_hasta, creado) VALUES (?,?,?,?,?,?,?,?,?,?)',
                array($empresaId, $entorno, $certPem, $cifrada, $info['sujeto'], $info['serie'], $info['huella'], $info['valido_desde'], $info['valido_hasta'], Db::ahora()));
        }
    }

    /** Estado publico (sin secretos) o null si no hay certificado. */
    public static function estado($empresaId, $entorno)
    {
        $c = Db::uno('SELECT * FROM certificados WHERE empresa_id = ? AND entorno = ?', array($empresaId, $entorno));
        if (!$c) {
            return null;
        }
        $dias = (int) floor((strtotime($c['valido_hasta']) - time()) / 86400);
        return array(
            'entorno' => $entorno === 'live' ? 'produccion' : 'prueba',
            'sujeto' => $c['sujeto'], 'huella_sha256' => $c['huella'], 'serie' => $c['serie_cert'],
            'valido_desde' => $c['valido_desde'], 'valido_hasta' => $c['valido_hasta'],
            'dias_restantes' => $dias, 'vigente' => $dias >= 0,
            'por_vencer' => $dias >= 0 && $dias <= 30,
        );
    }

    /** Antes de emitir en produccion: la empresa debe tener certificado propio y vigente. */
    public static function exigirParaEmitir($empresaId, $entorno)
    {
        if ($entorno !== 'live') {
            return;
        }
        $e = self::estado($empresaId, 'live');
        if (!$e) {
            throw new ApiError(422, 'certificado_requerido', 'La empresa no tiene certificado digital de produccion. Registrelo con POST /v1/certificado usando su API key live.');
        }
        if (!$e['vigente']) {
            throw new ApiError(422, 'certificado_vencido', 'El certificado de produccion vencio el ' . $e['valido_hasta'] . '. Suba uno nuevo con POST /v1/certificado.');
        }
    }

    /**
     * Usado por el motor al firmar. Devuelve array('cert'=>..,'key'=>..) o null.
     *  - RUC no registrado en la API (uso antiguo directo): null => se usa la carpeta libraries/certificado_digital/.
     *  - Empresa registrada: usa SU certificado. Si es produccion y no lo tiene (o vencio) => excepcion, nunca firma con el de otra empresa.
     */
    public static function paraFirmar($ruc, $modo)
    {
        $raiz = dirname(__DIR__, 2);
        if (!api_env('DB_DSN') && !is_file($raiz . '/storage/api.sqlite')) {
            return null;
        }
        $entorno = ((int) $modo === 1) ? 'live' : 'test';
        $emp = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array((string) $ruc));
        if (!$emp) {
            return null;
        }
        $c = Db::uno('SELECT * FROM certificados WHERE empresa_id = ? AND entorno = ?', array($emp['id'], $entorno));
        if (!$c) {
            if ($entorno === 'live') {
                throw new RuntimeException("La empresa $ruc no tiene certificado digital de produccion registrado.");
            }
            return null; // pruebas: certificado compartido de beta
        }
        if ($entorno === 'live' && strtotime($c['valido_hasta']) < time()) {
            throw new RuntimeException("El certificado de produccion de la empresa $ruc esta vencido.");
        }
        return array('cert' => $c['cert_pem'], 'key' => Crypto::descifrar($c['key_cifrada']));
    }
}
