<?php
/**
 * Emision de documentos: idempotencia, deteccion de duplicados, llamada al motor e historial.
 */
require_once __DIR__ . '/Certificados.php';
require_once __DIR__ . '/Series.php';
require_once __DIR__ . '/Webhooks.php';
require_once __DIR__ . '/Planes.php';

class Documentos
{
    /** Cuerpo JSON de la peticion (obligatorio, objeto). */
    public static function cuerpo()
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            throw new ApiError(400, 'cuerpo_vacio', 'Envie un cuerpo JSON (Content-Type: application/json).');
        }
        if (strlen($raw) > 3 * 1024 * 1024) {
            throw new ApiError(413, 'cuerpo_muy_grande', 'El cuerpo supera 3 MB.');
        }
        $j = json_decode($raw, true);
        if (!is_array($j) || $j === array() || array_keys($j) === range(0, count($j) - 1)) {
            throw new ApiError(400, 'json_invalido', 'El cuerpo debe ser un objeto JSON valido: ' . json_last_error_msg());
        }
        return $j;
    }

    private static function cabecera($nombre)
    {
        $k = 'HTTP_' . strtoupper(str_replace('-', '_', $nombre));
        return isset($_SERVER[$k]) ? trim($_SERVER[$k]) : '';
    }

    private static function sinBase64(array $a)
    {
        unset($a['archivos']['xml_base_64'], $a['archivos']['cdr_base_64']);
        return $a;
    }

    public static function formatear(array $f)
    {
        $arch = array();
        foreach (array('xml' => 'ruta_xml', 'cdr' => 'ruta_cdr', 'pdf' => 'ruta_pdf') as $k => $c) {
            if (!empty($f[$c])) {
                $arch[$k] = $f[$c];
            }
        }
        return array(
            'id' => $f['uid'], 'tipo' => $f['tipo'], 'serie' => $f['serie'], 'numero' => $f['numero'],
            'entorno' => $f['entorno'] === 'live' ? 'produccion' : 'prueba', 'estado' => $f['estado'],
            'sunat' => array('codigo' => $f['codigo_sunat'], 'descripcion' => $f['mensaje_sunat']),
            'ticket' => $f['ticket'], 'hash' => $f['hash_firma'], 'archivos' => (object) $arch, 'creado' => $f['creado'],
        );
    }

    /** Espera (segundos) antes de cada reintento automatico de un envio que fallo por un problema TRANSITORIO: 1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h. */
    public static $espera = array(60, 300, 900, 3600, 10800, 21600, 43200);
    /** Espera (segundos) entre consultas automaticas del ticket de una guia. */
    public static $esperaTicket = array(60, 120, 300, 600, 1200, 2400, 3600, 7200, 14400, 21600);
    /** Solo estos fallos se reintentan solos: son del motor/SUNAT, no de los datos del cliente. */
    private static $transitorios = array('motor_no_disponible', 'motor_error', 'tiempo_agotado');

    private static function antiguedad(array $f)
    {
        return strtotime(!empty($f['actualizado']) ? $f['actualizado'] : $f['creado']);
    }

    /**
     * Emite un documento por el motor.
     *  - $numero = null  => numeracion automatica (la API asigna el numero; exige Idempotency-Key).
     *  - $aplicar        => funcion(&$cuerpo, $numero) que escribe el numero asignado en el cuerpo para el motor.
     * Garantias: un reintento con la misma Idempotency-Key nunca consume otro numero ni duplica el documento.
     * Con Idempotency-Key, si el envio falla por un problema transitorio, la API lo REINTENTA SOLA (bin/worker.php).
     */
    public static function emitir(array $ctx, $script, array $cuerpo, $tipo, $serie, $numero, $aplicar = null)
    {
        $empresaId = (int) $ctx['empresa']['id'];
        $entorno = $ctx['key']['entorno'];
        $idem = self::cabecera('Idempotency-Key');
        $hashCuerpo = hash('sha256', json_encode($cuerpo));
        $auto = ($numero === null);

        if ($idem !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $idem)) {
            throw new ApiError(400, 'idempotency_key_invalida', 'Idempotency-Key: hasta 64 caracteres (letras, numeros, . _ -).');
        }
        if ($auto && $idem === '') {
            throw new ApiError(422, 'idempotency_key_requerida', 'Para numeracion automatica (sin "numero") envie la cabecera Idempotency-Key: asi, si debe reintentar, se reutiliza el mismo numero y no se duplica el documento.');
        }

        // 1) Idempotencia: resultado final => se devuelve; intento interrumpido o fallido => se retoma con el mismo numero
        $fila = null;
        if ($idem !== '') {
            $previo = Db::uno('SELECT * FROM documentos WHERE empresa_id = ? AND idem_key = ?', array($empresaId, $idem));
            if ($previo) {
                if ($previo['idem_hash'] !== $hashCuerpo) {
                    throw new ApiError(409, 'idempotencia_conflicto', 'Esa Idempotency-Key ya se uso con una peticion distinta.');
                }
                if ($previo['estado'] !== 'procesando' && $previo['estado'] !== 'error') {
                    Respuesta::ok(self::formatear($previo), 200, array('Idempotent-Replay' => 'true'));
                }
                // Se "toma" el documento de forma atomica: si el worker (o otra peticion) ya lo esta enviando, este cliente espera
                $tomada = Db::afectadas("UPDATE documentos SET estado = 'procesando', actualizado = ? WHERE id = ? AND (estado = 'error' OR (estado = 'procesando' AND COALESCE(actualizado, creado) < ?))",
                    array(Db::ahora(), $previo['id'], date('Y-m-d H:i:s', time() - 120)));
                if ($tomada !== 1) {
                    throw new ApiError(409, 'peticion_en_curso', 'Esa peticion se esta procesando. Espere unos segundos y reintente con la misma Idempotency-Key.');
                }
                $fila = Db::uno('SELECT * FROM documentos WHERE id = ?', array($previo['id']));
                $serie = $previo['serie'];
                $numero = $previo['numero'];
            }
        }

        // 2) Comprobaciones previas (antes de consumir un numero)
        Planes::exigirCuota($ctx, $tipo);                    // plan: cuota mensual (solo llave live)
        Certificados::exigirParaEmitir($empresaId, $entorno); // produccion: certificado propio y vigente
        Motor::empresa($ctx['empresa'], $entorno);             // falla aqui (y no despues) si las credenciales guardadas no se pueden descifrar

        // 3) Numero: asignarlo/reservarlo o validar el que envia el cliente
        if ($fila === null) {
            try {
                if ($auto) {
                    $r = Series::reservar($ctx, $tipo, $serie, 1, 'procesando', $idem, $hashCuerpo);
                    $fila = Db::uno('SELECT * FROM documentos WHERE id = ?', array($r[0]['id']));
                    $numero = $fila['numero'];
                } elseif ($serie !== null && $numero !== null) {
                    $reutil = Series::resolverManual($ctx, $tipo, $serie, $numero);
                    if ($reutil) {
                        $tomada = Db::afectadas('UPDATE documentos SET estado = ?, idem_key = ?, idem_hash = ?, actualizado = ? WHERE id = ? AND estado = ?',
                            array('procesando', $idem !== '' ? $idem : null, $idem !== '' ? $hashCuerpo : null, Db::ahora(), $reutil['id'], $reutil['estado']));
                        if ($tomada !== 1) {
                            throw new ApiError(409, 'documento_en_proceso', "$tipo $serie-$numero lo esta usando otra peticion.");
                        }
                        $fila = Db::uno('SELECT * FROM documentos WHERE id = ?', array($reutil['id']));
                    } elseif ($idem !== '') {
                        $fila = self::crearFila($empresaId, $entorno, $tipo, $serie, $numero, 'procesando', $idem, $hashCuerpo);
                    }
                }
            } catch (PDOException $x) {
                if (strpos((string) $x->getCode(), '23') === 0) { // violacion de unicidad: 23000 (SQLite/MySQL), 23505 (PostgreSQL)
                    throw new ApiError(409, 'peticion_en_curso', 'Ya hay una peticion en proceso con esa Idempotency-Key. Reintente en unos segundos.');
                }
                throw $x;
            }
        }
        if ($aplicar) {
            $aplicar($cuerpo, $numero);
        }

        // Lo necesario para reintentar solo (nunca credenciales: se agregan al enviar). Se borra cuando el documento queda final.
        if ($fila) {
            $guardar = $cuerpo;
            unset($guardar['empresa'], $guardar['emisor']);
            Db::ejecutar('UPDATE documentos SET script = ?, cuerpo = ?, numeracion_auto = ?, actualizado = ? WHERE id = ?',
                array($script, json_encode($guardar, JSON_UNESCAPED_UNICODE), $auto ? 1 : 0, Db::ahora(), $fila['id']));
            $fila = Db::uno('SELECT * FROM documentos WHERE id = ?', array($fila['id']));
        }

        // 4) Motor / SUNAT + registro del resultado
        list($n, $uid) = self::enviar($ctx, $fila, $script, $cuerpo, $tipo, $serie, $numero, $auto);

        $data = self::formatear(Db::uno('SELECT * FROM documentos WHERE uid = ?', array($uid)));
        if (isset($_GET['incluir']) && $_GET['incluir'] === 'archivos') {
            $arch = (array) $data['archivos'];
            foreach (array('xml_base_64', 'cdr_base_64') as $k) {
                if (isset($n['archivos'][$k])) {
                    $arch[$k] = $n['archivos'][$k];
                }
            }
            $data['archivos'] = (object) $arch;
        }
        // Un rechazo de SUNAT es un resultado valido de negocio (el estado dice "rechazado").
        return $data;
    }

    /**
     * Envia al motor y registra el resultado. Lo usan por igual la peticion del cliente y el worker de reintentos.
     * Si el motor/SUNAT falla y el documento ya tiene fila, queda en "error" con su numero (y programado para reintentarse si el fallo es transitorio).
     */
    private static function enviar(array $ctx, $fila, $script, array $cuerpo, $tipo, $serie, $numero, $auto)
    {
        $empresaId = (int) $ctx['empresa']['id'];
        $entorno = $ctx['key']['entorno'];
        try {
            Certificados::exigirParaEmitir($empresaId, $entorno);
            $empresaMotor = Motor::empresa($ctx['empresa'], $entorno);
            $res = Motor::llamar($script, Motor::prepararCuerpo($script, $cuerpo, $empresaMotor));
            $n = Motor::normalizar($script, $res);
        } catch (Exception $x) {
            if ($fila) {
                self::marcarFallo($fila, $x);
            }
            throw $x;
        }
        foreach (array('xml', 'cdr', 'pdf') as $k) {
            if (isset($n['archivos'][$k])) {
                $n['archivos'][$k] = Motor::urlPublica($n['archivos'][$k]);
            }
        }

        $a = $n['archivos'];
        $final = in_array($n['estado'], array('aceptado', 'rechazado'), true);
        $sigueTicket = ($n['estado'] === 'pendiente' && $tipo === '09' && !empty($n['ticket'])); // la guia con ticket se consulta sola
        $proximo = $sigueTicket ? date('Y-m-d H:i:s', time() + self::$esperaTicket[0]) : null;
        $valores = array($n['estado'], $n['sunat']['codigo'], $n['sunat']['descripcion'], $n['ticket'], $n['hash'],
            isset($a['xml']) ? $a['xml'] : null, isset($a['cdr']) ? $a['cdr'] : null, isset($a['pdf']) ? $a['pdf'] : null, json_encode(self::sinBase64($n)));

        $uid = null;
        Db::transaccion(function () use ($fila, $valores, $proximo, $sigueTicket, $empresaId, $entorno, $tipo, $serie, $numero, &$uid) {
            if ($fila) {
                $uid = $fila['uid'];
                Db::ejecutar('UPDATE documentos SET estado = ?, codigo_sunat = ?, mensaje_sunat = ?, ticket = ?, hash_firma = ?, ruta_xml = ?, ruta_cdr = ?, ruta_pdf = ?, respuesta = ?,
                    cuerpo = NULL, proximo_intento = ?, reintentos = ?, actualizado = ? WHERE id = ?',
                    array_merge($valores, array($proximo, $sigueTicket ? 0 : (int) $fila['reintentos'], Db::ahora(), $fila['id'])));
            } else {
                $uid = 'doc_' . bin2hex(random_bytes(10));
                Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, codigo_sunat, mensaje_sunat, ticket, hash_firma, ruta_xml, ruta_cdr, ruta_pdf, respuesta, proximo_intento, actualizado, creado)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array($uid, $empresaId, $tipo, $serie, $numero, Series::numeroN($numero), $entorno, $valores[0], $valores[1], $valores[2], $valores[3], $valores[4], $valores[5], $valores[6], $valores[7], $valores[8], $proximo, Db::ahora(), Db::ahora()));
            }
        });

        // Sincronizar el contador de la serie con lo que se uso; si SUNAT rechazo un numero asignado por la API, se libera
        Series::sincronizar($empresaId, $entorno, $tipo, $serie, $numero);
        if ($auto && $n['estado'] === 'rechazado') {
            Series::liberar($empresaId, $entorno, $tipo, $serie, $numero);
        }

        self::contabilizar($empresaId, $entorno, $uid, $n['estado']);

        // Aviso por webhook (un problema aqui NUNCA debe afectar al documento ya registrado)
        if ($final) {
            self::avisar($empresaId, $entorno, $uid, 'documento.' . $n['estado']);
        }
        return array($n, $uid);
    }

    /** Cuota del plan: aceptado/pendiente consume una unidad (una sola vez); rechazado la devuelve. Un fallo aqui NUNCA afecta al documento. */
    private static function contabilizar($empresaId, $entorno, $uid, $estado)
    {
        try {
            $doc = Db::uno('SELECT * FROM documentos WHERE uid = ?', array($uid));
            if (!$doc) {
                return;
            }
            if ($estado === 'aceptado' || $estado === 'pendiente') {
                Planes::consumir($empresaId, $entorno, $doc);
            } elseif ($estado === 'rechazado') {
                Planes::devolver($doc);
            }
        } catch (Exception $e) {
            error_log('[v1] no se pudo contabilizar la cuota de ' . $uid . ': ' . $e->getMessage());
        }
    }

    private static function avisar($empresaId, $entorno, $uid, $tipoEvento)
    {
        try {
            $doc = Db::uno('SELECT * FROM documentos WHERE uid = ?', array($uid));
            if ($doc) {
                Webhooks::evento($empresaId, $entorno, $doc, $tipoEvento);
            }
        } catch (Exception $e) {
            error_log('[v1] no se pudo registrar el evento ' . $tipoEvento . ' de ' . $uid . ': ' . $e->getMessage());
        }
    }

    /** El envio fallo: queda "error" con su numero. Si el fallo es transitorio se programa el reintento; si no, se avisa del error definitivo. */
    private static function marcarFallo(array $fila, Exception $x)
    {
        $codigo = $x instanceof ApiError ? $x->codigo : 'error_interno';
        Db::ejecutar('UPDATE documentos SET estado = ?, codigo_sunat = ?, mensaje_sunat = ?, reintentos = reintentos + 1, actualizado = ? WHERE id = ?',
            array('error', $codigo, substr($x->getMessage(), 0, 500), Db::ahora(), $fila['id']));
        $r = (int) Db::uno('SELECT reintentos AS r FROM documentos WHERE id = ?', array($fila['id']))['r'];
        if (in_array($codigo, self::$transitorios, true) && $r <= count(self::$espera)) {
            Db::ejecutar('UPDATE documentos SET proximo_intento = ? WHERE id = ?', array(date('Y-m-d H:i:s', time() + self::$espera[$r - 1]), $fila['id']));
            return;
        }
        Db::ejecutar('UPDATE documentos SET proximo_intento = NULL, cuerpo = NULL WHERE id = ?', array($fila['id']));
        self::avisar((int) $fila['empresa_id'], $fila['entorno'], $fila['uid'], 'documento.error'); // ya no se reintentara solo
    }

    /** Worker: reenvia un documento en "error" que el worker ya tomo (estado "procesando"). Devuelve el resultado en una palabra. */
    public static function reintentar(array $fila)
    {
        $empresa = Db::uno('SELECT * FROM empresas WHERE id = ?', array($fila['empresa_id']));
        $cuerpo = $fila['cuerpo'] !== null ? json_decode($fila['cuerpo'], true) : null;
        if (!$empresa || (int) $empresa['activo'] !== 1 || !is_array($cuerpo) || empty($fila['script'])) {
            self::marcarFallo($fila, new ApiError(500, 'reintento_imposible', 'No se puede reintentar: empresa inactiva o datos del envio no disponibles.'));
            return 'imposible';
        }
        $ctx = array('empresa' => $empresa, 'key' => array('entorno' => $fila['entorno']));
        try {
            list($n) = self::enviar($ctx, $fila, $fila['script'], $cuerpo, $fila['tipo'], $fila['serie'], $fila['numero'], (int) $fila['numeracion_auto'] === 1);
            return $n['estado'];
        } catch (Exception $x) {
            return 'fallo:' . ($x instanceof ApiError ? $x->codigo : 'error_interno'); // enviar() ya dejo el documento en "error"
        }
    }

    /** Worker: documentos "procesando" abandonados (el proceso murio a mitad) pasan a "error" para reintentarse. */
    public static function rescatar()
    {
        $limite = date('Y-m-d H:i:s', time() - 600);
        $n = 0;
        foreach (Db::consultar("SELECT * FROM documentos WHERE estado = 'procesando' AND cuerpo IS NOT NULL AND COALESCE(actualizado, creado) < ? LIMIT 50", array($limite)) as $f) {
            $tomada = Db::afectadas("UPDATE documentos SET actualizado = ? WHERE id = ? AND estado = 'procesando' AND COALESCE(actualizado, creado) < ?", array(Db::ahora(), $f['id'], $limite));
            if ($tomada === 1) {
                self::marcarFallo($f, new ApiError(500, 'motor_error', 'El intento anterior se interrumpio antes de terminar.'));
                $n++;
            }
        }
        return $n;
    }

    /** Worker: toma (de forma atomica) los documentos en "error" cuyo reintento ya toca. */
    public static function pendientesDeReintento($limite)
    {
        $ahora = Db::ahora();
        $out = array();
        foreach (Db::consultar("SELECT * FROM documentos WHERE estado = 'error' AND proximo_intento IS NOT NULL AND proximo_intento <= ? AND cuerpo IS NOT NULL ORDER BY proximo_intento, id LIMIT " . (int) $limite, array($ahora)) as $f) {
            $tomada = Db::afectadas("UPDATE documentos SET estado = 'procesando', actualizado = ? WHERE id = ? AND estado = 'error' AND proximo_intento <= ?", array($ahora, $f['id'], $ahora));
            if ($tomada === 1) {
                $out[] = Db::uno('SELECT * FROM documentos WHERE id = ?', array($f['id']));
            }
        }
        return $out;
    }

    /**
     * Worker: consulta el ticket de las guias "pendiente" cuyo momento llego. SOLO guias: es el unico ticket cuya respuesta
     * de SUNAT esta verificada. Los tickets de resumen/baja/reversion se consultan con POST /tickets.
     */
    public static function consultarTicketsGuia($limite)
    {
        $ahora = Db::ahora();
        $n = 0;
        foreach (Db::consultar("SELECT * FROM documentos WHERE tipo = '09' AND estado = 'pendiente' AND ticket IS NOT NULL AND proximo_intento IS NOT NULL AND proximo_intento <= ? ORDER BY proximo_intento, id LIMIT " . (int) $limite, array($ahora)) as $f) {
            // se toma empujando el proximo_intento (solo gana quien lo encuentre sin cambiar)
            $tomada = Db::afectadas("UPDATE documentos SET proximo_intento = ? WHERE id = ? AND estado = 'pendiente' AND proximo_intento = ?", array(date('Y-m-d H:i:s', time() + 300), $f['id'], $f['proximo_intento']));
            if ($tomada !== 1) {
                continue;
            }
            $n++;
            $empresa = Db::uno('SELECT * FROM empresas WHERE id = ?', array($f['empresa_id']));
            $resultado = 'sin_cambio';
            try {
                if (!$empresa || (int) $empresa['activo'] !== 1) {
                    throw new ApiError(500, 'empresa_inactiva', 'empresa inactiva');
                }
                $emp = Motor::empresa($empresa, $f['entorno']);
                $res = Motor::llamar('post_guia_ticket.php', array('empresa' => $emp, 'num_ticket' => $f['ticket']), 25);
                $t = Motor::normalizar('post_guia_ticket.php', $res);
                if ($t['estado'] === 'aceptado' || $t['estado'] === 'rechazado') {
                    foreach (array('xml', 'cdr', 'pdf') as $k) {
                        if (isset($t['archivos'][$k])) {
                            $t['archivos'][$k] = Motor::urlPublica($t['archivos'][$k]);
                        }
                    }
                    $a = $t['archivos'];
                    Db::ejecutar("UPDATE documentos SET estado = ?, codigo_sunat = ?, mensaje_sunat = ?, hash_firma = COALESCE(?, hash_firma), ruta_xml = COALESCE(?, ruta_xml), ruta_cdr = COALESCE(?, ruta_cdr), ruta_pdf = COALESCE(?, ruta_pdf), proximo_intento = NULL, actualizado = ? WHERE id = ? AND estado = 'pendiente'",
                        array($t['estado'], $t['sunat']['codigo'], $t['sunat']['descripcion'], $t['hash'], isset($a['xml']) ? $a['xml'] : null, isset($a['cdr']) ? $a['cdr'] : null, isset($a['pdf']) ? $a['pdf'] : null, Db::ahora(), $f['id']));
                    self::contabilizar((int) $f['empresa_id'], $f['entorno'], $f['uid'], $t['estado']);
                    self::avisar((int) $f['empresa_id'], $f['entorno'], $f['uid'], 'documento.' . $t['estado']);
                    $resultado = $t['estado'];
                }
            } catch (Exception $x) {
                $resultado = 'fallo:' . ($x instanceof ApiError ? $x->codigo : 'error_interno');
            }
            if ($resultado === 'sin_cambio' || strpos($resultado, 'fallo:') === 0) { // sigue pendiente: se programa la siguiente consulta
                $intentos = (int) $f['reintentos'] + 1;
                $prox = $intentos < count(self::$esperaTicket) ? date('Y-m-d H:i:s', time() + self::$esperaTicket[$intentos]) : null; // la 1a espera se uso al registrar la guia
                Db::ejecutar("UPDATE documentos SET reintentos = ?, proximo_intento = ?, actualizado = ? WHERE id = ? AND estado = 'pendiente'", array($intentos, $prox, Db::ahora(), $f['id']));
            }
            echo '  ticket guia ' . $f['serie'] . '-' . $f['numero'] . ' -> ' . $resultado . "\n";
        }
        return $n;
    }

    private static function crearFila($empresaId, $entorno, $tipo, $serie, $numero, $estado, $idem, $hash)
    {
        $uid = 'doc_' . bin2hex(random_bytes(10));
        $id = Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, idem_key, idem_hash, actualizado, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            array($uid, $empresaId, $tipo, $serie, $numero, Series::numeroN($numero), $entorno, $estado, $idem, $hash, Db::ahora(), Db::ahora()));
        return Db::uno('SELECT * FROM documentos WHERE id = ?', array($id));
    }

    /** Busca un documento por tipo, serie y numero (150 y 00000150 son el mismo). Devuelve el mas reciente. */
    public static function buscar(array $ctx, $tipo, $serie, $numero)
    {
        $n = Series::numeroN($numero);
        if ($n === null) {
            throw new ApiError(422, 'validacion', 'El numero debe tener de 1 a 8 digitos.', array(array('campo' => 'numero', 'mensaje' => 'solo digitos, maximo 8')));
        }
        $f = Db::uno('SELECT * FROM documentos WHERE empresa_id = ? AND entorno = ? AND tipo = ? AND serie = ? AND numero_n = ? ORDER BY id DESC LIMIT 1',
            array($ctx['empresa']['id'], $ctx['key']['entorno'], (string) $tipo, (string) $serie, $n));
        if (!$f) {
            throw new ApiError(404, 'no_encontrado', "No hay un documento $tipo $serie-$numero en este entorno (aun no se envio ni se reservo).");
        }
        return self::formatear($f);
    }

    public static function obtener(array $ctx, $uid)
    {
        $f = Db::uno('SELECT * FROM documentos WHERE uid = ? AND empresa_id = ?', array($uid, $ctx['empresa']['id']));
        if (!$f) {
            throw new ApiError(404, 'no_encontrado', 'Documento no encontrado.');
        }
        return self::formatear($f);
    }

    public static function listar(array $ctx)
    {
        $limite = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
        $desde = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
        $where = 'empresa_id = ? AND entorno = ?';
        $p = array($ctx['empresa']['id'], $ctx['key']['entorno']);
        foreach (array('tipo', 'estado', 'serie') as $c) {
            if (isset($_GET[$c]) && $_GET[$c] !== '') {
                $where .= " AND $c = ?";
                $p[] = (string) $_GET[$c];
            }
        }
        if (isset($_GET['numero']) && Series::numeroN($_GET['numero']) !== null) {
            $where .= ' AND numero_n = ?';
            $p[] = Series::numeroN($_GET['numero']);
        }
        $filas = Db::consultar("SELECT * FROM documentos WHERE $where ORDER BY id DESC LIMIT $limite OFFSET $desde", $p);
        $total = Db::uno("SELECT COUNT(*) AS n FROM documentos WHERE $where", $p);
        return array('items' => array_map(array('Documentos', 'formatear'), $filas), 'total' => (int) $total['n'], 'limit' => $limite, 'offset' => $desde);
    }
}
