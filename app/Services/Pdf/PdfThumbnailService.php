<?php

namespace App\Services\Pdf;

use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Facades\Log;
use Throwable;

class PdfThumbnailService
{
    public function __construct(
        protected SystemBinaryDetector $detector,
        protected SubprocessExecutorService $executor
    ) {}

    /**
     * Generate low-resolution page thumbnails for a PDF document.
     *
     * @param string $sourcePath Path to the PDF file
     * @param int $maxPages Maximum number of pages to generate thumbnails for
     * @param int $scale Maximum bounding dimension in pixels (default: 300)
     * @return array<int, string> Map of 1-indexed page number to base64 data URI string
     */
    public function generateThumbnails(string $sourcePath, int $maxPages = 10, int $scale = 300): array
    {
        if (!file_exists($sourcePath) || !$this->detector->hasPoppler()) {
            return [];
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_thumb_' . uniqid('', true);
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true)) {
            return [];
        }

        $thumbnails = [];

        try {
            $popplerPath = $this->detector->getPopplerPath();
            $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'thumb';

            // pdftoppm -jpeg -scale-to <scale> -f 1 -l <maxPages> <sourcePath> <prefix>
            $cmd = [
                $popplerPath,
                '-jpeg',
                '-scale-to',
                (string) max(100, min(800, $scale)),
                '-f',
                '1',
                '-l',
                (string) max(1, $maxPages),
                $sourcePath,
                $prefix,
            ];

            $this->executor->execute($cmd);

            // Read generated JPEG files
            $files = glob($tmpDir . DIRECTORY_SEPARATOR . 'thumb-*.jpg') ?: [];
            sort($files, SORT_NATURAL);

            foreach ($files as $idx => $file) {
                $pageNumber = $idx + 1;
                $content = file_get_contents($file);
                if ($content !== false) {
                    $thumbnails[$pageNumber] = 'data:image/jpeg;base64,' . base64_encode($content);
                }
            }
        } catch (Throwable $e) {
            Log::warning("PdfThumbnailService: thumbnail generation failed: " . $e->getMessage());
        } finally {
            // Clean up temporary files
            $remaining = glob($tmpDir . DIRECTORY_SEPARATOR . '*');
            if ($remaining) {
                foreach ($remaining as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            @rmdir($tmpDir);
        }

        return $thumbnails;
    }

    /**
     * Generate a high-resolution, crisp preview image for a specific page of a PDF document.
     *
     * @param string $sourcePath Path to the PDF file
     * @param int $page 1-indexed page number
     * @param int $dpiOrScale If <= 300, treated as DPI resolution (e.g. 150 DPI). If > 300, treated as pixel scale bounding box.
     * @return string|null Base64 data URI string or null on failure
     */
    public function generatePagePreview(string $sourcePath, int $page = 1, int $dpiOrScale = 150): ?string
    {
        if (!file_exists($sourcePath) || !$this->detector->hasPoppler()) {
            return null;
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_prev_' . uniqid('', true);
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true)) {
            return null;
        }

        try {
            $popplerPath = $this->detector->getPopplerPath();
            $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';

            if ($dpiOrScale <= 300) {
                $dpi = max(72, $dpiOrScale);
                $cmd = [
                    $popplerPath,
                    '-jpeg',
                    '-jpegopt',
                    'quality=90',
                    '-r',
                    (string) $dpi,
                    '-f',
                    (string) $page,
                    '-l',
                    (string) $page,
                    $sourcePath,
                    $prefix,
                ];
            } else {
                $scale = max(800, min(2400, $dpiOrScale));
                $cmd = [
                    $popplerPath,
                    '-jpeg',
                    '-jpegopt',
                    'quality=90',
                    '-scale-to',
                    (string) $scale,
                    '-f',
                    (string) $page,
                    '-l',
                    (string) $page,
                    $sourcePath,
                    $prefix,
                ];
            }

            $this->executor->execute($cmd);

            $files = glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.jpg') ?: [];
            if (!empty($files)) {
                $content = file_get_contents($files[0]);
                if ($content !== false) {
                    return 'data:image/jpeg;base64,' . base64_encode($content);
                }
            }
        } catch (Throwable $e) {
            Log::warning("PdfThumbnailService: preview failed for page {$page}: " . $e->getMessage());
        } finally {
            $remaining = glob($tmpDir . DIRECTORY_SEPARATOR . '*');
            if ($remaining) {
                foreach ($remaining as $f) {
                    if (is_file($f)) {
                        @unlink($f);
                    }
                }
            }
            @rmdir($tmpDir);
        }

        return null;
    }
}
