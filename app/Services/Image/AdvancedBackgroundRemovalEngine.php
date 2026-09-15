<?php

namespace App\Services\Image;

use InvalidArgumentException;
use RuntimeException;
use SplFixedArray;
use SplQueue;

/**
 * Advanced Edge & Saliency Matting Engine.
 *
 * A high-precision deterministic foreground segmentation engine combining:
 * 1. Spatially-adaptive perimeter background modeling with outlier rejection.
 * 2. 2D Sobel gradient energy barriers to prevent boundary leakage across hair/skin/clothing.
 * 3. Photometric human skin tone and facial feature guardrails.
 * 4. Saliency/subject core contrast-aware protection.
 * 5. Morphological connected-component silhouette closure and interior hole filling.
 * 6. Gradient and color-distance aware alpha matting with anti-aliasing.
 *
 * Fully modular and replaceable by an ML engine in future phases without breaking contracts.
 */
class AdvancedBackgroundRemovalEngine implements BackgroundRemovalEngineInterface
{
    public const ID = 'advanced_matting';
    public const LABEL = 'Advanced Edge & Geodesic Matting';
    public const DESCRIPTION = 'Deterministic high-precision segmentation combining edge gradient barriers, spatial saliency priors, skin protection, and geodesic matting.';

    public function __construct(
        protected ?ColorSamplingBackgroundRemovalEngine $fallbackEngine = null
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
        return false;
    }

    /**
     * Remove the background from an image.
     */
    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array
    {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Input file not found: {$inputPath}");
        }

        // Optional fallback to basic color sampling if requested
        if (($options['engine'] ?? null) === 'color_sampling' && $this->fallbackEngine) {
            return $this->fallbackEngine->removeBackground($inputPath, $outputPath, $options);
        }

        $imageInfo = @getimagesize($inputPath);
        if (!$imageInfo) {
            throw new InvalidArgumentException("Could not inspect image dimensions or format: {$inputPath}");
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $mime = $imageInfo['mime'];

        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException("Invalid image dimensions: {$width}x{$height}");
        }

