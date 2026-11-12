<?php
/**
 * Sesiones del portal: cookie HttpOnly + SameSite=Strict (+ Secure bajo HTTPS), identificador renovado al entrar,
 * expiracion por inactividad y absoluta, token CSRF y mensajes de una sola vez.
 */
class Sesion
{
    const INACTIVIDAD = 1800;   // 30 min sin actividad
    const ABSOLUTA = 28800;     // 8 h desde que entro

    // Cada aplicacion (portal de clientes, panel del operador) tiene SU cookie y SU carpeta de sesiones: una sesion de una
    // nunca puede ser presentada ante la otra.
    public static $nombre = 'portal_sid';
    public static $subcarpeta = 'sessions';

    public static function esHttps()
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        // Detras de un proxy/balanceador que termina el HTTPS: solo si el administrador lo declara en .env
        return api_env('PORTAL_PROXY_CONFIABLE') === '1' && isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https';
    }

    /** Carpeta del portal en la URL, p. ej. /API_SUNAT/portal */
    public static function base()
    {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    }

    public static function iniciar()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = dirname(__DIR__, 2) . '/storage/' . self::$subcarpeta; // fuera del alcance web (storage/ esta bloqueada) y aparte de otras sesiones
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        session_name(self::$nombre);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) (self::ABSOLUTA + 3600));
        // PHP 7.2 no tiene el parametro SameSite: se agrega al "path" (truco estandar)
        session_set_cookie_params(0, self::base() . '/; samesite=Strict', '', self::esHttps(), true);
        session_start();

        $ahora = time();
        if (isset($_SESSION['t_inicio'])) {
            $uaOk = !isset($_SESSION['ua']) || hash_equals($_SESSION['ua'], self::huellaNavegador());
            if (!$uaOk || $ahora - $_SESSION['t_ultimo'] > self::INACTIVIDAD || $ahora - $_SESSION['t_inicio'] > self::ABSOLUTA) {
                $motivo = !$uaOk ? 'navegador' : 'tiempo';
                self::destruir();
                session_start();
                session_regenerate_id(true);
                $_SESSION = array('expirada' => $motivo);
            }
        }
        if (isset($_SESSION['t_inicio'])) {
            $_SESSION['t_ultimo'] = $ahora;
        }
    }

    /**
     * Huella de la contrasena vigente. Se guarda en la sesion al entrar: si la contrasena cambia o el operador la restablece,
     * TODAS las demas sesiones abiertas de esa cuenta dejan de valer.
     */
    public static function huellaClave($claveHash)
    {
        return substr(hash('sha256', 'ch|' . $claveHash), 0, 24);
    }

    private static function huellaNavegador()
    {
        return hash('sha256', isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
    }

    public static function usuarioId()
    {
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
    }

    /** Entra: identificador NUEVO (evita fijacion de sesion) y datos limpios. */
    public static function entrar($usuarioId)
    {
        session_regenerate_id(true);
        $_SESSION = array('uid' => (int) $usuarioId, 't_inicio' => time(), 't_ultimo' => time(), 'ua' => self::huellaNavegador(),
            'entorno' => 'test', 'csrf' => bin2hex(random_bytes(32)));
    }

    public static function destruir()
    {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', time() - 3600, self::base() . '/; samesite=Strict', '', self::esHttps(), true);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // ------------------------------------------------------------------ CSRF
    public static function token()
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfValido()
    {
        $t = isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : '';
        if ($t === '' || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
            return false;
        }
        // Defensa adicional: si el navegador indica el origen, debe ser este mismo sitio
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $c) {
            if (!empty($_SERVER[$c])) {
                $h = parse_url($_SERVER[$c], PHP_URL_HOST);
                $p = parse_url($_SERVER[$c], PHP_URL_PORT);
                $origen = $h . ($p ? ':' . $p : '');
                return strcasecmp($origen, $host) === 0;
            }
        }
        return true;
    }

    // ------------------------------------------------------------------ mensajes de una sola vez
    public static function flash($tipo, $mensaje)
    {
        $_SESSION['flash'] = array('tipo' => $tipo === 'ok' ? 'ok' : 'error', 'msg' => (string) $mensaje);
    }

    public static function tomarFlash()
    {
        $f = isset($_SESSION['flash']) ? $_SESSION['flash'] : null;
        unset($_SESSION['flash']);
        return $f;
    }

    /** Valor secreto (llave API, secreto de webhook) que se muestra UNA vez y se borra al leerlo. */
    public static function secretoUnico($etiqueta, $valor)
    {
        $_SESSION['secreto'] = array('etiqueta' => (string) $etiqueta, 'valor' => (string) $valor);
    }

    public static function tomarSecreto()
    {
        $s = isset($_SESSION['secreto']) ? $_SESSION['secreto'] : null;
        unset($_SESSION['secreto']);
        return $s;
    }
}
