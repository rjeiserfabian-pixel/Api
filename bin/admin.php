<?php
/**
 * Administracion de la API (solo linea de comandos).
 *
 *   php bin/admin.php init
 *   php bin/admin.php empresa:crear --ruc=20123456789 --razon="MI EMPRESA SAC" --nombre="MI EMPRESA" \
 *        --direccion="AV. LIMA 123" --ubigeo=150101 --dep=LIMA --prov=LIMA --dist=LIMA \
 *        [--urbanizacion=..] [--cuenta-detraccion=..] [--sol-user=..] [--sol-pass=..] [--gre-id=..] [--gre-secret=..]
 *   php bin/admin.php empresa:actualizar --ruc=20123456789 [--sol-user=..] [--sol-pass=..] [--gre-id=..] [--gre-secret=..] [--razon=..] ...
 *   php bin/admin.php empresa:listar
 *   php bin/admin.php key:crear --ruc=20123456789 --entorno=test|live [--nombre="Mi sistema"]
 *   php bin/admin.php key:listar [--ruc=..]
 *   php bin/admin.php key:revocar --id=3
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth') as $c) {
    require_once $raiz . '/v1/src/' . $c . '.php';
}
date_default_timezone_set('America/Lima');

function salir($msg, $codigo = 1)
{
    fwrite($codigo ? STDERR : STDOUT, $msg . PHP_EOL);
    exit($codigo);
}

function opciones(array $argv)
{
    $o = array();
    foreach (array_slice($argv, 2) as $a) {
        if (preg_match('/^--([a-z\-]+)=(.*)$/s', $a, $m)) {
            $o[$m[1]] = $m[2];
        }
    }
    return $o;
}

function exigir(array $o, array $campos)
{
    foreach ($campos as $c) {
        if (!isset($o[$c]) || $o[$c] === '') {
            salir("Falta --$c");
        }
    }
}

/** Agrega claves al .env si no existen (sin tocar las demas). */
function asegurarEnv()
{
    global $raiz;
    $archivo = $raiz . '/.env';
    $actual = is_file($archivo) ? file_get_contents($archivo) : '';
    $nuevas = array();
    if (!preg_match('/^APP_KEY=.+$/m', $actual)) {
        $nuevas[] = 'APP_KEY=' . base64_encode(random_bytes(32));
    }
    if (!preg_match('/^INTERNAL_TOKEN=.+$/m', $actual)) {
        $nuevas[] = 'INTERNAL_TOKEN=' . bin2hex(random_bytes(24));
    }
    if ($nuevas) {
        $prefijo = ($actual !== '' && substr($actual, -1) !== "\n") ? "\n" : '';
        file_put_contents($archivo, $actual . $prefijo . "\n# --- generado por bin/admin.php init ---\n" . implode("\n", $nuevas) . "\n");
        @chmod($archivo, 0600);
    }
    return $nuevas;
}

$cmd = isset($argv[1]) ? $argv[1] : '';
$o = opciones($argv);

