<?php
/**
 * Guardian de los endpoints del motor (post.php, baja.php, resumen.php, ...).
 * Con BLOQUEAR_LEGACY=1 en .env, solo atiende peticiones que traen la cabecera
 * X-Internal-Token correcta (la envia la API /v1). Asi nadie puede saltarse la
 * autenticacion llamando al motor directamente.
 * Sin esa variable no hace nada (compatibilidad con integraciones antiguas).
 */
require_once __DIR__ . '/env.php';
if (api_env('BLOQUEAR_LEGACY') === '1') {
    $esperado = (string) api_env('INTERNAL_TOKEN', '');
    $recibido = isset($_SERVER['HTTP_X_INTERNAL_TOKEN']) ? $_SERVER['HTTP_X_INTERNAL_TOKEN'] : '';
    if ($esperado === '' || !hash_equals($esperado, $recibido)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('error' => 'Acceso directo deshabilitado. Use la API /v1.'));
        exit;
    }
}
