<?php

namespace App\Services\Image;

use InvalidArgumentException;
use RuntimeException;

class ImageBackgroundService
{
    public function __construct(
        protected BackgroundRemovalEngineInterface $engine
    ) {}

    public function getEngine(): BackgroundRemovalEngineInterface
    {
        return $this->engine;
    }

    /**
     * Remove the background from an image.
     */
    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array
    {
        return $this->engine->removeBackground($inputPath, $outputPath, $options);
    }

    /**
     * Remove background and composite onto a chosen replacement background.
     *
     * @param string $inputPath Path to original subject image
     * @param string $outputPath Path to save final result
     * @param array $backgroundConfig Configuration for the replacement background:
     *   - type: 'transparent' | 'solid' | 'gradient' | 'image'
     *   - color: string hex (e.g. '#FFFFFF') for 'solid'
     *   - color_start: string hex for 'gradient'
     *   - color_end: string hex for 'gradient'
     *   - direction: 'vertical' | 'horizontal' | 'diagonal' for 'gradient'
     *   - image_path: string absolute path for 'image'
     *   - fit: 'cover' | 'contain' | 'stretch' for 'image'
     *   - fill_color: string hex (optional, for letterbox in 'contain' mode)
     * @param array $options Engine tuning options for removal (tolerance, feather, etc.)
     * @return array Metadata about the operation
     */
    public function changeBackground(
        string $inputPath,
        string $outputPath,
        array $backgroundConfig,
        array $options = []
    ): array {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Input image file not found: {$inputPath}");
        }

        $bgType = $backgroundConfig['type'] ?? 'solid';

        // Fast path: if type is transparent, just remove background directly to outputPath
        if ($bgType === 'transparent') {
            $res = $this->engine->removeBackground($inputPath, $outputPath, $options);
            $res['background_type'] = 'transparent';
            return $res;
        }

