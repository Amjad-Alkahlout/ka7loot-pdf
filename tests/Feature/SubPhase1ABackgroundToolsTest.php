<?php

namespace Tests\Feature;

use App\Livewire\Tools\ChangeImageBackgroundComponent;
use App\Livewire\Tools\RemoveImageBackgroundComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class SubPhase1ABackgroundToolsTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sp1a_test_' . uniqid('', true);
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteRecursive($this->tempDir);
        }
        parent::tearDown();
    }

    protected function deleteRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $this->deleteRecursive($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected function createSampleImage(string $filename = 'sample.png', int $w = 400, int $h = 300, string $format = 'png'): UploadedFile
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $im = imagecreatetruecolor($w, $h);
        $col = imagecolorallocate($im, 79, 70, 229);
        imagefilledrectangle($im, 0, 0, $w, $h, $col);

        if ($format === 'jpg' || $format === 'jpeg') {
            imagejpeg($im, $path, 90);
        } elseif ($format === 'webp') {
            imagewebp($im, $path, 90);
        } else {
            imagepng($im, $path);
        }
        imagedestroy($im);

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($path));
    }

    public function test_remove_background_zero_base64_and_minimal_state(): void
    {
        $image = $this->createSampleImage('portrait.jpg', 600, 800, 'jpg');

        $test = Livewire::test(RemoveImageBackgroundComponent::class)
            ->set('upload', $image);

        $test->assertSet('jobStatus', 'uploaded')
            ->assertSet('filename', 'portrait.jpg')
            ->assertSet('imageWidth', 600)
            ->assertSet('imageHeight', 800);

        // Verify property originalPreviewUri does NOT exist on the component
        $this->assertFalse(property_exists($test->instance(), 'originalPreviewUri'), 'originalPreviewUri must be completely eliminated.');

        // Verify public state payload contains ZERO Base64 strings and stays under 3.5 KB budget
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(3500, strlen($snapshot), 'Snapshot must be under 3.5 KB');
        $this->assertStringNotContainsString('data:image', $snapshot);
        $this->assertStringNotContainsString('base64', $snapshot);

        // Verify preview URL is a clean signed temporary URL, NOT a data URI
        $previewUrl = $test->instance()->getPreviewUrl();
        $this->assertNotEmpty($previewUrl);
        $this->assertStringNotContainsString('data:', $previewUrl);

        // Verify slider updates transmit only numeric values
        $test->set('tolerance', 35.0)
            ->set('feather', 4);

        $this->assertEquals(35.0, $test->get('tolerance'));
        $this->assertEquals(4, $test->get('feather'));

        $updatedSnapshot = json_encode($test->instance()->all());
        $this->assertLessThan(3500, strlen($updatedSnapshot));
        $this->assertStringNotContainsString('data:image', $updatedSnapshot);

        // Test cancelToolUpload clears state
        $test->call('cancelToolUpload');
        $this->assertNull($test->get('upload'));
        $this->assertEquals('', $test->get('filename'));
        $this->assertEquals(0, $test->get('imageWidth'));
    }

    public function test_change_background_zero_base64_and_independent_uploads(): void
    {
        $fgImage = $this->createSampleImage('subject.png', 500, 500, 'png');
        $bgImage = $this->createSampleImage('scenery.jpg', 800, 600, 'jpg');

        $test = Livewire::test(ChangeImageBackgroundComponent::class)
            ->set('upload', $fgImage)
            ->set('bgUpload', $bgImage);

        $test->assertSet('jobStatus', 'uploaded')
            ->assertSet('filename', 'subject.png')
            ->assertSet('bgFilename', 'scenery.jpg')
            ->assertSet('imageWidth', 500)
            ->assertSet('imageHeight', 500);

        // Verify Base64 properties do NOT exist
        $this->assertFalse(property_exists($test->instance(), 'originalPreviewUri'));
        $this->assertFalse(property_exists($test->instance(), 'bgPreviewUri'));

        // Verify snapshot size is minimal and free of Base64
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(3500, strlen($snapshot), 'Combined foreground + background snapshot must be under 3.5 KB');
        $this->assertStringNotContainsString('data:image', $snapshot);
        $this->assertStringNotContainsString('base64', $snapshot);

        // Verify clearing background does NOT clear foreground
        $test->call('clearBgUpload');
        $this->assertNull($test->get('bgUpload'));
        $this->assertEquals('', $test->get('bgFilename'));
        $this->assertNotNull($test->get('upload'), 'Clearing background must NEVER reset foreground upload');
        $this->assertEquals('subject.png', $test->get('filename'));

        // Test replacement of background upload
        $newBg = $this->createSampleImage('new_scenery.webp', 1000, 800, 'webp');
        $test->set('bgUpload', $newBg);
        $this->assertEquals('new_scenery.webp', $test->get('bgFilename'));
        $this->assertEquals('subject.png', $test->get('filename'));

        // Test mode switches and presets
        $test->set('backgroundType', 'solid')
            ->call('setSolidPreset', '#E11D48')
            ->assertSet('solidColor', '#E11D48');

        $test->set('backgroundType', 'gradient')
            ->call('setGradientPreset', '#059669', '#10B981')
            ->assertSet('gradientStart', '#059669')
            ->assertSet('gradientEnd', '#10B981');

        $test->set('bgFit', 'contain')
            ->assertSet('bgFit', 'contain');
    }

    public function test_remove_background_job_processing_preserves_parameters(): void
    {
        $image = $this->createSampleImage('cutout.png', 300, 300, 'png');

        $test = Livewire::test(RemoveImageBackgroundComponent::class)
            ->set('upload', $image)
            ->set('tolerance', 42.0)
            ->set('feather', 3)
            ->set('floodFill', false)
            ->call('process');

        $test->assertHasNoErrors();
        $this->assertNotEquals('failed', $test->get('jobStatus'));

        $jobId = $test->get('fileJobId');
        $this->assertNotEmpty($jobId);

        $job = \App\Models\FileJob::find($jobId);
        $this->assertNotNull($job);
        $this->assertEquals(\App\Enums\ToolType::REMOVE_BACKGROUND, $job->tool_type);

        $meta = $job->input_metadata;
        $this->assertEquals(42.0, $meta['options']['tolerance']);
        $this->assertEquals(3, $meta['options']['feather']);
        $this->assertFalse($meta['options']['flood_fill']);
    }

    public function test_change_background_job_processing_preserves_configuration(): void
    {
        $fgImage = $this->createSampleImage('person.png', 400, 400, 'png');
        $bgImage = $this->createSampleImage('backdrop.jpg', 800, 600, 'jpg');

        $test = Livewire::test(ChangeImageBackgroundComponent::class)
            ->set('upload', $fgImage)
            ->set('bgUpload', $bgImage)
            ->set('backgroundType', 'image')
            ->set('bgFit', 'cover')
            ->set('tolerance', 30.0)
            ->set('feather', 1)
            ->call('process');

        $test->assertHasNoErrors();
        $this->assertNotEquals('failed', $test->get('jobStatus'));

        $jobId = $test->get('fileJobId');
        $this->assertNotEmpty($jobId);

        $job = \App\Models\FileJob::find($jobId);
        $this->assertNotNull($job);
        $this->assertEquals(\App\Enums\ToolType::CHANGE_BACKGROUND, $job->tool_type);

        $meta = $job->input_metadata;
        $this->assertEquals('image', $meta['background_config']['type']);
        $this->assertEquals('cover', $meta['background_config']['fit']);
        $this->assertNotEmpty($meta['background_config']['image_file']);
        $this->assertEquals(30.0, $meta['options']['tolerance']);
        $this->assertEquals(1, $meta['options']['feather']);
    }

    public function test_subphase_1a_tools_render_cleanly_in_arabic_rtl(): void
    {
        app()->setLocale('ar');

        $image = $this->createSampleImage('ar_sample.png', 300, 300, 'png');

        Livewire::test(RemoveImageBackgroundComponent::class)
            ->set('upload', $image)
            ->assertSee('ar_sample.png')
            ->assertDontSee('data:image');

        Livewire::test(ChangeImageBackgroundComponent::class)
            ->set('upload', $image)
            ->assertSee('ar_sample.png')
            ->assertDontSee('data:image');
    }
}
