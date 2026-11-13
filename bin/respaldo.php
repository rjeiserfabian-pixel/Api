<?php
/**
 * Respaldos del sistema (solo linea de comandos).
 *
 *   php bin/respaldo.php crear      [--dir=RUTA] [--sin-archivos] [--retener=14] [--clave=FRASE]
 *   php bin/respaldo.php listar     [--dir=RUTA]
 *   php bin/respaldo.php verificar  [--desde=CARPETA] [--dir=RUTA] [--clave=FRASE]
 *   php bin/respaldo.php restaurar  --desde=CARPETA --destino=CARPETA_NUEVA [--clave=FRASE]
 *
 * Que respalda (cada respaldo es una carpeta AAAAMMDD-HHMMSS):
 *   - base de datos: SQLite (copia consistente, con el bloqueo de escritura tomado) o PostgreSQL/MySQL (pg_dump / mysqldump);
 *   - .env (contiene APP_KEY: SIN ella no se pueden descifrar las claves SOL ni los certificados guardados). Con --clave se
 *     guarda CIFRADO (AES-256-GCM); sin --clave queda en claro: guardelo en un lugar aparte y protegido;
 *   - los comprobantes (XML, CDR) de files/facturacion_electronica, en un .zip (se conservan por obligacion tributaria);
 *   - los LOGOS de las empresas (storage/logos) en logos.zip, para que los PDF de los clientes no pierdan su imagen;
 *   - manifiesto.json con huellas SHA-256 y conteos, para poder comprobar que el respaldo esta sano.
 *
 * "restaurar" NUNCA pisa el sistema en uso: deja todo en una carpeta nueva para que usted lo coloque (ver docs/DESPLIEGUE.md).
 * Carpeta por defecto: RESPALDO_DIR del .env o storage/respaldos (guarde copias FUERA del servidor).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
require_once $raiz . '/v1/src/Db.php';
date_default_timezone_set('America/Lima');
error_reporting(E_ALL);
ini_set('display_errors', '1');

function salir($m, $codigo = 1)
{
    fwrite(STDERR, $m . PHP_EOL);
    exit($codigo);
}

function opciones(array $argv)
{
    $o = array();
    foreach (array_slice($argv, 2) as $a) {
        if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/s', $a, $m)) {
            $o[$m[1]] = isset($m[2]) ? $m[2] : '1';
        }
    }
    return $o;
}

function carpetaRespaldos(array $o)
{
    global $raiz;
    $d = isset($o['dir']) ? $o['dir'] : api_env('RESPALDO_DIR', $raiz . '/storage/respaldos');
    return rtrim(str_replace('\\', '/', $d), '/');
}

function crearCarpeta($d)
{
    if (!is_dir($d) && !@mkdir($d, 0700, true)) {
        salir("No se pudo crear la carpeta $d");
    }
}

function tamano($bytes)
{
    $u = array('B', 'KB', 'MB', 'GB');
    $i = 0;
    while ($bytes >= 1024 && $i < 3) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i ? 1 : 0) . ' ' . $u[$i];
}

// ---------------------------------------------------------------- cifrado del .env (opcional)
function cifrarConClave($texto, $clave)
{
    $sal = random_bytes(16);
    $iv = random_bytes(12);
    $llave = hash_pbkdf2('sha256', $clave, $sal, 100000, 32, true);
    $c = openssl_encrypt($texto, 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $iv, $tag);
    return 'RESP1' . $sal . $iv . $tag . $c;
}

function descifrarConClave($bin, $clave)
{
    if (substr($bin, 0, 5) !== 'RESP1' || strlen($bin) < 49) {
        return false;
    }
    $sal = substr($bin, 5, 16);
    $iv = substr($bin, 21, 12);
    $tag = substr($bin, 33, 16);
    $llave = hash_pbkdf2('sha256', $clave, $sal, 100000, 32, true);
    return openssl_decrypt(substr($bin, 49), 'aes-256-gcm', $llave, OPENSSL_RAW_DATA, $iv, $tag);
}

// ---------------------------------------------------------------- base de datos
function conteos(PDO $pdo)
{
    $tablas = array('empresas', 'api_keys', 'certificados', 'series', 'documentos', 'webhooks', 'planes', 'usuarios_portal', 'suscripciones', 'pagos');
    $c = array();
    foreach ($tablas as $t) {
        try {
            $c[$t] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
        } catch (Exception $e) {
            $c[$t] = null; // tabla que aun no existe en esa version
        }
    }
    return $c;
}

function programa($nombres)
{
    foreach ((array) $nombres as $n) {
        $r = trim((string) shell_exec((stripos(PHP_OS, 'WIN') === 0 ? 'where ' : 'command -v ') . escapeshellarg($n) . ' 2>' . (stripos(PHP_OS, 'WIN') === 0 ? 'NUL' : '/dev/null')));
        if ($r !== '') {
            return strtok($r, "\r\n");
        }
    }
    return null;
}

function volcarBase($destinoDir)
{
    global $raiz;
    $dsn = (string) api_env('DB_DSN', '');
    if ($dsn === '' || strpos($dsn, 'sqlite') === 0) {
        $pdo = Db::pdo();
        $archivo = $dsn === '' ? $raiz . '/storage/api.sqlite' : substr($dsn, 7);
        if (!$archivo || !is_file($archivo)) {
            salir('No se encontro el archivo de la base SQLite.');
        }
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pdo->exec('BEGIN IMMEDIATE'); // nadie escribe mientras se copia: copia consistente
        $ok = copy($archivo, $destinoDir . '/base.sqlite');
        $pdo->exec('ROLLBACK');
        if (!$ok) {
            salir('No se pudo copiar la base de datos.');
        }
        $copia = new PDO('sqlite:' . $destinoDir . '/base.sqlite');
        $integridad = $copia->query('PRAGMA integrity_check')->fetchColumn();
        if ($integridad !== 'ok') {
            salir('La copia de la base no paso la comprobacion de integridad: ' . $integridad);
        }
        return array('archivo' => 'base.sqlite', 'motor' => 'sqlite', 'conteos' => conteos($copia));
    }
    if (preg_match('/^pgsql:/', $dsn)) {
        parse_str(str_replace(';', '&', substr($dsn, 6)), $p);
        $bin = api_env('PG_DUMP_PATH') ?: programa(array('pg_dump', 'pg_dump.exe'));
        if (!$bin) {
            salir('No se encontro pg_dump. Instale el cliente de PostgreSQL o defina PG_DUMP_PATH en .env.');
        }
        $cmd = escapeshellarg($bin) . ' --no-owner --format=plain'
            . (isset($p['host']) ? ' -h ' . escapeshellarg($p['host']) : '') . (isset($p['port']) ? ' -p ' . escapeshellarg($p['port']) : '')
            . ' -U ' . escapeshellarg((string) api_env('DB_USER', '')) . ' -f ' . escapeshellarg($destinoDir . '/base.sql') . ' ' . escapeshellarg(isset($p['dbname']) ? $p['dbname'] : '');
        putenv('PGPASSWORD=' . api_env('DB_PASS', ''));
        exec($cmd . ' 2>&1', $salida, $codigo);
        putenv('PGPASSWORD');
        if ($codigo !== 0 || !is_file($destinoDir . '/base.sql') || filesize($destinoDir . '/base.sql') < 100) {
            salir('pg_dump fallo: ' . implode(' ', $salida));
        }
        return array('archivo' => 'base.sql', 'motor' => 'pgsql', 'conteos' => conteos(Db::pdo()));
    }
    if (preg_match('/^mysql:/', $dsn)) {
        parse_str(str_replace(';', '&', substr($dsn, 6)), $p);
        $bin = programa(array('mysqldump', 'mysqldump.exe', 'mariadb-dump'));
        if (!$bin) {
            salir('No se encontro mysqldump.');
        }
        $cmd = escapeshellarg($bin) . ' --single-transaction' . (isset($p['host']) ? ' -h ' . escapeshellarg($p['host']) : '') . (isset($p['port']) ? ' -P ' . escapeshellarg($p['port']) : '')
            . ' -u ' . escapeshellarg((string) api_env('DB_USER', '')) . ' --password=' . escapeshellarg((string) api_env('DB_PASS', '')) . ' ' . escapeshellarg(isset($p['dbname']) ? $p['dbname'] : '') . ' > ' . escapeshellarg($destinoDir . '/base.sql');
        exec($cmd . ' 2>&1', $salida, $codigo);
        if ($codigo !== 0 || !is_file($destinoDir . '/base.sql') || filesize($destinoDir . '/base.sql') < 100) {
            salir('mysqldump fallo: ' . implode(' ', $salida));
        }
        return array('archivo' => 'base.sql', 'motor' => 'mysql', 'conteos' => conteos(Db::pdo()));
    }
    salir('DB_DSN no reconocido.');
}

// ---------------------------------------------------------------- archivos (comprobantes)
function comprimirArchivos($carpeta, $zipRuta, &$n)
{
    if (!class_exists('ZipArchive')) {
        salir('Falta la extension zip de PHP. Use --sin-archivos o instale php-zip.');
    }
    $zip = new ZipArchive();
    if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        salir('No se pudo crear el .zip de comprobantes.');
    }
    $n = 0;
    if (is_dir($carpeta)) {
        $base = strlen(str_replace('\\', '/', dirname($carpeta))) + 1;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && !preg_match('#/(qr|dummy)(/|$)#', str_replace('\\', '/', $f->getPathname()))) {
                $zip->addFile($f->getPathname(), substr(str_replace('\\', '/', $f->getPathname()), $base));
                $n++;
            }
        }
    }
    $zip->close();
}

/** Comprime los logos de las empresas (storage/logos) en un .zip. Devuelve cuantos archivos incluyo. */
function comprimirLogos($carpeta, $zipRuta)
{
    $n = 0;
    $zip = new ZipArchive();
    if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        salir('No se pudo crear el .zip de logos.');
    }
    foreach (is_dir($carpeta) ? scandir($carpeta) : array() as $f) {
        if (preg_match('/^\d+\.png$/', $f) && is_file($carpeta . '/' . $f)) {
            $zip->addFile($carpeta . '/' . $f, 'logos/' . $f);
            $n++;
        }
    }
    $zip->close();
    if ($n === 0) {
        @unlink($zipRuta); // nada que respaldar
    }
    return $n;
}

