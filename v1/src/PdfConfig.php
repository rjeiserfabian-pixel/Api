<?php
/**
 * Personalizacion del PDF por empresa: color, formato por defecto, pie de pagina, linea de contacto y LOGO.
 * El logo se guarda en storage/logos/<empresa_id>.png (fuera del alcance web). Al subirlo se VALIDA (solo PNG o JPG reales, tamano y
 * dimensiones acotados) y se RE-CODIFICA con GD a un PNG limpio: asi no se guardan metadatos ni contenido oculto del archivo original.
 */
class PdfConfig
{
    const MAX_BYTES = 1048576;    // 1 MB
    const MAX_PIXELES = 4000000;  // 4 megapixeles (protege la memoria al procesarlo)

    public static function defecto()
    {
        return array('color' => '#1d4ed8', 'formato' => 'a4', 'pie' => '', 'contacto' => '', 'logo' => 0, 'mostrar_verificacion' => 1);
    }

    public static function carpetaLogos()
    {
        return dirname(__DIR__, 2) . '/storage/logos';
    }

    public static function de($empresaId)
    {
        $c = self::defecto();
        $f = Db::uno('SELECT * FROM pdf_config WHERE empresa_id = ?', array((int) $empresaId));
        if ($f) {
            foreach ($c as $k => $v) {
                if (isset($f[$k]) && $f[$k] !== null) {
                    $c[$k] = $f[$k];
                }
            }
        }
        $c['logo'] = (int) $c['logo'];
        $c['mostrar_verificacion'] = (int) $c['mostrar_verificacion'];
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c['color'])) {
            $c['color'] = '#1d4ed8';
        }
        if (!in_array($c['formato'], array('a4', 'ticket'), true)) {
            $c['formato'] = 'a4';
        }
        return $c;
    }

    /** Valida lo que escribe el usuario. Devuelve array('datos' => ..., 'error' => null|mensaje). */
    public static function validar(array $in)
    {
        $color = isset($in['color']) ? strtolower(trim((string) $in['color'])) : '';
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
            return array('datos' => null, 'error' => 'El color debe tener el formato #RRGGBB (por ejemplo #1d4ed8).');
        }
        $formato = isset($in['formato']) ? (string) $in['formato'] : 'a4';
        if (!in_array($formato, array('a4', 'ticket'), true)) {
            return array('datos' => null, 'error' => 'El formato debe ser A4 o ticket.');
        }
        $pie = isset($in['pie']) && is_string($in['pie']) ? trim(str_replace("\r\n", "\n", $in['pie'])) : '';
        $contacto = isset($in['contacto']) && is_string($in['contacto']) ? trim($in['contacto']) : '';
        if (mb_strlen($pie, 'UTF-8') > 300 || preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $pie)) {
            return array('datos' => null, 'error' => 'El pie de página admite hasta 300 caracteres (puede usar saltos de línea).');
        }
        if (mb_strlen($contacto, 'UTF-8') > 200 || preg_match('/[\x00-\x1F\x7F]/', $contacto)) {
            return array('datos' => null, 'error' => 'La línea de contacto admite hasta 200 caracteres en una sola línea.');
        }
        return array('datos' => array('color' => $color, 'formato' => $formato, 'pie' => $pie, 'contacto' => $contacto,
            'mostrar_verificacion' => !empty($in['mostrar_verificacion']) ? 1 : 0), 'error' => null);
    }

    public static function guardar($empresaId, array $d)
    {
        $ahora = Db::ahora();
        if (Db::afectadas('UPDATE pdf_config SET color = ?, formato = ?, pie = ?, contacto = ?, mostrar_verificacion = ?, actualizado = ? WHERE empresa_id = ?',
            array($d['color'], $d['formato'], $d['pie'] !== '' ? $d['pie'] : null, $d['contacto'] !== '' ? $d['contacto'] : null, (int) $d['mostrar_verificacion'], $ahora, (int) $empresaId)) === 0) {
            Db::ejecutar('INSERT INTO pdf_config (empresa_id, color, formato, pie, contacto, logo, mostrar_verificacion, actualizado) VALUES (?,?,?,?,?,0,?,?)',
                array((int) $empresaId, $d['color'], $d['formato'], $d['pie'] !== '' ? $d['pie'] : null, $d['contacto'] !== '' ? $d['contacto'] : null, (int) $d['mostrar_verificacion'], $ahora));
        }
    }

    // ------------------------------------------------------------------ logo
    public static function rutaLogo($empresaId)
    {
        $r = self::carpetaLogos() . '/' . (int) $empresaId . '.png';
        clearstatcache(true, $r); // otro proceso (el portal) pudo crearlo o borrarlo
        return is_file($r) ? $r : null;
    }

    /** Valida y guarda el logo subido ($tmp = ruta del archivo temporal). Lanza InvalidArgumentException con un mensaje claro. */
    public static function guardarLogo($empresaId, $tmp)
    {
        if (!extension_loaded('gd')) {
            throw new InvalidArgumentException('El servidor no puede procesar imágenes (falta la extensión GD).');
        }
        if (!is_file($tmp) || filesize($tmp) === 0) {
            throw new InvalidArgumentException('No se recibió el archivo.');
        }
        if (filesize($tmp) > self::MAX_BYTES) {
            throw new InvalidArgumentException('El logo es demasiado pesado (máximo 1 MB).');
        }
        $bin = (string) file_get_contents($tmp);
        $esPng = substr($bin, 0, 8) === "\x89PNG\r\n\x1a\n";
        $esJpg = substr($bin, 0, 3) === "\xFF\xD8\xFF";
        if (!$esPng && !$esJpg) {
            throw new InvalidArgumentException('Solo se aceptan logos en PNG o JPG (no GIF, WebP, SVG ni otros formatos).');
        }
        $info = @getimagesizefromstring($bin);
        if (!$info || ($info[2] !== IMAGETYPE_PNG && $info[2] !== IMAGETYPE_JPEG) || $info[0] < 32 || $info[1] < 32) {
            throw new InvalidArgumentException('El archivo no es una imagen válida (mínimo 32 x 32 píxeles).');
        }
        if ($info[0] * $info[1] > self::MAX_PIXELES) {
            throw new InvalidArgumentException('La imagen es demasiado grande (máximo 4 megapíxeles, por ejemplo 2000 x 2000).');
        }
        $img = @imagecreatefromstring($bin);
        if (!$img) {
            throw new InvalidArgumentException('No se pudo leer la imagen: el archivo está dañado.');
        }
        // se reduce (si hace falta) y se APLANA sobre fondo blanco: sin transparencia ni metadatos, compatible con el PDF
        $w = imagesx($img);
        $h = imagesy($img);
        $esc = min(1, 600 / $w, 300 / $h);
        $nw = max(1, (int) round($w * $esc));
        $nh = max(1, (int) round($h * $esc));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $dir = self::carpetaLogos();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            imagedestroy($dst);
            throw new InvalidArgumentException('No se pudo guardar el logo en el servidor.');
        }
        $ruta = $dir . '/' . (int) $empresaId . '.png';
        $ok = imagepng($dst, $ruta, 6);
        imagedestroy($dst);
        if (!$ok) {
            throw new InvalidArgumentException('No se pudo guardar el logo en el servidor.');
        }
        @chmod($ruta, 0600);
        if (Db::afectadas('UPDATE pdf_config SET logo = 1, actualizado = ? WHERE empresa_id = ?', array(Db::ahora(), (int) $empresaId)) === 0) {
            Db::ejecutar('INSERT INTO pdf_config (empresa_id, color, formato, logo, mostrar_verificacion, actualizado) VALUES (?,?,?,1,1,?)', array((int) $empresaId, '#1d4ed8', 'a4', Db::ahora()));
        }
    }

    public static function quitarLogo($empresaId)
    {
        $r = self::carpetaLogos() . '/' . (int) $empresaId . '.png';
        if (is_file($r)) {
            @unlink($r);
        }
        Db::afectadas('UPDATE pdf_config SET logo = 0, actualizado = ? WHERE empresa_id = ?', array(Db::ahora(), (int) $empresaId));
    }
}
