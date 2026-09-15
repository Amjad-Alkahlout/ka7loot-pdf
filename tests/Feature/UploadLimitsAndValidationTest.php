<?php

namespace Tests\Feature;

use App\Actions\File\SanitizeUploadedFileAction;
use App\Livewire\Tools\CompressPdfComponent;
use App\Livewire\Tools\CompressImageComponent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Livewire;
use Tests\TestCase;

class UploadLimitsAndValidationTest extends TestCase
{
    use RefreshDatabase;

    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        parent::tearDown();
    }

    /**
     * Helper to create a sized PDF with valid magic bytes.
     */
    protected function createSizedPdf(string $filename, int $bytes): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'test_pdf_');
        $this->tempFiles[] = $temp;

        $fh = fopen($temp, 'wb');
        fwrite($fh, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\nxref\n0 1\n0000000000 65535 f\ntrailer<</Size 1>>\nstartxref\n9\n%%EOF\n");
        fseek($fh, $bytes - 1);
        fwrite($fh, "\n");
        fclose($fh);

        return new UploadedFile($temp, $filename, 'application/pdf', null, true);
    }

    /**
     * Helper to create a sized JPEG with valid magic bytes.
     */
    protected function createSizedJpeg(string $filename, int $bytes): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'test_img_');
        $this->tempFiles[] = $temp;

        $fh = fopen($temp, 'wb');
        fwrite($fh, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");
        fseek($fh, $bytes - 2);
        fwrite($fh, "\xFF\xD9");
        fclose($fh);

        return new UploadedFile($temp, $filename, 'image/jpeg', null, true);
    }

    /**
     * Test: 12 MB PDF is accepted.
     */
    public function test_12_mb_pdf_is_accepted(): void
    {
        $action = new SanitizeUploadedFileAction();
        $file = $this->createSizedPdf('document_12mb.pdf', 12 * 1024 * 1024);

        $result = $action->execute($file, ['application/pdf']);

        $this->assertEquals('document_12mb.pdf', $result['original_name']);
        $this->assertEquals('application/pdf', $result['mime_type']);
        $this->assertEquals('pdf', $result['extension']);
        $this->assertNotEmpty($result['safe_filename']);
    }

    /**
     * Test: 25 MB image is accepted.
     */
    public function test_25_mb_image_is_accepted(): void
    {
        $action = new SanitizeUploadedFileAction();
        $file = $this->createSizedJpeg('photo_25mb.jpg', 25 * 1024 * 1024);

        $result = $action->execute($file, ['image/jpeg', 'image/png', 'image/webp']);

        $this->assertEquals('photo_25mb.jpg', $result['original_name']);
        $this->assertEquals('image/jpeg', $result['mime_type']);
        $this->assertEquals('jpg', $result['extension']);
        $this->assertNotEmpty($result['safe_filename']);
    }

    /**
     * Test: 50 MB PDF is accepted.
     */
    public function test_50_mb_pdf_is_accepted(): void
    {
        $action = new SanitizeUploadedFileAction();
        $file = $this->createSizedPdf('document_50mb.pdf', 50 * 1024 * 1024);

        $result = $action->execute($file, ['application/pdf']);

        $this->assertEquals('document_50mb.pdf', $result['original_name']);
        $this->assertEquals('application/pdf', $result['mime_type']);
        $this->assertEquals('pdf', $result['extension']);
        $this->assertNotEmpty($result['safe_filename']);
    }

    /**
     * Test: PDF above 50 MB is rejected.
     */
    public function test_pdf_above_50_mb_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $action = new SanitizeUploadedFileAction();
        $file = $this->createSizedPdf('oversized.pdf', 51 * 1024 * 1024);

        $action->execute($file, ['application/pdf']);
    }

    /**
     * Test: Image above 25 MB is rejected.
     */
    public function test_image_above_25_mb_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $action = new SanitizeUploadedFileAction();
        $file = $this->createSizedJpeg('oversized.jpg', 26 * 1024 * 1024);

        $action->execute($file, ['image/jpeg', 'image/png', 'image/webp']);
    }

    /**
     * Test: Livewire temporary upload validation configuration does not block files up to 50 MB.
     */
    public function test_livewire_temporary_upload_allows_files_up_to_50mb(): void
    {
        $rules = FileUploadConfiguration::rules();

        // Must not be the default 12288 KB (12 MB)
        $this->assertNotContains('max:12288', $rules);

        // Must be configured for at least 51200 KB (50 MB)
        $this->assertContains('max:51200', $rules);
    }

    /**
     * Test: Arabic validation messages are localized and raw English does not appear.
     */
    public function test_arabic_validation_messages_are_localized(): void
    {
        App::setLocale('ar');

        $action = new SanitizeUploadedFileAction();

        // 1. Rejection for oversized PDF in Arabic
        try {
            $file = $this->createSizedPdf('large.pdf', 52 * 1024 * 1024);
            $action->execute($file, ['application/pdf']);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $error = $e->validator->errors()->first('file');
            $this->assertStringContainsString('يتجاوز الحد الأقصى', $error);
            $this->assertStringContainsString('50', $error);
            $this->assertStringNotContainsString('may not be greater than', $error);
        }

        // 2. Rejection for oversized image in Arabic
        try {
            $image = $this->createSizedJpeg('large.jpg', 27 * 1024 * 1024);
            $action->execute($image, ['image/jpeg']);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $error = $e->validator->errors()->first('file');
            $this->assertStringContainsString('حجم الصورة يتجاوز الحد الأقصى', $error);
            $this->assertStringContainsString('25', $error);
            $this->assertStringNotContainsString('may not be greater than', $error);
        }

        // 3. Livewire BaseToolComponent messages dictionary in Arabic
        $component = new CompressPdfComponent();
        $messages = $component->messages();

        $this->assertStringContainsString('الملف', $messages['upload.max']);
        $this->assertStringContainsString('الملف', $messages['upload.required']);

        // 4. Livewire component validation when upload exceeds max
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);
        $validator = Validator::make(
            ['upload' => $this->createSizedPdf('toolarge.pdf', 52 * 1024 * 1024)],
            ['upload' => "required|file|max:{$maxPdfKb}"],
            $component->messages()
        );

        $this->assertTrue($validator->fails());
        $livewireError = $validator->errors()->first('upload');
        $this->assertStringContainsString('يجب ألا يتجاوز', $livewireError);
        $this->assertStringContainsString('الملف', $livewireError);
        $this->assertStringNotContainsString('may not be greater than', $livewireError);
    }
}
