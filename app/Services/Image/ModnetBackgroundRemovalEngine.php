<?php

namespace App\Services\Image;

use App\Services\File\FileStorageManager;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ModnetBackgroundRemovalEngine implements BackgroundRemovalEngineInterface
{
    public const EXPECTED_SHA256 = '07c308cf0fc7e6e8b2065a12ed7fc07e1de8febb7dc7839d7b7f15dd66584df9';

    public function __construct(
        protected SubprocessExecutorService $subprocess,
        protected SystemBinaryDetector $binaryDetector,
        protected ?FileStorageManager $storageManager = null
    ) {}

    public function getId(): string
    {
        return 'ml_modnet';
    }

    public function getLabel(): string
    {
        return 'AI Portrait Matting (MODNet)';
    }

    public function getDescription(): string
    {
        return 'Photographic portrait matting using local MODNet ONNX neural network';
    }

    public function isAi(): bool
    {
        return true;
    }

    /**
     * Check whether the Node runtime, runner script, and verified model are available.
     */
    public function isAvailable(): bool
    {
        if (!$this->binaryDetector->hasNode()) {
            return false;
        }

        $runnerPath = $this->getRunnerPath();
        if (!file_exists($runnerPath)) {
            return false;
        }

        $modelPath = $this->getModelPath();
        if (!file_exists($modelPath)) {
            return false;
        }

        // Verify exact hash integrity
        $hash = strtolower(hash_file('sha256', $modelPath));
        return hash_equals(self::EXPECTED_SHA256, $hash);
    }

    /**
     * Remove the background using the local MODNet ONNX runner.
     */
    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array
    {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Source image does not exist: {$inputPath}");
        }

        if (!$this->isAvailable()) {
            throw new RuntimeException("MODNet engine is not available (Node.js missing or model unverified).");
        }

        $info = @getimagesize($inputPath);
        if (!$info) {
            throw new InvalidArgumentException("Unable to determine image dimensions for: {$inputPath}");
        }

        $srcWidth = $info[0];
        $srcHeight = $info[1];

        // Safe temporary file for mask output
        $tempDir = $this->storageManager
            ? $this->storageManager->getSubprocessTempDirectory()
            : sys_get_temp_dir();

        $tempMaskPath = $tempDir . DIRECTORY_SEPARATOR . 'modnet_mask_' . uniqid('', true) . '.png';

        try {
            $nodePath = $this->binaryDetector->getNodePath();
            $runnerPath = $this->getRunnerPath();
            $modelDir = dirname(dirname($this->getModelPath()));

            $command = [
                $nodePath,
                $runnerPath,
                '--input',
                $inputPath,
                '--output',
                $tempMaskPath,
                '--model-dir',
                $modelDir,
            ];

            $timeout = (int) config('fileprocessor.timeouts.cli_process_seconds', 30);
            $result = $this->subprocess->execute($command, $timeout);

            $outputJson = json_decode(trim($result['output']), true);
            if (!$outputJson || empty($outputJson['success'])) {
                $err = $outputJson['error'] ?? ($result['error_output'] ?: 'Unknown runner error');
                throw new RuntimeException("MODNet runner failed: {$err}");
            }

            if (!file_exists($tempMaskPath) || filesize($tempMaskPath) === 0) {
                throw new RuntimeException("MODNet runner did not produce a valid mask file.");
            }

            // Sanity check mask dimensions and alpha coverage
            $this->validateMaskQuality($tempMaskPath, $srcWidth, $srcHeight);

            // Composite transparent subject
            $this->compositeTransparentOutput($inputPath, $tempMaskPath, $outputPath, $srcWidth, $srcHeight);

            return [
                'engine'       => $this->getId(),
                'engine_label' => $this->getLabel(),
                'is_ai'        => true,
                'width'        => $srcWidth,
                'height'       => $srcHeight,
                'format'       => 'png',
                'inference_ms' => $outputJson['inference_ms'] ?? null,
                'load_ms'      => $outputJson['load_ms'] ?? null,
                'size_bytes'   => file_exists($outputPath) ? filesize($outputPath) : 0,
            ];
        } finally {
            if (file_exists($tempMaskPath)) {
                @unlink($tempMaskPath);
            }
        }
    }

    /**
     * Validate that the generated mask meets production quality criteria:
     * - Dimensions match original image exactly.
     * - Alpha coverage is within realistic bounds (not totally empty or solid).
     */
    protected function validateMaskQuality(string $maskPath, int $expectedWidth, int $expectedHeight): void
    {
        $maskInfo = @getimagesize($maskPath);
        if (!$maskInfo || $maskInfo[0] !== $expectedWidth || $maskInfo[1] !== $expectedHeight) {
            throw new RuntimeException("MODNet mask dimensions mismatch: expected {$expectedWidth}x{$expectedHeight}, got {$maskInfo[0]}x{$maskInfo[1]}");
        }

        $maskImg = @imagecreatefrompng($maskPath);
        if (!$maskImg) {
            throw new RuntimeException("Failed to decode MODNet output mask PNG.");
        }

        try {
            $totalSamples = 0;
            $fgSamples = 0;

            // Sample grid of points across mask
            $stepX = max(1, (int) round($expectedWidth / 80));
            $stepY = max(1, (int) round($expectedHeight / 80));

            for ($y = 0; $y < $expectedHeight; $y += $stepY) {
                for ($x = 0; $x < $expectedWidth; $x += $stepX) {
                    $totalSamples++;
                    $val = imagecolorat($maskImg, $x, $y) & 0xFF;
                    if ($val > 30) {
                        $fgSamples++;
                    }
                }
            }

            $coverage = $fgSamples / max(1, $totalSamples);

            // If less than 4% foreground or greater than 96% foreground, reject as invalid matte
            if ($coverage < 0.04) {
                throw new RuntimeException(sprintf("MODNet mask rejected: foreground coverage too low (%.2f%%). Likely non-human subject.", $coverage * 100));
            }

            if ($coverage > 0.96) {
                throw new RuntimeException(sprintf("MODNet mask rejected: background not separated (coverage %.2f%%).", $coverage * 100));
            }
        } finally {
            imagedestroy($maskImg);
        }
    }

    /**
     * Composite transparent PNG using source RGB and MODNet grayscale matte as alpha.
     */
    protected function compositeTransparentOutput(
        string $inputPath,
        string $maskPath,
        string $outputPath,
        int $width,
        int $height
    ): void {
        $info = getimagesize($inputPath);
        $src = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($inputPath),
            'image/png'  => @imagecreatefrompng($inputPath),
            'image/webp' => @imagecreatefromwebp($inputPath),
            default      => throw new InvalidArgumentException("Unsupported image format: {$info['mime']}"),
        };

        if (!$src) {
            throw new RuntimeException("Failed to load source image for compositing: {$inputPath}");
        }

        $mask = @imagecreatefrompng($maskPath);
        if (!$mask) {
            imagedestroy($src);
            throw new RuntimeException("Failed to load mask image for compositing: {$maskPath}");
        }

        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        try {
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $rgb = imagecolorat($src, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    $m = imagecolorat($mask, $x, $y) & 0xFF;
                    // In GD: 0 is completely opaque, 127 is completely transparent
                    $gdAlpha = (int) round((1.0 - ($m / 255.0)) * 127.0);

                    $pixelColor = imagecolorallocatealpha($canvas, $r, $g, $b, $gdAlpha);
                    imagesetpixel($canvas, $x, $y, $pixelColor);
                }
            }

            $outDir = dirname($outputPath);
            if (!is_dir($outDir)) {
                mkdir($outDir, 0755, true);
            }

            imagepng($canvas, $outputPath, 8);
        } finally {
            imagedestroy($src);
            imagedestroy($mask);
            imagedestroy($canvas);
        }
    }

    protected function getRunnerPath(): string
    {
        return base_path('bin/modnet_runner.mjs');
    }

    protected function getModelPath(): string
    {
        return storage_path('app/models/modnet/onnx/model.onnx');
    }
}
