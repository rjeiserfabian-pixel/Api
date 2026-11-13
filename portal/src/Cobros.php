<?php
/**
 * Cobros: precios, pagos, pruebas gratis, suscripciones y vencimientos.
 *
 * UN solo punto de entrada para "esta empresa pago": Cobros::registrarPago(). Hoy lo usa el operador (transferencia, Yape...);
 * cuando exista una pasarela, su webhook llamara a la misma funcion con metodo "pasarela:<nombre>" y el identificador del pago
 * como referencia (un aviso repetido no duplica nada: (metodo, referencia) es unico).
 *
 * Reglas (todas configurables en el panel del operador):
 *  - Pago mensual = 1 mes, anual = 12 meses (con el descuento anual del plan). Renovar a tiempo suma desde el vencimiento anterior.
 *  - Al vencer: dias de GRACIA con el plan intacto; despues pasa al plan de destino ("plan al vencer") o, si no hay, no se toca.
 *  - La prueba gratis es una sola por empresa y no tiene gracia.
 *  - Las empresas SIN suscripcion (plan asignado a mano, convenios) no vencen nunca.
 * Requiere Auditoria (portal/src/Usuarios.php) y Suscripciones (portal/src/Suscripciones.php).
 */
class Dinero
{
    const MAX = 99999999; // S/ 999,999.99

    /** "99.90", "99,90", "99" -> 9990 (centimos). null si no es un monto valido (negativo, letras, mas de 2 decimales...). */
    public static function aCentimos($texto)
    {
        $t = trim((string) $texto);
        $t = str_replace(' ', '', $t);
        if (preg_match('/^\d{1,3}(,\d{3})+(\.\d{1,2})?$/', $t)) {
            $t = str_replace(',', '', $t); // 1,234.50
        } else {
            $t = str_replace(',', '.', $t); // 99,90
        }
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $t)) {
            return null;
        }
        $partes = explode('.', $t);
        $c = (int) $partes[0] * 100 + (isset($partes[1]) ? (int) str_pad($partes[1], 2, '0') : 0);
        return $c > self::MAX ? null : $c;
    }

    public static function formato($centimos)
    {
        return 'S/ ' . number_format(((int) $centimos) / 100, 2, '.', ',');
    }

    /** Valor para un campo de formulario: 9990 -> "99.90" */
    public static function paraCampo($centimos)
    {
        return $centimos === null || $centimos === '' ? '' : number_format(((int) $centimos) / 100, 2, '.', '');
    }

    /** Precio del plan pagado un ano completo, con el descuento anual (centimos). */
    public static function anual($mensual, $descuento)
    {
        return (int) round($mensual * 12 * (100 - (int) $descuento) / 100);
    }
}

class Ajustes
{
    public static $defectos = array('dias_gracia' => '5', 'dias_aviso' => '7', 'plan_vencido_id' => '', 'instrucciones_pago' => '');
    private static $cache = null;

    public static function todos()
    {
        if (self::$cache === null) {
            self::$cache = self::$defectos;
            foreach (Db::consultar('SELECT clave, valor FROM ajustes') as $f) {
                if (array_key_exists($f['clave'], self::$defectos)) {
                    self::$cache[$f['clave']] = (string) $f['valor'];
                }
            }
        }
        return self::$cache;
    }

    /** Descarta lo leido (la proxima consulta vuelve a la base de datos). */
    public static function olvidar()
    {
        self::$cache = null;
    }

    public static function get($clave)
    {
        $t = self::todos();
        return isset($t[$clave]) ? $t[$clave] : null;
    }

    public static function set($clave, $valor)
    {
        if (!array_key_exists($clave, self::$defectos)) {
            throw new InvalidArgumentException('Ajuste desconocido.');
        }
        if (Db::afectadas('UPDATE ajustes SET valor = ? WHERE clave = ?', array((string) $valor, $clave)) === 0) {
            Db::ejecutar('INSERT INTO ajustes (clave, valor) VALUES (?, ?)', array($clave, (string) $valor));
        }
        self::$cache = null;
    }

    public static function dias($clave)
    {
        return max(0, (int) self::get($clave));
    }
}

class Cobros
{
    public static $metodos = array('transferencia' => 'Transferencia bancaria', 'yape_plin' => 'Yape / Plin', 'efectivo' => 'Efectivo / depósito', 'tarjeta' => 'Tarjeta (fuera del sistema)', 'otro' => 'Otro');

