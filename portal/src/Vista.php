<?php
/** Plantillas del portal. TODO dato dinamico se imprime con h() (escape HTML). */
function h($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Solo se enlazan URLs http(s) (los enlaces a archivos vienen de la base de datos; esto evita "javascript:" y similares). */
function urlSegura($url)
{
    $url = (string) $url;
    return preg_match('#^https?://#i', $url) ? $url : '#';
}

function nombreTipo($t)
{
    $n = array('01' => 'Factura', '03' => 'Boleta', '07' => 'Nota de crédito', '08' => 'Nota de débito', '20' => 'Retención', '09' => 'Guía de remisión',
        'RC' => 'Resumen diario', 'RA' => 'Baja', 'RR' => 'Reversión');
    return isset($n[$t]) ? $n[$t] : $t;
}

function claseEstado($e)
{
    $m = array('aceptado' => 'ok', 'rechazado' => 'mal', 'error' => 'mal', 'pendiente' => 'espera', 'procesando' => 'espera', 'reservado' => 'info', 'simulado' => 'info');
    return isset($m[$e]) ? $m[$e] : 'info';
}

class Vista
{
    public static $base = '';
    public static $dir = null;   // carpeta de plantillas (por defecto la del portal)

    public static function url($ruta)
    {
        return self::$base . $ruta;
    }

    public static function render($vista, array $datos = array(), $conMenu = true)
    {
        $datos['_vista'] = $vista;
        $datos['_menu'] = $conMenu;
        extract($datos, EXTR_SKIP);
        ob_start();
        include (self::$dir !== null ? self::$dir : __DIR__ . '/../views') . '/' . $vista . '.php';
        $contenido = ob_get_clean();
        include (self::$dir !== null ? self::$dir : __DIR__ . '/../views') . '/_layout.php';
    }
}
