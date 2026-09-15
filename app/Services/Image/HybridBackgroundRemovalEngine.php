<?php

namespace App\Services\Image;

use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class HybridBackgroundRemovalEngine implements BackgroundRemovalEngineInterface
{
    public function __construct(
        protected ModnetBackgroundRemovalEngine $modnetEngine,
        protected AdvancedBackgroundRemovalEngine $advancedEngine,
        protected ColorSamplingBackgroundRemovalEngine $colorSamplingEngine,
        protected SystemBinaryDetector $binaryDetector
    ) {}

    public function getId(): string
    {
        return 'hybrid';
    }

    public function getLabel(): string
    {
        return 'Hybrid AI & Deterministic Matting';
    }

    public function getDescription(): string
    {
        return 'Intelligently routes human portraits to local AI neural matting and general objects to advanced edge matting with deterministic fallback.';
    }

    public function isAi(): bool
    {
        return false;
    }

    /**
     * Remove the background using the hybrid multi-tier routing architecture:
     * 1. Detect if image is likely a human portrait.
     * 2. If portrait and MODNet is available -> run MODNet.
     * 3. If MODNet fails, sanity check fails, or image is non-human -> run Advanced engine.
     * 4. If Advanced engine fails -> run ColorSampling engine.
     */
    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array
    {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Input image file not found: {$inputPath}");
        }

        $isPortrait = $this->isLikelyHumanPortrait($inputPath);

        // Tier 1: MODNet AI Matting (for Human Portraits)
        if ($isPortrait && $this->modnetEngine->isAvailable()) {
            try {
                $meta = $this->modnetEngine->removeBackground($inputPath, $outputPath, $options);
                $meta['detection'] = 'human_portrait';
                $meta['routing'] = 'modnet_portrait_ai';
                return $meta;
            } catch (Throwable $e) {
                Log::warning("MODNet AI matting failed or rejected for [{$inputPath}]: {$e->getMessage()}. Falling back to Advanced engine.");
            }
        }

        // Tier 2: Advanced Edge & Geodesic Matting (for general objects or MODNet fallback)
        try {
            $meta = $this->advancedEngine->removeBackground($inputPath, $outputPath, $options);
            $meta['detection'] = $isPortrait ? 'human_portrait_fallback' : 'general_object';
            $meta['routing'] = $isPortrait ? 'advanced_portrait_fallback' : 'advanced_general_object';
            return $meta;
        } catch (Throwable $e) {
            Log::warning("Advanced background removal failed for [{$inputPath}]: {$e->getMessage()}. Falling back to ColorSampling engine.");
        }

        // Tier 3: Deterministic ColorSampling fallback
        $meta = $this->colorSamplingEngine->removeBackground($inputPath, $outputPath, $options);
        $meta['detection'] = $isPortrait ? 'portrait_colorsampling_fallback' : 'general_colorsampling_fallback';
        $meta['routing'] = 'color_sampling_final_fallback';
        return $meta;
    }

    /**
     * Fast statistical heuristic to determine whether an image is likely a human portrait.
     * Samples a 64x64 thumbnail to analyze upper-center skin chrominance vs corner background.
     */
    public function isLikelyHumanPortrait(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $info = @getimagesize($path);
        if (!$info) {
            return false;
        }

        $w = $info[0];
        $h = $info[1];

        // Extreme aspect ratios (< 0.4 or > 2.5) are unlikely to be standard portraits
        $ratio = $w / max(1, $h);
        if ($ratio < 0.4 || $ratio > 2.2) {
            return false;
        }

        $raw = @file_get_contents($path);
        if (!$raw) {
            return false;
        }

        $src = @imagecreatefromstring($raw);
        if (!$src) {
            return false;
        }

        // Downsample to 64x64 for sub-millisecond statistical analysis
        $thumb = imagecreatetruecolor(64, 64);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, 64, 64, $w, $h);
        imagedestroy($src);

        $upperSkin = 0;
        $upperTotal = 0;
        $cornerSkin = 0;
        $cornerTotal = 0;

        for ($y = 0; $y < 64; $y++) {
            for ($x = 0; $x < 64; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // YCbCr skin chrominance space
                $cb = -0.168736 * $r - 0.331264 * $g + 0.5 * $b + 128;
                $cr = 0.5 * $r - 0.418688 * $g - 0.081312 * $b + 128;

                // Broad skin locus covering diverse global human tones
                $isSkin = ($cb >= 75 && $cb <= 135 && $cr >= 130 && $cr <= 180 && $r > $g && $g >= ($b - 5) && ($r - $g) >= 10);

                // Upper center region (x: 16-48, y: 10-42) - standard facial anatomy
                if ($x >= 16 && $x <= 48 && $y >= 10 && $y <= 42) {
                    $upperTotal++;
                    if ($isSkin) {
                        $upperSkin++;
                    }
                }

                // 4 Corners - background areas
                if (($x < 12 || $x > 52) && ($y < 12 || $y > 52)) {
                    $cornerTotal++;
                    if ($isSkin) {
                        $cornerSkin++;
                    }
                }
            }
        }
        imagedestroy($thumb);

        $faceSkinRatio = $upperSkin / max(1, $upperTotal);
        $cornerSkinRatio = $cornerSkin / max(1, $cornerTotal);

        // Portrait requires significant facial skin presence (>=10%) and minimal corner skin (<25%)
        return ($faceSkinRatio >= 0.10 && $cornerSkinRatio < 0.25);
    }
}
