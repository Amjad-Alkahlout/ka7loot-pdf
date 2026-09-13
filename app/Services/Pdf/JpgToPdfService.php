<?php

namespace App\Services\Pdf;

use App\Enums\PageOrientation;
use App\Enums\PageSize;
use FPDF;
use InvalidArgumentException;
use RuntimeException;

class JpgToPdfService
{
    /**
     * Convert an ordered list of image files into a single PDF document.
     *
     * @param array<string> $imagePaths Array of absolute paths to images
     * @param string $outputPath Absolute path to output PDF
     * @param PageSize $pageSize A4, LETTER, LEGAL, or FIT
     * @param PageOrientation $orientation PORTRAIT, LANDSCAPE, or AUTO
     * @param float $margin Margin in millimeters (e.g. 0.0, 10.0)
     * @return array{
     *     output_path: string,
     *     total_pages: int,
     *     size_bytes: int
     * }
     */
    public function convertImagesToPdf(
        array $imagePaths,
        string $outputPath,
        PageSize $pageSize = PageSize::A4,
        PageOrientation $orientation = PageOrientation::AUTO,
        float $margin = 10.0
    ): array {
        if (empty($imagePaths)) {
            throw new InvalidArgumentException("At least one image is required to generate a PDF.");
        }

        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(false);

        $totalPages = 0;

        try {
            foreach ($imagePaths as $imgPath) {
                if (!file_exists($imgPath)) {
                    continue;
                }

                $imgInfo = @getimagesize($imgPath);
                if (!$imgInfo) {
                    continue;
                }

                $imgWidthPx = $imgInfo[0];
                $imgHeightPx = $imgInfo[1];

                // Determine orientation if set to AUTO
                $resolvedOrientation = match ($orientation) {
                    PageOrientation::PORTRAIT  => 'P',
                    PageOrientation::LANDSCAPE => 'L',
                    PageOrientation::AUTO      => ($imgWidthPx > $imgHeightPx) ? 'L' : 'P',
                };

                // Determine page dimensions in mm
                if ($pageSize === PageSize::FIT) {
                    // Convert pixels to mm (assuming standard 72 DPI display points: 1 pt = 0.352778 mm)
                    $ptToMm = 0.352778;
                    $pageWidthMm = $imgWidthPx * $ptToMm;
                    $pageHeightMm = $imgHeightPx * $ptToMm;
                    $pageMargin = 0.0; // Fit mode is borderless
                    $pdf->AddPage($resolvedOrientation, [$pageWidthMm, $pageHeightMm]);
                } else {
                    $dims = $pageSize->dimensions(); // [width, height] in portrait
                    $pw = ($resolvedOrientation === 'L') ? $dims[1] : $dims[0];
                    $ph = ($resolvedOrientation === 'L') ? $dims[0] : $dims[1];
                    $pageWidthMm = $pw;
                    $pageHeightMm = $ph;
                    $pageMargin = max(0.0, $margin);
                    $pdf->AddPage($resolvedOrientation, [$pw, $ph]);
                }

                // Usable drawing area inside margins
                $usableWidth = max(1.0, $pageWidthMm - (2 * $pageMargin));
                $usableHeight = max(1.0, $pageHeightMm - (2 * $pageMargin));

                // Scale image proportionally to fit usable bounds
                $scaleFactor = min($usableWidth / $imgWidthPx, $usableHeight / $imgHeightPx);
                $finalWidth = $imgWidthPx * $scaleFactor;
                $finalHeight = $imgHeightPx * $scaleFactor;

                // Center image in the page
                $posX = $pageMargin + (($usableWidth - $finalWidth) / 2);
                $posY = $pageMargin + (($usableHeight - $finalHeight) / 2);

                $pdf->Image($imgPath, $posX, $posY, $finalWidth, $finalHeight);
                $totalPages++;
            }

            if ($totalPages === 0) {
                throw new RuntimeException("No valid images could be placed into the PDF document.");
            }

            $pdf->Output($outputPath, 'F');

            return [
                'output_path' => $outputPath,
                'total_pages' => $totalPages,
                'size_bytes'  => (int) filesize($outputPath),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("Image to PDF conversion failed: {$e->getMessage()}", 0, $e);
        }
    }
}
