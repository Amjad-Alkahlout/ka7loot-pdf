<?php

namespace App\Services\Image;

use App\Services\File\FileStorageManager;
use App\Services\Image\Matting\DecontaminationMattingService;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use InvalidArgumentException;
use RuntimeException;
use SplFixedArray;

class U2NetBackgroundRemovalEngine implements BackgroundRemovalEngineInterface
{
    public const ID = 'ml_u2net';
    public const LABEL = 'AI Graphic Matting (U-2-Net)';
    public const DESCRIPTION = 'General salient object detection using local U-2-Net ONNX combined with classical topological hole extraction for logos and graphics.';
    public const EXPECTED_SHA256 = '8D10D2F3BB75AE3B6D527C77944FC5E7DCD94B29809D47A739A7A728A912B491';

    public function __construct(
        protected SubprocessExecutorService $subprocess,
        protected SystemBinaryDetector $binaryDetector,
        protected FileStorageManager $storageManager,
        protected DecontaminationMattingService $mattingService
    ) {}

    public function getId(): string
    {
        return self::ID;
    }

    public function getLabel(): string
    {
        return self::LABEL;
    }

    public function getDescription(): string
    {
        return self::DESCRIPTION;
    }

    public function isAi(): bool
    {
        return true;
    }

    public function isAvailable(): bool
    {
        if (!$this->binaryDetector->hasNode()) {
            return false;
        }

        $runnerPath = base_path('bin/u2net_runner.mjs');
        if (!file_exists($runnerPath)) {
            return false;
        }

        $modelPath = config('fileprocessor.models.u2net', storage_path('app/models/u2net/u2net.onnx'));
        if (!file_exists($modelPath)) {
            return false;
        }

        $hash = strtolower(hash_file('sha256', $modelPath));
        return hash_equals(strtolower(self::EXPECTED_SHA256), $hash);
    }

    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array
    {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Source image does not exist: {$inputPath}");
        }

        if (!$this->isAvailable()) {
            throw new RuntimeException("U-2-Net engine is not available (Node.js missing or model unverified).");
        }

        $imageInfo = @getimagesize($inputPath);
        if (!$imageInfo) {
            throw new InvalidArgumentException("Could not inspect image dimensions: {$inputPath}");
        }

        $w = $imageInfo[0];
        $h = $imageInfo[1];
        $mime = $imageInfo['mime'];

        if ($w <= 0 || $h <= 0) {
            throw new InvalidArgumentException("Invalid image dimensions: {$w}x{$h}");
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($inputPath),
            'image/png'  => @imagecreatefrompng($inputPath),
            'image/webp' => @imagecreatefromwebp($inputPath),
            default      => throw new InvalidArgumentException("Unsupported image format: {$mime}"),
        };

        if (!$src) {
            throw new RuntimeException("Failed to decode source image for AI matting.");
        }

        $t0 = microtime(true);
        $out = null;

