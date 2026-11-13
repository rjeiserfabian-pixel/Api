<?php
/**
 * PDF propio de la API (representacion impresa de factura, boleta y notas de credito/debito), en formato A4 o ticket de 80 mm.
 *
 * Se genera A PEDIDO desde el XML firmado (la fuente de verdad) + la personalizacion de la empresa (logo, color, pie, contacto):
 * no se guarda ningun PDF. Incluye el codigo QR estandar de SUNAT, la huella de la firma y, si la empresa lo permite, el enlace y QR
 * de verificacion publica. Usa FPDF (libraries/fpdf), sin dependencias nuevas.
 */
require_once dirname(__DIR__, 2) . '/libraries/fpdf/fpdf.php';
require_once __DIR__ . '/Comprobante.php';
require_once __DIR__ . '/PdfConfig.php';
require_once __DIR__ . '/Verificacion.php';

class PdfDoc extends FPDF
{
    public $rgb = array(29, 78, 216);
    public $nombreTipo = '';

    /** UTF-8 -> Windows-1252 (lo que entienden las fuentes basicas del PDF). Lo que no existe en esa codificacion se aproxima o se omite. */
    public static function cp($s)
    {
        $s = (string) $s;
        $r = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        return $r === false ? utf8_decode($s) : $r;
    }

