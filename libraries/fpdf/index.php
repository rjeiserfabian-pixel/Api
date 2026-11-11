<?php

require('fpdf.php');


$pdf = new FPDF('P', 'mm', array(100,150));

$pdf->AddPage();
$pdf->SetFont('Arial','B',16);
$pdf->Cell(40,10,'aa');
$pdf->Output();

?>