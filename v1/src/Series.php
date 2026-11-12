<?php
/**
 * Numeracion de documentos (series y correlativos), sincronizada con el sistema comercial.
 *
 * Cada serie (por empresa y entorno) tiene un "dueño":
 *   - modo "cliente": el sistema comercial numera; la API acepta el numero que le envien y APRENDE el mayor
 *                     (ultimo_numero) para que ambos queden sincronizados.
 *   - modo "api":     la API numera. Se puede omitir "numero" (se asigna solo, atomico) o RESERVAR numeros antes
 *                     (POST /series/reservar), imprimirlos en el comprobante y enviarlos a SUNAT despues.
 * Resumenes, bajas y reversiones usan un correlativo por dia (serie = AAAAMMDD), siempre automatico si se omite.
 *
 * Los numeros se comparan como ENTEROS: 150 y 00000150 son el mismo documento.
 */
class Series
{
    public static $numerados = array('01', '03', '07', '08', '20', '09');
    public static $diarios = array('RC', 'RA', 'RR');
    const MAXIMO = 99999999;

    public static function patron($tipo)
    {
        $p = array('01' => '/^F[A-Z0-9]{3}$/', '03' => '/^B[A-Z0-9]{3}$/', '07' => '/^[FB][A-Z0-9]{3}$/', '08' => '/^[FB][A-Z0-9]{3}$/', '20' => '/^R[A-Z0-9]{3}$/', '09' => '/^[TV][A-Z0-9]{3}$/');
        return isset($p[$tipo]) ? $p[$tipo] : null;
    }

    public static function validarSerie($tipo, $serie)
    {
        $p = self::patron($tipo);
        if ($p === null) {
            throw new ApiError(422, 'validacion', 'Tipo de documento no numerable: use ' . implode(', ', self::$numerados) . '.', array(array('campo' => 'tipo', 'mensaje' => 'valor no permitido')));
        }
        if (!preg_match($p, (string) $serie)) {
            throw new ApiError(422, 'validacion', 'La serie no es valida para ese tipo de documento.', array(array('campo' => 'serie', 'mensaje' => 'formato incorrecto para el tipo ' . $tipo . ' (ej. 01=F001, 03=B001, 07/08=FC01, 20=R001, 09=T001)')));
        }
    }

    public static function numeroN($n)
    {
        return preg_match('/^\d{1,8}$/', (string) $n) ? (int) $n : null;
    }

    /** Comprobantes: 8 digitos con ceros a la izquierda (ej. 00000150). Resumenes/bajas/reversiones: sin relleno. */
    public static function formato($tipo, $n)
    {
        return in_array($tipo, self::$diarios, true) ? (string) (int) $n : str_pad((string) (int) $n, 8, '0', STR_PAD_LEFT);
    }

    public static function buscar($empresaId, $entorno, $tipo, $serie)
    {
        return Db::uno('SELECT * FROM series WHERE empresa_id = ? AND entorno = ? AND tipo = ? AND serie = ?', array($empresaId, $entorno, $tipo, (string) $serie));
    }

    /** Representacion publica de una serie. */
    public static function ver(array $s)
    {
        $abiertas = Db::uno("SELECT COUNT(*) AS n FROM documentos WHERE empresa_id = ? AND entorno = ? AND tipo = ? AND serie = ? AND estado = 'reservado'",
            array($s['empresa_id'], $s['entorno'], $s['tipo'], $s['serie']));
        return array(
            'tipo' => $s['tipo'], 'serie' => $s['serie'], 'modo' => $s['modo'],
            'entorno' => $s['entorno'] === 'live' ? 'produccion' : 'prueba',
            'ultimo_numero' => (int) $s['ultimo_numero'],
            'siguiente_numero' => self::formato($s['tipo'], (int) $s['ultimo_numero'] + 1),
            'reservas_abiertas' => (int) $abiertas['n'],
        );
    }

    /** Mayor numero que ya existe para esa serie (para no empezar por debajo de lo ya usado). */
    private static function mayorUsado($empresaId, $entorno, $tipo, $serie)
    {
        $r = Db::uno('SELECT MAX(numero_n) AS m FROM documentos WHERE empresa_id = ? AND entorno = ? AND tipo = ? AND serie = ? AND numero_n IS NOT NULL',
            array($empresaId, $entorno, $tipo, (string) $serie));
        return $r && $r['m'] !== null ? (int) $r['m'] : 0;
    }

