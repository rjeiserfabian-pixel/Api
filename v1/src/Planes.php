<?php
/**
 * Planes comerciales: limite de velocidad (peticiones por minuto) y cuota mensual de documentos.
 *
 * LIMITE DE VELOCIDAD (protege el servicio y a SUNAT; no se factura)
 *   Dos clases de peticion, cada una con su limite por API key y por minuto:
 *     "sunat"   : emitir (POST /comprobantes, /resumenes, /bajas, /reversiones, /retenciones, /guias) y consultar tickets.
 *                 Son caras: dependen de SUNAT, que limita la concurrencia.
 *     "general" : todo lo demas (consultas, series, webhooks...). Son baratas.
 *   Ademas, un TOPE GLOBAL de emisiones por minuto (LIMITE_GLOBAL_SUNAT_MIN en .env, por defecto 150, 0 = sin tope)
 *   para que, aunque muchos clientes emitan a la vez, ninguno degrade el servicio de los demas.
 *   Al excederse: HTTP 429 + cabecera Retry-After.
 *
 * CUOTA MENSUAL (lo que se vende)
 *   Solo cuentan los documentos EMITIDOS Y ACEPTADOS (o recibidos por SUNAT a la espera de ticket) con llave "live":
 *   factura, boleta, notas, retencion y guia. No cuentan: pruebas (llave test), consultas, reservas, rechazos de SUNAT,
 *   ni resumenes/bajas/reversiones. Un reintento con la misma Idempotency-Key nunca cobra dos veces.
 *   Al agotarse: HTTP 402 "cuota_agotada". Avisos por webhook al 80 % (cuota.advertencia) y al 100 % (cuota.agotada).
 *   El periodo es el mes calendario (hora de Lima).
 *
 * Una empresa sin plan asignado no tiene limites (compatibilidad): asigne uno con  php bin/admin.php empresa:plan
 */
class Planes
{
    /** Tipos de documento que consumen cuota. */
    public static $cuentan = array('01', '03', '07', '08', '20', '09');

    /** Valores recomendados (medidos: una emision tarda ~0.6-1 s y SUNAT tolera pocas simultaneas). php bin/admin.php plan:sembrar */
    public static $recomendados = array(
        array('nombre' => 'Prueba', 'cuota_mensual' => 0, 'limite_sunat_min' => 10, 'limite_general_min' => 30, 'max_webhooks' => 1),
        array('nombre' => 'Basico', 'cuota_mensual' => 500, 'limite_sunat_min' => 20, 'limite_general_min' => 120, 'max_webhooks' => 2),
        array('nombre' => 'Pro', 'cuota_mensual' => 5000, 'limite_sunat_min' => 60, 'limite_general_min' => 300, 'max_webhooks' => 5),
        array('nombre' => 'Empresa', 'cuota_mensual' => 50000, 'limite_sunat_min' => 120, 'limite_general_min' => 600, 'max_webhooks' => 5),
    );

    private static function entero($v)
    {
        return $v === null ? null : (int) $v;
    }

    /** Plan efectivo de la empresa (sin plan: sin limites). */
    public static function de(array $empresa)
    {
        if (!empty($empresa['plan_id'])) {
            $p = Db::uno('SELECT * FROM planes WHERE id = ? AND activo = 1', array($empresa['plan_id']));
            if ($p) {
                return array('id' => (int) $p['id'], 'nombre' => $p['nombre'], 'cuota_mensual' => self::entero($p['cuota_mensual']), 'limite_sunat_min' => self::entero($p['limite_sunat_min']),
                    'limite_general_min' => self::entero($p['limite_general_min']), 'max_webhooks' => (int) $p['max_webhooks']);
            }
        }
        return array('id' => null, 'nombre' => 'sin_plan', 'cuota_mensual' => null, 'limite_sunat_min' => null, 'limite_general_min' => null, 'max_webhooks' => 5);
    }

    public static function periodo()
    {
        return date('Y-m');
    }

    public static function reinicia()
    {
        return date('c', strtotime('first day of next month midnight'));
    }

    public static function usado($empresaId, $periodo = null)
    {
        $r = Db::uno('SELECT documentos FROM uso WHERE empresa_id = ? AND periodo = ?', array($empresaId, $periodo === null ? self::periodo() : $periodo));
        return $r ? (int) $r['documentos'] : 0;
    }

    // ------------------------------------------------------------------ limite de velocidad
    private static function incrementar($clave, $ventana)
    {
        if (Db::afectadas('UPDATE limites SET n = n + 1 WHERE clave = ?', array($clave)) === 0) {
            try {
                Db::ejecutar('INSERT INTO limites (clave, n, ventana) VALUES (?, 1, ?)', array($clave, $ventana));
                return 1;
            } catch (PDOException $e) { // otra peticion la creo al mismo tiempo
                Db::afectadas('UPDATE limites SET n = n + 1 WHERE clave = ?', array($clave));
            }
        }
        $r = Db::uno('SELECT n FROM limites WHERE clave = ?', array($clave));
        return $r ? (int) $r['n'] : 1;
    }

