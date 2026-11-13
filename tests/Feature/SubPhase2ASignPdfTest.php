<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\SignPdfComponent;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfSigningService;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class SubPhase2ASignPdfTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subphase2a_test_' . uniqid('', true);
        @mkdir($this->tempDir, 0777, true);
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
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $this->deleteRecursive($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected function createPdfFixture(int $pages = 3): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . 'doc_' . uniqid() . '.pdf';
        $pdf = new FPDF('P', 'mm', 'A4');
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(40, 10, "Page {$i} Test Content");
        }
        $pdf->Output($path, 'F');
        return $path;
    }

    protected function createSamplePngDataUri(): string
    {
        $im = imagecreatetruecolor(120, 50);
        $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $trans);
        imagesavealpha($im, true);
        $black = imagecolorallocate($im, 15, 23, 42);
        imageline($im, 10, 40, 110, 10, $black);
        imageline($im, 10, 10, 110, 40, $black);

        ob_start();
        imagepng($im);
        $binary = ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    public function test_no_base64_in_public_state_and_snapshot_size_budgets(): void
    {
        $pdfPath = $this->createPdfFixture(4);
        $uploaded = UploadedFile::fake()->createWithContent('document.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        // Verify public properties do NOT have pagePreviews or activeSignaturePreview
        $reflection = new \ReflectionClass(SignPdfComponent::class);
        $this->assertFalse($reflection->hasProperty('pagePreviews'), 'pagePreviews array must be eliminated');
        $this->assertFalse($reflection->hasProperty('activeSignaturePreview'), 'activeSignaturePreview must be eliminated');

        // Measure initial state snapshot payload size
        $initialSnapshot = json_encode($test->snapshot);
        $initialBytes = strlen($initialSnapshot);
        $this->assertLessThan(2500, $initialBytes, "Initial snapshot should be < 2.5 KB (got {$initialBytes} bytes)");

        // Measure page navigation snapshot size
        $test->call('goToPage', 2);
        $navSnapshot = json_encode($test->snapshot);
        $navBytes = strlen($navSnapshot);
        $this->assertLessThan(2800, $navBytes, "Page navigation snapshot should be < 2.8 KB (got {$navBytes} bytes)");

        // Place drawn signature
        $dataUri = $this->createSamplePngDataUri();
        $test->call('setDrawData', $dataUri);

        // Verify placements array contains NO Base64 strings
        $placements = $test->get('placements');
        $this->assertCount(1, $placements);
        $p1 = $placements[0];
        $this->assertEquals('draw', $p1['signature_type']);
        $this->assertArrayNotHasKey('signature_data', $p1, 'Placement must not contain signature_data Base64 string');
        $this->assertStringStartsWith('signatures/sig_', $p1['signature_path']);

        $singlePlaceSnapshot = json_encode($test->snapshot);
        $singlePlaceBytes = strlen($singlePlaceSnapshot);
        $this->assertLessThan(3000, $singlePlaceBytes, "Single placement snapshot should be < 3.0 KB (got {$singlePlaceBytes} bytes)");

        // Add typed signature and navigate to page 3
        $test->set('typedName', 'Alexandre Dumas')
            ->call('setTypedStyle', 'brush')
            ->call('applyTypedSignature')
            ->call('goToPage', 3);

        $multiSnapshot = json_encode($test->snapshot);
        $multiBytes = strlen($multiSnapshot);
        $this->assertLessThan(3500, $multiBytes, "Multi placement snapshot should be < 3.5 KB (got {$multiBytes} bytes)");

        // Placements must only contain lightweight metadata
        $allPlacements = $test->get('placements');
        $this->assertCount(2, $allPlacements);
        foreach ($allPlacements as $p) {
            foreach ($p as $k => $v) {
                if (is_string($v)) {
                    $this->assertStringNotContainsString('data:image', $v, "Placement field {$k} must not contain data URI");
                    $this->assertLessThan(200, strlen($v), "Placement field {$k} should be short metadata");
                }
            }
        }
    }

    public function test_signed_preview_route_and_security_isolation(): void
    {
        $pdfPath = $this->createPdfFixture(2);
        $uploaded = UploadedFile::fake()->createWithContent('preview_doc.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $url = $test->instance()->getPagePreviewUrl(1, 150);
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('tools/pdf-preview', $url);
        $this->assertStringContainsString('dpi=150', $url);

        // Fetching page preview with valid signed route returns 200 JPEG
        $response = $this->get($url);
        $response->assertStatus(200);
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));

        // Tampered signature or invalid signature returns 403
        $tamperedUrl = $url . 'tampered';
        $this->get($tamperedUrl)->assertStatus(403);
    }

    public function test_signature_preview_route_and_session_isolation(): void
    {
        $pdfPath = $this->createPdfFixture(2);
        $uploaded = UploadedFile::fake()->createWithContent('sig_test.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $test->call('setDrawData', $dataUri);

        $placements = $test->get('placements');
        $this->assertCount(1, $placements);

        $sigUrl = $test->instance()->getSignaturePreviewUrl($placements[0]);
        $this->assertNotEmpty($sigUrl);
        $this->assertStringContainsString('tools/signature-preview', $sigUrl);

        // Fetch signature preview with valid signed route returns 200 PNG
        $response = $this->get($sigUrl);
        $response->assertStatus(200);
        $this->assertEquals('image/png', $response->headers->get('Content-Type'));

        // Tampered signature returns 403
        $this->get($sigUrl . 'tampered')->assertStatus(403);

        // Path traversal in filename parameter rejected
        $traversalUrl = URL::temporarySignedRoute('tools.signature-preview', now()->addHour(), [
            'filename' => '../secret.txt',
            'job_id' => $test->get('jobId'),
            'session_id' => session()->getId(),
        ]);
        $this->get($traversalUrl)->assertStatus(400);
    }

    public function test_uploaded_signature_normalizes_to_disk_and_resets_upload_property(): void
    {
        $pdfPath = $this->createPdfFixture(1);
        $uploaded = UploadedFile::fake()->createWithContent('upload_doc.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        // Create sample signature image file
        $sigImage = UploadedFile::fake()->image('my_signature.png', 200, 80);
        $test->set('signatureUpload', $sigImage);

        // Verify signatureUpload was reset to null to prevent public state ballooning
        $this->assertNull($test->get('signatureUpload'));

        $placements = $test->get('placements');
        $this->assertCount(1, $placements);
        $p = $placements[0];
        $this->assertEquals('upload', $p['signature_type']);
        $this->assertStringStartsWith('signatures/sig_up_', $p['signature_path']);

        // Verify the file was created on disk in the authorized job directory
        $jobId = $test->get('jobId');
        $storage = app(FileStorageManager::class);
        $fullPath = $storage->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $p['signature_path'];
        $this->assertFileExists($fullPath);
    }

    public function test_typed_signature_supports_arabic_and_native_stamping(): void
    {
        $pdfPath = $this->createPdfFixture(2);
        $uploaded = UploadedFile::fake()->createWithContent('arabic_doc.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        // Type Arabic signature
        $arabicName = 'محمد عبد الله';
        $test->set('typedName', $arabicName)
            ->call('setTypedStyle', 'casual')
            ->call('applyTypedSignature');

        $placements = $test->get('placements');
        $this->assertCount(1, $placements);
        $p = $placements[0];
        $this->assertEquals('type', $p['signature_type']);
        $this->assertEquals($arabicName, $p['signature_text']);
        $this->assertEquals('casual', $p['signature_style']);

        // Process document
        $test->call('process')
            ->assertSet('jobStatus', 'completed');

        $jobId = $test->get('fileJobId');
        $fileJob = FileJob::find($jobId);
        $this->assertNotNull($fileJob);
        $this->assertEquals(JobStatus::COMPLETED, $fileJob->status);

        $storage = app(FileStorageManager::class);
        $outputPath = $storage->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $fileJob->input_metadata['output_filename'];
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(500, filesize($outputPath));

        // Verify PDF magic bytes
        $header = file_get_contents($outputPath, false, null, 0, 5);
        $this->assertEquals('%PDF-', $header);
    }

    public function test_coordinate_mapping_and_rotation_bounds(): void
    {
        $pdfPath = $this->createPdfFixture(2);
        $uploaded = UploadedFile::fake()->createWithContent('coord_doc.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $test->call('setDrawData', $dataUri);

        $placements = $test->get('placements');
        $id = $placements[0]['id'];

        // Test coordinate updates with valid values
        $test->call('updatePlacement', $id, 0.45, 0.55, 0.25, 0.10, 90);
        $updated = $test->get('placements')[0];
        $this->assertEqualsWithDelta(0.45, $updated['x_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.55, $updated['y_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.25, $updated['width_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.10, $updated['height_ratio'], 0.001);
        $this->assertEquals(90, $updated['rotation']);

        // Test rotation action
        $test->call('rotatePlacement', $id, 90);
        $rotated = $test->get('placements')[0];
        $this->assertEquals(180, $rotated['rotation']);

        // Rotate again
        $test->call('rotatePlacement', $id, 90);
        $rotated270 = $test->get('placements')[0];
        $this->assertEquals(270, $rotated270['rotation']);

        // Test clamping: xRatio out of bounds should be clamped
        $test->call('updatePlacement', $id, 1.5, -0.5, 0.30, 0.15, 0);
        $clamped = $test->get('placements')[0];
        $this->assertEqualsWithDelta(0.70, $clamped['x_ratio'], 0.001); // 1.0 - width_ratio
        $this->assertEqualsWithDelta(0.0, $clamped['y_ratio'], 0.001);
    }

    public function test_placement_removal_and_cleanup(): void
    {
        $pdfPath = $this->createPdfFixture(1);
        $uploaded = UploadedFile::fake()->createWithContent('clean_doc.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $test->call('setDrawData', $dataUri);

        $p = $test->get('placements')[0];
        $jobId = $test->get('jobId');
        $storage = app(FileStorageManager::class);
        $filePath = $storage->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $p['signature_path'];
        $this->assertFileExists($filePath);

        // Remove placement -> file should be deleted from disk
        $test->call('removePlacement', $p['id']);
        $this->assertCount(0, $test->get('placements'));
        $this->assertFileDoesNotExist($filePath);

        // Add 2 signatures, clear all, verify files deleted
        $test->call('setDrawData', $dataUri);
        $test->call('setDrawData', $dataUri);
        $this->assertCount(2, $test->get('placements'));

        $test->call('clearPlacements');
        $this->assertCount(0, $test->get('placements'));
    }

    public function test_placement_removal_cannot_delete_files_outside_signature_directory(): void
    {
        $pdfPath = $this->createPdfFixture(1);
        $uploaded = UploadedFile::fake()->createWithContent('safe_doc.pdf', file_get_contents($pdfPath));
        $test = Livewire::test(SignPdfComponent::class)->set('upload', $uploaded);

        $jobId = $test->get('jobId');
        $storage = app(FileStorageManager::class);
        $jobDirectory = $storage->getJobDirectory($jobId);
        $sentinel = dirname($jobDirectory).DIRECTORY_SEPARATOR.'sign_pdf_cleanup_sentinel.txt';
        file_put_contents($sentinel, 'preserve');

        try {
            $test->set('placements', [[
                'id' => 'forged-placement',
                'page' => 1,
                'x_ratio' => 0.1,
                'y_ratio' => 0.1,
                'width_ratio' => 0.2,
                'height_ratio' => 0.1,
                'rotation' => 0,
                'signature_type' => 'upload',
                'signature_path' => '../sign_pdf_cleanup_sentinel.txt',
                'signature_filename' => null,
                'signature_text' => null,
                'signature_style' => 'classic',
            ]])->call('removePlacement', 'forged-placement');

            $this->assertFileExists($sentinel);
            $this->assertSame('preserve', file_get_contents($sentinel));
        } finally {
            @unlink($sentinel);
        }
    }

    public function test_processing_rejects_client_supplied_signature_paths(): void
    {
        $pdfPath = $this->createPdfFixture(1);
        $uploaded = UploadedFile::fake()->createWithContent('safe_doc.pdf', file_get_contents($pdfPath));
        $externalImage = $this->tempDir.DIRECTORY_SEPARATOR.'external_signature.png';
        file_put_contents($externalImage, base64_decode(substr($this->createSamplePngDataUri(), strlen('data:image/png;base64,'))));

        $test = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('placements', [[
                'id' => 'forged-placement',
                'page' => 1,
                'x_ratio' => 0.1,
                'y_ratio' => 0.1,
                'width_ratio' => 0.2,
                'height_ratio' => 0.1,
                'rotation' => 0,
                'signature_type' => 'upload',
                'signature_path' => $externalImage,
                'signature_filename' => basename($externalImage),
                'signature_text' => null,
                'signature_style' => 'classic',
            ]]);

        try {
            $test->call('process')->assertSet('jobStatus', 'failed');
            $this->assertSame('Signature image is not available in this signing session.', $test->get('errorMessage'));
        } finally {
            $test->call('resetTool');
        }
    }
}
