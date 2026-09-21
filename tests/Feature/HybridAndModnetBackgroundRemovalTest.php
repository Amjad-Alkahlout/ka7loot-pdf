<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Livewire\Tools\ChangeImageBackgroundComponent;
use App\Livewire\Tools\RemoveImageBackgroundComponent;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\Image\AdvancedBackgroundRemovalEngine;
use App\Services\Image\BackgroundRemovalEngineInterface;
use App\Services\Image\ColorSamplingBackgroundRemovalEngine;
use App\Services\Image\HybridBackgroundRemovalEngine;
use App\Services\Image\ImageBackgroundService;
use App\Services\Image\ModnetBackgroundRemovalEngine;
use App\Services\Image\U2NetBackgroundRemovalEngine;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class HybridAndModnetBackgroundRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected string $qaPortraitPath;
    protected string $productPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hybrid_modnet_test_' . uniqid();
        @mkdir($this->tempDir, 0777, true);

        $this->qaPortraitPath = base_path('storage/ml_prototype/fixtures_modnet/qa_portrait_1200x1437.jpg');
        $this->productPath = base_path('storage/ml_prototype/fixtures_modnet/non_human_product.jpg');
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

    public function test_modnet_engine_contract_and_model_integrity(): void
    {
        $engine = app(ModnetBackgroundRemovalEngine::class);

        $this->assertEquals('ml_modnet', $engine->getId());
        $this->assertTrue($engine->isAi());
        $this->assertStringContainsString('MODNet', $engine->getLabel());
        $this->assertStringContainsString('MODNet', $engine->getDescription());

        // Confirms Node runtime and 25.88 MB ONNX model SHA256 match
        $this->assertTrue($engine->isAvailable());
    }

    public function test_hybrid_engine_routes_real_portrait_to_modnet(): void
    {
        if (!file_exists($this->qaPortraitPath)) {
            $this->markTestSkipped('QA Portrait fixture not found.');
        }

        $engine = app(HybridBackgroundRemovalEngine::class);
        $this->assertTrue($engine->isLikelyHumanPortrait($this->qaPortraitPath));

        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'portrait_out.png';
        $meta = $engine->removeBackground($this->qaPortraitPath, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertEquals('ml_modnet', $meta['engine']);
        $this->assertTrue($meta['is_ai']);
        $this->assertEquals('human_portrait', $meta['detection']);
        $this->assertEquals(1200, $meta['width']);
        $this->assertEquals(1437, $meta['height']);

        // Inspect torso pixel (x: 600, y: 1300) - must remain completely opaque
        $im = imagecreatefrompng($outputPath);
        $torsoRgba = imagecolorat($im, 600, 1300);
        $torsoAlpha = ($torsoRgba >> 24) & 0x7F;
        $this->assertLessThan(15, $torsoAlpha, 'Torso/shirt must be preserved with full opacity');

        // Inspect corner pixel (x: 10, y: 10) - must be transparent
        $cornerRgba = imagecolorat($im, 10, 10);
        $cornerAlpha = ($cornerRgba >> 24) & 0x7F;
        $this->assertGreaterThan(115, $cornerAlpha, 'Background corner must be transparent');

        imagedestroy($im);
    }

    public function test_hybrid_engine_routes_non_human_object_to_u2net(): void
    {
        if (!file_exists($this->productPath)) {
            $this->markTestSkipped('Product fixture not found.');
        }

        $engine = app(HybridBackgroundRemovalEngine::class);
        $this->assertFalse($engine->isLikelyHumanPortrait($this->productPath));

        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'product_out.png';
        $meta = $engine->removeBackground($this->productPath, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertEquals('ml_u2net', $meta['engine']);
        $this->assertTrue($meta['is_ai']);
        $this->assertEquals('general_graphic', $meta['detection']);

        // Product center must be preserved
        $im = imagecreatefrompng($outputPath);
        $centerRgba = imagecolorat($im, 600, 500);
        $centerAlpha = ($centerRgba >> 24) & 0x7F;
        $this->assertLessThan(20, $centerAlpha, 'Product body must not be erased');
        imagedestroy($im);
    }

    public function test_hybrid_engine_falls_back_to_advanced_when_u2net_fails_on_non_human_object(): void
    {
        if (!file_exists($this->productPath)) {
            $this->markTestSkipped('Product fixture not found.');
        }

        $mockU2Net = $this->createMock(U2NetBackgroundRemovalEngine::class);
        $mockU2Net->method('isAvailable')->willReturn(true);
        $mockU2Net->method('removeBackground')->willThrowException(new \RuntimeException('U2Net crash'));

        $hybrid = new HybridBackgroundRemovalEngine(
            app(ModnetBackgroundRemovalEngine::class),
            $mockU2Net,
            app(AdvancedBackgroundRemovalEngine::class),
            app(ColorSamplingBackgroundRemovalEngine::class),
            app(SystemBinaryDetector::class)
        );

        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'product_adv_fallback.png';
        $meta = $hybrid->removeBackground($this->productPath, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertEquals('advanced_matting', $meta['engine']);
        $this->assertFalse($meta['is_ai']);
        $this->assertEquals('general_object', $meta['detection']);
    }

    public function test_hybrid_engine_falls_back_to_advanced_when_modnet_fails(): void
    {
        if (!file_exists($this->qaPortraitPath)) {
            $this->markTestSkipped('QA Portrait fixture not found.');
        }

        // Mock ModnetBackgroundRemovalEngine to throw an exception
        $mockModnet = $this->createMock(ModnetBackgroundRemovalEngine::class);
        $mockModnet->method('isAvailable')->willReturn(true);
        $mockModnet->method('removeBackground')->willThrowException(new \RuntimeException('Subprocess timeout'));

        $hybrid = new HybridBackgroundRemovalEngine(
            $mockModnet,
            app(U2NetBackgroundRemovalEngine::class),
            app(AdvancedBackgroundRemovalEngine::class),
            app(ColorSamplingBackgroundRemovalEngine::class),
            app(SystemBinaryDetector::class)
        );

        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'fallback_out.png';
        $meta = $hybrid->removeBackground($this->qaPortraitPath, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertEquals('advanced_matting', $meta['engine']);
        $this->assertFalse($meta['is_ai']);
        $this->assertEquals('human_portrait_fallback', $meta['detection']);
    }

    public function test_hybrid_engine_falls_back_to_color_sampling_when_advanced_also_fails(): void
    {
        if (!file_exists($this->qaPortraitPath)) {
            $this->markTestSkipped('QA Portrait fixture not found.');
        }

        // Mock both Modnet and Advanced to throw
        $mockModnet = $this->createMock(ModnetBackgroundRemovalEngine::class);
        $mockModnet->method('isAvailable')->willReturn(false);

        $mockAdv = $this->createMock(AdvancedBackgroundRemovalEngine::class);
        $mockAdv->method('removeBackground')->willThrowException(new \RuntimeException('GD out of memory'));

        $hybrid = new HybridBackgroundRemovalEngine(
            $mockModnet,
            app(U2NetBackgroundRemovalEngine::class),
            $mockAdv,
            app(ColorSamplingBackgroundRemovalEngine::class),
            app(SystemBinaryDetector::class)
        );

        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'fallback_color_out.png';
        $meta = $hybrid->removeBackground($this->qaPortraitPath, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertEquals('color_sampling', $meta['engine']);
        $this->assertFalse($meta['is_ai']);
        $this->assertEquals('color_sampling_final_fallback', $meta['routing']);
    }

    public function test_image_background_service_portrait_gradient_replacement(): void
    {
        if (!file_exists($this->qaPortraitPath)) {
            $this->markTestSkipped('QA Portrait fixture not found.');
        }

        $service = app(ImageBackgroundService::class);
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'portrait_gradient.png';

        $meta = $service->changeBackground($this->qaPortraitPath, $outputPath, [
            'type'        => 'gradient',
            'color_start' => '#1E3A8A',
            'color_end'   => '#3B82F6',
            'direction'   => 'vertical',
        ]);

        $this->assertFileExists($outputPath);
        $this->assertEquals('ml_modnet', $meta['engine']);
        $this->assertTrue($meta['is_ai']);
        $this->assertEquals('gradient', $meta['background_type']);
        $this->assertEquals(1200, $meta['width']);
        $this->assertEquals(1437, $meta['height']);
    }

    public function test_remove_background_component_truthful_attribution_view(): void
    {
        if (!file_exists($this->qaPortraitPath)) {
            $this->markTestSkipped('QA Portrait fixture not found.');
        }

        $uploadedFile = UploadedFile::fake()->createWithContent(
            'portrait_qa.jpg',
            file_get_contents($this->qaPortraitPath)
        );

        $test = Livewire::test(RemoveImageBackgroundComponent::class)
            ->set('upload', $uploadedFile)
            ->assertSet('jobStatus', 'uploaded')
            ->set('tolerance', 25.0)
            ->set('feather', 2)
            ->set('floodFill', true)
            ->call('process');

        $test->assertHasNoErrors();
        $jobId = $test->get('fileJobId');
        $job = FileJob::find($jobId);

        $this->assertNotNull($job);
        $this->assertEquals(JobStatus::COMPLETED->value, $job->status->value);
        $this->assertTrue($job->input_metadata['is_ai'] ?? false);
        $this->assertEquals('ml_modnet', $job->input_metadata['engine'] ?? '');

        // Render the job result view component to assert the AI attribution badge
        $renderedHtml = view('livewire.tools.job-result', [
            'job' => $job,
            'processedFiles' => ProcessedFile::where('file_job_id', $jobId)->get(),
        ])->render();

        $this->assertStringContainsString('AI Portrait Matting', $renderedHtml);
        $this->assertStringContainsString('MODNet', $renderedHtml);
    }

    public function test_hybrid_engine_routes_document_signature_to_colorsampling(): void
    {
        $input = base_path("tests/Fixtures/background-removal/real-trs-signature.png");
        if (!file_exists($input)) {
            $this->markTestSkipped("Real TRS fixture not found.");
        }
        $engine = app(HybridBackgroundRemovalEngine::class);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . "doc_out.png";

        $meta = $engine->removeBackground($input, $output);
        
        $this->assertEquals("color_sampling_document", $meta["routing"]);
        $this->assertEquals("document_signature", $meta["detection"]);
    }

    public function test_hybrid_engine_routes_synthetic_graphic_to_colorsampling(): void
    {
        $engine = app(HybridBackgroundRemovalEngine::class);
        $input = base_path("tests/Fixtures/background-removal/synthetic-trs-topology.png");
        $output = $this->tempDir . DIRECTORY_SEPARATOR . "syn_out.png";

        $meta = $engine->removeBackground($input, $output);
        $this->assertEquals("color_sampling_document", $meta["routing"]);
        $this->assertEquals("document_signature", $meta["detection"]);
    }

    public function test_hybrid_engine_does_not_misroute_white_background_object_to_colorsampling(): void
    {
        $engine = app(HybridBackgroundRemovalEngine::class);
        $img = imagecreatetruecolor(400, 300);
        $white = imagecolorallocate($img, 255, 255, 255);
        $shoeColor = imagecolorallocate($img, 240, 240, 240); // very light gray
        imagefilledrectangle($img, 0, 0, 400, 300, $white);
        imagefilledellipse($img, 200, 150, 250, 250, $shoeColor);
        $input = $this->tempDir . "/mock-shoe.png";
        imagepng($img, $input);
        imagedestroy($img);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . "shoe_out.png";

        $meta = $engine->removeBackground($input, $output);
        $this->assertEquals("u2net_graphic_ai", $meta["routing"]);
        $this->assertEquals("general_graphic", $meta["detection"]);
    }

    public function test_hybrid_engine_adversarial_routing(): void
    {
        $engine = app(HybridBackgroundRemovalEngine::class);

        // F. Empty white image -> should NOT be document
        $img = imagecreatetruecolor(400, 300);
        imagefilledrectangle($img, 0, 0, 400, 300, imagecolorallocate($img, 255, 255, 255));
        $emptyPath = $this->tempDir . "/empty.png";
        imagepng($img, $emptyPath);
        imagedestroy($img);
        $meta = $engine->removeBackground($emptyPath, $this->tempDir . "/out.png");
        $this->assertNotEquals("color_sampling_document", $meta["routing"]);

        // G. Dark background graphic -> should NOT be document
        $img = imagecreatetruecolor(400, 300);
        imagefilledrectangle($img, 0, 0, 400, 300, imagecolorallocate($img, 50, 50, 50));
        imagefilledellipse($img, 200, 150, 100, 100, imagecolorallocate($img, 255, 0, 0));
        $darkPath = $this->tempDir . "/dark.png";
        imagepng($img, $darkPath);
        imagedestroy($img);
        $meta = $engine->removeBackground($darkPath, $this->tempDir . "/out.png");
        $this->assertNotEquals("color_sampling_document", $meta["routing"]);
        
        // E. Sparse logo -> low transition count/ink ratio -> general graphic
        $img = imagecreatetruecolor(400, 300);
        imagefilledrectangle($img, 0, 0, 400, 300, imagecolorallocate($img, 255, 255, 255));
        for($x = 100; $x < 300; $x += 10) { imageline($img, $x, 140, $x, 160, imagecolorallocate($img, 0, 0, 0)); }
        $logoPath = $this->tempDir . "/logo.png";
        imagepng($img, $logoPath);
        imagedestroy($img);
        $meta = $engine->removeBackground($logoPath, $this->tempDir . "/out.png");
        $this->assertNotEquals("color_sampling_document", $meta["routing"]);
    }
}
