<?php
/**
 * Puente hacia el motor de facturacion existente (post.php, baja.php, resumen.php, ...).
 * - Arma el bloque "empresa" desde la base de datos (el cliente nunca envia credenciales).
 * - Llama al motor por HTTP local y traduce su respuesta a un formato uniforme.
 */
class Motor
{
    /** Datos de la empresa en el formato que espera el motor, segun el entorno de la API key. */
    public static function empresa(array $e, $entorno)
    {
        $modo = ($entorno === 'live') ? 1 : 0;
        $user = (string) $e['sol_usuario'];
        $pass = Crypto::descifrar($e['sol_clave_cifrada']);
        return array(
            'ruc' => $e['ruc'],
            'razon_social' => $e['razon_social'],
            'nombre_comercial' => $e['nombre_comercial'],
            'domicilio_fiscal' => $e['domicilio_fiscal'],
            'ubigeo' => $e['ubigeo'],
            'departamento' => $e['departamento'],
            'provincia' => $e['provincia'],
            'distrito' => $e['distrito'],
            'urbanizacion' => (string) $e['urbanizacion'],
            'cuenta_detraccion' => (string) $e['cuenta_detraccion'],
            'modo' => $modo,
            // Segun el endpoint, el motor lee uno u otro par de campos:
            'usu_secundario_produccion_user' => $user,
            'usu_secundario_produccion_password' => $pass,
            'usu_secundario_user' => $user,
            'usu_secundario_password' => $pass,
            'gre_client_id' => (string) $e['gre_client_id'],
            'gre_client_secret' => Crypto::descifrar($e['gre_secret_cifrado']),
        );
    }

    /** Cuerpo que se envia al motor: el cliente nunca aporta credenciales; se agregan aqui (y NO se guardan en la base). */
    public static function prepararCuerpo($script, array $cuerpo, array $empresaMotor)
    {
        $cuerpo['empresa'] = $empresaMotor;
        if ($script === 'post_retencion.php') {
            $cuerpo['emisor'] = $empresaMotor; // este endpoint del motor lee "emisor"
        }
        return $cuerpo;
    }

    public static function urlInterna()
    {
        $u = api_env('API_INTERNAL_URL');
        if ($u) {
            return rtrim($u, '/');
        }
        if (PHP_SAPI === 'cli') {
            // El worker no tiene peticion web de la que deducir la carpeta: se usa el nombre de la carpeta del proyecto
            // (ej. http://127.0.0.1/API_SUNAT). Si el servidor web la publica con otro nombre o puerto, defina API_INTERNAL_URL en .env.
            return 'http://127.0.0.1/' . basename(dirname(__DIR__, 2));
        }
        // Nunca se usa el Host de la peticion (evita enviar credenciales a un host ajeno).
        $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
        return 'http://127.0.0.1' . $base;
    }

