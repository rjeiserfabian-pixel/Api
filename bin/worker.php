<?php
/**
 * Worker de la API: trabajo en segundo plano (solo linea de comandos).
 *
 *   php bin/worker.php                  # queda corriendo, un ciclo cada 5 segundos (Ctrl+C para detener)
 *   php bin/worker.php --una-vez        # un solo ciclo y termina (para cron / Programador de tareas)
 *   php bin/worker.php --pausa=10 --max-ciclos=100
 *
 * En cada ciclo:
 *   1) rescata documentos que quedaron "procesando" por un proceso que murio;
 *   2) REINTENTA los envios que fallaron por un problema transitorio (SUNAT/motor caido): solo los que llegaron con
 *      Idempotency-Key, con espera creciente (1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h);
 *   3) consulta los tickets de guias pendientes;
 *   4) envia los avisos (webhooks) pendientes, con reintentos (1 min ... 24 h);
 *   5) procesa los vencimientos de suscripciones (prueba terminada, gracia, paso al plan de destino).
 *
 * Es seguro ejecutar mas de un worker a la vez: cada documento/aviso se "toma" de forma atomica.
 * Para detenerlo con calma cree el archivo storage/worker.stop (se borra solo al detenerse).
 * Produccion: Linux -> systemd o supervisor con el modo sin argumentos; o cron cada minuto con --una-vez.
 *             Windows -> Programador de tareas ("al iniciar el sistema") con el modo sin argumentos.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Certificados', 'Validador', 'Motor', 'Series', 'Planes', 'Documentos', 'Webhooks') as $c) {
    require_once $raiz . '/v1/src/' . $c . '.php';
}
foreach (array('Usuarios', 'Suscripciones', 'Cobros') as $c) {
    require_once $raiz . '/portal/src/' . $c . '.php';
}
date_default_timezone_set('America/Lima');
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

$o = array();
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/s', $a, $m)) {
        $o[$m[1]] = isset($m[2]) ? $m[2] : '1';
    }
}
$unaVez = isset($o['una-vez']);
$pausa = isset($o['pausa']) ? max(1, (int) $o['pausa']) : 5;
$maxCiclos = isset($o['max-ciclos']) ? (int) $o['max-ciclos'] : 0;
$parar = $raiz . '/storage/worker.stop';
$latido = $raiz . '/storage/worker.heartbeat';
$log = $raiz . '/logs/worker.log';
if (!is_dir($raiz . '/logs')) {
    @mkdir($raiz . '/logs', 0755, true);
}

function anotar($msg)
{
    global $log;
    $linea = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $linea;
    if (is_file($log) && filesize($log) > 5 * 1024 * 1024) {
        @rename($log, $log . '.1'); // rotacion simple
    }
    @file_put_contents($log, $linea, FILE_APPEND);
}

/** Ejecuta un paso del ciclo; un error en un paso no detiene los demas. */
function paso($nombre, $fn)
{
    try {
        ob_start();
        $n = $fn();
        $salida = trim(ob_get_clean());
        if ($n || $salida !== '') {
            anotar("$nombre: $n" . ($salida !== '' ? "\n" . $salida : ''));
        }
        return $n;
    } catch (Throwable $e) {
        if (ob_get_level()) {
            ob_end_clean();
        }
        anotar("ERROR en $nombre: " . get_class($e) . ': ' . $e->getMessage());
        return 0;
    }
}

// Comprobacion de conectividad: el worker llama al motor por HTTP igual que la API
$chk = curl_init(Motor::urlInterna() . '/v1/health');
curl_setopt_array($chk, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4));
curl_exec($chk);
$chkHttp = (int) curl_getinfo($chk, CURLINFO_HTTP_CODE);
curl_close($chk);
if ($chkHttp !== 200) {
    anotar('ADVERTENCIA: el worker no llega a la API en ' . Motor::urlInterna() . ' (HTTP ' . $chkHttp . '). Los reintentos fallaran. Defina API_INTERNAL_URL en .env con la direccion local de la API.');
}

if (!api_env('API_BASE_URL')) {
    anotar('AVISO: API_BASE_URL no esta definida en .env; los enlaces a XML/CDR/PDF que genere el worker (y viajen en los webhooks) apuntaran a http://localhost/' . basename($raiz) . '. En produccion defina API_BASE_URL con la direccion publica.');
}

anotar('Worker iniciado' . ($unaVez ? ' (un ciclo)' : " (cada {$pausa}s)") . ' - motor de base de datos: ' . Db::motor());
$ciclo = 0;
do {
    $ciclo++;
    @file_put_contents($latido, (string) time());
    try {
        Db::asegurarEsquema();
    } catch (Throwable $e) {
        anotar('ERROR de base de datos: ' . $e->getMessage());
        if ($unaVez) {
            exit(1);
        }
        sleep($pausa);
        continue;
    }
    paso('documentos abandonados rescatados', function () {
        return Documentos::rescatar();
    });
    paso('reintentos de envio', function () {
        $n = 0;
        foreach (Documentos::pendientesDeReintento(5) as $fila) {
            $r = Documentos::reintentar($fila);
            echo '  ' . $fila['tipo'] . ' ' . $fila['serie'] . '-' . $fila['numero'] . ' (intento ' . ((int) $fila['reintentos'] + 1) . ') -> ' . $r . "\n";
            $n++;
            usleep(500000); // no saturar a SUNAT
        }
        return $n;
    });
    paso('tickets de guia consultados', function () {
        return Documentos::consultarTicketsGuia(10);
    });
    paso('avisos (webhooks) enviados', function () {
        return Webhooks::procesarEntregas(20);
    });
    paso('suscripciones vencidas procesadas', function () {
        return Cobros::procesarVencimientos();
    });
    paso('contadores de limite antiguos borrados', function () {
        return Planes::limpiar();
    });
    if ($unaVez || ($maxCiclos > 0 && $ciclo >= $maxCiclos)) {
        break;
    }
    if (is_file($parar)) {
        @unlink($parar);
        anotar('Detenido por storage/worker.stop');
        break;
    }
    sleep($pausa);
} while (true);
if ($unaVez) {
    anotar('Ciclo terminado');
}
