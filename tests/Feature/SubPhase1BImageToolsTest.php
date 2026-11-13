<?php

namespace Tests\Feature;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\ToolType;
use App\Livewire\Tools\CompressImageComponent;
use App\Livewire\Tools\ConvertImageComponent;
use App\Livewire\Tools\ResizeImageComponent;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class SubPhase1BImageToolsTest extends TestCase
{
    use RefreshDatabase;

    private function createSampleImage(string $name = 'sample.jpg', int $width = 800, int $height = 600): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($image, 240, 240, 240);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);

        $tempPath = tempnam(sys_get_temp_dir(), 'test_img_');
        imagejpeg($image, $tempPath, 90);
        imagedestroy($image);

        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function createSamplePng(string $name = 'sample.png', int $width = 400, int $height = 300): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefilledrectangle($image, 0, 0, $width, $height, $transparent);

        $tempPath = tempnam(sys_get_temp_dir(), 'test_png_');
        imagepng($image, $tempPath);
        imagedestroy($image);

        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_compress_image_zero_base64_and_minimal_state(): void
    {
        $file = $this->createSampleImage('compress_sample.jpg', 640, 480);

        $component = Livewire::test(CompressImageComponent::class)
            ->set('upload', $file);

        // Verify zero Base64 in public state
        $data = $component->instance();
        $this->assertObjectNotHasProperty('originalPreviewUri', $data);

        // Verify metadata was populated correctly
        $this->assertSame('compress_sample.jpg', $component->get('filename'));
        $this->assertSame(640, $component->get('imageWidth'));
        $this->assertSame(480, $component->get('imageHeight'));
        $this->assertNotNull($component->get('fileSizeBytes'));
        $this->assertNotNull($component->get('fileSizeFormatted'));

        // Verify preview URL resolution
        $previewUrl = $component->instance()->getPreviewUrl();
        $this->assertNotNull($previewUrl);
        $this->assertStringNotContainsString('data:image', $previewUrl);

        // Verify Livewire snapshot budget (< 3.5 KB)
        $snapshot = json_encode($component->snapshot ?? []);
        $snapshotSize = strlen($snapshot);
        $this->assertLessThan(3500, $snapshotSize, "Snapshot size of {$snapshotSize} bytes exceeds budget");

        // Test cancel upload resets metadata
        $component->call('cancelToolUpload');
        $this->assertNull($component->get('upload'));
        $this->assertNull($component->get('filename'));
        $this->assertNull($component->get('imageWidth'));
    }

    public function test_compress_image_job_processing_preserves_parameters(): void
    {
        $file = $this->createSampleImage('card_compress.jpg', 1000, 1000);
        $origKb = (int) round($file->getSize() / 1024);
        $targetKb = max(15, (int) round($origKb * 0.5));

        Livewire::test(CompressImageComponent::class)
            ->set('upload', $file)
            ->set('compressionMethod', 'target')
            ->set('targetValue', (string) $targetKb)
            ->set('targetUnit', 'kb')
            ->set('targetPriority', 'smallest')
            ->call('process')
            ->assertHasNoErrors();

        $job = FileJob::where('tool_type', ToolType::COMPRESS_IMAGE)->latest('created_at')->first();
        $this->assertNotNull($job);
        $this->assertSame('target', $job->input_metadata['compression_method']);
        $this->assertSame((string) $targetKb, $job->input_metadata['target_value']);
        $this->assertSame('kb', $job->input_metadata['target_unit']);
        $this->assertSame('smallest', $job->input_metadata['target_priority']);
        $this->assertSame($targetKb * 1024, $job->input_metadata['target_size_bytes']);
    }

    public function test_resize_image_zero_base64_and_aspect_ratio_metadata(): void
    {
        $file = $this->createSampleImage('resize_sample.jpg', 1200, 800);

        $component = Livewire::test(ResizeImageComponent::class)
            ->set('upload', $file);

        // Verify zero Base64 in state
        $data = $component->instance();
        $this->assertObjectNotHasProperty('originalPreviewUri', $data);

        // Verify original dimensions detected
        $this->assertSame(1200, $component->get('originalWidth'));
        $this->assertSame(800, $component->get('originalHeight'));
        $this->assertSame(1200, $component->get('width'));
        $this->assertSame(800, $component->get('height'));

        // Verify preview URL resolution
        $previewUrl = $component->instance()->getPreviewUrl();
        $this->assertNotNull($previewUrl);
        $this->assertStringNotContainsString('data:image', $previewUrl);

        // Verify snapshot budget (< 3.5 KB)
        $snapshot = json_encode($component->snapshot ?? []);
        $this->assertLessThan(3500, strlen($snapshot));

        // Test resize execution with cover fit mode
        $component->set('width', 600)
            ->set('height', 400)
            ->set('fit', 'cover')
            ->call('process')
            ->assertHasNoErrors();

        $job = FileJob::where('tool_type', ToolType::RESIZE_IMAGE)->latest('created_at')->first();
        $this->assertNotNull($job);
        $this->assertSame(600, $job->input_metadata['target_width']);
        $this->assertSame(400, $job->input_metadata['target_height']);
        $this->assertSame('cover', $job->input_metadata['fit']);
    }

    public function test_convert_image_native_formats_and_heic_fallback(): void
    {
        // 1. Test native PNG
        $png = $this->createSamplePng('convert_sample.png', 400, 300);

        $component = Livewire::test(ConvertImageComponent::class)
            ->set('upload', $png);

        $this->assertFalse($component->get('isHeic'));
        $this->assertSame('jpg', $component->get('targetFormat')); // PNG defaults target to JPG
        $this->assertSame(400, $component->get('imageWidth'));
        $this->assertSame(300, $component->get('imageHeight'));

        $previewUrl = $component->instance()->getPreviewUrl();
        $this->assertNotNull($previewUrl);
        $this->assertStringNotContainsString('data:image', $previewUrl);

        // Snapshot budget (< 3.5 KB)
        $snapshot = json_encode($component->snapshot ?? []);
        $this->assertLessThan(3500, strlen($snapshot));

        // 2. Test HEIC detection and fallback
        $heicPath = base_path('tests/Fixtures/photo_1.heic');
        $heicFile = UploadedFile::fake()->createWithContent('photo_1.heic', file_get_contents($heicPath));

        $heicComponent = Livewire::test(ConvertImageComponent::class)
            ->set('upload', $heicFile);

        $this->assertTrue($heicComponent->get('isHeic'));
        $this->assertSame('jpg', $heicComponent->get('targetFormat'));
        // For HEIC, getPreviewUrl must return null so no broken browser blob is generated
        $this->assertNull($heicComponent->instance()->getPreviewUrl());
        // Verify HEIC badge rendered in Blade
        $heicComponent->assertSee('HEIC / HEIF');

        // Test conversion process dispatch with target WebP
        $heicComponent->set('targetFormat', 'webp')
            ->call('process')
            ->assertHasNoErrors();

        $job = FileJob::where('tool_type', ToolType::CONVERT_IMAGE)->latest('created_at')->first();
        $this->assertNotNull($job);
        $this->assertSame('webp', $job->input_metadata['target_format']);
    }

    public function test_subphase_1b_tools_render_cleanly_in_arabic_rtl(): void
    {
        app()->setLocale('ar');

        $compressResponse = $this->get('/compress-image');
        $compressResponse->assertOk();
        $compressResponse->assertSee('dir="rtl"', false);
        $compressResponse->assertSee(__('tools.compress_image.title'));

        $resizeResponse = $this->get('/resize-image');
        $resizeResponse->assertOk();
        $resizeResponse->assertSee('dir="rtl"', false);
        $resizeResponse->assertSee(__('tools.resize_image.title'));

        $convertResponse = $this->get('/convert-image');
        $convertResponse->assertOk();
        $convertResponse->assertSee('dir="rtl"', false);
        $convertResponse->assertSee(__('tools.convert_image.title'));

        app()->setLocale('en');
    }
}
