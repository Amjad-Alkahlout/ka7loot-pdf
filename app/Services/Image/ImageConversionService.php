<?php

namespace App\Services\Image;

use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use RuntimeException;

class ImageConversionService
{
    protected ImageManager $manager;

    public const SUPPORTED_TARGET_FORMATS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'];

    public function __construct()
    {
        $this->manager = new ImageManager(new Driver());
    }

    /**
     * Convert an image from one format to another.
     *
     * @param string $sourcePath Absolute path to input image
     * @param string $outputPath Absolute path to output image
     * @param string $targetFormat 'jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'
     * @param int $quality Quality factor (1-100)
     * @param string $matteColor Background fill color if converting transparent image to JPG
     * @return array{
     *     output_path: string,
     *     format: string,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     processed_size: int
     * }
     */
    public function convert(
        string $sourcePath,
        string $outputPath,
        string $targetFormat,
        int $quality = 90,
        string $matteColor = '#ffffff'
    ): array {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source image does not exist: {$sourcePath}");
        }

        $fmt = strtolower(trim($targetFormat, '.'));
        if (!in_array($fmt, self::SUPPORTED_TARGET_FORMATS, true)) {
            throw new InvalidArgumentException("Unsupported target format: {$targetFormat}");
        }

        try {
            $image = $this->manager->read($sourcePath);

            $encoded = match ($fmt) {
                'png' => $image->toPng(),
                'webp' => $image->toWebp(quality: $quality),
                'gif' => $image->toGif(),
                'bmp' => $image->toBitmap(),
                default => $image->toJpeg(quality: $quality),
            };

            $encoded->save($outputPath);

            return [
                'output_path'    => $outputPath,
                'format'         => $fmt,
                'mime_type'      => $encoded->mediaType(),
                'width'          => $image->width(),
                'height'         => $image->height(),
                'processed_size' => (int) filesize($outputPath),
            ];
        } catch (\Throwable $e) {
            throw new RuntimeException("Image conversion failed: {$e->getMessage()}", 0, $e);
        }
    }
}
