<?php

namespace Tests\Feature;

use App\Enums\CompressionLevel;
use App\Services\Image\ImageCompressionService;
use App\Services\Image\ImageTargetSizeCompressionService;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

class ImageCompressionFunctionalTest extends TestCase
{
    protected string $tmpDir;
    protected ImageManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_img_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->manager = new ImageManager(new Driver());
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Create a realistic multi-color test image.
     */
    protected function createTestImage(string $path, int $width, int $height, string $format = 'jpeg'): string
    {
        $gd = imagecreatetruecolor($width, $height);
        // Draw colorful gradients and noise to create measurable byte weight
        for ($x = 0; $x < $width; $x += 4) {
            for ($y = 0; $y < $height; $y += 4) {
                $color = imagecolorallocate($gd, ($x * 2) % 255, ($y * 3) % 255, (($x + $y) * 4) % 255);
                imagefilledrectangle($gd, $x, $y, $x + 3, $y + 3, $color);
            }
        }

        if ($format === 'png') {
            imagepng($gd, $path);
        } elseif ($format === 'webp') {
            imagewebp($gd, $path, 95);
        } else {
            imagejpeg($gd, $path, 95);
        }
        imagedestroy($gd);

        return $path;
    }

    public function test_jpeg_preset_compression_reduces_size_and_reports_gd_engine(): void
    {
        $src = $this->createTestImage($this->tmpDir . '/source.jpg', 800, 600, 'jpeg');
        $out = $this->tmpDir . '/output_preset.jpg';

        $service = new ImageCompressionService();
        $result = $service->compress($src, $out, CompressionLevel::EXTREME);

        $this->assertFileExists($out);
        $this->assertSame('gd', $result['engine']);
        $this->assertStringContainsString('GD', $result['engine_label']);
        $this->assertStringNotContainsStringIgnoringCase('ghostscript', $result['engine_label']);
        $this->assertLessThanOrEqual($result['original_size'], $result['processed_size']);
        $this->assertSame(800, $result['width']);
        $this->assertSame(600, $result['height']);
    }

    public function test_png_and_webp_preset_compression_characteristics(): void
    {
        $srcPng = $this->createTestImage($this->tmpDir . '/source.png', 400, 400, 'png');
        $outPng = $this->tmpDir . '/output.png';

        $srcWebp = $this->createTestImage($this->tmpDir . '/source.webp', 400, 400, 'webp');
        $outWebp = $this->tmpDir . '/output.webp';

        $service = new ImageCompressionService();

        $resPng = $service->compress($srcPng, $outPng, CompressionLevel::EXTREME);
        $this->assertFileExists($outPng);
        $this->assertSame('gd', $resPng['engine']);

        $resWebp = $service->compress($srcWebp, $outWebp, CompressionLevel::RECOMMENDED);
        $this->assertFileExists($outWebp);
        $this->assertSame('gd', $resWebp['engine']);
    }

    public function test_target_size_compression_achieves_target_by_reducing_quality_first(): void
    {
        $src = $this->createTestImage($this->tmpDir . '/source_target.jpg', 1000, 800, 'jpeg');
        $out = $this->tmpDir . '/output_target.jpg';

        $origSize = filesize($src);
        // Request realistic target size: half of original
        $targetBytes = (int) round($origSize * 0.5);

        $service = new ImageTargetSizeCompressionService();
        $res = $service->compressToTarget($src, $out, $targetBytes, 'balanced');

        $this->assertFileExists($out);
        $this->assertLessThanOrEqual($targetBytes, $res['processed_size']);
        $this->assertSame('achieved', $res['target_status']);
        $this->assertTrue($res['target_achieved']);
        $this->assertSame('gd', $res['engine']);
        $this->assertArrayHasKey('diagnostics', $res);
        $this->assertNotEmpty($res['diagnostics']['attempts']);
    }

    public function test_target_size_compression_scales_dimensions_when_quality_reaches_floor(): void
    {
        $src = $this->createTestImage($this->tmpDir . '/source_scale.jpg', 1200, 900, 'jpeg');
        $out = $this->tmpDir . '/output_scale.jpg';

        $origSize = filesize($src);
        // Request aggressive target (3% of original) that cannot be achieved
        // by quality reduction alone on any platform/libjpeg implementation,
        // forcing the algorithm to progress into dimension downscaling once quality floor is reached.
        $targetBytes = max(3000, (int) round($origSize * 0.03));

        $service = new ImageTargetSizeCompressionService();
        $res = $service->compressToTarget($src, $out, $targetBytes, 'smallest');

        $this->assertFileExists($out);
        $this->assertLessThanOrEqual($origSize, $res['processed_size']);
        $this->assertArrayHasKey('diagnostics', $res);

        // Verify that dimension downscaling was used when necessary
        $hasDimensionAttempt = false;
        foreach ($res['diagnostics']['attempts'] as $att) {
            if ($att['phase'] === 'dimension_downscaling') {
                $hasDimensionAttempt = true;
                break;
            }
        }
        $this->assertTrue($hasDimensionAttempt, "Expected dimension downscaling attempts for aggressive target size.");
        $this->assertTrue($res['dimension_changed']);
        $this->assertLessThan(1200, $res['dimensions']['width']);
        $this->assertLessThan(900, $res['dimensions']['height']);
    }

    public function test_target_size_compression_truthfully_reports_unachievable_target(): void
    {
        $src = $this->createTestImage($this->tmpDir . '/source_impossible.jpg', 800, 600, 'jpeg');
        $out = $this->tmpDir . '/output_impossible.jpg';

        // An impossible target: 50 bytes for a full image
        $impossibleTargetBytes = 50;

        $service = new ImageTargetSizeCompressionService();
        $res = $service->compressToTarget($src, $out, $impossibleTargetBytes, 'max_quality');

        $this->assertFileExists($out);
        // Crucial requirement: Do NOT claim target achieved unless processed_size <= target_size
        $this->assertFalse($res['target_achieved']);
        $this->assertSame('not_achieved', $res['target_status']);
        $this->assertNotNull($res['explanation']);
        $this->assertStringContainsString('could not be', $res['explanation']);
        $this->assertGreaterThan($impossibleTargetBytes, $res['processed_size']);
    }
}
