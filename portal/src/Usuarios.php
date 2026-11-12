<?php
/**
 * Usuarios del portal: alta, contrasenas, control de intentos y registro de actividad (auditoria).
 */
class Auditoria
{
    public static function registrar($empresaId, $usuarioId, $accion, $detalle = '')
    {
        try {
            Db::ejecutar('INSERT INTO auditoria (empresa_id, usuario_id, accion, detalle, ip, creado) VALUES (?,?,?,?,?,?)',
                array((int) $empresaId, $usuarioId ? (int) $usuarioId : null, substr($accion, 0, 40), substr((string) $detalle, 0, 300),
                    isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null, Db::ahora()));
        } catch (Exception $e) {
            error_log('[portal] no se pudo registrar la actividad: ' . $e->getMessage());
        }
    }

    public static function recientes($empresaId, $limite = 50)
    {
        return Db::consultar('SELECT a.*, u.email FROM auditoria a LEFT JOIN usuarios_portal u ON u.id = a.usuario_id WHERE a.empresa_id = ? ORDER BY a.id DESC LIMIT ' . (int) $limite, array($empresaId));
    }
}

class Usuarios
{
    const FALLOS_PARA_BLOQUEO = 5;
    const MINUTOS_BLOQUEO = 15;
    const FALLOS_POR_IP = 20;      // por IP y por ventana de 15 minutos
    // Hash de relleno: si el correo no existe se verifica igual contra este (mismo tiempo de respuesta: no revela si el correo existe)
    const HASH_RELLENO = '$2y$10$bQOzQdeei7iOf9rvWWGTiurkqe6nobbuggzJ/MOzdhjsuI8vmNOCm';

    private static $comunes = array('password', 'contrasena', '1234567890', '123456789012', 'qwertyuiop', 'abcdefghij', 'administrador', 'letmein123', 'iloveyou123', 'welcome1234',
        'passw0rd123', 'contraseña1', 'sunat123456', 'factura12345', 'admin12345', 'changeme123');

    /** Devuelve un mensaje si la clave no cumple la politica; null si esta bien. */
    public static function revisarClave($clave, $email = '')
    {
        $largo = function_exists('mb_strlen') ? mb_strlen($clave, 'UTF-8') : strlen($clave);
        if ($largo < 10) {
            return 'La contraseña debe tener al menos 10 caracteres.';
        }
        if ($largo > 128) {
            return 'La contraseña es demasiado larga (máximo 128).';
        }
        $clases = (preg_match('/[a-z]/', $clave) ? 1 : 0) + (preg_match('/[A-Z]/', $clave) ? 1 : 0) + (preg_match('/\d/', $clave) ? 1 : 0) + (preg_match('/[^A-Za-z0-9]/', $clave) ? 1 : 0);
        if ($clases < 2) {
            return 'Use al menos dos tipos de caracteres (minúsculas, mayúsculas, números o símbolos).';
        }
        $minus = strtolower($clave);
        if (in_array($minus, self::$comunes, true) || ($email !== '' && strpos($minus, strtolower(strstr($email . '@', '@', true))) !== false && strlen(strstr($email . '@', '@', true)) >= 4)) {
            return 'Esa contraseña es demasiado común o contiene su correo. Elija otra.';
        }
        return null;
    }

    public static function claveTemporal()
    {
        do {
            $c = substr(strtr(base64_encode(random_bytes(12)), '+/=', 'Xy7'), 0, 14);
        } while (self::revisarClave($c) !== null);
        return $c;
    }

    public static function porId($id)
    {
        return Db::uno('SELECT * FROM usuarios_portal WHERE id = ?', array((int) $id));
    }

    public static function porEmail($email)
    {
        return Db::uno('SELECT * FROM usuarios_portal WHERE email = ?', array(strtolower(trim($email))));
    }

