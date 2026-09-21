<?php

namespace App\Services\Image;

use InvalidArgumentException;
use RuntimeException;
use SplFixedArray;
use SplQueue;

class ColorSamplingBackgroundRemovalEngine implements BackgroundRemovalEngineInterface
{
    public const ID = 'color_sampling';
    public const LABEL = 'Color Sampling & Connected Edge Detection';

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
        return 'Deterministic multi-corner sampling, connected flood fill, and anti-aliased edge feathering. (Not an AI/ML model)';
    }

    public function isAi(): bool
    {
        return false;
    }

    /**
     * Remove the background from an image file and save the transparent PNG output.
     *
     * @param string $inputPath
     * @param string $outputPath
     * @param array $options Tuning options:
     *   - tolerance: float (5.0 - 80.0, default 25.0)
     *   - feather: int (0 - 5, default 2)
     *   - flood_fill: bool (default true - flood from edges to protect interior subject colors)
     *   - sample_corners: bool (default true)
     *   - custom_color: ?string (hex '#FFFFFF' or null)
     * @return array
     */
    public function removeBackground(string $inputPath, string $outputPath, array $options = []): array
    {
        if (!file_exists($inputPath)) {
            throw new InvalidArgumentException("Input image file not found: {$inputPath}");
        }

        $imageInfo = @getimagesize($inputPath);
        if ($imageInfo === false) {
            throw new InvalidArgumentException("Invalid or unsupported image file: {$inputPath}");
        }

        $width = $imageInfo[0];
        $height = $imageInfo[1];
        $mime = $imageInfo['mime'];

        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException("Invalid image dimensions: {$width}x{$height}");
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

        // Ensure parent directory of output exists
        $outDir = dirname($outputPath);
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        $tolerance = isset($options['tolerance']) ? max(5.0, min(80.0, (float) $options['tolerance'])) : 25.0;
        $feather = isset($options['feather']) ? max(0, min(5, (int) $options['feather'])) : 2;
        $useFloodFill = $options['flood_fill'] ?? true;
        $customHex = $options['custom_color'] ?? null;

        try {
            // 1. Gather background color samples
            $bgSamples = $this->collectBackgroundSamples($src, $width, $height, $customHex);

            // 2. Perform background segmentation
            $totalPixels = $width * $height;
            // Mask: 0 = unvisited/foreground, 1 = background, 2 = edge/boundary
            $mask = new SplFixedArray($totalPixels);

            if ($useFloodFill) {
                $this->floodFillBackground($src, $width, $height, $bgSamples, $tolerance, $mask);
            } else {
                $this->globalColorMatchBackground($src, $width, $height, $bgSamples, $tolerance, $mask);
            }

            // 3. Mark boundary pixels for feathering & anti-aliasing
            $boundaryDistances = [];
            if ($feather > 0) {
                $boundaryDistances = $this->computeBoundaryTransition($src, $width, $height, $mask, $bgSamples, $tolerance, $feather);
            }

            // 4. Construct output truecolor image with transparent alpha
            $out = imagecreatetruecolor($width, $height);
            imagealphablending($out, false);
            imagesavealpha($out, true);

            $tLow = $tolerance * 0.65;
            $tHigh = $tolerance * 1.35;
            $tRange = max(1.0, $tHigh - $tLow);

            for ($y = 0; $y < $height; $y++) {
                $yOffset = $y * $width;
                for ($x = 0; $x < $width; $x++) {
                    $idx = $yOffset + $x;
                    $status = $mask[$idx];

                    if ($status === 1) {
                        // Fully transparent background
                        $transparentColor = imagecolorallocatealpha($out, 0, 0, 0, 127);
                        imagesetpixel($out, $x, $y, $transparentColor);
                        continue;
                    }

                    $rgb = imagecolorat($src, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $origAlpha = ($rgb >> 24) & 0x7F; // 0 = opaque, 127 = transparent

                    $finalAlpha = $origAlpha;

                    if ($status === 2 && isset($boundaryDistances[$idx])) {
                        // Feathered boundary pixel
                        $dist = $boundaryDistances[$idx];
                        $ratio = max(0.0, min(1.0, ($dist - $tLow) / $tRange));
                        // Ratio: 0 = background-like, 1 = foreground-like
                        if ($ratio < 0.05) {
                            $transparentColor = imagecolorallocatealpha($out, 0, 0, 0, 127);
                            imagesetpixel($out, $x, $y, $transparentColor);
                            continue;
                        }
                        $featherAlpha = (int) round((1.0 - $ratio) * 127);
                        $finalAlpha = max($origAlpha, $featherAlpha);

                        // Decontaminate background color spill from boundary pixel RGB
                        $closestBg = $this->getClosestBackgroundSample([$r, $g, $b], $bgSamples);
                        $alphaNorm = 1.0 - ($finalAlpha / 127.0);
                        $cleanR = max(0, min(255, (int) round($closestBg[0] + ($r - $closestBg[0]) / max($alphaNorm, 0.25))));
                        $cleanG = max(0, min(255, (int) round($closestBg[1] + ($g - $closestBg[1]) / max($alphaNorm, 0.25))));
                        $cleanB = max(0, min(255, (int) round($closestBg[2] + ($b - $closestBg[2]) / max($alphaNorm, 0.25))));

                        $pixelColor = imagecolorallocatealpha($out, $cleanR, $cleanG, $cleanB, $finalAlpha);
                        imagesetpixel($out, $x, $y, $pixelColor);
                        continue;
                    }

                    $pixelColor = imagecolorallocatealpha($out, $r, $g, $b, $finalAlpha);
                    imagesetpixel($out, $x, $y, $pixelColor);
                }
            }

            imagepng($out, $outputPath, 9);
            imagedestroy($out);

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
            if (is_resource($src) || $src instanceof \GdImage) {
                imagedestroy($src);
            }
        }
    }

    /**
     * Collect background color reference samples from corners and borders.
     */
    protected function collectBackgroundSamples($src, int $width, int $height, ?string $customHex = null): array
    {
        $samples = [];

        if ($customHex !== null && preg_match('/^#?([a-f0-9]{6})$/i', $customHex, $matches)) {
            $hex = $matches[1];
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            $samples[] = [$r, $g, $b];
            return $samples;
        }

        // Sample four corners with 3x3 local patch average to avoid compression noise
        $cornerCoords = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
        ];

        foreach ($cornerCoords as [$cx, $cy]) {
            $samples[] = $this->samplePatchAverage($src, $cx, $cy, $width, $height);
        }

        // Sample along borders at 25%, 50%, 75%
        $borderFractions = [0.25, 0.50, 0.75];
        foreach ($borderFractions as $frac) {
            $bx = (int) round(($width - 1) * $frac);
            $by = (int) round(($height - 1) * $frac);

            $samples[] = $this->samplePatchAverage($src, $bx, 0, $width, $height); // Top
            $samples[] = $this->samplePatchAverage($src, $bx, $height - 1, $width, $height); // Bottom
            $samples[] = $this->samplePatchAverage($src, 0, $by, $width, $height); // Left
            $samples[] = $this->samplePatchAverage($src, $width - 1, $by, $width, $height); // Right
        }

        // Filter out extreme outlier samples if subject touches one edge
        return $this->filterSampleOutliers($samples);
    }

    protected function samplePatchAverage($src, int $cx, int $cy, int $width, int $height): array
    {
        $rSum = 0;
        $gSum = 0;
        $bSum = 0;
        $count = 0;

        for ($dy = -1; $dy <= 1; $dy++) {
            $py = max(0, min($height - 1, $cy + $dy));
            for ($dx = -1; $dx <= 1; $dx++) {
                $px = max(0, min($width - 1, $cx + $dx));
                $rgb = imagecolorat($src, $px, $py);
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

    /**
     * Filter out samples that are distant from the consensus background cluster.
     */
    protected function filterSampleOutliers(array $samples): array
    {
        if (count($samples) <= 4) {
            return $samples;
        }

        // Find medoid of samples
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

        // Keep samples within 35 distance of medoid
        $filtered = [];
        foreach ($samples as $s) {
            if ($this->colorDistance($s, $medoid) <= 35.0) {
                $filtered[] = $s;
            }
        }

        return !empty($filtered) ? $filtered : [$medoid];
    }

    /**
     * Find closest background sample for local color decontamination.
     */
    protected function getClosestBackgroundSample(array $rgb, array $samples): array
    {
        $minDist = PHP_FLOAT_MAX;
        $closest = $samples[0] ?? [255, 255, 255];

        foreach ($samples as $s) {
            $dist = $this->colorDistance($rgb, $s);
            if ($dist < $minDist) {
                $minDist = $dist;
                $closest = $s;
            }
        }

        return $closest;
    }

    /**
     * Perceptual color distance normalized to 0..100.
     */
    protected function colorDistance(array $c1, array $c2): float
    {
        $dr = $c1[0] - $c2[0];
        $dg = $c1[1] - $c2[1];
        $db = $c1[2] - $c2[2];

        // Red-mean perceptual formula with boosted blue sensitivity for ink preservation
        $rMean = ($c1[0] + $c2[0]) / 2.0;
        $weightR = 2.0 + ($rMean / 256.0);
        $weightG = 4.0;
        $weightB = 3.0 + ((255.0 - $rMean) / 256.0);

        $dist = sqrt($weightR * $dr * $dr + $weightG * $dg * $dg + $weightB * $db * $db);

        // Apply chroma penalty: protect saturated foreground colors (like logos/ink) from neutral backgrounds
        $chroma1 = max($c1) - min($c1);
        $chroma2 = max($c2) - min($c2);
        
        if ($chroma1 > $chroma2) {
            $dist += ($chroma1 - $chroma2) * 2.0;
        }

        // Normalize by max theoretical distance to 0..100 scale
        return ($dist / 7.65);
    }

    /**
     * Find minimum color distance to any background sample.
     */
    protected function minBackgroundDistance(array $color, array $bgSamples): float
    {
        $min = PHP_FLOAT_MAX;
        foreach ($bgSamples as $s) {
            $d = $this->colorDistance($color, $s);
            if ($d < $min) {
                $min = $d;
            }
        }
        return $min;
    }

    /**
     * Connected-region flood fill starting exclusively from border pixels.
     * Guarantees that internal subject pixels (e.g. white shirts or eyes) are protected.
     */
    protected function floodFillBackground(
        $src,
        int $width,
        int $height,
        array $bgSamples,
        float $tolerance,
        SplFixedArray $mask
    ): void {
        $queue = new SplQueue();
        $totalPixels = $width * $height;

        // Tolerance for border seed entry
        $seedTolerance = $tolerance * 1.15;

        // Top and Bottom borders
        for ($x = 0; $x < $width; $x++) {
            foreach ([0, $height - 1] as $y) {
                $idx = $y * $width + $x;
                if ($mask[$idx] === null || $mask[$idx] === 0) {
                    $rgb = imagecolorat($src, $x, $y);
                    $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                    if ($this->minBackgroundDistance($c, $bgSamples) <= $seedTolerance) {
                        $mask[$idx] = 1;
                        $queue->enqueue($idx);
                    }
                }
            }
        }

        // Left and Right borders
        for ($y = 1; $y < $height - 1; $y++) {
            foreach ([0, $width - 1] as $x) {
                $idx = $y * $width + $x;
                if ($mask[$idx] === null || $mask[$idx] === 0) {
                    $rgb = imagecolorat($src, $x, $y);
                    $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                    if ($this->minBackgroundDistance($c, $bgSamples) <= $seedTolerance) {
                        $mask[$idx] = 1;
                        $queue->enqueue($idx);
                    }
                }
            }
        }

        // BFS propagation
        $localTolerance = $tolerance * 0.50;

        while (!$queue->isEmpty()) {
            $idx = $queue->dequeue();
            $x = $idx % $width;
            $y = intdiv($idx, $width);

            $currentRgb = imagecolorat($src, $x, $y);
            $currentC = [($currentRgb >> 16) & 0xFF, ($currentRgb >> 8) & 0xFF, $currentRgb & 0xFF];

            $neighbors = [];
            if ($x > 0) {
                $neighbors[] = [$x - 1, $y, $idx - 1];
            }
            if ($x < $width - 1) {
                $neighbors[] = [$x + 1, $y, $idx + 1];
            }
            if ($y > 0) {
                $neighbors[] = [$x, $y - 1, $idx - $width];
            }
            if ($y < $height - 1) {
                $neighbors[] = [$x, $y + 1, $idx + $width];
            }

            foreach ($neighbors as [$nx, $ny, $nidx]) {
                if ($mask[$nidx] === null || $mask[$nidx] === 0) {
                    $nRgb = imagecolorat($src, $nx, $ny);
                    $nC = [($nRgb >> 16) & 0xFF, ($nRgb >> 8) & 0xFF, $nRgb & 0xFF];

                    $globalDist = $this->minBackgroundDistance($nC, $bgSamples);

                    // Match if within global sample tolerance OR within local propagation tolerance
                    if ($globalDist <= $tolerance || ($this->colorDistance($nC, $currentC) <= $localTolerance && $globalDist <= $tolerance * 1.15)) {
                        $mask[$nidx] = 1;
                        $queue->enqueue($nidx);
                    }
                }
            }
        }
    }

    /**
     * Fallback global color match when flood_fill is disabled.
     */
    protected function globalColorMatchBackground(
        $src,
        int $width,
        int $height,
        array $bgSamples,
        float $tolerance,
        SplFixedArray $mask
    ): void {
        for ($y = 0; $y < $height; $y++) {
            $yOffset = $y * $width;
            for ($x = 0; $x < $width; $x++) {
                $idx = $yOffset + $x;
                $rgb = imagecolorat($src, $x, $y);
                $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                if ($this->minBackgroundDistance($c, $bgSamples) <= $tolerance) {
                    $mask[$idx] = 1;
                }
            }
        }
    }

    /**
     * Compute boundary transition zone for smooth edge feathering and anti-aliasing.
     * Returns array of [idx => color_distance].
     */
    protected function computeBoundaryTransition(
        $src,
        int $width,
        int $height,
        SplFixedArray $mask,
        array $bgSamples,
        float $tolerance,
        int $featherRadius
    ): array {
        $boundaryDistances = [];
        $tMax = $tolerance * 1.35;

        for ($y = 0; $y < $height; $y++) {
            $yOffset = $y * $width;
            for ($x = 0; $x < $width; $x++) {
                $idx = $yOffset + $x;

                // Only consider foreground pixels that are near the background
                if ($mask[$idx] !== 1) {
                    // Check if this pixel neighbors a background pixel
                    $touchesBg = false;
                    for ($dy = -$featherRadius; $dy <= $featherRadius && !$touchesBg; $dy++) {
                        $ny = $y + $dy;
                        if ($ny < 0 || $ny >= $height) {
                            continue;
                        }
                        for ($dx = -$featherRadius; $dx <= $featherRadius && !$touchesBg; $dx++) {
                            $nx = $x + $dx;
                            if ($nx < 0 || $nx >= $width) {
                                continue;
                            }
                            $nidx = $ny * $width + $nx;
                            if ($mask[$nidx] === 1) {
                                $touchesBg = true;
                            }
                        }
                    }

                    if ($touchesBg) {
                        $rgb = imagecolorat($src, $x, $y);
                        $c = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                        $d = $this->minBackgroundDistance($c, $bgSamples);

                        if ($d <= $tMax) {
                            $mask[$idx] = 2; // Mark as boundary
                            $boundaryDistances[$idx] = $d;
                        }
                    }
                }
            }
        }

        return $boundaryDistances;
    }
}
