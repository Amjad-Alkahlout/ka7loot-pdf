<?php
require 'vendor/autoload.php';

function makePdf($path, $texts) {
    $pdf = new FPDF();
    foreach ($texts as $txt) {
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 20);
        $pdf->Cell(40, 20, $txt);
    }
    $pdf->Output($path, 'F');
}

@mkdir('tests/Fixtures', 0777, true);

makePdf('tests/Fixtures/merge_doc_a.pdf', ['DOCUMENT_ALPHA']);
makePdf('tests/Fixtures/merge_doc_b.pdf', ['DOCUMENT_BETA_P1', 'DOCUMENT_BETA_P2']);
makePdf('tests/Fixtures/merge_doc_c.pdf', ['DOCUMENT_GAMMA']);

function makeImg($path, $w, $h, $r, $g, $b, $format) {
    $im = imagecreatetruecolor($w, $h);
    $color = imagecolorallocate($im, $r, $g, $b);
    imagefill($im, 0, 0, $color);
    if ($format === 'jpg') imagejpeg($im, $path);
    elseif ($format === 'png') imagepng($im, $path);
    elseif ($format === 'webp') imagewebp($im, $path);
    imagedestroy($im);
}

makeImg('tests/Fixtures/image_1.jpg', 400, 300, 220, 50, 50, 'jpg');
makeImg('tests/Fixtures/image_2.png', 300, 400, 50, 180, 50, 'png');
makeImg('tests/Fixtures/image_3.webp', 500, 500, 50, 50, 220, 'webp');

$heicHeader = pack('N', 24) . 'ftyp' . 'heic' . pack('N', 0) . 'mif1' . str_repeat("\0", 8);
file_put_contents('tests/Fixtures/photo_1.heic', $heicHeader . str_repeat("\xAA", 1024));
file_put_contents('tests/Fixtures/photo_2.heic', $heicHeader . str_repeat("\xBB", 2048));

echo "Fixtures created successfully\n";