function manifestoDe($dir)
{
    $m = json_decode((string) @file_get_contents($dir . '/manifiesto.json'), true);
    return is_array($m) ? $m : null;
}

function listaRespaldos($base)
{
    $r = array();
    foreach (is_dir($base) ? scandir($base) : array() as $d) {
        if (preg_match('/^\d{8}-\d{6}$/', $d) && is_file($base . '/' . $d . '/manifiesto.json')) {
            $r[] = $d;
        }
    }
    sort($r);
    return $r;
}

// ---------------------------------------------------------------- verificacion
function verificarRespaldo($dir, $clave, $imprimir = true)
{
    $m = manifestoDe($dir);
    $fallas = array();
    if (!$m) {
        return array('No hay manifiesto.json (no es una carpeta de respaldo).');
    }
    foreach ($m['archivos'] as $nombre => $info) {
        $ruta = $dir . '/' . $nombre;
        if (!is_file($ruta)) {
            $fallas[] = "Falta el archivo $nombre";
            continue;
        }
        if (hash_file('sha256', $ruta) !== $info['sha256']) {
            $fallas[] = "$nombre esta alterado o danado (la huella SHA-256 no coincide)";
        }
    }
    if (!$fallas && $m['base']['motor'] === 'sqlite') {
        try {
            $pdo = new PDO('sqlite:' . $dir . '/base.sqlite');
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                $fallas[] = 'La base SQLite del respaldo no pasa la comprobacion de integridad';
            }
            foreach (conteos($pdo) as $t => $n) {
                if (array_key_exists($t, $m['base']['conteos']) && $m['base']['conteos'][$t] !== $n) {
                    $fallas[] = "La tabla $t tiene $n filas y el manifiesto dice " . $m['base']['conteos'][$t];
                }
            }
        } catch (Exception $e) {
            $fallas[] = 'No se pudo abrir la base del respaldo: ' . $e->getMessage();
        }
    }
    if (!$fallas && isset($m['archivos']['env.enc'])) {
        if ($clave === null) {
            $fallas[] = 'El .env esta cifrado: indique --clave para comprobarlo';
        } elseif (descifrarConClave(file_get_contents($dir . '/env.enc'), $clave) === false) {
            $fallas[] = 'La clave no descifra el .env (clave incorrecta o archivo danado)';
        }
    }
    if (!$fallas && isset($m['archivos']['logos.zip']) && class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($dir . '/logos.zip') !== true) {
            $fallas[] = 'El .zip de logos no se puede abrir';
        } elseif (isset($m['logos']) && $z->numFiles !== (int) $m['logos']) {
            $fallas[] = 'El .zip de logos tiene ' . $z->numFiles . ' archivos y el manifiesto dice ' . $m['logos'];
        }
        $z->close();
    }
    if (!$fallas && isset($m['archivos']['archivos.zip']) && class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($dir . '/archivos.zip') !== true) {
            $fallas[] = 'El .zip de comprobantes no se puede abrir';
        } elseif ($z->numFiles !== (int) $m['comprobantes']) {
            $fallas[] = 'El .zip tiene ' . $z->numFiles . ' archivos y el manifiesto dice ' . $m['comprobantes'];
        }
        $z->close();
    }
    return $fallas;
}