    /** Borra contadores de minutos ya pasados. */
    public static function limpiar()
    {
        return Db::afectadas('DELETE FROM limites WHERE ventana < ?', array((int) floor(time() / 60) - 10));
    }

    /** Aplica el limite de velocidad de la API key para la clase "sunat" o "general". Lanza ApiError 429 si se excede. */
    public static function limitar(array $ctx, $clase)
    {
        $plan = self::de($ctx['empresa']);
        $limite = $clase === 'sunat' ? $plan['limite_sunat_min'] : $plan['limite_general_min'];
        $ventana = (int) floor(time() / 60);
        $espera = max(1, ($ventana + 1) * 60 - time());
        if (mt_rand(1, 300) === 1) {
            self::limpiar();
        }

        if ($limite !== null) {
            $n = self::incrementar('k' . (int) $ctx['key']['id'] . ':' . $clase . ':' . $ventana, $ventana);
            Respuesta::cabecera('X-RateLimit-Limit', $limite);
            Respuesta::cabecera('X-RateLimit-Remaining', max(0, $limite - $n));
            Respuesta::cabecera('X-RateLimit-Reset', $espera);
            if ($n > $limite) {
                $que = $clase === 'sunat' ? 'emisiones y consultas de ticket' : 'peticiones';
                throw new ApiError(429, 'limite_de_peticiones', "Supero el limite de $limite $que por minuto de su plan {$plan['nombre']}. Espere $espera segundos y reintente.",
                    array('clase' => $clase, 'limite_por_minuto' => $limite, 'reintentar_en_segundos' => $espera), array('Retry-After' => (string) $espera));
            }
        }
        // Tope global de emisiones: protege a SUNAT y a los demas clientes (se cuenta despues del limite propio: una llave abusiva no lo consume)
        if ($clase === 'sunat') {
            $g = (int) api_env('LIMITE_GLOBAL_SUNAT_MIN', 150);
            if ($g > 0 && self::incrementar('g:sunat:' . $ventana, $ventana) > $g) {
                throw new ApiError(429, 'servicio_saturado', 'El servicio esta atendiendo muchas emisiones a la vez. Espere unos segundos y reintente.',
                    array('reintentar_en_segundos' => $espera), array('Retry-After' => (string) $espera));
            }
        }
    }

    // ------------------------------------------------------------------ cuota mensual
    private static function cuentaEnCuota(array $ctx, $tipo)
    {
        return $ctx['key']['entorno'] === 'live' && in_array($tipo, self::$cuentan, true);
    }

    /** Antes de emitir: bloquea si la cuota del mes ya se agoto. Solo llaves live y tipos que cuentan. */
    public static function exigirCuota(array $ctx, $tipo)
    {
        if (!self::cuentaEnCuota($ctx, $tipo)) {
            return;
        }
        $plan = self::de($ctx['empresa']);
        $cuota = $plan['cuota_mensual'];
        if ($cuota === null) {
            return;
        }
        $usado = self::usado((int) $ctx['empresa']['id']);
        if ($usado >= $cuota) {
            $msg = $cuota === 0
                ? "Su plan {$plan['nombre']} no incluye emision en produccion. Contacte a su proveedor para contratar un plan."
                : "Agoto los $cuota documentos de su plan {$plan['nombre']} este mes. La cuota se reinicia el " . date('d/m/Y', strtotime(self::reinicia())) . '. Contacte a su proveedor para ampliar su plan.';
            throw new ApiError(402, 'cuota_agotada', $msg, array('plan' => $plan['nombre'], 'cuota_mensual' => $cuota, 'usados' => $usado, 'reinicia' => self::reinicia()),
                array('X-Quota-Limit' => (string) $cuota, 'X-Quota-Remaining' => '0'));
        }
    }

    /** Cabeceras de cuota para la respuesta de una emision (solo live). */
    public static function cabecerasCuota(array $ctx)
    {
        if ($ctx['key']['entorno'] !== 'live') {
            return;
        }
        $plan = self::de($ctx['empresa']);
        if ($plan['cuota_mensual'] === null) {
            return;
        }
        $u = self::usado((int) $ctx['empresa']['id']);
        Respuesta::cabecera('X-Quota-Limit', $plan['cuota_mensual']);
        Respuesta::cabecera('X-Quota-Remaining', max(0, $plan['cuota_mensual'] - $u));
    }

