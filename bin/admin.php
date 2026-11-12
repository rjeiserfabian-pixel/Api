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
 *   php bin/admin.php cert:subir --ruc=20123456789 --entorno=test|live (--pfx=archivo.pfx --pass=clave | --cert=server.pem --key=server_key.pem [--pass=clave_llave])
 *   php bin/admin.php cert:ver [--ruc=20123456789]
 *   php bin/admin.php serie:crear --ruc=20123456789 --entorno=test|live --tipo=03 --serie=B001 [--modo=cliente|api] [--ultimo=150]
 *   php bin/admin.php serie:listar [--ruc=20123456789] [--entorno=test|live]
 *   php bin/admin.php plan:sembrar                      # crea los 4 planes recomendados (Prueba, Basico, Pro, Empresa)
 *   php bin/admin.php plan:crear --nombre=Basico [--cuota=500|ilimitada|0] [--sunat-min=20|ilimitado] [--general-min=120|ilimitado] [--webhooks=2]
 *   php bin/admin.php plan:listar
 *   php bin/admin.php empresa:plan --ruc=20123456789 --plan=Basico|ninguno
 *   php bin/admin.php uso:ver [--ruc=20123456789]       # consumo del mes (solo llaves live)
 *   php bin/admin.php usuario:crear --ruc=20123456789 --email=cliente@empresa.com --nombre="Nombre" [--rol=admin|lector]   # acceso al portal; muestra una clave TEMPORAL
 *   php bin/admin.php usuario:listar [--ruc=...] | usuario:reset --email=... | usuario:desactivar --email=... | usuario:activar --email=...
 *   php bin/admin.php db:copiar --dsn="pgsql:host=127.0.0.1;port=5432;dbname=api_sunat" --user=... --pass=...
 *        Copia TODOS los datos de la base actual (la de DB_DSN o SQLite) a otra base VACIA (ej. PostgreSQL).
 *        No modifica el origen. Despues, cambie DB_DSN/DB_USER/DB_PASS en .env para usar la nueva base.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$raiz = dirname(__DIR__);
