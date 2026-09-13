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
            $installCmd = PHP_OS_FAMILY === 'Windows'
                ? 'choco install poppler -y'
                : 'sudo apt-get install poppler-utils';

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
        } catch (\Throwable $e) {
            throw new RuntimeException("Poppler rendering failed: {$e->getMessage()}", 0, $e);
        }

        // Scan for generated images
        $ext = ($cleanFormat === 'png') ? 'png' : 'jpg';
        $mime = ($cleanFormat === 'png') ? 'image/png' : 'image/jpeg';

        $pattern = $outputDir . DIRECTORY_SEPARATOR . $prefix . '-*.' . $ext;
        $found = glob($pattern);
        sort($found);

        $results = [];
        $pageNum = 1;
        foreach ($found as $filePath) {
            $results[] = [
                'path'      => $filePath,
                'name'      => basename($filePath),
                'page'      => $pageNum,
                'mime_type' => $mime,
            ];
            $pageNum++;
        }

        if (empty($results)) {
            throw new RuntimeException("Poppler completed execution but no image files were produced.");
        }

        return $results;
    }
}
