<?php

namespace App\Services\Image;

use App\Enums\ImageFit;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use RuntimeException;

class ImageResizeService
{
    protected ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Resize an image using GD driver.
     *
     * @param string $sourcePath Absolute path to source image
     * @param string $outputPath Absolute path to save resized image
     * @param int|null $targetWidth In pixels
     * @param int|null $targetHeight In pixels
     * @param ImageFit $fit Resizing mode: CONTAIN, COVER, or FILL
     * @param int $quality Compression quality (1-100)
     * @param string $bgColor Background color for padding (default '#ffffff')
     * @return array{
     *     output_path: string,
     *     width: int,
     *     height: int,
     *     original_size: int,
     *     processed_size: int,
     *     mime_type: string
     * }
     */
    public function resize(
        string $sourcePath,
        string $outputPath,
        ?int $targetWidth = null,
        ?int $targetHeight = null,
        ImageFit $fit = ImageFit::CONTAIN,
        int $quality = 85,
        string $bgColor = '#ffffff'
    ): array {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source image does not exist: {$sourcePath}");
        }

        if ($targetWidth === null && $targetHeight === null) {
            throw new InvalidArgumentException("At least one dimension (width or height) must be specified.");
        }

        $originalSize = (int) filesize($sourcePath);

        try {
            $image = $this->manager->read($sourcePath);

            switch ($fit) {
                case ImageFit::CONTAIN:
                    if ($targetWidth && $targetHeight) {
                        $image->pad(width: $targetWidth, height: $targetHeight, background: $bgColor);
                    } else {
                        $image->scale(width: $targetWidth, height: $targetHeight);
                    }
                    break;

                case ImageFit::COVER:
                    $image->cover(width: (int) $targetWidth, height: (int) $targetHeight);
                    break;

                case ImageFit::FILL:
                    $image->resize(width: $targetWidth, height: $targetHeight);
                    break;
            }

            $extension = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));

            $encoded = match ($extension) {
                'png' => $image->toPng(),
                'webp' => $image->toWebp(quality: $quality),
                'gif' => $image->toGif(),
                'bmp' => $image->toBitmap(),
                default => $image->toJpeg(quality: $quality),
            };

            $encoded->save($outputPath);

            return [
                'output_path'    => $outputPath,
                'width'          => $image->width(),
                'height'         => $image->height(),
                'original_size'  => $originalSize,
                'processed_size' => (int) filesize($outputPath),
                'mime_type'      => $encoded->mediaType(),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("Image resize failed: {$e->getMessage()}", 0, $e);
        }
    }
}
