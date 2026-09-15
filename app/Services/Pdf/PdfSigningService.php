<?php

namespace App\Services\Pdf;

use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PdfSigningService
{
    /**
     * Genuine signature styles mapped to verified, standard system fonts.
     */
    public const TYPED_STYLES = [
        'classic' => [
            'id'       => 'classic',
            'name_en'  => 'Classic Script',
            'name_ar'  => 'خط كلاسيكي',
            'font_ttf' => 'C:/Windows/Fonts/segoesc.ttf',
            'fallback' => 'C:/Windows/Fonts/segoescb.ttf',
            'css'      => "'Segoe Script', cursive",
        ],
        'casual' => [
            'id'       => 'casual',
            'name_en'  => 'Modern Casual',
            'name_ar'  => 'خط حديث وعفوي',
            'font_ttf' => 'C:/Windows/Fonts/Inkfree.ttf',
            'fallback' => 'C:/Windows/Fonts/segoepr.ttf',
            'css'      => "'Ink Free', 'Segoe Print', cursive",
        ],
        'brush' => [
            'id'       => 'brush',
            'name_en'  => 'Flowing Brush',
            'name_ar'  => 'ريشة انسيابية',
            'font_ttf' => 'C:/Windows/Fonts/BRUSHSCI.TTF',
            'fallback' => 'C:/Windows/Fonts/MISTRAL.TTF',
            'css'      => "'Brush Script MT', cursive",
        ],
        'formal' => [
            'id'       => 'formal',
            'name_en'  => 'Formal Calligraphy',
            'name_ar'  => 'خط رسمي عريق',
            'font_ttf' => 'C:/Windows/Fonts/FRSCRIPT.TTF',
            'fallback' => 'C:/Windows/Fonts/VLADIMIR.TTF',
            'css'      => "'French Script MT', cursive",
        ],
    ];

    public function __construct(
        protected SystemBinaryDetector $detector
    ) {}

    /**
     * Sign a PDF document by placing one or more visual electronic signatures onto specified pages.
     *
     * @param string $sourcePath Path to input PDF file
     * @param string $outputPath Path to output PDF file
     * @param array<int, array> $placements Array of placement configurations:
     *        [
     *          'page' => int,                // 1-indexed page number
     *          'x_ratio' => float,           // 0.0 to 1.0 (left coordinate / page width)
     *          'y_ratio' => float,           // 0.0 to 1.0 (top coordinate / page height)
     *          'width_ratio' => float,       // 0.0 to 1.0 (signature width / page width)
     *          'height_ratio' => float,      // 0.0 to 1.0 (signature height / page height)
     *          'rotation' => int,            // 0, 90, 180, 270 degrees
     *          'signature_data' => string,   // Base64 data URI (PNG)
     *          'signature_path' => string,   // Or file path to signature image
     *          'signature_text' => string,   // Or text for typed signature
     *          'signature_style' => string,  // Optional style for typed signature
     *        ]
     * @return array
     */
    public function sign(string $sourcePath, string $outputPath, array $placements): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Input PDF not found: {$sourcePath}");
        }

        if (empty($placements)) {
            throw new InvalidArgumentException("At least one signature placement is required.");
        }

        $outDir = dirname($outputPath);
        if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
            throw new RuntimeException("Failed to create output directory: {$outDir}");
        }

        $tempFiles = [];
        $signedPagesMap = [];

        try {
            $pdf = new RotatableFpdi();
            $maxPages = $pdf->setSourceFile($sourcePath);

            if ($maxPages < 1) {
                throw new RuntimeException("PDF has no readable pages.");
            }

            // Group placements by page number (1-indexed)
            $placementsByPage = [];
            foreach ($placements as $idx => $p) {
                $pageNo = (int) ($p['page'] ?? 1);
                if ($pageNo < 1 || $pageNo > $maxPages) {
                    throw new InvalidArgumentException("Signature placement #{$idx} specifies invalid page {$pageNo}. Total pages: {$maxPages}.");
                }

                $ratios = $this->resolveRatios($p, $idx);
                $signatureImageFile = $this->resolveSignatureImage($p, $idx, $tempFiles);

                $rotation = isset($p['rotation']) ? (int) $p['rotation'] : 0;
                $rotation = (($rotation % 360) + 360) % 360;

                if ($rotation !== 0) {
                    $rotatedFile = $this->rotateTransparentImage($signatureImageFile, $rotation, $tempFiles);
                    $signatureImageFile = $rotatedFile;
                }

                $placementsByPage[$pageNo][] = [
                    'image'        => $signatureImageFile,
                    'x_ratio'      => $ratios['x_ratio'],
                    'y_ratio'      => $ratios['y_ratio'],
                    'width_ratio'  => $ratios['width_ratio'],
                    'height_ratio' => $ratios['height_ratio'],
                ];

                $signedPagesMap[$pageNo] = true;
            }

            // Process all pages, importing original vector/text templates and stamping signatures
            for ($pageNo = 1; $pageNo <= $maxPages; $pageNo++) {
                $templateId = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($templateId);

                $orientation = $size['orientation'] ?? 'P';
                $pageWidth = (float) $size['width'];
                $pageHeight = (float) $size['height'];

                $pdf->AddPage($orientation, [$pageWidth, $pageHeight]);
                $pdf->useTemplate($templateId);

                if (!empty($placementsByPage[$pageNo])) {
                    foreach ($placementsByPage[$pageNo] as $item) {
                        $imgX = $item['x_ratio'] * $pageWidth;
                        $imgY = $item['y_ratio'] * $pageHeight;
                        $imgW = $item['width_ratio'] * $pageWidth;
                        $imgH = $item['height_ratio'] * $pageHeight;

                        // Ensure dimensions are positive
                        if ($imgW <= 0 || $imgH <= 0) {
                            continue;
                        }

                        $pdf->Image($item['image'], $imgX, $imgY, $imgW, $imgH);
                    }
                }
            }

            $pdf->Output($outputPath, 'F');

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                throw new RuntimeException("Signed output PDF was not created successfully.");
            }

            return [
                'output_path'      => $outputPath,
                'total_pages'      => $maxPages,
                'signed_pages'     => array_keys($signedPagesMap),
                'placements_count' => count($placements),
                'size_bytes'       => (int) filesize($outputPath),
            ];
        } catch (Throwable $e) {
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new RuntimeException("PDF signing failed: {$e->getMessage()}", 0, $e);
        } finally {
            // Clean up temporary image files
            foreach ($tempFiles as $tf) {
                if (file_exists($tf)) {
                    @unlink($tf);
                }
            }
        }
    }

    /**
     * Resolve and validate normalized coordinate ratios from placement parameters.
     *
     * @param array $placement
     * @param int $idx
     * @return array{x_ratio: float, y_ratio: float, width_ratio: float, height_ratio: float}
     */
    public function resolveRatios(array $placement, int $idx = 0): array
    {
        if (isset($placement['x_ratio'], $placement['y_ratio'], $placement['width_ratio'], $placement['height_ratio'])) {
            $xRatio = (float) $placement['x_ratio'];
            $yRatio = (float) $placement['y_ratio'];
            $wRatio = (float) $placement['width_ratio'];
            $hRatio = (float) $placement['height_ratio'];
        } elseif (isset($placement['x'], $placement['y'], $placement['width'], $placement['height'])) {
            $containerW = (float) ($placement['container_width'] ?? 1.0);
            $containerH = (float) ($placement['container_height'] ?? 1.0);

            if ($containerW <= 0 || $containerH <= 0) {
                throw new InvalidArgumentException("Placement #{$idx} has non-positive container dimensions ({$containerW}x{$containerH}).");
            }

            $xRatio = (float) $placement['x'] / $containerW;
            $yRatio = (float) $placement['y'] / $containerH;
            $wRatio = (float) $placement['width'] / $containerW;
            $hRatio = (float) $placement['height'] / $containerH;
        } else {
            throw new InvalidArgumentException("Placement #{$idx} missing coordinate definitions.");
        }

        if (!is_finite($xRatio) || !is_finite($yRatio) || !is_finite($wRatio) || !is_finite($hRatio)) {
            throw new InvalidArgumentException("Placement #{$idx} coordinates must be finite numbers.");
        }

        if ($xRatio < 0 || $yRatio < 0 || $wRatio <= 0 || $hRatio <= 0) {
            throw new InvalidArgumentException("Placement #{$idx} coordinates and dimensions must be positive numbers.");
        }

        if ($xRatio > 1.0 || $yRatio > 1.0 || $wRatio > 1.0 || $hRatio > 1.0) {
            throw new InvalidArgumentException("Placement #{$idx} coordinates or dimensions exceed page bounds (> 1.0).");
        }

        return [
            'x_ratio'      => $xRatio,
            'y_ratio'      => $yRatio,
            'width_ratio'  => $wRatio,
            'height_ratio' => $hRatio,
        ];
    }

    /**
     * Resolve, validate, and normalize signature image to a temporary PNG file.
     *
     * @param array $placement
     * @param int $idx
     * @param array<string> &$tempFiles
     * @return string Path to temporary PNG file
     */
    protected function resolveSignatureImage(array $placement, int $idx, array &$tempFiles): string
    {
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sig_' . uniqid('', true) . '.png';

        if (!empty($placement['signature_data'])) {
            $raw = $placement['signature_data'];
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/is', $raw, $matches)) {
                $raw = $matches[2];
            }
            $binary = base64_decode($raw, true);
            if ($binary === false || strlen($binary) === 0) {
                throw new InvalidArgumentException("Placement #{$idx} contains invalid base64 signature image data.");
            }

            $im = @imagecreatefromstring($binary);
            if (!$im) {
                throw new InvalidArgumentException("Placement #{$idx} signature image data is corrupted or unsupported.");
            }

            imagealphablending($im, false);
            imagesavealpha($im, true);
            imagepng($im, $tempPath);
            imagedestroy($im);

            $tempFiles[] = $tempPath;
            return $tempPath;
        }

        if (!empty($placement['signature_path'])) {
            $sourceFile = $placement['signature_path'];
            if (!file_exists($sourceFile)) {
                throw new InvalidArgumentException("Placement #{$idx} signature image file not found: {$sourceFile}");
            }

            return $this->normalizeSignatureImage($sourceFile, $tempPath, $tempFiles);
        }

        if (!empty($placement['signature_text'])) {
            $style = (string) ($placement['signature_style'] ?? 'classic');
            $this->generateTypedSignatureImage((string) $placement['signature_text'], $tempPath, 32, '#0f172a', $style);
            $tempFiles[] = $tempPath;
            return $tempPath;
        }

        throw new InvalidArgumentException("Placement #{$idx} has no signature data, image path, or text provided.");
    }

    /**
     * Safely normalize any image (PNG, JPG, WebP) into a high-fidelity PNG with alpha transparency.
     */
    public function normalizeSignatureImage(string $sourceFile, string $destFile, array &$tempFiles = []): string
    {
        $info = @getimagesize($sourceFile);
        if (!$info) {
            throw new InvalidArgumentException("Unsupported or unreadable signature image format.");
        }

        $mime = $info['mime'] ?? '';
        $src = match ($mime) {
            'image/png'  => @imagecreatefrompng($sourceFile),
            'image/jpeg' => @imagecreatefromjpeg($sourceFile),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourceFile) : null,
            default      => null,
        };

        if (!$src) {
            $content = file_get_contents($sourceFile);
            $src = $content !== false ? @imagecreatefromstring($content) : null;
        }

        if (!$src) {
            throw new InvalidArgumentException("Failed to decode signature image from {$sourceFile}.");
        }

        $w = imagesx($src);
        $h = imagesy($src);

        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefilledrectangle($out, 0, 0, $w, $h, $transparent);
        imagealphablending($out, true);

        imagecopy($out, $src, 0, 0, 0, 0, $w, $h);
        imagedestroy($src);

        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagepng($out, $destFile);
        imagedestroy($out);

        $tempFiles[] = $destFile;
        return $destFile;
    }

    /**
     * Rotate a transparent PNG by an angle in degrees (clockwise in display) while preserving transparency.
     */
    protected function rotateTransparentImage(string $sourcePath, int $degrees, array &$tempFiles): string
    {
        $im = @imagecreatefrompng($sourcePath);
        if (!$im) {
            return $sourcePath;
        }

        $rotatedPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sig_rot_' . uniqid('', true) . '.png';

        // In GD, imagerotate uses counter-clockwise angle, so pass -$degrees for clockwise rotation
        $transColor = imagecolorallocatealpha($im, 0, 0, 0, 127);
        $rotated = imagerotate($im, -$degrees, $transColor);
        imagedestroy($im);

        if (!$rotated) {
            return $sourcePath;
        }

        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);
        imagepng($rotated, $rotatedPath);
        imagedestroy($rotated);

        $tempFiles[] = $rotatedPath;
        return $rotatedPath;
    }

    /**
     * Render a typed name as an electronic visual signature image with alpha transparency.
     */
    public function generateTypedSignatureImage(
        string $text,
        ?string $outputPath = null,
        int $fontSize = 32,
        string $colorHex = '#0f172a',
        string $style = 'classic'
    ): string {
        $cleanText = trim($text);
        if ($cleanText === '') {
            $cleanText = 'Signature';
        }

        $tempOutput = false;
        if ($outputPath === null || $outputPath === '') {
            $outputPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sig_type_' . uniqid('', true) . '.png';
            $tempOutput = true;
        }

        $fontPath = $this->getSignatureFontPath($style);

        // Extract RGB
        $hex = ltrim($colorHex, '#');
        if (strlen($hex) === 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
        } elseif (strlen($hex) >= 6) {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        } else {
            $r = 15;
            $g = 23;
            $b = 42;
        }

        if ($fontPath !== null && function_exists('imagettfbbox') && function_exists('imagettftext')) {
            $bbox = @imagettfbbox($fontSize, 0, $fontPath, $cleanText);
            if ($bbox) {
                $minX = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
                $maxX = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
                $minY = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
                $maxY = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);

                $padding = 20;
                $width = max(150, (int) ($maxX - $minX + ($padding * 2)));
                $height = max(60, (int) ($maxY - $minY + ($padding * 2)));

                $im = imagecreatetruecolor($width, $height);
                imagealphablending($im, false);
                imagesavealpha($im, true);
                $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
                imagefilledrectangle($im, 0, 0, $width, $height, $transparent);
                imagealphablending($im, true);

                $ink = imagecolorallocate($im, $r, $g, $b);
                $baseX = $padding - $minX;
                $baseY = $padding - $minY;

                imagettftext($im, $fontSize, 0, $baseX, $baseY, $ink, $fontPath, $cleanText);

                imagealphablending($im, false);
                imagesavealpha($im, true);
                imagepng($im, $outputPath);
                imagedestroy($im);

                return $outputPath;
            }
        }

        // GD built-in font fallback
        $font = 5;
        $charW = imagefontwidth($font);
        $charH = imagefontheight($font);
        $width = max(150, strlen($cleanText) * $charW + 40);
        $height = max(60, $charH + 30);

        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $transparent = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, $width, $height, $transparent);
        imagealphablending($im, true);

        $ink = imagecolorallocate($im, $r, $g, $b);
        $x = 20;
        $y = (int) (($height - $charH) / 2);
        imagestring($im, $font, $x, $y, $cleanText, $ink);

        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagepng($im, $outputPath);
        imagedestroy($im);

        return $outputPath;
    }

    /**
     * Generate base64 data URI for typed signature with the specified style.
     */
    public function generateTypedSignatureDataUri(
        string $text,
        string $style = 'classic',
        int $fontSize = 36,
        string $colorHex = '#0f172a'
    ): string {
        $tempPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sig_prev_' . uniqid('', true) . '.png';
        $this->generateTypedSignatureImage($text, $tempPath, $fontSize, $colorHex, $style);

        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        if ($content === false) {
            throw new RuntimeException("Failed to read generated signature preview.");
        }

        return 'data:image/png;base64,' . base64_encode($content);
    }

    /**
     * Find an existing system script or cursive TTF font path for a style.
     */
    public function getSignatureFontPath(string $style = 'classic'): ?string
    {
        $styleConfig = self::TYPED_STYLES[$style] ?? self::TYPED_STYLES['classic'];

        $candidates = [
            $styleConfig['font_ttf'] ?? null,
            $styleConfig['fallback'] ?? null,
            'C:\\Windows\\Fonts\\segoesc.ttf',   // Segoe Script
            'C:\\Windows\\Fonts\\segoescb.ttf',  // Segoe Script Bold
            'C:\\Windows\\Fonts\\segoepr.ttf',   // Segoe Print
            'C:\\Windows\\Fonts\\Inkfree.ttf',   // Ink Free
            'C:\\Windows\\Fonts\\BRUSHSCI.TTF',  // Brush Script
            'C:\\Windows\\Fonts\\FRSCRIPT.TTF',  // French Script
            'C:\\Windows\\Fonts\\ariali.ttf',    // Arial Italic
            'C:\\Windows\\Fonts\\arial.ttf',     // Arial
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Oblique.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Italic.ttf',
        ];

        foreach ($candidates as $font) {
            if ($font && file_exists($font)) {
                return $font;
            }
        }

        return null;
    }
}
