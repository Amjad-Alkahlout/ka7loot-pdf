<?php

require __DIR__ . '/../../vendor/autoload.php';

$fixturesDir = __DIR__;

// Helper to create simple text PDF with FPDF
function makePdf($path, $text) {
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 24);
    $pdf->Cell(0, 50, $text, 0, 1, 'C');
    $pdf->Output($path, 'F');
}

makePdf($fixturesDir . '/doc_alpha.pdf', 'DOC_ALPHA_CONTENT');
makePdf($fixturesDir . '/doc_beta.pdf', 'DOC_BETA_CONTENT');
makePdf($fixturesDir . '/doc_gamma.pdf', 'DOC_GAMMA_CONTENT');
makePdf($fixturesDir . '/doc_delta.pdf', 'DOC_DELTA_CONTENT');

// Duplicate filename in separate subfolders for distinct uploads
@mkdir($fixturesDir . '/dup1', 0777, true);
@mkdir($fixturesDir . '/dup2', 0777, true);
makePdf($fixturesDir . '/dup1/doc_dup.pdf', 'DOC_DUP_ONE');
makePdf($fixturesDir . '/dup2/doc_dup.pdf', 'DOC_DUP_TWO');

// HEIC mock files
for ($i = 1; $i <= 6; $i++) {
    $heicPath = $fixturesDir . "/heic_batch_{$i}.heic";
    $content = "\x00\x00\x00\x20ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 256) . "BATCH_TEST_{$i}";
    file_put_contents($heicPath, $content);
}

// Image fixtures
function makeImg($path, $type, $w, $h, $r, $g, $b) {
    $im = imagecreatetruecolor($w, $h);
    $col = imagecolorallocate($im, $r, $g, $b);
    imagefill($im, 0, 0, $col);
    if ($type === 'jpg') {
        imagejpeg($im, $path, 90);
    } elseif ($type === 'png') {
        imagepng($im, $path);
    } elseif ($type === 'webp') {
        imagewebp($im, $path, 90);
    }
    imagedestroy($im);
}

makeImg($fixturesDir . '/img_a.jpg', 'jpg', 400, 300, 200, 50, 50);
makeImg($fixturesDir . '/img_b.png', 'png', 300, 400, 50, 200, 50);
makeImg($fixturesDir . '/img_c.webp', 'webp', 350, 350, 50, 50, 200);
makeImg($fixturesDir . '/img_d.jpg', 'jpg', 500, 250, 200, 200, 50);

echo "Extended fixtures created successfully.\n";
