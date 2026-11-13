<?php
/**
 * Cifrado de secretos (clave SOL, secreto GRE) con AES-256-GCM.
 * La llave maestra es APP_KEY en .env (32 bytes en base64). Se genera con: php bin/admin.php init
 */
class Crypto
{
    private static function llave()
    {
        $b64 = api_env('APP_KEY');
        $raw = $b64 ? base64_decode($b64, true) : false;
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('APP_KEY no configurada. Ejecute: php bin/admin.php init');
        }
        return $raw;
    }

    /** Firma HMAC-SHA256 con una clave derivada de APP_KEY (para tokens que no deben poder falsificarse). */
    public static function firmar($texto)
    {
        return hash_hmac('sha256', $texto, hash('sha256', 'firma|' . self::llave(), true));
    }

    public static function cifrar($texto)
    {
        if ($texto === null || $texto === '') {
            return null;
        }
        $iv = random_bytes(12);
        $cifrado = openssl_encrypt($texto, 'aes-256-gcm', self::llave(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cifrado === false) {
            throw new RuntimeException('No se pudo cifrar');
        }
        return base64_encode($iv . $tag . $cifrado);
    }

    public static function descifrar($valor)
    {
        if ($valor === null || $valor === '') {
            return '';
        }
        $bin = base64_decode($valor, true);
        if ($bin === false || strlen($bin) < 29) {
            throw new RuntimeException('Dato cifrado invalido');
        }
        $texto = openssl_decrypt(substr($bin, 28), 'aes-256-gcm', self::llave(), OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, 12, 16));
        if ($texto === false) {
            throw new RuntimeException('No se pudo descifrar (APP_KEY distinta?)');
        }
        return $texto;
    }
}