require_once $raiz . '/libraries/env.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Series', 'Planes') as $c) {
    require_once $raiz . '/v1/src/' . $c . '.php';
}
require_once $raiz . '/portal/src/Usuarios.php';
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

    case 'cert:subir':
        exigir($o, array('ruc', 'entorno'));
        if (!in_array($o['entorno'], array('test', 'live'), true)) {
            salir('--entorno debe ser test o live.');
        }
        $e = Db::uno('SELECT * FROM empresas WHERE ruc = ?', array($o['ruc']));
        if (!$e) {
            salir('No existe esa empresa.');
        }
        require_once $raiz . '/v1/src/Certificados.php';
        try {
            if (isset($o['pfx'])) {
                if (!is_file($o['pfx'])) {
                    salir('No existe el archivo ' . $o['pfx']);
                }
                list($certPem, $keyPem) = Certificados::leerPfx(file_get_contents($o['pfx']), isset($o['pass']) ? $o['pass'] : '');
                $clave = null;
            } else {
                exigir($o, array('cert', 'key'));
                if (!is_file($o['cert']) || !is_file($o['key'])) {
                    salir('No existe el archivo de --cert o --key.');
                }
                $certPem = file_get_contents($o['cert']);
                $keyPem = file_get_contents($o['key']);
                $clave = isset($o['pass']) ? $o['pass'] : null;
            }
            list($hoja, $keyLimpia, $info) = Certificados::validar($certPem, $keyPem, $o['ruc'], $o['entorno'], $clave);
        } catch (ApiError $err) {
            salir('Certificado rechazado: ' . $err->getMessage());
        }
        Certificados::guardar($e['id'], $o['entorno'], $hoja, $keyLimpia, $info);
        echo 'Certificado guardado (' . $o['entorno'] . '): ' . $info['sujeto'] . PHP_EOL . '  vigente hasta ' . $info['valido_hasta'] . ($info['ruc_coincide'] ? '' : '  [AVISO: no contiene el RUC; solo sirve para pruebas]') . PHP_EOL;
        break;

    case 'db:copiar':
        exigir($o, array('dsn'));
        $tablas = array('planes', 'empresas', 'api_keys', 'certificados', 'series', 'documentos', 'uso', 'webhooks', 'eventos', 'entregas', 'usuarios_portal', 'auditoria'); // (limites es transitoria: no se copia)
        $origen = Db::pdo();
        $motorOrigen = $origen->getAttribute(PDO::ATTR_DRIVER_NAME);
        Db::asegurarEsquema();
        $conteoOrigen = array();
        foreach ($tablas as $t) {
            $conteoOrigen[$t] = (int) $origen->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
        }
        try {
            $destino = Db::conectar($o['dsn'], isset($o['user']) ? $o['user'] : null, isset($o['pass']) ? $o['pass'] : null);
        } catch (PDOException $e) {
            salir('No se pudo conectar al destino: ' . $e->getMessage());
        }
        $motorDestino = $destino->getAttribute(PDO::ATTR_DRIVER_NAME);
        Db::crearEsquema(); // crea las tablas en el destino
        foreach ($tablas as $t) {
            if ((int) $destino->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn() > 0) {
                salir("El destino ya tiene datos en la tabla $t. Use una base de datos vacia (nada se copio).");
            }
        }
        echo "Copiando de $motorOrigen a $motorDestino...\n";
        $destino->beginTransaction();
        try {
            foreach ($tablas as $t) {
                $filas = $origen->query('SELECT * FROM ' . $t . ' ORDER BY id');
                $ins = null;
                $n = 0;
                while ($f = $filas->fetch(PDO::FETCH_ASSOC)) {
                    if ($ins === null) {
                        $cols = array_keys($f);
                        $ins = $destino->prepare('INSERT INTO ' . $t . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
                    }
                    $ins->execute(array_values($f));
                    $n++;
                }
                echo sprintf("  %-14s %d filas\n", $t, $n);
            }
            if ($motorDestino === 'pgsql') { // las secuencias deben continuar despues del mayor id copiado
                foreach ($tablas as $t) {
                    $destino->query("SELECT setval(pg_get_serial_sequence('$t', 'id'), COALESCE((SELECT MAX(id) FROM $t), 1), (SELECT COUNT(*) > 0 FROM $t))");
                }
            }
            $destino->commit();
        } catch (Exception $e) {
            if ($destino->inTransaction()) {
                $destino->rollBack();
            }
            salir('Fallo la copia (se deshizo todo, el destino quedo vacio): ' . $e->getMessage());
        }
        $bien = true;
        foreach ($tablas as $t) {
            $nd = (int) $destino->query('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
            if ($nd !== $conteoOrigen[$t]) {
                $bien = false;
                echo "  DIFERENCIA en $t: origen {$conteoOrigen[$t]}, destino $nd\n";
            }
        }
        if (!$bien) {
            salir('La verificacion de conteos fallo.');
        }
        echo "Copia verificada: los conteos coinciden en las 12 tablas." . PHP_EOL
            . "Para usar la nueva base, ponga en .env:  DB_DSN=" . $o['dsn'] . "  DB_USER=...  DB_PASS=..." . PHP_EOL;
        break;

    case 'plan:sembrar':
        Db::asegurarEsquema();
        foreach (Planes::$recomendados as $p) {
            if (Db::uno('SELECT id FROM planes WHERE nombre = ?', array($p['nombre']))) {
                echo "  ya existe: {$p['nombre']}" . PHP_EOL;
                continue;
            }
            Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, activo, creado) VALUES (?,?,?,?,?,1,?)',
                array($p['nombre'], $p['cuota_mensual'], $p['limite_sunat_min'], $p['limite_general_min'], $p['max_webhooks'], Db::ahora()));
            echo "  creado: {$p['nombre']}" . PHP_EOL;
        }
        echo "Listo. Vea los planes con: php bin/admin.php plan:listar" . PHP_EOL;
        break;

    case 'plan:crear':
        exigir($o, array('nombre'));
        Db::asegurarEsquema();
        $num = function ($clave, $defecto) use ($o) {
            if (!isset($o[$clave])) {
                return $defecto;
            }
            if (in_array(strtolower($o[$clave]), array('ilimitada', 'ilimitado', 'null'), true)) {
                return null;
            }
            if (!ctype_digit($o[$clave])) {
                salir("--$clave debe ser un numero entero (o 'ilimitado').");
            }
            return (int) $o[$clave];
        };
        $existe = Db::uno('SELECT * FROM planes WHERE nombre = ?', array($o['nombre']));
        $cuota = $num('cuota', $existe ? ($existe['cuota_mensual'] === null ? null : (int) $existe['cuota_mensual']) : null);
        $sunat = $num('sunat-min', $existe ? ($existe['limite_sunat_min'] === null ? null : (int) $existe['limite_sunat_min']) : 20);
        $gral = $num('general-min', $existe ? ($existe['limite_general_min'] === null ? null : (int) $existe['limite_general_min']) : 120);
        $wh = $num('webhooks', $existe ? (int) $existe['max_webhooks'] : 5);
        if ($wh === null || $wh < 1) {
            salir('--webhooks debe ser un entero de 1 en adelante.');
        }
        if ($existe) {
            Db::ejecutar('UPDATE planes SET cuota_mensual = ?, limite_sunat_min = ?, limite_general_min = ?, max_webhooks = ?, activo = 1 WHERE id = ?', array($cuota, $sunat, $gral, $wh, $existe['id']));
            echo "Plan {$o['nombre']} actualizado (aplica de inmediato a sus empresas)." . PHP_EOL;
        } else {
            Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, activo, creado) VALUES (?,?,?,?,?,1,?)', array($o['nombre'], $cuota, $sunat, $gral, $wh, Db::ahora()));
            echo "Plan {$o['nombre']} creado." . PHP_EOL;
        }
        echo '  cuota mensual: ' . ($cuota === null ? 'ilimitada' : $cuota) . ' | emisiones/min: ' . ($sunat === null ? 'sin limite' : $sunat) . ' | peticiones/min: ' . ($gral === null ? 'sin limite' : $gral) . ' | webhooks: ' . $wh . PHP_EOL;
        break;

    case 'plan:listar':
        Db::asegurarEsquema();
        printf("%-14s %-15s %-14s %-16s %-9s %s\n", 'PLAN', 'CUOTA/MES', 'EMISIONES/MIN', 'PETICIONES/MIN', 'WEBHOOKS', 'EMPRESAS');
        foreach (Db::consultar('SELECT p.*, (SELECT COUNT(*) FROM empresas e WHERE e.plan_id = p.id) AS n FROM planes p ORDER BY p.id') as $p) {
            printf("%-14s %-15s %-14s %-16s %-9s %d%s\n", $p['nombre'], $p['cuota_mensual'] === null ? 'ilimitada' : $p['cuota_mensual'], $p['limite_sunat_min'] === null ? 'sin limite' : $p['limite_sunat_min'],
                $p['limite_general_min'] === null ? 'sin limite' : $p['limite_general_min'], $p['max_webhooks'], $p['n'], $p['activo'] ? '' : '  [INACTIVO]');
        }
        $sin = Db::uno('SELECT COUNT(*) AS n FROM empresas WHERE plan_id IS NULL');
        echo "Empresas sin plan (sin ningun limite): {$sin['n']}" . PHP_EOL;
        break;

    case 'empresa:plan':
        exigir($o, array('ruc', 'plan'));
        Db::asegurarEsquema();
        $e = Db::uno('SELECT * FROM empresas WHERE ruc = ?', array($o['ruc']));
        if (!$e) {
            salir('No existe esa empresa.');
        }
        if (strtolower($o['plan']) === 'ninguno') {
            Db::ejecutar('UPDATE empresas SET plan_id = NULL WHERE id = ?', array($e['id']));
            echo "La empresa {$o['ruc']} quedo SIN plan (sin ningun limite)." . PHP_EOL;
            break;
        }
        $p = Db::uno('SELECT * FROM planes WHERE nombre = ? AND activo = 1', array($o['plan']));
        if (!$p) {
            salir("No existe el plan '{$o['plan']}'. Vea los planes con plan:listar (o cree los recomendados con plan:sembrar).");
        }
        Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($p['id'], $e['id']));
        echo "La empresa {$o['ruc']} ({$e['razon_social']}) ahora tiene el plan {$p['nombre']}." . PHP_EOL;
        break;

    case 'uso:ver':
        Db::asegurarEsquema();
        $sql = 'SELECT * FROM empresas' . (isset($o['ruc']) ? ' WHERE ruc = ?' : '') . ' ORDER BY id';
        printf("%-12s %-30s %-10s %-10s %-9s %s\n", 'RUC', 'EMPRESA', 'PLAN', 'USADOS', 'CUOTA', 'MES ' . Planes::periodo() . ' (solo llave live)');
        foreach (Db::consultar($sql, isset($o['ruc']) ? array($o['ruc']) : array()) as $e) {
            $plan = Planes::de($e);
            $u = Planes::usado((int) $e['id']);
            $cuota = $plan['cuota_mensual'];
            printf("%-12s %-30s %-10s %-10d %-9s %s\n", $e['ruc'], substr($e['razon_social'], 0, 30), $plan['nombre'], $u, $cuota === null ? 'ilimitada' : $cuota, ($cuota !== null && $cuota > 0) ? round(100 * $u / $cuota, 1) . ' %' : '');
        }
        break;

    case 'usuario:crear':
        exigir($o, array('ruc', 'email', 'nombre'));
        Db::asegurarEsquema();
        $e = Db::uno('SELECT * FROM empresas WHERE ruc = ?', array($o['ruc']));
        if (!$e) {
            salir('No existe esa empresa.');
        }
        try {
            $r = Usuarios::crear($e['id'], $o['email'], $o['nombre'], isset($o['rol']) ? $o['rol'] : 'admin');
        } catch (InvalidArgumentException $x) {
            salir($x->getMessage());
        }
        Auditoria::registrar($e['id'], null, 'usuario_creado', strtolower($o['email']) . ' por el administrador del servicio');
        echo "Usuario creado para {$e['razon_social']} ({$o['ruc']}): " . strtolower($o['email']) . PHP_EOL . PHP_EOL
            . "  Contrasena TEMPORAL (se muestra una sola vez; deberá cambiarla al ingresar):" . PHP_EOL . PHP_EOL . "  {$r['clave']}" . PHP_EOL . PHP_EOL;
        break;

    case 'usuario:listar':
        Db::asegurarEsquema();
        $sql = 'SELECT u.*, e.ruc FROM usuarios_portal u JOIN empresas e ON e.id = u.empresa_id' . (isset($o['ruc']) ? ' WHERE e.ruc = ?' : '') . ' ORDER BY u.id';
        foreach (Db::consultar($sql, isset($o['ruc']) ? array($o['ruc']) : array()) as $u) {
            printf("%-3d %s  %-34s %-7s %s%s%s\n", $u['id'], $u['ruc'], $u['email'], $u['rol'], $u['ultimo_acceso'] ? 'ultimo acceso ' . $u['ultimo_acceso'] : 'nunca ingreso',
                $u['activo'] ? '' : '  [DESACTIVADO]', ($u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) ? '  [BLOQUEADO hasta ' . $u['bloqueado_hasta'] . ']' : '');
        }
        break;

    case 'usuario:reset':
        exigir($o, array('email'));
        Db::asegurarEsquema();
        try {
            $clave = Usuarios::restablecer($o['email']);
        } catch (InvalidArgumentException $x) {
            salir($x->getMessage());
        }
        echo "Contrasena restablecida para " . strtolower($o['email']) . " (cuenta desbloqueada y activa)." . PHP_EOL . PHP_EOL
            . "  Contrasena TEMPORAL (una sola vez; deberá cambiarla al ingresar):" . PHP_EOL . PHP_EOL . "  {$clave}" . PHP_EOL . PHP_EOL;
        break;

    case 'usuario:desactivar':
    case 'usuario:activar':
        exigir($o, array('email'));
        Db::asegurarEsquema();
        $u = Usuarios::porEmail($o['email']);
        if (!$u) {
            salir('No existe ese usuario.');
        }
        $act = $cmd === 'usuario:activar' ? 1 : 0;
        Db::ejecutar('UPDATE usuarios_portal SET activo = ? WHERE id = ?', array($act, $u['id']));
        Auditoria::registrar($u['empresa_id'], null, $act ? 'usuario_activado' : 'usuario_desactivado', $u['email'] . ' por el administrador del servicio');
        echo ($act ? 'Usuario activado: ' : 'Usuario desactivado (ya no puede ingresar): ') . $u['email'] . PHP_EOL;
        break;

    case 'serie:crear':
        exigir($o, array('ruc', 'entorno', 'tipo', 'serie'));
        if (!in_array($o['entorno'], array('test', 'live'), true)) {
            salir('--entorno debe ser test o live.');
        }
        $e = Db::uno('SELECT * FROM empresas WHERE ruc = ?', array($o['ruc']));
        if (!$e) {
            salir('No existe esa empresa.');
        }
        Db::asegurarEsquema();
        try {
            list($vista, $creada) = Series::guardar(array('empresa' => $e, 'key' => array('entorno' => $o['entorno'])), $o['tipo'], strtoupper($o['serie']),
                isset($o['modo']) ? $o['modo'] : null, isset($o['ultimo']) ? (ctype_digit($o['ultimo']) ? (int) $o['ultimo'] : -1) : null);
        } catch (ApiError $err) {
            salir('Serie rechazada: ' . $err->getMessage() . ($err->detalles ? ' (' . $err->detalles[0]['mensaje'] . ')' : ''));
        }
        echo ($creada ? 'Serie creada: ' : 'Serie actualizada: ') . $vista['tipo'] . ' ' . $vista['serie'] . '  modo=' . $vista['modo'] . '  ultimo=' . $vista['ultimo_numero'] . '  siguiente=' . $vista['siguiente_numero'] . PHP_EOL;
        break;

    case 'serie:listar':
        Db::asegurarEsquema();
        $sql = 'SELECT s.*, e.ruc FROM series s JOIN empresas e ON e.id = s.empresa_id WHERE 1=1' . (isset($o['ruc']) ? ' AND e.ruc = ?' : '') . (isset($o['entorno']) ? ' AND s.entorno = ?' : '') . ' ORDER BY e.ruc, s.entorno, s.tipo, s.serie';
        $par = array();
        if (isset($o['ruc'])) {
            $par[] = $o['ruc'];
        }
        if (isset($o['entorno'])) {
            $par[] = $o['entorno'];
        }
        foreach (Db::consultar($sql, $par) as $s) {
            $v = Series::ver($s);
            printf("%s  %-4s  %-3s %-9s modo=%-7s ultimo=%-9s siguiente=%-9s reservas=%d\n", $s['ruc'], $s['entorno'], $s['tipo'], $s['serie'], $s['modo'], $s['ultimo_numero'], $v['siguiente_numero'], $v['reservas_abiertas']);
        }
        break;

    case 'cert:ver':
        require_once $raiz . '/v1/src/Certificados.php';
        $sql = 'SELECT e.ruc, e.id FROM empresas e' . (isset($o['ruc']) ? ' WHERE e.ruc = ?' : '') . ' ORDER BY e.id';
        foreach (Db::consultar($sql, isset($o['ruc']) ? array($o['ruc']) : array()) as $e) {
            foreach (array('test', 'live') as $ent) {
                $c = Certificados::estado($e['id'], $ent);
                printf("%s  %-4s  %s
", $e['ruc'], $ent, $c ? $c['sujeto'] . '  vence ' . $c['valido_hasta'] . ' (' . $c['dias_restantes'] . ' dias)' . ($c['por_vencer'] ? '  [POR VENCER]' : '') : '(sin certificado propio' . ($ent === 'test' ? ': usa el compartido de pruebas)' : ': NO PUEDE EMITIR EN PRODUCCION)'));
            }
        }
        break;

    default:
        echo "Comandos: init | empresa:crear | empresa:actualizar | empresa:listar | key:crear | key:listar | key:revocar | cert:subir | cert:ver | serie:crear | serie:listar | db:copiar | plan:sembrar | plan:crear | plan:listar | empresa:plan | uso:ver | usuario:crear | usuario:listar | usuario:reset | usuario:activar | usuario:desactivar" . PHP_EOL
            . "Vea el encabezado de bin/admin.php para las opciones." . PHP_EOL;
}
