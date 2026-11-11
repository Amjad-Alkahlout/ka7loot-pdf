<?php

namespace App\Services\Image;

use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use RuntimeException;

class HeicConverterService
{
    public function __construct(
        protected SubprocessExecutorService $subprocess,
        protected SystemBinaryDetector $binaryDetector,
    ) {}

    /**
     * Convert one HEIC/HEIF file to JPEG using the tracked Node worker.
     *
     * @return array{output_path: string, size_bytes: int}
     */
    public function convertToJpeg(string $inputPath, string $outputPath, int $quality = 92): array
    {
        if (!is_file($inputPath)) {
            throw new RuntimeException('HEIC input file does not exist.');
        }

        $this->binaryDetector->probe();
        $node = $this->binaryDetector->getNodePath();
        $worker = base_path('scripts' . DIRECTORY_SEPARATOR . 'heic-to-jpeg.mjs');

        if (!$node) {
            throw new RuntimeException('Node.js is required to convert HEIC images.');
        }
        if (!is_file($worker)) {
            throw new RuntimeException('The HEIC conversion worker is not installed.');
        }

        $this->subprocess->execute(
            [$node, $worker, $inputPath, $outputPath, (string) max(1, min(100, $quality))],
            (int) config('fileprocessor.timeouts.cli_process_seconds', 60),
        );

        if (!is_file($outputPath) || filesize($outputPath) === 0) {
            throw new RuntimeException('HEIC conversion produced no JPEG output.');
        }

        return [
            'output_path' => $outputPath,
            'size_bytes' => (int) filesize($outputPath),
        ];
    }
}
