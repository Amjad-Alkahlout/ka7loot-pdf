<?php

namespace App\Services\Pdf;

use InvalidArgumentException;
use RuntimeException;

class PdfOrganizeService
{
    /**
     * Reorganize, reorder, delete, and rotate pages of a PDF document.
     *
     * @param string $sourcePath
     * @param string $outputPath
     * @param array<array{page: int, rotation?: int}> $pageOperations Array of ordered pages with optional rotation
     * @return array{
     *     output_path: string,
     *     total_pages: int,
     *     size_bytes: int
     * }
     */
    public function organize(string $sourcePath, string $outputPath, array $pageOperations): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        if (empty($pageOperations)) {
            throw new InvalidArgumentException("At least one page must be retained in the organized document.");
        }

        try {
            $pdf = new RotatableFpdi();
            $maxPages = $pdf->setSourceFile($sourcePath);

            $pageCounter = 0;
            foreach ($pageOperations as $op) {
                $pageNo = (int) ($op['page'] ?? 0);
                $rotation = (int) ($op['rotation'] ?? 0);

                if ($pageNo < 1 || $pageNo > $maxPages) {
                    continue;
                }

                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
                $pageCounter++;

                $normalizedRotation = (($rotation % 360) + 360) % 360;
                if ($normalizedRotation !== 0) {
                    $pdf->setPageRotation($normalizedRotation, $pageCounter);
                }
            }

            if ($pageCounter === 0) {
                throw new RuntimeException("No valid pages remained after processing operations.");
            }

            $pdf->Output($outputPath, 'F');

            return [
                'output_path' => $outputPath,
                'total_pages' => $pageCounter,
                'size_bytes'  => (int) filesize($outputPath),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("PDF organization failed: {$e->getMessage()}", 0, $e);
        }
    }
}