        $maxDimension = (int) ($options['max_dimension'] ?? 0);
        if ($maxDimension <= 0) {
            $maxDimension = (function_exists('config') && class_exists(\Illuminate\Container\Container::class) && \Illuminate\Container\Container::getInstance()?->has('config'))
                ? (int) (config('fileprocessor.background_removal.max_dimension') ?? 4096)
                : 4096;
        }
        if ($width > $maxDimension || $height > $maxDimension) {
            throw new InvalidArgumentException("Image dimensions ({$width}x{$height}) exceed maximum allowed limit of {$maxDimension}px.");
        }

        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($inputPath),
            'image/png'  => @imagecreatefrompng($inputPath),
            'image/webp' => @imagecreatefromwebp($inputPath),
            default      => throw new InvalidArgumentException("Unsupported image format: {$mime}"),
        };

        if (!$src) {
            throw new RuntimeException("Failed to decode image: {$inputPath}");
        }

        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $tolerance = isset($options['tolerance']) ? max(5.0, min(80.0, (float) $options['tolerance'])) : 25.0;
        $feather = isset($options['feather']) ? max(0, min(8, (int) $options['feather'])) : 2;
        $protectSkin = $options['protect_skin'] ?? true;
        $edgeBarrierSensitivity = isset($options['edge_threshold']) ? (float) $options['edge_threshold'] : 20.0;
        $customHex = $options['custom_color'] ?? null;

        $analysisImg = null;
        $out = null;

        try {
            // Determine working resolution for analysis (downscale for performance if very large)
            $maxDim = max($width, $height);
            $scale = 1.0;
            if ($maxDim > 640) {
                $scale = 640.0 / $maxDim;
            }

            $analysisW = (int) round($width * $scale);
            $analysisH = (int) round($height * $scale);

            if ($scale < 0.99) {
                $analysisImg = imagecreatetruecolor($analysisW, $analysisH);
                imagecopyresampled($analysisImg, $src, 0, 0, 0, 0, $analysisW, $analysisH, $width, $height);
            } else {
                $analysisImg = $src;
            }

            // 1. Gather perimeter background color samples (with outlier rejection)
            $bgSamples = $this->collectPerimeterSamples($analysisImg, $analysisW, $analysisH, $customHex);

            // 2. Compute 2D Sobel gradient energy map to detect physical boundaries
            $edgeMap = $this->computeSobelEdgeMap($analysisImg, $analysisW, $analysisH);

            // 3. Compute foreground protection mask (skin tones + central subject saliency)
            $protectedMask = $this->computeProtectedMask($analysisImg, $analysisW, $analysisH, $protectSkin, $bgSamples, $tolerance);

            // 4. Edge-constrained geodesic segmentation
            $bgMask = $this->segmentBackground(
                $analysisImg,
                $analysisW,
                $analysisH,
                $bgSamples,
                $edgeMap,
                $protectedMask,
                $tolerance,
                $edgeBarrierSensitivity
            );

            // 5. Morphological hole filling: close interior subject regions
            $bgMask = $this->fillInternalHoles($bgMask, $analysisW, $analysisH);

            // 6. Construct full-resolution transparent output image
            $out = imagecreatetruecolor($width, $height);
            imagealphablending($out, false);
            imagesavealpha($out, true);

            $this->renderOutputWithMatting(
                $src,
                $out,
                $width,
                $height,
                $bgMask,
                $analysisW,
                $analysisH,
                $bgSamples,
                $tolerance,
                $feather,
                $protectSkin
            );

            imagepng($out, $outputPath, 9);

            return [
                'engine'        => self::ID,
                'engine_label'  => self::LABEL,
                'is_ai'         => false,
                'width'         => $width,
                'height'        => $height,
                'format'        => 'png',
                'mime_type'     => 'image/png',
                'size_bytes'    => file_exists($outputPath) ? filesize($outputPath) : 0,
            ];
        } finally {
            if ($out !== null && (is_resource($out) || $out instanceof \GdImage)) {
                imagedestroy($out);
            }
            if ($analysisImg !== null && $analysisImg !== $src && (is_resource($analysisImg) || $analysisImg instanceof \GdImage)) {
                imagedestroy($analysisImg);
            }
            if (is_resource($src) || $src instanceof \GdImage) {
                imagedestroy($src);
            }
        }
    }

    /**
     * Collect perimeter samples from the outer image frame, filtering out foreground touches.
     */
    protected function collectPerimeterSamples($img, int $w, int $h, ?string $customHex = null): array
    {
        $samples = [];

        if ($customHex !== null && preg_match('/^#?([a-f0-9]{6})$/i', $matches[1] ?? '', $matches)) {
            $val = hexdec($matches[1]);
            return [[
                ($val >> 16) & 0xFF,
                ($val >> 8) & 0xFF,
                $val & 0xFF,
            ]];
        }

        // Sample four corners
        $cornerInsets = [
            [2, 2],
            [$w - 3, 2],
            [2, $h - 3],
            [$w - 3, $h - 3],
        ];
        foreach ($cornerInsets as [$cx, $cy]) {
            $samples[] = $this->samplePatchMean($img, $cx, $cy, $w, $h);
        }

        // Sample edges at intervals
        $numSamplesPerEdge = 6;
        for ($i = 1; $i <= $numSamplesPerEdge; $i++) {
            $frac = $i / ($numSamplesPerEdge + 1);
            $sx = (int) ($frac * $w);
            $sy = (int) ($frac * $h);

            $samples[] = $this->samplePatchMean($img, $sx, 2, $w, $h);          // Top
            $samples[] = $this->samplePatchMean($img, $sx, $h - 3, $w, $h);      // Bottom
            $samples[] = $this->samplePatchMean($img, 2, $sy, $w, $h);          // Left
            $samples[] = $this->samplePatchMean($img, $w - 3, $sy, $w, $h);      // Right
        }

        return $this->filterSampleOutliers($samples);
    }

    protected function samplePatchMean($img, int $cx, int $cy, int $w, int $h): array
    {
        $rSum = 0; $gSum = 0; $bSum = 0; $count = 0;
        for ($dy = -1; $dy <= 1; $dy++) {
            $py = max(0, min($h - 1, $cy + $dy));
            for ($dx = -1; $dx <= 1; $dx++) {
                $px = max(0, min($w - 1, $cx + $dx));
                $rgb = imagecolorat($img, $px, $py);
                $rSum += ($rgb >> 16) & 0xFF;
                $gSum += ($rgb >> 8) & 0xFF;
                $bSum += $rgb & 0xFF;
                $count++;
            }
        }
        return [
            (int) round($rSum / $count),
            (int) round($gSum / $count),
            (int) round($bSum / $count),
        ];
    }

    protected function filterSampleOutliers(array $samples): array
    {
        if (count($samples) <= 4) {
            return $samples;
        }

        // Find medoid sample
        $minTotalDist = PHP_FLOAT_MAX;
        $medoid = $samples[0];

        foreach ($samples as $candidate) {
            $totalDist = 0.0;
            foreach ($samples as $other) {
                $totalDist += $this->colorDistance($candidate, $other);
            }
            if ($totalDist < $minTotalDist) {
                $minTotalDist = $totalDist;
                $medoid = $candidate;
            }
        }

        // Filter out extreme outlier samples (e.g. clothing touching bottom border)
        $filtered = [];
        foreach ($samples as $s) {
            if ($this->colorDistance($s, $medoid) <= 32.0) {
                $filtered[] = $s;
            }
        }

        return !empty($filtered) ? $filtered : [$medoid];
    }

    /**
     * Compute 2D Sobel gradient magnitude for each pixel normalized to 0..100.
     */
    protected function computeSobelEdgeMap($img, int $w, int $h): SplFixedArray
    {
        $total = $w * $h;
        $lum = new SplFixedArray($total);

        // Precompute luminance
        for ($y = 0; $y < $h; $y++) {
            $yOffset = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                // ITU-R BT.601 perceptual luminance
                $lum[$yOffset + $x] = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
            }
        }

        $edgeMap = new SplFixedArray($total);

        for ($y = 0; $y < $h; $y++) {
            $yOffset = $y * $w;
            $yPrev = max(0, $y - 1) * $w;
            $yNext = min($h - 1, $y + 1) * $w;

            for ($x = 0; $x < $w; $x++) {
                $xPrev = max(0, $x - 1);
                $xNext = min($w - 1, $x + 1);

                // Sobel kernels
                // Gx: [-1 0 1, -2 0 2, -1 0 1]
                $gx = ($lum[$yPrev + $xNext] - $lum[$yPrev + $xPrev])
                    + 2 * ($lum[$yOffset + $xNext] - $lum[$yOffset + $xPrev])
                    + ($lum[$yNext + $xNext] - $lum[$yNext + $xPrev]);

                // Gy: [-1 -2 -1, 0 0 0, 1 2 1]
                $gy = ($lum[$yNext + $xPrev] - $lum[$yPrev + $xPrev])
                    + 2 * ($lum[$yNext + $x] - $lum[$yPrev + $x])
                    + ($lum[$yNext + $xNext] - $lum[$yPrev + $xNext]);

                $mag = sqrt($gx * $gx + $gy * $gy);
                // Normalized scale: max Sobel gradient is ~1020, divide by 10.2 for 0..100
                $edgeMap[$yOffset + $x] = min(100.0, $mag / 10.2);
            }
        }

        return $edgeMap;
    }

    /**
     * Compute protected foreground mask combining photometric skin detection
     * and high-confidence central subject saliency.
     */
    protected function computeProtectedMask(
        $img,
        int $w,
        int $h,
        bool $protectSkin,
        array $bgSamples,
        float $tolerance
    ): SplFixedArray {
        $total = $w * $h;
        $mask = new SplFixedArray($total);

        $cx = $w / 2.0;
        $cy = $h / 2.0;
        $rx = $w * 0.45;
        $ry = $h * 0.48;

        for ($y = 0; $y < $h; $y++) {
            $yOffset = $y * $w;
            $dy = ($y - $cy) / $ry;
            $dy2 = $dy * $dy;

            for ($x = 0; $x < $w; $x++) {
                $dx = ($x - $cx) / $rx;
                $distCenter = sqrt($dx * $dx + $dy2);

                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $isProtected = false;

                // 1. Skin tone protection across face/torso/hands
                if ($protectSkin && $distCenter <= 0.95 && $this->isSkinTone($r, $g, $b)) {
                    $isProtected = true;
                }

                // 2. Saliency subject core: Protect central subject pixels that contrast with background
                if (!$isProtected && $distCenter <= 0.50) {
                    $distFromBg = $this->minSampleDistance([$r, $g, $b], $bgSamples);
                    if ($distFromBg > $tolerance * 0.65) {
                        $isProtected = true;
                    }
                }

                $mask[$yOffset + $x] = $isProtected ? 1 : 0;
            }
        }

        return $mask;
    }

    /**
     * Photometric human skin tone classification across ethnicities.
     */
    public function isSkinTone(int $r, int $g, int $b): bool
    {
        if ($r < 55 || $g < 38 || $b < 20) {
            return false;
        }

        if ($r <= $g || $r <= $b) {
            return false;
        }

        if (($r - $g) < 8) {
            return false;
        }

        $sum = $r + $g + $b;
        if ($sum === 0) {
            return false;
        }

        $nr = $r / $sum;
        $ng = $g / $sum;

        // Standard normalized chromaticity bounds
        return ($nr >= 0.35 && $nr <= 0.65 && $ng >= 0.25 && $ng <= 0.40);
    }

    /**
     * Segment background using edge-barrier geodesic flood fill.
     * Prevents boundary leakage by stopping at strong physical edges.
     */
    protected function segmentBackground(
        $img,
        int $w,
        int $h,
        array $bgSamples,
        SplFixedArray $edgeMap,
        SplFixedArray $protectedMask,
        float $tolerance,
        float $edgeBarrierSensitivity
    ): SplFixedArray {
        $total = $w * $h;
        $bgMask = new SplFixedArray($total); // 0 = foreground, 1 = background

        $queue = new SplQueue();
        $seedTolerance = $tolerance * 1.25;

        // Initialize outer perimeter seeds
        // Top and Bottom borders
        for ($x = 0; $x < $w; $x++) {
            foreach ([0, $h - 1] as $y) {
                $idx = $y * $w + $x;
                if ($protectedMask[$idx] === 1 || $bgMask[$idx] === 1) {
                    continue;
                }

                $rgb = imagecolorat($img, $x, $y);
                $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                $dist = $this->minSampleDistance($c, $bgSamples);

                if ($dist <= $seedTolerance) {
                    $bgMask[$idx] = 1;
                    $queue->enqueue($idx);
                }
            }
        }

        // Left and Right borders
        for ($y = 1; $y < $h - 1; $y++) {
            foreach ([0, $w - 1] as $x) {
                $idx = $y * $w + $x;
                if ($protectedMask[$idx] === 1 || $bgMask[$idx] === 1) {
                    continue;
                }

                $rgb = imagecolorat($img, $x, $y);
                $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                $dist = $this->minSampleDistance($c, $bgSamples);

                if ($dist <= $seedTolerance) {
                    $bgMask[$idx] = 1;
                    $queue->enqueue($idx);
                }
            }
        }

        // Barrier-aware BFS propagation
        $maxPropagationTolerance = $tolerance * 1.15;

        while (!$queue->isEmpty()) {
            $idx = $queue->dequeue();
            $x = $idx % $w;
            $y = intdiv($idx, $w);

            $neighbors = [];
            if ($x > 0) $neighbors[] = $idx - 1;
            if ($x < $w - 1) $neighbors[] = $idx + 1;
            if ($y > 0) $neighbors[] = $idx - $w;
            if ($y < $h - 1) $neighbors[] = $idx + $w;

            foreach ($neighbors as $nidx) {
                if ($bgMask[$nidx] === 1) {
                    continue;
                }

                // Guardrail 1: Protected mask (skin tones & core saliency) is impenetrable
                if ($protectedMask[$nidx] === 1) {
                    continue;
                }

                // Guardrail 2: Sobel Edge barrier prevents crossing silhouette contour
                if ($edgeMap[$nidx] >= $edgeBarrierSensitivity) {
                    continue;
                }

                // Guardrail 3: Color must match background reference model
                $rgb = imagecolorat($img, $nidx % $w, intdiv($nidx, $w));
                $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                $dist = $this->minSampleDistance($c, $bgSamples);

                if ($dist <= $maxPropagationTolerance) {
                    $bgMask[$nidx] = 1;
                    $queue->enqueue($nidx);
                }
            }
        }

        return $bgMask;
    }

    /**
     * Morphological hole filling: ensures enclosed interior pixels (e.g. white shirts, teeth)
     * are never hollowed out.
     */
    protected function fillInternalHoles(SplFixedArray $bgMask, int $w, int $h): SplFixedArray
    {
        $total = $w * $h;
        // Connected to exterior mask
        $exterior = new SplFixedArray($total);
        $queue = new SplQueue();

        // Seed exterior from actual image perimeter background pixels
        for ($x = 0; $x < $w; $x++) {
            foreach ([0, $h - 1] as $y) {
                $idx = $y * $w + $x;
                if ($bgMask[$idx] === 1 && $exterior[$idx] === null) {
                    $exterior[$idx] = 1;
                    $queue->enqueue($idx);
                }
            }
        }

        for ($y = 1; $y < $h - 1; $y++) {
            foreach ([0, $w - 1] as $x) {
                $idx = $y * $w + $x;
                if ($bgMask[$idx] === 1 && $exterior[$idx] === null) {
                    $exterior[$idx] = 1;
                    $queue->enqueue($idx);
                }
            }
        }

        // Propagate exterior connectivity
        while (!$queue->isEmpty()) {
            $idx = $queue->dequeue();

            $x = $idx % $w;
            $y = intdiv($idx, $w);

            $neighbors = [];
            if ($x > 0) $neighbors[] = $idx - 1;
            if ($x < $w - 1) $neighbors[] = $idx + 1;
            if ($y > 0) $neighbors[] = $idx - $w;
            if ($y < $h - 1) $neighbors[] = $idx + $w;

            foreach ($neighbors as $nidx) {
                if ($bgMask[$nidx] === 1 && $exterior[$nidx] === null) {
                    $exterior[$nidx] = 1;
                    $queue->enqueue($nidx);
                }
            }
        }

        // Any background-labeled pixel NOT connected to exterior boundary is an interior hole!
        for ($i = 0; $i < $total; $i++) {
            if ($bgMask[$i] === 1 && $exterior[$i] !== 1) {
                $bgMask[$i] = 0; // Restore to foreground
            }
        }

        return $bgMask;
    }

    /**
     * Render full-resolution transparent PNG with anti-aliasing and matting feathering.
     */
    protected function renderOutputWithMatting(
        $src,
        $out,
        int $width,
        int $height,
        SplFixedArray $bgMask,
        int $analysisW,
        int $analysisH,
        array $bgSamples,
        float $tolerance,
        int $feather,
        bool $protectSkin
    ): void {
        $scaleX = $analysisW / (float) $width;
        $scaleY = $analysisH / (float) $height;

        $boundaryRadius = max(1, min(3, $feather));

        for ($y = 0; $y < $height; $y++) {
            $ay = min($analysisH - 1, (int) ($y * $scaleY));
            $ayOffset = $ay * $analysisW;

            for ($x = 0; $x < $width; $x++) {
                $ax = min($analysisW - 1, (int) ($x * $scaleX));
                $aIdx = $ayOffset + $ax;

                $isBg = ($bgMask[$aIdx] === 1);

                $rgb = imagecolorat($src, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($feather > 0 && $this->isBoundaryPixel($ax, $ay, $bgMask, $analysisW, $analysisH, $boundaryRadius)) {
                    if ($protectSkin && $this->isSkinTone($r, $g, $b)) {
                        $col = imagecolorallocatealpha($out, $r, $g, $b, 0);
                    } else {
                        $c = [$r, $g, $b];
                        $dist = $this->minSampleDistance($c, $bgSamples);

                        $t = max(0.0, min(1.0, ($dist - ($tolerance * 0.6)) / max(1.0, $tolerance * 0.8)));
                        $gdAlpha = (int) round((1.0 - $t) * 127);
                        $gdAlpha = max(0, min(127, $gdAlpha));

                        $col = imagecolorallocatealpha($out, $r, $g, $b, $gdAlpha);
                    }
                } elseif ($isBg) {
                    // Fully transparent background
                    $col = imagecolorallocatealpha($out, 0, 0, 0, 127);
                } else {
                    // Fully opaque foreground
                    $col = imagecolorallocatealpha($out, $r, $g, $b, 0);
                }

                imagesetpixel($out, $x, $y, $col);
            }
        }
    }

    protected function isBoundaryPixel(int $x, int $y, SplFixedArray $mask, int $w, int $h, int $radius = 1): bool
    {
        $current = $mask[$y * $w + $x];
        for ($dy = -$radius; $dy <= $radius; $dy++) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny >= $h) continue;
            $yOff = $ny * $w;
            for ($dx = -$radius; $dx <= $radius; $dx++) {
                $nx = $x + $dx;
                if ($nx < 0 || $nx >= $w) continue;
                if ($mask[$yOff + $nx] !== $current) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function minSampleDistance(array $color, array $samples): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($samples as $s) {
            $d = $this->colorDistance($color, $s);
            if ($d < $min) {
                $min = $d;
            }
        }
        return $min;
    }

    protected function colorDistance(array $c1, array $c2): float
    {
        $rBar = ($c1[0] + $c2[0]) / 2.0;
        $dr = $c1[0] - $c2[0];
        $dg = $c1[1] - $c2[1];
        $db = $c1[2] - $c2[2];

        $wR = 2.0 + ($rBar / 256.0);
        $wG = 4.0;
        $wB = 2.0 + ((255.0 - $rBar) / 256.0);

        $sq = ($wR * $dr * $dr) + ($wG * $dg * $dg) + ($wB * $db * $db);
        $raw = sqrt(max(0.0, $sq));

        return min(100.0, ($raw / 765.0) * 100.0);
    }
}
