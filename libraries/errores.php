<?php
/**
 * Manejo de errores para los endpoints publicos.
 * - No muestra errores de PHP al cliente (pueden revelar rutas y datos).
 * - Los guarda en logs/php_errors.log (carpeta bloqueada por .htaccess).
 * - Si ocurre un error fatal, responde HTTP 500 con un JSON generico.
 */
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$api_dir_logs = dirname(__DIR__) . '/logs';
if (!is_dir($api_dir_logs)) {
    @mkdir($api_dir_logs, 0755, true);
}
ini_set('error_log', $api_dir_logs . '/php_errors.log');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e !== null && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('error' => 'Error interno del servidor'));
    }
});