    /** El documento llego a "aceptado" (o "pendiente" de ticket): consume UNA unidad, una sola vez por documento. */
    public static function consumir($empresaId, $entorno, array $doc)
    {
        if ($entorno !== 'live' || !in_array($doc['tipo'], self::$cuentan, true)) {
            return;
        }
        $periodo = self::periodo();
        if (Db::afectadas('UPDATE documentos SET cuota = ? WHERE id = ? AND cuota IS NULL', array($periodo, $doc['id'])) !== 1) {
            return; // ya estaba contado
        }
        if (Db::afectadas('UPDATE uso SET documentos = documentos + 1 WHERE empresa_id = ? AND periodo = ?', array($empresaId, $periodo)) === 0) {
            try {
                Db::ejecutar('INSERT INTO uso (empresa_id, periodo, documentos) VALUES (?, ?, 1)', array($empresaId, $periodo));
            } catch (PDOException $e) {
                Db::afectadas('UPDATE uso SET documentos = documentos + 1 WHERE empresa_id = ? AND periodo = ?', array($empresaId, $periodo));
            }
        }
        $empresa = Db::uno('SELECT * FROM empresas WHERE id = ?', array($empresaId));
        if ($empresa) {
            self::avisos($empresaId, self::de($empresa), $periodo);
        }
    }

    /** El documento (una guia pendiente) termino rechazado: se devuelve la unidad. */
    public static function devolver(array $doc)
    {
        $act = Db::uno('SELECT cuota, empresa_id FROM documentos WHERE id = ?', array($doc['id']));
        if (!$act || $act['cuota'] === null) {
            return;
        }
        if (Db::afectadas('UPDATE documentos SET cuota = NULL WHERE id = ? AND cuota IS NOT NULL', array($doc['id'])) === 1) {
            Db::ejecutar('UPDATE uso SET documentos = documentos - 1 WHERE empresa_id = ? AND periodo = ? AND documentos > 0', array($act['empresa_id'], $act['cuota']));
        }
    }

    /** Avisos por webhook al 80 % y al 100 % (cada uno una sola vez por mes). */
    private static function avisos($empresaId, array $plan, $periodo)
    {
        $cuota = $plan['cuota_mensual'];
        if ($cuota === null || $cuota <= 0) {
            return;
        }
        $u = Db::uno('SELECT * FROM uso WHERE empresa_id = ? AND periodo = ?', array($empresaId, $periodo));
        if (!$u) {
            return;
        }
        $usados = (int) $u['documentos'];
        $datos = array('plan' => $plan['nombre'], 'periodo' => $periodo, 'cuota_mensual' => $cuota, 'usados' => $usados, 'restantes' => max(0, $cuota - $usados),
            'porcentaje' => round(100 * $usados / $cuota, 1), 'reinicia' => self::reinicia());
        try {
            if ($usados * 100 >= $cuota * 80 && (int) $u['aviso80'] === 0 && Db::afectadas('UPDATE uso SET aviso80 = 1 WHERE id = ? AND aviso80 = 0', array($u['id'])) === 1) {
                Webhooks::eventoGeneral($empresaId, 'live', 'cuota.advertencia', $datos);
            }
            if ($usados >= $cuota && (int) $u['aviso100'] === 0 && Db::afectadas('UPDATE uso SET aviso100 = 1 WHERE id = ? AND aviso100 = 0', array($u['id'])) === 1) {
                Webhooks::eventoGeneral($empresaId, 'live', 'cuota.agotada', $datos);
            }
        } catch (Exception $e) {
            error_log('[v1] no se pudo registrar el aviso de cuota: ' . $e->getMessage());
        }
    }

    /** GET /uso */
    public static function uso(array $ctx)
    {
        $plan = self::de($ctx['empresa']);
        $usados = self::usado((int) $ctx['empresa']['id']);
        $cuota = $plan['cuota_mensual'];
        return array(
            'plan' => array('nombre' => $plan['nombre'], 'cuota_mensual' => $cuota, 'limite_emisiones_por_minuto' => $plan['limite_sunat_min'],
                'limite_peticiones_por_minuto' => $plan['limite_general_min'], 'max_webhooks' => $plan['max_webhooks']),
            'periodo' => self::periodo(),
            'documentos_usados' => $usados,
            'documentos_restantes' => $cuota === null ? null : max(0, $cuota - $usados),
            'porcentaje' => ($cuota === null || $cuota === 0) ? null : round(100 * $usados / $cuota, 1),
            'reinicia' => self::reinicia(),
            'nota' => 'Solo los documentos emitidos y aceptados con una llave de produccion (live) consumen cuota. Las pruebas (test), las consultas y los rechazos de SUNAT no.',
        );
    }
}
