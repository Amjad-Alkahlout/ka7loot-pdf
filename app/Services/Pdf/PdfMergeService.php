<?php

namespace App\Services\Pdf;

use InvalidArgumentException;
use RuntimeException;

class PdfMergeService
{
    /**
     * Merge multiple PDF documents into a single output PDF.
     *
     * @param array<string> $sourcePaths Array of absolute paths to source PDF files in order
     * @param string $outputPath Absolute path to output merged PDF
     * @return array{
     *     output_path: string,
     *     total_pages: int,
     *     files_count: int,
     *     size_bytes: int
     * }
     */
    public function merge(array $sourcePaths, string $outputPath): array
    {
        if (count($sourcePaths) < 2) {
            throw new InvalidArgumentException("At least two PDF files are required for merging.");
        }

        foreach ($sourcePaths as $path) {
            if (!file_exists($path)) {
                throw new InvalidArgumentException("Source PDF not found: {$path}");
            }
        }

        $pdf = new RotatableFpdi();
        $totalPages = 0;

        try {
            foreach ($sourcePaths as $filePath) {
                $pageCount = $pdf->setSourceFile($filePath);

                for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                    $templateId = $pdf->importPage($pageNo);
                    $size = $pdf->getTemplateSize($templateId);

                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($templateId);
                    $totalPages++;
                }
            }

            $pdf->Output($outputPath, 'F');

            return [
                'output_path' => $outputPath,
                'total_pages' => $totalPages,
                'files_count' => count($sourcePaths),
                'size_bytes'  => (int) filesize($outputPath),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("PDF merge failed: {$e->getMessage()}", 0, $e);
        }
    }
}
