<?php
/**
 * Acceso a base de datos (PDO). Por defecto SQLite en storage/api.sqlite.
 * Para MySQL definir en .env:  DB_DSN=mysql:host=127.0.0.1;dbname=api_sunat;charset=utf8mb4  DB_USER=...  DB_PASS=...
 */
class Db
{
    private static $pdo;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $raiz = dirname(__DIR__, 2);
            $dsn = api_env('DB_DSN', 'sqlite:' . $raiz . '/storage/api.sqlite');
            if (strpos($dsn, 'sqlite:') === 0 && !is_dir($raiz . '/storage')) {
                @mkdir($raiz . '/storage', 0755, true);
            }
            self::$pdo = new PDO($dsn, api_env('DB_USER'), api_env('DB_PASS'), array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ));
        }
        return self::$pdo;
    }

    public static function ahora()
    {
        return date('Y-m-d H:i:s');
    }

    public static function consultar($sql, array $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function uno($sql, array $params = array())
    {
        $r = self::consultar($sql, $params);
        return $r ? $r[0] : null;
    }

    public static function ejecutar($sql, array $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return self::pdo()->lastInsertId();
    }

    /** Crea las tablas si no existen (compatible con SQLite y MySQL). */
    public static function crearEsquema()
    {
        $pdo = self::pdo();
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $txt = 'TEXT';

        $pdo->exec("CREATE TABLE IF NOT EXISTS empresas (
            id $pk,
            ruc VARCHAR(11) NOT NULL UNIQUE,
            razon_social VARCHAR(255) NOT NULL,
            nombre_comercial VARCHAR(255) NOT NULL,
            domicilio_fiscal VARCHAR(255) NOT NULL,
            ubigeo VARCHAR(6) NOT NULL,
            departamento VARCHAR(80) NOT NULL,
            provincia VARCHAR(80) NOT NULL,
            distrito VARCHAR(80) NOT NULL,
            urbanizacion VARCHAR(120) NULL,
            cuenta_detraccion VARCHAR(30) NULL,
            sol_usuario VARCHAR(30) NULL,
            sol_clave_cifrada $txt NULL,
            gre_client_id VARCHAR(80) NULL,
            gre_secret_cifrado $txt NULL,
            activo INTEGER NOT NULL DEFAULT 1,
            creado VARCHAR(19) NOT NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
            id $pk,
            empresa_id INTEGER NOT NULL,
            nombre VARCHAR(80) NOT NULL,
            prefijo VARCHAR(20) NOT NULL,
            hash VARCHAR(64) NOT NULL UNIQUE,
            entorno VARCHAR(4) NOT NULL,
            activo INTEGER NOT NULL DEFAULT 1,
            creado VARCHAR(19) NOT NULL,
            ultimo_uso VARCHAR(19) NULL
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS documentos (
            id $pk,
            uid VARCHAR(40) NOT NULL UNIQUE,
            empresa_id INTEGER NOT NULL,
            tipo VARCHAR(4) NOT NULL,
            serie VARCHAR(16) NULL,
            numero VARCHAR(24) NULL,
            entorno VARCHAR(4) NOT NULL,
            estado VARCHAR(12) NOT NULL,
            codigo_sunat VARCHAR(60) NULL,
            mensaje_sunat $txt NULL,
            ticket VARCHAR(80) NULL,
            hash_firma VARCHAR(80) NULL,
            ruta_xml VARCHAR(255) NULL,
            ruta_cdr VARCHAR(255) NULL,
            ruta_pdf VARCHAR(255) NULL,
            idem_key VARCHAR(64) NULL,
            idem_hash VARCHAR(64) NULL,
            respuesta $txt NULL,
            creado VARCHAR(19) NOT NULL
        )");

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_doc_empresa ON documentos (empresa_id, tipo, serie, numero)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_doc_idem ON documentos (empresa_id, idem_key)');
    }
}