$cmd = isset($argv[1]) ? $argv[1] : '';
$o = opciones($argv);
$clave = isset($o['clave']) && $o['clave'] !== '1' ? $o['clave'] : null;

switch ($cmd) {
    case 'crear':
        $base = carpetaRespaldos($o);
        crearCarpeta($base);
        $nombre = date('Ymd-His');
        $dir = $base . '/' . $nombre;
        crearCarpeta($dir);
        echo "Respaldo $nombre en $base" . PHP_EOL;
        $m = array('creado' => date('c'), 'version_php' => PHP_VERSION, 'host' => php_uname('n'), 'archivos' => array());
        $m['base'] = volcarBase($dir);
        echo '  base de datos (' . $m['base']['motor'] . '): ' . tamano(filesize($dir . '/' . $m['base']['archivo'])) . PHP_EOL;
        $envTxt = is_file($raiz . '/.env') ? file_get_contents($raiz . '/.env') : '';
        if ($clave !== null) {
            file_put_contents($dir . '/env.enc', cifrarConClave($envTxt, $clave));
            @chmod($dir . '/env.enc', 0600);
            echo '  .env: guardado CIFRADO (env.enc). Guarde la clave aparte: sin ella no se puede abrir.' . PHP_EOL;
        } else {
            file_put_contents($dir . '/env.txt', $envTxt);
            @chmod($dir . '/env.txt', 0600);
            echo '  .env: guardado en CLARO (env.txt) porque no se indico --clave. Contiene APP_KEY: no deje este respaldo en un lugar compartido.' . PHP_EOL;
        }
        $m['comprobantes'] = 0;
        if (!isset($o['sin-archivos'])) {
            comprimirArchivos($raiz . '/files/facturacion_electronica', $dir . '/archivos.zip', $m['comprobantes']);
            echo '  comprobantes (XML/CDR/PDF): ' . $m['comprobantes'] . ' archivos, ' . tamano(filesize($dir . '/archivos.zip')) . PHP_EOL;
        } else {
            echo '  comprobantes: OMITIDOS (--sin-archivos). Recuerde respaldarlos aparte.' . PHP_EOL;
        }
        $m['logos'] = class_exists('ZipArchive') ? comprimirLogos($raiz . '/storage/logos', $dir . '/logos.zip') : 0;
        if ($m['logos'] > 0) {
            echo '  logos de las empresas: ' . $m['logos'] . ' archivos' . PHP_EOL;
        }
        foreach (scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..' && $f !== 'manifiesto.json') {
                $m['archivos'][$f] = array('bytes' => filesize($dir . '/' . $f), 'sha256' => hash_file('sha256', $dir . '/' . $f));
            }
        }
        file_put_contents($dir . '/manifiesto.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $fallas = verificarRespaldo($dir, $clave);
        if ($fallas) {
            salir('El respaldo recien creado NO paso la verificacion: ' . implode('; ', $fallas));
        }
        echo '  verificado: OK' . PHP_EOL;
        if (isset($o['retener']) && ctype_digit($o['retener']) && (int) $o['retener'] > 0) {
            $todos = listaRespaldos($base);
            $sobran = array_slice($todos, 0, max(0, count($todos) - (int) $o['retener']));
            foreach ($sobran as $d) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . '/' . $d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($base . '/' . $d);
                echo "  retencion: se borro el respaldo antiguo $d" . PHP_EOL;
            }
        }
        echo 'Listo.' . PHP_EOL;
        break;

    case 'listar':
        $base = carpetaRespaldos($o);
        $todos = listaRespaldos($base);
        if (!$todos) {
            echo "No hay respaldos en $base" . PHP_EOL;
        }
        foreach ($todos as $d) {
            $m = manifestoDe($base . '/' . $d);
            $bytes = 0;
            foreach ($m['archivos'] as $a) {
                $bytes += $a['bytes'];
            }
            printf("%s  %-8s  %-10s  %s%s\n", $d, $m['base']['motor'], tamano($bytes), $m['comprobantes'] . ' comprobantes', isset($m['archivos']['env.enc']) ? '  .env cifrado' : '');
        }
        break;

    case 'verificar':
        $dir = isset($o['desde']) ? rtrim(str_replace('\\', '/', $o['desde']), '/') : null;
        if ($dir === null) {
            $todos = listaRespaldos(carpetaRespaldos($o));
            if (!$todos) {
                salir('No hay respaldos que verificar.');
            }
            $dir = carpetaRespaldos($o) . '/' . end($todos);
        }
        $fallas = verificarRespaldo($dir, $clave);
        if ($fallas) {
            foreach ($fallas as $f) {
                echo '  [FALLA] ' . $f . PHP_EOL;
            }
            exit(1);
        }
        echo "Respaldo " . basename($dir) . ": integro (huellas, base de datos y conteos coinciden)." . PHP_EOL;
        break;

    case 'restaurar':
        if (!isset($o['desde']) || !isset($o['destino'])) {
            salir('Use: php bin/respaldo.php restaurar --desde=CARPETA_DEL_RESPALDO --destino=CARPETA_NUEVA [--clave=FRASE]');
        }
        $desde = rtrim(str_replace('\\', '/', $o['desde']), '/');
        $destino = rtrim(str_replace('\\', '/', $o['destino']), '/');
        if (is_dir($destino) && count(array_diff(scandir($destino), array('.', '..'))) > 0) {
            salir('La carpeta destino no esta vacia. Use una carpeta nueva: este comando nunca pisa archivos existentes.');
        }
        if (realpath($destino) !== false && realpath($destino) === realpath($raiz)) {
            salir('El destino no puede ser la carpeta del sistema en uso.');
        }
        $fallas = verificarRespaldo($desde, $clave);
        if ($fallas) {
            salir('El respaldo no esta sano: ' . implode('; ', $fallas));
        }
        crearCarpeta($destino);
        $m = manifestoDe($desde);
        copy($desde . '/' . $m['base']['archivo'], $destino . '/' . $m['base']['archivo']);
        if (isset($m['archivos']['env.enc'])) {
            file_put_contents($destino . '/.env', descifrarConClave(file_get_contents($desde . '/env.enc'), $clave));
        } elseif (isset($m['archivos']['env.txt'])) {
            copy($desde . '/env.txt', $destino . '/.env');
        }
        @chmod($destino . '/.env', 0600);
        if (isset($m['archivos']['archivos.zip'])) {
            $z = new ZipArchive();
            $z->open($desde . '/archivos.zip');
            $z->extractTo($destino . '/files');
            $z->close();
        }
        if (isset($m['archivos']['logos.zip'])) {
            $z = new ZipArchive();
            $z->open($desde . '/logos.zip');
            $z->extractTo($destino . '/storage');
            $z->close();
        }
        echo "Restaurado en $destino:" . PHP_EOL . (isset($m['archivos']['logos.zip']) ? '  storage/logos/  (' . $m['logos'] . ' logos)' . PHP_EOL : '') . '  ' . $m['base']['archivo'] . '  (base de datos)' . PHP_EOL . '  .env' . PHP_EOL . ($m['comprobantes'] ? '  files/  (' . $m['comprobantes'] . ' comprobantes)' . PHP_EOL : '');
        echo 'Para ponerlo en servicio: ver docs/DESPLIEGUE.md, seccion "Restaurar".' . PHP_EOL;
        break;

    default:
        echo "Uso: php bin/respaldo.php crear|listar|verificar|restaurar [opciones]  (ver el comentario del archivo)" . PHP_EOL;
        exit($cmd === '' ? 0 : 1);
}
