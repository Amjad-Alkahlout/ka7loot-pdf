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
        protected U2NetBackgroundRemovalEngine $u2netEngine,
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
        return 'Intelligently routes human portraits to MODNet, graphics/logos to U-2-Net, with deterministic classical fallbacks.';
    }

    public function isAi(): bool
    {
        return false;
    }

    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array
    {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Input image file not found: {$inputPath}");
        }

        $isPortrait = $this->isLikelyHumanPortrait($inputPath);

        // Tier 1: Local MODNet AI Matting (for Human Portraits)
        if ($isPortrait && $this->modnetEngine->isAvailable()) {
            try {
                $meta = $this->modnetEngine->removeBackground($inputPath, $outputPath, $options);
                $meta['detection'] = 'human_portrait';
                $meta['routing'] = 'modnet_portrait_ai';
                return $meta;
            } catch (Throwable $e) {
                Log::info("MODNet AI matting failed or rejected for [{$inputPath}]: {$e->getMessage()}. Falling back...");
            }
        }

        // Tier 2: Graphic/General Saliency AI Matting (for Graphics, Logos, Documents)
        if (!$isPortrait && $this->u2netEngine->isAvailable()) {
            try {
                $meta = $this->u2netEngine->removeBackground($inputPath, $outputPath, $options);
                $meta['detection'] = 'general_graphic';
                $meta['routing'] = 'u2net_graphic_ai';
                return $meta;
            } catch (Throwable $e) {
                Log::warning("U-2-Net AI matting failed for [{$inputPath}]: {$e->getMessage()}. Falling back to Advanced engine.");
            }
        }

        // Tier 3: Advanced Edge & Geodesic Matting (Fallback)
        try {
            $meta = $this->advancedEngine->removeBackground($inputPath, $outputPath, $options);
            $meta['detection'] = $isPortrait ? 'human_portrait_fallback' : 'general_object';
            $meta['routing'] = $isPortrait ? 'advanced_portrait_fallback' : 'advanced_general_object';
            return $meta;
        } catch (Throwable $e) {
            Log::warning("Advanced background removal failed for [{$inputPath}]: {$e->getMessage()}. Falling back to ColorSampling engine.");
        }

        // Tier 4: Deterministic ColorSampling final fallback
        $meta = $this->colorSamplingEngine->removeBackground($inputPath, $outputPath, $options);
        $meta['detection'] = $isPortrait ? 'portrait_colorsampling_fallback' : 'general_colorsampling_fallback';
        $meta['routing'] = 'color_sampling_final_fallback';
        return $meta;
    }

    public function isLikelyHumanPortrait(string $path): bool
    {
        if (!file_exists($path)) return false;
        $info = @getimagesize($path);
        if (!$info) return false;
        $w = $info[0];
        $h = $info[1];
        $ratio = $w / max(1, $h);
        if ($ratio < 0.35 || $ratio > 2.5) return false;
        $raw = @file_get_contents($path);
        if (!$raw) return false;
        $src = @imagecreatefromstring($raw);
        if (!$src) return false;
        $thumb = imagecreatetruecolor(64, 64);
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, 64, 64, $w, $h);
        imagedestroy($src);
        $upperSkin = 0; $upperTotal = 0;
        for ($y = 8; $y < 48; $y++) {
            for ($x = 14; $x < 50; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                $cb = -0.168736 * $r - 0.331264 * $g + 0.5 * $b + 128;
                $cr = 0.5 * $r - 0.418688 * $g - 0.081312 * $b + 128;
                $isSkin = ($cb >= 75 && $cb <= 135 && $cr >= 130 && $cr <= 180 && $r > $g && $g >= ($b - 5) && ($r - $g) >= 10);
                $upperTotal++;
                if ($isSkin) $upperSkin++;
            }
        }
        imagedestroy($thumb);
        $faceSkinRatio = $upperSkin / max(1, $upperTotal);
        return $faceSkinRatio >= 0.08;
    }
}
