<?php

namespace App\Services\Pdf;

use InvalidArgumentException;
use RuntimeException;

class PdfSplitService
{
    /**
     * Get the total page count of a PDF file using FPDI.
     *
     * @param string $sourcePath
     * @return int
     */
    public function getPageCount(string $sourcePath): int
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        try {
            $reader = new RotatableFpdi();
            return (int) $reader->setSourceFile($sourcePath);
        } catch (\Throwable $e) {
            throw new RuntimeException("Failed to read PDF page count: {$e->getMessage()}", 0, $e);
        }
    }

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
     * Parse a range string into distinct split groups with strict validation against document bounds and duplicates.
     *
     * Example input: "1-5, 8, 10-12"
     * Output:
     * [
     *   ['id' => 1, 'label' => 'PDF 1', 'range' => '1-5', 'pages' => [1,2,3,4,5], 'count' => 5],
     *   ['id' => 2, 'label' => 'PDF 2', 'range' => '8', 'pages' => [8], 'count' => 1],
     *   ['id' => 3, 'label' => 'PDF 3', 'range' => '10-12', 'pages' => [10,11,12], 'count' => 3],
     * ]
     *
     * @param string $rangeStr
     * @param int $maxPages
     * @return array<array{id: int, label: string, range: string, pages: array<int>, count: int}>
     */
    public function parseRangeGroups(string $rangeStr, int $maxPages): array
    {
        if ($maxPages < 1) {
            throw new InvalidArgumentException("Document has no pages.");
        }

        $trimmed = trim($rangeStr);
        if ($trimmed === '') {
            throw new InvalidArgumentException("Please enter at least one page range.");
        }

        $parts = explode(',', $trimmed);
        $groups = [];
        $groupId = 1;

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '-')) {
                if (!preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                    throw new InvalidArgumentException("Invalid range format: '{$part}'");
                }
                $start = (int) $m[1];
                $end = (int) $m[2];

                if ($start <= 0 || $end <= 0) {
                    throw new InvalidArgumentException("Page numbers must be 1 or greater in '{$part}'");
                }
                if ($start > $end) {
                    throw new InvalidArgumentException("Invalid range interval '{$part}' (start page must be less than or equal to end page)");
                }
                if ($start > $maxPages) {
                    throw new InvalidArgumentException("Page {$start} exceeds total document pages ({$maxPages})");
                }
                if ($end > $maxPages) {
                    throw new InvalidArgumentException("Page {$end} exceeds total document pages ({$maxPages})");
                }

                $pages = range($start, $end);
                $label = ($start === $end) ? (string) $start : "{$start}-{$end}";
                $groups[] = [
                    'id'    => $groupId,
                    'label' => "PDF {$groupId}",
                    'range' => $label,
                    'pages' => $pages,
                    'count' => count($pages),
                ];
            } else {
                if (!preg_match('/^\d+$/', $part)) {
                    throw new InvalidArgumentException("Invalid range format: '{$part}'");
                }
                $page = (int) $part;
                if ($page <= 0) {
                    throw new InvalidArgumentException("Page numbers must be 1 or greater");
                }
                if ($page > $maxPages) {
                    throw new InvalidArgumentException("Page {$page} exceeds total document pages ({$maxPages})");
                }

                $groups[] = [
                    'id'    => $groupId,
                    'label' => "PDF {$groupId}",
                    'range' => (string) $page,
                    'pages' => [$page],
                    'count' => 1,
                ];
            }

            $groupId++;
        }

        if (empty($groups)) {
            throw new InvalidArgumentException("Please enter at least one valid page range.");
        }

        // Check for duplicate / overlapping page references across groups
        $seen = [];
        $duplicates = [];
        foreach ($groups as $group) {
            foreach ($group['pages'] as $p) {
                if (isset($seen[$p])) {
                    $duplicates[] = $p;
                }
                $seen[$p] = true;
            }
        }

        if (!empty($duplicates)) {
            $dupStr = implode(', ', array_unique($duplicates));
            throw new InvalidArgumentException("Duplicate page reference detected: page {$dupStr}");
        }

        return $groups;
    }

    /**
     * Generate discrete split groups for "Split every N pages" mode.
     *
     * @param int $pageCount Total pages in document
     * @param int $n Interval of pages per split
     * @return array<array{id: int, label: string, range: string, pages: array<int>, count: int}>
     */
    public function generateEveryNGroups(int $pageCount, int $n): array
    {
        if ($pageCount < 1) {
            throw new InvalidArgumentException("Document has no pages.");
        }
        if ($n < 1 || $n > $pageCount) {
            throw new InvalidArgumentException("Page interval must be between 1 and {$pageCount}.");
        }

        $groups = [];
        $current = 1;
        $groupId = 1;

        while ($current <= $pageCount) {
            $end = min($current + $n - 1, $pageCount);
            $pages = range($current, $end);
            $rangeLabel = ($current === $end) ? (string) $current : "{$current}-{$end}";

            $groups[] = [
                'id'    => $groupId,
                'label' => "PDF {$groupId}",
                'range' => $rangeLabel,
                'pages' => $pages,
                'count' => count($pages),
            ];

            $current = $end + 1;
            $groupId++;
        }

        return $groups;
    }

    /**
     * Generate discrete split groups for "Extract every individual page" mode.
     *
     * @param int $pageCount Total pages in document
     * @return array<array{id: int, label: string, range: string, pages: array<int>, count: int}>
     */
    public function generateIndividualGroups(int $pageCount): array
    {
        if ($pageCount < 1) {
            throw new InvalidArgumentException("Document has no pages.");
        }

        $groups = [];
        for ($i = 1; $i <= $pageCount; $i++) {
            $groups[] = [
                'id'    => $i,
                'label' => "PDF {$i}",
                'range' => (string) $i,
                'pages' => [$i],
                'count' => 1,
            ];
        }

        return $groups;
    }

    /**
     * Extract multiple groups of pages into separate PDF files.
     *
     * @param string $sourcePath Absolute path to input PDF
     * @param string $outputDir Directory where generated PDFs will be placed
     * @param array $groups List of groups from parseRangeGroups or generateEveryNGroups
     * @param string $prefix File name prefix
     * @return array<array{path: string, name: string, pages: array<int>, size_bytes: int}>
     */
    public function extractGroups(string $sourcePath, string $outputDir, array $groups, string $prefix = 'split_part'): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $results = [];
        $index = 1;

        foreach ($groups as $group) {
            $pages = $group['pages'] ?? [];
            if (empty($pages)) {
                continue;
            }

            $rangeSlug = preg_replace('/[^0-9\-]/', '_', $group['range'] ?? (string) $index);
            $filename = sprintf("%s_part_%02d_pages_%s.pdf", $prefix, $index, $rangeSlug);
            $outPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

            $this->extractPages($sourcePath, $outPath, $pages);

            $results[] = [
                'path'       => $outPath,
                'name'       => $filename,
                'pages'      => $pages,
                'size_bytes' => (int) filesize($outPath),
            ];

            $index++;
        }

        return $results;
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