    /** Llama a un script del motor. Devuelve array('http' => int, 'json' => array|null, 'raw' => string). */
    public static function llamar($script, array $payload, $timeout = 100)
    {
        $ch = curl_init(self::urlInterna() . '/' . $script);
        $cab = array('Content-Type: application/json');
        $token = api_env('INTERNAL_TOKEN');
        if ($token) {
            $cab[] = 'X-Internal-Token: ' . $token;
        }
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $cab,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) $timeout,
        ));
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($raw === false && $errno === 28) {
            error_log('[v1] tiempo agotado esperando al motor/SUNAT (' . $script . ', ' . $timeout . ' s)');
            throw new ApiError(504, 'tiempo_agotado', 'SUNAT no respondio a tiempo. Intente de nuevo en unos minutos.');
        }
        if ($raw === false) {
            error_log('[v1] motor sin respuesta (' . $script . '): ' . $err);
            throw new ApiError(502, 'motor_no_disponible', 'El motor de facturacion no respondio. Intente nuevamente.');
        }
        $json = json_decode($raw, true);
        return array('http' => $http, 'json' => is_array($json) ? $json : null, 'raw' => $raw);
    }

    /**
     * Traduce la respuesta del motor a: estado, sunat{codigo,descripcion}, ticket, hash, archivos.
     * estado: aceptado | rechazado | pendiente | simulado
     */
    public static function normalizar($script, array $res)
    {
        $j = $res['json'];
        if ($j === null) {
            error_log('[v1] respuesta no JSON del motor (' . $script . '): ' . substr($res['raw'], 0, 600));
            throw new ApiError(502, 'motor_error', 'El motor devolvio una respuesta inesperada. Revise logs/php_errors.log (request ' . Respuesta::id() . ').');
        }

        // baja / resumen / reversion: {"ticket": "123"} o {"ticket": {"error": "..."}}
        if (array_key_exists('ticket', $j) && !isset($j['data'])) {
            $t = $j['ticket'];
            if (is_array($t) && isset($t['error'])) {
                return self::resultado('rechazado', null, (string) $t['error'], null, null, array());
            }
            if (is_string($t) && preg_match('/^[A-Za-z0-9-]{6,80}$/', $t) && $t !== 'null') {
                return self::resultado('pendiente', null, 'Recibido por SUNAT. Consulte el ticket para conocer el resultado.', $t, null, array());
            }
            error_log('[v1] ticket inesperado (' . $script . '): ' . substr(is_string($t) ? $t : json_encode($t), 0, 600));
            throw new ApiError(502, 'motor_error', 'SUNAT no devolvio un ticket valido. Revise logs/php_errors.log (request ' . Respuesta::id() . ').');
        }

        $d = isset($j['data']) ? $j['data'] : $j;
        if (!is_array($d)) {
            throw new ApiError(502, 'motor_error', 'Respuesta del motor no reconocida.');
        }
        $codigo = isset($d['respuesta_sunat_codigo']) ? (string) $d['respuesta_sunat_codigo'] : null;
        $desc = isset($d['respuesta_sunat_descripcion']) ? (string) $d['respuesta_sunat_descripcion'] : null;
        $hash = isset($d['codigo_hash']) ? (is_array($d['codigo_hash']) ? reset($d['codigo_hash']) : $d['codigo_hash']) : null;
        $archivos = array();
        foreach (array('xml' => 'ruta_xml', 'cdr' => 'ruta_cdr', 'pdf' => 'ruta_pdf') as $k => $campo) {
            if (!empty($d[$campo])) {
                $archivos[$k] = $d[$campo];
            }
        }
        foreach (array('xml_base_64', 'cdr_base_64') as $k) {
            if (!empty($d[$k])) {
                $archivos[$k] = $d[$k];
            }
        }
        $ticket = !empty($d['num_ticket']) ? $d['num_ticket'] : (!empty($d['estado_ticket']) ? $d['estado_ticket'] : null);

        // Ticket de guia (GRE): "EN_PROCESO" (codigo 98) NO es un rechazo: SUNAT aun esta procesando
        if (isset($d['estado']) && in_array($d['estado'], array('EN_PROCESO', 'ERROR_REST', 'DESCONOCIDO'), true)) {
            if ($d['estado'] !== 'EN_PROCESO') {
                error_log('[v1] ticket de guia sin resultado utilizable (' . $script . '): ' . substr($res['raw'], 0, 500));
                throw new ApiError(502, 'motor_error', 'SUNAT no devolvio un resultado utilizable para el ticket (request ' . Respuesta::id() . '). Intente de nuevo mas tarde.');
            }
            $estado = 'pendiente';
        } elseif ($codigo !== null && strpos($codigo, 'OAUTH_ERROR') === 0) {
            error_log('[v1] error de autenticacion GRE (' . $script . '): ' . $desc);
            throw new ApiError(502, 'motor_error', 'No se pudo autenticar ante SUNAT con las credenciales GRE de la empresa (request ' . Respuesta::id() . ').');
        } elseif (!empty($d['simulado'])) {
            $estado = 'simulado';
        } elseif (isset($d['estado']) && $d['estado'] === 'ACEPTADO') {
            $estado = 'aceptado';
        } elseif ($codigo === 'TICKET_GENERADO') {
            $estado = 'pendiente';
        } elseif ((isset($d['error']) && ($d['error'] === 'true' || $d['error'] === true)) || ($codigo !== null && $codigo !== '0')) {
            $estado = 'rechazado';
        } elseif ($codigo === '0') {
            $estado = 'aceptado';
        } else {
            error_log('[v1] estado no determinado (' . $script . '): ' . substr($res['raw'], 0, 600));
            throw new ApiError(502, 'motor_error', 'No se pudo interpretar la respuesta del motor (request ' . Respuesta::id() . ').');
        }
        return self::resultado($estado, $codigo, $desc, $ticket, $hash, $archivos);
    }

    private static function resultado($estado, $codigo, $desc, $ticket, $hash, array $archivos)
    {
        return array('estado' => $estado, 'sunat' => array('codigo' => $codigo, 'descripcion' => $desc),
            'ticket' => $ticket, 'hash' => $hash, 'archivos' => $archivos);
    }

    /** Convierte las rutas internas (http://127.0.0.1/...) en enlaces publicos. */
    public static function urlPublica($ruta)
    {
        $pos = strpos((string) $ruta, '/files/');
        if ($pos === false) {
            return $ruta;
        }
        $base = api_env('API_BASE_URL');
        if (!$base && PHP_SAPI === 'cli') {
            // El worker no tiene peticion web: en produccion defina API_BASE_URL (direccion publica, ej. https://midominio.com/API_SUNAT)
            // para que los enlaces de los avisos y del historial apunten al dominio correcto.
            $base = 'http://localhost/' . basename(dirname(__DIR__, 2));
        }
        if (!$base) {
            $https =(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
            $host = preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') ? $_SERVER['HTTP_HOST'] : 'localhost';
            $base = ($https ? 'https://' : 'http://') . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
        }
        return rtrim($base, '/') . substr($ruta, $pos);
    }
}