    public static function hexARgb($hex)
    {
        $hex = ltrim((string) $hex, '#');
        return preg_match('/^[0-9a-fA-F]{6}$/', $hex) ? array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))) : array(29, 78, 216);
    }

    public function acento()
    {
        $this->SetTextColor($this->rgb[0], $this->rgb[1], $this->rgb[2]);
    }

    public function negro()
    {
        $this->SetTextColor(20, 24, 31);
    }

    public function gris()
    {
        $this->SetTextColor(95, 105, 120);
    }

    /** Numero de lineas que ocupara un texto en una celda de ancho $w (con la fuente actual). */
    public function nbLineas($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string) $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    /** Parte un texto sin espacios (una URL, una huella) en lineas que quepan en $w mm. */
    public function partir($txt, $w)
    {
        $lineas = array();
        $l = '';
        foreach (str_split((string) $txt) as $ch) {
            if ($this->GetStringWidth($l . $ch) > $w && $l !== '') {
                $lineas[] = $l;
                $l = '';
            }
            $l .= $ch;
        }
        if ($l !== '') {
            $lineas[] = $l;
        }
        return $lineas;
    }

    public function Footer()
    {
        if ($this->modo !== 'a4') {
            return;
        }
        $this->SetY(-11);
        $this->SetDrawColor(210, 215, 224);
        $this->SetLineWidth(0.2);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->SetFont('Helvetica', '', 7);
        $this->gris();
        $this->SetY(-9.5);
        $this->Cell(140, 4, self::cp('Representación impresa de la ' . ucfirst(mb_strtolower($this->nombreTipo, 'UTF-8'))), 0, 0, 'L');
        $this->Cell(46, 4, self::cp('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'R');
    }

    public $modo = 'a4';

    /** Altura (mm) a partir de la cual FPDF cambia de pagina. */
    public function limite()
    {
        return $this->PageBreakTrigger;
    }
}

class Pdf
{
    /** Ruta en disco del XML de un documento (solo dentro de files/facturacion_electronica). */
    public static function rutaXml(array $doc)
    {
        if (empty($doc['ruta_xml']) || !preg_match('#/files/facturacion_electronica/((?:FIRMA|XML)/[0-9A-Za-z._\-]+\.xml)$#', (string) parse_url($doc['ruta_xml'], PHP_URL_PATH), $m)) {
            return null;
        }
        return dirname(__DIR__, 2) . '/files/facturacion_electronica/' . $m[1];
    }

    /** Pasa de UTF-8 a lo que se imprime. */
    private static function t($s)
    {
        return PdfDoc::cp($s);
    }

    private static function qrArchivo($texto, $tam)
    {
        $png = Verificacion::qrPng($texto, $tam);
        $f = tempnam(sys_get_temp_dir(), 'qrp') . '.png';
        file_put_contents($f, $png);
        return $f;
    }

    /**
     * Genera el PDF de un documento (fila de la tabla documentos). Lanza ApiError 404 si no hay XML o no es imprimible.
     * @return string contenido del PDF
     */
    public static function generar(array $doc, $formato = null)
    {
        $emp = Db::uno('SELECT * FROM empresas WHERE id = ?', array((int) $doc['empresa_id']));
        $cfg = PdfConfig::de($doc['empresa_id']);
        $formato = in_array($formato, array('a4', 'ticket'), true) ? $formato : $cfg['formato'];
        $ruta = self::rutaXml($doc);
        $extra = !empty($doc['extra_impresion']) ? json_decode($doc['extra_impresion'], true) : array();
        $d = $ruta ? Comprobante::desdeArchivo($ruta, is_array($extra) ? $extra : array()) : null;
        if (!$d || !$emp) {
            throw new ApiError(404, 'pdf_no_disponible', 'No se puede generar el PDF de este documento (no hay XML firmado disponible o su tipo no tiene representación impresa).');
        }
        $urlVerif = null;
        if ($cfg['mostrar_verificacion'] && (int) $emp['verificacion_publica'] === 1) {
            $slug = Verificacion::slugDeFila($doc, $emp['ruc']);
            $urlVerif = $slug ? Verificacion::url($slug) : null;
        }
        return self::dibujar($d, $cfg, $formato, $cfg['logo'] ? PdfConfig::rutaLogo($doc['empresa_id']) : null, $urlVerif);
    }

    public static function dibujar(array $d, array $cfg, $formato, $logo, $urlVerif)
    {
        $tmp = array();
        try {
            $qrSunat = self::qrArchivo(Comprobante::textoQrSunat($d), 6);
            $tmp[] = $qrSunat;
            $qrVerif = null;
            if ($urlVerif) {
                $qrVerif = self::qrArchivo($urlVerif, 4);
                $tmp[] = $qrVerif;
            }
            $ctx = array('logo' => $logo, 'qrSunat' => $qrSunat, 'qrVerif' => $qrVerif, 'urlVerif' => $urlVerif);
            return $formato === 'ticket' ? self::ticket($d, $cfg, $ctx) : self::a4($d, $cfg, $ctx);
        } finally {
            foreach ($tmp as $f) {
                @unlink($f);
                @unlink(substr($f, 0, -4)); // tempnam crea tambien el archivo sin extension
            }
        }
    }

    private static function num8($n)
    {
        return ctype_digit((string) $n) && strlen($n) < 8 ? str_pad($n, 8, '0', STR_PAD_LEFT) : (string) $n;
    }

    /** Filas de totales (etiqueta, valor) segun lo que traiga el comprobante. */
    private static function filasTotales(array $d)
    {
        $m = Comprobante::simboloMoneda($d['moneda']);
        $f = function ($v) use ($m) {
            return $m . ' ' . Comprobante::dinero($v, 2);
        };
        $tr = $d['tributos'];
        $filas = array();
        $base = function ($cod) use ($tr) {
            return isset($tr[$cod]) ? (float) $tr[$cod]['base'] : 0.0;
        };
        $monto = function ($cod) use ($tr) {
            return isset($tr[$cod]) ? (float) $tr[$cod]['monto'] : 0.0;
        };
        if ($base('9995') > 0) {
            $filas[] = array('Op. Exportación', $f($base('9995')));
        }
        if ($base('1000') > 0 || (!$base('9997') && !$base('9998') && !$base('9996') && !$base('9995'))) {
            $filas[] = array('Op. Gravadas', $f($base('1000')));
        }
        if ($base('1016') > 0) {
            $filas[] = array('Op. Gravadas IVAP', $f($base('1016')));
        }
        if ($base('9997') > 0) {
            $filas[] = array('Op. Exoneradas', $f($base('9997')));
        }
        if ($base('9998') > 0) {
            $filas[] = array('Op. Inafectas', $f($base('9998')));
        }
        if ($base('9996') > 0) {
            $filas[] = array('Op. Gratuitas', $f($base('9996')));
        }
        $desc = $d['totales']['descuentos'] !== null ? (float) $d['totales']['descuentos'] : ($d['descuento_global'] && !$d['descuento_global']['cargo'] ? (float) $d['descuento_global']['monto'] : 0.0);
        if ($desc > 0) {
            $filas[] = array('Descuentos', '-' . $f($desc));
        }
        if ($monto('2000') > 0) {
            $filas[] = array('ISC', $f($monto('2000')));
        }
        if (isset($tr['1000']) || isset($tr['1016'])) {
            $filas[] = array(isset($tr['1000']) ? 'IGV' : 'IVAP', $f($monto('1000') + $monto('1016')));
        }
        if ($monto('7152') > 0) {
            $filas[] = array('ICBPER', $f($monto('7152')));
        }
        if ($monto('9999') > 0) {
            $filas[] = array('Otros tributos', $f($monto('9999')));
        }
        if ((float) $d['totales']['cargos'] > 0) {
            $filas[] = array('Otros cargos', $f($d['totales']['cargos']));
        }
        if ((float) $d['totales']['anticipos'] > 0) {
            $filas[] = array('Anticipos', '-' . $f($d['totales']['anticipos']));
        }
        return $filas;
    }

    // ================================================================== A4
    private static function a4(array $d, array $cfg, array $ctx)
    {
        $p = new PdfDoc('P', 'mm', 'A4');
        $p->modo = 'a4';
        $p->rgb = PdfDoc::hexARgb($cfg['color']);
        $p->nombreTipo = $d['nombre_tipo'];
        $p->SetTitle(self::t($d['nombre_tipo'] . ' ' . $d['serie'] . '-' . self::num8($d['numero'])));
        $p->SetCreator('API de facturacion electronica');
        $p->SetMargins(12, 12, 12);
        $p->SetAutoPageBreak(true, 16);
        $p->AliasNbPages();
        $p->AddPage();
        $x0 = 12;
        $y0 = 12;
        $m = Comprobante::simboloMoneda($d['moneda']);

        // ---- encabezado: logo + datos del emisor (izquierda) y recuadro del documento (derecha)
        $xt = $x0;
        if ($ctx['logo'] && is_file($ctx['logo'])) {
            $sz = @getimagesize($ctx['logo']);
            if ($sz) {
                $esc = min(50 / $sz[0], 26 / $sz[1]);
                $iw = $sz[0] * $esc;
                $ih = $sz[1] * $esc;
                $p->Image($ctx['logo'], $x0, $y0, $iw, $ih, 'PNG');
                $xt = $x0 + max($iw, 20) + 5;
            }
        }
        $wt = 133 - $xt;
        $p->SetXY($xt, $y0);
        $p->SetFont('Helvetica', 'B', 12);
        $p->acento();
        $p->MultiCell($wt, 5.2, self::t($d['emisor']['razon_social']), 0, 'L');
        $p->SetFont('Helvetica', '', 8.5);
        $p->negro();
        if ($d['emisor']['comercial'] && mb_strtoupper($d['emisor']['comercial'], 'UTF-8') !== mb_strtoupper((string) $d['emisor']['razon_social'], 'UTF-8')) {
            $p->SetX($xt);
            $p->MultiCell($wt, 4.2, self::t($d['emisor']['comercial']), 0, 'L');
        }
        if ($d['emisor']['direccion']) {
            $p->SetX($xt);
            $p->MultiCell($wt, 4.2, self::t($d['emisor']['direccion']), 0, 'L');
        }
        if ($cfg['contacto'] !== '') {
            $p->SetX($xt);
            $p->SetFont('Helvetica', '', 8);
            $p->gris();
            $p->MultiCell($wt, 4.2, self::t($cfg['contacto']), 0, 'L');
        }
        $yIzq = $p->GetY();
        // recuadro derecho
        $bx = 138;
        $bw = 60;
        $p->SetDrawColor($p->rgb[0], $p->rgb[1], $p->rgb[2]);
        $p->SetLineWidth(0.5);
        $p->Rect($bx, $y0, $bw, 30);
        $p->SetXY($bx, $y0 + 2.5);
        $p->SetFont('Helvetica', 'B', 10);
        $p->negro();
        $p->Cell($bw, 5, self::t('R.U.C. ' . $d['emisor']['ruc']), 0, 2, 'C');
        $p->SetFillColor($p->rgb[0], $p->rgb[1], $p->rgb[2]);
        $p->Rect($bx, $y0 + 9, $bw, 11, 'F');
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('Helvetica', 'B', 8.5);
        $largoTipo = $p->nbLineas($bw - 4, self::t($d['nombre_tipo']));
        $p->SetXY($bx + 2, $y0 + 9 + ($largoTipo > 1 ? 0.8 : 3.2));
        $p->MultiCell($bw - 4, 4.4, self::t($d['nombre_tipo']), 0, 'C');
        $p->SetXY($bx, $y0 + 22);
        $p->SetFont('Helvetica', 'B', 13);
        $p->negro();
        $p->Cell($bw, 6, self::t($d['serie'] . ' - N° ' . self::num8($d['numero'])), 0, 0, 'C');
        $p->SetLineWidth(0.2);
        $y = max($yIzq, $y0 + 30) + 5;

        // ---- datos del cliente y del documento
        $izq = array(array('Señor(es):', $d['cliente']['nombre']), array($d['cliente']['nombre_tipo_documento'] . ':', $d['cliente']['numero_documento']));
        if ($d['cliente']['direccion']) {
            $izq[] = array('Dirección:', $d['cliente']['direccion']);
        }
        $der = array(array('Fecha de emisión:', $d['fecha'] ? date('d/m/Y', strtotime($d['fecha'])) . ($d['hora'] ? '  ' . substr($d['hora'], 0, 5) : '') : ''));
        if ($d['vencimiento'] && $d['tipo'] !== '07' && $d['tipo'] !== '08') {
            $der[] = array('Vencimiento:', date('d/m/Y', strtotime($d['vencimiento'])));
        }
        $der[] = array('Moneda:', $d['moneda'] === 'PEN' ? 'SOLES (PEN)' : ($d['moneda'] === 'USD' ? 'DÓLARES (USD)' : $d['moneda']));
        if ($d['forma_pago']) {
            $der[] = array('Forma de pago:', strtoupper($d['forma_pago']) === 'CREDITO' ? 'Crédito' : $d['forma_pago']);
        }
        if ($d['orden_compra']) {
            $der[] = array('Orden de compra:', $d['orden_compra']);
        }
        if ($d['guias']) {
            $der[] = array('Guía(s):', implode(', ', $d['guias']));
        }
        $yb = $y;
        $p->SetFont('Helvetica', '', 8.5);
        $yi = $y + 2;
        foreach ($izq as $fila) {
            $p->SetXY(14, $yi);
            $p->SetFont('Helvetica', 'B', 8.5);
            $p->negro();
            $p->Cell(22, 4.6, self::t($fila[0]), 0, 0, 'L');
            $p->SetFont('Helvetica', '', 8.5);
            $p->MultiCell(86, 4.6, self::t($fila[1]), 0, 'L');
            $yi = $p->GetY() + 0.4;
        }
        $yd = $y + 2;
        foreach ($der as $fila) {
            $p->SetXY(126, $yd);
            $p->SetFont('Helvetica', 'B', 8.5);
            $p->Cell(30, 4.6, self::t($fila[0]), 0, 0, 'L');
            $p->SetFont('Helvetica', '', 8.5);
            $p->MultiCell(40, 4.6, self::t($fila[1]), 0, 'L');
            $yd = $p->GetY() + 0.4;
        }
        $y = max($yi, $yd) + 1;
        $p->SetDrawColor(205, 211, 222);
        $p->Rect(12, $yb, 186, $y - $yb);
        $y += 3;
        if (isset($d['modifica'])) {
            $mo = $d['modifica'];
            $txt = 'Documento que modifica: ' . ($mo['documento'] ?: '-') . ($mo['tipo'] === '01' ? ' (Factura)' : ($mo['tipo'] === '03' ? ' (Boleta)' : ''))
                . '     Motivo: ' . ($mo['motivo_codigo'] ? $mo['motivo_codigo'] . ' - ' : '') . $mo['motivo'];
            $p->SetFont('Helvetica', 'B', 8.5);
            $p->SetXY(12, $y);
            $p->SetFillColor(244, 246, 250);
            $p->MultiCell(186, 5, self::t($txt), 1, 'L', true);
            $y = $p->GetY() + 3;
        }

        // ---- tabla de items
        $cols = array(array('N°', 8, 'C'), array('Cant.', 16, 'R'), array('Unid.', 13, 'C'), array('Código', 23, 'L'), array('Descripción', 68, 'L'), array('V. Unit.', 19, 'R'), array('P. Unit.', 19, 'R'), array('Importe', 20, 'R'));
        $encabezado = function ($yy) use ($p, $cols) {
            $p->SetXY(12, $yy);
            $p->SetFillColor($p->rgb[0], $p->rgb[1], $p->rgb[2]);
            $p->SetTextColor(255, 255, 255);
            $p->SetFont('Helvetica', 'B', 8);
            foreach ($cols as $c) {
                $p->Cell($c[1], 6.5, self::t($c[0]), 0, 0, $c[2] === 'L' ? 'L' : 'C', true);
            }
            return $yy + 6.5;
        };
        $y = $encabezado($y);
        $p->SetFont('Helvetica', '', 8);
        $p->negro();
        $fila = 0;
        foreach ($d['items'] as $it) {
            $desc = (string) $it['descripcion'] . ($it['gratuito'] ? '  (GRATUITO)' : '') . ($it['descuento'] !== null && (float) $it['descuento'] > 0 ? '  [Desc. ' . $m . ' ' . Comprobante::dinero($it['descuento']) . ']' : '');
            $p->SetFont('Helvetica', '', 8);
            $nl = max($p->nbLineas(68, self::t($desc)), $p->nbLineas(23, self::t($it['codigo'])));
            $h = $nl * 4.1 + 1.6;
            if ($y + $h > $p->limite() - 2) {
                $p->AddPage();
                $y = $encabezado(14);
                $p->SetFont('Helvetica', '', 8);
                $p->negro();
            }
            if ($fila % 2 === 1) {
                $p->SetFillColor(246, 248, 252);
                $p->Rect(12, $y, 186, $h, 'F');
            }
            $vals = array($it['numero'], Comprobante::numero($it['cantidad'], 0, 3), $it['unidad'], $it['codigo'], $desc, Comprobante::numero($it['valor_unitario'], 2, 4),
                $it['gratuito'] ? '0.00' : Comprobante::numero($it['precio_unitario'], 2, 4), Comprobante::dinero($it['valor_venta']));
            $x = 12;
            foreach ($cols as $i => $c) {
                $p->SetXY($x, $y + 0.8);
                $p->MultiCell($c[1], 4.1, self::t($vals[$i]), 0, $c[2]);
                $x += $c[1];
            }
            $y += $h;
            $p->SetDrawColor(225, 229, 237);
            $p->Line(12, $y, 198, $y);
            $fila++;
        }
        $y += 4;

        // ---- bloque inferior: letras, cuotas, leyendas (izquierda) y totales (derecha)
        $filas = self::filasTotales($d);
        $altTotales = count($filas) * 5.2 + 9;
        $p->SetFont('Helvetica', '', 8);
        $nLetras = !empty($d['en_letras']) ? $p->nbLineas(112, self::t('SON: ' . $d['en_letras'])) : 0;
        $altIzq = $nLetras * 4.4 + (count($d['cuotas']) ? 8 + count($d['cuotas']) * 4.6 : 0) + (count($d['leyendas']) ? 5 : 0) * 1 + ($d['nota'] ? 10 : 0) + count($d['leyendas']) * 4.4;
        $altQr = 40 + ($cfg['pie'] !== '' ? 4.2 * max(1, $p->nbLineas(186, self::t($cfg['pie']))) + 2 : 0);
        $necesario = max($altTotales, $altIzq) + $altQr;
        if ($y + $necesario > $p->limite()) {
            $p->AddPage();
            $y = 14;
        }
        // totales (derecha)
        $tx = 128;
        $yt = $y;
        $p->SetDrawColor(205, 211, 222);
        foreach ($filas as $f) {
            $p->SetXY($tx, $yt);
            $p->SetFont('Helvetica', '', 8.5);
            $p->negro();
            $p->Cell(42, 5.2, self::t($f[0]), 0, 0, 'R');
            $p->Cell(28, 5.2, self::t($f[1]), 0, 0, 'R');
            $yt += 5.2;
        }
        $p->SetFillColor($p->rgb[0], $p->rgb[1], $p->rgb[2]);
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('Helvetica', 'B', 10);
        $p->SetXY($tx, $yt + 1.5);
        $p->Cell(30, 7.5, self::t('IMPORTE TOTAL'), 0, 0, 'L', true);
        $p->Cell(40, 7.5, self::t($m . ' ' . Comprobante::dinero($d['totales']['total'])), 0, 0, 'R', true);
        $yt += 9;
        // texto (izquierda)
        $yl = $y;
        if (!empty($d['en_letras'])) {
            $p->SetXY(12, $yl);
            $p->SetFont('Helvetica', 'B', 8.5);
            $p->negro();
            $p->MultiCell(112, 4.4, self::t('SON: ' . $d['en_letras']), 0, 'L');
            $yl = $p->GetY() + 2;
        }
        if ($d['cuotas']) {
            $p->SetXY(12, $yl);
            $p->SetFont('Helvetica', 'B', 8);
            $p->Cell(112, 4.6, self::t('Pago al crédito' . ($d['monto_credito'] ? ' - Total a crédito: ' . $m . ' ' . Comprobante::dinero($d['monto_credito']) : '')), 0, 1, 'L');
            $p->SetFont('Helvetica', '', 8);
            $yl = $p->GetY();
            $p->SetFillColor(244, 246, 250);
            foreach ($d['cuotas'] as $cu) {
                $p->SetXY(12, $yl);
                $p->Cell(22, 4.6, self::t('Cuota ' . $cu['numero']), 0, 0, 'L', true);
                $p->Cell(40, 4.6, self::t($cu['fecha'] ? date('d/m/Y', strtotime($cu['fecha'])) : ''), 0, 0, 'L', true);
                $p->Cell(34, 4.6, self::t($m . ' ' . Comprobante::dinero($cu['monto'])), 0, 0, 'R', true);
                $yl += 4.6;
            }
            $yl += 2;
        }
        foreach ($d['leyendas'] as $ley) {
            $p->SetXY(12, $yl);
            $p->SetFont('Helvetica', '', 7.5);
            $p->gris();
            $p->MultiCell(112, 4, self::t($ley), 0, 'L');
            $yl = $p->GetY() + 1;
        }
        if ($d['detraccion'] && $d['detraccion']['monto']) {
            $p->SetXY(12, $yl);
            $p->SetFont('Helvetica', '', 7.5);
            $p->gris();
            $p->MultiCell(112, 4, self::t('Detracción ' . ($d['detraccion']['porcentaje'] ? $d['detraccion']['porcentaje'] . '% ' : '') . ': ' . $m . ' ' . Comprobante::dinero($d['detraccion']['monto']) . ($d['detraccion']['cuenta'] ? ' - Cta. Banco de la Nación ' . $d['detraccion']['cuenta'] : '')), 0, 'L');
            $yl = $p->GetY() + 1;
        }
        if ($d['nota']) {
            $p->SetXY(12, $yl);
            $p->SetFont('Helvetica', 'B', 8);
            $p->negro();
            $p->Cell(112, 4.4, self::t('Observaciones:'), 0, 1, 'L');
            $p->SetFont('Helvetica', '', 8);
            $p->SetX(12);
            $p->MultiCell(112, 4.2, self::t($d['nota']), 0, 'L');
            $yl = $p->GetY() + 1;
        }
        $y = max($yt, $yl) + 5;

        // ---- QR de SUNAT, huella, verificacion y pie (anclados al final de la ultima pagina)
        $p->SetFont('Helvetica', '', 8);
        $nPie = $cfg['pie'] !== '' ? $p->nbLineas(186, self::t($cfg['pie'])) : 0;
        $yPie = $p->limite() - (37 + $nPie * 4.2 + ($nPie ? 2 : 0));
        $y = max($y, $yPie);
        $p->SetDrawColor(205, 211, 222);
        $p->Line(12, $y, 198, $y);
        $y += 3;
        $p->Image($ctx['qrSunat'], 12, $y, 30, 30, 'PNG');
        $p->SetXY(45, $y + 1);
        $p->SetFont('Helvetica', 'B', 7.5);
        $p->negro();
        $p->Cell(100, 4, self::t('Código QR (SUNAT)'), 0, 1, 'L');
        $p->SetFont('Helvetica', '', 7);
        $p->gris();
        if ($d['hash']) {
            $p->SetX(45);
            $p->Cell(100, 3.8, self::t('Resumen (hash): ' . $d['hash']), 0, 1, 'L');
        }
        $p->SetX(45);
        $p->MultiCell(100, 3.8, self::t('Representación impresa de la ' . mb_strtolower($d['nombre_tipo'], 'UTF-8') . '. Puede consultar su validez en el portal de SUNAT con los datos del código QR.'), 0, 'L');
        if ($ctx['urlVerif']) {
            $xq = 160;
            $p->Image($ctx['qrVerif'], $xq + 8, $y, 22, 22, 'PNG');
            $p->SetFont('Helvetica', 'B', 6.8);
            $p->negro();
            $p->SetXY($xq - 2, $y + 22.5);
            $p->Cell(42, 3.4, self::t('Verifique este documento'), 0, 1, 'C');
            $p->SetFont('Helvetica', '', 5.6);
            $p->gris();
            $yy = $y + 26;
            foreach ($p->partir($ctx['urlVerif'], 42) as $ln) {
                $p->SetXY($xq - 2, $yy);
                $p->Cell(42, 2.6, self::t($ln), 0, 0, 'C');
                $yy += 2.6;
            }
        }
        $y += 34;
        if ($cfg['pie'] !== '') {
            $p->SetXY(12, $y);
            $p->SetFont('Helvetica', '', 8);
            $p->gris();
            $p->MultiCell(186, 4.2, self::t($cfg['pie']), 0, 'C');
        }
        return $p->Output('S');
    }

    // ================================================================== ticket 80 mm
    private static function ticket(array $d, array $cfg, array $ctx)
    {
        // Se dibuja dos veces: la primera mide cuanto mide el ticket; la segunda crea una pagina de ESE alto (sin cortes ni espacio de sobra).
        $alto = 0;
        $pdf = '';
        for ($pasada = 1; $pasada <= 2; $pasada++) {
            $p = new PdfDoc('P', 'mm', array(80, $pasada === 1 ? 3000 : $alto));
            $p->modo = 'ticket';
            $p->rgb = PdfDoc::hexARgb($cfg['color']);
            $p->nombreTipo = $d['nombre_tipo'];
            $p->SetTitle(self::t($d['nombre_tipo'] . ' ' . $d['serie'] . '-' . self::num8($d['numero'])));
            $p->SetMargins(4, 4, 4);
            $p->SetAutoPageBreak(false);
            $p->AddPage();
            $y = self::dibujarTicket($p, $d, $cfg, $ctx);
            if ($pasada === 1) {
                $alto = ceil($y + 4);
            } else {
                $pdf = $p->Output('S');
            }
        }
        return $pdf;
    }

    private static function dibujarTicket(PdfDoc $p, array $d, array $cfg, array $ctx)
    {
        $W = 72;
        $m = Comprobante::simboloMoneda($d['moneda']);
        $y = 4;
        $linea = function ($yy) use ($p) {
            $p->SetDrawColor(150, 158, 170);
            $p->SetLineWidth(0.2);
            $p->Line(4, $yy, 76, $yy);
        };
        if ($ctx['logo'] && is_file($ctx['logo'])) {
            $sz = @getimagesize($ctx['logo']);
            if ($sz) {
                $esc = min(44 / $sz[0], 18 / $sz[1]);
                $iw = $sz[0] * $esc;
                $ih = $sz[1] * $esc;
                $p->Image($ctx['logo'], 4 + ($W - $iw) / 2, $y, $iw, $ih, 'PNG');
                $y += $ih + 2;
            }
        }
        $p->SetXY(4, $y);
        $p->SetFont('Helvetica', 'B', 9.5);
        $p->acento();
        $p->MultiCell($W, 4.4, self::t($d['emisor']['razon_social']), 0, 'C');
        $p->SetFont('Helvetica', '', 7.5);
        $p->negro();
        if ($d['emisor']['comercial'] && mb_strtoupper($d['emisor']['comercial'], 'UTF-8') !== mb_strtoupper((string) $d['emisor']['razon_social'], 'UTF-8')) {
            $p->SetX(4);
            $p->MultiCell($W, 3.6, self::t($d['emisor']['comercial']), 0, 'C');
        }
        $p->SetX(4);
        $p->SetFont('Helvetica', 'B', 8);
        $p->Cell($W, 4, self::t('RUC ' . $d['emisor']['ruc']), 0, 1, 'C');
        $p->SetFont('Helvetica', '', 7);
        if ($d['emisor']['direccion']) {
            $p->SetX(4);
            $p->MultiCell($W, 3.3, self::t($d['emisor']['direccion']), 0, 'C');
        }
        if ($cfg['contacto'] !== '') {
            $p->SetX(4);
            $p->gris();
            $p->MultiCell($W, 3.3, self::t($cfg['contacto']), 0, 'C');
            $p->negro();
        }
        $y = $p->GetY() + 1.5;
        $linea($y);
        $p->SetXY(4, $y + 1.2);
        $p->SetFont('Helvetica', 'B', 8.5);
        $p->MultiCell($W, 4, self::t($d['nombre_tipo']), 0, 'C');
        $p->SetX(4);
        $p->SetFont('Helvetica', 'B', 11);
        $p->Cell($W, 5.5, self::t($d['serie'] . ' - ' . self::num8($d['numero'])), 0, 1, 'C');
        $y = $p->GetY() + 0.5;
        $linea($y);
        $p->SetXY(4, $y + 1.2);
        $p->SetFont('Helvetica', '', 7.2);
        $kv = array(array('Fecha:', ($d['fecha'] ? date('d/m/Y', strtotime($d['fecha'])) : '') . ($d['hora'] ? ' ' . substr($d['hora'], 0, 5) : '')),
            array($d['cliente']['nombre_tipo_documento'] . ':', $d['cliente']['numero_documento']), array('Cliente:', $d['cliente']['nombre']));
        if ($d['cliente']['direccion']) {
            $kv[] = array('Dirección:', $d['cliente']['direccion']);
        }
        if ($d['forma_pago']) {
            $kv[] = array('Pago:', strtoupper($d['forma_pago']) === 'CREDITO' ? 'Crédito' : $d['forma_pago']);
        }
        if ($d['vencimiento'] && $d['tipo'] !== '07' && $d['tipo'] !== '08' && strtoupper((string) $d['forma_pago']) === 'CREDITO') {
            $kv[] = array('Vence:', date('d/m/Y', strtotime($d['vencimiento'])));
        }
        if (isset($d['modifica'])) {
            $kv[] = array('Modifica:', $d['modifica']['documento']);
            $kv[] = array('Motivo:', $d['modifica']['motivo']);
        }
        foreach ($kv as $f) {
            $p->SetX(4);
            $p->SetFont('Helvetica', 'B', 7.2);
            $p->Cell(15, 3.6, self::t($f[0]), 0, 0, 'L');
            $p->SetFont('Helvetica', '', 7.2);
            $p->MultiCell($W - 15, 3.6, self::t($f[1]), 0, 'L');
        }
        $y = $p->GetY() + 1;
        $linea($y);
        // items
        $p->SetXY(4, $y + 1);
        $p->SetFont('Helvetica', 'B', 7);
        $p->Cell(10, 3.6, 'CANT', 0, 0, 'L');
        $p->Cell(42, 3.6, self::t('DESCRIPCIÓN'), 0, 0, 'L');
        $p->Cell(20, 3.6, 'IMPORTE', 0, 1, 'R');
        $y = $p->GetY();
        $linea($y);
        foreach ($d['items'] as $it) {
            $p->SetXY(4, $y + 1);
            $p->SetFont('Helvetica', '', 7);
            $p->Cell(10, 3.5, self::t(Comprobante::numero($it['cantidad'], 0, 3)), 0, 0, 'L');
            $desc = $it['descripcion'] . ($it['gratuito'] ? ' (GRATUITO)' : '');
            $xd = $p->GetX();
            $p->MultiCell(42, 3.5, self::t($desc), 0, 'L');
            $yFin = $p->GetY();
            $p->SetXY($xd + 42, $y + 1);
            $p->Cell(20, 3.5, self::t(Comprobante::dinero($it['valor_venta'])), 0, 0, 'R');
            $p->SetXY(14, $yFin);
            $p->SetFont('Helvetica', '', 6.5);
            $p->gris();
            $p->Cell(58, 3.2, self::t($it['unidad'] . ' x ' . $m . ' ' . ($it['gratuito'] ? '0.00' : Comprobante::numero($it['precio_unitario'], 2, 4)) . ($it['codigo'] ? '  [' . $it['codigo'] . ']' : '')), 0, 1, 'L');
            $p->negro();
            $y = $p->GetY() + 0.6;
        }
        $linea($y);
        // totales
        $yy = $y + 1.5;
        foreach (self::filasTotales($d) as $f) {
            $p->SetXY(4, $yy);
            $p->SetFont('Helvetica', '', 7.5);
            $p->Cell(42, 3.8, self::t($f[0]), 0, 0, 'R');
            $p->Cell(30, 3.8, self::t($f[1]), 0, 0, 'R');
            $yy += 3.8;
        }
        $p->SetFillColor($p->rgb[0], $p->rgb[1], $p->rgb[2]);
        $p->SetTextColor(255, 255, 255);
        $p->SetFont('Helvetica', 'B', 9.5);
        $p->SetXY(4, $yy + 1);
        $p->Cell(28, 6, self::t('TOTAL'), 0, 0, 'L', true);
        $p->Cell(44, 6, self::t($m . ' ' . Comprobante::dinero($d['totales']['total'])), 0, 1, 'R', true);
        $p->negro();
        $yy = $p->GetY() + 1.5;
        if (!empty($d['en_letras'])) {
            $p->SetXY(4, $yy);
            $p->SetFont('Helvetica', 'B', 7);
            $p->MultiCell($W, 3.4, self::t('SON: ' . $d['en_letras']), 0, 'L');
            $yy = $p->GetY() + 1;
        }
        if ($d['cuotas']) {
            $p->SetXY(4, $yy);
            $p->SetFont('Helvetica', 'B', 7);
            $p->Cell($W, 3.6, self::t('Cuotas'), 0, 1, 'L');
            $p->SetFont('Helvetica', '', 7);
            foreach ($d['cuotas'] as $cu) {
                $p->SetX(4);
                $p->Cell(34, 3.4, self::t('Cuota ' . $cu['numero'] . '  ' . ($cu['fecha'] ? date('d/m/Y', strtotime($cu['fecha'])) : '')), 0, 0, 'L');
                $p->Cell(38, 3.4, self::t($m . ' ' . Comprobante::dinero($cu['monto'])), 0, 1, 'R');
            }
            $yy = $p->GetY() + 1;
        }
        foreach ($d['leyendas'] as $ley) {
            $p->SetXY(4, $yy);
            $p->SetFont('Helvetica', '', 6.5);
            $p->gris();
            $p->MultiCell($W, 3.1, self::t($ley), 0, 'L');
            $p->negro();
            $yy = $p->GetY() + 0.8;
        }
        if ($d['nota']) {
            $p->SetXY(4, $yy);
            $p->SetFont('Helvetica', '', 7);
            $p->MultiCell($W, 3.4, self::t('Obs.: ' . $d['nota']), 0, 'L');
            $yy = $p->GetY() + 1;
        }
        $linea($yy);
        // QR de SUNAT, huella y verificacion
        $yy += 2.5;
        $p->Image($ctx['qrSunat'], 4 + ($W - 32) / 2, $yy, 32, 32, 'PNG');
        $yy += 33.5;
        $p->SetXY(4, $yy);
        $p->SetFont('Helvetica', '', 6.5);
        $p->gris();
        if ($d['hash']) {
            foreach ($p->partir('Hash: ' . $d['hash'], $W) as $ln) {
                $p->SetX(4);
                $p->Cell($W, 3, self::t($ln), 0, 1, 'C');
            }
        }
        $p->SetX(4);
        $p->MultiCell($W, 3, self::t('Representación impresa de la ' . mb_strtolower($d['nombre_tipo'], 'UTF-8') . '. Consulte su validez en SUNAT.'), 0, 'C');
        $yy = $p->GetY() + 2;
        if ($ctx['urlVerif']) {
            $p->Image($ctx['qrVerif'], 4 + ($W - 22) / 2, $yy, 22, 22, 'PNG');
            $yy += 23;
            $p->SetXY(4, $yy);
            $p->SetFont('Helvetica', 'B', 7);
            $p->negro();
            $p->Cell($W, 3.4, self::t('Verifique este documento'), 0, 1, 'C');
            $p->SetFont('Helvetica', '', 5.8);
            $p->gris();
            foreach ($p->partir($ctx['urlVerif'], $W - 2) as $ln) {
                $p->SetX(4);
                $p->Cell($W, 2.8, self::t($ln), 0, 1, 'C');
            }
            $yy = $p->GetY() + 1.5;
        }
        if ($cfg['pie'] !== '') {
            $p->SetXY(4, $yy);
            $p->SetFont('Helvetica', '', 7);
            $p->MultiCell($W, 3.4, self::t($cfg['pie']), 0, 'C');
            $yy = $p->GetY();
        }
        return $yy;
    }

    // ================================================================== ejemplo (vista previa en el portal)
    /** Comprobante de ejemplo (con datos ficticios) para que el cliente vea como quedan su logo, color y pie. */
    public static function ejemplo(array $emp)
    {
        return array(
            'tipo' => '01', 'nombre_tipo' => 'FACTURA ELECTRÓNICA', 'serie' => 'F001', 'numero' => '00000123', 'fecha' => date('Y-m-d'), 'hora' => '10:30:00', 'vencimiento' => date('Y-m-d', strtotime('+30 days')),
            'moneda' => 'PEN', 'orden_compra' => 'OC-2026-045', 'hash' => 'DpL3Tb7gsOql6mMKZA61o3mQ7+Q=', 'en_letras' => 'Mil ciento ochenta con 00/100 soles', 'leyendas' => array(), 'guias' => array(), 'anticipos' => array(),
            'emisor' => array('ruc' => $emp['ruc'], 'razon_social' => $emp['razon_social'], 'comercial' => $emp['nombre_comercial'], 'direccion' => trim($emp['domicilio_fiscal'] . ' - ' . $emp['distrito'] . ' - ' . $emp['provincia'], ' -')),
            'cliente' => array('tipo_documento' => '6', 'nombre_tipo_documento' => 'RUC', 'numero_documento' => '20987654321', 'nombre' => 'CLIENTE DE EJEMPLO S.A.C.', 'direccion' => 'AV. EJEMPLO 456 - LIMA'),
            'nota' => 'Este es un comprobante de EJEMPLO para ver su logo, color y pie de página. No es un documento real.',
            'forma_pago' => 'Credito', 'monto_credito' => '1180.00', 'detraccion' => null,
            'cuotas' => array(array('numero' => 1, 'monto' => '590.00', 'fecha' => date('Y-m-d', strtotime('+15 days'))), array('numero' => 2, 'monto' => '590.00', 'fecha' => date('Y-m-d', strtotime('+30 days')))),
            'tributos' => array('1000' => array('codigo' => '1000', 'nombre' => 'IGV', 'base' => '1000.00', 'monto' => '180.00')),
            'totales' => array('valor_venta' => '1000.00', 'con_impuestos' => '1180.00', 'descuentos' => null, 'cargos' => '0.00', 'anticipos' => '0', 'total' => '1180.00'), 'descuento_global' => null,
            'items' => array(
                array('numero' => '1', 'cantidad' => '2.00', 'unidad' => 'UND', 'codigo' => 'PROD001', 'descripcion' => 'PRODUCTO DE EJEMPLO UNO', 'valor_unitario' => '250.000000', 'valor_venta' => '500.00', 'precio_unitario' => '295.00', 'gratuito' => false, 'igv_porcentaje' => '18', 'descuento' => null),
                array('numero' => '2', 'cantidad' => '1.00', 'unidad' => 'SERV', 'codigo' => 'SERV002', 'descripcion' => 'SERVICIO DE EJEMPLO CON UNA DESCRIPCIÓN LARGA QUE OCUPA VARIAS LÍNEAS EN EL COMPROBANTE', 'valor_unitario' => '500.000000', 'valor_venta' => '500.00', 'precio_unitario' => '590.00', 'gratuito' => false, 'igv_porcentaje' => '18', 'descuento' => null),
            ),
        );
    }
}
