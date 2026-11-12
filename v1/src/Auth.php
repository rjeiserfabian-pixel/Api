<?php
/**
 * Autenticacion por API key.  Cabecera:  Authorization: Bearer sk_test_xxxxx   (o X-API-Key)
 *   sk_test_...  -> entorno "test": SUNAT beta (modo 0)
 *   sk_live_...  -> entorno "live": SUNAT produccion (modo 1)
 * En la base solo se guarda el hash SHA-256 de la llave; la llave completa se muestra una sola vez al crearla.
 */
class Auth
{
    public static function generarKey($entorno)
    {
        return 'sk_' . $entorno . '_' . bin2hex(random_bytes(20));
    }

    public static function hash($key)
    {
        return hash('sha256', $key);
    }

    private static function leerKey()
    {
        $h = '';
        foreach (array('HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION') as $k) {
            if (!empty($_SERVER[$k])) {
                $h = $_SERVER[$k];
                break;
            }
        }
        if ($h === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $nombre => $valor) {
                if (strtolower($nombre) === 'authorization') {
                    $h = $valor;
                }
            }
        }
        if (stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }
        return isset($_SERVER['HTTP_X_API_KEY']) ? trim($_SERVER['HTTP_X_API_KEY']) : '';
    }

    /** Devuelve array('key' => fila api_keys, 'empresa' => fila empresas) o lanza ApiError 401. */
    public static function autenticar()
    {
        $key = self::leerKey();
        if ($key === '' || !preg_match('/^sk_(test|live)_[a-f0-9]{40}$/', $key)) {
            throw new ApiError(401, 'no_autenticado', 'Falta la API key o es invalida. Envie: Authorization: Bearer sk_test_...');
        }
        $fila = Db::uno('SELECT * FROM api_keys WHERE hash = ?', array(self::hash($key)));
        if (!$fila || (int) $fila['activo'] !== 1) {
            throw new ApiError(401, 'no_autenticado', 'API key invalida o revocada.');
        }
        $empresa = Db::uno('SELECT * FROM empresas WHERE id = ?', array($fila['empresa_id']));
        if (!$empresa || (int) $empresa['activo'] !== 1) {
            throw new ApiError(403, 'empresa_inactiva', 'La empresa asociada a esta API key esta desactivada.');
        }
        Db::ejecutar('UPDATE api_keys SET ultimo_uso = ? WHERE id = ?', array(Db::ahora(), $fila['id']));
        return array('key' => $fila, 'empresa' => $empresa);
    }
}
