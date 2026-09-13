<?php

namespace App\Services\Pdf;

use InvalidArgumentException;
use RuntimeException;

class PdfSplitService
{
    /**
     * Parse a human-readable page range string (e.g. "1-3, 5, 8-10") into a sorted list of unique page integers.
     *
     * @param string $rangeStr
     * @param int $maxPages
     * @return array<int>
     */
    public function parsePageRange(string $rangeStr, int $maxPages): array
    {
        $pages = [];
        $parts = explode(',', $rangeStr);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            if (str_contains($part, '-')) {
                $sub = explode('-', $part);
                if (count($sub) !== 2) {
                    throw new InvalidArgumentException("Invalid range format: '{$part}'");
                }
                $start = (int) trim($sub[0]);
                $end = (int) trim($sub[1]);

                if ($start <= 0 || $end <= 0 || $start > $end) {
                    throw new InvalidArgumentException("Invalid range interval: '{$part}'");
                }

                for ($i = $start; $i <= $end; $i++) {
                    if ($i <= $maxPages) {
                        $pages[] = $i;
                    }
                }
            } else {
                $single = (int) $part;
                if ($single > 0 && $single <= $maxPages) {
                    $pages[] = $single;
                }
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        if (empty($pages)) {
            throw new InvalidArgumentException("No valid pages found in range '{$rangeStr}' (total pages in document: {$maxPages})");
        }

        return $pages;
    }

    /**
     * Split each page of a PDF into individual PDF files.
     *
     * @param string $sourcePath Absolute path to input PDF
     * @param string $outputDir Directory where single page PDFs will be placed
     * @param string $prefix Prefix for generated file names
     * @return array<array{path: string, name: string, page: int}>
     */
    public function splitAllPages(string $sourcePath, string $outputDir, string $prefix = 'page'): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $results = [];

        try {
            $reader = new RotatableFpdi();
            $pageCount = $reader->setSourceFile($sourcePath);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $pdf = new RotatableFpdi();
                $pdf->setSourceFile($sourcePath);
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);

                $filename = sprintf("%s_%03d.pdf", $prefix, $pageNo);
                $outPath = $outputDir . DIRECTORY_SEPARATOR . $filename;
                $pdf->Output($outPath, 'F');

                $results[] = [
                    'path' => $outPath,
                    'name' => $filename,
                    'page' => $pageNo,
                ];
            }

            return $results;
        } catch (\Throwable $e) {
            throw new RuntimeException("Splitting all pages failed: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Extract specific page numbers into a single combined PDF.
     *
     * @param string $sourcePath Absolute path to input PDF
     * @param string $outputPath Output PDF path
     * @param array<int> $pages 1-indexed page numbers to extract
     * @return array{
     *     output_path: string,
     *     extracted_pages: int,
     *     size_bytes: int
     * }
     */
    public function extractPages(string $sourcePath, string $outputPath, array $pages): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        if (empty($pages)) {
            throw new InvalidArgumentException("No pages specified for extraction.");
        }

        try {
            $pdf = new RotatableFpdi();
            $pageCount = $pdf->setSourceFile($sourcePath);

            $extracted = 0;
            foreach ($pages as $pageNo) {
                if ($pageNo < 1 || $pageNo > $pageCount) {
                    continue;
                }

                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
                $extracted++;
            }

            if ($extracted === 0) {
                throw new RuntimeException("No matching pages could be extracted.");
            }

            $pdf->Output($outputPath, 'F');

            return [
                'output_path'     => $outputPath,
                'extracted_pages' => $extracted,
                'size_bytes'      => (int) filesize($outputPath),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("Extracting pages failed: {$e->getMessage()}", 0, $e);
        }
    }
}
