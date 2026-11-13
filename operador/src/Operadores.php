<?php
/**
 * Cuentas del operador (dueno del servicio). Son DISTINTAS de las de los clientes: otra tabla, otra cookie, otra carpeta de sesiones.
 * Se crean solo por consola (php bin/admin.php operador:crear). Mismas protecciones que el portal: bcrypt, bloqueo tras 5 fallos,
 * limite por IP, contrasena temporal obligatoria de cambiar.
 */
class Operadores
{
    const HASH_RELLENO = Usuarios::HASH_RELLENO;

    public static function porId($id)
    {
        return Db::uno('SELECT * FROM operadores WHERE id = ?', array((int) $id));
    }

    public static function porEmail($email)
    {
        return Db::uno('SELECT * FROM operadores WHERE email = ?', array(strtolower(trim($email))));
    }

    /** Alta (por consola). Devuelve la contrasena temporal en claro (se muestra una sola vez). */
    public static function crear($email, $nombre)
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new InvalidArgumentException('El correo no es valido.');
        }
        if (self::porEmail($email)) {
            throw new InvalidArgumentException('Ya existe un operador con ese correo.');
        }
        $clave = Usuarios::claveTemporal();
        Db::ejecutar('INSERT INTO operadores (email, nombre, clave_hash, debe_cambiar, activo, creado) VALUES (?,?,?,1,1,?)',
            array($email, substr(trim($nombre), 0, 120), password_hash($clave, PASSWORD_DEFAULT), Db::ahora()));
        return $clave;
    }

    public static function restablecer($email)
    {
        $o = self::porEmail($email);
        if (!$o) {
            throw new InvalidArgumentException('No existe ese operador.');
        }
        $clave = Usuarios::claveTemporal();
        Db::ejecutar('UPDATE operadores SET clave_hash = ?, debe_cambiar = 1, intentos_fallidos = 0, bloqueado_hasta = NULL, activo = 1 WHERE id = ?', array(password_hash($clave, PASSWORD_DEFAULT), $o['id']));
        return $clave;
    }

    private static function contador($clave)
    {
        $r = Db::uno('SELECT n FROM limites WHERE clave = ?', array($clave));
        return $r ? (int) $r['n'] : 0;
    }

    private static function registrar($accion, $detalle)
    {
        Auditoria::registrar(0, null, $accion, $detalle); // empresa 0 = actividad del propio operador
    }

    /** Devuelve la fila del operador o null (mismo resultado ante cualquier fallo: no revela si el correo existe). */
    public static function autenticar($email, $clave, $ip)
    {
        $ventana = (int) floor(time() / 900);
        $claveIp = 'oplogin:ip:' . $ip . ':' . $ventana;
        if (self::contador($claveIp) >= Usuarios::FALLOS_POR_IP) {
            return null;
        }
        $o = self::porEmail($email);
        $ok = password_verify((string) $clave, $o ? $o['clave_hash'] : self::HASH_RELLENO);
        $bloqueada = $o && $o['bloqueado_hasta'] && strtotime($o['bloqueado_hasta']) > time();
        if (!$o || !$ok || $bloqueada || (int) $o['activo'] !== 1) {
            Planes::incrementar($claveIp, ((int) $ventana + 1) * 15); // fin de la ventana, en minutos (ver Usuarios::autenticar)
            if ($o) {
                $n = (int) $o['intentos_fallidos'] + 1;
                if ($n >= Usuarios::FALLOS_PARA_BLOQUEO) {
                    Db::ejecutar('UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() + 60 * Usuarios::MINUTOS_BLOQUEO), $o['id']));
                    self::registrar('op_bloqueado', $o['email']);
                } else {
                    Db::ejecutar('UPDATE operadores SET intentos_fallidos = ? WHERE id = ?', array($n, $o['id']));
                }
            }
            self::registrar('op_login_fallido', $o ? $o['email'] : 'correo desconocido');
            return null;
        }
        Db::ejecutar('UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL, ultimo_acceso = ? WHERE id = ?', array(Db::ahora(), $o['id']));
        if (password_needs_rehash($o['clave_hash'], PASSWORD_DEFAULT)) {
            Db::ejecutar('UPDATE operadores SET clave_hash = ? WHERE id = ?', array(password_hash($clave, PASSWORD_DEFAULT), $o['id']));
        }
        self::registrar('op_login_ok', $o['email']);
        return self::porId($o['id']);
    }

    /** Confirmacion de contrasena para acciones sensibles; los fallos cuentan para el bloqueo. */
    public static function confirmarClave(array $o, $clave)
    {
        if ($o['bloqueado_hasta'] && strtotime($o['bloqueado_hasta']) > time()) {
            return false;
        }
        if (password_verify((string) $clave, $o['clave_hash'])) {
            if ((int) $o['intentos_fallidos'] > 0) {
                Db::ejecutar('UPDATE operadores SET intentos_fallidos = 0 WHERE id = ?', array($o['id']));
            }
            return true;
        }
        $n = (int) $o['intentos_fallidos'] + 1;
        if ($n >= Usuarios::FALLOS_PARA_BLOQUEO) {
            Db::ejecutar('UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() + 60 * Usuarios::MINUTOS_BLOQUEO), $o['id']));
            self::registrar('op_bloqueado', $o['email'] . ' (confirmaciones fallidas)');
        } else {
            Db::ejecutar('UPDATE operadores SET intentos_fallidos = ? WHERE id = ?', array($n, $o['id']));
        }
        self::registrar('op_confirmacion_fallida', $o['email']);
        return false;
    }

    public static function cambiarClave(array $o, $nueva)
    {
        Db::ejecutar('UPDATE operadores SET clave_hash = ?, debe_cambiar = 0 WHERE id = ?', array(password_hash($nueva, PASSWORD_DEFAULT), $o['id']));
        self::registrar('op_clave_cambiada', $o['email']);
    }
}
