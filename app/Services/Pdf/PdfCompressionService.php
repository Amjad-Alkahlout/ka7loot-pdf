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
     *     duration_ms: int,
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
     * Deep compression via Ghostscript with distinct preset parameter sets.
     */
    protected function compressWithGhostscript(
        string $sourcePath,
        string $outputPath,
        CompressionLevel $level,
        int $originalSize
    ): array {
        $startTime = microtime(true);
        $gsBinary = $this->detector->getGhostscriptPath();

        // Specific, differentiated Ghostscript parameters per preset
        $presetFlags = match ($level) {
            CompressionLevel::LOW => [
                '-dPDFSETTINGS=/printer',
                '-dColorImageResolution=300',
                '-dGrayImageResolution=300',
                '-dMonoImageResolution=600',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dColorImageDownsampleType=/Bicubic',
            ],
            CompressionLevel::EXTREME => [
                '-dPDFSETTINGS=/screen',
                '-dColorImageResolution=72',
                '-dGrayImageResolution=72',
                '-dMonoImageResolution=150',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dDownsampleMonoImages=true',
                '-dColorImageDownsampleType=/Bicubic',
                '-dConvertCMYKImagesToRGB=true',
            ],
            default => [ // RECOMMENDED
                '-dPDFSETTINGS=/ebook',
                '-dColorImageResolution=150',
                '-dGrayImageResolution=150',
                '-dMonoImageResolution=300',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dDownsampleMonoImages=true',
                '-dColorImageDownsampleType=/Bicubic',
            ],
        };

        $cmd = array_merge([
            $gsBinary,
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.4',
            '-dNOPAUSE',
            '-dQUIET',
            '-dBATCH',
            '-dDetectDuplicateImages=true',
            '-dCompressFonts=true',
            '-dSubsetFonts=true',
            '-dAutoRotatePages=/None',
            '-sOutputFile=' . $outputPath,
        ], $presetFlags, [$sourcePath]);

        try {
            $this->executor->execute($cmd);
        } catch (\Throwable $e) {
            throw new RuntimeException("Ghostscript deep compression failed: {$e->getMessage()}", 0, $e);
        }

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $processedSize = (int) filesize($outputPath);
        $notice = null;

        // If compressed file is larger than or equal to original (e.g. text only or pre-compressed),
        // revert to original and explain honestly rather than claiming savings
        if ($processedSize >= $originalSize) {
            copy($sourcePath, $outputPath);
            $processedSize = $originalSize;
            $notice = 'The PDF document was already highly compressed. Original file preserved to prevent size inflation.';
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
            'duration_ms'     => $durationMs,
            'engine'          => 'ghostscript',
            'engine_label'    => 'Deep compression (Ghostscript)',
            'is_deep'         => true,
            'notice'          => $notice,
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
        $startTime = microtime(true);
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

        $durationMs = (int) round((microtime(true) - $startTime) * 1000);
        $processedSize = (int) filesize($outputPath);
        $notice = 'Ghostscript is required for deep compression and significant file size reduction.';

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
            'duration_ms'     => $durationMs,
            'engine'          => 'pure_php',
            'engine_label'    => 'Limited optimization (Pure PHP)',
            'is_deep'         => false,
            'notice'          => $notice,
        ];
    }
}
