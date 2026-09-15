<?php

namespace App\Services\Image;

use App\Enums\CompressionLevel;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use RuntimeException;

class ImageCompressionService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Compress an image file using GD driver with strict output measurement.
     *
     * @param string $sourcePath Absolute path to source image
     * @param string $outputPath Absolute path to save compressed image
     * @param CompressionLevel|int $quality Preset or integer between 10 and 100
     * @param int|null $maxWidth Optional maximum width in pixels
     * @param int|null $maxHeight Optional maximum height in pixels
     * @return array{
     *     output_path: string,
     *     original_size: int,
     *     processed_size: int,
     *     saved_bytes: int,
     *     savings_percent: float,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     original_width: int,
     *     original_height: int,
     *     engine: string,
     *     engine_label: string,
     *     notice: ?string
     * }
     */
    public function compress(
        string $sourcePath,
        string $outputPath,
        CompressionLevel|int $quality = CompressionLevel::RECOMMENDED,
        ?int $maxWidth = null,
        ?int $maxHeight = null
    ): array {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source image does not exist: {$sourcePath}");
        }

        $qualityInt = $quality instanceof CompressionLevel
            ? $quality->imageQuality()
            : max(10, min(100, $quality));

        $originalSize = (int) filesize($sourcePath);

        try {
            $image = $this->manager->read($sourcePath);
            $origWidth = $image->width();
            $origHeight = $image->height();

            // Optional dimension downscaling
            if ($maxWidth !== null || $maxHeight !== null) {
                $image->scaleDown(width: $maxWidth, height: $maxHeight);
            }

            $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
            if ($extension === 'jpg') {
                $extension = 'jpeg';
            }

            // Perform compression encoding based on format characteristics
            if ($extension === 'png') {
                // If aggressive compression requested (quality <= 50), try indexed 8-bit palette
                if ($qualityInt <= 50) {
                    $encoded = $image->toPng(indexed: true);
                } else {
                    $encoded = $image->toPng();
                }
            } elseif ($extension === 'webp') {
                $encoded = $image->toWebp(quality: $qualityInt);
            } elseif ($extension === 'gif') {
                $encoded = $image->toGif();
            } elseif ($extension === 'bmp') {
                $encoded = $image->toBitmap();
            } else {
                $encoded = $image->toJpeg(quality: $qualityInt);
            }

            $encoded->save($outputPath);

            $processedSize = (int) filesize($outputPath);
            $notice = null;

            // If the re-encoded image is larger than or equal to the original (e.g. already compressed),
            // attempt a secondary pass with lower quality or preserve the original to prevent inflation
            if ($processedSize >= $originalSize && $maxWidth === null && $maxHeight === null) {
                if ($extension === 'jpeg' || $extension === 'webp') {
                    $lowerQuality = max(20, $qualityInt - 25);
                    $retryEncoded = $extension === 'webp'
                        ? $image->toWebp(quality: $lowerQuality)
                        : $image->toJpeg(quality: $lowerQuality);

                    $retryTempPath = $outputPath . '.retry.tmp';
                    $retryEncoded->save($retryTempPath);
                    $retrySize = (int) filesize($retryTempPath);

                    if ($retrySize < $originalSize) {
                        @unlink($outputPath);
                        rename($retryTempPath, $outputPath);
                        $processedSize = $retrySize;
                    } else {
                        @unlink($retryTempPath);
                        // Preserve original file so user never gets an inflated file
                        copy($sourcePath, $outputPath);
                        $processedSize = $originalSize;
                        $notice = __('ui.compression_already_optimized', [], 'en') ?? 'Image was already highly compressed. Original file preserved to prevent size inflation.';
                    }
                } elseif ($extension === 'png' && $qualityInt > 50) {
                    // Try indexed palette
                    $indexedEncoded = $image->toPng(indexed: true);
                    $retryTempPath = $outputPath . '.retry.tmp';
                    $indexedEncoded->save($retryTempPath);
                    $retrySize = (int) filesize($retryTempPath);

                    if ($retrySize < $originalSize) {
                        @unlink($outputPath);
                        rename($retryTempPath, $outputPath);
                        $processedSize = $retrySize;
                    } else {
                        @unlink($retryTempPath);
                        copy($sourcePath, $outputPath);
                        $processedSize = $originalSize;
                        $notice = 'PNG was already highly compressed. Original file preserved to prevent size inflation.';
                    }
                } else {
                    copy($sourcePath, $outputPath);
                    $processedSize = $originalSize;
                    $notice = 'Image was already highly compressed. Original file preserved to prevent size inflation.';
                }
            }

            $savedBytes = max(0, $originalSize - $processedSize);
            $savingsPercent = $originalSize > 0
                ? round((($originalSize - $processedSize) / $originalSize) * 100, 1)
                : 0.0;

            return [
                'output_path'       => $outputPath,
                'original_size'     => $originalSize,
                'processed_size'    => $processedSize,
                'saved_bytes'       => $savedBytes,
                'savings_percent'   => max(0.0, $savingsPercent),
                'mime_type'         => $encoded->mediaType(),
                'width'             => $image->width(),
                'height'            => $image->height(),
                'original_width'    => $origWidth,
                'original_height'   => $origHeight,
                'engine'            => 'gd',
                'engine_label'      => __('ui.engine_image_gd'),
                'notice'            => $notice,
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("Image compression failed: {$e->getMessage()}", 0, $e);
        }
    }
}
