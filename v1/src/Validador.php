<?php
/**
 * Validacion de la entrada. Devuelve una lista de errores [{campo, mensaje}]; vacia si todo esta bien.
 */
class Validador
{
    private $errores = array();

    public function error($campo, $mensaje)
    {
        $this->errores[] = array('campo' => $campo, 'mensaje' => $mensaje);
    }

    public function lista()
    {
        return $this->errores;
    }

    public function lanzarSiHayErrores()
    {
        if ($this->errores) {
            throw new ApiError(422, 'validacion', 'Los datos enviados no son validos.', $this->errores);
        }
    }

    public static function obtener($arr, $ruta)
    {
        $actual = $arr;
        foreach (explode('.', $ruta) as $p) {
            if (!is_array($actual) || !array_key_exists($p, $actual)) {
                return null;
            }
            $actual = $actual[$p];
        }
        return $actual;
    }

    public function requerido($arr, $ruta)
    {
        $v = self::obtener($arr, $ruta);
        if ($v === null || $v === '' || $v === array()) {
            $this->error($ruta, 'es obligatorio');
            return false;
        }
        return true;
    }

    public function enLista($arr, $ruta, array $validos)
    {
        if (!$this->requerido($arr, $ruta)) {
            return false;
        }
        if (!in_array((string) self::obtener($arr, $ruta), array_map('strval', $validos), true)) {
            $this->error($ruta, 'valor no permitido; use uno de: ' . implode(', ', $validos));
            return false;
        }
        return true;
    }

    public function patron($arr, $ruta, $regex, $ayuda)
    {
        if (!$this->requerido($arr, $ruta)) {
            return false;
        }
        if (!preg_match($regex, (string) self::obtener($arr, $ruta))) {
            $this->error($ruta, $ayuda);
            return false;
        }
        return true;
    }

    public function fecha($arr, $ruta)
    {
        if (!$this->patron($arr, $ruta, '/^\d{4}-\d{2}-\d{2}$/', 'formato AAAA-MM-DD')) {
            return false;
        }
        list($a, $m, $d) = array_map('intval', explode('-', self::obtener($arr, $ruta)));
        if (!checkdate($m, $d, $a)) {
            $this->error($ruta, 'fecha inexistente');
            return false;
        }
        return true;
    }

    public function numero($arr, $ruta, $minimo = null, $obligatorio = true)
    {
        $v = self::obtener($arr, $ruta);
        if ($v === null || $v === '') {
            if ($obligatorio) {
                $this->error($ruta, 'es obligatorio');
            }
            return false;
        }
        if (!is_numeric($v)) {
            $this->error($ruta, 'debe ser numerico');
            return false;
        }
        if ($minimo !== null && $v < $minimo) {
            $this->error($ruta, 'debe ser mayor o igual a ' . $minimo);
            return false;
        }
        return true;
    }