        try {
            // 1. Obtain coarse macro masking via ONNX
            $aiFg = $this->generateAiMask($inputPath, $w, $h);

            // 2. Compute classical parameters for original-resolution topology extraction
            $bgSamples = $this->mattingService->collectAdaptiveBackgroundSamples($src, $w, $h, null);
            $tolerance = isset($options['tolerance']) ? max(5.0, min(80.0, (float) $options['tolerance'])) : 25.0;
            $feather = isset($options['feather']) ? max(0, min(8, (int) $options['feather'])) : 2;
            $edgeMap = $this->mattingService->computeSobelEdgeMap($src, $w, $h);

            // 3. Classical segmentation (with neutral protected mask and internal hole seeds) to extract precise background geometry
            $emptyProtectedMask = new SplFixedArray($w * $h);
            $bgMask = $this->mattingService->segmentBackground(
                $src,
                $w,
                $h,
                $bgSamples,
                $edgeMap,
                $emptyProtectedMask,
                $tolerance,
                15.0,
                true
            );

            // 4. Mathematical Intersection (AI defines outer macro-boundaries; Classical defines pure micro-holes)
            $fgMask = $this->mattingService->intersectAiWithTopology(
                $aiFg,
                $bgMask,
                $w,
                $h,
                $edgeMap,
                $src,
                $bgSamples,
                $tolerance
            );

            // 5. Final RGB edge decontamination matting
            $out = $this->mattingService->renderOutputWithMatting(
                $src,
                $fgMask,
                $w,
                $h,
                $bgSamples,
                $tolerance,
                $feather,
                1.0
            );

            $outDir = dirname($outputPath);
            if (!is_dir($outDir)) {
                mkdir($outDir, 0755, true);
            }
            imagepng($out, $outputPath, 8);

            return [
                'engine'       => $this->getId(),
                'engine_label' => $this->getLabel(),
                'is_ai'        => true,
                'width'        => $w,
                'height'       => $h,
                'format'       => 'png',
                'inference_ms' => round((microtime(true) - $t0) * 1000),
                'size_bytes'   => file_exists($outputPath) ? filesize($outputPath) : 0,
            ];
        } finally {
            if ($src) {
                imagedestroy($src);
            }
            if ($out) {
                imagedestroy($out);
            }
        }
    }

    public function generateAiMask(string $inputPath, int $srcW, int $srcH): SplFixedArray
    {
        $raw = @file_get_contents($inputPath);
        if (!$raw) {
            throw new RuntimeException("Could not read image for AI mask: {$inputPath}");
        }
        $img = @imagecreatefromstring($raw);
        if (!$img) {
            throw new RuntimeException("Failed to decode image data for AI mask: {$inputPath}");
        }

        $thumb = imagecreatetruecolor(320, 320);
        imagecopyresampled($thumb, $img, 0, 0, 0, 0, 320, 320, $srcW, $srcH);

        $mean = [0.485, 0.456, 0.406];
        $std = [0.229, 0.224, 0.225];

        $binaryData = '';
        for ($c = 0; $c < 3; $c++) {
            for ($y = 0; $y < 320; $y++) {
                for ($x = 0; $x < 320; $x++) {
                    $rgb = imagecolorat($thumb, $x, $y);
                    $val = match($c) {
                        0 => ($rgb >> 16) & 0xFF,
                        1 => ($rgb >> 8) & 0xFF,
                        2 => $rgb & 0xFF,
                    };
                    $norm = ($val / 255.0 - $mean[$c]) / $std[$c];
                    $binaryData .= pack('f', $norm);
                }
            }
        }
        imagedestroy($thumb);
        imagedestroy($img);

        $tmpIn = $this->storageManager->getSubprocessTempDirectory() . DIRECTORY_SEPARATOR . 'uin_' . uniqid() . '.bin';
        $tmpOut = $this->storageManager->getSubprocessTempDirectory() . DIRECTORY_SEPARATOR . 'uout_' . uniqid() . '.bin';
        file_put_contents($tmpIn, $binaryData);

        $runnerPath = base_path('bin/u2net_runner.mjs');
        $modelPath = config('fileprocessor.models.u2net', storage_path('app/models/u2net/u2net.onnx'));

        $cmd = [
            $this->binaryDetector->getNodePath(),
            $runnerPath,
            $modelPath,
            $tmpIn,
            $tmpOut
        ];

        try {
            $timeout = (int) config('fileprocessor.timeouts.cli_process_seconds', 30);
            $result = $this->subprocess->execute($cmd, $timeout);

            $json = json_decode(trim($result['output']), true);
            if (!$json || empty($json['success'])) {
                $err = $json['error'] ?? ($result['error_output'] ?: 'Unknown runner error');
                throw new RuntimeException("U-2-Net runner failed: {$err}");
            }

            if (!file_exists($tmpOut) || filesize($tmpOut) !== (320 * 320 * 4)) {
                throw new RuntimeException("U-2-Net runner did not produce a valid float32 mask tensor.");
            }

            $outBinary = file_get_contents($tmpOut);
            $outFloats = unpack('f*', $outBinary); // 1-indexed

            $maskThumb = imagecreatetruecolor(320, 320);
            $max = max($outFloats);
            $min = min($outFloats);
            if ($max - $min == 0) $max = $min + 1;

            $idx = 1;
            for ($y = 0; $y < 320; $y++) {
                for ($x = 0; $x < 320; $x++) {
                    $val = ($outFloats[$idx++] - $min) / ($max - $min);
                    $gray = (int)round($val * 255);
                    $color = imagecolorallocate($maskThumb, $gray, $gray, $gray);
                    imagesetpixel($maskThumb, $x, $y, $color);
                }
            }

            $maskFull = imagecreatetruecolor($srcW, $srcH);
            imagecopyresampled($maskFull, $maskThumb, 0, 0, 0, 0, $srcW, $srcH, 320, 320);

            $aiMask = new SplFixedArray($srcW * $srcH);
            for ($y = 0; $y < $srcH; $y++) {
                for ($x = 0; $x < $srcW; $x++) {
                    $color = imagecolorat($maskFull, $x, $y) & 0xFF; // Grayscale
                    $aiMask[$y * $srcW + $x] = ($color > 127) ? 1 : 0;
                }
            }

            imagedestroy($maskThumb);
            imagedestroy($maskFull);

            return $aiMask;

        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }
}
