<?php

namespace App\Services\Pdf;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PdfAnnotationApplierService
{
    /**
     * Apply additive text boxes and graphic shapes to a PDF document.
     *
     * @param string $sourcePath Path to input PDF
     * @param string $outputPath Path to output PDF
     * @param array<int, array> $addedTexts Additive text items
     * @param array<int, array> $addedShapes Additive shape items
     * @return array
     */
    public function apply(
        string $sourcePath,
        string $outputPath,
        array $addedTexts = [],
        array $addedShapes = []
    ): array {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        // If no additive items, copy source to output
        if (empty($addedTexts) && empty($addedShapes)) {
            if ($sourcePath !== $outputPath) {
                copy($sourcePath, $outputPath);
            }
            return [
                'success'    => true,
                'path'       => $outputPath,
                'size_bytes' => filesize($outputPath),
            ];
        }

        $outDir = dirname($outputPath);
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            throw new RuntimeException("Failed to create directory: {$outDir}");
        }

        $pdf = new RotatableFpdi();
        $pageCount = $pdf->setSourceFile($sourcePath);

        // Group additions by 1-indexed page number
        $textsByPage = [];
        foreach ($addedTexts as $t) {
            $p = (int) ($t['page'] ?? 1);
            $textsByPage[$p][] = $t;
        }

        $shapesByPage = [];
        foreach ($addedShapes as $s) {
            $p = (int) ($s['page'] ?? 1);
            $shapesByPage[$p][] = $s;
        }

        for ($p = 1; $p <= $pageCount; $p++) {
            $tplId = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tplId);

            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
            $pdf->AddPage($orientation, [$size['width'], $size['height']]);
            $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

            $w = (float) $size['width'];
            $h = (float) $size['height'];

            // 1. Draw shapes first (under text)
            if (!empty($shapesByPage[$p])) {
                foreach ($shapesByPage[$p] as $shape) {
                    $type = $shape['type'] ?? 'rect';
                    $xRatio = max(0.0, min(1.0, (float) ($shape['x_ratio'] ?? 0.1)));
                    $yRatio = max(0.0, min(1.0, (float) ($shape['y_ratio'] ?? 0.1)));
                    $wRatio = max(0.01, min(1.0, (float) ($shape['width_ratio'] ?? 0.2)));
                    $hRatio = max(0.01, min(1.0, (float) ($shape['height_ratio'] ?? 0.05)));

                    $sx = $xRatio * $w;
                    $sy = $yRatio * $h;
                    $sw = $wRatio * $w;
                    $sh = $hRatio * $h;

                    if ($type === 'highlight') {
                        // Translucent yellow highlight: 255, 235, 59
                        $pdf->SetFillColor(255, 235, 59);
                        $pdf->Rect($sx, $sy, $sw, $sh, 'F');
                    } elseif ($type === 'line') {
                        $stroke = $this->hexToRgb($shape['stroke_color'] ?? '#ef4444');
                        $pdf->SetDrawColor($stroke['r'], $stroke['g'], $stroke['b']);
                        $pdf->SetLineWidth(2);
                        $pdf->Line($sx, $sy, $sx + $sw, $sy + $sh);
                    } else {
                        // Standard Rectangle
                        $stroke = $this->hexToRgb($shape['stroke_color'] ?? '#2563eb');
                        $pdf->SetDrawColor($stroke['r'], $stroke['g'], $stroke['b']);
                        $pdf->SetLineWidth(1.5);

                        if (!empty($shape['fill_color']) && $shape['fill_color'] !== 'none') {
                            $fill = $this->hexToRgb($shape['fill_color']);
                            $pdf->SetFillColor($fill['r'], $fill['g'], $fill['b']);
                            $pdf->Rect($sx, $sy, $sw, $sh, 'FD');
                        } else {
                            $pdf->Rect($sx, $sy, $sw, $sh, 'D');
                        }
                    }
                }
            }

            // 2. Draw additive text
            if (!empty($textsByPage[$p])) {
                foreach ($textsByPage[$p] as $txtItem) {
                    $textStr = trim((string) ($txtItem['text'] ?? ''));
                    if ($textStr === '') continue;

                    $fontSize = max(8, min(72, (float) ($txtItem['font_size'] ?? 14)));
                    $xRatio = max(0.0, min(1.0, (float) ($txtItem['x_ratio'] ?? 0.1)));
                    $yRatio = max(0.0, min(1.0, (float) ($txtItem['y_ratio'] ?? 0.1)));
                    $color = $this->hexToRgb($txtItem['color'] ?? '#000000');

                    $tx = $xRatio * $w;
                    $ty = ($yRatio * $h) + $fontSize; // Adjust baseline

                    $pdf->SetFont('Arial', '', $fontSize);
                    $pdf->SetTextColor($color['r'], $color['g'], $color['b']);
                    $pdf->Text($tx, $ty, utf8_decode($textStr));
                }
            }
        }

        $pdf->Output($outputPath, 'F');

        return [
            'success'    => true,
            'path'       => $outputPath,
            'size_bytes' => filesize($outputPath),
        ];
    }

    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6) {
            return ['r' => 0, 'g' => 0, 'b' => 0];
        }
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2)),
        ];
    }
}
