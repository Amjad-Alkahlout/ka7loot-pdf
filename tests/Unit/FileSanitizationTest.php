<?php

namespace Tests\Unit;

use App\Actions\File\SanitizeUploadedFileAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FileSanitizationTest extends TestCase
{
    protected SanitizeUploadedFileAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new SanitizeUploadedFileAction();
    }

    protected function createFakePdf(string $filename = 'test.pdf', int $sizeKb = 10): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\nxref\n0 1\n0000000000 65535 f\ntrailer<</Size 1>>\nstartxref\n9\n%%EOF\n";
        if ($sizeKb > 1) {
            $content .= str_repeat(" ", ($sizeKb * 1024) - strlen($content));
        }

        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    public function test_valid_pdf_passes_sanitization(): void
    {
        $file = $this->createFakePdf('report.pdf', 5);
        $result = $this->action->execute($file, ['application/pdf']);

        $this->assertEquals('report.pdf', $result['original_name']);
        $this->assertEquals('application/pdf', $result['mime_type']);
        $this->assertEquals('pdf', $result['extension']);
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}\.pdf$/', $result['safe_filename']);
    }

    public function test_valid_image_passes_sanitization(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 800, 600);
        $result = $this->action->execute($file);

        $this->assertEquals('photo.jpg', $result['original_name']);
        $this->assertEquals('image/jpeg', $result['mime_type']);
        $this->assertEquals('jpg', $result['extension']);
        $this->assertMatchesRegularExpression('/^[0-9a-f\-]{36}\.jpg$/', $result['safe_filename']);
    }

    public function test_valid_heic_is_detected_from_isobmff_brand(): void
    {
        $header = pack('N', 24) . 'ftyp' . 'heic' . pack('N', 0) . 'mif1' . str_repeat("\0", 8);
        $file = UploadedFile::fake()->createWithContent('iphone.heic', $header . str_repeat('x', 100));

        $result = $this->action->execute($file, ['image/heic']);

        $this->assertSame('image/heic', $result['mime_type']);
        $this->assertSame('heic', $result['extension']);
    }

    public function test_fake_heic_without_isobmff_header_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $file = UploadedFile::fake()->createWithContent('fake.heic', 'not an image');
        $this->action->execute($file, ['image/heic']);
    }

    public function test_extension_mismatch_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        // Disguised file: text content named as .pdf
        $file = UploadedFile::fake()->createWithContent('malicious.pdf', 'Plain text content without PDF header');
        $this->action->execute($file);
    }

    public function test_disallowed_mime_type_for_tool_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $file = UploadedFile::fake()->image('photo.png', 400, 400);
        // Tool whitelist only allows PDF
        $this->action->execute($file, ['application/pdf']);
    }

    public function test_filename_with_path_traversal_is_sanitized(): void
    {
        $file = $this->createFakePdf('../../../etc/evil.pdf', 2);
        $result = $this->action->execute($file);

        $this->assertStringNotContainsString('/', $result['original_name']);
        $this->assertStringNotContainsString('\\', $result['original_name']);
        $this->assertStringNotContainsString('..', $result['original_name']);
        $this->assertEquals('evil.pdf', $result['original_name']);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        config(['fileprocessor.limits.max_pdf_size_kb' => 1]); // 1 KB limit for test
        $file = $this->createFakePdf('huge.pdf', 10);
        $this->action->execute($file);
    }
}
