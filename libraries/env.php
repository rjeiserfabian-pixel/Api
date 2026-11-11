<?php
/**
 * Lector minimo de .env (sin dependencias).
 * Uso: api_env('ADMIN_USER') o api_env('ALGO', 'valor por defecto').
 * El archivo .env vive en la raiz del proyecto, NO se sube a git y esta bloqueado por .htaccess.
 */
function api_env($clave, $defecto = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        $archivo = dirname(__DIR__) . '/.env';
        if (is_readable($archivo)) {
            foreach (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
                $linea = trim($linea);
                if ($linea === '' || $linea[0] === '#' || strpos($linea, '=') === false) {
                    continue;
                }
                list($k, $v) = explode('=', $linea, 2);
                $v = trim($v);
                $largo = strlen($v);
                if ($largo >= 2 && (($v[0] === '"' && $v[$largo - 1] === '"') || ($v[0] === "'" && $v[$largo - 1] === "'"))) {
                    $v = substr($v, 1, -1);
                }
                $cache[trim($k)] = $v;
            }
        }
    }
    if (isset($cache[$clave])) {
        return $cache[$clave];
    }
    $sistema = getenv($clave);
    return ($sistema !== false) ? $sistema : $defecto;
}
