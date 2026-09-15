<?php

namespace App\Services\Pdf;

use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class PdfTargetSizeCompressionService
{
    public function __construct(
        protected SystemBinaryDetector $detector,
        protected SubprocessExecutorService $executor
    ) {}

    /**
     * Compress a PDF targeting a specific maximum file size in bytes using Ghostscript bounded iterations.
     *
     * @param string $sourcePath Path to input PDF file
     * @param string $outputPath Path to save final compressed PDF
     * @param int $targetSizeBytes Target maximum size in bytes
     * @param string $priority 'max_quality', 'balanced', or 'smallest'
     * @return array{
     *     output_path: string,
     *     original_size: int,
     *     target_size: int,
     *     processed_size: int,
     *     saved_bytes: int,
     *     savings_percent: float,
     *     target_status: string,
     *     target_achieved: bool,
     *     iterations: int,
     *     explanation: ?string,
     *     engine: string,
     *     engine_label: string,
     *     is_deep: bool,
     *     diagnostics: array
     * }
     */
    public function compressToTarget(
        string $sourcePath,
        string $outputPath,
        int $targetSizeBytes,
        string $priority = 'balanced'
    ): array {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source PDF not found: {$sourcePath}");
        }

        if (!$this->detector->hasGhostscript()) {
            throw new RuntimeException(__('tools.compress_pdf.ghostscript_required_target'));
        }

        $originalSize = (int) filesize($sourcePath);
        if ($targetSizeBytes <= 0) {
            throw new InvalidArgumentException("Target size must be greater than zero.");
        }

        // If original is already within target size, keep original without modification
        if ($originalSize <= $targetSizeBytes) {
            copy($sourcePath, $outputPath);

            return [
                'output_path'     => $outputPath,
                'original_size'   => $originalSize,
                'target_size'     => $targetSizeBytes,
                'processed_size'  => $originalSize,
                'saved_bytes'     => 0,
                'savings_percent' => 0.0,
                'target_status'   => 'achieved',
                'target_achieved' => true,
                'iterations'      => 0,
                'explanation'     => null,
                'engine'          => 'ghostscript',
                'engine_label'    => __('ui.engine_pdf_ghostscript'),
                'is_deep'         => true,
                'diagnostics'     => [
                    'original_bytes' => $originalSize,
                    'target_bytes'   => $targetSizeBytes,
                    'stop_reason'    => 'already_within_target',
                    'attempts'       => [],
                ],
            ];
        }

        $gsBinary = $this->detector->getGhostscriptPath();
        $profiles = $this->buildAttemptProfiles($priority);

        $tempBase = dirname($outputPath) . DIRECTORY_SEPARATOR . 'target_pdf_' . uniqid('', true);
        $attempts = [];
        $attemptPaths = [];

        $bestAttemptPath = null;
        $bestAttemptSize = PHP_INT_MAX;
        $selectedIteration = 0;
        $iterationCount = 0;
        $achieved = false;
        $stopReason = 'exhausted_profiles';

        try {
            foreach ($profiles as $profileName => $flags) {
                $iterationCount++;
                $attemptPath = "{$tempBase}_{$iterationCount}.pdf";
                $attemptPaths[] = $attemptPath;

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
                    '-sOutputFile=' . $attemptPath,
                ], $flags, [$sourcePath]);

                $this->executor->execute($cmd);

                if (file_exists($attemptPath)) {
                    $attemptSize = (int) filesize($attemptPath);
                    $targetDistance = $attemptSize - $targetSizeBytes;

                    $attempts[] = [
                        'iteration'       => $iterationCount,
                        'profile'         => $profileName,
                        'flags'           => $flags,
                        'output_bytes'    => $attemptSize,
                        'target_distance' => $targetDistance,
                    ];

                    // Track best (smallest valid output)
                    if ($attemptSize < $bestAttemptSize) {
                        $bestAttemptPath = $attemptPath;
                        $bestAttemptSize = $attemptSize;
                        $selectedIteration = $iterationCount;
                    }

                    // Check if target is achieved
                    if ($attemptSize <= $targetSizeBytes) {
                        $achieved = true;
                        $stopReason = 'target_achieved';
                        break;
                    }
                }
            }

            // If best attempt is smaller than original, copy to output
            if ($bestAttemptPath && file_exists($bestAttemptPath) && $bestAttemptSize < $originalSize) {
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
                copy($bestAttemptPath, $outputPath);
            } else {
                copy($sourcePath, $outputPath);
                $bestAttemptSize = $originalSize;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Target size PDF compression failed: {$e->getMessage()}", 0, $e);
        } finally {
            // Clean up temporary iteration files
            foreach ($attemptPaths as $p) {
                if (file_exists($p)) {
                    @unlink($p);
                }
            }
        }

        $finalSize = (int) filesize($outputPath);
        if ($finalSize >= $originalSize) {
            copy($sourcePath, $outputPath);
            $finalSize = $originalSize;
        }

        $actualAchieved = ($finalSize <= $targetSizeBytes);
        $savedBytes = max(0, $originalSize - $finalSize);
        $savingsPercent = $originalSize > 0
            ? round((($originalSize - $finalSize) / $originalSize) * 100, 1)
            : 0.0;

        // Generate truthful explanation if not achieved
        $explanation = null;
        if (!$actualAchieved) {
            $formattedTarget = $this->formatBytes($targetSizeBytes);
            $formattedBest = $this->formatBytes($finalSize);

            if (abs($finalSize - $originalSize) < ($originalSize * 0.02)) {
                $explanation = "Significant size reduction was not possible without excessive quality loss.";
            } else {
                $explanation = "Target size of {$formattedTarget} could not be achieved. Returning the closest possible result ({$formattedBest}). Further compression would exceed quality safeguards.";
            }
        }

        $diagnostics = [
            'original_bytes'     => $originalSize,
            'target_bytes'       => $targetSizeBytes,
            'final_bytes'        => $finalSize,
            'target_achieved'    => $actualAchieved,
            'stop_reason'        => $stopReason,
            'selected_iteration' => $selectedIteration,
            'total_iterations'   => $iterationCount,
            'attempts'           => $attempts,
        ];

        Log::info("PdfTargetSizeCompression completed", [
            'original' => $originalSize,
            'target'   => $targetSizeBytes,
            'final'    => $finalSize,
            'achieved' => $actualAchieved,
            'attempts' => $iterationCount,
            'reason'   => $stopReason,
        ]);

        return [
            'output_path'     => $outputPath,
            'original_size'   => $originalSize,
            'target_size'     => $targetSizeBytes,
            'processed_size'  => $finalSize,
            'saved_bytes'     => $savedBytes,
            'savings_percent' => max(0.0, $savingsPercent),
            'target_status'   => $actualAchieved ? 'achieved' : 'not_achieved',
            'target_achieved' => $actualAchieved,
            'iterations'      => $iterationCount,
            'explanation'     => $explanation,
            'engine'          => 'ghostscript',
            'engine_label'    => __('ui.engine_pdf_ghostscript'),
            'is_deep'         => true,
            'diagnostics'     => $diagnostics,
        ];
    }

    /**
     * Build progressive Ghostscript profiles from highest quality to most aggressive.
     *
     * @return array<string, array<string>>
     */
    protected function buildAttemptProfiles(string $priority): array
    {
        $allProfiles = [
            'printer_300' => [
                '-dPDFSETTINGS=/printer',
                '-dColorImageResolution=300',
                '-dGrayImageResolution=300',
            ],
            'ebook_150' => [
                '-dPDFSETTINGS=/ebook',
                '-dColorImageResolution=150',
                '-dGrayImageResolution=150',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
            ],
            'ebook_120' => [
                '-dPDFSETTINGS=/ebook',
                '-dColorImageResolution=120',
                '-dGrayImageResolution=120',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dColorImageDownsampleType=/Bicubic',
            ],
            'screen_96' => [
                '-dPDFSETTINGS=/screen',
                '-dColorImageResolution=96',
                '-dGrayImageResolution=96',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dColorImageDownsampleType=/Bicubic',
            ],
            'screen_72' => [
                '-dPDFSETTINGS=/screen',
                '-dColorImageResolution=72',
                '-dGrayImageResolution=72',
                '-dMonoImageResolution=150',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dColorImageDownsampleType=/Bicubic',
                '-dConvertCMYKImagesToRGB=true',
            ],
            'screen_50' => [
                '-dPDFSETTINGS=/screen',
                '-dColorImageResolution=50',
                '-dGrayImageResolution=50',
                '-dMonoImageResolution=72',
                '-dDownsampleColorImages=true',
                '-dDownsampleGrayImages=true',
                '-dColorImageDownsampleType=/Bicubic',
                '-dConvertCMYKImagesToRGB=true',
            ],
        ];

        return match ($priority) {
            'max_quality' => [
                'printer_300' => $allProfiles['printer_300'],
                'ebook_150'   => $allProfiles['ebook_150'],
                'ebook_120'   => $allProfiles['ebook_120'],
                'screen_96'   => $allProfiles['screen_96'],
            ],
            'smallest' => [
                'ebook_150'   => $allProfiles['ebook_150'],
                'screen_96'   => $allProfiles['screen_96'],
                'screen_72'   => $allProfiles['screen_72'],
                'screen_50'   => $allProfiles['screen_50'],
            ],
            default => [ // balanced
                'ebook_150'   => $allProfiles['ebook_150'],
                'ebook_120'   => $allProfiles['ebook_120'],
                'screen_96'   => $allProfiles['screen_96'],
                'screen_72'   => $allProfiles['screen_72'],
            ],
        };
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
