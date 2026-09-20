<?php

namespace App\Services\Image\Matting;

use SplFixedArray;
use SplQueue;

/**
 * Reusable classical topology, refinement, and edge decontamination matting service.
 *
 * Responsibilities:
 * 1. Adaptive perimeter background sampling with consensus outlier rejection.
 * 2. 2D Sobel gradient energy barrier calculation for sharp boundary protection.
 * 3. Geodesic flood fill background segmentation (perimeter or internal seed modes).
 * 4. Morphological interior hole-filling preserving enclosed solid regions (a71fe72).
 * 5. Macro-AI mask and micro-topology intersection with high-contrast fine stroke preservation.
 * 6. High-fidelity alpha boundary matting and RGB background color decontamination (a71fe72).
 */
class DecontaminationMattingService
{
    /**
     * Collect adaptive background samples along the image perimeter with consensus outlier rejection.
     */
    public function collectAdaptiveBackgroundSamples($img, int $w, int $h, ?string $customHex = null): array
    {
        if ($customHex !== null) {
            $hex = ltrim($customHex, '#');
            if (strlen($hex) === 3) {
                $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
            }
            if (strlen($hex) === 6) {
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                return [[$r, $g, $b]];
            }
        }

        $cornerSamples = [];
        $cornerInsets = [
            [2, 2],
            [$w - 3, 2],
            [2, $h - 3],
            [$w - 3, $h - 3],
        ];

        foreach ($cornerInsets as [$cx, $cy]) {
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    $px = max(0, min($w - 1, $cx + $dx));
                    $py = max(0, min($h - 1, $cy + $dy));
                    $rgb = imagecolorat($img, $px, $py);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    $isDuplicate = false;
                    foreach ($cornerSamples as $s) {
                        $dr = $r - $s[0];
                        $dg = $g - $s[1];
                        $db = $b - $s[2];
                        if (($dr * $dr + $dg * $dg + $db * $db) < 25) {
                            $isDuplicate = true;
                            break;
                        }
                    }
                    if (!$isDuplicate) {
                        $cornerSamples[] = [$r, $g, $b];
                    }
                }
            }
        }

        if (empty($cornerSamples)) {
            $cornerSamples = [[255, 255, 255]];
        }

        // Perimeter sample points along top, bottom, left, right borders
        $samples = $cornerSamples;
        $numBorderSamples = 8;
        for ($i = 1; $i < $numBorderSamples; $i++) {
            $x = (int) round(($w * $i) / $numBorderSamples);
            $y = (int) round(($h * $i) / $numBorderSamples);
            $samplePoints = [
                [$x, 1],
                [$x, $h - 2],
                [1, $y],
                [$w - 2, $y],
            ];

            foreach ($samplePoints as [$px, $py]) {
                $rgb = imagecolorat($img, $px, $py);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Outlier rejection: Candidate perimeter sample must be reasonably consistent
                // with at least one corner sample to avoid sampling foreground objects cutting across borders (e.g. torso/clothing at bottom)
                $distToCornerBg = $this->minSampleDistance([$r, $g, $b], $cornerSamples);
                if ($distToCornerBg > 65.0) {
                    continue; // Skip foreground subject crossing the perimeter
                }

                $isDuplicate = false;
                foreach ($samples as $s) {
                    $dr = $r - $s[0];
                    $dg = $g - $s[1];
                    $db = $b - $s[2];
                    if (($dr * $dr + $dg * $dg + $db * $db) < 25) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if (!$isDuplicate) {
                    $samples[] = [$r, $g, $b];
                }
            }
        }

        return $samples;
    }

    /**
     * Compute 2D Sobel gradient energy map for physical edge preservation.
     */
    public function computeSobelEdgeMap($img, int $w, int $h): SplFixedArray
    {
        $total = $w * $h;
        $edgeMap = new SplFixedArray($total);

        // Precompute grayscale luminance buffer
        $gray = new SplFixedArray($total);
        for ($y = 0; $y < $h; $y++) {
            $yOffset = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $gray[$yOffset + $x] = (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
            }
        }

        // Apply 3x3 Sobel convolution
        for ($y = 0; $y < $h; $y++) {
            $yOffset = $y * $w;
            $topY = max(0, $y - 1) * $w;
            $botY = min($h - 1, $y + 1) * $w;

            for ($x = 0; $x < $w; $x++) {
                $leftX = max(0, $x - 1);
                $rightX = min($w - 1, $x + 1);

                $tl = $gray[$topY + $leftX];
                $tc = $gray[$topY + $x];
                $tr = $gray[$topY + $rightX];

                $ml = $gray[$yOffset + $leftX];
                $mr = $gray[$yOffset + $rightX];

                $bl = $gray[$botY + $leftX];
                $bc = $gray[$botY + $x];
                $br = $gray[$botY + $rightX];

                // Horizontal gradient
                $gx = (-1 * $tl) + (1 * $tr)
                    + (-2 * $ml) + (2 * $mr)
                    + (-1 * $bl) + (1 * $br);

                // Vertical gradient
                $gy = (-1 * $tl) + (-2 * $tc) + (-1 * $tr)
                    + ( 1 * $bl) + ( 2 * $bc) + ( 1 * $br);

                $mag = sqrt($gx * $gx + $gy * $gy);
                // Normalized scale: max Sobel gradient is ~1020, divide by 10.2 for 0..100
                $edgeMap[$yOffset + $x] = min(100.0, $mag / 10.2);
            }
        }

        return $edgeMap;
    }

    /**
     * Segment background using edge-barrier geodesic flood fill.
     * When $seedInternal is true, internal background seeds are also initialized for hole penetration.
     */
    public function segmentBackground(
        $img,
        int $w,
        int $h,
        array $bgSamples,
        SplFixedArray $edgeMap,
        SplFixedArray $protectedMask,
        float $tolerance,
        float $edgeBarrierSensitivity,
        bool $seedInternal = false
    ): SplFixedArray {
        $total = $w * $h;
        $bgMask = new SplFixedArray($total); // 0 = foreground, 1 = background

        $queue = new SplQueue();
        $seedTolerance = $tolerance * 1.25;

        // 1. Initialize outer perimeter seeds
        // Top and Bottom borders
        for ($x = 0; $x < $w; $x++) {
            foreach ([0, $h - 1] as $y) {
                $idx = $y * $w + $x;
                if ($protectedMask[$idx] === 1 || $bgMask[$idx] === 1) {
                    continue;
                }
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($this->minSampleDistance([$r, $g, $b], $bgSamples) <= $seedTolerance) {
                    $bgMask[$idx] = 1;
                    $queue->enqueue([$x, $y]);
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
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                if ($this->minSampleDistance([$r, $g, $b], $bgSamples) <= $seedTolerance) {
                    $bgMask[$idx] = 1;
                    $queue->enqueue([$x, $y]);
                }
            }
        }

        // 2. Optional internal high-confidence background seeds for deep graphic holes
        if ($seedInternal) {
            for ($y = 1; $y < $h - 1; $y++) {
                for ($x = 1; $x < $w - 1; $x++) {
                    $idx = $y * $w + $x;
                    if ($protectedMask[$idx] === 1 || $bgMask[$idx] === 1) {
                        continue;
                    }

                    $rgb = imagecolorat($img, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    if ($this->minSampleDistance([$r, $g, $b], $bgSamples) <= ($tolerance * 0.25)) {
                        $bgMask[$idx] = 1;
                        $queue->enqueue([$x, $y]);
                    }
                }
            }
        }

        $neighbors = [
            [-1, 0], [1, 0], [0, -1], [0, 1],
            [-1, -1], [1, -1], [-1, 1], [1, 1]
        ];

        while (!$queue->isEmpty()) {
            [$cx, $cy] = $queue->dequeue();

            foreach ($neighbors as [$dx, $dy]) {
                $nx = $cx + $dx;
                $ny = $cy + $dy;

                if ($nx < 0 || $nx >= $w || $ny < 0 || $ny >= $h) {
                    continue;
                }

                $nidx = $ny * $w + $nx;

                // Guardrail 1: Already visited
                if ($bgMask[$nidx] === 1) {
                    continue;
                }

                // Guardrail 2: Protected mask is impenetrable
                if ($protectedMask[$nidx] === 1) {
                    continue;
                }

                $rgb = imagecolorat($img, $nx, $ny);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $dist = $this->minSampleDistance([$r, $g, $b], $bgSamples);

                // Guardrail 3: 2D Sobel edge energy barrier
                if ($dist > ($tolerance * 0.60) && $edgeMap[$nidx] > $edgeBarrierSensitivity) {
                    continue;
                }

                if ($dist <= $tolerance) {
                    $bgMask[$nidx] = 1;
                    $queue->enqueue([$nx, $ny]);
                }
            }
        }

        return $bgMask;
    }

    /**
     * Morphological interior hole-filling (exact a71fe72 behavior):
     * Inverts the background mask, labels exterior connected regions, and seals any internal trapped cavities.
     */
    public function fillInternalHoles(SplFixedArray $bgMask, int $w, int $h): SplFixedArray
    {
        $total = $w * $h;
        $fgMask = new SplFixedArray($total);
        $exterior = new SplFixedArray($total);
        $queue = new SplQueue();

        for ($x = 0; $x < $w; $x++) {
            foreach ([0, $h - 1] as $y) {
                $idx = $y * $w + $x;
                if ($bgMask[$idx] === 1 && empty($exterior[$idx])) {
                    $exterior[$idx] = 1;
                    $queue->enqueue([$x, $y]);
                }
            }
        }

        for ($y = 1; $y < $h - 1; $y++) {
            foreach ([0, $w - 1] as $x) {
                $idx = $y * $w + $x;
                if ($bgMask[$idx] === 1 && empty($exterior[$idx])) {
                    $exterior[$idx] = 1;
                    $queue->enqueue([$x, $y]);
                }
            }
        }

        $neighbors = [[-1, 0], [1, 0], [0, -1], [0, 1]];

        while (!$queue->isEmpty()) {
            [$cx, $cy] = $queue->dequeue();

            foreach ($neighbors as [$dx, $dy]) {
                $nx = $cx + $dx;
                $ny = $cy + $dy;

                if ($nx < 0 || $nx >= $w || $ny < 0 || $ny >= $h) {
                    continue;
                }

                $nidx = $ny * $w + $nx;
                if ($bgMask[$nidx] === 1 && empty($exterior[$nidx])) {
                    $exterior[$nidx] = 1;
                    $queue->enqueue([$nx, $ny]);
                }
            }
        }

        // Exact a71fe72 behavior: Any pixel not reachable from exterior background is preserved as solid foreground
        for ($i = 0; $i < $total; $i++) {
            $fgMask[$i] = empty($exterior[$i]) ? 1 : 0;
        }

        return $fgMask;
    }

    /**
     * Intersects an AI-generated macro foreground mask with classical micro-topology.
     * AI defines outer macro-boundaries; Classical defines pure micro-holes and cutout regions,
     * while preserving sharp physical strokes that resolution-downsampling may thin out.
     */
    public function intersectAiWithTopology(
        SplFixedArray $aiFgMask,
        SplFixedArray $bgMask,
        int $w,
        int $h,
        ?SplFixedArray $edgeMap = null,
        $src = null,
        ?array $bgSamples = null,
        float $tolerance = 25.0
    ): SplFixedArray {
        $total = $w * $h;
        $fgMask = new SplFixedArray($total);

        $hasEdgeRecovery = ($edgeMap !== null && $src !== null && $bgSamples !== null);
        $minDistForStroke = $tolerance * 1.5;

        for ($y = 0; $y < $h; $y++) {
            $yOffset = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $i = $yOffset + $x;

                // 1. Any pixel reached by background flood fill is strictly background (hole punched)
                if ($bgMask[$i] === 1) {
                    $fgMask[$i] = 0;
                    continue;
                }

                // 2. High-confidence AI macro-foreground
                if ($aiFgMask[$i] === 1) {
                    $fgMask[$i] = 1;
                    continue;
                }

                // 3. Fine stroke recovery: physical edges with high contrast against background
                if ($hasEdgeRecovery) {
                    $rgb = imagecolorat($src, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;

                    if ($this->minSampleDistance([$r, $g, $b], $bgSamples) > $minDistForStroke) {
                        $hasEdge = ($edgeMap[$i] > 15.0);
                        if (!$hasEdge) {
                            foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                                $nx = $x + $dx;
                                $ny = $y + $dy;
                                if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                                    if ($edgeMap[$ny * $w + $nx] > 15.0) {
                                        $hasEdge = true;
                                        break;
                                    }
                                }
                            }
                        }
                        if ($hasEdge) {
                            $fgMask[$i] = 1;
                            continue;
                        }
                    }
                }

                $fgMask[$i] = 0;
            }
        }

        // Island Removal Block (Appended without disrupting original stroke recovery)
        if ($hasEdgeRecovery) {
            $targetDist = $tolerance * 6.0;
            $colorBg = new \SplFixedArray($total);

            for ($i = 0; $i < $total; $i++) {
                if ($fgMask[$i] === 1) {
                    $cx = $i % $w; $cy = intdiv($i, $w);
                    $rgb = imagecolorat($src, $cx, $cy);
                    $r = ($rgb >> 16) & 0xFF; $g = ($rgb >> 8) & 0xFF; $b = $rgb & 0xFF;
                    if ($this->minSampleDistance([$r, $g, $b], $bgSamples) <= $targetDist) {
                        $colorBg[$i] = 1;
                    }
                }
            }

            $visited = new \SplFixedArray($total);
            $queue = new \SplQueue();

            for ($i = 0; $i < $total; $i++) {
                if ($colorBg[$i] === 1 && empty($visited[$i])) {
                    $queue->enqueue($i);
                    $visited[$i] = 1;
                    $pixels = [];

                    while (!$queue->isEmpty()) {
                        $curr = $queue->dequeue();
                        $pixels[] = $curr;
                        $cx = $curr % $w; $cy = intdiv($curr, $w);
                        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                            $nx = $cx + $dx; $ny = $cy + $dy;
                            if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                                $nidx = $ny * $w + $nx;
                                if ($colorBg[$nidx] === 1 && empty($visited[$nidx])) {
                                    $visited[$nidx] = 1;
                                    $queue->enqueue($nidx);
                                }
                            }
                        }
                    }

                    $internalEdges = [];
                    foreach ($pixels as $p) {
                        $isInternal = true;
                        $px = $p % $w; $py = intdiv($p, $w);
                        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                            $nx = $px + $dx; $ny = $py + $dy;
                            if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                                $pnidx = $ny * $w + $nx;
                                if (empty($colorBg[$pnidx])) {
                                    $isInternal = false;
                                    break;
                                }
                            }
                        }
                        if ($isInternal) {
                            $internalEdges[] = $edgeMap[$p];
                        }
                    }

                    $intCount = count($internalEdges);
                    $intMeanEdge = $intCount > 0 ? array_sum($internalEdges) / $intCount : 0.0;
                    $intMaxEdge = $intCount > 0 ? max($internalEdges) : 0.0;
                    
                    if ($intMaxEdge > 0.001 && $intMeanEdge < 22.0 && $intMaxEdge < 86.0) {
                        foreach ($pixels as $p) {
                            $fgMask[$p] = 0;
                        }
                    }
                }
            }
        }

        return $fgMask;
    }

    /**
     * Render high-resolution transparent PNG with anti-aliased alpha boundary matting and background color decontamination.
     */
    public function renderOutputWithMatting(
        $src,
        SplFixedArray $fgMask,
        int $w,
        int $h,
        array $bgSamples,
        float $tolerance,
        int $feather,
        float $scale = 1.0
    ) {
        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $total = $w * $h;
        $featherRadius = max(1, min(4, $feather > 0 ? $feather : 1));
        if ($scale < 1.0) {
            $featherRadius = max($featherRadius, (int) min(4, ceil(1.0 / $scale)));
        }

        // Identify boundary pixels: foreground pixels located within $featherRadius of background
        $isBoundary = new SplFixedArray($total);
        if ($feather > 0 || $scale < 1.0) {
            for ($y = 0; $y < $h; $y++) {
                $yOffset = $y * $w;
                for ($x = 0; $x < $w; $x++) {
                    $idx = $yOffset + $x;
                    if ($fgMask[$idx] === 1) {
                        $touches = false;
                        for ($dy = -$featherRadius; $dy <= $featherRadius && !$touches; $dy++) {
                            $ny = $y + $dy;
                            if ($ny < 0 || $ny >= $h) continue;
                            for ($dx = -$featherRadius; $dx <= $featherRadius && !$touches; $dx++) {
                                $nx = $x + $dx;
                                if ($nx < 0 || $nx >= $w) continue;
                                if ($fgMask[$ny * $w + $nx] === 0) {
                                    $touches = true;
                                }
                            }
                        }
                        if ($touches) {
                            $isBoundary[$idx] = 1;
                        }
                    }
                }
            }
        }

        $tLow = $tolerance * 0.50;
        $tHigh = $tolerance * 1.50;
        $tRange = max(1.0, $tHigh - $tLow);

        for ($y = 0; $y < $h; $y++) {
            $yOffset = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                $idx = $yOffset + $x;

                // Background pixel -> Completely transparent (127)
                if ($fgMask[$idx] === 0) {
                    $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagesetpixel($canvas, $x, $y, $color);
                    continue;
                }

                $rgb = imagecolorat($src, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                // Solid interior foreground pixel -> Completely opaque (0)
                if (empty($isBoundary[$idx])) {
                    $color = imagecolorallocatealpha($canvas, $r, $g, $b, 0);
                    imagesetpixel($canvas, $x, $y, $color);
                    continue;
                }

                // Boundary pixel -> Smooth alpha matting with background color decontamination
                $dist = $this->minSampleDistance([$r, $g, $b], $bgSamples);

                // If pixel color is essentially background, remove residual edge spill
                if ($dist <= $tLow) {
                    $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagesetpixel($canvas, $x, $y, $color);
                    continue;
                }

                // If pixel color strongly contrasts with background, preserve full opacity
                if ($dist >= $tHigh) {
                    $color = imagecolorallocatealpha($canvas, $r, $g, $b, 0);
                    imagesetpixel($canvas, $x, $y, $color);
                    continue;
                }

                // Transition alpha [0.0 = background-like, 1.0 = foreground-like]
                $alphaNorm = max(0.0, min(1.0, ($dist - $tLow) / $tRange));
                if ($alphaNorm < 0.05) {
                    $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                    imagesetpixel($canvas, $x, $y, $color);
                    continue;
                }

                // Decontaminate background color spill from boundary pixel RGB
                $closestBg = $this->getClosestBackgroundSample([$r, $g, $b], $bgSamples);
                $cleanR = max(0, min(255, (int) round($closestBg[0] + ($r - $closestBg[0]) / max($alphaNorm, 0.25))));
                $cleanG = max(0, min(255, (int) round($closestBg[1] + ($g - $closestBg[1]) / max($alphaNorm, 0.25))));
                $cleanB = max(0, min(255, (int) round($closestBg[2] + ($b - $closestBg[2]) / max($alphaNorm, 0.25))));

                $gdAlpha = (int) round((1.0 - $alphaNorm) * 127.0);

                $color = imagecolorallocatealpha($canvas, $cleanR, $cleanG, $cleanB, $gdAlpha);
                imagesetpixel($canvas, $x, $y, $color);
            }
        }

        return $canvas;
    }

    /**
     * Find closest background sample for local color decontamination.
     */
    public function getClosestBackgroundSample(array $rgb, array $samples): array
    {
        $minDist = PHP_FLOAT_MAX;
        $closest = $samples[0] ?? [255, 255, 255];
        $r1 = $rgb[0];
        $g1 = $rgb[1];
        $b1 = $rgb[2];

        foreach ($samples as $s) {
            $dr = $r1 - $s[0];
            $dg = $g1 - $s[1];
            $db = $b1 - $s[2];
            $rmean = ($r1 + $s[0]) / 2.0;
            $dist = (2 + $rmean / 256.0) * $dr * $dr + 4.0 * $dg * $dg + (2 + (255 - $rmean) / 256.0) * $db * $db;
            if ($dist < $minDist) {
                $minDist = $dist;
                $closest = $s;
            }
        }

        return $closest;
    }

    /**
     * Minimum Euclidean color distance in sRGB / CIE distance proxy.
     */
    public function minSampleDistance(array $rgb, array $samples): float
    {
        $minDist = PHP_FLOAT_MAX;
        $r1 = $rgb[0];
        $g1 = $rgb[1];
        $b1 = $rgb[2];

        foreach ($samples as $s) {
            $dr = $r1 - $s[0];
            $dg = $g1 - $s[1];
            $db = $b1 - $s[2];

            // Perceptual color weighting (Redmean formula approximation)
            $rmean = ($r1 + $s[0]) / 2.0;
            $dist = sqrt((2 + $rmean / 256.0) * $dr * $dr + 4.0 * $dg * $dg + (2 + (255 - $rmean) / 256.0) * $db * $db);

            if ($dist < $minDist) {
                $minDist = $dist;
            }
        }

        return $minDist;
    }
}
