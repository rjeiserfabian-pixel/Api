<?php
/**
 * Webhooks: avisos HTTP a la URL del cliente cuando un documento llega a un estado final.
 *
 * Diseno (patron "outbox"): al cambiar un documento se guarda un EVENTO y una ENTREGA por cada webhook suscrito (en la
 * misma transaccion que el cambio). Un proceso aparte (bin/worker.php) envia las entregas y las reintenta con espera creciente.
 *
 * Seguridad:
 *  - Cada aviso va firmado: X-Webhook-Signature: t=<unix>,v1=hex(HMAC_SHA256(secreto, "<unix>.<cuerpo>")).
 *  - SSRF: se rechazan destinos que apunten a redes privadas/reservadas (se vuelve a comprobar al enviar, y se fija la IP
 *    resuelta para evitar cambios de DNS). No se siguen redirecciones. En produccion se exige HTTPS.
 *  - El secreto se guarda cifrado y se muestra una sola vez.
 *  - Los avisos no incluyen datos del cliente final: solo el estado del documento y los enlaces a sus archivos.
 */
require_once __DIR__ . '/Crypto.php';

class Webhooks
{
    public static $eventos = array('documento.aceptado', 'documento.rechazado', 'documento.error', 'cuota.advertencia', 'cuota.agotada');
    const MAX_POR_ENTORNO = 5;
    /** Segundos de espera tras cada intento fallido de entrega (1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h, 24 h). */
    public static $espera = array(60, 300, 900, 3600, 10800, 21600, 43200, 86400);

    // ------------------------------------------------------------------ destino seguro
    public static function permitirRedesPrivadas()
    {
        return api_env('WEBHOOKS_PERMITIR_REDES_PRIVADAS') === '1'; // SOLO para desarrollo local
    }

