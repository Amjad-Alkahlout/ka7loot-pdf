<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\App;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClientPreUploadValidationTest extends TestCase
{
    /**
     * Test that limits in config match the required application defaults.
     */
    public function test_centralized_limits_configuration(): void
    {
        $this->assertSame(51200, config('fileprocessor.limits.max_pdf_size_kb'));
        $this->assertSame(25600, config('fileprocessor.limits.max_image_size_kb'));
        $this->assertSame(20, config('fileprocessor.limits.max_batch_files'));
        $this->assertSame(102400, config('fileprocessor.limits.max_total_batch_kb'));

        $pdfMb = (int) round(config('fileprocessor.limits.max_pdf_size_kb') / 1024);
        $imgMb = (int) round(config('fileprocessor.limits.max_image_size_kb') / 1024);
        $totalMb = (int) round(config('fileprocessor.limits.max_total_batch_kb') / 1024);

        $this->assertSame(50, $pdfMb);
        $this->assertSame(25, $imgMb);
        $this->assertSame(100, $totalMb);
    }

    /**
     * Test exact bilingual error messages for oversized PDF.
     */
    public function test_exact_bilingual_error_messages_for_pdf(): void
    {
        $pdfMb = (int) round(config('fileprocessor.limits.max_pdf_size_kb') / 1024);

        App::setLocale('ar');
        $arMessage = __('ui.error_file_too_large', ['size' => $pdfMb]);
        $this->assertSame('حجم الملف يتجاوز الحد المسموح وهو 50 ميجابايت.', $arMessage);

        App::setLocale('en');
        $enMessage = __('ui.error_file_too_large', ['size' => $pdfMb]);
        $this->assertSame('The file exceeds the maximum allowed size of 50 MB.', $enMessage);
    }

    /**
     * Test exact bilingual error messages for oversized image.
     */
    public function test_exact_bilingual_error_messages_for_image(): void
    {
        $imgMb = (int) round(config('fileprocessor.limits.max_image_size_kb') / 1024);

        App::setLocale('ar');
        $arMessage = __('ui.error_image_preupload_too_large', ['size' => $imgMb]);
        $this->assertSame('حجم الصورة يتجاوز الحد المسموح وهو 25 ميجابايت.', $arMessage);

        App::setLocale('en');
        $enMessage = __('ui.error_image_preupload_too_large', ['size' => $imgMb]);
        $this->assertSame('The image exceeds the maximum allowed size of 25 MB.', $enMessage);
    }

    /**
     * Test that tool pages render the client validation capture directive and messages.
     */
    public function test_tool_views_render_client_validation_directives(): void
    {
        $tools = [
            '/compress-pdf',
            '/compress-image',
            '/merge-pdf',
            '/split-pdf',
            '/jpg-to-pdf',
        ];

        foreach ($tools as $uri) {
            $response = $this->get($uri);
            $response->assertStatus(200);

            // Assert capture-phase file selection listener is present
            $response->assertSee('@change.capture="handleFileSelect($event)"', false);
            // Assert fileUploadHandler data initialization is present
            $response->assertSee('fileUploadHandler(', false);
            // Assert centralized window.fileProcessorConfig is in layout
            $response->assertSee('window.fileProcessorConfig', false);
        }
    }

    /**
     * Test Arabic layout renders localized error text payloads into client handler.
     */
    public function test_arabic_tool_views_render_localized_error_payload(): void
    {
        $this->withSession(['locale' => 'ar']);
        App::setLocale('ar');

        $response = $this->get('/compress-pdf');
        $response->assertStatus(200);
        $response->assertSee('حجم الملف يتجاوز الحد المسموح وهو 50 ميجابايت.');

        $imgResponse = $this->get('/compress-image');
        $imgResponse->assertStatus(200);
        $imgResponse->assertSee('حجم الصورة يتجاوز الحد المسموح وهو 25 ميجابايت.');
    }

    /**
     * Run Node.js client-side unit test suite for upload-progress validation.
     */
    public function test_client_side_javascript_validation_suite(): void
    {
        $process = new Process(['node', '--test', 'tests/Javascript/upload-progress-validation.test.js'], base_path());
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            "Node.js client validation tests failed:\n" . $process->getOutput() . "\n" . $process->getErrorOutput()
        );
    }
}