    /** Crea o actualiza una serie. El contador solo puede subir (nunca baja: evitaria repetir numeros). */
    public static function guardar(array $ctx, $tipo, $serie, $modo, $ultimo)
    {
        self::validarSerie($tipo, $serie);
        if ($modo !== null && !in_array($modo, array('cliente', 'api'), true)) {
            throw new ApiError(422, 'validacion', 'modo invalido.', array(array('campo' => 'modo', 'mensaje' => 'use "cliente" (su sistema numera) o "api" (la API numera)')));
        }
        if ($ultimo !== null && (!is_int($ultimo) || $ultimo < 0 || $ultimo > self::MAXIMO)) {
            throw new ApiError(422, 'validacion', 'ultimo_numero invalido.', array(array('campo' => 'ultimo_numero', 'mensaje' => 'entero entre 0 y 99999999: el ultimo numero que ya uso en esa serie')));
        }
        $e = (int) $ctx['empresa']['id'];
        $ent = $ctx['key']['entorno'];
        $usado = self::mayorUsado($e, $ent, $tipo, $serie);
        $actual = self::buscar($e, $ent, $tipo, $serie);
        if ($actual) {
            $nuevo = max((int) $actual['ultimo_numero'], $ultimo === null ? 0 : $ultimo, $usado);
            Db::ejecutar('UPDATE series SET modo = ?, ultimo_numero = ? WHERE id = ?', array($modo === null ? $actual['modo'] : $modo, $nuevo, $actual['id']));
            $creada = false;
        } else {
            Db::ejecutar('INSERT INTO series (empresa_id, entorno, tipo, serie, modo, ultimo_numero, creado) VALUES (?,?,?,?,?,?,?)',
                array($e, $ent, $tipo, $serie, $modo === null ? 'cliente' : $modo, max($ultimo === null ? 0 : $ultimo, $usado), Db::ahora()));
            $creada = true;
        }
        return array(self::ver(self::buscar($e, $ent, $tipo, $serie)), $creada);
    }

    public static function listar(array $ctx)
    {
        $filas = Db::consultar('SELECT * FROM series WHERE empresa_id = ? AND entorno = ? ORDER BY tipo, serie', array($ctx['empresa']['id'], $ctx['key']['entorno']));
        $out = array();
        foreach ($filas as $s) {
            $out[] = self::ver($s);
        }
        return $out;
    }

    /** Falta "numero": la serie debe existir y ser del modo "api". */
    public static function exigirAutomatica(array $ctx, $tipo, $serie)
    {
        $s = self::buscar($ctx['empresa']['id'], $ctx['key']['entorno'], $tipo, (string) $serie);
        if (!$s) {
            throw new ApiError(422, 'numero_requerido', "Falta el numero. Envielo, o registre la serie $serie en modo automatico (POST /series con modo \"api\") para que la API lo asigne.");
        }
        if ($s['modo'] !== 'api') {
            throw new ApiError(422, 'numero_requerido', "Falta el numero: la serie $serie la numera su sistema (modo \"cliente\"). Envielo, o cambie la serie a modo \"api\".");
        }
    }

    /** Resumenes, bajas y reversiones: crea (si no existe) el contador del dia. */
    public static function asegurarDiaria(array $ctx, $tipo, $fecha)
    {
        $e = (int) $ctx['empresa']['id'];
        $ent = $ctx['key']['entorno'];
        if (!self::buscar($e, $ent, $tipo, $fecha)) {
            try {
                Db::ejecutar('INSERT INTO series (empresa_id, entorno, tipo, serie, modo, ultimo_numero, creado) VALUES (?,?,?,?,?,?,?)',
                    array($e, $ent, $tipo, $fecha, 'api', self::mayorUsado($e, $ent, $tipo, $fecha), Db::ahora()));
            } catch (PDOException $x) {
                // otra peticion la creo al mismo tiempo: sirve igual
            }
        }
    }