    private static function ipPublica($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false; // 10/8, 172.16/12, 192.168/16, 127/8, 169.254/16 (metadatos de nube), 0/8, 240/4...
        }
        $n = ip2long($ip);
        return !($n >= ip2long('100.64.0.0') && $n <= ip2long('100.127.255.255')); // CGNAT
    }

    private static function resolver($host)
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return array($host);
        }
        $ips = @gethostbynamel($host);
        return $ips ? $ips : array();
    }

    /** Valida una URL de webhook y devuelve array('url', 'host', 'puerto', 'esquema', 'ip'). Lanza ApiError 422 si no es segura. */
    public static function validarUrl($url, $entorno)
    {
        $malo = function ($msg) {
            throw new ApiError(422, 'webhook_url_invalida', $msg, array(array('campo' => 'url', 'mensaje' => $msg)));
        };
        if (!is_string($url) || $url === '' || strlen($url) > 500) {
            $malo('La URL es obligatoria (maximo 500 caracteres).');
        }
        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            $malo('La URL no es valida (ejemplo: https://midominio.com/webhooks/sunat).');
        }
        $esq = strtolower($p['scheme']);
        if (!in_array($esq, array('http', 'https'), true)) {
            $malo('Solo se admiten URLs http o https.');
        }
        if (isset($p['user']) || isset($p['pass'])) {
            $malo('La URL no puede incluir usuario ni contrasena.');
        }
        $host = strtolower($p['host']);
        if (strpos($host, '[') !== false || strpos($host, ':') !== false) {
            $malo('No se admiten direcciones IPv6.');
        }
        $puerto = isset($p['port']) ? (int) $p['port'] : ($esq === 'https' ? 443 : 80);
        if ($puerto < 1 || $puerto > 65535) {
            $malo('Puerto invalido.');
        }
        $libre = self::permitirRedesPrivadas();
        if ($esq !== 'https' && $entorno === 'live' && !$libre) {
            $malo('En produccion el webhook debe usar HTTPS.');
        }
        $ips = self::resolver($host);
        if (!$ips) {
            $malo('No se pudo resolver el dominio de la URL.');
        }
        if (!$libre) {
            foreach ($ips as $ip) {
                if (!self::ipPublica($ip)) {
                    $malo('La URL apunta a una direccion privada o reservada; solo se admiten destinos publicos de Internet.');
                }
            }
        }
        return array('url' => $url, 'host' => $host, 'puerto' => $puerto, 'esquema' => $esq, 'ip' => $ips[0]);
    }

    // ------------------------------------------------------------------ registro de webhooks
    private static function ver(array $w, $secreto = null)
    {
        $v = array('id' => $w['uid'], 'url' => $w['url'], 'eventos' => $w['eventos'] === null || $w['eventos'] === '' ? self::$eventos : explode(',', $w['eventos']),
            'activo' => (int) $w['activo'] === 1, 'entorno' => $w['entorno'] === 'live' ? 'produccion' : 'prueba', 'creado' => $w['creado']);
        if ($secreto !== null) {
            $v['secreto'] = $secreto;
            $v['aviso'] = 'Guarde este secreto ahora: no se vuelve a mostrar. Sirve para verificar la firma de cada aviso.';
        }
        return $v;
    }

    public static function crear(array $ctx, array $b)
    {
        $e = (int) $ctx['empresa']['id'];
        $ent = $ctx['key']['entorno'];
        $url = isset($b['url']) ? $b['url'] : null;
        self::validarUrl($url, $ent);
        $lista = null;
        if (isset($b['eventos'])) {
            if (!is_array($b['eventos']) || !$b['eventos'] || array_diff($b['eventos'], self::$eventos)) {
                throw new ApiError(422, 'validacion', 'eventos invalidos.', array(array('campo' => 'eventos', 'mensaje' => 'use uno o mas de: ' . implode(', ', self::$eventos))));
            }
            $lista = implode(',', array_values(array_unique($b['eventos'])));
        }
        $n = Db::uno('SELECT COUNT(*) AS n FROM webhooks WHERE empresa_id = ? AND entorno = ?', array($e, $ent));
        $plan = Planes::de($ctx['empresa']);
        $max = $plan['max_webhooks'];
        if ((int) $n['n'] >= $max) {
            throw new ApiError(409, 'limite_webhooks', "Su plan {$plan['nombre']} permite hasta $max webhooks por entorno. Elimine alguno primero.");
        }
        $secreto = 'whsec_' . bin2hex(random_bytes(24));
        $uid = 'wh_' . bin2hex(random_bytes(10));
        Db::ejecutar('INSERT INTO webhooks (uid, empresa_id, entorno, url, secreto_cifrado, eventos, activo, creado) VALUES (?,?,?,?,?,?,1,?)',
            array($uid, $e, $ent, $url, Crypto::cifrar($secreto), $lista, Db::ahora()));
        return self::ver(Db::uno('SELECT * FROM webhooks WHERE uid = ?', array($uid)), $secreto);
    }

    public static function listar(array $ctx)
    {
        $out = array();
        foreach (Db::consultar('SELECT * FROM webhooks WHERE empresa_id = ? AND entorno = ? ORDER BY id', array($ctx['empresa']['id'], $ctx['key']['entorno'])) as $w) {
            $out[] = self::ver($w);
        }
        return $out;
    }

    private static function propio(array $ctx, $uid)
    {
        $w = Db::uno('SELECT * FROM webhooks WHERE uid = ? AND empresa_id = ? AND entorno = ?', array($uid, $ctx['empresa']['id'], $ctx['key']['entorno']));
        if (!$w) {
            throw new ApiError(404, 'no_encontrado', 'Webhook no encontrado.');
        }
        return $w;
    }

    public static function eliminar(array $ctx, $uid)
    {
        $w = self::propio($ctx, $uid);
        Db::transaccion(function () use ($w) {
            Db::ejecutar('DELETE FROM entregas WHERE webhook_id = ?', array($w['id']));
            Db::ejecutar('DELETE FROM webhooks WHERE id = ?', array($w['id']));
        });
    }

    // ------------------------------------------------------------------ eventos
    /** Evento sobre un documento. Pensado para llamarse despues de que el documento cambio. */
    public static function evento($empresaId, $entorno, array $doc, $tipo)
    {
        return self::encolar($empresaId, $entorno, $tipo, isset($doc['id']) ? $doc['id'] : null, Documentos::formatear($doc));
    }

    /** Evento que no es de un documento (por ejemplo, avisos de cuota). */
    public static function eventoGeneral($empresaId, $entorno, $tipo, array $datos)
    {
        return self::encolar($empresaId, $entorno, $tipo, null, $datos);
    }

    /** Guarda el evento y una entrega por cada webhook suscrito (solo si hay alguno). */
    private static function encolar($empresaId, $entorno, $tipo, $documentoId, array $data)
    {
        $hooks = Db::consultar('SELECT * FROM webhooks WHERE empresa_id = ? AND entorno = ? AND activo = 1', array($empresaId, $entorno));
        $destinos = array();
        foreach ($hooks as $h) {
            if ($h['eventos'] === null || $h['eventos'] === '' || in_array($tipo, explode(',', $h['eventos']), true)) {
                $destinos[] = $h;
            }
        }
        if (!$destinos) {
            return null;
        }
        $uid = 'evt_' . bin2hex(random_bytes(10));
        $payload = json_encode(array('id' => $uid, 'tipo' => $tipo, 'entorno' => $entorno === 'live' ? 'produccion' : 'prueba', 'creado' => Db::ahora(),
            'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $evId = Db::ejecutar('INSERT INTO eventos (uid, empresa_id, entorno, tipo, documento_id, payload, creado) VALUES (?,?,?,?,?,?,?)',
            array($uid, $empresaId, $entorno, $tipo, $documentoId, $payload, Db::ahora()));
        foreach ($destinos as $h) {
            Db::ejecutar('INSERT INTO entregas (uid, evento_id, webhook_id, estado, intentos, proximo_intento, creado) VALUES (?,?,?,?,0,?,?)',
                array('ent_' . bin2hex(random_bytes(10)), $evId, $h['id'], 'pendiente', Db::ahora(), Db::ahora()));
        }
        return $uid;
    }
    // ------------------------------------------------------------------ envio HTTP
    /** Un intento de entrega. Devuelve array('ok' => bool, 'http' => int|null, 'error' => string|null). */
    public static function intentar(array $entrega)
    {
        $w = Db::uno('SELECT * FROM webhooks WHERE id = ?', array($entrega['webhook_id']));
        $ev = Db::uno('SELECT * FROM eventos WHERE id = ?', array($entrega['evento_id']));
        if (!$w || !$ev) {
            return array('ok' => false, 'http' => null, 'error' => 'webhook o evento inexistente');
        }
        try {
            $dest = self::validarUrl($w['url'], $w['entorno']); // se vuelve a comprobar al enviar (el DNS pudo cambiar)
            $secreto = Crypto::descifrar($w['secreto_cifrado']);
        } catch (ApiError $x) {
            return array('ok' => false, 'http' => null, 'error' => substr($x->getMessage(), 0, 250));
        } catch (Exception $x) {
            return array('ok' => false, 'http' => null, 'error' => 'no se pudo preparar el envio');
        }
        $ts = time();
        $cuerpo = $ev['payload'];
        $firma = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $cuerpo, $secreto);
        $ch = curl_init($dest['url']);
        $recibido = 0;
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json; charset=utf-8', 'User-Agent: ApiSunat-Webhook/1', 'X-Webhook-Event: ' . $ev['tipo'],
                'X-Webhook-Id: ' . $ev['uid'], 'X-Webhook-Delivery: ' . $entrega['uid'], 'X-Webhook-Timestamp: ' . $ts, 'X-Webhook-Signature: ' . $firma),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_RESOLVE => array($dest['host'] . ':' . $dest['puerto'] . ':' . $dest['ip']), // fija la IP ya validada
            CURLOPT_WRITEFUNCTION => function ($c, $datos) use (&$recibido) {
                $recibido += strlen($datos);
                return $recibido > 65536 ? 0 : strlen($datos); // no se guarda la respuesta; se corta si es enorme
            },
        ));
        curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($http >= 200 && $http < 300) {
            return array('ok' => true, 'http' => $http, 'error' => null);
        }
        if ($http === 0) {
            return array('ok' => false, 'http' => null, 'error' => substr($err !== '' ? $err : 'sin respuesta', 0, 250));
        }
        return array('ok' => false, 'http' => $http, 'error' => 'el destino respondio HTTP ' . $http . ($http >= 300 && $http < 400 ? ' (no se siguen redirecciones)' : ''));
    }

    /** Registra el resultado de un intento y programa el siguiente (o marca la entrega como fallida). */
    private static function registrarResultado(array $f, array $r)
    {
        $ahora = Db::ahora();
        if ($r['ok']) {
            Db::ejecutar('UPDATE entregas SET estado = ?, intentos = intentos + 1, ultimo_codigo = ?, ultimo_error = NULL, entregado_en = ?, proximo_intento = NULL, reclamado = NULL WHERE id = ?',
                array('entregado', $r['http'], $ahora, $f['id']));
            return 'entregado';
        }
        $intentos = (int) $f['intentos'] + 1;
        if ($intentos <= count(self::$espera)) {
            Db::ejecutar('UPDATE entregas SET estado = ?, intentos = ?, ultimo_codigo = ?, ultimo_error = ?, proximo_intento = ?, reclamado = NULL WHERE id = ?',
                array('pendiente', $intentos, $r['http'], $r['error'], date('Y-m-d H:i:s', time() + self::$espera[$intentos - 1]), $f['id']));
            return 'reintentara';
        }
        Db::ejecutar('UPDATE entregas SET estado = ?, intentos = ?, ultimo_codigo = ?, ultimo_error = ?, proximo_intento = NULL, reclamado = NULL WHERE id = ?',
            array('fallido', $intentos, $r['http'], $r['error'], $f['id']));
        return 'fallido';
    }

    /** Worker: envia las entregas pendientes cuyo momento llego. Devuelve cuantas proceso. */
    public static function procesarEntregas($limite = 20)
    {
        // entregas "enviando" que quedaron colgadas (el worker murio): vuelven a la cola
        Db::ejecutar("UPDATE entregas SET estado = 'pendiente', reclamado = NULL, proximo_intento = ? WHERE estado = 'enviando' AND reclamado < ?", array(Db::ahora(), date('Y-m-d H:i:s', time() - 300)));
        $filas = Db::consultar("SELECT * FROM entregas WHERE estado = 'pendiente' AND proximo_intento IS NOT NULL AND proximo_intento <= ? ORDER BY proximo_intento, id LIMIT " . (int) $limite, array(Db::ahora()));
        $n = 0;
        foreach ($filas as $f) {
            $tomada = Db::afectadas("UPDATE entregas SET estado = 'enviando', reclamado = ? WHERE id = ? AND estado = 'pendiente'", array(Db::ahora(), $f['id']));
            if ($tomada !== 1) {
                continue; // otro worker la tomo primero
            }
            $res = self::registrarResultado($f, self::intentar($f));
            $n++;
            echo '  entrega ' . $f['uid'] . ' -> ' . $res . "\n";
        }
        return $n;
    }

    // ------------------------------------------------------------------ operaciones de la API
    /** Envia un evento de prueba AHORA (un solo intento, sin reintentos) y devuelve el resultado. */
    public static function probar(array $ctx, $uid)
    {
        $w = self::propio($ctx, $uid);
        $evUid = 'evt_' . bin2hex(random_bytes(10));
        $payload = json_encode(array('id' => $evUid, 'tipo' => 'webhook.prueba', 'entorno' => $w['entorno'] === 'live' ? 'produccion' : 'prueba', 'creado' => Db::ahora(),
            'data' => array('mensaje' => 'Evento de prueba de la API SUNAT')), JSON_UNESCAPED_UNICODE);
        $evId = Db::ejecutar('INSERT INTO eventos (uid, empresa_id, entorno, tipo, documento_id, payload, creado) VALUES (?,?,?,?,?,?,?)',
            array($evUid, $w['empresa_id'], $w['entorno'], 'webhook.prueba', null, $payload, Db::ahora()));
        $entUid = 'ent_' . bin2hex(random_bytes(10));
        Db::ejecutar('INSERT INTO entregas (uid, evento_id, webhook_id, estado, intentos, proximo_intento, creado) VALUES (?,?,?,?,0,NULL,?)', array($entUid, $evId, $w['id'], 'enviando', Db::ahora()));
        $f = Db::uno('SELECT * FROM entregas WHERE uid = ?', array($entUid));
        $r = self::intentar($f);
        Db::ejecutar('UPDATE entregas SET estado = ?, intentos = 1, ultimo_codigo = ?, ultimo_error = ?, entregado_en = ? WHERE id = ?',
            array($r['ok'] ? 'entregado' : 'fallido', $r['http'], $r['error'], $r['ok'] ? Db::ahora() : null, $f['id']));
        return array('entrega' => $entUid, 'entregado' => $r['ok'], 'http' => $r['http'], 'error' => $r['error']);
    }

    public static function entregas(array $ctx, $uid)
    {
        $w = self::propio($ctx, $uid);
        $limite = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
        $where = 'e.webhook_id = ?';
        $p = array($w['id']);
        if (isset($_GET['estado']) && in_array($_GET['estado'], array('pendiente', 'enviando', 'entregado', 'fallido'), true)) {
            $where .= ' AND e.estado = ?';
            $p[] = $_GET['estado'];
        }
        $out = array();
        foreach (Db::consultar("SELECT e.*, v.uid AS evento_uid, v.tipo AS evento_tipo FROM entregas e JOIN eventos v ON v.id = e.evento_id WHERE $where ORDER BY e.id DESC LIMIT $limite", $p) as $f) {
            $out[] = array('id' => $f['uid'], 'evento' => array('id' => $f['evento_uid'], 'tipo' => $f['evento_tipo']), 'estado' => $f['estado'], 'intentos' => (int) $f['intentos'],
                'proximo_intento' => $f['proximo_intento'], 'ultimo_http' => $f['ultimo_codigo'] === null ? null : (int) $f['ultimo_codigo'], 'ultimo_error' => $f['ultimo_error'],
                'entregado_en' => $f['entregado_en'], 'creado' => $f['creado']);
        }
        return $out;
    }

    /** Vuelve a poner en cola una entrega (por ejemplo, una que quedo "fallido" despues de arreglar su servidor). */
    public static function reenviar(array $ctx, $uid, $entUid)
    {
        $w = self::propio($ctx, $uid);
        $n = Db::afectadas("UPDATE entregas SET estado = 'pendiente', intentos = 0, proximo_intento = ?, reclamado = NULL WHERE uid = ? AND webhook_id = ? AND estado IN ('fallido','entregado')",
            array(Db::ahora(), $entUid, $w['id']));
        if ($n !== 1) {
            throw new ApiError(404, 'no_encontrado', 'Entrega no encontrada, o todavia esta en cola o en envio.');
        }
        return array('id' => $entUid, 'estado' => 'pendiente', 'aviso' => 'Se enviara en el proximo ciclo del worker.');
    }
}
