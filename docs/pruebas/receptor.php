<?php
// Receptor de webhooks (solo para pruebas). Registra cada peticion en receptor.log.
// Herramienta de DESARROLLO: no se usa en produccion. La arranca docs/probar_reintentos.php.
$dir = __DIR__ . '/tmp';
if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
}
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
$cuerpo = file_get_contents('php://input');
$h = array();
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_X_WEBHOOK') === 0 || $k === 'CONTENT_TYPE' || $k === 'HTTP_USER_AGENT') {
        $h[$k] = $v;
    }
}
file_put_contents($dir . '/receptor.log', json_encode(array('t' => date('H:i:s'), 'ruta' => $ruta, 'cab' => $h, 'cuerpo' => $cuerpo)) . "\n", FILE_APPEND);

if ($ruta === '/error500') {
    http_response_code(500);
    echo 'boom';
} elseif ($ruta === '/lento') {
    sleep(15);
    echo 'ok tarde';
} elseif ($ruta === '/redirect') {
    http_response_code(302);
    header('Location: /ok');
} elseif ($ruta === '/grande') {
    echo str_repeat('x', 200000);
} elseif ($ruta === '/flaky') { // falla las primeras N veces
    $f = $dir . '/flaky_' . (isset($q['id']) ? preg_replace('/\W/', '', $q['id']) : 'x') . '.txt';
    $n = (int) @file_get_contents($f);
    file_put_contents($f, $n + 1);
    if ($n < (int) (isset($q['n']) ? $q['n'] : 2)) {
        http_response_code(503);
        echo 'todavia no';
    } else {
        echo 'ok';
    }
} else {
    echo '{"ok":true}';
}
