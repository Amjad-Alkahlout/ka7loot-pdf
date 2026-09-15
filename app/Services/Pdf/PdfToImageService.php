<?php

namespace App\Services\Pdf;

use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use InvalidArgumentException;
use RuntimeException;

class PdfToImageService
{
    public function __construct(
        protected SystemBinaryDetector $detector,
        protected SubprocessExecutorService $executor
    ) {}

    /**
     * Get platform-appropriate installation recommendation for Poppler.
     */
    public static function getPopplerInstallCommand(?string $osFamily = null): string
    {
        $family = $osFamily ?? PHP_OS_FAMILY;

        return match ($family) {
            'Windows' => 'choco install poppler -y',
            'Darwin'  => 'brew install poppler',
            default   => 'sudo apt-get install poppler-utils',
        };
    }

    /**
     * Convert PDF pages into individual image files.
     *
     * @param string $sourcePath Absolute path to source PDF
     * @param string $outputDir Directory where generated images will be saved
     * @param string $format 'jpg' or 'png'
     * @param int $dpi Resolution in dots per inch (default 150)
     * @param string $prefix File prefix for output images
     * @return array<array{path: string, name: string, page: int, mime_type: string}>
     * @throws RuntimeException
     */
    public function convertPdfToImages(
        string $sourcePath,
        string $outputDir,
        string $format = 'jpg',
        int $dpi = 150,
        string $prefix = 'page'
    ): array {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        if (!$this->detector->hasPoppler()) {
            $installCmd = self::getPopplerInstallCommand();

            throw new RuntimeException(
                "PDF to Image rasterization requires the Poppler utility (pdftoppm). " .
                "It is currently not installed or not found on PATH. " .
                "Install it on this server by running: {$installCmd}"
            );
        }

        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $popplerPath = $this->detector->getPopplerPath();
        $cleanFormat = strtolower($format) === 'png' ? 'png' : 'jpeg';
        $formatFlag = ($cleanFormat === 'png') ? '-png' : '-jpeg';
        $outPrefix = $outputDir . DIRECTORY_SEPARATOR . $prefix;

        $cmd = [
            $popplerPath,
            $formatFlag,
            '-r',
            (string) max(72, min(600, $dpi)),
            $sourcePath,
            $outPrefix,
        ];

        try {
            $this->executor->execute($cmd);
        } catch (\Exception $e) {
            throw new RuntimeException("PDF to Image conversion failed during pdftoppm execution: {$e->getMessage()}", 0, $e);
        }

        // Gather and sort all generated page image files
        $pattern = $outPrefix . '-*.' . ($cleanFormat === 'png' ? 'png' : 'jpg');
        $files = glob($pattern);

        if (!$files) {
            // Also check for jpeg extension if jpg was not found
            if ($cleanFormat !== 'png') {
                $files = glob($outPrefix . '-*.jpeg') ?: [];
            } else {
                $files = [];
            }
        }

        natsort($files);

        $results = [];
        $pageNumber = 1;
        $mimeType = ($cleanFormat === 'png') ? 'image/png' : 'image/jpeg';

        foreach ($files as $filePath) {
            $results[] = [
                'path'      => $filePath,
                'name'      => basename($filePath),
                'page'      => $pageNumber++,
                'mime_type' => $mimeType,
            ];
        }

        if (empty($results)) {
            throw new RuntimeException("No images were produced from PDF rasterization.");
        }

        return $results;
    }
}
