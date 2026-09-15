<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Livewire\Tools\ChangeImageBackgroundComponent;
use App\Livewire\Tools\RemoveImageBackgroundComponent;
use App\Models\ProcessedFile;
use App\Services\Image\AdvancedBackgroundRemovalEngine;
use App\Services\Image\BackgroundRemovalEngineInterface;
use App\Services\Image\ColorSamplingBackgroundRemovalEngine;
use App\Services\Image\HybridBackgroundRemovalEngine;
use App\Services\Image\ImageBackgroundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ImageBackgroundRemovalAndChangeTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bg_test_' . uniqid();
        @mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $files = glob($this->tempDir . DIRECTORY_SEPARATOR . '*');
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * Create a synthetic image with white background and a red circle in the center.
     */
    protected function createSampleSubjectImage(int $width = 100, int $height = 100, string $format = 'png'): string
    {
        $im = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $width, $height, $white);

        $red = imagecolorallocate($im, 220, 30, 30);
        imagefilledellipse($im, (int)($width / 2), (int)($height / 2), (int)($width * 0.6), (int)($height * 0.6), $red);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . "sample_subject.{$format}";
        if ($format === 'jpg' || $format === 'jpeg') {
            imagejpeg($im, $path, 95);
        } elseif ($format === 'webp') {
            imagewebp($im, $path, 95);
        } else {
            imagepng($im, $path);
        }
        imagedestroy($im);

        return $path;
    }

    /**
     * Create a synthetic image with a white subject feature (like white teeth/shirt)
     * inside a dark boundary, on a white background.
     */
    protected function createSampleWithInteriorWhiteImage(): string
    {
        $w = 120;
        $h = 120;
        $im = imagecreatetruecolor($w, $h);

        // White background
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $w, $h, $white);

        // Blue outer body (subject)
        $blue = imagecolorallocate($im, 30, 60, 200);
        imagefilledrectangle($im, 30, 30, 90, 90, $blue);

        // White inner shirt/teeth completely enclosed inside the blue subject
        imagefilledrectangle($im, 45, 45, 75, 75, $white);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'subject_with_white_interior.png';
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function test_modular_engine_interface_contract(): void
    {
        // Default configured production engine is HybridBackgroundRemovalEngine
        $defaultEngine = app(BackgroundRemovalEngineInterface::class);
        $this->assertInstanceOf(HybridBackgroundRemovalEngine::class, $defaultEngine);
        $this->assertEquals('hybrid', $defaultEngine->getId());
        $this->assertFalse($defaultEngine->isAi());
        $this->assertNotEmpty($defaultEngine->getLabel());
        $this->assertNotEmpty($defaultEngine->getDescription());

        // Advanced engine remains available and selectable via config
        config(['fileprocessor.background_removal.engine' => 'advanced']);
        $advancedEngine = app(BackgroundRemovalEngineInterface::class);
        $this->assertInstanceOf(AdvancedBackgroundRemovalEngine::class, $advancedEngine);
        $this->assertEquals(AdvancedBackgroundRemovalEngine::ID, $advancedEngine->getId());
        $this->assertFalse($advancedEngine->isAi());

        // ColorSampling remains available and selectable via config
        config(['fileprocessor.background_removal.engine' => 'color_sampling']);
        $fallbackEngine = app(BackgroundRemovalEngineInterface::class);
        $this->assertInstanceOf(ColorSamplingBackgroundRemovalEngine::class, $fallbackEngine);
        $this->assertEquals(ColorSamplingBackgroundRemovalEngine::ID, $fallbackEngine->getId());
        $this->assertFalse($fallbackEngine->isAi());

        // Reset config back to hybrid
        config(['fileprocessor.background_removal.engine' => 'hybrid']);
    }

    public function test_remove_background_with_white_background_produces_transparent_png(): void
    {
        $inputPath = $this->createSampleSubjectImage(80, 80, 'png');
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'output_transparent.png';

        $service = app(ImageBackgroundService::class);
        $result = $service->removeBackground($inputPath, $outputPath, [
            'tolerance' => 20,
            'feather'   => 1,
            'flood_fill'=> true,
        ]);

        $this->assertFileExists($outputPath);
        $this->assertEquals(80, $result['width']);
        $this->assertEquals(80, $result['height']);
        $this->assertFalse($result['is_ai']);

        // Inspect output pixels
        $im = imagecreatefrompng($outputPath);
        $this->assertNotFalse($im);

        // Corner pixel (0, 0) should be transparent (GD alpha > 100)
        $cornerRgba = imagecolorat($im, 2, 2);
        $cornerAlpha = ($cornerRgba >> 24) & 0x7F;
        $this->assertGreaterThan(100, $cornerAlpha, 'Corner pixel must be transparent');

        // Center pixel (40, 40) should be opaque red (GD alpha == 0)
        $centerRgba = imagecolorat($im, 40, 40);
        $centerAlpha = ($centerRgba >> 24) & 0x7F;
        $centerR = ($centerRgba >> 16) & 0xFF;
        $this->assertEquals(0, $centerAlpha, 'Center subject pixel must be opaque');
        $this->assertGreaterThan(180, $centerR, 'Center subject pixel must be reddish');

        imagedestroy($im);
    }

    public function test_remove_background_preserves_interior_matching_pixels_via_flood_fill(): void
    {
        $inputPath = $this->createSampleWithInteriorWhiteImage();
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'flood_output.png';

        $service = app(ImageBackgroundService::class);
        $service->removeBackground($inputPath, $outputPath, [
            'tolerance'  => 15,
            'feather'    => 1,
            'flood_fill' => true,
        ]);

        $this->assertFileExists($outputPath);
        $im = imagecreatefrompng($outputPath);

        // Outer corner should be transparent
        $outerAlpha = (imagecolorat($im, 5, 5) >> 24) & 0x7F;
        $this->assertGreaterThan(100, $outerAlpha, 'Outer corner must be transparent');

        // Outer body (blue) should be opaque
        $bodyAlpha = (imagecolorat($im, 35, 35) >> 24) & 0x7F;
        $this->assertEquals(0, $bodyAlpha, 'Subject body must remain opaque');

        // Inner white square (60, 60) must be PRESERVED and OPAQUE!
        $innerColor = imagecolorat($im, 60, 60);
        $innerAlpha = ($innerColor >> 24) & 0x7F;
        $innerR = ($innerColor >> 16) & 0xFF;
        $innerG = ($innerColor >> 8) & 0xFF;
        $innerB = $innerColor & 0xFF;

        $this->assertEquals(0, $innerAlpha, 'Enclosed interior white pixel must not be erased');
        $this->assertGreaterThan(240, $innerR);
        $this->assertGreaterThan(240, $innerG);
        $this->assertGreaterThan(240, $innerB);

        imagedestroy($im);
    }

    public function test_change_background_to_solid_color(): void
    {
        $inputPath = $this->createSampleSubjectImage(60, 60, 'jpg');
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'solid_output.png';

        $service = app(ImageBackgroundService::class);
        $result = $service->changeBackground($inputPath, $outputPath, [
            'type'  => 'solid',
            'color' => '#00FF00', // Solid green
        ], [
            'tolerance' => 25,
            'feather'   => 1,
        ]);

        $this->assertFileExists($outputPath);
        $this->assertEquals(60, $result['width']);
        $this->assertEquals(60, $result['height']);

        $im = imagecreatefrompng($outputPath);
        $cornerColor = imagecolorat($im, 2, 2);
        $cornerR = ($cornerColor >> 16) & 0xFF;
        $cornerG = ($cornerColor >> 8) & 0xFF;
        $cornerB = $cornerColor & 0xFF;

        // Background replaced with green
        $this->assertLessThan(40, $cornerR);
        $this->assertGreaterThan(200, $cornerG);
        $this->assertLessThan(40, $cornerB);

        // Subject in center remains reddish
        $centerColor = imagecolorat($im, 30, 30);
        $centerR = ($centerColor >> 16) & 0xFF;
        $this->assertGreaterThan(160, $centerR);

        imagedestroy($im);
    }

    public function test_change_background_to_gradient(): void
    {
        $inputPath = $this->createSampleSubjectImage(50, 50, 'png');
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'gradient_output.png';

        $service = app(ImageBackgroundService::class);
        $result = $service->changeBackground($inputPath, $outputPath, [
            'type'        => 'gradient',
            'color_start' => '#4F46E5',
            'color_end'   => '#06B6D4',
            'direction'   => 'vertical',
        ]);

        $this->assertFileExists($outputPath);
        $this->assertEquals(50, $result['width']);
        $this->assertEquals(50, $result['height']);
    }

    public function test_change_background_to_uploaded_image_with_cover(): void
    {
        $inputPath = $this->createSampleSubjectImage(60, 60, 'png');

        // Create a 100x80 background image
        $bgIm = imagecreatetruecolor(100, 80);
        $gold = imagecolorallocate($bgIm, 255, 215, 0);
        imagefilledrectangle($bgIm, 0, 0, 100, 80, $gold);
        $bgPath = $this->tempDir . DIRECTORY_SEPARATOR . 'custom_bg.png';
        imagepng($bgIm, $bgPath);
        imagedestroy($bgIm);

        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'composite_output.png';

        $service = app(ImageBackgroundService::class);
        $result = $service->changeBackground($inputPath, $outputPath, [
            'type'       => 'image',
            'image_path' => $bgPath,
            'fit'        => 'cover',
        ]);

        $this->assertFileExists($outputPath);
        $this->assertEquals(60, $result['width']);
        $this->assertEquals(60, $result['height']);

        $im = imagecreatefrompng($outputPath);
        $cornerColor = imagecolorat($im, 2, 2);
        $cornerR = ($cornerColor >> 16) & 0xFF;
        $cornerG = ($cornerColor >> 8) & 0xFF;

        // Background replaced with gold (R ~255, G ~215)
        $this->assertGreaterThan(220, $cornerR);
        $this->assertGreaterThan(180, $cornerG);

        imagedestroy($im);
    }

    public function test_remove_background_livewire_component_execution_flow(): void
    {
        $uploadedFile = UploadedFile::fake()->image('test_subject.png', 50, 50);

        $test = Livewire::test(RemoveImageBackgroundComponent::class)
            ->set('upload', $uploadedFile)
            ->assertSet('jobStatus', 'uploaded')
            ->set('tolerance', 22.0)
            ->set('feather', 2)
            ->set('floodFill', true)
            ->call('process');

        $test->assertHasNoErrors();
        $this->assertEquals(JobStatus::COMPLETED->value, $test->get('fileJob')->status->value);

        $processedFiles = ProcessedFile::where('file_job_id', $test->get('fileJobId'))->get();
        $this->assertCount(1, $processedFiles);
        $this->assertStringEndsWith('_nobg.png', $processedFiles[0]->original_name);
    }

    public function test_change_background_livewire_component_execution_flow(): void
    {
        $uploadedFile = UploadedFile::fake()->image('test_change.png', 50, 50);

        $test = Livewire::test(ChangeImageBackgroundComponent::class)
            ->set('upload', $uploadedFile)
            ->assertSet('jobStatus', 'uploaded')
            ->call('setSolidPreset', '#2563EB')
            ->assertSet('solidColor', '#2563EB')
            ->call('process');

        $test->assertHasNoErrors();
        $this->assertEquals(JobStatus::COMPLETED->value, $test->get('fileJob')->status->value);

        $processedFiles = ProcessedFile::where('file_job_id', $test->get('fileJobId'))->get();
        $this->assertCount(1, $processedFiles);
        $this->assertStringEndsWith('_custom_bg.png', $processedFiles[0]->original_name);
    }

    public function test_remove_background_rejects_oversized_file(): void
    {
        // 26 MB fake file exceeding 25 MB limit
        $fakeOversized = UploadedFile::fake()->create('huge.png', 27000, 'image/png');

        Livewire::test(RemoveImageBackgroundComponent::class)
            ->set('upload', $fakeOversized)
            ->assertHasErrors(['upload']);
    }

    public function test_remove_background_rejects_invalid_file_type(): void
    {
        $fakePdf = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        Livewire::test(RemoveImageBackgroundComponent::class)
            ->set('upload', $fakePdf)
            ->assertHasErrors(['upload']);
    }
}
