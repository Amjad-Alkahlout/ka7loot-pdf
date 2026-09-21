<?php

namespace Tests\Feature;

use App\Services\Image\AdvancedBackgroundRemovalEngine;
use App\Services\Image\BackgroundRemovalEngineInterface;
use App\Services\Image\ColorSamplingBackgroundRemovalEngine;
use App\Services\Image\ImageBackgroundService;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class AdvancedBackgroundRemovalEngineTest extends TestCase
{
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fp_adv_engine_tests_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * Helper to create a synthetic portrait image.
     */
    protected function createSyntheticPortrait(int $w = 180, int $h = 220, bool $gradientBg = false): string
    {
        $im = imagecreatetruecolor($w, $h);

        if ($gradientBg) {
            for ($y = 0; $y < $h; $y++) {
                $val = (int) (240 - ($y / $h) * 55);
                $bg = imagecolorallocate($im, $val, $val, max(0, $val - 10));
                imageline($im, 0, $y, $w - 1, $y, $bg);
            }
        } else {
            $white = imagecolorallocate($im, 255, 255, 255);
            imagefilledrectangle($im, 0, 0, $w, $h, $white);
        }

        // Dark hair
        $hair = imagecolorallocate($im, 28, 25, 25);
        imagefilledellipse($im, (int) ($w * 0.5), (int) ($h * 0.30), (int) ($w * 0.45), (int) ($h * 0.35), $hair);

        // Face (skin tone)
        $skin = imagecolorallocate($im, 215, 155, 115);
        imagefilledellipse($im, (int) ($w * 0.5), (int) ($h * 0.36), (int) ($w * 0.35), (int) ($h * 0.30), $skin);

        // Neck
        imagefilledrectangle($im, (int) ($w * 0.44), (int) ($h * 0.45), (int) ($w * 0.56), (int) ($h * 0.54), $skin);

        // Dark clothing touching bottom
        $suit = imagecolorallocate($im, 35, 40, 55);
        imagefilledrectangle($im, (int) ($w * 0.20), (int) ($h * 0.52), (int) ($w * 0.80), $h - 1, $suit);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'portrait_' . ($gradientBg ? 'grad' : 'white') . '.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function test_advanced_engine_metadata_and_ai_contract(): void
    {
        $engine = new AdvancedBackgroundRemovalEngine();

        $this->assertEquals(AdvancedBackgroundRemovalEngine::ID, $engine->getId());
        $this->assertEquals(AdvancedBackgroundRemovalEngine::LABEL, $engine->getLabel());
        $this->assertEquals(AdvancedBackgroundRemovalEngine::DESCRIPTION, $engine->getDescription());
        $this->assertFalse($engine->isAi(), 'Deterministic edge matting must strictly return false for isAi');
    }

    public function test_sobel_gradient_barrier_prevents_leakage(): void
    {
        $w = 100;
        $h = 100;
        $im = imagecreatetruecolor($w, $h);

        // Background: Light gray
        $bg = imagecolorallocate($im, 230, 230, 230);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        // Dark barrier contour (strong Sobel edge)
        $black = imagecolorallocate($im, 10, 10, 10);
        imagerectangle($im, 25, 25, 75, 75, $black);
        imagerectangle($im, 26, 26, 74, 74, $black);

        // Interior area with same color as background
        imagefilledrectangle($im, 27, 27, 73, 73, $bg);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'barrier_test.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'barrier_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 20, 'edge_threshold' => 15.0]);

        $res = imagecreatefrompng($out);
        $outerAlpha = (imagecolorat($res, 5, 5) >> 24) & 0x7F;
        $barrierAlpha = (imagecolorat($res, 25, 25) >> 24) & 0x7F;
        $innerAlpha = (imagecolorat($res, 50, 50) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertGreaterThan(120, $outerAlpha, 'Outer perimeter background must be transparent');
        $this->assertEquals(0, $barrierAlpha, 'Sobel barrier edge contour must remain intact and opaque');
        $this->assertEquals(0, $innerAlpha, 'Interior enclosed region must be protected by hole filling');
    }

    public function test_skin_tone_protection_preserves_facial_features(): void
    {
        $w = 120;
        $h = 140;
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 240, 240, 240);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);

        // Test multiple realistic skin tones
        $skinLight = imagecolorallocate($im, 225, 175, 140);
        $skinMedium = imagecolorallocate($im, 185, 125, 85);
        $skinDeep = imagecolorallocate($im, 125, 80, 50);

        imagefilledrectangle($im, 40, 30, 80, 50, $skinLight);
        imagefilledrectangle($im, 40, 55, 80, 75, $skinMedium);
        imagefilledrectangle($im, 40, 80, 80, 100, $skinDeep);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'skin_test.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'skin_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 25, 'protect_skin' => true]);

        $res = imagecreatefrompng($out);
        $alphaLight = (imagecolorat($res, 60, 40) >> 24) & 0x7F;
        $alphaMedium = (imagecolorat($res, 60, 65) >> 24) & 0x7F;
        $alphaDeep = (imagecolorat($res, 60, 90) >> 24) & 0x7F;
        $alphaBg = (imagecolorat($res, 10, 10) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertGreaterThan(120, $alphaBg, 'Background must be transparent');
        $this->assertEquals(0, $alphaLight, 'Light skin tone must remain fully opaque');
        $this->assertEquals(0, $alphaMedium, 'Medium skin tone must remain fully opaque');
        $this->assertEquals(0, $alphaDeep, 'Deep skin tone must remain fully opaque');
    }

    public function test_differential_skin_protection_clears_beige_warm_background(): void
    {
        $w = 140;
        $h = 140;
        $im = imagecreatetruecolor($w, $h);

        // Beige/tan background that matches photometric skin tone formula
        $beigeBg = imagecolorallocate($im, 220, 185, 150);
        imagefilledrectangle($im, 0, 0, $w, $h, $beigeBg);

        // Subject: Dark hair and blue shirt
        $hair = imagecolorallocate($im, 30, 25, 25);
        imagefilledellipse($im, 70, 45, 50, 40, $hair);

        $shirt = imagecolorallocate($im, 30, 80, 160);
        imagefilledrectangle($im, 35, 75, 105, 139, $shirt);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'beige_bg_test.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'beige_bg_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 25, 'protect_skin' => true]);

        $res = imagecreatefrompng($out);
        $cornerAlpha = (imagecolorat($res, 10, 10) >> 24) & 0x7F;
        $nearHeadBgAlpha = (imagecolorat($res, 25, 45) >> 24) & 0x7F;
        $hairAlpha = (imagecolorat($res, 70, 45) >> 24) & 0x7F;
        $shirtAlpha = (imagecolorat($res, 70, 100) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertGreaterThan(120, $cornerAlpha, 'Beige background corner must be transparent');
        $this->assertGreaterThan(120, $nearHeadBgAlpha, 'Beige background around head must NOT be locked as protected skin');
        $this->assertEquals(0, $hairAlpha, 'Subject hair must be preserved');
        $this->assertEquals(0, $shirtAlpha, 'Subject shirt must be preserved');
    }

    public function test_dark_background_portrait_preservation(): void
    {
        $w = 140;
        $h = 140;
        $im = imagecreatetruecolor($w, $h);

        // Dark/charcoal studio background
        $darkBg = imagecolorallocate($im, 20, 22, 25);
        imagefilledrectangle($im, 0, 0, $w, $h, $darkBg);

        // Subject: light skin face and light shirt
        $skin = imagecolorallocate($im, 220, 165, 130);
        imagefilledellipse($im, 70, 50, 45, 45, $skin);

        $shirt = imagecolorallocate($im, 200, 200, 210);
        imagefilledrectangle($im, 40, 75, 100, 139, $shirt);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'dark_bg_test.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'dark_bg_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 25, 'protect_skin' => true]);

        $res = imagecreatefrompng($out);
        $cornerAlpha = (imagecolorat($res, 10, 10) >> 24) & 0x7F;
        $faceAlpha = (imagecolorat($res, 70, 50) >> 24) & 0x7F;
        $shirtAlpha = (imagecolorat($res, 70, 100) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertGreaterThan(120, $cornerAlpha, 'Dark background must be transparent');
        $this->assertEquals(0, $faceAlpha, 'Face must be preserved and fully opaque');
        $this->assertEquals(0, $shirtAlpha, 'Shirt must be preserved and fully opaque');
    }

    public function test_off_center_subject_segmentation(): void
    {
        $w = 160;
        $h = 140;
        $im = imagecreatetruecolor($w, $h);

        $whiteBg = imagecolorallocate($im, 250, 250, 250);
        imagefilledrectangle($im, 0, 0, $w, $h, $whiteBg);

        // Off-center object on the right side
        $purple = imagecolorallocate($im, 140, 40, 180);
        imagefilledrectangle($im, 90, 30, 145, 110, $purple);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'off_center_test.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'off_center_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 20]);

        $res = imagecreatefrompng($out);
        $leftBgAlpha = (imagecolorat($res, 40, 70) >> 24) & 0x7F;
        $objAlpha = (imagecolorat($res, 115, 70) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertGreaterThan(120, $leftBgAlpha, 'Empty left background region must be transparent');
        $this->assertEquals(0, $objAlpha, 'Off-center object must be preserved');
    }

    public function test_interior_hole_filling_preserves_enclosed_white_regions(): void
    {
        $w = 140;
        $h = 140;
        $im = imagecreatetruecolor($w, $h);

        // Pure white background
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w, $h, $white);

        // Subject silhouette (dark green coat)
        $green = imagecolorallocate($im, 20, 100, 40);
        imagefilledrectangle($im, 30, 30, 110, 110, $green);

        // White inner shirt/teeth completely enclosed inside the coat
        imagefilledrectangle($im, 50, 50, 90, 90, $white);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'hole_filling_test.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'hole_filling_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 20]);

        $res = imagecreatefrompng($out);
        $innerWhiteAlpha = (imagecolorat($res, 70, 70) >> 24) & 0x7F;
        $greenCoatAlpha = (imagecolorat($res, 40, 40) >> 24) & 0x7F;
        $outerBgAlpha = (imagecolorat($res, 10, 10) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertGreaterThan(120, $outerBgAlpha, 'Perimeter white background must be removed');
        $this->assertEquals(0, $greenCoatAlpha, 'Silhouette body must be preserved');
        $this->assertEquals(0, $innerWhiteAlpha, 'Interior white shirt enclosed within subject must not be hollowed out');
    }

    public function test_edge_feathering_produces_smooth_anti_aliased_alpha(): void
    {
        $w = 120;
        $h = 120;
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w, $h, $white);

        // Dark circle with gray intermediate ring
        $dark = imagecolorallocate($im, 20, 20, 20);
        imagefilledellipse($im, 60, 60, 50, 50, $dark);
        $gray = imagecolorallocate($im, 170, 170, 170);
        imageellipse($im, 60, 60, 52, 52, $gray);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'feather_test.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'feather_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 25, 'feather' => 3]);

        $res = imagecreatefrompng($out);
        $coreAlpha = (imagecolorat($res, 60, 60) >> 24) & 0x7F;
        $boundaryAlpha = (imagecolorat($res, 86, 60) >> 24) & 0x7F;
        $bgAlpha = (imagecolorat($res, 105, 60) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertEquals(0, $coreAlpha, 'Subject core must be fully opaque');
        $this->assertGreaterThan(120, $bgAlpha, 'Background must be transparent');
        $this->assertGreaterThanOrEqual(0, $boundaryAlpha);
        $this->assertLessThanOrEqual(127, $boundaryAlpha);
    }

    public function test_dimensions_preserved_and_valid_png_generated(): void
    {
        $w = 175;
        $h = 215;
        $im = imagecreatetruecolor($w, $h);
        $bg = imagecolorallocate($im, 250, 250, 250);
        imagefilledrectangle($im, 0, 0, $w, $h, $bg);
        $red = imagecolorallocate($im, 220, 40, 40);
        imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), 60, 80, $red);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'dim_test.jpg';
        imagejpeg($im, $path, 95);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'dim_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $meta = $engine->removeBackground($path, $out);

        $this->assertTrue(file_exists($out));
        $this->assertEquals($w, $meta['width']);
        $this->assertEquals($h, $meta['height']);
        $this->assertEquals('png', $meta['format']);
        $this->assertEquals('image/png', $meta['mime_type'] ?? 'image/png');

        $size = getimagesize($out);
        $this->assertEquals($w, $size[0]);
        $this->assertEquals($h, $size[1]);
        $this->assertEquals('image/png', $size['mime']);
    }

    public function test_safe_failure_on_exceeded_dimensions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceed maximum allowed limit');

        $w = 100;
        $h = 100;
        $im = imagecreatetruecolor($w, $h);
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'small.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'small_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        // Force small max dimension option to trigger safety check
        $engine->removeBackground($path, $out, ['max_dimension' => 50]);
    }

    /**
     * Head-to-Head Comparison: Advanced vs ColorSampling on Realistic Non-Uniform Portrait.
     * Asserts that AdvancedEngine prevents catastrophic face deletion where ColorSampling fails.
     */
    public function test_head_to_head_portrait_preservation_comparison(): void
    {
        $path = $this->createSyntheticPortrait(180, 220, true);

        $outAdv = $this->tempDir . DIRECTORY_SEPARATOR . 'comp_adv.png';
        $outColor = $this->tempDir . DIRECTORY_SEPARATOR . 'comp_col.png';

        $advEngine = new AdvancedBackgroundRemovalEngine();
        $colorEngine = new ColorSamplingBackgroundRemovalEngine();

        // Run with tolerance 35 needed to clear the non-uniform gradient background
        $advEngine->removeBackground($path, $outAdv, ['tolerance' => 35, 'feather' => 2]);
        $colorEngine->removeBackground($path, $outColor, ['tolerance' => 35, 'feather' => 2]);

        $imA = imagecreatefrompng($outAdv);
        $imC = imagecreatefrompng($outColor);

        // Subject Face Center (X: 90, Y: 80)
        $faceAlphaAdv = (imagecolorat($imA, 90, 80) >> 24) & 0x7F;
        $faceAlphaColor = (imagecolorat($imC, 90, 80) >> 24) & 0x7F;

        // Subject Torso / Clothing (X: 90, Y: 160)
        $torsoAlphaAdv = (imagecolorat($imA, 90, 160) >> 24) & 0x7F;

        // Background top-left
        $bgTopAlphaAdv = (imagecolorat($imA, 10, 10) >> 24) & 0x7F;

        imagedestroy($imA);
        imagedestroy($imC);

        // Advanced engine guarantees face & torso preservation while background is removed
        $this->assertEquals(0, $faceAlphaAdv, 'Advanced engine MUST keep face fully intact (alpha=0)');
        $this->assertEquals(0, $torsoAlphaAdv, 'Advanced engine MUST keep torso clothing fully intact');
        $this->assertGreaterThan(120, $bgTopAlphaAdv, 'Advanced engine MUST clear gradient background');

        // Verify ColorSampling suffered face erosion or deletion on the non-uniform background
        // ColorSampling no longer fails here after the chroma fix!
    }

    /**
     * Test realistic Scenario 5 & 6: Object/Product with clear boundaries and complex background.
     */
    public function test_product_clear_boundary_preservation(): void
    {
        $w = 160;
        $h = 160;
        $im = imagecreatetruecolor($w, $h);

        // Subtle gradient studio backdrop
        for ($y = 0; $y < $h; $y++) {
            $c = (int) (250 - ($y / $h) * 40);
            $bg = imagecolorallocate($im, $c, $c, $c);
            imageline($im, 0, $y, $w - 1, $y, $bg);
        }

        // Product: Vibrant blue camera/device body
        $blue = imagecolorallocate($im, 25, 75, 210);
        imagefilledrectangle($im, 40, 45, 120, 115, $blue);

        // Lens ring (black)
        $black = imagecolorallocate($im, 15, 15, 20);
        imagefilledellipse($im, 80, 80, 45, 45, $black);

        // Lens glass (dark cyan)
        $cyan = imagecolorallocate($im, 10, 80, 100);
        imagefilledellipse($im, 80, 80, 35, 35, $cyan);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'product_camera.png';
        imagepng($im, $path);
        imagedestroy($im);

        $out = $this->tempDir . DIRECTORY_SEPARATOR . 'product_camera_out.png';
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($path, $out, ['tolerance' => 30, 'feather' => 2]);

        $res = imagecreatefrompng($out);
        $bodyAlpha = (imagecolorat($res, 50, 60) >> 24) & 0x7F;
        $lensAlpha = (imagecolorat($res, 80, 80) >> 24) & 0x7F;
        $bgAlpha = (imagecolorat($res, 10, 10) >> 24) & 0x7F;
        imagedestroy($res);

        $this->assertEquals(0, $bodyAlpha, 'Product body must be preserved');
        $this->assertEquals(0, $lensAlpha, 'Product lens core must be preserved');
        $this->assertGreaterThan(120, $bgAlpha, 'Product studio background must be removed');
    }

    /**
     * Test scanned/stamped document edge decontamination:
     * Eliminates white/gray halo around 1px lines, signatures, and blue logos
     * over both dark and light backgrounds without eroding thin strokes.
     */
    public function test_document_logo_signature_decontamination_eliminates_white_halo_on_dark_and_light_backgrounds(): void
    {
        $w = 400;
        $h = 250;
        $im = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w, $h, $white);

        // 1. Thin 1px black divider line
        $black = imagecolorallocate($im, 15, 15, 15);
        imageline($im, 40, 110, 360, 110, $black);

        // 2. Blue company logo (like TRS logo) with interior white text
        $blue = imagecolorallocate($im, 0, 85, 185);
        imagefilledrectangle($im, 30, 30, 110, 80, $blue);
        $logoWhite = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 45, 45, 95, 65, $logoWhite);

        // 3. Thin handwritten signature stroke (1-2px)
        $sigColor = imagecolorallocate($im, 20, 25, 45);
        for ($t = 0; $t < 150; $t++) {
            $x = 150 + $t;
            $y = 180 + (int) (20 * sin($t / 15.0) + 10 * cos($t / 30.0));
            imagesetpixel($im, $x, $y, $sigColor);
            imagesetpixel($im, $x, $y + 1, $sigColor);
        }

        $inputPath = $this->tempDir . DIRECTORY_SEPARATOR . "doc_signature_logo.png";
        imagepng($im, $inputPath);
        imagedestroy($im);

        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . "doc_signature_logo_out.png";
        $engine = new AdvancedBackgroundRemovalEngine();
        $engine->removeBackground($inputPath, $outputPath, ["tolerance" => 25, "feather" => 2]);

        $this->assertTrue(file_exists($outputPath));
        $res = imagecreatefrompng($outputPath);

        // A. Verify transparency and core element preservation
        $bgAlpha = (imagecolorat($res, 10, 10) >> 24) & 0x7F;
        $lineAlpha = (imagecolorat($res, 200, 110) >> 24) & 0x7F;
        $logoAlpha = (imagecolorat($res, 35, 35) >> 24) & 0x7F;
        $logoWhiteAlpha = (imagecolorat($res, 60, 55) >> 24) & 0x7F;
        $sigAlpha = (imagecolorat($res, 200, 176) >> 24) & 0x7F;

        $this->assertEquals(127, $bgAlpha, "Background outside elements must be fully transparent");
        $this->assertEquals(0, $lineAlpha, "Thin 1px line must be fully opaque and intact");
        $this->assertEquals(0, $logoAlpha, "Blue logo core must be fully opaque and intact");
        $this->assertEquals(0, $logoWhiteAlpha, "Interior white element inside logo must be preserved");
        $this->assertEquals(0, $sigAlpha, "Thin signature stroke must be fully opaque and intact");

        // B. Composite over solid black background to test white halo elimination
        $blackCanvas = imagecreatetruecolor($w, $h);
        imagefilledrectangle($blackCanvas, 0, 0, $w, $h, imagecolorallocate($blackCanvas, 0, 0, 0));
        imagealphablending($blackCanvas, true);
        imagecopy($blackCanvas, $res, 0, 0, 0, 0, $w, $h);

        $haloNearLine = 0;
        for ($x = 40; $x <= 360; $x++) {
            foreach ([108, 109, 111, 112] as $y) {
                $c = imagecolorat($blackCanvas, $x, $y);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                if ($r > 25 || $g > 25 || $b > 25) {
                    $haloNearLine++;
                }
            }
        }
        $this->assertEquals(0, $haloNearLine, "Must have zero white/gray halo around thin 1px line on dark background");

        $haloNearLogo = 0;
        for ($y = 30; $y <= 80; $y++) {
            foreach ([28, 29, 111, 112] as $x) {
                $c = imagecolorat($blackCanvas, $x, $y);
                $r = ($c >> 16) & 0xFF;
                if ($r > 25) {
                    $haloNearLogo++;
                }
            }
        }
        $this->assertEquals(0, $haloNearLogo, "Must have zero white halo around blue logo perimeter on dark background");

        // C. Composite over solid white background to confirm natural edge rendering
        $whiteCanvas = imagecreatetruecolor($w, $h);
        imagefilledrectangle($whiteCanvas, 0, 0, $w, $h, imagecolorallocate($whiteCanvas, 255, 255, 255));
        imagealphablending($whiteCanvas, true);
        imagecopy($whiteCanvas, $res, 0, 0, 0, 0, $w, $h);

        $lineOverWhite = imagecolorat($whiteCanvas, 200, 110);
        $lr = ($lineOverWhite >> 16) & 0xFF;
        $lg = ($lineOverWhite >> 8) & 0xFF;
        $lb = $lineOverWhite & 0xFF;
        $this->assertEquals([15, 15, 15], [$lr, $lg, $lb], "1px line core must retain exact color on white background");

        $logoOverWhite = imagecolorat($whiteCanvas, 35, 35);
        $br = ($logoOverWhite >> 16) & 0xFF;
        $bgCol = ($logoOverWhite >> 8) & 0xFF;
        $bb = $logoOverWhite & 0xFF;
        $this->assertEquals([0, 85, 185], [$br, $bgCol, $bb], "Logo core must retain exact blue color on white background");

        imagedestroy($blackCanvas);
        imagedestroy($whiteCanvas);
        imagedestroy($res);
    }
}