    /** Alta (por consola). Devuelve array('id', 'clave') con la contrasena temporal en claro (se muestra una sola vez). */
    public static function crear($empresaId, $email, $nombre, $rol)
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new InvalidArgumentException('El correo no es valido.');
        }
        if (!in_array($rol, array('admin', 'lector'), true)) {
            throw new InvalidArgumentException('El rol debe ser admin o lector.');
        }
        if (self::porEmail($email)) {
            throw new InvalidArgumentException('Ya existe un usuario con ese correo.');
        }
        $clave = self::claveTemporal();
        $id = Db::ejecutar('INSERT INTO usuarios_portal (empresa_id, email, nombre, rol, clave_hash, debe_cambiar, activo, creado) VALUES (?,?,?,?,?,1,1,?)',
            array((int) $empresaId, $email, substr(trim($nombre), 0, 120), $rol, password_hash($clave, PASSWORD_DEFAULT), Db::ahora()));
        return array('id' => $id, 'clave' => $clave);
    }

    public static function restablecer($email)
    {
        $u = self::porEmail($email);
        if (!$u) {
            throw new InvalidArgumentException('No existe ese usuario.');
        }
        $clave = self::claveTemporal();
        Db::ejecutar('UPDATE usuarios_portal SET clave_hash = ?, debe_cambiar = 1, intentos_fallidos = 0, bloqueado_hasta = NULL, activo = 1 WHERE id = ?', array(password_hash($clave, PASSWORD_DEFAULT), $u['id']));
        Auditoria::registrar($u['empresa_id'], null, 'clave_restablecida', 'por el administrador del servicio');
        return $clave;
    }

    private static function contador($clave)
    {
        $r = Db::uno('SELECT n FROM limites WHERE clave = ?', array($clave));
        return $r ? (int) $r['n'] : 0;
    }

    /**
     * Verifica correo y contrasena. Devuelve la fila del usuario o null (siempre el mismo resultado ante cualquier fallo:
     * correo inexistente, clave mala, cuenta bloqueada o inactiva).
     */
    public static function autenticar($email, $clave, $ip)
    {
        $ventana = (int) floor(time() / 900);
        $claveIp = 'login:ip:' . $ip . ':' . $ventana;
        if (self::contador($claveIp) >= self::FALLOS_POR_IP) {
            return null; // demasiados fallos desde esa IP
        }
        $u = self::porEmail($email);
        $ok = password_verify((string) $clave, $u ? $u['clave_hash'] : self::HASH_RELLENO);
        $bloqueada = $u && $u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time();
        $empresaActiva = false;
        if ($u) {
            $e = Db::uno('SELECT activo FROM empresas WHERE id = ?', array($u['empresa_id']));
            $empresaActiva = $e && (int) $e['activo'] === 1;
        }
        if (!$u || !$ok || $bloqueada || (int) $u['activo'] !== 1 || !$empresaActiva) {
            Planes::incrementar($claveIp, $ventana);
            if ($u) {
                $n = (int) $u['intentos_fallidos'] + 1;
                if ($n >= self::FALLOS_PARA_BLOQUEO) {
                    Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() + 60 * self::MINUTOS_BLOQUEO), $u['id']));
                    Auditoria::registrar($u['empresa_id'], $u['id'], 'cuenta_bloqueada', 'por intentos fallidos (' . self::MINUTOS_BLOQUEO . ' min)');
                } else {
                    Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = ? WHERE id = ?', array($n, $u['id']));
                }
                Auditoria::registrar($u['empresa_id'], $u['id'], 'login_fallido', $bloqueada ? 'cuenta bloqueada' : 'contrasena incorrecta');
            }
            return null;
        }
        Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = ? WHERE id = ?', array(Db::ahora(), $u['id']));
        if (password_needs_rehash($u['clave_hash'], PASSWORD_DEFAULT)) {
            Db::ejecutar('UPDATE usuarios_portal SET clave_hash = ? WHERE id = ?', array(password_hash($clave, PASSWORD_DEFAULT), $u['id']));
        }
        Auditoria::registrar($u['empresa_id'], $u['id'], 'login_ok', '');
        return $u;
    }

    /**
     * Confirmacion de contrasena para acciones sensibles. Los fallos cuentan para el bloqueo (si no, esta pantalla
     * serviria para adivinar la clave de una sesion robada).
     */
    public static function confirmarClave(array $u, $clave)
    {
        if ($u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) {
            return false;
        }
        if (password_verify((string) $clave, $u['clave_hash'])) {
            if ((int) $u['intentos_fallidos'] > 0) {
                Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0 WHERE id = ?', array($u['id']));
            }
            return true;
        }
        $n = (int) $u['intentos_fallidos'] + 1;
        if ($n >= self::FALLOS_PARA_BLOQUEO) {
            Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() + 60 * self::MINUTOS_BLOQUEO), $u['id']));
            Auditoria::registrar($u['empresa_id'], $u['id'], 'cuenta_bloqueada', 'confirmaciones de contrasena fallidas');
        } else {
            Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = ? WHERE id = ?', array($n, $u['id']));
        }
        Auditoria::registrar($u['empresa_id'], $u['id'], 'confirmacion_fallida', '');
        return false;
    }

    public static function cambiarClave(array $u, $nueva)
    {
        Db::ejecutar('UPDATE usuarios_portal SET clave_hash = ?, debe_cambiar = 0 WHERE id = ?', array(password_hash($nueva, PASSWORD_DEFAULT), $u['id']));
        Auditoria::registrar($u['empresa_id'], $u['id'], 'clave_cambiada', '');
    }
}
