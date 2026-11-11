<?php

namespace App\Services\Pdf;

use App\Enums\PageOrientation;
use App\Enums\PageSize;
use App\Services\Image\HeicConverterService;
use RuntimeException;

class HeicToPdfService
{
    public function __construct(
        protected HeicConverterService $converter,
        protected JpgToPdfService $jpgToPdf,
    ) {}

    /**
     * @param array<string> $heicPaths
     * @return array{output_path: string, total_pages: int, size_bytes: int}
     */
    public function convertFilesToPdf(
        array $heicPaths,
        string $outputPath,
        PageSize $pageSize,
        PageOrientation $orientation,
        float $margin,
        string $tempDirectory,
    ): array {
        if (!$heicPaths) {
            throw new RuntimeException('At least one HEIC image is required.');
        }

        if (!is_dir($tempDirectory) && !mkdir($tempDirectory, 0755, true) && !is_dir($tempDirectory)) {
            throw new RuntimeException('Unable to create the HEIC conversion directory.');
        }

        $jpegPaths = [];

        try {
            foreach ($heicPaths as $index => $heicPath) {
                $jpegPath = $tempDirectory . DIRECTORY_SEPARATOR . 'converted_' . $index . '.jpg';
                $this->converter->convertToJpeg($heicPath, $jpegPath);
                $jpegPaths[] = $jpegPath;
            }

            return $this->jpgToPdf->convertImagesToPdf($jpegPaths, $outputPath, $pageSize, $orientation, $margin);
        } finally {
            foreach ($jpegPaths as $jpegPath) {
                if (is_file($jpegPath)) {
                    @unlink($jpegPath);
                }
            }
        }
    }
}
