<?php
/**
 * Emision de documentos: idempotencia, deteccion de duplicados, llamada al motor e historial.
 */
require_once __DIR__ . '/Certificados.php';

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
     * Emite un documento por el motor. $script: archivo del motor. $tipo/$serie/$numero identifican el documento.
     */
    public static function emitir(array $ctx, $script, array $cuerpo, $tipo, $serie, $numero)
    {
        $empresaId = (int) $ctx['empresa']['id'];
        $entorno = $ctx['key']['entorno'];
        $idem = self::cabecera('Idempotency-Key');
        $hashCuerpo = hash('sha256', json_encode($cuerpo));

        if ($idem !== '') {
            if (!preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $idem)) {
                throw new ApiError(400, 'idempotency_key_invalida', 'Idempotency-Key: hasta 64 caracteres (letras, numeros, . _ -).');
            }
            $previo = Db::uno('SELECT * FROM documentos WHERE empresa_id = ? AND idem_key = ?', array($empresaId, $idem));
            if ($previo) {
                if ($previo['idem_hash'] !== $hashCuerpo) {
                    throw new ApiError(409, 'idempotencia_conflicto', 'Esa Idempotency-Key ya se uso con una peticion distinta.');
                }
                Respuesta::ok(self::formatear($previo), 200, array('Idempotent-Replay' => 'true'));
            }
        }

        if ($serie !== null && $numero !== null) {
            $dup = Db::uno(
                "SELECT * FROM documentos WHERE empresa_id = ? AND tipo = ? AND serie = ? AND numero = ? AND entorno = ? AND estado IN ('aceptado','pendiente') ORDER BY id DESC",
                array($empresaId, $tipo, $serie, $numero, $entorno)
            );
            if ($dup) {
                throw new ApiError(409, 'documento_duplicado', "Ya existe $tipo $serie-$numero con estado " . $dup['estado'] . '.', array('id' => $dup['uid']));
            }
        }

        Certificados::exigirParaEmitir($empresaId, $entorno); // produccion: certificado propio y vigente
        $cuerpo['empresa'] = Motor::empresa($ctx['empresa'], $entorno); // credenciales siempre desde la base, nunca del cliente
        $res = Motor::llamar($script, $cuerpo);
        $n = Motor::normalizar($script, $res);

        foreach (array('xml', 'cdr', 'pdf') as $k) {
            if (isset($n['archivos'][$k])) {
                $n['archivos'][$k] = Motor::urlPublica($n['archivos'][$k]);
            }
        }

        $uid = 'doc_' . bin2hex(random_bytes(10));
        $a = $n['archivos'];
        Db::ejecutar(
            'INSERT INTO documentos (uid, empresa_id, tipo, serie, numero, entorno, estado, codigo_sunat, mensaje_sunat, ticket, hash_firma, ruta_xml, ruta_cdr, ruta_pdf, idem_key, idem_hash, respuesta, creado)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array($uid, $empresaId, $tipo, $serie, $numero, $entorno, $n['estado'], $n['sunat']['codigo'], $n['sunat']['descripcion'],
                $n['ticket'], $n['hash'], isset($a['xml']) ? $a['xml'] : null, isset($a['cdr']) ? $a['cdr'] : null, isset($a['pdf']) ? $a['pdf'] : null,
                $idem !== '' ? $idem : null, $idem !== '' ? $hashCuerpo : null, json_encode(self::sinBase64($n)), Db::ahora())
        );

        $fila = Db::uno('SELECT * FROM documentos WHERE uid = ?', array($uid));
        $data = self::formatear($fila);
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
        $filas = Db::consultar("SELECT * FROM documentos WHERE $where ORDER BY id DESC LIMIT $limite OFFSET $desde", $p);
        $total = Db::uno("SELECT COUNT(*) AS n FROM documentos WHERE $where", $p);
        return array('items' => array_map(array('Documentos', 'formatear'), $filas), 'total' => (int) $total['n'], 'limit' => $limite, 'offset' => $desde);
    }
}
