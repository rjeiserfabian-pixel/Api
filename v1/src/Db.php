<?php
/**
 * Acceso a base de datos (PDO). Por defecto SQLite en storage/api.sqlite.
 * Motores soportados (se elige con DB_DSN en .env):
 *   SQLite     (por defecto)
 *   PostgreSQL DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=api_sunat   DB_USER=...  DB_PASS=...     <- recomendado en produccion
 *   MySQL      DB_DSN=mysql:host=127.0.0.1;dbname=api_sunat;charset=utf8mb4   DB_USER=...  DB_PASS=...
 * Para llevar los datos de SQLite a otro motor:  php bin/admin.php db:copiar --dsn=... --user=... --pass=...
 */
class Db
{
    private static $pdo;

    /** Usa otra conexion (la herramienta db:copiar). */
    public static function conectar($dsn, $usuario = null, $clave = null)
    {
        self::$pdo = null;
        $raiz = dirname(__DIR__, 2);
        $pdo = new PDO($dsn, $usuario, $clave, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
        self::$pdo = $pdo;
        return $pdo;
    }

    /** 'sqlite', 'pgsql' o 'mysql'. */
    public static function motor()
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

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
            if (self::$pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                // Varias peticiones a la vez: WAL permite leer mientras otra escribe, y busy_timeout hace que esperen su turno
                self::$pdo->exec('PRAGMA busy_timeout = 20000');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
                self::$pdo->exec('PRAGMA synchronous = NORMAL');
            }
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

    /**
     * Ejecuta $fn dentro de una transaccion de ESCRITURA. En SQLite usa BEGIN IMMEDIATE: toma el bloqueo de escritura al
     * empezar y espera su turno (un BEGIN normal que luego intenta escribir falla al instante con "database is locked").
     */
    public static function transaccion($fn)
    {
        $pdo = self::pdo();
        $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
        if ($sqlite) {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $pdo->beginTransaction();
        }
        try {
            $r = $fn();
            if ($sqlite) {
                $pdo->exec('COMMIT');
            } else {
                $pdo->commit();
            }
            return $r;
        } catch (Exception $x) {
            try {
                if ($sqlite) {
                    $pdo->exec('ROLLBACK');
                } elseif ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Exception $y) {
                // ya estaba cerrada
            }
            throw $x;
        }
    }

    /** Ejecuta y devuelve cuantas filas se modificaron (para tomar un recurso de forma atomica). */
    public static function afectadas($sql, array $params = array())
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function ejecutar($sql, array $params = array())
    {
        $pg = self::motor() === 'pgsql';
        $esInsert = stripos(ltrim($sql), 'INSERT') === 0;
        if ($pg && $esInsert && stripos($sql, 'RETURNING') === false) {
            $sql .= ' RETURNING id'; // todas las tablas tienen "id"
        }
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        if ($pg) {
            return $esInsert ? $st->fetchColumn() : null;
        }
        return self::pdo()->lastInsertId();
    }

    /** Ejecuta un CREATE INDEX ignorando el error si el indice ya existe (SQLite y MySQL). */
    private static function indice($sql)
    {
        if (in_array(self::motor(), array('sqlite', 'pgsql'), true)) {
            $sql = preg_replace('/^CREATE (UNIQUE )?INDEX /', 'CREATE $1INDEX IF NOT EXISTS ', $sql);
        }
        try {
            self::pdo()->exec($sql);
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate key name') === false && stripos($msg, 'already exists') === false) {
                throw $e;
            }
        }
    }

    private static function columnaExiste($tabla, $columna)
    {
        $pdo = self::pdo();
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            return (bool) self::consultar('SHOW COLUMNS FROM ' . $tabla . ' LIKE ?', array($columna));
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            return (bool) self::consultar('SELECT 1 FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?', array($tabla, $columna));
        }
        foreach (self::consultar('PRAGMA table_info(' . $tabla . ')') as $c) {
            if ($c['name'] === $columna) {
                return true;
            }
        }
        return false;
    }

    /** Cambios de esquema sobre bases ya existentes (idempotente). */
    public static function migrar()
    {
        if (!self::columnaExiste('documentos', 'numero_n')) {
            self::pdo()->exec('ALTER TABLE documentos ADD COLUMN numero_n INTEGER NULL');
        }
        // numero_n = el numero como entero (150 y 00000150 son el mismo documento)
        foreach (self::consultar('SELECT id, numero FROM documentos WHERE numero_n IS NULL AND numero IS NOT NULL') as $r) {
            if (preg_match('/^\d{1,8}$/', $r['numero'])) {
                self::ejecutar('UPDATE documentos SET numero_n = ? WHERE id = ?', array((int) $r['numero'], $r['id']));
            }
        }
        self::indice('CREATE INDEX idx_doc_numero ON documentos (empresa_id, tipo, serie, numero_n)');
    }

    /** Si falta el esquema nuevo (despues de actualizar el codigo), lo crea. Barato: 2 consultas. */
    public static function asegurarEsquema()
    {
        try {
            self::pdo()->query('SELECT numero_n FROM documentos LIMIT 1');
            self::pdo()->query('SELECT 1 FROM series LIMIT 1');
        } catch (PDOException $e) {
            self::crearEsquema();
        }
    }

    /** Crea las tablas si no existen (compatible con SQLite y MySQL). */
    public static function crearEsquema()
    {
        $pdo = self::pdo();
        $motor = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $pks = array('mysql' => 'INT AUTO_INCREMENT PRIMARY KEY', 'pgsql' => 'SERIAL PRIMARY KEY', 'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT');
        $pk = isset($pks[$motor]) ? $pks[$motor] : $pks['sqlite'];
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

        $pdo->exec("CREATE TABLE IF NOT EXISTS certificados (
            id $pk,
            empresa_id INTEGER NOT NULL,
            entorno VARCHAR(4) NOT NULL,
            cert_pem $txt NOT NULL,
            key_cifrada $txt NOT NULL,
            sujeto VARCHAR(500) NULL,
            serie_cert VARCHAR(80) NULL,
            huella VARCHAR(64) NULL,
            valido_desde VARCHAR(19) NULL,
            valido_hasta VARCHAR(19) NULL,
            creado VARCHAR(19) NOT NULL
        )");
        self::indice('CREATE UNIQUE INDEX idx_cert_empresa ON certificados (empresa_id, entorno)');

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

        self::indice('CREATE INDEX idx_doc_empresa ON documentos (empresa_id, tipo, serie, numero)');
        self::indice('CREATE UNIQUE INDEX idx_doc_idem ON documentos (empresa_id, idem_key)');

        // Numeracion: una fila por serie (o por dia, para resumenes/bajas/reversiones)
        $pdo->exec("CREATE TABLE IF NOT EXISTS series (
            id $pk,
            empresa_id INTEGER NOT NULL,
            entorno VARCHAR(4) NOT NULL,
            tipo VARCHAR(4) NOT NULL,
            serie VARCHAR(16) NOT NULL,
            modo VARCHAR(8) NOT NULL DEFAULT 'cliente',
            ultimo_numero INTEGER NOT NULL DEFAULT 0,
            creado VARCHAR(19) NOT NULL
        )");
        self::indice('CREATE UNIQUE INDEX idx_serie ON series (empresa_id, entorno, tipo, serie)');

        self::migrar();
    }
}