    /** Reglas de un comprobante 01/03/07/08. */
    public static function comprobante(array $b)
    {
        $v = new self();
        $tipo = (string) self::obtener($b, 'venta.tipo_documento_codigo');
        $v->enLista($b, 'venta.tipo_documento_codigo', array('01', '03', '07', '08'));

        if ($tipo === '01') {
            $v->patron($b, 'venta.serie', '/^F[A-Z0-9]{3}$/', 'la serie de una factura son 4 caracteres y empieza con F (ej. F001)');
        } elseif ($tipo === '03') {
            $v->patron($b, 'venta.serie', '/^B[A-Z0-9]{3}$/', 'la serie de una boleta son 4 caracteres y empieza con B (ej. B001)');
        } else {
            $v->patron($b, 'venta.serie', '/^[FB][A-Z0-9]{3}$/', 'la serie de una nota son 4 caracteres y empieza con F o B (ej. FC01)');
        }
        $v->patron($b, 'venta.numero', '/^\d{1,8}$/', 'solo digitos, maximo 8');
        $v->fecha($b, 'venta.fecha_emision');
        $v->patron($b, 'venta.hora_emision', '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', 'formato HH:MM:SS');
        $v->enLista($b, 'venta.moneda_id', array(1, 2, 3));
        $v->enLista($b, 'venta.forma_pago_id', array(1, 2));
        foreach (array('venta.total_gravada', 'venta.total_igv', 'venta.total_exonerada', 'venta.total_inafecta') as $c) {
            $v->numero($b, $c, 0, false);
        }

        if ($tipo === '07' || $tipo === '08') {
            $v->patron($b, 'venta.relacionado_serie', '/^[FB][A-Z0-9]{3}$/', 'serie del documento que se modifica (ej. F001)');
            $v->patron($b, 'venta.relacionado_numero', '/^\d{1,8}$/', 'solo digitos, maximo 8');
            $v->enLista($b, 'venta.relacionado_tipo_documento', array('01', '03'));
            $v->patron($b, 'venta.relacionado_motivo_codigo', '/^(0[1-9]|10|1[1-3])$/', 'codigo de motivo del catalogo 09/10 de SUNAT (01 a 13)');
        }

        $v->enLista($b, 'cliente.codigo_tipo_entidad', array('0', '1', '4', '6', '7', 'A'));
        $v->requerido($b, 'cliente.numero_documento');
        $v->requerido($b, 'cliente.razon_social_nombres');
        if ($tipo === '01') {
            if ((string) self::obtener($b, 'cliente.codigo_tipo_entidad') !== '6') {
                $v->error('cliente.codigo_tipo_entidad', 'una factura exige cliente con RUC (codigo 6)');
            } elseif (!preg_match('/^\d{11}$/', (string) self::obtener($b, 'cliente.numero_documento'))) {
                $v->error('cliente.numero_documento', 'el RUC tiene 11 digitos');
            }
        }
        if ((string) self::obtener($b, 'cliente.codigo_tipo_entidad') === '1'
            && !preg_match('/^\d{8}$/', (string) self::obtener($b, 'cliente.numero_documento'))) {
            $v->error('cliente.numero_documento', 'el DNI tiene 8 digitos');
        }

        $items = self::obtener($b, 'items');
        if (!is_array($items) || !$items) {
            $v->error('items', 'debe enviar al menos un item');
        } else {
            foreach ($items as $i => $it) {
                $p = 'items.' . $i;
                if (!is_array($it)) {
                    $v->error($p, 'item invalido');
                    continue;
                }
                foreach (array('producto', 'codigo_unidad', 'tipo_igv_codigo') as $c) {
                    if (!isset($it[$c]) || $it[$c] === '') {
                        $v->error("$p.$c", 'es obligatorio');
                    }
                }
                if (!isset($it['codigo_sunat']) || !preg_match('/^\d{8}$/', (string) $it['codigo_sunat'])) {
                    $v->error("$p.codigo_sunat", 'es obligatorio: codigo de producto SUNAT de 8 digitos (catalogo 25, ej. 50202201)');
                }
                if (!isset($it['cantidad']) || !is_numeric($it['cantidad']) || $it['cantidad'] <= 0) {
                    $v->error("$p.cantidad", 'debe ser un numero mayor a 0');
                }
                if (!isset($it['precio_base']) || !is_numeric($it['precio_base']) || $it['precio_base'] < 0) {
                    $v->error("$p.precio_base", 'debe ser un numero mayor o igual a 0 (precio unitario sin IGV)');
                }
            }
        }

        if ((string) self::obtener($b, 'venta.forma_pago_id') === '2') {
            $c = self::obtener($b, 'cuotas');
            if (!is_array($c) || !$c) {
                $v->error('cuotas', 'una venta al credito requiere al menos una cuota');
            }
        }

        // Coherencia de IGV: evita rechazos de SUNAT por totales mal calculados.
        $grav = self::obtener($b, 'venta.total_gravada');
        $igv = self::obtener($b, 'venta.total_igv');
        if (is_numeric($grav) && $grav > 0 && is_numeric($igv) && !self::obtener($b, 'venta.descuento_global')) {
            if (abs(round($grav * 0.18, 2) - $igv) > 0.02) {
                $v->error('venta.total_igv', 'no coincide con el 18% de total_gravada (esperado ' . number_format($grav * 0.18, 2, '.', '') . ')');
            }
        }

        $v->lanzarSiHayErrores();
        return $b;
    }
}
