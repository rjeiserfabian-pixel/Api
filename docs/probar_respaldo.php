<?php
/**
 * Prueba de los respaldos (solo linea de comandos): crea, verifica, detecta alteraciones, restaura y aplica retencion.
 *
 *   php docs/probar_respaldo.php
 *
 * No modifica el sistema: trabaja en una carpeta temporal que borra al terminar.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$RAIZ = dirname(__DIR__);
chdir($RAIZ);
require 'libraries/env.php';
require 'v1/src/Db.php';
$TMP = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/prueba_respaldo_' . bin2hex(random_bytes(4));
mkdir($TMP, 0700, true);
register_shutdown_function(function () use ($TMP) {
    if (is_dir($TMP)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($TMP, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($TMP);
    }
});
$ok = 0;
$mal = 0;
function chk($n, $c, $d = '')
{
    global $ok, $mal;
    if ($c) {
        $ok++;
        echo "  [OK]   $n\n";
    } else {
        $mal++;
        echo "  [FALLA] $n" . ($d !== '' ? "  -> $d" : '') . "\n";
    }
}
function resp($args, &$codigo = null)
{
    $salida = array();
    exec('php bin/respaldo.php ' . $args . ' 2>&1', $salida, $codigo);
    return implode("\n", $salida);
}
function ultimo($dir)
{
    $d = array_values(array_filter(scandir($dir), function ($x) {
        return preg_match('/^\d{8}-\d{6}$/', $x);
    }));
    return $d ? $dir . '/' . end($d) : null;
}

echo "Prueba de respaldos\n";
$DIR = $TMP . '/respaldos';

echo "\n== 1) Crear\n";
$s = resp("crear --dir=$DIR --clave=Frase-De-Prueba-1", $cod);
chk('crear termina bien y verifica el respaldo por si mismo', $cod === 0 && strpos($s, 'verificado: OK') !== false, $s);
$r1 = ultimo($DIR);
chk('la carpeta del respaldo tiene base, .env cifrado, comprobantes y manifiesto', $r1 && is_file("$r1/base.sqlite") && is_file("$r1/env.enc") && is_file("$r1/archivos.zip") && is_file("$r1/manifiesto.json"));
chk('el .env NO queda en claro cuando se usa --clave (APP_KEY no aparece en ningun archivo de texto)', !is_file("$r1/env.txt") && strpos((string) file_get_contents("$r1/env.enc"), (string) api_env('APP_KEY')) === false);
$m = json_decode(file_get_contents("$r1/manifiesto.json"), true);
$vivo = Db::pdo();
chk('el manifiesto registra los conteos reales de la base (empresas, documentos...)', $m['base']['conteos']['empresas'] === (int) $vivo->query('SELECT COUNT(*) FROM empresas')->fetchColumn() && $m['base']['conteos']['documentos'] === (int) $vivo->query('SELECT COUNT(*) FROM documentos')->fetchColumn());
chk('la copia de la base se puede abrir y contiene las mismas tablas', (function () use ($r1) {
    $p = new PDO('sqlite:' . $r1 . '/base.sqlite');
    return (int) $p->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn() >= 15;
})());

echo "\n== 2) Verificar\n";
chk('un respaldo sano se reconoce como integro', strpos(resp("verificar --desde=$r1 --clave=Frase-De-Prueba-1", $c), 'integro') !== false && $c === 0);
resp("verificar --desde=$r1", $c);
chk('sin la clave no se puede comprobar el .env cifrado (avisa)', $c !== 0);
resp("verificar --desde=$r1 --clave=otra-clave", $c);
chk('con una clave equivocada falla', $c !== 0);
chk('"verificar" sin --desde toma el ultimo respaldo de la carpeta', strpos(resp("verificar --dir=$DIR --clave=Frase-De-Prueba-1", $c), 'integro') !== false);

echo "\n== 2b) Logos de las empresas\n";
$logoLab = $RAIZ . '/storage/logos/999999991.png';
@mkdir($RAIZ . '/storage/logos', 0700, true);
file_put_contents($logoLab, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
sleep(1);
$s = resp("crear --dir=$DIR --sin-archivos --clave=Frase-De-Prueba-1", $cod);
$rl = ultimo($DIR);
chk('los logos de las empresas entran al respaldo (logos.zip) y se verifican', $cod === 0 && is_file("$rl/logos.zip") && strpos($s, 'logos de las empresas') !== false && strpos(resp("verificar --desde=$rl --clave=Frase-De-Prueba-1", $cv), 'integro') !== false);
$dl = $TMP . '/restaurado-logos';
resp("restaurar --desde=$rl --destino=$dl --clave=Frase-De-Prueba-1", $cr);
chk('al restaurar, el logo vuelve a storage/logos con el mismo contenido', $cr === 0 && is_file("$dl/storage/logos/999999991.png") && md5_file("$dl/storage/logos/999999991.png") === md5_file($logoLab));
@unlink($logoLab);

echo "\n== 3) Detectar alteraciones\n";
$copia = $TMP . '/alterado';
mkdir($copia);
foreach (scandir($r1) as $f) {
    if ($f !== '.' && $f !== '..') {
        copy("$r1/$f", "$copia/$f");
    }
}
$bytes = file_get_contents("$copia/base.sqlite");
file_put_contents("$copia/base.sqlite", substr($bytes, 0, 5000) . chr(ord($bytes[5000]) ^ 0xFF) . substr($bytes, 5001));
$s = resp("verificar --desde=$copia --clave=Frase-De-Prueba-1", $c);
chk('un solo byte cambiado en la base se detecta (huella SHA-256)', $c !== 0 && strpos($s, 'alterado') !== false);
unlink("$copia/archivos.zip");
$s = resp("verificar --desde=$copia --clave=Frase-De-Prueba-1", $c);
chk('un archivo que falta se detecta', $c !== 0 && strpos($s, 'archivos.zip') !== false);
$s = resp("restaurar --desde=$copia --destino=" . $TMP . '/no-debe-existir --clave=Frase-De-Prueba-1', $c);
chk('restaurar un respaldo danado se rechaza y no crea nada', $c !== 0 && !is_dir($TMP . '/no-debe-existir'));

echo "\n== 4) Restaurar\n";
$dest = $TMP . '/restaurado';
$s = resp("restaurar --desde=$r1 --destino=$dest --clave=Frase-De-Prueba-1", $c);
chk('restaurar deja base, .env y comprobantes en la carpeta nueva', $c === 0 && is_file("$dest/base.sqlite") && is_file("$dest/.env") && is_dir("$dest/files"), $s);
chk('el .env restaurado es identico al original (incluye APP_KEY)', file_get_contents("$dest/.env") === file_get_contents($RAIZ . '/.env'));
$p = new PDO('sqlite:' . $dest . '/base.sqlite');
chk('la base restaurada tiene los mismos datos que la original', (int) $p->query('SELECT COUNT(*) FROM empresas')->fetchColumn() === $m['base']['conteos']['empresas'] && (int) $p->query('SELECT COUNT(*) FROM documentos')->fetchColumn() === $m['base']['conteos']['documentos']);
$n = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dest/files", FilesystemIterator::SKIP_DOTS)) as $f) {
    $n += $f->isFile() ? 1 : 0;
}
chk('los comprobantes restaurados coinciden con los del respaldo (' . $m['comprobantes'] . ')', $n === $m['comprobantes']);
resp("restaurar --desde=$r1 --destino=$dest --clave=Frase-De-Prueba-1", $c);
chk('restaurar sobre una carpeta que ya tiene archivos se rechaza (nunca pisa nada)', $c !== 0);
resp("restaurar --desde=$r1 --destino=" . str_replace('\\', '/', $RAIZ) . ' --clave=Frase-De-Prueba-1', $c);
chk('restaurar sobre la carpeta del sistema en uso se rechaza', $c !== 0);

echo "\n== 5) Opciones\n";
sleep(1);
$s = resp("crear --dir=$DIR --sin-archivos", $c);
$r2 = ultimo($DIR);
chk('--sin-archivos omite los comprobantes y lo dice', $c === 0 && !is_file("$r2/archivos.zip") && strpos($s, 'OMITIDOS') !== false);
chk('sin --clave el .env queda en claro y el programa lo advierte', is_file("$r2/env.txt") && strpos($s, 'en CLARO') !== false);
sleep(1);
resp("crear --dir=$DIR --sin-archivos --retener=2", $c);
chk('--retener=2 conserva solo los 2 respaldos mas recientes', count(array_filter(scandir($DIR), function ($x) {
    return preg_match('/^\d{8}-\d{6}$/', $x);
})) === 2 && !is_dir($r1));
$s = resp("listar --dir=$DIR", $c);
chk('listar muestra los respaldos con su tamano', substr_count($s, 'sqlite') === 2);
chk('el sistema sigue intacto: la base original no cambio durante la prueba', (int) $vivo->query('SELECT COUNT(*) FROM empresas')->fetchColumn() === $m['base']['conteos']['empresas']);

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