    // ------------------------------------------------------------------ fechas
    /** Suma meses sin "saltarse" el fin de mes (31 de enero + 1 mes = 28/29 de febrero). */
    public static function sumarMeses($fecha, $n)
    {
        $d = new DateTime($fecha);
        $dia = (int) $d->format('j');
        $d->modify('first day of this month');
        $d->modify('+' . (int) $n . ' month');
        $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min($dia, (int) $d->format('t')));
        return $d->format('Y-m-d H:i:s');
    }

    public static function sumarDias($fecha, $n)
    {
        $d = new DateTime($fecha);
        $d->modify('+' . (int) $n . ' day');
        return $d->format('Y-m-d H:i:s');
    }

    /** Dias que faltan (redondeado hacia arriba; 0 o negativo si ya paso). */
    public static function diasHasta($fecha)
    {
        return (int) ceil((strtotime($fecha) - time()) / 86400);
    }

    // ------------------------------------------------------------------ precios
    /** array('mensual' => c|null, 'anual' => c|null, 'ahorro_pct' => n, 'ahorro' => c) */
    public static function precios(array $plan)
    {
        $m = isset($plan['precio_mensual']) && $plan['precio_mensual'] !== null && $plan['precio_mensual'] !== '' ? (int) $plan['precio_mensual'] : null;
        $d = isset($plan['descuento_anual']) ? (int) $plan['descuento_anual'] : 0;
        if ($m === null) {
            return array('mensual' => null, 'anual' => null, 'ahorro_pct' => 0, 'ahorro' => 0);
        }
        $a = Dinero::anual($m, $d);
        return array('mensual' => $m, 'anual' => $a, 'ahorro_pct' => $d, 'ahorro' => $m * 12 - $a);
    }

    public static function precioPeriodo(array $plan, $periodo)
    {
        $p = self::precios($plan);
        return $periodo === 'anual' ? $p['anual'] : $p['mensual'];
    }

    // ------------------------------------------------------------------ consultas
    public static function suscripcion($empresaId)
    {
        return Db::uno('SELECT s.*, p.nombre AS plan FROM suscripciones s LEFT JOIN planes p ON p.id = s.plan_id WHERE s.empresa_id = ?', array((int) $empresaId));
    }

    private static function guardar($empresaId, array $campos)
    {
        $ahora = Db::ahora();
        $s = Db::uno('SELECT id FROM suscripciones WHERE empresa_id = ?', array((int) $empresaId));
        if ($s) {
            Db::ejecutar('UPDATE suscripciones SET plan_id = ?, periodo = ?, estado = ?, inicio = ?, vence = ?, gracia_hasta = ?, prueba_usada = ?, actualizado = ? WHERE id = ?',
                array($campos['plan_id'], $campos['periodo'], $campos['estado'], $campos['inicio'], $campos['vence'], $campos['gracia_hasta'], $campos['prueba_usada'], $ahora, $s['id']));
        } else {
            Db::ejecutar('INSERT INTO suscripciones (empresa_id, plan_id, periodo, estado, inicio, vence, gracia_hasta, prueba_usada, actualizado, creado) VALUES (?,?,?,?,?,?,?,?,?,?)',
                array((int) $empresaId, $campos['plan_id'], $campos['periodo'], $campos['estado'], $campos['inicio'], $campos['vence'], $campos['gracia_hasta'], $campos['prueba_usada'], $ahora, $ahora));
        }
    }

    // ------------------------------------------------------------------ pagos
    /**
     * Registra un pago CONFIRMADO y deja la suscripcion activa (asigna el plan). Idempotente por (metodo, referencia).
     * @return array('duplicado' => bool, 'vence' => fecha, 'plan' => nombre, 'pago_id' => id)
     */
    public static function registrarPago($empresaId, $planId, $periodo, $monto, $metodo, $referencia, $nota, $quien)
    {
        if (!in_array($periodo, array('mensual', 'anual'), true)) {
            throw new InvalidArgumentException('El periodo debe ser mensual o anual.');
        }
        if (!is_int($monto) || $monto <= 0 || $monto > Dinero::MAX) {
            throw new InvalidArgumentException('El monto no es valido.');
        }
        if (!preg_match('/^[a-z0-9_:\-]{1,30}$/', (string) $metodo)) {
            throw new InvalidArgumentException('El metodo de pago no es valido.');
        }
        $referencia = trim((string) $referencia);
        if (strlen($referencia) > 80 || preg_match('/[\x00-\x1F\x7F]/', $referencia)) {
            throw new InvalidArgumentException('La referencia es demasiado larga o tiene caracteres no permitidos (maximo 80).');
        }
        $nota = trim((string) $nota);
        if (strlen($nota) > 200 || preg_match('/[\x00-\x1F\x7F]/', $nota)) {
            throw new InvalidArgumentException('La nota es demasiado larga o tiene caracteres no permitidos (maximo 200).');
        }
        $empresa = Db::uno('SELECT id FROM empresas WHERE id = ?', array((int) $empresaId));
        $plan = Db::uno('SELECT * FROM planes WHERE id = ? AND activo = 1', array((int) $planId));
        if (!$empresa || !$plan) {
            throw new InvalidArgumentException('La empresa o el plan no existen (o el plan esta inactivo).');
        }
        if ($referencia !== '') {
            $prev = Db::uno('SELECT * FROM pagos WHERE metodo = ? AND referencia = ?', array($metodo, $referencia));
            if ($prev) {
                return array('duplicado' => true, 'vence' => $prev['vence_resultante'], 'plan' => $plan['nombre'], 'pago_id' => (int) $prev['id']);
            }
        }
        $meses = $periodo === 'anual' ? 12 : 1;
        try {
            $r = Db::transaccion(function () use ($empresaId, $plan, $periodo, $monto, $metodo, $referencia, $nota, $quien, $meses) {
                $ahora = Db::ahora();
                $s = Db::uno('SELECT * FROM suscripciones WHERE empresa_id = ?', array((int) $empresaId));
                // Renovar a tiempo (o dentro de la gracia) suma desde el vencimiento anterior: nadie pierde ni gana dias.
                $base = $ahora;
                $inicio = $ahora;
                if ($s && in_array($s['estado'], array('activa', 'gracia'), true) && (int) $s['plan_id'] === (int) $plan['id']) {
                    $base = $s['vence'];
                    $inicio = $s['inicio'];
                    if (strtotime(self::sumarMeses($base, $meses)) <= time()) {
                        $base = $ahora;
                        $inicio = $ahora;
                    }
                }
                $vence = self::sumarMeses($base, $meses);
                self::guardar($empresaId, array('plan_id' => (int) $plan['id'], 'periodo' => $periodo, 'estado' => 'activa', 'inicio' => $inicio, 'vence' => $vence, 'gracia_hasta' => null,
                    'prueba_usada' => $s ? (int) $s['prueba_usada'] : 0));
                $pagoId = Db::ejecutar('INSERT INTO pagos (empresa_id, plan_id, periodo, monto, moneda, metodo, referencia, nota, registrado_por, vence_resultante, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    array((int) $empresaId, (int) $plan['id'], $periodo, $monto, 'PEN', $metodo, $referencia !== '' ? $referencia : null, $nota !== '' ? $nota : null, substr((string) $quien, 0, 190), $vence, $ahora));
                Suscripciones::asignar($empresaId, $plan['id'], 'pago ' . $periodo . ' registrado');
                Db::ejecutar("UPDATE solicitudes_plan SET estado = 'aprobada', resuelto = ?, resuelto_por = ? WHERE empresa_id = ? AND plan_id = ? AND estado = 'pendiente'",
                    array($ahora, 'al registrar el pago', (int) $empresaId, (int) $plan['id']));
                return array('duplicado' => false, 'vence' => $vence, 'plan' => $plan['nombre'], 'pago_id' => (int) $pagoId);
            });
        } catch (PDOException $e) {
            // dos avisos iguales al mismo tiempo (p. ej. de una pasarela): el segundo choca con el indice unico
            if ($referencia !== '' && ($prev = Db::uno('SELECT * FROM pagos WHERE metodo = ? AND referencia = ?', array($metodo, $referencia)))) {
                return array('duplicado' => true, 'vence' => $prev['vence_resultante'], 'plan' => $plan['nombre'], 'pago_id' => (int) $prev['id']);
            }
            throw $e;
        }
        Auditoria::registrar($empresaId, null, 'pago_registrado', Dinero::formato($monto) . ' ' . $periodo . ' ' . $plan['nombre'] . ' (' . $metodo . ') hasta ' . substr($r['vence'], 0, 10));
        return $r;
    }

    // ------------------------------------------------------------------ prueba gratis
    /** Puede esta empresa iniciar una prueba gratis (una sola vez y sin suscripcion pagada)? */
    public static function puedeProbar($empresaId, array $plan)
    {
        if ((int) $plan['dias_prueba'] <= 0 || (int) $plan['activo'] !== 1) {
            return false;
        }
        $s = Db::uno('SELECT * FROM suscripciones WHERE empresa_id = ?', array((int) $empresaId));
        return !$s || ((int) $s['prueba_usada'] === 0 && !in_array($s['estado'], array('activa', 'gracia'), true));
    }

    /** Inicia la prueba gratis. $forzar (operador): permite repetirla y fijar los dias; nunca pisa una suscripcion pagada vigente. */
    public static function iniciarPrueba($empresaId, $planId, $dias, $forzar, $quien)
    {
        $plan = Db::uno('SELECT * FROM planes WHERE id = ? AND activo = 1', array((int) $planId));
        $empresa = Db::uno('SELECT id FROM empresas WHERE id = ?', array((int) $empresaId));
        if (!$plan || !$empresa) {
            throw new InvalidArgumentException('La empresa o el plan no existen (o el plan esta inactivo).');
        }
        if (!$forzar && (int) $plan['dias_prueba'] <= 0) {
            throw new InvalidArgumentException('Este plan no ofrece prueba gratis.');
        }
        $dias = $dias === null ? (int) $plan['dias_prueba'] : (int) $dias;
        if ($dias < 1 || $dias > 365) {
            throw new InvalidArgumentException('Los dias de prueba deben estar entre 1 y 365.');
        }
        $s = Db::uno('SELECT * FROM suscripciones WHERE empresa_id = ?', array((int) $empresaId));
        if ($s && in_array($s['estado'], array('activa', 'gracia'), true)) {
            throw new InvalidArgumentException('La empresa ya tiene una suscripcion pagada vigente.');
        }
        if (!$forzar) {
            if (!self::puedeProbar($empresaId, $plan)) {
                throw new InvalidArgumentException($s && (int) $s['prueba_usada'] === 1 ? 'Su empresa ya uso su prueba gratis.' : 'Este plan no ofrece prueba gratis.');
            }
        }
        $ahora = Db::ahora();
        $vence = self::sumarDias($ahora, $dias);
        Db::transaccion(function () use ($empresaId, $plan, $ahora, $vence) {
            self::guardar($empresaId, array('plan_id' => (int) $plan['id'], 'periodo' => 'prueba', 'estado' => 'prueba', 'inicio' => $ahora, 'vence' => $vence, 'gracia_hasta' => null, 'prueba_usada' => 1));
            Suscripciones::asignar($empresaId, $plan['id'], 'prueba gratis');
        });
        Auditoria::registrar($empresaId, null, 'prueba_iniciada', $plan['nombre'] . ' ' . $dias . ' dias, hasta ' . substr($vence, 0, 10) . ' (' . $quien . ')');
        return array('vence' => $vence, 'plan' => $plan['nombre'], 'dias' => $dias);
    }

    // ------------------------------------------------------------------ vencimientos (lo ejecuta el worker)
    /** Plan de destino al vencer, o null si no hay uno configurado y valido. */
    public static function planAlVencer()
    {
        $id = Ajustes::get('plan_vencido_id');
        if ($id === null || $id === '' || !ctype_digit($id)) {
            return null;
        }
        return Db::uno('SELECT * FROM planes WHERE id = ? AND activo = 1', array((int) $id));
    }

    private static function bajar(array $s, $evento)
    {
        $e = Db::uno('SELECT plan_id FROM empresas WHERE id = ?', array($s['empresa_id']));
        $destino = self::planAlVencer();
        if ($e && (int) $e['plan_id'] === (int) $s['plan_id']) {
            if ($destino && (int) $destino['id'] !== (int) $s['plan_id']) {
                Suscripciones::asignar($s['empresa_id'], $destino['id'], 'suscripcion vencida');
                Auditoria::registrar($s['empresa_id'], null, $evento, 'paso al plan ' . $destino['nombre']);
                return;
            }
            Auditoria::registrar($s['empresa_id'], null, $evento, 'sin plan de destino configurado: el plan no se cambio');
            return;
        }
        Auditoria::registrar($s['empresa_id'], null, $evento, 'su plan ya habia sido cambiado por otra via');
    }

    /** Pasa de estado lo que ya vencio. Seguro con varios workers (cada paso se toma con un UPDATE condicional). Devuelve cuantos cambios hizo. */
    public static function procesarVencimientos()
    {
        $n = 0;
        $ahora = Db::ahora();
        $gracia = Ajustes::dias('dias_gracia');
        foreach (Db::consultar("SELECT * FROM suscripciones WHERE estado = 'prueba' AND vence <= ?", array($ahora)) as $s) {
            if (Db::afectadas("UPDATE suscripciones SET estado = 'vencida', actualizado = ? WHERE id = ? AND estado = 'prueba' AND vence <= ?", array($ahora, $s['id'], $ahora)) === 1) {
                self::bajar($s, 'prueba_terminada');
                $n++;
            }
        }
        foreach (Db::consultar("SELECT * FROM suscripciones WHERE estado = 'activa' AND vence <= ?", array($ahora)) as $s) {
            if ($gracia > 0) {
                $hasta = self::sumarDias($s['vence'], $gracia);
                if (Db::afectadas("UPDATE suscripciones SET estado = 'gracia', gracia_hasta = ?, actualizado = ? WHERE id = ? AND estado = 'activa' AND vence <= ?", array($hasta, $ahora, $s['id'], $ahora)) === 1) {
                    Auditoria::registrar($s['empresa_id'], null, 'suscripcion_en_gracia', 'vencio el ' . substr($s['vence'], 0, 10) . '; tiene hasta el ' . substr($hasta, 0, 10));
                    $n++;
                }
            } elseif (Db::afectadas("UPDATE suscripciones SET estado = 'vencida', actualizado = ? WHERE id = ? AND estado = 'activa' AND vence <= ?", array($ahora, $s['id'], $ahora)) === 1) {
                self::bajar($s, 'suscripcion_vencida');
                $n++;
            }
        }
        foreach (Db::consultar("SELECT * FROM suscripciones WHERE estado = 'gracia' AND gracia_hasta <= ?", array($ahora)) as $s) {
            if (Db::afectadas("UPDATE suscripciones SET estado = 'vencida', actualizado = ? WHERE id = ? AND estado = 'gracia' AND gracia_hasta <= ?", array($ahora, $s['id'], $ahora)) === 1) {
                self::bajar($s, 'suscripcion_vencida');
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ avisos para el cliente
    /** Mensaje para el portal segun el estado de la suscripcion; null si no hay nada que decir. @return array|null ('tipo' => info|aviso|alerta, 'texto') */
    public static function aviso($empresaId)
    {
        $s = self::suscripcion($empresaId);
        if (!$s) {
            return null;
        }
        $f = function ($fecha) {
            return date('d/m/Y', strtotime($fecha));
        };
        $dias = self::diasHasta($s['vence']);
        switch ($s['estado']) {
            case 'prueba':
                return array('tipo' => 'info', 'texto' => 'Está en su prueba gratis del plan ' . $s['plan'] . ': termina el ' . $f($s['vence']) . ($dias > 0 ? ' (' . $dias . ' ' . ($dias === 1 ? 'día' : 'días') . ')' : '') . '. Para seguir con este plan, contrátelo antes de esa fecha.');
            case 'activa':
                if ($dias <= Ajustes::dias('dias_aviso')) {
                    return array('tipo' => 'aviso', 'texto' => 'Su plan ' . $s['plan'] . ' vence el ' . $f($s['vence']) . ' (' . max(0, $dias) . ' ' . ($dias === 1 ? 'día' : 'días') . '). Renueve para no perder el servicio.');
                }
                return null;
            case 'gracia':
                $dest = self::planAlVencer();
                return array('tipo' => 'alerta', 'texto' => 'Su suscripción al plan ' . $s['plan'] . ' venció el ' . $f($s['vence']) . '. Puede renovar hasta el ' . $f($s['gracia_hasta']) . '; después' . ($dest ? ' pasará al plan ' . $dest['nombre'] . '.' : ' el servicio podría limitarse.'));
            case 'vencida':
                $dest = self::planAlVencer();
                return array('tipo' => 'alerta', 'texto' => 'Su suscripción al plan ' . $s['plan'] . ' venció el ' . $f($s['vence']) . ($dest ? ' y su cuenta está en el plan ' . $dest['nombre'] . '.' : '.') . ' Renueve para recuperar su plan.');
        }
        return null;
    }
}