switch ($cmd) {
    case 'init':
        $n = asegurarEnv();
        Db::crearEsquema();
        echo "Base de datos lista. " . ($n ? 'Se generaron en .env: ' . implode(', ', array_map(function ($x) { return strtok($x, '='); }, $n)) : '.env ya tenia APP_KEY e INTERNAL_TOKEN') . PHP_EOL;
        echo "IMPORTANTE: respalde APP_KEY. Si se pierde, no se pueden descifrar las claves SOL guardadas." . PHP_EOL;
        break;

    case 'empresa:crear':
        exigir($o, array('ruc', 'razon', 'nombre', 'direccion', 'ubigeo', 'dep', 'prov', 'dist'));
        if (!preg_match('/^\d{11}$/', $o['ruc'])) {
            salir('El RUC debe tener 11 digitos.');
        }
        if (!preg_match('/^\d{6}$/', $o['ubigeo'])) {
            salir('El ubigeo debe tener 6 digitos.');
        }
        Db::crearEsquema();
        if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($o['ruc']))) {
            salir('Ya existe una empresa con ese RUC. Use empresa:actualizar.');
        }
        Db::ejecutar(
            'INSERT INTO empresas (ruc, razon_social, nombre_comercial, domicilio_fiscal, ubigeo, departamento, provincia, distrito, urbanizacion, cuenta_detraccion, sol_usuario, sol_clave_cifrada, gre_client_id, gre_secret_cifrado, activo, creado)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)',
            array($o['ruc'], $o['razon'], $o['nombre'], $o['direccion'], $o['ubigeo'], $o['dep'], $o['prov'], $o['dist'],
                isset($o['urbanizacion']) ? $o['urbanizacion'] : null, isset($o['cuenta-detraccion']) ? $o['cuenta-detraccion'] : null,
                isset($o['sol-user']) ? $o['sol-user'] : null, isset($o['sol-pass']) ? Crypto::cifrar($o['sol-pass']) : null,
                isset($o['gre-id']) ? $o['gre-id'] : null, isset($o['gre-secret']) ? Crypto::cifrar($o['gre-secret']) : null, Db::ahora())
        );
        echo "Empresa {$o['ruc']} creada." . PHP_EOL;
        break;

    case 'empresa:actualizar':
        exigir($o, array('ruc'));
        $e = Db::uno('SELECT * FROM empresas WHERE ruc = ?', array($o['ruc']));
        if (!$e) {
            salir('No existe esa empresa.');
        }
        $mapa = array('razon' => 'razon_social', 'nombre' => 'nombre_comercial', 'direccion' => 'domicilio_fiscal', 'ubigeo' => 'ubigeo',
            'dep' => 'departamento', 'prov' => 'provincia', 'dist' => 'distrito', 'urbanizacion' => 'urbanizacion',
            'cuenta-detraccion' => 'cuenta_detraccion', 'sol-user' => 'sol_usuario', 'gre-id' => 'gre_client_id');
        $sets = array();
        $vals = array();
        foreach ($mapa as $opt => $col) {
            if (isset($o[$opt])) {
                $sets[] = "$col = ?";
                $vals[] = $o[$opt];
            }
        }
        if (isset($o['sol-pass'])) {
            $sets[] = 'sol_clave_cifrada = ?';
            $vals[] = Crypto::cifrar($o['sol-pass']);
        }
        if (isset($o['gre-secret'])) {
            $sets[] = 'gre_secret_cifrado = ?';
            $vals[] = Crypto::cifrar($o['gre-secret']);
        }
        if (isset($o['activo'])) {
            $sets[] = 'activo = ?';
            $vals[] = $o['activo'] === '0' ? 0 : 1;
        }
        if (!$sets) {
            salir('Nada que actualizar.');
        }
        $vals[] = $e['id'];
        Db::ejecutar('UPDATE empresas SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
        echo "Empresa {$o['ruc']} actualizada." . PHP_EOL;
        break;

    case 'empresa:listar':
        foreach (Db::consultar('SELECT * FROM empresas ORDER BY id') as $e) {
            printf("%-3d %s  %-40s SOL:%s GRE:%s %s\n", $e['id'], $e['ruc'], $e['razon_social'], $e['sol_usuario'] ? 'si' : 'no', $e['gre_client_id'] ? 'si' : 'no', $e['activo'] ? '' : '[INACTIVA]');
        }
        break;

    case 'key:crear':
        exigir($o, array('ruc', 'entorno'));
        if (!in_array($o['entorno'], array('test', 'live'), true)) {
            salir('--entorno debe ser test o live.');
        }
        $e = Db::uno('SELECT * FROM empresas WHERE ruc = ?', array($o['ruc']));
        if (!$e) {
            salir('No existe esa empresa. Cree primero con empresa:crear.');
        }
        $key = Auth::generarKey($o['entorno']);
        Db::ejecutar('INSERT INTO api_keys (empresa_id, nombre, prefijo, hash, entorno, activo, creado) VALUES (?,?,?,?,?,1,?)',
            array($e['id'], isset($o['nombre']) ? $o['nombre'] : 'sin nombre', substr($key, 0, 12), Auth::hash($key), $o['entorno'], Db::ahora()));
        echo "API key creada (" . ($o['entorno'] === 'live' ? 'PRODUCCION' : 'prueba/beta') . "). Guardela ahora; no se vuelve a mostrar:" . PHP_EOL . PHP_EOL . "  $key" . PHP_EOL . PHP_EOL;
        break;

    case 'key:listar':
        $sql = 'SELECT k.*, e.ruc FROM api_keys k JOIN empresas e ON e.id = k.empresa_id' . (isset($o['ruc']) ? ' WHERE e.ruc = ?' : '') . ' ORDER BY k.id';
        foreach (Db::consultar($sql, isset($o['ruc']) ? array($o['ruc']) : array()) as $k) {
            printf("%-3d %s  %-4s %s...  %-20s ultimo uso: %s %s\n", $k['id'], $k['ruc'], $k['entorno'], $k['prefijo'], $k['nombre'], $k['ultimo_uso'] ?: 'nunca', $k['activo'] ? '' : '[REVOCADA]');
        }
        break;

    case 'key:revocar':
        exigir($o, array('id'));
        Db::ejecutar('UPDATE api_keys SET activo = 0 WHERE id = ?', array((int) $o['id']));
        echo "API key {$o['id']} revocada." . PHP_EOL;
        break;

    default:
        echo "Comandos: init | empresa:crear | empresa:actualizar | empresa:listar | key:crear | key:listar | key:revocar" . PHP_EOL
            . "Vea el encabezado de bin/admin.php para las opciones." . PHP_EOL;
}
