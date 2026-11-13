<?php
/**
 * Prueba de la VERIFICACION PUBLICA de documentos (solo linea de comandos).
 *
 *   php docs/probar_verificacion.php [--key=sk_test_XXXX]
 *
 * Con --key (llave de PRUEBA) tambien hace una emision real contra el beta de SUNAT para comprobar de punta a punta que se guardan
 * los datos, que la API devuelve el enlace y que el PDF trae impreso el enlace. Sin --key se omite solo esa parte.
 * Crea empresas y documentos de laboratorio y los borra al terminar. Con Apache encendido.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$RAIZ = dirname(__DIR__);
chdir($RAIZ);
date_default_timezone_set('America/Lima');
require 'libraries/env.php';
foreach (array('Respuesta', 'Db', 'Crypto', 'Auth', 'Series', 'Planes') as $c) {
    require_once 'v1/src/' . $c . '.php';
}
require_once 'v1/src/Verificacion.php';
require_once 'portal/src/Usuarios.php';
$BASE = 'http://localhost/API_SUNAT';
$KEY = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $BASE = rtrim($m[1], '/');
    }
    if (preg_match('/^--key=(sk_test_[a-f0-9]{40})$/', $a, $m)) {
        $KEY = $m[1];
    }
}
$WEB = $BASE . '/web';
$API = $BASE . '/v1';
$POU = $BASE . '/portal';
$CLAVE = 'Cl4ve-Segura-2026!';
$RUCS = array('20000000031', '20000000032');
Db::asegurarEsquema();
foreach ($RUCS as $r) {
    if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($r))) {
        fwrite(STDERR, "ABORTADO: ya existe la empresa $r (de una corrida interrumpida). Borrela antes de repetir.\n");
        exit(3);
    }
}
$limpiar = function () use ($RUCS) {
    try {
        foreach ($RUCS as $ruc) {
            $e = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($ruc));
            if (!$e) {
                continue;
            }
            foreach (array('documentos', 'usuarios_portal', 'auditoria', 'api_keys', 'uso') as $t) {
                Db::ejecutar("DELETE FROM $t WHERE empresa_id = ?", array((int) $e['id']));
            }
            Db::ejecutar('DELETE FROM empresas WHERE id = ?', array((int) $e['id']));
        }
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'verif:%' OR clave LIKE 'login:%'");
    } catch (Exception $x) {
        fwrite(STDERR, 'No se pudo limpiar: ' . $x->getMessage() . "\n");
    }
};
register_shutdown_function($limpiar);

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
    return (bool) $c;
}
$LIBRE = true;
function http($metodo, $url, $campos = null, array $cab = array(), &$cookies = null)
{
    global $LIBRE;
    if ($LIBRE) {
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'verif:%'"); // las pruebas hacen muchas consultas desde la misma IP
    }
    $ch = curl_init($url);
    $h = array_merge(array('User-Agent: ProbarVerificacion/1.0'), $cab);
    if (is_array($cookies) && $cookies) {
        $p = array();
        foreach ($cookies as $k => $v) {
            $p[] = "$k=$v";
        }
        $h[] = 'Cookie: ' . implode('; ', $p);
    }
    $cabR = array();
    $setc = array();
    curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$cabR, &$setc) {
            if (strpos($l, ':') !== false) {
                list($k, $v) = explode(':', $l, 2);
                $k = strtolower(trim($k));
                $cabR[$k] = trim($v);
                if ($k === 'set-cookie') {
                    $setc[] = trim($v);
                }
            }
            return strlen($l);
        }));
    if ($campos !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($campos) ? http_build_query($campos) : $campos);
    }
    $cuerpo = (string) curl_exec($ch);
    $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (is_array($cookies)) {
        foreach ($setc as $sc) {
            $q = explode(';', $sc);
            list($n, $v) = array_pad(explode('=', trim($q[0]), 2), 2, '');
            if ($v === '' || stripos($sc, 'expires=Thu, 01-Jan-1970') !== false) {
                unset($cookies[$n]);
            } else {
                $cookies[$n] = $v;
            }
        }
    }
    return array('http' => $st, 'cab' => $cabR, 'cuerpo' => $cuerpo, 'json' => json_decode($cuerpo, true), 'loc' => isset($cabR['location']) ? $cabR['location'] : null);
}
function nuevaEmpresa($ruc, $razon, $verif = 1)
{
    return (int) Db::ejecutar('INSERT INTO empresas (ruc, razon_social, nombre_comercial, domicilio_fiscal, ubigeo, departamento, provincia, distrito, activo, verificacion_publica, creado) VALUES (?,?,?,?,?,?,?,?,1,?,?)',
        array($ruc, $razon, 'LAB', 'AV. LAB 1', '150101', 'LIMA', 'LIMA', 'LIMA', $verif, Db::ahora()));
}
function nuevoDoc($emp, $tipo, $serie, $n, $ent, $estado, $fecha, $total, $extra = array())
{
    $uid = 'doc_' . bin2hex(random_bytes(10));
    Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, codigo_sunat, mensaje_sunat, hash_firma, fecha_emision, total, moneda, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        array($uid, $emp, $tipo, $serie, str_pad($n, 8, '0', STR_PAD_LEFT), $n, $ent, $estado, isset($extra['cod']) ? $extra['cod'] : '0', isset($extra['msg']) ? $extra['msg'] : 'El comprobante ' . $serie . '-' . $n . ' ha sido aceptado',
            'aGFzaC1kZS1wcnVlYmE=', $fecha, $total, $total === null ? null : 'PEN', Db::ahora()));
    return $uid;
}
function enlace($ruc, $tipo, $serie, $n, $ent)
{
    return Verificacion::slug($ruc, $tipo, $serie, $n, $ent);
}
function web($ruta, $campos = null, array $cab = array())
{
    global $WEB;
    return http($campos === null ? 'GET' : 'POST', $WEB . $ruta, $campos, $cab);
}

echo "Prueba de la verificacion publica de documentos\n";
$hoy = date('Y-m-d');

echo "\n== 0) Preparacion\n";
$idA = nuevaEmpresa($RUCS[0], 'LAB VERIF SAC <script>alert("x")</script>');
$idB = nuevaEmpresa($RUCS[1], 'LAB SIN VERIFICACION SAC', 0);
$RUC = $RUCS[0];
$d1 = nuevoDoc($idA, '01', 'F001', 150, 'live', 'aceptado', $hoy, 11800, array('msg' => 'La Factura numero F001-150, ha sido aceptada'));
$d2 = nuevoDoc($idA, '01', 'F001', 150, 'test', 'aceptado', '2026-11-12', 5000);
$d3 = nuevoDoc($idA, '03', 'B001', 7, 'live', 'rechazado', $hoy, 10000, array('cod' => '2325', 'msg' => 'El comprobante fue enviado fuera del plazo'));
$d4 = nuevoDoc($idA, '03', 'B001', 8, 'live', 'pendiente', $hoy, 10000, array('cod' => null, 'msg' => null));
$d5 = nuevoDoc($idA, '03', 'B001', 9, 'live', 'error', $hoy, null);
$d6 = nuevoDoc($idA, '01', 'F001', 151, 'live', 'aceptado', null, null);
$d7 = nuevoDoc($idA, '09', 'T001', 5, 'live', 'aceptado', $hoy, null);
$dB = nuevoDoc($idB, '01', 'F001', 1, 'live', 'aceptado', $hoy, 23600);
chk('empresas y 8 documentos de laboratorio creados', $idA > 0 && $idB > 0 && Db::uno('SELECT COUNT(*) AS n FROM documentos WHERE empresa_id IN (?, ?)', array($idA, $idB))['n'] == 8);

echo "\n== 1) El codigo de verificacion\n";
$t = Verificacion::token($RUC, '01', 'F001', 150, 'live');
chk('el codigo tiene 12 caracteres del alfabeto sin I, L, O, U', (bool) preg_match('/^[0-9A-HJKMNP-TV-Z]{12}$/', $t), $t);
chk('es determinista: el mismo documento siempre da el mismo codigo', $t === Verificacion::token($RUC, '01', 'F001', 150, 'live'));
chk('la serie en minusculas y el numero con ceros dan el mismo enlace', Verificacion::slug($RUC, '01', 'f001', '00000150', 'live') === Verificacion::slug($RUC, '01', 'F001', 150, 'live'));
$distintos = array($t);
foreach (array(array($RUC, '01', 'F001', 151, 'live'), array($RUC, '01', 'F001', 150, 'test'), array($RUC, '03', 'F001', 150, 'live'), array($RUC, '01', 'F002', 150, 'live'), array('20000000099', '01', 'F001', 150, 'live')) as $a) {
    $distintos[] = Verificacion::token($a[0], $a[1], $a[2], $a[3], $a[4]);
}
chk('cualquier cambio (numero, entorno, tipo, serie, RUC) cambia el codigo', count(array_unique($distintos)) === 6);
$a = Verificacion::analizar(Verificacion::slug($RUC, '01', 'F001', 150, 'live'));
chk('el enlace se descompone en RUC, tipo, serie, numero y codigo', $a && $a['ruc'] === $RUC && $a['tipo'] === '01' && $a['serie'] === 'F001' && $a['n'] === 150 && $a['token'] === $t);
$malos = array('', 'x', '20000000031-01-F001-150', $RUC . '-01-F001-150-' . substr($t, 0, 11), $RUC . '-01-F001-150-' . $t . 'A', $RUC . '-99-F001-150-' . $t, $RUC . "-01-F001-150-" . $t . "'; DROP TABLE documentos;--",
    '../../etc/passwd', $RUC . '-01-F001-123456789-' . $t, $RUC . '-01-F0 01-150-' . $t, str_repeat('A', 200));
$bien = true;
foreach ($malos as $x) {
    if (Verificacion::analizar($x) !== null) {
        $bien = false;
        echo "      acepto: " . substr($x, 0, 60) . "\n";
    }
}
chk('enlaces mal formados, demasiado largos o con codigo malicioso se rechazan', $bien);
$tipeado = strtr($t, array('0' => 'O', '1' => 'I'));
chk('al copiar a mano, O/0 e I/L/1 se confunden y se perdona (como en los codigos de Crockford)', Verificacion::analizar($RUC . '-01-F001-150-' . $tipeado)['token'] === $t);

echo "\n== 2) Busqueda por enlace\n";
$f = Verificacion::porEnlace(enlace($RUC, '01', 'F001', 150, 'live'));
chk('el enlace de produccion encuentra el documento de PRODUCCION (no el de pruebas con el mismo numero)', $f && $f['uid'] === $d1 && $f['entorno'] === 'live');
$f = Verificacion::porEnlace(enlace($RUC, '01', 'F001', 150, 'test'));
chk('el enlace de pruebas encuentra el de PRUEBAS', $f && $f['uid'] === $d2);
$tokMalo = substr($t, 0, 11) . ($t[11] === 'A' ? 'B' : 'A');
chk('un solo caracter del codigo cambiado -> no se obtiene nada', Verificacion::porEnlace($RUC . '-01-F001-150-' . $tokMalo) === null);
chk('un documento que existe con un codigo inventado -> nada', Verificacion::porEnlace($RUC . '-01-F001-150-AAAAAAAAAAAA') === null);
chk('un numero inexistente con codigo valido para otro documento -> nada', Verificacion::porEnlace($RUC . '-01-F001-9999-' . $t) === null);
chk('el codigo de un documento NO sirve para otro (cambiar el numero)', Verificacion::porEnlace($RUC . '-01-F001-151-' . $t) === null);

echo "\n== 3) Lo que se hace publico\n";
$p = Verificacion::publico(Verificacion::porEnlace(enlace($RUC, '01', 'F001', 150, 'live')));
chk('documento aceptado en produccion -> valido, con emisor, tipo, serie-numero, fecha, total y respuesta de SUNAT', $p['valido'] === true && $p['estado'] === 'aceptado' && $p['entorno'] === 'produccion'
    && $p['emisor']['ruc'] === $RUC && $p['tipo_nombre'] === 'Factura electrónica' && $p['serie'] === 'F001' && $p['numero'] === '00000150' && $p['fecha_emision'] === $hoy && $p['total'] === '118.00' && $p['moneda'] === 'PEN'
    && strpos($p['sunat']['descripcion'], 'aceptada') !== false);
$json = json_encode($p);
chk('NO incluye ningun dato del comprador ni identificadores internos (cliente, documento, id interno, cuerpo)', !preg_match('/cliente|comprador|adquirente|numero_documento|"id"|uid|doc_[a-f0-9]{20}|cuerpo|idem/i', $json));
$p2 = Verificacion::publico(Verificacion::porEnlace(enlace($RUC, '01', 'F001', 150, 'test')));
chk('un documento de PRUEBAS aparece como prueba y NUNCA como valido', $p2['entorno'] === 'prueba' && $p2['valido'] === false && $p2['estado'] === 'aceptado');
$p3 = Verificacion::publico(Verificacion::porEnlace(enlace($RUC, '03', 'B001', 7, 'live')));
chk('rechazado por SUNAT -> no valido, con el motivo', $p3['estado'] === 'rechazado' && $p3['valido'] === false && strpos($p3['sunat']['descripcion'], 'plazo') !== false);
$p4 = Verificacion::publico(Verificacion::porEnlace(enlace($RUC, '03', 'B001', 8, 'live')));
chk('pendiente -> "pendiente", no valido', $p4['estado'] === 'pendiente' && $p4['valido'] === false);
$p5 = Verificacion::publico(Verificacion::porEnlace(enlace($RUC, '03', 'B001', 9, 'live')));
chk('un documento que fallo (error) o nunca llego a SUNAT -> "no_validado", sin respuesta de SUNAT, no valido', $p5['estado'] === 'no_validado' && $p5['valido'] === false && $p5['sunat'] === null);
chk('si la empresa NO habilita la verificacion publica, no se devuelve nada', Verificacion::publico(Verificacion::porEnlace(enlace($RUCS[1], '01', 'F001', 1, 'live'))) === null);
$p7 = Verificacion::publico(Verificacion::porEnlace(enlace($RUC, '09', 'T001', 5, 'live')));
chk('una guia de remision tambien se verifica (sin total)', $p7 !== null && $p7['total'] === null && $p7['tipo_nombre'] === 'Guía de remisión electrónica');

echo "\n== 4) Consulta con los datos del documento\n";
$cent = 11800;
chk('RUC, tipo, serie, numero, fecha y total exactos -> lo encuentra (el de produccion)', ($f = Verificacion::porDatos($RUC, '01', 'F001', '150', $hoy, $cent)) && $f['uid'] === $d1);
chk('la serie en minusculas y el numero con ceros no importan', Verificacion::porDatos($RUC, '01', 'f001', '00000150', $hoy, $cent) !== null);
chk('el total con UN centimo de diferencia -> nada', Verificacion::porDatos($RUC, '01', 'F001', '150', $hoy, 11801) === null);
chk('la fecha con un dia de diferencia -> nada', Verificacion::porDatos($RUC, '01', 'F001', '150', date('Y-m-d', strtotime('-1 day')), $cent) === null);
chk('un RUC, tipo, serie o numero distintos -> nada', Verificacion::porDatos('20000000099', '01', 'F001', '150', $hoy, $cent) === null && Verificacion::porDatos($RUC, '03', 'F001', '150', $hoy, $cent) === null
    && Verificacion::porDatos($RUC, '01', 'F002', '150', $hoy, $cent) === null && Verificacion::porDatos($RUC, '01', 'F001', '152', $hoy, $cent) === null);
chk('con los datos del de PRUEBAS (fecha y total distintos) encuentra el de pruebas', ($f = Verificacion::porDatos($RUC, '01', 'F001', '150', '2026-11-12', 5000)) && $f['uid'] === $d2);
chk('un documento sin fecha/total guardados NO se puede consultar por datos (solo por enlace)', Verificacion::porDatos($RUC, '01', 'F001', '151', $hoy, 0) === null && Verificacion::porEnlace(enlace($RUC, '01', 'F001', 151, 'live')) !== null);
$bien = true;
foreach (array(array("20000000031' OR '1'='1", '01', 'F001', '150', $hoy, $cent), array($RUC, '01', "F001' OR 1=1--", '150', $hoy, $cent), array($RUC, '01', 'F001', '1 OR 1=1', $hoy, $cent),
    array($RUC, '01', 'F001', '150', "2026-11-13' OR '1'='1", $cent), array($RUC, '99', 'F001', '150', $hoy, $cent), array($RUC, '01', 'F001', '150', $hoy, -5), array($RUC, '09', 'T001', '5', $hoy, 0)) as $x) {
    try {
        if (Verificacion::porDatos($x[0], $x[1], $x[2], $x[3], $x[4], $x[5]) !== null) {
            $bien = false;
        }
    } catch (Exception $e) {
        $bien = false;
    }
}
chk('entradas con inyeccion SQL, tipos no permitidos o totales negativos -> nada y sin errores', $bien);
chk('el total acepta "118", "118.00", 118.0 y rechaza "118.005", "-1" y texto', Verificacion::aCentimos('118') === 11800 && Verificacion::aCentimos('118.00') === 11800 && Verificacion::aCentimos(118.5) === 11850
    && Verificacion::aCentimos('118.005') === null && Verificacion::aCentimos('-1') === null && Verificacion::aCentimos('abc') === null && Verificacion::aCentimos(null) === null);

echo "\n== 5) Pagina publica /verificar\n";
$r = web('/verificar');
chk('el formulario de consulta abre (200)', $r['http'] === 200 && strpos($r['cuerpo'], 'Verifique un comprobante electrónico') !== false);
chk('no se indexa ni se guarda: noindex, no-store, no-referrer, CSP estricta y sin scripts en linea', strpos($r['cab']['x-robots-tag'], 'noindex') !== false && strpos($r['cab']['cache-control'], 'no-store') !== false && $r['cab']['referrer-policy'] === 'no-referrer'
    && strpos($r['cab']['content-security-policy'], 'unsafe-inline') === false && !preg_match('/<script(?![^>]*\ssrc=)/i', $r['cuerpo']) && !preg_match('/\sstyle\s*=/i', $r['cuerpo']));
$u1 = '/verificar/' . enlace($RUC, '01', 'F001', 150, 'live');
$r = web($u1);
chk('el enlace del documento valido muestra "Documento válido", el emisor, serie-numero, fecha y total', $r['http'] === 200 && strpos($r['cuerpo'], 'Documento válido') !== false && strpos($r['cuerpo'], 'F001-00000150') !== false
    && strpos($r['cuerpo'], 'S/ 118.00') !== false && strpos($r['cuerpo'], date('d/m/Y')) !== false && strpos($r['cuerpo'], 'RUC ' . $RUC) !== false);
chk('...con su codigo QR (imagen incrustada) y el aviso de que la validez la confirma SUNAT', strpos($r['cuerpo'], 'src="data:image/png;base64,') !== false && strpos($r['cuerpo'], 'La validez tributaria la confirma SUNAT') !== false);
chk('...y sin ningun dato del comprador', strpos($r['cuerpo'], '12345678') === false && stripos($r['cuerpo'], 'comprador:') === false);
chk('la razon social con codigo malicioso sale ESCAPADA', strpos($r['cuerpo'], '<script>alert') === false && strpos($r['cuerpo'], '&lt;script&gt;') !== false);
chk('el documento de PRUEBAS se muestra como "Documento de PRUEBA ... sin validez tributaria"', strpos(web('/verificar/' . enlace($RUC, '01', 'F001', 150, 'test'))['cuerpo'], 'Documento de PRUEBA') !== false);
chk('el rechazado muestra "Documento NO válido" y el motivo', ($x = web('/verificar/' . enlace($RUC, '03', 'B001', 7, 'live'))['cuerpo']) && strpos($x, 'Documento NO válido') !== false && strpos($x, 'fuera del plazo') !== false);
chk('el pendiente muestra "En proceso"', strpos(web('/verificar/' . enlace($RUC, '03', 'B001', 8, 'live'))['cuerpo'], 'En proceso') !== false);
chk('el que nunca llego a SUNAT muestra "Sin validación de SUNAT"', strpos(web('/verificar/' . enlace($RUC, '03', 'B001', 9, 'live'))['cuerpo'], 'Sin validación de SUNAT') !== false);
$t1 = web('/verificar/' . $RUC . '-01-F001-150-AAAAAAAAAAAA');
$t2 = web('/verificar/' . enlace($RUC, '01', 'F001', 99999, 'live'));
$t3 = web('/verificar/' . enlace($RUCS[1], '01', 'F001', 1, 'live'));
chk('codigo incorrecto, documento inexistente y empresa que no habilita la verificacion dan EXACTAMENTE la misma respuesta (no se puede distinguir)', $t1['cuerpo'] === $t2['cuerpo'] && $t2['cuerpo'] === $t3['cuerpo'] && strpos($t1['cuerpo'], 'No encontramos un documento') !== false);
$campos = array('ruc' => $RUC, 'tipo' => '01', 'serie' => 'f001', 'numero' => '150', 'fecha' => $hoy, 'total' => '118.00');
$r = web('/verificar', $campos);
chk('la consulta con datos exactos (por formulario) encuentra el documento', $r['http'] === 200 && strpos($r['cuerpo'], 'Documento válido') !== false);
$r = web('/verificar', array_merge($campos, array('total' => '118,00')));
chk('...acepta la coma decimal (118,00)', strpos($r['cuerpo'], 'Documento válido') !== false);
$r = web('/verificar', array_merge($campos, array('total' => '118.01')));
chk('con un dato que no coincide -> "No encontramos" (igual que si no existiera)', strpos($r['cuerpo'], 'No encontramos un documento') !== false && $r['cuerpo'] !== '');
foreach (array(array('ruc' => '123'), array('tipo' => '99'), array('serie' => '<b>'), array('numero' => 'abc'), array('fecha' => '13/11/2026'), array('fecha' => '2026-02-31'), array('total' => 'abc')) as $malo) {
    $r = web('/verificar', array_merge($campos, $malo));
    if ($r['http'] !== 422 || strpos($r['cuerpo'], 'Revise los datos') === false) {
        chk('datos mal escritos (' . key($malo) . ') -> 422 con ayuda', false, 'HTTP ' . $r['http']);
        $malo = null;
        break;
    }
}
chk('RUC corto, tipo no permitido, serie con HTML, numero con letras, fecha mal escrita o imposible, total con texto -> 422 con ayuda', $malo !== null);
$r = web('/verificar', array_merge($campos, array('serie' => '"><script>alert(1)</script>')));
chk('un valor malicioso en el formulario no se refleja sin escapar', strpos($r['cuerpo'], '<script>alert(1)') === false);
$r = http('POST', $WEB . '/verificar', $campos, array('Origin: http://sitio-malicioso.example'));
chk('un envio con Origin de otro sitio -> 403', $r['http'] === 403);
$r = web('/verificar/' . enlace($RUC, '01', 'F001', 150, 'live') . '/qr');
chk('/qr devuelve una imagen PNG valida (cabecera \\x89PNG)', $r['http'] === 200 && strpos($r['cab']['content-type'], 'image/png') === 0 && substr($r['cuerpo'], 1, 3) === 'PNG');
chk('/qr de un codigo incorrecto -> 404 sin contenido', web('/verificar/' . $RUC . '-01-F001-150-AAAAAAAAAAAA/qr')['http'] === 404);
chk('robots.txt pide no indexar /verificar', strpos(web('/robots.txt')['cuerpo'], 'Disallow: /verificar') !== false);
chk('el menu y el pie de la portada enlazan a "Verificar documento"', strpos(web('/')['cuerpo'], 'Verificar documento') !== false && strpos(web('/')['cuerpo'], 'Verificar un documento') !== false);
chk('la pagina de verificacion tiene el mismo menu de la portada', strpos(web('/verificar')['cuerpo'], 'Verificar documento') !== false && strpos(web('/verificar')['cuerpo'], '#caracteristicas') !== false);
chk('una ruta de verificacion invalida -> 404 (no la portada)', web('/verificar/x')['http'] === 404);

echo "\n== 6) API publica (sin llave)\n";
$slug = enlace($RUC, '01', 'F001', 150, 'live');
$r = http('GET', $API . '/verificar/' . $slug);
$dj = isset($r['json']['data']) ? $r['json']['data'] : array();
chk('GET /v1/verificar/{codigo} responde sin API key con el documento', $r['http'] === 200 && $r['json']['ok'] === true && $dj['valido'] === true && $dj['total'] === '118.00' && $dj['estado'] === 'aceptado');
chk('...con CORS abierto (se puede consultar desde un navegador) y X-Request-Id', isset($r['cab']['access-control-allow-origin']) && $r['cab']['access-control-allow-origin'] === '*' && isset($r['cab']['x-request-id']));
chk('...y no devuelve datos del comprador', !preg_match('/cliente|comprador|adquirente|numero_documento/i', $r['cuerpo']));
$r = http('GET', $API . '/verificar/' . $RUC . '-01-F001-150-AAAAAAAAAAAA');
$r2 = http('GET', $API . '/verificar/' . enlace($RUCS[1], '01', 'F001', 1, 'live'));
chk('codigo incorrecto y empresa que no habilita la verificacion -> el mismo 404 "no_encontrado"', $r['http'] === 404 && $r2['http'] === 404 && $r['json']['error']['code'] === 'no_encontrado' && $r['json']['error']['message'] === $r2['json']['error']['message']);
$r = http('POST', $API . '/verificar', json_encode(array('ruc' => $RUC, 'tipo' => '01', 'serie' => 'F001', 'numero' => '150', 'fecha_emision' => $hoy, 'total' => 118)), array('Content-Type: application/json'));
chk('POST /v1/verificar con los datos del documento lo encuentra', $r['http'] === 200 && $r['json']['data']['valido'] === true);
$r = http('POST', $API . '/verificar', json_encode(array('ruc' => $RUC, 'tipo' => '01', 'serie' => 'F001', 'numero' => '150', 'fecha_emision' => $hoy, 'total' => 119)), array('Content-Type: application/json'));
chk('...con un total distinto -> 404', $r['http'] === 404);
$r = http('POST', $API . '/verificar', '{no es json', array('Content-Type: application/json'));
chk('un cuerpo que no es JSON -> 400, no un error 500', $r['http'] === 400);
chk('metodos equivocados -> 405 con la cabecera Allow', http('POST', $API . '/verificar/' . $slug)['http'] === 405 && http('GET', $API . '/verificar')['http'] === 405);
$r = http('GET', $API . '/verificar/' . $slug . '/qr');
chk('GET /v1/verificar/{codigo}/qr devuelve la imagen PNG del enlace', $r['http'] === 200 && substr($r['cuerpo'], 1, 3) === 'PNG');
chk('el resto de la API sigue exigiendo llave (/v1/me sin llave -> 401)', http('GET', $API . '/me')['http'] === 401);

echo "\n== 7) Los documentos de la API traen su enlace de verificacion\n";
$k = (string) shell_exec('php bin/admin.php key:crear --ruc=' . $RUC . ' --entorno=test --nombre=lab 2>&1');
preg_match('/sk_test_[a-f0-9]{40}/', $k, $mk);
$llave = isset($mk[0]) ? $mk[0] : '';
$auth = array('Authorization: Bearer ' . $llave);
$r = http('GET', $API . '/documentos/' . $d1, null, $auth);
$v = isset($r['json']['data']['verificacion']) ? $r['json']['data']['verificacion'] : null;
chk('GET /v1/documentos/{id} incluye verificacion: codigo, url y qr', $llave !== '' && $r['http'] === 200 && $v && $v['codigo'] === $slug && strpos($v['url'], '/verificar/' . $slug) !== false && strpos($v['qr'], '/v1/verificar/' . $slug . '/qr') !== false);
chk('...y la url apunta a la pagina publica que de verdad responde', $v && http('GET', $v['url'])['http'] === 200);
$r = http('GET', $API . '/documentos?limit=50', null, $auth);
$conEnlace = 0;
foreach ((array) $r['json']['data']['items'] as $it) {
    $conEnlace += !empty($it['verificacion']['codigo']) ? 1 : 0;
}
chk('el listado (del entorno de la llave: pruebas) tambien incluye el enlace en cada documento verificable', $conEnlace >= 1 && $conEnlace === count((array) $r['json']['data']['items']), "con enlace: $conEnlace");
$dRc = nuevoDoc($idA, 'RC', '20261113', 1, 'test', 'pendiente', null, null);
$r = http('GET', $API . '/documentos/' . $dRc, null, $auth);
chk('un resumen diario (no verificable) NO lleva enlace (verificacion = null)', $r['http'] === 200 && array_key_exists('verificacion', $r['json']['data']) && $r['json']['data']['verificacion'] === null);
// el PDF de la API es VIRTUAL (se genera a pedido): se anuncia solo en documentos aceptados de tipo 01/03/07/08
$r = http('GET', $API . '/documentos/' . $d2, null, $auth);
chk('un documento aceptado de tipo 01 anuncia archivos.pdf (direccion del endpoint de la API); uno rechazado o una guia, no', $r['http'] === 200 && isset(((array) $r['json']['data']['archivos'])['pdf']) && strpos($r['json']['data']['archivos']['pdf'], '/documentos/' . $d2 . '/pdf') !== false
    && !isset(((array) http('GET', $API . '/documentos/' . $d3, null, $auth)['json']['data']['archivos'])['pdf']));

echo "\n== 8) El cliente decide desde su portal\n";
$tmp = preg_match('/^\s{2}(\S{14})\s*$/m', (string) shell_exec('php bin/admin.php usuario:crear --ruc=' . $RUC . ' --email=ver@lab-verif.test --nombre="Cli V" --rol=admin 2>&1'), $mm) ? $mm[1] : null;
$tmpL = preg_match('/^\s{2}(\S{14})\s*$/m', (string) shell_exec('php bin/admin.php usuario:crear --ruc=' . $RUC . ' --email=lec@lab-verif.test --nombre="Lec V" --rol=lector 2>&1'), $ml) ? $ml[1] : null;
$ck = array();
function csrf(&$ck, $ruta)
{
    global $POU;
    return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', http('GET', $POU . $ruta, null, array(), $ck)['cuerpo'], $m) ? $m[1] : '';
}
function portalPost(&$ck, $ruta, $campos, $desde)
{
    global $POU;
    return http('POST', $POU . $ruta, array_merge(array('_csrf' => csrf($ck, $desde)), $campos), array(), $ck);
}
portalPost($ck, '/login', array('email' => 'ver@lab-verif.test', 'clave' => (string) $tmp), '/login');
portalPost($ck, '/cuenta/clave', array('clave_actual' => (string) $tmp, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
$pd = http('GET', $POU . '/documentos/' . $d1, null, array(), $ck);
Db::ejecutar("UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL");
// el portal esta en modo "pruebas" por defecto: el documento de produccion no se ve; se pasa a produccion
portalPost($ck, '/entorno', array('entorno' => 'live', 'volver' => '/'), '/');
$pd = http('GET', $POU . '/documentos/' . $d1, null, array(), $ck);
chk('el detalle del documento en el portal muestra su enlace, el boton "Copiar" y el codigo QR', $pd['http'] === 200 && strpos($pd['cuerpo'], 'Verificación pública') !== false && strpos($pd['cuerpo'], $slug) !== false
    && strpos($pd['cuerpo'], 'data-copiar="#verif-url"') !== false && strpos($pd['cuerpo'], 'src="data:image/png;base64,') !== false);
$cu = http('GET', $POU . '/cuenta', null, array(), $ck);
chk('"Mi cuenta" muestra que la verificacion esta ACTIVADA', strpos($cu['cuerpo'], 'ACTIVADA') !== false && strpos($cu['cuerpo'], 'Desactivar verificación pública') !== false);
portalPost($ck, '/cuenta/verificacion', array('activa' => '0', 'clave_actual' => 'contrasena-equivocada-1'), '/cuenta');
chk('desactivar con la contrasena equivocada -> no cambia nada', (int) Db::uno('SELECT verificacion_publica AS v FROM empresas WHERE id = ?', array($idA))['v'] === 1);
Db::ejecutar("UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL");
portalPost($ck, '/cuenta/verificacion', array('activa' => '0', 'clave_actual' => $CLAVE), '/cuenta');
chk('con la contrasena correcta se DESACTIVA', (int) Db::uno('SELECT verificacion_publica AS v FROM empresas WHERE id = ?', array($idA))['v'] === 0);
chk('...y de inmediato la pagina publica, la API y el QR dejan de mostrar nada (404 / "No encontramos")', strpos(web($u1)['cuerpo'], 'No encontramos un documento') !== false && http('GET', $API . '/verificar/' . $slug)['http'] === 404
    && web($u1 . '/qr')['http'] === 404 && strpos(web('/verificar', $campos)['cuerpo'], 'No encontramos un documento') !== false);
$pd = http('GET', $POU . '/documentos/' . $d1, null, array(), $ck);
chk('el portal avisa que esta desactivada', strpos($pd['cuerpo'], 'desactivada') !== false && strpos($pd['cuerpo'], 'src="data:image/png') === false);
chk('el cambio queda en la actividad de la empresa', (int) Db::uno("SELECT COUNT(*) AS n FROM auditoria WHERE empresa_id = ? AND accion = 'verificacion_desactivada'", array($idA))['n'] === 1);
portalPost($ck, '/cuenta/verificacion', array('activa' => '1', 'clave_actual' => $CLAVE), '/cuenta');
chk('al reactivarla vuelve a funcionar', strpos(web($u1)['cuerpo'], 'Documento válido') !== false);
$ckl = array();
portalPost($ckl, '/login', array('email' => 'lec@lab-verif.test', 'clave' => (string) $tmpL), '/login');
portalPost($ckl, '/cuenta/clave', array('clave_actual' => (string) $tmpL, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
chk('un usuario "lector" no puede cambiar la verificacion (403)', portalPost($ckl, '/cuenta/verificacion', array('activa' => '0', 'clave_actual' => $CLAVE), '/cuenta')['http'] === 403 && (int) Db::uno('SELECT verificacion_publica AS v FROM empresas WHERE id = ?', array($idA))['v'] === 1);

echo "\n== 9) Limites contra abuso\n";
$LIBRE = false;
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'verif:%'");
$ultimo = null;
$limitadoEn = 0;
for ($i = 1; $i <= 64; $i++) {
    $ultimo = http('GET', $WEB . '/verificar/' . $RUC . '-01-F001-' . $i . '-AAAAAAAAAAAA');
    if (strpos($ultimo['cuerpo'], 'Demasiadas consultas') !== false && $limitadoEn === 0) {
        $limitadoEn = $i;
    }
}
chk('tras 60 consultas de enlaces desde una IP se frena (la 61 ya dice "Demasiadas consultas")', $limitadoEn === 61, "se freno en $limitadoEn");
require_once 'v1/src/Planes.php';
Planes::limpiar();
chk('el limite sobrevive a la limpieza del worker (Planes::limpiar)', strpos(http('GET', $WEB . '/verificar/' . $RUC . '-01-F001-5-AAAAAAAAAAAA')['cuerpo'], 'Demasiadas consultas') !== false);
$r = http('GET', $API . '/verificar/' . $slug);
chk('el limite de la web NO bloquea a la API (contadores separados)', $r['http'] === 200);
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'verif:%'");
$api429 = 0;
for ($i = 1; $i <= 63; $i++) {
    $r = http('GET', $API . '/verificar/' . $RUC . '-01-F001-' . $i . '-AAAAAAAAAAAA');
    if ($r['http'] === 429 && $api429 === 0) {
        $api429 = $i;
        $retry = isset($r['cab']['retry-after']) ? $r['cab']['retry-after'] : null;
        $cod = $r['json']['error']['code'];
    }
}
chk('la API publica tambien se frena a la consulta 61 (429 limite_de_peticiones + Retry-After)', $api429 === 61 && $retry === '600' && $cod === 'limite_de_peticiones', "se freno en $api429");
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'verif:%'");
$LIBRE = true;
for ($i = 1; $i <= 22; $i++) {
    $r = http('POST', $WEB . '/verificar', array_merge($campos, array('numero' => (string) (1000 + $i))));
    $LIBRE = false;
}
chk('la consulta con datos (formulario) se frena a la 21 (limite mas estricto: 20 por 10 minutos)', $r['http'] === 429 && strpos($r['cuerpo'], 'Demasiadas consultas') !== false);
$LIBRE = true;
Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'verif:%'");

echo "\n== 10) De punta a punta con SUNAT beta (emision real de prueba)\n";
if ($KEY === null) {
    echo "  (omitido: ejecute con --key=sk_test_... para probarlo)\n";
} else {
    $A = array('Authorization: Bearer ' . $KEY, 'Content-Type: application/json');
    $c = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $serieE = 'B' . $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)] . $c[mt_rand(0, 31)];
    $me = http('GET', $API . '/me', null, array('Authorization: Bearer ' . $KEY));
    $rucE = isset($me['json']['data']['ruc']) ? $me['json']['data']['ruc'] : '';
    http('POST', $API . '/series', json_encode(array('tipo' => '03', 'serie' => $serieE, 'modo' => 'api')), $A);
    $cuerpo = array('cliente' => array('codigo_tipo_entidad' => '1', 'numero_documento' => '12345678', 'razon_social_nombres' => 'JUAN PEREZ'),
        'venta' => array('tipo_documento_codigo' => '03', 'serie' => $serieE, 'fecha_emision' => $hoy, 'hora_emision' => '10:00:00', 'moneda_id' => 1, 'forma_pago_id' => 1, 'total_gravada' => 84.75, 'total_igv' => 15.25),
        'items' => array(array('producto' => 'PRODUCTO', 'codigo_sunat' => '50202201', 'cantidad' => 1, 'codigo_unidad' => 'NIU', 'precio_base' => 84.75, 'tipo_igv_codigo' => '10')));
    $r = http('POST', $API . '/comprobantes', json_encode($cuerpo), array_merge($A, array('Idempotency-Key: verif-' . bin2hex(random_bytes(6)))));
    $dd = isset($r['json']['data']) ? $r['json']['data'] : array();
    chk('la boleta se emite y SUNAT beta la acepta (201, aceptado)', $r['http'] === 201 && isset($dd['estado']) && $dd['estado'] === 'aceptado', 'HTTP ' . $r['http'] . ' ' . substr($r['cuerpo'], 0, 160));
    $ver = isset($dd['verificacion']) ? $dd['verificacion'] : null;
    chk('la respuesta de la emision YA trae el enlace de verificacion (codigo, url, qr)', $ver && !empty($ver['codigo']) && strpos($ver['url'], '/verificar/') !== false && strpos($ver['qr'], '/qr') !== false);
    $fila = Db::uno('SELECT * FROM documentos WHERE uid = ?', array(isset($dd['id']) ? $dd['id'] : ''));
    chk('se guardaron fecha, total y moneda leidos del XML firmado (S/ 100.00)', $fila && $fila['fecha_emision'] === $hoy && (int) $fila['total'] === 10000 && $fila['moneda'] === 'PEN', json_encode($fila ? array($fila['fecha_emision'], $fila['total'], $fila['moneda']) : null));
    chk('el codigo de la respuesta coincide con el que calcula el sistema', $ver && $fila && $ver['codigo'] === Verificacion::slug($rucE, '03', $serieE, (int) $fila['numero_n'], 'test'));
    $pagina = $ver ? http('GET', $ver['url']) : array('cuerpo' => '', 'http' => 0);
    chk('el enlace de verificacion de la respuesta abre la pagina publica: "Documento de PRUEBA"', $pagina['http'] === 200 && strpos($pagina['cuerpo'], 'Documento de PRUEBA') !== false && strpos($pagina['cuerpo'], 'S/ 100.00') !== false);
    $r = http('POST', $API . '/verificar', json_encode(array('ruc' => $rucE, 'tipo' => '03', 'serie' => $serieE, 'numero' => (string) (int) $fila['numero_n'], 'fecha_emision' => $hoy, 'total' => '100.00')), array('Content-Type: application/json'));
    chk('la consulta publica con los datos del documento recien emitido tambien lo encuentra', $r['http'] === 200 && $r['json']['data']['estado'] === 'aceptado');
    // La API no genera PDF: no debe anunciar uno que no existe
    chk('la respuesta de la emision anuncia el PDF de la API (se genera a pedido) y trae el XML y el CDR', isset($dd['archivos']['pdf']) && strpos($dd['archivos']['pdf'], '/documentos/' . $dd['id'] . '/pdf') !== false && isset($dd['archivos']['xml']) && isset($dd['archivos']['cdr']));
    chk('el XML y el CDR que SI anuncia existen de verdad (HTTP 200)', http('GET', $dd['archivos']['xml'])['http'] === 200 && http('GET', $dd['archivos']['cdr'])['http'] === 200);
}

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
