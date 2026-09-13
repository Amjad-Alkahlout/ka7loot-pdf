<?php

namespace Tests\Unit;

use App\Enums\CompressionLevel;
use App\Enums\ImageFit;
use App\Services\File\ArchiveGeneratorService;
use App\Services\Image\ImageCompressionService;
use App\Services\Image\ImageConversionService;
use App\Services\Image\ImageResizeService;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;
use ZipArchive;

class ImageProcessingTest extends TestCase
{
    protected string $testDir;
    protected ImageManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_project_img_test_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
        $this->manager = new ImageManager(new Driver());
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testDir);
        }
        parent::tearDown();
    }

    protected function createSampleImage(string $name, int $width = 800, int $height = 600, string $format = 'jpg'): string
    {
        $path = $this->testDir . DIRECTORY_SEPARATOR . $name;
        $image = $this->manager->create($width, $height);

        $encoded = match ($format) {
            'png' => $image->toPng(),
            'webp' => $image->toWebp(quality: 90),
            default => $image->toJpeg(quality: 95),
        };

        $encoded->save($path);
        return $path;
    }

    public function test_image_compression_reduces_size_and_reports_metrics(): void
    {
        $service = new ImageCompressionService();
        $source = $this->createSampleImage('source_large.jpg', 1200, 900);
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'compressed.jpg';

        $result = $service->compress($source, $output, CompressionLevel::EXTREME, maxWidth: 600);

        $this->assertFileExists($output);
        $this->assertLessThanOrEqual(600, $result['width']);
        $this->assertGreaterThan(0, $result['processed_size']);
        $this->assertEquals('image/jpeg', $result['mime_type']);
        $this->assertArrayHasKey('savings_percent', $result);
    }

    public function test_image_resize_scales_dimensions_correctly(): void
    {
        $service = new ImageResizeService();
        $source = $this->createSampleImage('source_resize.jpg', 1000, 500);
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'resized.jpg';

        // Half scale
        $result = $service->resize($source, $output, targetWidth: 500, targetHeight: 250, fit: ImageFit::FILL);

        $this->assertFileExists($output);
        $this->assertEquals(500, $result['width']);
        $this->assertEquals(250, $result['height']);
    }

    public function test_image_resize_contain_with_padding(): void
    {
        $service = new ImageResizeService();
        $source = $this->createSampleImage('source_contain.jpg', 800, 400);
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'contained.jpg';

        // Fit inside 400x400 square (pads with background)
        $result = $service->resize($source, $output, targetWidth: 400, targetHeight: 400, fit: ImageFit::CONTAIN);

        $this->assertFileExists($output);
        $this->assertEquals(400, $result['width']);
        $this->assertEquals(400, $result['height']);
    }

    public function test_image_format_conversion(): void
    {
        $service = new ImageConversionService();
        $source = $this->createSampleImage('source.png', 400, 300, 'png');
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'converted.webp';

        $result = $service->convert($source, $output, 'webp', 80);

        $this->assertFileExists($output);
        $this->assertEquals('webp', $result['format']);
        $this->assertEquals('image/webp', $result['mime_type']);
    }

    public function test_archive_generator_bundles_multiple_files_into_zip(): void
    {
        $service = new ArchiveGeneratorService();
        $file1 = $this->createSampleImage('image1.jpg', 200, 200);
        $file2 = $this->createSampleImage('image2.jpg', 200, 200);
        $zipOutput = $this->testDir . DIRECTORY_SEPARATOR . 'bundle.zip';

        $result = $service->createZip([
            ['path' => $file1, 'name' => 'first.jpg'],
            ['path' => $file2, 'name' => 'first.jpg'], // intentional duplicate name to test deduplication
        ], $zipOutput);

        $this->assertFileExists($zipOutput);
        $this->assertEquals(2, $result['files_count']);
        $this->assertGreaterThan(0, $result['size_bytes']);

        // Inspect zip content
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipOutput));
        $this->assertEquals(2, $zip->numFiles);
        $this->assertEquals('first.jpg', $zip->getNameIndex(0));
        $this->assertEquals('first_1.jpg', $zip->getNameIndex(1));
        $zip->close();
    }
}
