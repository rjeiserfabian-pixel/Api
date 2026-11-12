<?php
/**
 * Respuestas uniformes de la API /v1.
 *   exito : { "ok": true,  "data": {...},  "request_id": "req_..." }
 *   error : { "ok": false, "error": { "code": "...", "message": "...", "details": [...] }, "request_id": "req_..." }
 */
class ApiError extends Exception
{
    public $status;
    public $codigo;
    public $detalles;
    public $cabeceras;

    public function __construct($status, $codigo, $mensaje, $detalles = null, array $cabeceras = array())
    {
        parent::__construct($mensaje);
        $this->status = $status;
        $this->codigo = $codigo;
        $this->detalles = $detalles;
        $this->cabeceras = $cabeceras;
    }
}

class Respuesta
{
    private static $requestId;
    private static $extra = array();

    /** Cabecera que se agregara a la respuesta (limites, cuota...). */
    public static function cabecera($nombre, $valor)
    {
        self::$extra[$nombre] = (string) $valor;
    }

    public static function id()
    {
        if (!self::$requestId) {
            self::$requestId = 'req_' . bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }

    public static function enviar($status, array $cuerpo, array $cabeceras = array())
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Request-Id: ' . self::id());
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        foreach (array_merge(self::$extra, $cabeceras) as $k => $v) {
            header($k . ': ' . $v);
        }
        $cuerpo['request_id'] = self::id();
        echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    public static function ok($data, $status = 200, array $cabeceras = array())
    {
        self::enviar($status, array('ok' => true, 'data' => $data), $cabeceras);
    }

    public static function error($status, $codigo, $mensaje, $detalles = null, array $cabeceras = array())
    {
        $e = array('code' => $codigo, 'message' => $mensaje);
        if ($detalles !== null) {
            $e['details'] = $detalles;
        }
        self::enviar($status, array('ok' => false, 'error' => $e), $cabeceras);
    }
}
