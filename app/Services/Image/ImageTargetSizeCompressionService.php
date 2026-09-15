<?php

namespace App\Services\Image;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use RuntimeException;

class ImageTargetSizeCompressionService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Compress an image targeting a specific maximum file size in bytes using an iterative strategy.
     *
     * @param string $sourcePath Path to input image file
     * @param string $outputPath Path to save final compressed image
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
     *     quality_used: int,
     *     dimensions: array{width: int, height: int},
     *     original_dimensions: array{width: int, height: int},
     *     dimension_changed: bool,
     *     explanation: ?string,
     *     engine: string,
     *     engine_label: string,
     *     mime_type: string,
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
            throw new InvalidArgumentException("Source image not found: {$sourcePath}");
        }

        $originalSize = (int) filesize($sourcePath);
        if ($targetSizeBytes <= 0) {
            throw new InvalidArgumentException("Target size must be greater than zero.");
        }

        $sourceImage = $this->manager->read($sourcePath);
        $origW = $sourceImage->width();
        $origH = $sourceImage->height();

        // 1. If original file is already within target, keep original without degradation
        if ($originalSize <= $targetSizeBytes) {
            copy($sourcePath, $outputPath);

            return [
                'output_path'         => $outputPath,
                'original_size'       => $originalSize,
                'target_size'         => $targetSizeBytes,
                'processed_size'      => $originalSize,
                'saved_bytes'         => 0,
                'savings_percent'     => 0.0,
                'target_status'       => 'achieved',
                'target_achieved'     => true,
                'iterations'          => 0,
                'quality_used'        => 100,
                'dimensions'          => ['width' => $origW, 'height' => $origH],
                'original_dimensions' => ['width' => $origW, 'height' => $origH],
                'dimension_changed'   => false,
                'explanation'         => null,
                'engine'              => 'gd',
                'engine_label'        => __('ui.engine_image_gd'),
                'mime_type'           => mime_content_type($sourcePath) ?: 'image/jpeg',
                'diagnostics'         => [
                    'original_bytes' => $originalSize,
                    'target_bytes'   => $targetSizeBytes,
                    'stop_reason'    => 'already_within_target',
                    'attempts'       => [],
                ],
            ];
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($extension === 'jpg') {
            $extension = 'jpeg';
        }

        $tempBase = dirname($outputPath) . DIRECTORY_SEPARATOR . 'target_img_' . uniqid('', true);
        $attempts = [];
        $attemptPaths = [];

        $bestAttemptPath = null;
        $bestAttemptSize = PHP_INT_MAX;
        $bestAttemptQuality = 80;
        $bestAttemptWidth = $origW;
        $bestAttemptHeight = $origH;
        $bestMime = 'image/jpeg';
        $targetAchieved = false;
        $stopReason = 'exhausted_limits';

        // Iteration parameters based on priority
        $config = $this->getPriorityConfig($priority, $extension);
        $qualitySteps = $config['quality_steps'];
        $scaleSteps = $config['scale_steps'];

        $attemptNumber = 0;

        try {
            // STEP A: Progressive Quality Reduction first (preserving 100% dimensions)
            foreach ($qualitySteps as $q) {
                $attemptNumber++;
                $curPath = "{$tempBase}_{$attemptNumber}.{$extension}";
                $attemptPaths[] = $curPath;

                $img = $this->manager->read($sourcePath);
                $encoded = $this->encodeImage($img, $extension, $q, false);
                $encoded->save($curPath);

                $outBytes = (int) filesize($curPath);

                $attempts[] = [
                    'attempt'      => $attemptNumber,
                    'phase'        => 'quality_reduction',
                    'width'        => $origW,
                    'height'       => $origH,
                    'format'       => $extension,
                    'quality'      => $q,
                    'scale'        => 1.0,
                    'output_bytes' => $outBytes,
                    'target_bytes' => $targetSizeBytes,
                ];

                if ($outBytes < $bestAttemptSize) {
                    $bestAttemptPath = $curPath;
                    $bestAttemptSize = $outBytes;
                    $bestAttemptQuality = $q;
                    $bestAttemptWidth = $origW;
                    $bestAttemptHeight = $origH;
                    $bestMime = $encoded->mediaType();
                }

                if ($outBytes <= $targetSizeBytes) {
                    $targetAchieved = true;
                    $stopReason = 'target_achieved_quality_only';
                    break;
                }
            }

            // STEP B: If target not reached with quality alone, progressively downscale dimensions
            if (!$targetAchieved) {
                $minQuality = end($qualitySteps);

                foreach ($scaleSteps as $scale) {
                    $attemptNumber++;
                    $curPath = "{$tempBase}_{$attemptNumber}.{$extension}";
                    $attemptPaths[] = $curPath;

                    $img = $this->manager->read($sourcePath);
                    $targetW = max(50, (int) round($origW * $scale));
                    $targetH = max(50, (int) round($origH * $scale));
                    $img->scaleDown(width: $targetW, height: $targetH);

                    // For PNG, try indexed if aggressive
                    $useIndexed = ($extension === 'png' && $scale < 0.7);
                    $encoded = $this->encodeImage($img, $extension, $minQuality, $useIndexed);
                    $encoded->save($curPath);

                    $outBytes = (int) filesize($curPath);

                    $attempts[] = [
                        'attempt'      => $attemptNumber,
                        'phase'        => 'dimension_downscaling',
                        'width'        => $img->width(),
                        'height'       => $img->height(),
                        'format'       => $extension,
                        'quality'      => $minQuality,
                        'scale'        => $scale,
                        'output_bytes' => $outBytes,
                        'target_bytes' => $targetSizeBytes,
                    ];

                    if ($outBytes < $bestAttemptSize) {
                        $bestAttemptPath = $curPath;
                        $bestAttemptSize = $outBytes;
                        $bestAttemptQuality = $minQuality;
                        $bestAttemptWidth = $img->width();
                        $bestAttemptHeight = $img->height();
                        $bestMime = $encoded->mediaType();
                    }

                    if ($outBytes <= $targetSizeBytes) {
                        $targetAchieved = true;
                        $stopReason = 'target_achieved_with_scaling';
                        break;
                    }
                }
            }

            // Move the best attempt to output path
            if ($bestAttemptPath && file_exists($bestAttemptPath)) {
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
                copy($bestAttemptPath, $outputPath);
            } else {
                copy($sourcePath, $outputPath);
                $bestAttemptSize = $originalSize;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException("Target size image compression failed: {$e->getMessage()}", 0, $e);
        } finally {
            // Clean up all temporary iteration files
            foreach ($attemptPaths as $p) {
                if (file_exists($p)) {
                    @unlink($p);
                }
            }
        }

        $finalSize = (int) filesize($outputPath);
        // Ensure processed_size is never claimed as smaller than reality
        if ($finalSize >= $originalSize) {
            copy($sourcePath, $outputPath);
            $finalSize = $originalSize;
        }

        $actualAchieved = ($finalSize <= $targetSizeBytes);
        $savedBytes = max(0, $originalSize - $finalSize);
        $savingsPercent = $originalSize > 0
            ? round((($originalSize - $finalSize) / $originalSize) * 100, 1)
            : 0.0;

        $explanation = null;
        if (!$actualAchieved) {
            $formattedTarget = $this->formatBytes($targetSizeBytes);
            $formattedBest = $this->formatBytes($finalSize);
            $explanation = "Target size of {$formattedTarget} could not be fully achieved within safe quality and dimension limits. Returning the closest possible result ({$formattedBest}).";
        }

        $diagnostics = [
            'original_bytes'   => $originalSize,
            'target_bytes'     => $targetSizeBytes,
            'final_bytes'      => $finalSize,
            'target_achieved'  => $actualAchieved,
            'stop_reason'      => $stopReason,
            'total_iterations' => $attemptNumber,
            'attempts'         => $attempts,
        ];

        Log::info("ImageTargetSizeCompression completed", [
            'original' => $originalSize,
            'target'   => $targetSizeBytes,
            'final'    => $finalSize,
            'achieved' => $actualAchieved,
            'attempts' => $attemptNumber,
            'reason'   => $stopReason,
        ]);

        return [
            'output_path'         => $outputPath,
            'original_size'       => $originalSize,
            'target_size'         => $targetSizeBytes,
            'processed_size'      => $finalSize,
            'saved_bytes'         => $savedBytes,
            'savings_percent'     => max(0.0, $savingsPercent),
            'target_status'       => $actualAchieved ? 'achieved' : 'not_achieved',
            'target_achieved'     => $actualAchieved,
            'iterations'          => $attemptNumber,
            'quality_used'        => $bestAttemptQuality,
            'dimensions'          => ['width' => $bestAttemptWidth, 'height' => $bestAttemptHeight],
            'original_dimensions' => ['width' => $origW, 'height' => $origH],
            'dimension_changed'   => ($bestAttemptWidth !== $origW || $bestAttemptHeight !== $origH),
            'explanation'         => $explanation,
            'engine'              => 'gd',
            'engine_label'        => __('ui.engine_image_gd'),
            'mime_type'           => $bestMime,
            'diagnostics'         => $diagnostics,
        ];
    }

    /**
     * Encode image according to format and quality.
     */
    protected function encodeImage($image, string $extension, int $quality, bool $indexed = false)
    {
        return match ($extension) {
            'png'   => $indexed ? $image->toPng(indexed: true) : $image->toPng(),
            'webp'  => $image->toWebp(quality: $quality),
            'gif'   => $image->toGif(),
            'bmp'   => $image->toBitmap(),
            default => $image->toJpeg(quality: $quality),
        };
    }

    /**
     * Get quality and scale configuration based on priority and format.
     *
     * @return array{quality_steps: array<int>, scale_steps: array<float>}
     */
    protected function getPriorityConfig(string $priority, string $extension): array
    {
        if ($extension === 'png') {
            // PNG in GD is primarily compressed via dimension scaling & palette reduction
            return match ($priority) {
                'max_quality' => [
                    'quality_steps' => [95, 85],
                    'scale_steps'   => [0.90, 0.80, 0.70, 0.60, 0.50],
                ],
                'smallest' => [
                    'quality_steps' => [80, 65],
                    'scale_steps'   => [0.85, 0.70, 0.55, 0.40, 0.30, 0.20],
                ],
                default => [ // balanced
                    'quality_steps' => [90, 75],
                    'scale_steps'   => [0.90, 0.80, 0.70, 0.55, 0.45, 0.35],
                ],
            };
        }

        return match ($priority) {
            'max_quality' => [
                'quality_steps' => [90, 80, 70, 60, 50, 40],
                'scale_steps'   => [0.90, 0.80, 0.70, 0.60, 0.50],
            ],
            'smallest' => [
                'quality_steps' => [80, 65, 50, 35, 20, 15],
                'scale_steps'   => [0.85, 0.70, 0.55, 0.40, 0.30, 0.20],
            ],
            default => [ // balanced
                'quality_steps' => [85, 75, 65, 50, 35, 25],
                'scale_steps'   => [0.90, 0.80, 0.70, 0.55, 0.45, 0.35],
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