    /**
     * Reserva atomicamente $cantidad numeros consecutivos. Cada uno queda como una fila de documentos con el estado
     * indicado ('reservado' o 'procesando'). Devuelve las filas creadas.
     */
    public static function reservar(array $ctx, $tipo, $serie, $cantidad, $estado = 'reservado', $idem = null, $idemHash = null)
    {
        $e = (int) $ctx['empresa']['id'];
        $ent = $ctx['key']['entorno'];
        return Db::transaccion(function () use ($e, $ent, $tipo, $serie, $cantidad, $estado, $idem, $idemHash) {
            $s = self::buscar($e, $ent, $tipo, (string) $serie);
            if (!$s) {
                throw new ApiError(404, 'serie_no_registrada', "La serie $serie no esta registrada. Registrela con POST /series.");
            }
            if ($s['modo'] !== 'api') {
                throw new ApiError(409, 'serie_no_automatica', "La serie $serie la numera su sistema (modo \"cliente\"). Para que la API asigne numeros, cambie su modo a \"api\".");
            }
            Db::ejecutar('UPDATE series SET ultimo_numero = ultimo_numero + ? WHERE id = ?', array($cantidad, $s['id']));
            $fin = (int) Db::uno('SELECT ultimo_numero AS u FROM series WHERE id = ?', array($s['id']))['u'];
            if ($fin > self::MAXIMO) {
                throw new ApiError(409, 'serie_agotada', "La serie $serie llego al numero maximo (99999999). Registre una serie nueva.");
            }
            $filas = array();
            for ($n = $fin - $cantidad + 1; $n <= $fin; $n++) {
                $uid = 'doc_' . bin2hex(random_bytes(10));
                $num = self::formato($tipo, $n);
                $id = Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, idem_key, idem_hash, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    array($uid, $e, $tipo, (string) $serie, $num, $n, $ent, $estado, $idem, $idemHash, Db::ahora()));
                $filas[] = array('id' => $id, 'uid' => $uid, 'numero' => $num, 'serie' => (string) $serie);
            }
            return $filas;
        });
    }
    /**
     * Numero enviado por el cliente. Valida duplicados y, en series "api", que el numero haya sido reservado.
     * Devuelve la fila reutilizable (reserva o intento interrumpido) o null.
     */
    public static function resolverManual(array $ctx, $tipo, $serie, $numero)
    {
        $e = (int) $ctx['empresa']['id'];
        $ent = $ctx['key']['entorno'];
        $n = self::numeroN($numero);
        if ($n === null) {
            return null;
        }
        $ult = Db::uno('SELECT * FROM documentos WHERE empresa_id = ? AND entorno = ? AND tipo = ? AND serie = ? AND numero_n = ? ORDER BY id DESC LIMIT 1',
            array($e, $ent, $tipo, (string) $serie, $n));
        $reutilizable = null;
        if ($ult) {
            if ($ult['estado'] === 'aceptado' || $ult['estado'] === 'pendiente') {
                throw new ApiError(409, 'documento_duplicado', "Ya existe $tipo $serie-$numero con estado " . $ult['estado'] . '.', array('id' => $ult['uid']));
            }
            if ($ult['estado'] === 'procesando') {
                if (strtotime($ult['actualizado'] ?: $ult['creado']) > time() - 300) {
                    throw new ApiError(409, 'documento_en_proceso', "$tipo $serie-$numero se esta enviando (o una peticion anterior se interrumpio). Reintente con la misma Idempotency-Key o espere unos minutos.", array('id' => $ult['uid']));
                }
                $reutilizable = $ult;
            } elseif ($ult['estado'] === 'reservado' || $ult['estado'] === 'error') {
                $reutilizable = $ult;
            }
        }
        $s = self::buscar($e, $ent, $tipo, (string) $serie);
        if ($s && $s['modo'] === 'api' && !in_array($tipo, self::$diarios, true) && !$reutilizable && !($ult && in_array($ult['estado'], array('rechazado', 'simulado'), true))) {
            throw new ApiError(409, 'numero_no_reservado', "La serie $serie la numera la API: el numero $numero no esta reservado. Reserve uno con POST /series/reservar, u omita \"numero\" para que se asigne solo.");
        }
        return $reutilizable;
    }

    /** El sistema comercial uso este numero: el contador sube hasta alli (nunca baja). Si la serie no existia, la registra en modo "cliente". */
    public static function sincronizar($empresaId, $entorno, $tipo, $serie, $numero)
    {
        $n = self::numeroN($numero);
        if ($n === null) {
            return;
        }
        if (in_array($tipo, self::$diarios, true)) {
            // Resumen/baja/reversion: si ese dia ya hay contador automatico, subirlo; si no, no hace falta crearlo
            $d = self::buscar($empresaId, $entorno, $tipo, (string) $serie);
            if ($d) {
                Db::ejecutar('UPDATE series SET ultimo_numero = ? WHERE id = ? AND ultimo_numero < ?', array($n, $d['id'], $n));
            }
            return;
        }
        if (!in_array($tipo, self::$numerados, true)) {
            return;
        }
        $s = self::buscar($empresaId, $entorno, $tipo, (string) $serie);
        if (!$s) {
            try {
                Db::ejecutar('INSERT INTO series (empresa_id, entorno, tipo, serie, modo, ultimo_numero, creado) VALUES (?,?,?,?,?,?,?)',
                    array($empresaId, $entorno, $tipo, (string) $serie, 'cliente', $n, Db::ahora()));
                return;
            } catch (PDOException $x) {
                $s = self::buscar($empresaId, $entorno, $tipo, (string) $serie);
                if (!$s) {
                    throw $x;
                }
            }
        }
        Db::ejecutar('UPDATE series SET ultimo_numero = ? WHERE id = ? AND ultimo_numero < ?', array($n, $s['id'], $n));
    }

    /** SUNAT rechazo un documento numerado automaticamente: si sigue siendo el ultimo, su numero se vuelve a poder usar. */
    public static function liberar($empresaId, $entorno, $tipo, $serie, $numero)
    {
        $n = self::numeroN($numero);
        if ($n === null) {
            return false;
        }
        $s = self::buscar($empresaId, $entorno, $tipo, (string) $serie);
        if (!$s) {
            return false;
        }
        return Db::afectadas('UPDATE series SET ultimo_numero = ultimo_numero - 1 WHERE id = ? AND ultimo_numero = ? AND ultimo_numero > 0', array($s['id'], $n)) === 1;
    }
}
