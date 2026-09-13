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
     * Compress an image file using GD driver.
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
     *     height: int
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

            // Optional dimension downscaling
            if ($maxWidth !== null || $maxHeight !== null) {
                $image->scaleDown(width: $maxWidth, height: $maxHeight);
            }

            // Determine output format and encode
            $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));

            $encoded = match ($extension) {
                'png' => $image->toPng(),
                'webp' => $image->toWebp(quality: $qualityInt),
                'gif' => $image->toGif(),
                'bmp' => $image->toBitmap(),
                default => $image->toJpeg(quality: $qualityInt),
            };

            $encoded->save($outputPath);

            $processedSize = (int) filesize($outputPath);
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
                'mime_type'       => $encoded->mediaType(),
                'width'           => $image->width(),
                'height'          => $image->height(),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("Image compression failed: {$e->getMessage()}", 0, $e);
        }
    }
}
