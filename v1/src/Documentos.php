<?php
/**
 * Emision de documentos: idempotencia, deteccion de duplicados, llamada al motor e historial.
 */
require_once __DIR__ . '/Certificados.php';
require_once __DIR__ . '/Series.php';

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

    private static function formatear(array $f)
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

    /**
     * Emite un documento por el motor.
     *  - $numero = null  => numeracion automatica (la API asigna el numero; exige Idempotency-Key).
     *  - $aplicar        => funcion(&$cuerpo, $numero) que escribe el numero asignado en el cuerpo para el motor.
     * Garantias: un reintento con la misma Idempotency-Key nunca consume otro numero ni duplica el documento.
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

        // 1) Idempotencia: resultado final => se devuelve; intento interrumpido ("procesando") => se retoma con el mismo numero
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
                if ($previo['estado'] === 'procesando' && strtotime($previo['creado']) > time() - 120) {
                    throw new ApiError(409, 'peticion_en_curso', 'Esa peticion se esta procesando. Espere unos segundos y reintente con la misma Idempotency-Key.');
                }
                $fila = $previo;
                $serie = $previo['serie'];
                $numero = $previo['numero'];
            }
        }

        // 2) Comprobaciones previas (antes de consumir un numero)
        Certificados::exigirParaEmitir($empresaId, $entorno); // produccion: certificado propio y vigente
        $empresaMotor = Motor::empresa($ctx['empresa'], $entorno); // credenciales siempre desde la base, nunca del cliente

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
                        $tomada = Db::afectadas('UPDATE documentos SET estado = ?, idem_key = ?, idem_hash = ?, creado = ? WHERE id = ? AND estado = ?',
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
                if (strpos((string) $x->getCode(), '23') === 0) { // violacion de unicidad: 23000 (SQLite/MySQL), 23505 (PostgreSQL) // otra peticion con la misma Idempotency-Key entro al mismo tiempo
                    throw new ApiError(409, 'peticion_en_curso', 'Ya hay una peticion en proceso con esa Idempotency-Key. Reintente en unos segundos.');
                }
                throw $x;
            }
        }
        if ($aplicar) {
            $aplicar($cuerpo, $numero);
        }

        // 4) Motor / SUNAT. Si falla aqui, la fila queda "procesando" con su numero: el reintento con la misma Idempotency-Key lo retoma.
        $cuerpo['empresa'] = $empresaMotor;
        try {
            $res = Motor::llamar($script, $cuerpo);
            $n = Motor::normalizar($script, $res);
        } catch (Exception $x) {
            if ($fila) {
                Db::ejecutar('UPDATE documentos SET estado = ?, codigo_sunat = ?, mensaje_sunat = ? WHERE id = ?',
                    array('error', $x instanceof ApiError ? $x->codigo : 'error_interno', substr($x->getMessage(), 0, 500), $fila['id']));
            }
            throw $x;
        }
        foreach (array('xml', 'cdr', 'pdf') as $k) {
            if (isset($n['archivos'][$k])) {
                $n['archivos'][$k] = Motor::urlPublica($n['archivos'][$k]);
            }
        }

        // 5) Registrar el resultado
        $a = $n['archivos'];
        $valores = array($n['estado'], $n['sunat']['codigo'], $n['sunat']['descripcion'], $n['ticket'], $n['hash'],
            isset($a['xml']) ? $a['xml'] : null, isset($a['cdr']) ? $a['cdr'] : null, isset($a['pdf']) ? $a['pdf'] : null, json_encode(self::sinBase64($n)));
        if ($fila) {
            $uid = $fila['uid'];
            Db::ejecutar('UPDATE documentos SET estado = ?, codigo_sunat = ?, mensaje_sunat = ?, ticket = ?, hash_firma = ?, ruta_xml = ?, ruta_cdr = ?, ruta_pdf = ?, respuesta = ? WHERE id = ?',
                array_merge($valores, array($fila['id'])));
        } else {
            $uid = 'doc_' . bin2hex(random_bytes(10));
            Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, codigo_sunat, mensaje_sunat, ticket, hash_firma, ruta_xml, ruta_cdr, ruta_pdf, respuesta, creado)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                array($uid, $empresaId, $tipo, $serie, $numero, Series::numeroN($numero), $entorno, $valores[0], $valores[1], $valores[2], $valores[3], $valores[4], $valores[5], $valores[6], $valores[7], $valores[8], Db::ahora()));
        }

        // 6) Sincronizar el contador de la serie con lo que se uso; si SUNAT rechazo un numero asignado por la API, se libera
        Series::sincronizar($empresaId, $entorno, $tipo, $serie, $numero);
        if ($auto && $n['estado'] === 'rechazado') {
            Series::liberar($empresaId, $entorno, $tipo, $serie, $numero);
        }

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

    private static function crearFila($empresaId, $entorno, $tipo, $serie, $numero, $estado, $idem, $hash)
    {
        $uid = 'doc_' . bin2hex(random_bytes(10));
        $id = Db::ejecutar('INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, numero_n, entorno, estado, idem_key, idem_hash, creado) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            array($uid, $empresaId, $tipo, $serie, $numero, Series::numeroN($numero), $entorno, $estado, $idem, $hash, Db::ahora()));
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