        // 1. Generate transparent foreground subject in isolated temp file
        $tempSubjectPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bg_subject_' . uniqid('', true) . '.png';
        try {
            $removalRes = $this->engine->removeBackground($inputPath, $tempSubjectPath, $options);
            $width = $removalRes['width'];
            $height = $removalRes['height'];

            // 2. Render replacement background canvas
            $canvas = imagecreatetruecolor($width, $height);

            switch ($bgType) {
                case 'solid':
                    $hex = $backgroundConfig['color'] ?? '#FFFFFF';
                    $rgb = $this->hexToRgb($hex);
                    $color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
                    imagefilledrectangle($canvas, 0, 0, $width, $height, $color);
                    break;

                case 'gradient':
                    $startHex = $backgroundConfig['color_start'] ?? '#4F46E5';
                    $endHex = $backgroundConfig['color_end'] ?? '#06B6D4';
                    $direction = $backgroundConfig['direction'] ?? 'vertical';
                    $this->renderGradient($canvas, $width, $height, $startHex, $endHex, $direction);
                    break;

                case 'image':
                    $bgImgPath = $backgroundConfig['image_path'] ?? '';
                    $fit = $backgroundConfig['fit'] ?? 'cover';
                    $fillColor = $backgroundConfig['fill_color'] ?? '#FFFFFF';
                    $this->renderBackgroundImage($canvas, $width, $height, $bgImgPath, $fit, $fillColor);
                    break;

                default:
                    throw new InvalidArgumentException("Unsupported background replacement type: {$bgType}");
            }

            // 3. Composite transparent subject on top of canvas
            $subjectImg = @imagecreatefrompng($tempSubjectPath);
            if (!$subjectImg) {
                throw new RuntimeException("Failed to load transparent subject for compositing.");
            }

            imagealphablending($canvas, true);
            imagecopy($canvas, $subjectImg, 0, 0, 0, 0, $width, $height);
            imagedestroy($subjectImg);

            // 4. Save output
            $outDir = dirname($outputPath);
            if (!is_dir($outDir)) {
                mkdir($outDir, 0755, true);
            }

            $ext = strtolower(pathinfo($outputPath, PATHINFO_EXTENSION));
            if ($ext === 'jpg' || $ext === 'jpeg') {
                imagejpeg($canvas, $outputPath, 92);
            } else {
                // Default to PNG
                imagepng($canvas, $outputPath, 8);
            }
            imagedestroy($canvas);

            return [
                'engine'          => $removalRes['engine'] ?? $this->engine->getId(),
                'engine_label'    => $removalRes['engine_label'] ?? $this->engine->getLabel(),
                'is_ai'           => $removalRes['is_ai'] ?? $this->engine->isAi(),
                'background_type' => $bgType,
                'width'           => $width,
                'height'          => $height,
                'format'          => $ext ?: 'png',
                'size_bytes'      => file_exists($outputPath) ? filesize($outputPath) : 0,
            ];
        } finally {
            if (file_exists($tempSubjectPath)) {
                @unlink($tempSubjectPath);
            }
        }
    }

    /**
     * Generate a base64 Data URI preview image for instant interactive feedback in UI.
     */
    public function generatePreview(
        string $inputPath,
        array $backgroundConfig,
        int $maxDimension = 600,
        array $options = []
    ): string {
        $tempPreview = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bg_prev_' . uniqid('', true) . '.png';
        try {
            $this->changeBackground($inputPath, $tempPreview, $backgroundConfig, $options);

            // Downscale for web preview if larger than maxDimension
            $src = imagecreatefrompng($tempPreview);
            if (!$src) {
                return '';
            }

            $w = imagesx($src);
            $h = imagesy($src);

            if ($w > $maxDimension || $h > $maxDimension) {
                $scale = min($maxDimension / $w, $maxDimension / $h);
                $nw = (int) round($w * $scale);
                $nh = (int) round($h * $scale);

                $downscaled = imagecreatetruecolor($nw, $nh);
                imagealphablending($downscaled, false);
                imagesavealpha($downscaled, true);
                imagecopyresampled($downscaled, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($src);

                ob_start();
                imagepng($downscaled, null, 7);
                $raw = ob_get_clean();
                imagedestroy($downscaled);
            } else {
                ob_start();
                imagepng($src, null, 7);
                $raw = ob_get_clean();
                imagedestroy($src);
            }

            return 'data:image/png;base64,' . base64_encode($raw);
        } finally {
            if (file_exists($tempPreview)) {
                @unlink($tempPreview);
            }
        }
    }

    /**
     * Render a smooth linear gradient across the canvas.
     */
    protected function renderGradient(
        $canvas,
        int $width,
        int $height,
        string $startHex,
        string $endHex,
        string $direction
    ): void {
        $c1 = $this->hexToRgb($startHex);
        $c2 = $this->hexToRgb($endHex);

        if ($direction === 'horizontal') {
            for ($x = 0; $x < $width; $x++) {
                $t = $width > 1 ? ($x / ($width - 1)) : 0.0;
                $r = (int) round($c1[0] + $t * ($c2[0] - $c1[0]));
                $g = (int) round($c1[1] + $t * ($c2[1] - $c1[1]));
                $b = (int) round($c1[2] + $t * ($c2[2] - $c1[2]));
                $color = imagecolorallocate($canvas, $r, $g, $b);
                imageline($canvas, $x, 0, $x, $height - 1, $color);
            }
        } elseif ($direction === 'diagonal') {
            $maxCoord = ($width - 1) + ($height - 1);
            for ($y = 0; $y < $height; $y++) {
                for ($x = 0; $x < $width; $x++) {
                    $t = $maxCoord > 0 ? (($x + $y) / $maxCoord) : 0.0;
                    $r = (int) round($c1[0] + $t * ($c2[0] - $c1[0]));
                    $g = (int) round($c1[1] + $t * ($c2[1] - $c1[1]));
                    $b = (int) round($c1[2] + $t * ($c2[2] - $c1[2]));
                    $color = imagecolorallocate($canvas, $r, $g, $b);
                    imagesetpixel($canvas, $x, $y, $color);
                }
            }
        } else {
            // Default: vertical
            for ($y = 0; $y < $height; $y++) {
                $t = $height > 1 ? ($y / ($height - 1)) : 0.0;
                $r = (int) round($c1[0] + $t * ($c2[0] - $c1[0]));
                $g = (int) round($c1[1] + $t * ($c2[1] - $c1[1]));
                $b = (int) round($c1[2] + $t * ($c2[2] - $c1[2]));
                $color = imagecolorallocate($canvas, $r, $g, $b);
                imageline($canvas, 0, $y, $width - 1, $y, $color);
            }
        }
    }

    /**
     * Render replacement background image with cover/contain/stretch fit modes.
     */
    protected function renderBackgroundImage(
        $canvas,
        int $width,
        int $height,
        string $bgImgPath,
        string $fit,
        string $fillColorHex
    ): void {
        if (!file_exists($bgImgPath)) {
            throw new InvalidArgumentException("Replacement background image not found: {$bgImgPath}");
        }

        $info = @getimagesize($bgImgPath);
        if (!$info) {
            throw new InvalidArgumentException("Invalid background image file: {$bgImgPath}");
        }

        $bgSrc = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($bgImgPath),
            'image/png'  => @imagecreatefrompng($bgImgPath),
            'image/webp' => @imagecreatefromwebp($bgImgPath),
            default      => throw new InvalidArgumentException("Unsupported background image format: {$info['mime']}"),
        };

        if (!$bgSrc) {
            throw new RuntimeException("Failed to decode background image: {$bgImgPath}");
        }

        try {
            $sw = $info[0];
            $sh = $info[1];

            // Fill canvas with fill color first
            $fillRgb = $this->hexToRgb($fillColorHex);
            $fillCol = imagecolorallocate($canvas, $fillRgb[0], $fillRgb[1], $fillRgb[2]);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $fillCol);

            if ($fit === 'contain') {
                // Scale to fit inside canvas with padding
                $scale = min($width / $sw, $height / $sh);
                $dw = (int) round($sw * $scale);
                $dh = (int) round($sh * $scale);
                $dx = (int) round(($width - $dw) / 2);
                $dy = (int) round(($height - $dh) / 2);

                imagecopyresampled($canvas, $bgSrc, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
            } elseif ($fit === 'stretch') {
                // Direct stretch to canvas dimensions
                imagecopyresampled($canvas, $bgSrc, 0, 0, 0, 0, $width, $height, $sw, $sh);
            } else {
                // Default: cover (scale to fill, center and crop)
                $scale = max($width / $sw, $height / $sh);
                $dw = (int) round($sw * $scale);
                $dh = (int) round($sh * $scale);
                $dx = (int) round(($width - $dw) / 2);
                $dy = (int) round(($height - $dh) / 2);

                imagecopyresampled($canvas, $bgSrc, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
            }
        } finally {
            imagedestroy($bgSrc);
        }
    }

    protected function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6) {
            return [255, 255, 255];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
