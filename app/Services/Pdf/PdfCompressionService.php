<?php

namespace App\Services\Pdf;

use App\Enums\CompressionLevel;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use InvalidArgumentException;
use RuntimeException;

class PdfCompressionService
{
    public function __construct(
        protected SystemBinaryDetector $detector,
        protected SubprocessExecutorService $executor
    ) {}

    /**
     * Compress or optimize a PDF document.
     *
     * @param string $sourcePath Absolute path to input PDF
     * @param string $outputPath Absolute path to output PDF
     * @param CompressionLevel $level LOW, RECOMMENDED, or EXTREME
     * @return array{
     *     output_path: string,
     *     original_size: int,
     *     processed_size: int,
     *     saved_bytes: int,
     *     savings_percent: float,
     *     engine: string,
     *     engine_label: string,
     *     is_deep: bool,
     *     notice: ?string
     * }
     */
    public function compress(
        string $sourcePath,
        string $outputPath,
        CompressionLevel $level = CompressionLevel::RECOMMENDED
    ): array {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        $originalSize = (int) filesize($sourcePath);

        if ($this->detector->hasGhostscript()) {
            return $this->compressWithGhostscript($sourcePath, $outputPath, $level, $originalSize);
        }

        return $this->optimizeWithPurePhpFallback($sourcePath, $outputPath, $originalSize);
    }

    /**
     * Deep compression via Ghostscript.
     */
    protected function compressWithGhostscript(
        string $sourcePath,
        string $outputPath,
        CompressionLevel $level,
        int $originalSize
    ): array {
        $gsBinary = $this->detector->getGhostscriptPath();

        $cmd = [
            $gsBinary,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dPDFSETTINGS=' . $level->gsSetting(),
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-sOutputFile=' . $outputPath,
            $sourcePath,
        ];

        try {
            $this->executor->execute($cmd);
        } catch (\Throwable $e) {
            throw new RuntimeException("Ghostscript deep compression failed: {$e->getMessage()}", 0, $e);
        }

        $processedSize = (int) filesize($outputPath);

        // If compressed file is larger than original, copy original to preserve minimal size
        if ($processedSize >= $originalSize) {
            copy($sourcePath, $outputPath);
            $processedSize = $originalSize;
        }

        $savedBytes = max(0, $originalSize - $processedSize);
        $savingsPercent = $originalSize > 0
            ? round((($originalSize - $processedSize) / $originalSize) * 100, 1)
            : 0.0;

        return [
            'output_path'     => $outputPath,
            'original_size'   => $originalSize,
            'processed_size'  => $processedSize,
            'saved_bytes'     => $savedBytes,
            'savings_percent' => max(0.0, $savingsPercent),
            'engine'          => 'ghostscript',
            'engine_label'    => 'Deep compression (Ghostscript)',
            'is_deep'         => true,
            'notice'          => null,
        ];
    }

    /**
     * Limited optimization fallback using Pure PHP FPDI.
     */
    protected function optimizeWithPurePhpFallback(
        string $sourcePath,
        string $outputPath,
        int $originalSize
    ): array {
        try {
            $pdf = new RotatableFpdi();
            $pageCount = $pdf->setSourceFile($sourcePath);

            for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($templateId);
            }

            $pdf->Output($outputPath, 'F');
        } catch (\Throwable $e) {
            throw new RuntimeException("Pure PHP PDF optimization fallback failed: {$e->getMessage()}", 0, $e);
        }

        $processedSize = (int) filesize($outputPath);

        // Preserve smaller file if repack increased size
        if ($processedSize >= $originalSize) {
            copy($sourcePath, $outputPath);
            $processedSize = $originalSize;
        }

        $savedBytes = max(0, $originalSize - $processedSize);
        $savingsPercent = $originalSize > 0
            ? round((($originalSize - $processedSize) / $originalSize) * 100, 1)
            : 0.0;

        return [
            'output_path'     => $outputPath,
            'original_size'   => $originalSize,
            'processed_size'  => $processedSize,
            'saved_bytes'     => $savedBytes,
            'savings_percent' => max(0.0, $savingsPercent),
            'engine'          => 'pure_php',
            'engine_label'    => 'Limited optimization (Pure PHP)',
            'is_deep'         => false,
            'notice'          => 'Ghostscript is required for deep compression and significant file size reduction.',
        ];
    }
}
