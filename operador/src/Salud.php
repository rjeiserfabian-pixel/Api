<?php
/**
 * Estado del servicio para el operador: el worker (trabajo en segundo plano) y sus colas.
 * El worker escribe storage/worker.heartbeat al inicio de cada ciclo (bin/worker.php).
 */
class Salud
{
    /** @return array('estado' => activo|demorado|detenido|nunca, 'edad' => segundos|null, 'ultimo' => fecha|null) */
    public static function worker()
    {
        $f = dirname(__DIR__, 2) . '/storage/worker.heartbeat';
        $t = is_file($f) ? (int) trim((string) @file_get_contents($f)) : 0;
        if ($t <= 0) {
            return array('estado' => 'nunca', 'edad' => null, 'ultimo' => null);
        }
        $edad = max(0, time() - $t);
        // un ciclo con reintentos hacia SUNAT puede durar un par de minutos: 120 s sigue siendo "activo"
        $estado = $edad <= 120 ? 'activo' : ($edad <= 600 ? 'demorado' : 'detenido');
        return array('estado' => $estado, 'edad' => $edad, 'ultimo' => date('Y-m-d H:i:s', $t));
    }

    /** Trabajo que espera al worker. */
    public static function colas()
    {
        $ahora = Db::ahora();
        $n = function ($sql, $p = array()) {
            $r = Db::uno($sql, $p);
            return $r ? (int) $r['n'] : 0;
        };
        $viejaEnt = Db::uno("SELECT MIN(proximo_intento) AS t FROM entregas WHERE estado = 'pendiente' AND proximo_intento IS NOT NULL AND proximo_intento <= ?", array($ahora));
        $viejaDoc = Db::uno("SELECT MIN(proximo_intento) AS t FROM documentos WHERE estado = 'error' AND proximo_intento IS NOT NULL AND proximo_intento <= ? AND cuerpo IS NOT NULL", array($ahora));
        $espera = 0; // segundos que lleva esperando el trabajo vencido mas antiguo
        foreach (array($viejaEnt, $viejaDoc) as $v) {
            if ($v && $v['t']) {
                $espera = max($espera, time() - strtotime($v['t']));
            }
        }
        return array(
            'reintentos_programados' => $n("SELECT COUNT(*) AS n FROM documentos WHERE estado = 'error' AND proximo_intento IS NOT NULL AND cuerpo IS NOT NULL"),
            'reintentos_vencidos' => $n("SELECT COUNT(*) AS n FROM documentos WHERE estado = 'error' AND proximo_intento IS NOT NULL AND proximo_intento <= ? AND cuerpo IS NOT NULL", array($ahora)),
            'entregas_pendientes' => $n("SELECT COUNT(*) AS n FROM entregas WHERE estado = 'pendiente'"),
            'entregas_fallidas_7d' => $n("SELECT COUNT(*) AS n FROM entregas WHERE estado = 'fallido' AND creado >= ?", array(date('Y-m-d H:i:s', strtotime('-7 days')))),
            'documentos_error_7d' => $n("SELECT COUNT(*) AS n FROM documentos WHERE estado = 'error' AND creado >= ?", array(date('Y-m-d H:i:s', strtotime('-7 days')))),
            'espera_max' => $espera,
        );
    }

    public static function edadTexto($seg)
    {
        if ($seg === null) {
            return 'nunca';
        }
        if ($seg < 90) {
            return 'hace ' . (int) $seg . ' s';
        }
        if ($seg < 5400) {
            return 'hace ' . (int) round($seg / 60) . ' min';
        }
        if ($seg < 172800) {
            return 'hace ' . (int) round($seg / 3600) . ' h';
        }
        return 'hace ' . (int) round($seg / 86400) . ' días';
    }
}
