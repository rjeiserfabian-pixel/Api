<?php
/**
 * Asignacion de planes a empresas. UN solo lugar para cambiar el plan, sea quien sea el origen:
 *   - el operador (panel /operador),
 *   - el propio cliente desde el portal (solo planes marcados "autoservicio"),
 *   - en el futuro, la confirmacion de un pago (la pasarela llamara a Suscripciones::asignar()).
 * Cada cambio queda en el registro de actividad de la empresa.
 */
class Suscripciones
{
    public static function planesActivos()
    {
        return Db::consultar('SELECT * FROM planes WHERE activo = 1 ORDER BY (cuota_mensual IS NULL), cuota_mensual, id');
    }

    /**
     * Cambia el plan de la empresa ($planId null = sin plan, sin ningun limite).
     * $origen: texto corto para la auditoria ("operador x@y", "cliente", "pago ...").
     */
    public static function asignar($empresaId, $planId, $origen)
    {
        $e = Db::uno('SELECT * FROM empresas WHERE id = ?', array((int) $empresaId));
        if (!$e) {
            throw new InvalidArgumentException('No existe la empresa.');
        }
        $nuevo = 'sin plan (sin limites)';
        if ($planId !== null) {
            $p = Db::uno('SELECT * FROM planes WHERE id = ? AND activo = 1', array((int) $planId));
            if (!$p) {
                throw new InvalidArgumentException('El plan no existe o esta inactivo.');
            }
            $nuevo = $p['nombre'];
        }
        $antes = 'sin plan';
        if (!empty($e['plan_id'])) {
            $a = Db::uno('SELECT nombre FROM planes WHERE id = ?', array($e['plan_id']));
            $antes = $a ? $a['nombre'] : 'sin plan';
        }
        Db::ejecutar('UPDATE empresas SET plan_id = ? WHERE id = ?', array($planId === null ? null : (int) $planId, (int) $empresaId));
        Auditoria::registrar($empresaId, null, 'plan_cambiado', $antes . ' -> ' . $nuevo . ' (' . $origen . ')');
        return $nuevo;
    }

    public static function pendienteDe($empresaId)
    {
        return Db::uno("SELECT s.*, p.nombre AS plan FROM solicitudes_plan s JOIN planes p ON p.id = s.plan_id WHERE s.empresa_id = ? AND s.estado = 'pendiente' ORDER BY s.id DESC LIMIT 1", array((int) $empresaId));
    }

    /**
     * Pedido de un cliente. Si el plan es de autoservicio se asigna al instante; si no, queda PENDIENTE para el operador.
     * Devuelve 'automatica' o 'pendiente'.
     */
    public static function solicitar(array $empresa, array $usuario, $planId, $nota = '')
    {
        $p = Db::uno('SELECT * FROM planes WHERE id = ? AND activo = 1', array((int) $planId));
        if (!$p) {
            throw new InvalidArgumentException('Ese plan no esta disponible.');
        }
        if ((int) $empresa['plan_id'] === (int) $p['id']) {
            throw new InvalidArgumentException('Ya tiene ese plan.');
        }
        $nota = substr(trim((string) $nota), 0, 200);
        Db::ejecutar("UPDATE solicitudes_plan SET estado = 'cancelada', resuelto = ?, resuelto_por = 'reemplazada por una solicitud nueva' WHERE empresa_id = ? AND estado = 'pendiente'", array(Db::ahora(), (int) $empresa['id']));
        if ((int) $p['autoservicio'] === 1) {
            self::asignar($empresa['id'], $p['id'], 'cliente ' . $usuario['email']);
            Db::ejecutar('INSERT INTO solicitudes_plan (empresa_id, usuario_id, plan_id, estado, nota, creado, resuelto, resuelto_por) VALUES (?,?,?,?,?,?,?,?)',
                array((int) $empresa['id'], (int) $usuario['id'], (int) $p['id'], 'automatica', $nota, Db::ahora(), Db::ahora(), 'automatico'));
            return 'automatica';
        }
        Db::ejecutar('INSERT INTO solicitudes_plan (empresa_id, usuario_id, plan_id, estado, nota, creado) VALUES (?,?,?,?,?,?)',
            array((int) $empresa['id'], (int) $usuario['id'], (int) $p['id'], 'pendiente', $nota, Db::ahora()));
        Auditoria::registrar($empresa['id'], $usuario['id'], 'plan_solicitado', $p['nombre']);
        return 'pendiente';
    }

    public static function cancelar($empresaId, $usuarioId)
    {
        $n = Db::afectadas("UPDATE solicitudes_plan SET estado = 'cancelada', resuelto = ?, resuelto_por = 'cliente' WHERE empresa_id = ? AND estado = 'pendiente'", array(Db::ahora(), (int) $empresaId));
        if ($n > 0) {
            Auditoria::registrar($empresaId, $usuarioId, 'solicitud_cancelada', '');
        }
        return $n;
    }

    /** El operador aprueba (asigna el plan) o rechaza una solicitud pendiente. */
    public static function resolver($solicitudId, $aprobar, $operadorEmail)
    {
        $s = Db::uno("SELECT * FROM solicitudes_plan WHERE id = ? AND estado = 'pendiente'", array((int) $solicitudId));
        if (!$s) {
            throw new InvalidArgumentException('La solicitud ya no esta pendiente.');
        }
        if ($aprobar) {
            self::asignar($s['empresa_id'], $s['plan_id'], 'solicitud aprobada por ' . $operadorEmail);
        } else {
            $p = Db::uno('SELECT nombre FROM planes WHERE id = ?', array($s['plan_id']));
            Auditoria::registrar($s['empresa_id'], null, 'solicitud_rechazada', ($p ? $p['nombre'] : '') . ' (' . $operadorEmail . ')');
        }
        Db::ejecutar('UPDATE solicitudes_plan SET estado = ?, resuelto = ?, resuelto_por = ? WHERE id = ?', array($aprobar ? 'aprobada' : 'rechazada', Db::ahora(), $operadorEmail, $s['id']));
    }

    /** Ultimos periodos de consumo de una empresa (para el operador). */
    public static function consumo($empresaId, $meses = 6)
    {
        return Db::consultar('SELECT periodo, documentos FROM uso WHERE empresa_id = ? ORDER BY periodo DESC LIMIT ' . (int) $meses, array((int) $empresaId));
    }
}
