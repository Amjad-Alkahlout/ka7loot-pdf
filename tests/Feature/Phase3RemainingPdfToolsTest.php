<?php

namespace Tests\Feature;

use App\Livewire\Tools\CompressPdfComponent;
use App\Livewire\Tools\PdfToJpgComponent;
use App\Livewire\Tools\PdfToPngComponent;
use App\Livewire\Tools\ProtectPdfComponent;
use App\Livewire\Tools\UnlockPdfComponent;
use App\Services\Pdf\PdfProtectionService;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class Phase3RemainingPdfToolsTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p3_test_' . uniqid('', true);
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

    protected function createPdfFixture(string $filename = 'test.pdf', int $pages = 3): UploadedFile
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('P', 'mm', 'A4');
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(40, 10, "Page {$i} Phase 3 Test Document");
        }
        $pdf->Output('F', $path);

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($path));
    }

    protected function createEncryptedPdfFixture(string $filename = 'encrypted.pdf', string $password = 'secret123'): UploadedFile
    {
        $plainFile = $this->createPdfFixture('plain_for_enc.pdf', 2);
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . $filename;

        $service = app(PdfProtectionService::class);
        $service->protect(
            sourcePath: $plainFile->getRealPath(),
            outputPath: $outputPath,
            userPassword: $password,
        );

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($outputPath));
    }

    public function test_compress_pdf_has_minimal_state_and_generates_signed_preview_url(): void
    {
        $file = $this->createPdfFixture('compress_sample.pdf', 4);

        $test = Livewire::test(CompressPdfComponent::class)
            ->set('upload', $file);

        $test->assertSet('jobStatus', 'uploaded')
            ->assertSet('pageCount', 4)
            ->assertSet('filename', 'compress_sample.pdf');

        // Verify Livewire public payload contains NO Base64 strings and remains under 5 KB budget
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot));
        $this->assertStringNotContainsString('data:image', $snapshot);

        // Verify signed preview URL generation
        $previewUrl = $test->instance()->getPreviewUrl(1, 120);
        $this->assertNotEmpty($previewUrl);
        $this->assertStringContainsString('page=1', $previewUrl);
        $this->assertStringContainsString('dpi=120', $previewUrl);
        $this->assertStringContainsString('signature=', $previewUrl);

        $highResUrl = $test->instance()->getHighResPreviewUrl(1);
        $this->assertNotEmpty($highResUrl);
        $this->assertStringContainsString('dpi=200', $highResUrl);

        // Verify Blade renders preview element and inspect modal
        $test->assertSeeHtml('pdfProgressivePreview')
            ->assertSeeHtml('pdfInspectModal')
            ->assertSee('compress_sample.pdf');

        // Test cancelToolUpload clears state
        $test->call('cancelToolUpload');
        $this->assertEquals(1, $test->get('pageCount'));
        $this->assertEquals('', $test->get('filename'));
    }

    public function test_pdf_to_jpg_generates_preview_and_keeps_state_minimal(): void
    {
        $file = $this->createPdfFixture('to_jpg_sample.pdf', 6);

        $test = Livewire::test(PdfToJpgComponent::class)
            ->set('upload', $file);

        $test->assertSet('jobStatus', 'uploaded')
            ->assertSet('pageCount', 6)
            ->assertSet('filename', 'to_jpg_sample.pdf');

        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot));
        $this->assertStringNotContainsString('data:image', $snapshot);

        $previewUrl = $test->instance()->getPreviewUrl(1, 120);
        $this->assertNotEmpty($previewUrl);
        $this->assertStringContainsString('page=1', $previewUrl);
        $this->assertStringContainsString('signature=', $previewUrl);

        $test->assertSeeHtml('pdfProgressivePreview')
            ->assertSeeHtml('pdfInspectModal')
            ->assertSee('to_jpg_sample.pdf');
    }

    public function test_pdf_to_png_generates_preview_and_keeps_state_minimal(): void
    {
        $file = $this->createPdfFixture('to_png_sample.pdf', 5);

        $test = Livewire::test(PdfToPngComponent::class)
            ->set('upload', $file);

        $test->assertSet('jobStatus', 'uploaded')
            ->assertSet('pageCount', 5)
            ->assertSet('filename', 'to_png_sample.pdf');

        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot));
        $this->assertStringNotContainsString('data:image', $snapshot);

        $previewUrl = $test->instance()->getPreviewUrl(1, 120);
        $this->assertNotEmpty($previewUrl);
        $this->assertStringContainsString('page=1', $previewUrl);
        $this->assertStringContainsString('signature=', $previewUrl);

        $test->assertSeeHtml('pdfProgressivePreview')
            ->assertSeeHtml('pdfInspectModal')
            ->assertSee('to_png_sample.pdf');
    }

    public function test_protect_pdf_generates_preview_and_keeps_state_minimal(): void
    {
        $file = $this->createPdfFixture('protect_sample.pdf', 3);

        $test = Livewire::test(ProtectPdfComponent::class)
            ->set('upload', $file);

        $test->assertSet('jobStatus', 'uploaded')
            ->assertSet('pageCount', 3)
            ->assertSet('filename', 'protect_sample.pdf');

        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot));
        $this->assertStringNotContainsString('data:image', $snapshot);

        $previewUrl = $test->instance()->getPreviewUrl(1, 120);
        $this->assertNotEmpty($previewUrl);
        $this->assertStringContainsString('page=1', $previewUrl);
        $this->assertStringContainsString('signature=', $previewUrl);

        $test->assertSeeHtml('pdfProgressivePreview')
            ->assertSeeHtml('pdfInspectModal')
            ->assertSee('protect_sample.pdf');
    }

    public function test_unlock_pdf_does_not_generate_preview_for_encrypted_pdf(): void
    {
        $file = $this->createEncryptedPdfFixture('locked.pdf', 'pass123');

        $test = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $file);

        $test->assertSet('jobStatus', 'uploaded')
            ->assertSet('filename', 'locked.pdf');

        // Verify method getPreviewUrl does NOT exist on UnlockPdfComponent
        $this->assertFalse(method_exists($test->instance(), 'getPreviewUrl'));

        // Verify state is minimal and contains no base64
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot));
        $this->assertStringNotContainsString('data:image', $snapshot);

        // Verify Blade renders encrypted document card with lock icon and no progressive preview
        $test->assertDontSeeHtml('pdfProgressivePreview')
            ->assertSee('locked.pdf');
    }

    public function test_signed_preview_resolves_and_serves_rendered_image(): void
    {
        $file = $this->createPdfFixture('serve_test.pdf', 2);

        $test = Livewire::test(CompressPdfComponent::class)
            ->set('upload', $file);

        $previewUrl = $test->instance()->getPreviewUrl(1, 120);

        // Request signed preview route with matching session
        $sessionId = session()->get('preview_session_id');
        $response = $this->withSession(['preview_session_id' => $sessionId])
            ->get($previewUrl);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_phase3_tools_render_cleanly_in_arabic_locale(): void
    {
        app()->setLocale('ar');

        $file = $this->createPdfFixture('ar_test.pdf', 2);

        // 1. Compress PDF in Arabic
        Livewire::test(CompressPdfComponent::class)
            ->set('upload', $file)
            ->assertSeeHtml('pdfProgressivePreview')
            ->assertSee('ar_test.pdf');

        // 2. PDF to JPG in Arabic
        Livewire::test(PdfToJpgComponent::class)
            ->set('upload', $file)
            ->assertSeeHtml('pdfProgressivePreview')
            ->assertSee('ar_test.pdf');

        // 3. PDF to PNG in Arabic
        Livewire::test(PdfToPngComponent::class)
            ->set('upload', $file)
            ->assertSeeHtml('pdfProgressivePreview')
            ->assertSee('ar_test.pdf');

        // 4. Protect PDF in Arabic
        Livewire::test(ProtectPdfComponent::class)
            ->set('upload', $file)
            ->assertSeeHtml('pdfProgressivePreview')
            ->assertSee('ar_test.pdf');

        // 5. Unlock PDF in Arabic with encrypted file
        $encFile = $this->createEncryptedPdfFixture('ar_locked.pdf', 'pass123');
        Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $encFile)
            ->assertDontSeeHtml('pdfProgressivePreview')
            ->assertSee('ar_locked.pdf');
    }

    public function test_protect_and_unlock_pdf_roundtrip_flow(): void
    {
        $file = $this->createPdfFixture('roundtrip.pdf', 2);

        // Protect file via component
        $protectComponent = Livewire::test(ProtectPdfComponent::class)
            ->set('upload', $file)
            ->set('password', 'SecretPass2026!')
            ->set('password_confirmation', 'SecretPass2026!')
            ->call('process');

        $protectComponent->assertHasNoErrors();
        $this->assertEquals('completed', $protectComponent->get('jobStatus'));

        $protectedJob = $protectComponent->instance()->fileJob;
        $this->assertNotNull($protectedJob);
        $processedFiles = $protectComponent->instance()->processedFiles;
        $this->assertNotEmpty($processedFiles);

        $storage = app(\App\Services\File\FileStorageManager::class);
        $outputPath = $storage->getJobDirectory($protectedJob->id) . DIRECTORY_SEPARATOR . $processedFiles->first()->stored_filename;
        $this->assertTrue(file_exists($outputPath));

        // Unlock file via component
        $uploadedProtected = UploadedFile::fake()->createWithContent('protected_roundtrip.pdf', file_get_contents($outputPath));

        $unlockComponent = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $uploadedProtected)
            ->set('password', 'SecretPass2026!')
            ->call('process');

        $unlockComponent->assertHasNoErrors();
        $this->assertEquals('completed', $unlockComponent->get('jobStatus'));

        // Verify passwords never persist in component state or snapshots
        $this->assertSame('', $protectComponent->get('password'));
        $this->assertSame('', $protectComponent->get('password_confirmation'));
        $this->assertSame('', $unlockComponent->get('password'));
        $this->assertStringNotContainsString('SecretPass2026!', json_encode($protectComponent->instance()->all()));
        $this->assertStringNotContainsString('SecretPass2026!', json_encode($unlockComponent->instance()->all()));

        // Verify passwords never stored in FileJob input_metadata or ProcessedFile
        $this->assertArrayNotHasKey('password', $protectedJob->input_metadata);
        $this->assertArrayNotHasKey('user_password', $protectedJob->input_metadata);
        $this->assertStringNotContainsString('SecretPass2026!', json_encode($protectedJob->input_metadata));

        $unlockedJob = $unlockComponent->instance()->fileJob;
        $this->assertNotNull($unlockedJob);
        $this->assertArrayNotHasKey('password', $unlockedJob->input_metadata);
        $this->assertStringNotContainsString('SecretPass2026!', json_encode($unlockedJob->input_metadata));
    }

    public function test_password_security_failure_modes_and_cleanup(): void
    {
        $storage = app(\App\Services\File\FileStorageManager::class);
        $removalService = app(\App\Services\Pdf\PdfPasswordRemovalService::class);

        // 1. Unencrypted PDF uploaded to Unlock PDF -> Rejection
        $plainFile = $this->createPdfFixture('plain_unlock_attempt.pdf', 2);
        $unlockUnenc = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $plainFile)
            ->set('password', 'dummy_pass')
            ->call('process');

        $unlockUnenc->assertHasErrors(['upload']);
        $this->assertStringNotContainsString('completed', $unlockUnenc->get('jobStatus'));

        // 2. Encrypted PDF with INCORRECT password -> Rejection & password wipe
        $encFile = $this->createEncryptedPdfFixture('locked_for_wrong_pass.pdf', 'CorrectPass123!');
        $unlockWrong = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $encFile)
            ->set('password', 'WrongPassword999!')
            ->call('process');

        $unlockWrong->assertHasErrors(['password']);
        $this->assertSame('', $unlockWrong->get('password'));
        $this->assertStringNotContainsString('WrongPassword999!', json_encode($unlockWrong->instance()->all()));

        // 3. Corrupt/malformed file uploaded to Unlock PDF -> Graceful rejection
        $corruptFile = UploadedFile::fake()->createWithContent('corrupt.pdf', "%PDF-1.4\n%%EOF\nGARBAGE_BYTES_NOT_A_REAL_PDF");
        $unlockCorrupt = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $corruptFile)
            ->set('password', 'AnyPassword123!')
            ->call('process');

        $unlockCorrupt->assertHasErrors();
        $this->assertSame('', $unlockCorrupt->get('password'));

        // 4. Verify no stray Ghostscript temp directories left in storage
        $scratchMatches = glob($storage->getRootPath() . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'gs_*');
        $this->assertEmpty($scratchMatches, 'No stray Ghostscript temporary directories should remain after execution.');
    }

    public function test_pdf_to_images_multipage_order_and_dpi_resolution_verification(): void
    {
        $service = app(\App\Services\Pdf\PdfToImageService::class);
        $sourceFile = $this->createPdfFixture('multipage_render_test.pdf', 3);
        $sourcePath = $sourceFile->getRealPath();

        $outDir150 = $this->tempDir . DIRECTORY_SEPARATOR . 'render_150';
        $outDir300 = $this->tempDir . DIRECTORY_SEPARATOR . 'render_300';
        $outDirPng = $this->tempDir . DIRECTORY_SEPARATOR . 'render_png';

        // 1. Convert at 150 DPI (JPG)
        $images150 = $service->convertPdfToImages($sourcePath, $outDir150, 'jpg', 150);
        $this->assertCount(3, $images150, 'Every page of 3-page PDF must be converted');

        // Check sequential page numbering and order
        $this->assertEquals(1, $images150[0]['page']);
        $this->assertEquals(2, $images150[1]['page']);
        $this->assertEquals(3, $images150[2]['page']);

        $size150 = getimagesize($images150[0]['path']);
        $this->assertNotFalse($size150, 'Rendered 150 DPI JPEG must be valid and readable');
        $this->assertEquals('image/jpeg', $size150['mime']);

        // 2. Convert at 300 DPI (JPG)
        $images300 = $service->convertPdfToImages($sourcePath, $outDir300, 'jpg', 300);
        $this->assertCount(3, $images300);
        $size300 = getimagesize($images300[0]['path']);
        $this->assertNotFalse($size300, 'Rendered 300 DPI JPEG must be valid and readable');

        // 300 DPI linear resolution must be roughly 2x the 150 DPI resolution (within 5% tolerance)
        $ratioW = $size300[0] / $size150[0];
        $ratioH = $size300[1] / $size150[1];
        $this->assertEqualsWithDelta(2.0, $ratioW, 0.05, '300 DPI width must be exactly 2x 150 DPI width');
        $this->assertEqualsWithDelta(2.0, $ratioH, 0.05, '300 DPI height must be exactly 2x 150 DPI height');

        // 3. Convert to PNG format
        $imagesPng = $service->convertPdfToImages($sourcePath, $outDirPng, 'png', 150);
        $this->assertCount(3, $imagesPng);
        $sizePng = getimagesize($imagesPng[0]['path']);
        $this->assertNotFalse($sizePng, 'Rendered PNG must be valid and readable');
        $this->assertEquals('image/png', $sizePng['mime']);
    }

    public function test_compress_pdf_representative_and_large_documents_with_integrity(): void
    {
        $compressService = app(\App\Services\Pdf\PdfCompressionService::class);
        $detector = app(\App\Services\System\SystemBinaryDetector::class);

        // 1. Representative document (12-page PDF fixture)
        $repSource = base_path('tests/Fixtures/document_12_pages.pdf');
        $repOutput = $this->tempDir . DIRECTORY_SEPARATOR . 'compressed_12_pages.pdf';

        $resRep = $compressService->compress($repSource, $repOutput, \App\Enums\CompressionLevel::RECOMMENDED);
        $this->assertTrue(file_exists($repOutput));
        $this->assertGreaterThan(0, filesize($repOutput));

        // Verify compressed output opens and is valid PDF
        $fpdi = new \setasign\Fpdi\Fpdi();
        $repPageCount = $fpdi->setSourceFile($repOutput);
        $this->assertEquals(12, $repPageCount, 'Compressed PDF must open successfully and retain all 12 pages');

        // 2. Create an image-heavy PDF that achieves significant compression
        $imgPdfPath = $this->tempDir . DIRECTORY_SEPARATOR . 'image_heavy.pdf';
        $imgOutput = $this->tempDir . DIRECTORY_SEPARATOR . 'compressed_image_heavy.pdf';

        // Generate uncompressed test image
        $tempImg = $this->tempDir . DIRECTORY_SEPARATOR . 'large_sample.jpg';
        $im = imagecreatetruecolor(1200, 1600);
        for ($y = 0; $y < 1600; $y += 40) {
            $col = imagecolorallocate($im, ($y * 7) % 255, ($y * 13) % 255, ($y * 19) % 255);
            imagefilledrectangle($im, 0, $y, 1200, $y + 40, $col);
        }
        imagejpeg($im, $tempImg, 100);
        imagedestroy($im);

        $pdf = new FPDF('P', 'mm', 'A4');
        $pdf->AddPage();
        $pdf->Image($tempImg, 10, 10, 190, 260);
        $pdf->Output('F', $imgPdfPath);

        $origSize = filesize($imgPdfPath);
        $resImg = $compressService->compress($imgPdfPath, $imgOutput, \App\Enums\CompressionLevel::EXTREME);

        $this->assertTrue(file_exists($imgOutput));
        $compressedSize = filesize($imgOutput);

        // If Ghostscript is present, verify deep compression occurred
        if ($detector->hasGhostscript()) {
            $this->assertLessThan($origSize, $compressedSize, 'Extreme compression with Ghostscript must reduce image-heavy PDF size');
            $this->assertGreaterThan(0.0, $resImg['savings_percent']);
        }

        // Verify output integrity
        $fpdiImg = new \setasign\Fpdi\Fpdi();
        $imgPages = $fpdiImg->setSourceFile($imgOutput);
        $this->assertEquals(1, $imgPages, 'Compressed image PDF must be valid and readable');
    }

    public function test_large_file_resource_and_progressive_preview_verification(): void
    {
        $largePdfPath = base_path('tests/Fixtures/document_100_pages.pdf');
        $this->assertTrue(file_exists($largePdfPath));

        $startMem = memory_get_peak_usage(true);
        $startTime = hrtime(true);

        $largeFile = UploadedFile::fake()->createWithContent('document_100_pages.pdf', file_get_contents($largePdfPath));

        $test = Livewire::test(CompressPdfComponent::class)
            ->set('upload', $largeFile);

        $durationMs = (int) round((hrtime(true) - $startTime) / 1e6);
        $peakMemMb = round((memory_get_peak_usage(true) - $startMem) / (1024 * 1024), 2);

        // Verify page count detected accurately
        $test->assertSet('pageCount', 100);

        // Verify Livewire payload remains minimal (< 5 KB) even for 100-page document
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot));
        $this->assertStringNotContainsString('data:image', $snapshot);

        // Verify that only Page 1 preview is requested on-demand, not all 100 pages
        $page1Url = $test->instance()->getPreviewUrl(1, 120);
        $this->assertStringContainsString('page=1', $page1Url);
        $this->assertStringNotContainsString('page=2', $page1Url);
        $this->assertStringNotContainsString('page=100', $page1Url);

        // Ensure reasonable response time (< 5 seconds)
        $this->assertLessThan(5000, $durationMs, "100-page document upload must complete within 5 seconds (took {$durationMs}ms)");
    }
}
