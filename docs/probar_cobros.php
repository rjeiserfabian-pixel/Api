<?php
/**
 * Prueba de COBROS: dinero, fechas, pagos, prueba gratis, vencimientos, avisos y las pantallas del operador y del portal.
 *
 *   php docs/probar_cobros.php
 *
 * Crea empresas, planes y un operador de laboratorio, y los borra al terminar (tambien restaura los ajustes). Con Apache encendido.
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
foreach (array('Usuarios', 'Suscripciones', 'Cobros') as $c) {
    require_once 'portal/src/' . $c . '.php';
}
$BASE = 'http://localhost/API_SUNAT';
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--base=(.*)$/', $a, $m)) {
        $BASE = rtrim($m[1], '/');
    }
}
$OPU = $BASE . '/operador';
$POU = $BASE . '/portal';
$WEB = $BASE . '/web';
$CLAVE = 'Cl4ve-Segura-2026!';
$RUCS = array('20000000021', '20000000022', '20000000023', '20000000024');
Db::asegurarEsquema();
foreach ($RUCS as $r) {
    if (Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($r))) {
        fwrite(STDERR, "ABORTADO: ya existe la empresa $r (de una corrida interrumpida). Borrela antes de repetir.\n");
        exit(3);
    }
}
$ajustesOriginales = Db::consultar('SELECT clave, valor FROM ajustes');
$limpiar = function () use ($RUCS, $ajustesOriginales) {
    try {
        foreach ($RUCS as $ruc) {
            $e = Db::uno('SELECT id FROM empresas WHERE ruc = ?', array($ruc));
            if (!$e) {
                continue;
            }
            foreach (array('suscripciones', 'pagos', 'solicitudes_plan', 'usuarios_portal', 'auditoria', 'api_keys', 'uso') as $t) {
                Db::ejecutar("DELETE FROM $t WHERE empresa_id = ?", array((int) $e['id']));
            }
            Db::ejecutar('DELETE FROM empresas WHERE id = ?', array((int) $e['id']));
        }
        Db::ejecutar("DELETE FROM planes WHERE nombre LIKE 'LabC%'");
        Db::ejecutar("DELETE FROM operadores WHERE email LIKE '%@lab-cobros.test'");
        Db::ejecutar("DELETE FROM auditoria WHERE empresa_id = 0 AND detalle LIKE '%lab-cobros.test%'");
        Db::ejecutar("DELETE FROM limites WHERE clave LIKE 'login:%' OR clave LIKE 'oplogin:%'");
        Db::ejecutar('DELETE FROM ajustes');
        foreach ($ajustesOriginales as $a) {
            Db::ejecutar('INSERT INTO ajustes (clave, valor) VALUES (?, ?)', array($a['clave'], $a['valor']));
        }
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
        if ($d === '' && !empty($GLOBALS['ULT'])) {
            echo '         (ultima respuesta: ' . substr($GLOBALS['ULT'], 0, 300) . ")\n";
        }
    }
    return (bool) $c;
}
function falla(callable $f)
{
    try {
        $f();
    } catch (InvalidArgumentException $e) {
        return true;
    }
    return false;
}
function nuevaEmpresa($ruc, $razon)
{
    return (int) Db::ejecutar('INSERT INTO empresas (ruc, razon_social, nombre_comercial, domicilio_fiscal, ubigeo, departamento, provincia, distrito, activo, creado) VALUES (?,?,?,?,?,?,?,?,1,?)',
        array($ruc, $razon, 'LAB', 'AV. LAB 1', '150101', 'LIMA', 'LIMA', 'LIMA', Db::ahora()));
}
function nuevoPlan($nombre, $precio, $desc, $prueba, $extra = array())
{
    return (int) Db::ejecutar('INSERT INTO planes (nombre, cuota_mensual, limite_sunat_min, limite_general_min, max_webhooks, autoservicio, activo, publico, precio_mensual, descuento_anual, dias_prueba, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        array($nombre, isset($extra['cuota']) ? $extra['cuota'] : 100, 10, 50, 2, 0, isset($extra['activo']) ? $extra['activo'] : 1, 1, $precio, $desc, $prueba, Db::ahora()));
}
function planDeEmpresa($id)
{
    $e = Db::uno('SELECT plan_id FROM empresas WHERE id = ?', array($id));
    return $e && $e['plan_id'] ? (int) $e['plan_id'] : 0;
}
function sus($id)
{
    return Db::uno('SELECT * FROM suscripciones WHERE empresa_id = ?', array($id));
}
function cuantosPagos($id)
{
    return (int) Db::uno('SELECT COUNT(*) AS n FROM pagos WHERE empresa_id = ?', array($id))['n'];
}
function ponerVence($id, $vence, $estado = null, $gracia = null)
{
    Db::ejecutar('UPDATE suscripciones SET vence = ?, estado = COALESCE(?, estado), gracia_hasta = ? WHERE empresa_id = ?', array($vence, $estado, $gracia, $id));
}
function ymd($f)
{
    return substr($f, 0, 10);
}

echo "Prueba de cobros\n";

echo "\n== 1) Dinero\n";
$casos = array('99.90' => 9990, '99,90' => 9990, '99' => 9900, '0' => 0, '0.5' => 50, '1,234.50' => 123450, ' 12 ' => 1200, '999999.99' => 99999999, '1000000' => null,
    '-5' => null, 'abc' => null, '12.345' => null, '' => null, '1e3' => null, '12..3' => null, '$5' => null, '1.234,50' => null);
$bien = true;
foreach ($casos as $in => $esp) {
    if (Dinero::aCentimos($in) !== $esp) {
        $bien = false;
        echo "      '$in' -> " . var_export(Dinero::aCentimos($in), true) . " (esperado " . var_export($esp, true) . ")\n";
    }
}
chk('se interpretan bien 99.90, 99,90, 1,234.50... y se rechazan negativos, letras, 3 decimales y topes', $bien);
chk('formato en soles: S/ 99.90, S/ 1,234.50, S/ 0.05', Dinero::formato(9990) === 'S/ 99.90' && Dinero::formato(123450) === 'S/ 1,234.50' && Dinero::formato(5) === 'S/ 0.05');
chk('precio anual con descuento: 12 x S/100 con 20 % = S/ 960', Dinero::anual(10000, 20) === 96000 && Dinero::anual(9900, 0) === 118800);
$p = Cobros::precios(array('precio_mensual' => 10000, 'descuento_anual' => 20));
chk('ahorro anual = S/ 240 (2 meses gratis)', $p['mensual'] === 10000 && $p['anual'] === 96000 && $p['ahorro'] === 24000 && $p['ahorro_pct'] === 20);
chk('un plan sin precio devuelve null (se mostrara "Consultar")', Cobros::precios(array('precio_mensual' => null, 'descuento_anual' => 10))['mensual'] === null);

echo "\n== 2) Fechas\n";
chk('31 de enero + 1 mes = 28 de febrero (no salta a marzo)', ymd(Cobros::sumarMeses('2026-01-31 10:00:00', 1)) === '2026-02-28');
chk('31 de enero + 1 mes en ano bisiesto = 29 de febrero', ymd(Cobros::sumarMeses('2028-01-31 10:00:00', 1)) === '2028-02-29');
chk('+12 meses = mismo dia del ano siguiente y conserva la hora', Cobros::sumarMeses('2026-11-13 14:30:15', 12) === '2027-11-13 14:30:15');
chk('31 de diciembre + 2 meses = 28 de febrero', ymd(Cobros::sumarMeses('2026-12-31 00:00:00', 2)) === '2027-02-28');
chk('sumar dias', ymd(Cobros::sumarDias('2026-02-27 08:00:00', 3)) === '2026-03-02');

echo "\n== 3) Ajustes (configurables)\n";
Db::ejecutar('DELETE FROM ajustes');
chk('sin nada guardado rigen los valores por defecto (5 dias de gracia, aviso a 7 dias)', Ajustes::dias('dias_gracia') === 5 && Ajustes::dias('dias_aviso') === 7 && Cobros::planAlVencer() === null);
Ajustes::set('dias_gracia', '3');
chk('un ajuste guardado se lee', Ajustes::dias('dias_gracia') === 3);
chk('un ajuste desconocido se rechaza', falla(function () {
    Ajustes::set('clave_inventada', 'x');
}));
Ajustes::set('dias_gracia', '5');

echo "\n== 4) Pagos y suscripcion\n";
$idA = nuevaEmpresa($RUCS[0], 'LAB COBROS A');
$idB = nuevaEmpresa($RUCS[1], 'LAB COBROS B');
$pro = nuevoPlan('LabCPro', 9900, 17, 14);
$base = nuevoPlan('LabCBase', 0, 0, 0, array('cuota' => 0));
$otro = nuevoPlan('LabCOtro', 29900, 10, 0);
$inac = nuevoPlan('LabCInactivo', 100, 0, 0, array('activo' => 0));
$r = Cobros::registrarPago($idA, $pro, 'mensual', 9900, 'transferencia', 'OP-0001', 'primer pago', 'op@lab-cobros.test');
$s = sus($idA);
chk('un pago mensual deja la suscripcion ACTIVA con el plan asignado', !$r['duplicado'] && $s['estado'] === 'activa' && (int) $s['plan_id'] === $pro && planDeEmpresa($idA) === $pro && $s['periodo'] === 'mensual');
chk('...y vence en exactamente 1 mes', ymd($s['vence']) === ymd(Cobros::sumarMeses(Db::ahora(), 1)));
$vence1 = $s['vence'];
$r2 = Cobros::registrarPago($idA, $pro, 'mensual', 9900, 'transferencia', 'OP-0001', '', 'op@lab-cobros.test');
chk('el MISMO pago (metodo + referencia) enviado dos veces no se duplica ni extiende', $r2['duplicado'] === true && cuantosPagos($idA) === 1 && sus($idA)['vence'] === $vence1);
$r3 = Cobros::registrarPago($idA, $pro, 'mensual', 9900, 'transferencia', 'OP-0002', '', 'op@lab-cobros.test');
chk('renovar a tiempo SUMA desde el vencimiento anterior (no se pierden ni se regalan dias)', sus($idA)['vence'] === Cobros::sumarMeses($vence1, 1) && cuantosPagos($idA) === 2);
$r4 = Cobros::registrarPago($idA, $pro, 'anual', 98604, 'yape_plin', null, '', 'op@lab-cobros.test');
chk('un pago ANUAL suma 12 meses mas', sus($idA)['vence'] === Cobros::sumarMeses(Cobros::sumarMeses($vence1, 1), 12) && sus($idA)['periodo'] === 'anual');
$r5 = Cobros::registrarPago($idA, $pro, 'mensual', 9900, 'yape_plin', null, '', 'op@lab-cobros.test');
$r6 = Cobros::registrarPago($idA, $pro, 'mensual', 9900, 'yape_plin', null, '', 'op@lab-cobros.test');
chk('sin referencia no hay deduplicacion (dos pagos distintos del mismo monto son dos pagos)', cuantosPagos($idA) === 5);
$antes = sus($idA)['vence'];
Cobros::registrarPago($idA, $otro, 'mensual', 29900, 'transferencia', 'OP-0003', '', 'op@lab-cobros.test');
chk('pagar OTRO plan empieza ahora (no suma al vencimiento del plan anterior) y cambia el plan', planDeEmpresa($idA) === $otro && ymd(sus($idA)['vence']) === ymd(Cobros::sumarMeses(Db::ahora(), 1)));
chk('el historial guarda monto, metodo, referencia, quien lo registro y el vencimiento resultante', (function () use ($idA) {
    $p = Db::uno("SELECT * FROM pagos WHERE referencia = 'OP-0001'");
    return $p && (int) $p['monto'] === 9900 && $p['metodo'] === 'transferencia' && $p['registrado_por'] === 'op@lab-cobros.test' && $p['vence_resultante'] !== null && $p['moneda'] === 'PEN';
})());
$n0 = cuantosPagos($idB);
chk('monto cero o negativo se rechaza', falla(function () use ($idB, $pro) { Cobros::registrarPago($idB, $pro, 'mensual', 0, 'otro', null, '', 'x'); }) && falla(function () use ($idB, $pro) { Cobros::registrarPago($idB, $pro, 'mensual', -5, 'otro', null, '', 'x'); }));
chk('periodo "semanal" se rechaza', falla(function () use ($idB, $pro) { Cobros::registrarPago($idB, $pro, 'semanal', 100, 'otro', null, '', 'x'); }));
chk('metodo con espacios o simbolos se rechaza', falla(function () use ($idB, $pro) { Cobros::registrarPago($idB, $pro, 'mensual', 100, "<b>x</b>", null, '', 'x'); }));
chk('referencia o nota demasiado largas, o con saltos de linea, se rechazan', falla(function () use ($idB, $pro) { Cobros::registrarPago($idB, $pro, 'mensual', 100, 'otro', str_repeat('A', 81), '', 'x'); })
    && falla(function () use ($idB, $pro) { Cobros::registrarPago($idB, $pro, 'mensual', 100, 'otro', "a\nb", '', 'x'); }) && falla(function () use ($idB, $pro) { Cobros::registrarPago($idB, $pro, 'mensual', 100, 'otro', null, str_repeat('N', 201), 'x'); }));
chk('un plan inactivo o inexistente, o una empresa inexistente, se rechazan', falla(function () use ($idB, $inac) { Cobros::registrarPago($idB, $inac, 'mensual', 100, 'otro', null, '', 'x'); })
    && falla(function () use ($idB) { Cobros::registrarPago($idB, 99999999, 'mensual', 100, 'otro', null, '', 'x'); }) && falla(function () use ($pro) { Cobros::registrarPago(99999999, $pro, 'mensual', 100, 'otro', null, '', 'x'); }));
chk('ningun pago invalido dejo rastro (ni pago ni suscripcion)', cuantosPagos($idB) === $n0 && sus($idB) === null);
// solicitud pendiente se cierra al pagar
Db::ejecutar("INSERT INTO solicitudes_plan (empresa_id, usuario_id, plan_id, estado, nota, periodo, creado) VALUES (?,?,?,?,?,?,?)", array($idB, null, $pro, 'pendiente', '', 'anual', Db::ahora()));
Cobros::registrarPago($idB, $pro, 'anual', 98604, 'transferencia', 'OP-B1', '', 'x');
chk('registrar el pago cierra la solicitud pendiente de ese plan (aprobada)', Db::uno("SELECT estado FROM solicitudes_plan WHERE empresa_id = ?", array($idB))['estado'] === 'aprobada');
chk('el pago queda en la actividad de la empresa', (int) Db::uno("SELECT COUNT(*) AS n FROM auditoria WHERE empresa_id = ? AND accion = 'pago_registrado'", array($idB))['n'] === 1);

echo "\n== 5) Prueba gratis\n";
$idC = nuevaEmpresa($RUCS[2], 'LAB COBROS C');
chk('un plan con dias de prueba permite probar; uno sin dias, no', Cobros::puedeProbar($idC, Db::uno('SELECT * FROM planes WHERE id = ?', array($pro))) && !Cobros::puedeProbar($idC, Db::uno('SELECT * FROM planes WHERE id = ?', array($otro))));
chk('un plan INACTIVO no permite probar', !Cobros::puedeProbar($idC, array('dias_prueba' => 10, 'activo' => 0)));
$t = Cobros::iniciarPrueba($idC, $pro, null, false, 'cliente');
$s = sus($idC);
chk('iniciar la prueba: estado "prueba", plan asignado, vence en 14 dias (los del plan)', $s['estado'] === 'prueba' && planDeEmpresa($idC) === $pro && $t['dias'] === 14 && ymd($s['vence']) === ymd(Cobros::sumarDias(Db::ahora(), 14)) && (int) $s['prueba_usada'] === 1);
chk('una empresa NO puede repetir su prueba gratis', falla(function () use ($idC, $pro) { Cobros::iniciarPrueba($idC, $pro, null, false, 'cliente'); }) && !Cobros::puedeProbar($idC, Db::uno('SELECT * FROM planes WHERE id = ?', array($pro))));
chk('dias de prueba fuera de rango (0, 400) se rechazan', falla(function () use ($idC, $pro) { Cobros::iniciarPrueba($idC, $pro, 0, true, 'op'); }) && falla(function () use ($idC, $pro) { Cobros::iniciarPrueba($idC, $pro, 400, true, 'op'); }));
$t = Cobros::iniciarPrueba($idC, $otro, 5, true, 'op');
chk('el OPERADOR si puede repetirla y fijar los dias (5), incluso en un plan sin prueba propia', $t['dias'] === 5 && planDeEmpresa($idC) === $otro && sus($idC)['estado'] === 'prueba');
chk('...pero nunca pisa una suscripcion PAGADA vigente', falla(function () use ($idA, $pro) { Cobros::iniciarPrueba($idA, $pro, 5, true, 'op'); }));

echo "\n== 6) Vencimientos\n";
Ajustes::set('plan_vencido_id', (string) $base);
Ajustes::set('dias_gracia', '5');
$ayer = date('Y-m-d H:i:s', time() - 86400);
ponerVence($idC, $ayer);
$n = Cobros::procesarVencimientos();
chk('una PRUEBA vencida pasa a "vencida" (sin gracia) y la empresa baja al plan de destino', sus($idC)['estado'] === 'vencida' && planDeEmpresa($idC) === $base && $n >= 1);
chk('...y consta en su actividad', (int) Db::uno("SELECT COUNT(*) AS n FROM auditoria WHERE empresa_id = ? AND accion = 'prueba_terminada'", array($idC))['n'] === 1);
chk('ejecutarlo otra vez no hace nada mas (idempotente; varios workers no se pisan)', Cobros::procesarVencimientos() === 0 && (int) Db::uno("SELECT COUNT(*) AS n FROM auditoria WHERE empresa_id = ? AND accion = 'prueba_terminada'", array($idC))['n'] === 1);
// suscripcion pagada vencida -> gracia
ponerVence($idA, $ayer);
Cobros::procesarVencimientos();
$s = sus($idA);
chk('una suscripcion PAGADA vencida entra en GRACIA (5 dias) y conserva su plan', $s['estado'] === 'gracia' && planDeEmpresa($idA) === $otro && ymd($s['gracia_hasta']) === ymd(Cobros::sumarDias($ayer, 5)));
$a = Cobros::aviso($idA);
chk('en gracia el aviso es una ALERTA con la fecha limite y el plan al que pasara', $a['tipo'] === 'alerta' && strpos($a['texto'], 'Puede renovar hasta') !== false && strpos($a['texto'], 'LabCBase') !== false);
// pagar durante la gracia
$venceViejo = sus($idA)['vence'];
Cobros::registrarPago($idA, $otro, 'mensual', 29900, 'transferencia', 'OP-GRACIA', '', 'x');
$s = sus($idA);
chk('pagar DENTRO de la gracia reactiva y suma desde el vencimiento anterior (no regala dias)', $s['estado'] === 'activa' && $s['gracia_hasta'] === null && $s['vence'] === Cobros::sumarMeses($venceViejo, 1));
// gracia agotada
ponerVence($idA, date('Y-m-d H:i:s', time() - 10 * 86400), 'gracia', date('Y-m-d H:i:s', time() - 86400));
Cobros::procesarVencimientos();
chk('terminada la gracia: "vencida" y baja al plan de destino', sus($idA)['estado'] === 'vencida' && planDeEmpresa($idA) === $base);
$a = Cobros::aviso($idA);
chk('el aviso dice que esta en el plan de destino y que renueve', $a['tipo'] === 'alerta' && strpos($a['texto'], 'LabCBase') !== false && strpos($a['texto'], 'Renueve') !== false);
Cobros::registrarPago($idA, $otro, 'mensual', 29900, 'transferencia', 'OP-REVIVE', '', 'x');
chk('pagar estando VENCIDA reactiva desde AHORA y devuelve el plan contratado', sus($idA)['estado'] === 'activa' && planDeEmpresa($idA) === $otro && ymd(sus($idA)['vence']) === ymd(Cobros::sumarMeses(Db::ahora(), 1)));
// sin dias de gracia
Ajustes::set('dias_gracia', '0');
ponerVence($idA, $ayer);
Cobros::procesarVencimientos();
chk('con 0 dias de gracia pasa directo a "vencida"', sus($idA)['estado'] === 'vencida' && planDeEmpresa($idA) === $base);
Ajustes::set('dias_gracia', '5');
// sin plan de destino
Cobros::registrarPago($idA, $otro, 'mensual', 29900, 'transferencia', 'OP-OTRA', '', 'x');
Ajustes::set('plan_vencido_id', '');
ponerVence($idA, date('Y-m-d H:i:s', time() - 20 * 86400), 'gracia', date('Y-m-d H:i:s', time() - 86400));
Cobros::procesarVencimientos();
chk('SIN plan de destino configurado: queda "vencida" pero el plan NO se toca (jamas queda ilimitada)', sus($idA)['estado'] === 'vencida' && planDeEmpresa($idA) === $otro
    && (int) Db::uno("SELECT COUNT(*) AS n FROM auditoria WHERE empresa_id = ? AND detalle LIKE '%sin plan de destino%'", array($idA))['n'] >= 1);
// plan cambiado a mano antes de vencer
Ajustes::set('plan_vencido_id', (string) $base);
Cobros::registrarPago($idA, $otro, 'mensual', 29900, 'transferencia', 'OP-MANUAL', '', 'x');
Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($pro, $idA)); // el operador le puso otro plan a mano
ponerVence($idA, date('Y-m-d H:i:s', time() - 20 * 86400), 'gracia', date('Y-m-d H:i:s', time() - 86400));
Cobros::procesarVencimientos();
chk('si el operador ya le puso otro plan a mano, el vencimiento NO lo pisa', planDeEmpresa($idA) === $pro && sus($idA)['estado'] === 'vencida');
// empresa sin suscripcion
$idSin = nuevaEmpresa('20000000099', 'LAB SIN SUSC');
Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($pro, $idSin));
Cobros::procesarVencimientos();
chk('una empresa SIN suscripcion (convenio, plan a mano) nunca vence', planDeEmpresa($idSin) === $pro && sus($idSin) === null);
Db::ejecutar('DELETE FROM empresas WHERE id = ?', array($idSin));

echo "\n== 7) Avisos al cliente\n";
Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($pro, $idB));
Cobros::registrarPago($idB, $pro, 'mensual', 9900, 'transferencia', 'OP-B2', '', 'x');
ponerVence($idB, date('Y-m-d H:i:s', time() + 30 * 86400), 'activa');
chk('con mucho tiempo por delante no hay aviso', Cobros::aviso($idB) === null);
ponerVence($idB, date('Y-m-d H:i:s', time() + 3 * 86400 + 600), 'activa');
$a = Cobros::aviso($idB);
chk('a 3 dias de vencer: aviso "vence el ... (4 dias)" (umbral de 7 dias)', $a !== null && $a['tipo'] === 'aviso' && strpos($a['texto'], 'vence el') !== false);
Ajustes::set('dias_aviso', '2');
chk('el umbral es configurable: con 2 dias ya no avisa a 3', Cobros::aviso($idB) === null);
Ajustes::set('dias_aviso', '7');
ponerVence($idB, date('Y-m-d H:i:s', time() + 5 * 86400), 'prueba');
$a = Cobros::aviso($idB);
chk('en prueba el aviso es informativo e indica cuando termina', $a['tipo'] === 'info' && strpos($a['texto'], 'prueba gratis') !== false);
chk('empresa sin suscripcion: sin aviso', Cobros::aviso(99999999) === null);


// ====================================================================== PANTALLAS (HTTP)
class Cli
{
    public $cookies = array();
    public $raiz;

    public function __construct($raiz)
    {
        $this->raiz = $raiz;
    }

    public function req($metodo, $url, $campos = null)
    {
        $ch = curl_init($url);
        $h = array('User-Agent: ProbarCobros/1.0');
        if ($this->cookies) {
            $p = array();
            foreach ($this->cookies as $k => $v) {
                $p[] = "$k=$v";
            }
            $h[] = 'Cookie: ' . implode('; ', $p);
        }
        $cab = array();
        $setc = array();
        curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $h,
            CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$cab, &$setc) {
                if (strpos($l, ':') !== false) {
                    list($k, $v) = explode(':', $l, 2);
                    $k = strtolower(trim($k));
                    $cab[$k] = trim($v);
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
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $GLOBALS['ULT'] = $http . ' ' . trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('#<(script|style).*?</(script|style)>#s', '', $cuerpo))));
        foreach ($setc as $sc) {
            $q = explode(';', $sc);
            list($n, $v) = array_pad(explode('=', trim($q[0]), 2), 2, '');
            if ($v === '' || stripos($sc, 'expires=Thu, 01-Jan-1970') !== false) {
                unset($this->cookies[$n]);
            } else {
                $this->cookies[$n] = $v;
            }
        }
        return array('http' => $http, 'cab' => $cab, 'cuerpo' => $cuerpo, 'loc' => isset($cab['location']) ? $cab['location'] : null);
    }

    public function get($ruta)
    {
        return $this->req('GET', $this->raiz . $ruta);
    }

    public function csrf($ruta = '/')
    {
        return preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $this->get($ruta)['cuerpo'], $m) ? $m[1] : '';
    }

    public function post($ruta, array $campos, $desde = '/')
    {
        $tok = $this->csrf($desde);
        return $this->req('POST', $this->raiz . $ruta, array_merge(array('_csrf' => $tok), $campos));
    }

    public function login($email, $clave)
    {
        return $this->req('POST', $this->raiz . '/login', array('_csrf' => $this->csrf('/login'), 'email' => $email, 'clave' => $clave));
    }
}
function claveCli($salida)
{
    return preg_match('/^\s{2}(\S{14})\s*$/m', $salida, $m) ? $m[1] : null;
}
function adminCli($a)
{
    return (string) shell_exec('php bin/admin.php ' . $a . ' 2>&1');
}

echo "\n== 8) Panel del operador: planes con precio, ajustes, pagos y cobros\n";
$tmpOp = claveCli(adminCli('operador:crear --email=op@lab-cobros.test --nombre="Op Cobros"'));
$op = new Cli($OPU);
$op->login('op@lab-cobros.test', (string) $tmpOp);
$op->post('/cuenta/clave', array('clave_actual' => (string) $tmpOp, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
chk('el operador entra al panel', $op->get('/')['http'] === 200);
foreach (array('/cobros', '/pagos', '/ajustes') as $ruta) {
    $x = (new Cli($OPU))->get($ruta);
    chk("$ruta sin sesion redirige al acceso", $x['http'] === 302 && strpos((string) $x['loc'], '/operador/login') !== false);
}
$bien = true;
foreach (array('/ajustes/guardar', '/empresas/' . $idA . '/pago', '/empresas/' . $idA . '/prueba') as $ruta) {
    if ((new Cli($OPU))->req('POST', $OPU . $ruta, array('id' => 1))['http'] !== 403) {
        $bien = false;
    }
}
chk('las 3 acciones nuevas sin token CSRF -> 403', $bien);

// --- planes
$op->post('/planes/guardar', array('nombre' => 'LabCNuevo', 'precio_mensual' => '99,90', 'descuento_anual' => '17', 'dias_prueba' => '14', 'cuota_mensual' => '500', 'max_webhooks' => '2', 'publico' => '1'), '/planes');
$pn = Db::uno("SELECT * FROM planes WHERE nombre = 'LabCNuevo'");
chk('crear un plan con precio 99,90, descuento anual 17 % y 14 dias de prueba', $pn && (int) $pn['precio_mensual'] === 9990 && (int) $pn['descuento_anual'] === 17 && (int) $pn['dias_prueba'] === 14);
$pagPl = $op->get('/planes');
chk('la pantalla de planes muestra el calculo: S/ 99.90 al mes o S/ 995.00 al año (ahorra S/ 203.80)', strpos($pagPl['cuerpo'], 'S/ 99.90') !== false && strpos($pagPl['cuerpo'], 'S/ 995.00') !== false && strpos($pagPl['cuerpo'], 'S/ 203.80') !== false && strpos($pagPl['cuerpo'], 'value="99.90"') !== false);
$n0 = (int) Db::uno('SELECT COUNT(*) AS n FROM planes')['n'];
foreach (array(array('precio_mensual' => 'abc'), array('precio_mensual' => '-5'), array('precio_mensual' => '1000000'), array('precio_mensual' => '12.345'), array('descuento_anual' => '95'), array('descuento_anual' => 'x'),
    array('dias_prueba' => '999'), array('dias_prueba' => '-1')) as $malo) {
    $op->post('/planes/guardar', array_merge(array('nombre' => 'LabCMalo', 'max_webhooks' => '2'), $malo), '/planes');
}
chk('precios negativos, con texto, enormes o con 3 decimales; descuento > 90; prueba > 365 -> ninguno se guarda', (int) Db::uno('SELECT COUNT(*) AS n FROM planes')['n'] === $n0);
$op->post('/planes/guardar', array('id' => $pn['id'], 'nombre' => 'LabCNuevo', 'precio_mensual' => '', 'descuento_anual' => '', 'dias_prueba' => '', 'max_webhooks' => '2', 'activo' => '1'), '/planes');
$pn = Db::uno('SELECT * FROM planes WHERE id = ?', array($pn['id']));
chk('vaciar los campos quita el precio (null), el descuento y la prueba (0)', $pn['precio_mensual'] === null && (int) $pn['descuento_anual'] === 0 && (int) $pn['dias_prueba'] === 0);

// --- ajustes
$op->post('/ajustes/guardar', array('dias_gracia' => '3', 'dias_aviso' => '10', 'plan_vencido_id' => (string) $base, 'instrucciones_pago' => "Yape al 999 999 999\n<script>alert(1)</script>"), '/ajustes');
Ajustes::olvidar();
chk('guardar ajustes: gracia 3, aviso 10, plan de destino y las instrucciones de pago', Ajustes::dias('dias_gracia') === 3 && Ajustes::dias('dias_aviso') === 10 && (int) Ajustes::get('plan_vencido_id') === $base && strpos(Ajustes::get('instrucciones_pago'), 'Yape') !== false);
$pg = $op->get('/ajustes');
chk('la pantalla de ajustes muestra los valores y escapa las instrucciones', strpos($pg['cuerpo'], 'value="3"') !== false && strpos($pg['cuerpo'], '<script>alert(1)') === false && strpos($pg['cuerpo'], '&lt;script&gt;') !== false);
$op->post('/ajustes/guardar', array('dias_gracia' => '3', 'dias_aviso' => '10', 'plan_vencido_id' => (string) $base, 'instrucciones_pago' => 'Yape'), '/ajustes');
foreach (array(array('dias_gracia' => 'x'), array('dias_gracia' => '100'), array('dias_gracia' => ''), array('dias_aviso' => '0'), array('plan_vencido_id' => '999999'), array('plan_vencido_id' => '1 OR 1=1'), array('instrucciones_pago' => str_repeat('A', 601))) as $malo) {
    $op->post('/ajustes/guardar', array_merge(array('dias_gracia' => '3', 'dias_aviso' => '10', 'plan_vencido_id' => (string) $base, 'instrucciones_pago' => 'ok'), $malo), '/ajustes');
}
Ajustes::olvidar();
chk('gracia no numerica o > 60, aviso 0, plan de destino inexistente o manipulado, texto > 600 -> no cambian nada', Ajustes::dias('dias_gracia') === 3 && Ajustes::dias('dias_aviso') === 10 && (int) Ajustes::get('plan_vencido_id') === $base && strpos(Ajustes::get('instrucciones_pago'), 'Yape') !== false);
$op->post('/ajustes/guardar', array('dias_gracia' => '5', 'dias_aviso' => '7', 'plan_vencido_id' => (string) $base, 'instrucciones_pago' => 'Transferencia BCP 000-111. Envie el voucher.'), '/ajustes');
Ajustes::olvidar();

// --- registrar pago desde la ficha
$idP = nuevaEmpresa('20000000024', 'LAB COBROS P');
$ficha = $op->get('/empresas/' . $idP . '?plan_id=' . $pro . '&periodo=anual');
chk('la ficha trae el formulario de pago con el monto ANUAL propuesto (S/ 986.04 = 12 x 99 con 17 %)', strpos($ficha['cuerpo'], 'Registrar un pago') !== false && strpos($ficha['cuerpo'], 'value="986.04"') !== false);
$ficha = $op->get('/empresas/' . $idP);
chk('...sin parametros propone el mensual del plan y avisa que no tiene suscripcion (no vence)', strpos($ficha['cuerpo'], 'no vence') !== false);
$op->post('/empresas/' . $idP . '/pago', array('plan_id' => $pro, 'periodo' => 'mensual', 'monto' => '99', 'metodo' => 'transferencia', 'referencia' => 'WEB-1', 'nota' => 'x', 'clave_actual' => 'contrasena-equivocada-1'), '/empresas/' . $idP);
chk('registrar un pago con la contrasena equivocada -> no se registra', cuantosPagos($idP) === 0 && sus($idP) === null);
Db::ejecutar('UPDATE operadores SET intentos_fallidos = 0, bloqueado_hasta = NULL');
foreach (array(array('monto' => 'abc'), array('monto' => '0'), array('monto' => '-10'), array('metodo' => 'bitcoin'), array('referencia' => str_repeat('R', 81)), array('plan_id' => '999999')) as $malo) {
    $op->post('/empresas/' . $idP . '/pago', array_merge(array('plan_id' => $pro, 'periodo' => 'mensual', 'monto' => '99', 'metodo' => 'transferencia', 'referencia' => 'WEB-X', 'nota' => '', 'clave_actual' => $CLAVE), $malo), '/empresas/' . $idP);
}
chk('monto invalido, metodo inexistente, referencia larga o plan inexistente -> nada se registra', cuantosPagos($idP) === 0 && sus($idP) === null);
$op->post('/empresas/' . $idP . '/pago', array('plan_id' => $pro, 'periodo' => 'anual', 'monto' => '986.04', 'metodo' => 'transferencia', 'referencia' => 'WEB-1<b>', 'nota' => 'nota ok', 'clave_actual' => $CLAVE), '/empresas/' . $idP);
$s = sus($idP);
chk('un pago valido: suscripcion activa ANUAL, plan asignado, 12 meses', $s && $s['estado'] === 'activa' && $s['periodo'] === 'anual' && planDeEmpresa($idP) === $pro && ymd($s['vence']) === ymd(Cobros::sumarMeses(Db::ahora(), 12)));
$flash = $op->get('/empresas/' . $idP);
chk('...el operador ve el resumen: "Plan LabCPro activo hasta el ..."', strpos($flash['cuerpo'], 'activo hasta el') !== false || strpos($flash['cuerpo'], 'LabCPro') !== false);
$op->post('/empresas/' . $idP . '/pago', array('plan_id' => $pro, 'periodo' => 'anual', 'monto' => '986.04', 'metodo' => 'transferencia', 'referencia' => 'WEB-1<b>', 'nota' => '', 'clave_actual' => $CLAVE), '/empresas/' . $idP);
chk('repetir el mismo pago (misma referencia) avisa "no se duplico" y no extiende', strpos($op->get('/empresas/' . $idP)['cuerpo'], 'no se duplicó') !== false && cuantosPagos($idP) === 1);
$ficha = $op->get('/empresas/' . $idP);
chk('la ficha muestra el pago en su historial y la referencia con HTML sale escapada', strpos($ficha['cuerpo'], 'S/ 986.04') !== false && strpos($ficha['cuerpo'], 'WEB-1<b>') === false && strpos($ficha['cuerpo'], 'WEB-1&lt;b&gt;') !== false);
$op->post('/empresas/' . $idP . '/prueba', array('plan_id' => $pro, 'dias' => '7', 'clave_actual' => $CLAVE), '/empresas/' . $idP);
chk('dar prueba gratis a quien YA tiene suscripcion pagada vigente -> se rechaza', sus($idP)['estado'] === 'activa' && strpos($op->get('/empresas/' . $idP)['cuerpo'], 'suscripcion pagada vigente') !== false);

// --- listados
Db::ejecutar("UPDATE suscripciones SET vence = ?, estado = 'activa' WHERE empresa_id = ?", array(date('Y-m-d H:i:s', time() + 4 * 86400), $idP));
$cb = $op->get('/cobros');
chk('Cobros lista las suscripciones con su estado y dias restantes', $cb['http'] === 200 && strpos($cb['cuerpo'], 'LAB COBROS P') !== false && strpos($cb['cuerpo'], '4 d') !== false);
chk('el filtro "Por vencer" (aviso a 7 dias) incluye a la que vence en 4', strpos($op->get('/cobros?estado=porvencer')['cuerpo'], 'LAB COBROS P') !== false);
chk('un filtro "En gracia" no la incluye', strpos($op->get('/cobros?estado=gracia')['cuerpo'], 'LAB COBROS P') === false);
chk('el filtro con codigo malicioso se ignora (no se refleja)', strpos($op->get('/cobros?estado=' . urlencode('"><script>x</script>'))['cuerpo'], '<script>x') === false);
chk('el menu avisa cuantas requieren atencion (por vencer o en gracia)', (bool) preg_match('/Cobros <span class="badge espera">\d+<\/span>/', $cb['cuerpo']));
$pgs = $op->get('/pagos');
chk('Pagos muestra el historial, el total del mes y el metodo en palabras', $pgs['http'] === 200 && strpos($pgs['cuerpo'], 'Transferencia bancaria') !== false && strpos($pgs['cuerpo'], 'Cobrado en ' . date('Y-m')) !== false);
chk('...y escapa lo que viene de la base de datos', strpos($pgs['cuerpo'], 'WEB-1<b>') === false);

echo "\n== 9) Portal del cliente: ve su suscripcion, prueba gratis y pide plan\n";
$tmpC = claveCli(adminCli('usuario:crear --ruc=' . $RUCS[2] . ' --email=cli@lab-cobros.test --nombre="Cliente C" --rol=admin'));
$tmpL = claveCli(adminCli('usuario:crear --ruc=' . $RUCS[2] . ' --email=lec@lab-cobros.test --nombre="Lector C" --rol=lector'));
$cp = new Cli($POU);
$cp->login('cli@lab-cobros.test', (string) $tmpC);
$cp->post('/cuenta/clave', array('clave_actual' => (string) $tmpC, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
$cl = new Cli($POU);
$cl->login('lec@lab-cobros.test', (string) $tmpL);
$cl->post('/cuenta/clave', array('clave_actual' => (string) $tmpL, 'clave_nueva' => $CLAVE, 'clave_repetida' => $CLAVE), '/cuenta');
Db::ejecutar('DELETE FROM suscripciones WHERE empresa_id = ?', array($idC));
Db::ejecutar('UPDATE empresas SET plan_id = NULL WHERE id = ?', array($idC));
Db::ejecutar('UPDATE planes SET precio_mensual = 9900, descuento_anual = 17, dias_prueba = 14 WHERE id = ?', array($pro));
$pl = $cp->get('/plan');
chk('el cliente ve los planes con precio mensual, anual y el ahorro', $pl['http'] === 200 && strpos($pl['cuerpo'], 'S/ 99.00') !== false && strpos($pl['cuerpo'], 'S/ 986.04') !== false && strpos($pl['cuerpo'], 'ahorra') !== false);
chk('...y la insignia "14 dias gratis" con el boton "Probar gratis"', strpos($pl['cuerpo'], '14 días gratis') !== false && strpos($pl['cuerpo'], '>Probar gratis<') !== false);
chk('...y las instrucciones de pago que configuro el operador', strpos($pl['cuerpo'], 'Transferencia BCP 000-111') !== false);
$cp->post('/plan/prueba', array('plan_id' => $pro, 'clave_actual' => 'contrasena-equivocada-1'), '/plan');
chk('iniciar la prueba con la contrasena equivocada -> no se inicia', sus($idC) === null);
Db::ejecutar('UPDATE usuarios_portal SET intentos_fallidos = 0, bloqueado_hasta = NULL');
chk('un usuario "lector" no puede iniciar la prueba (403)', $cl->post('/plan/prueba', array('plan_id' => $pro, 'clave_actual' => $CLAVE), '/plan')['http'] === 403 && sus($idC) === null);
$cp->post('/plan/prueba', array('plan_id' => $otro, 'clave_actual' => $CLAVE), '/plan');
chk('un plan SIN dias de prueba no se puede probar', sus($idC) === null && strpos($cp->get('/plan')['cuerpo'], 'no ofrece prueba gratis') !== false);
$cp->post('/plan/prueba', array('plan_id' => $pro, 'clave_actual' => $CLAVE), '/plan');
$s = sus($idC);
chk('la prueba gratis se inicia: plan asignado, estado "prueba", 14 dias', $s && $s['estado'] === 'prueba' && planDeEmpresa($idC) === $pro && ymd($s['vence']) === ymd(Cobros::sumarDias(Db::ahora(), 14)));
$pl = $cp->get('/plan');
chk('el cliente ve un aviso con la fecha en que termina su prueba', strpos($pl['cuerpo'], 'prueba gratis') !== false && strpos($pl['cuerpo'], 'termina el') !== false);
chk('...tambien en su pantalla de Resumen', strpos($cp->get('/')['cuerpo'], 'termina el') !== false);
chk('el boton "Probar gratis" desaparece (solo una vez por empresa)', strpos($pl['cuerpo'], '>Probar gratis<') === false);
$cp->post('/plan/prueba', array('plan_id' => $pro, 'clave_actual' => $CLAVE), '/plan');
chk('forzar una segunda prueba por la fuerza -> rechazada', strpos($cp->get('/plan')['cuerpo'], 'ya uso su prueba gratis') !== false);
$cp->post('/plan/solicitar', array('plan_id' => $otro, 'periodo' => 'anual', 'clave_actual' => $CLAVE), '/plan');
$sol = Db::uno("SELECT * FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($idC));
chk('pedir un plan de pago guarda el periodo elegido (anual) en la solicitud', $sol && $sol['periodo'] === 'anual' && planDeEmpresa($idC) === $pro);
chk('...y el portal lo muestra', strpos($cp->get('/plan')['cuerpo'], '(anual)') !== false);
$cp->post('/plan/solicitar', array('plan_id' => $otro, 'periodo' => 'cada-semana', 'clave_actual' => $CLAVE), '/plan');
chk('un periodo inventado se ignora (se guarda sin periodo)', Db::uno("SELECT periodo FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($idC))['periodo'] === null);
// el operador cierra el ciclo: registra el pago
$solId = (int) Db::uno("SELECT id FROM solicitudes_plan WHERE empresa_id = ? AND estado = 'pendiente'", array($idC))['id'];
$op->post('/empresas/' . $idC . '/pago', array('plan_id' => $otro, 'periodo' => 'anual', 'monto' => '3229.20', 'metodo' => 'yape_plin', 'referencia' => 'YP-77', 'clave_actual' => $CLAVE), '/empresas/' . $idC);
chk('el operador registra el pago anual y la solicitud pendiente queda aprobada sola', sus($idC)['estado'] === 'activa' && planDeEmpresa($idC) === $otro && Db::uno('SELECT estado FROM solicitudes_plan WHERE id = ?', array($solId))['estado'] === 'aprobada');
// vencimiento visto por el cliente
Db::ejecutar('UPDATE suscripciones SET vence = ? WHERE empresa_id = ?', array(date('Y-m-d H:i:s', time() - 86400), $idC));
$op->get('/cobros'); // al abrir Cobros el panel procesa los vencimientos
chk('al vencer, el panel la pasa a "gracia" y el cliente ve una alerta roja con la fecha limite', sus($idC)['estado'] === 'gracia' && strpos($cp->get('/')['cuerpo'], 'Puede renovar hasta') !== false && strpos($cp->get('/')['cuerpo'], 'class="aviso error"') !== false);
Db::ejecutar('UPDATE suscripciones SET vence = ?, gracia_hasta = ? WHERE empresa_id = ?', array(date('Y-m-d H:i:s', time() - 9 * 86400), date('Y-m-d H:i:s', time() - 86400), $idC));
$op->get('/');
chk('agotada la gracia: vencida, el cliente queda en el plan de destino y lo ve', sus($idC)['estado'] === 'vencida' && planDeEmpresa($idC) === $base && strpos($cp->get('/plan')['cuerpo'], 'LabCBase') !== false);
$act = $cp->get('/actividad');
chk('su actividad registra el pago, el paso a gracia y el vencimiento', strpos($act['cuerpo'], 'pago_registrado') !== false && strpos($act['cuerpo'], 'suscripcion_en_gracia') !== false && strpos($act['cuerpo'], 'suscripcion_vencida') !== false);

echo "\n== 10) Pagina publica: precios, ahorro y prueba gratis\n";
Db::ejecutar("UPDATE planes SET publico = 1, precio_mensual = 9900, descuento_anual = 17, dias_prueba = 14, precio = NULL WHERE id = ?", array($pro));
Db::ejecutar("UPDATE planes SET publico = 1, precio_mensual = 0, descuento_anual = 0, dias_prueba = 0 WHERE id = ?", array($base));
Db::ejecutar("UPDATE planes SET publico = 1, precio_mensual = NULL, precio = 'A medida', dias_prueba = 0 WHERE id = ?", array($otro));
$web = curl_init($WEB . '/');
curl_setopt_array($web, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60));
$html = (string) curl_exec($web);
curl_close($web);
chk('un plan con precio muestra "S/ 99.00 al mes" y "o S/ 986.04 al año · ahorra S/ 201.96 (17 %)"', strpos($html, 'S/ 99.00') !== false && strpos($html, 'S/ 986.04') !== false && strpos($html, 'ahorra S/ 201.96') !== false && strpos($html, '(17 %)') !== false);
chk('...con la insignia "14 dias de prueba gratis" y el boton "Probar LabCPro gratis"', strpos($html, '14 días de prueba gratis') !== false && strpos($html, 'Probar LabCPro gratis') !== false);
chk('un plan de precio 0 se muestra como "Gratis"', (bool) preg_match('#<h3>LabCBase</h3>(.*?)</article>#s', $html, $mc) && strpos($mc[1], 'Gratis') !== false);
chk('un plan sin precio mensual usa su texto ("A medida")', strpos($html, 'A medida') !== false);

echo "\n== 11) El worker procesa los vencimientos\n";
Db::ejecutar('DELETE FROM suscripciones WHERE empresa_id = ?', array($idC));
Cobros::iniciarPrueba($idC, $pro, 3, true, 'op');
ponerVence($idC, date('Y-m-d H:i:s', time() - 3600));
Ajustes::olvidar();
$salida = (string) shell_exec('php bin/worker.php --una-vez 2>&1');
chk('un ciclo del worker pasa a "vencida" la prueba terminada y baja al plan de destino', sus($idC)['estado'] === 'vencida' && planDeEmpresa($idC) === $base && strpos($salida, 'suscripciones vencidas procesadas') !== false, substr(trim($salida), -300));

echo "\nResultado: $ok correctas, $mal con falla.\n";
exit($mal ? 1 : 0);
