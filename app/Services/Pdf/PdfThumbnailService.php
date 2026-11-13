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

    /**
     * Render a specific page of a PDF document directly to an output file on disk.
     *
     * @param string $sourcePath Path to the PDF file
     * @param string $outputPath Absolute target file path to write JPEG image
     * @param int $page 1-indexed page number
     * @param int $dpiOrScale If <= 300, treated as DPI resolution. If > 300, treated as max pixel scale.
     * @param int $quality JPEG quality (10-100, default: 85)
     * @return bool True if successfully generated, false otherwise
     */
    public function renderPageToFile(
        string $sourcePath,
        string $outputPath,
        int $page = 1,
        int $dpiOrScale = 150,
        int $quality = 85
    ): bool {
        $sourcePath = realpath($sourcePath) ?: str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $sourcePath);
        $outputPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $outputPath);

        if (!file_exists($sourcePath) || !$this->detector->hasPoppler()) {
            return false;
        }

        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            return false;
        }

        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_rend_' . uniqid('', true);
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true)) {
            return false;
        }

        try {
            $popplerPath = $this->detector->getPopplerPath();
            $prefix = $tmpDir . DIRECTORY_SEPARATOR . 'page';
            $quality = max(10, min(100, $quality));

            $popplerInputPath = $sourcePath;
            if (strlen($sourcePath) > 180 || str_contains($sourcePath, '-meta')) {
                $shortCopy = $tmpDir . DIRECTORY_SEPARATOR . 'src_' . uniqid() . '.pdf';
                if (@copy($sourcePath, $shortCopy)) {
                    $popplerInputPath = $shortCopy;
                }
            }

            if ($dpiOrScale <= 300) {
                $dpi = max(50, $dpiOrScale);
                $cmd = [
                    $popplerPath,
                    '-jpeg',
                    '-jpegopt',
                    "quality={$quality}",
                    '-r',
                    (string) $dpi,
                    '-f',
                    (string) $page,
                    '-l',
                    (string) $page,
                    $popplerInputPath,
                    $prefix,
                ];
            } else {
                $scale = max(200, min(2400, $dpiOrScale));
                $cmd = [
                    $popplerPath,
                    '-jpeg',
                    '-jpegopt',
                    "quality={$quality}",
                    '-scale-to',
                    (string) $scale,
                    '-f',
                    (string) $page,
                    '-l',
                    (string) $page,
                    $popplerInputPath,
                    $prefix,
                ];
            }

            $this->executor->execute($cmd);

            $files = glob($tmpDir . DIRECTORY_SEPARATOR . 'page-*.jpg') ?: [];
            if (!empty($files) && file_exists($files[0]) && filesize($files[0]) > 0) {
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
                return copy($files[0], $outputPath);
            }

            return false;
        } catch (Throwable $e) {
            Log::warning("PdfThumbnailService: renderPageToFile failed for page {$page}: " . $e->getMessage());
            return false;
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
    }

    /**
     * Get the dedicated cache directory for a given PDF source file path or identifier.
     *
     * @param string $sourcePath
     * @return string
     */
    public function getPreviewCacheDirectory(string $sourcePath): string
    {
        $normalizedPath = @realpath($sourcePath) ?: $sourcePath;
        $sourceKey = md5($normalizedPath);
        return storage_path('app' . DIRECTORY_SEPARATOR . 'pdf-preview-cache' . DIRECTORY_SEPARATOR . $sourceKey);
    }

    /**
     * Get the deterministic cached preview file path for a specific page, resolution, quality, and document content.
     * Uses full SHA-256 hash of the document content to guarantee detection of any byte-level modifications.
     *
     * @param string $sourcePath
     * @param int $page
     * @param int $dpiOrScale
     * @param int $quality
     * @return string
     */
    public function getCachedPreviewPath(
        string $sourcePath,
        int $page = 1,
        int $dpiOrScale = 150,
        int $quality = 85,
        ?string $contentHash = null
    ): string {
        $dir = $this->getPreviewCacheDirectory($sourcePath);
        $contentKey = '0';

        if ($contentHash !== null) {
            $contentKey = $contentHash === '0' ? '0' : substr($contentHash, 0, 32);
        } elseif (file_exists($sourcePath)) {
            $sha256 = @hash_file('sha256', $sourcePath);
            $contentKey = $sha256 ? substr($sha256, 0, 32) : '0';
        }

        return $dir . DIRECTORY_SEPARATOR . "p{$page}_d{$dpiOrScale}_q{$quality}_{$contentKey}.jpg";
    }

    /**
     * Delete all cached preview files for a given PDF file.
     *
     * @param string $sourcePath Path to the PDF file or identifier
     */
    public function deleteThumbnailsForPdf(string $sourcePath): void
    {
        $dir = $this->getPreviewCacheDirectory($sourcePath);
        $this->deleteDirectoryRecursively($dir);
    }

    /**
     * Recursively delete a directory and its contents.
     */
    protected function deleteDirectoryRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            } elseif (is_dir($file)) {
                $this->deleteDirectoryRecursively($file);
            }
        }
        @rmdir($dir);
    }
}
