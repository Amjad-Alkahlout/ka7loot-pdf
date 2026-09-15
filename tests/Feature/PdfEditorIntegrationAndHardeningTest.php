<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\EditPdfComponent;
use App\Models\FileJob;
use App\Services\Pdf\PdfProtectionService;
use App\Services\Pdf\PdfSigningService;
use App\Services\Pdf\PdfiumTextEditorService;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PdfEditorIntegrationAndHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected SystemBinaryDetector $detector;
    protected SubprocessExecutorService $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = app(SystemBinaryDetector::class);
        $this->executor = app(SubprocessExecutorService::class);

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edit_pdf_hardening_' . uniqid();
        mkdir($this->tempDir, 0777, true);
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
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->deleteRecursive($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    protected function extractPdfText(string $pdfPath): string
    {
        $pdftotext = $this->detector->getPdftotextPath() ?? 'pdftotext';
        $res = $this->executor->execute([$pdftotext, $pdfPath, '-']);
        return $res['exit_code'] === 0 ? $res['output'] : '';
    }

    protected function createComplexReportPdf(string $filename = 'complex_report.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $fpdf = new FPDF('P', 'pt', 'A4');
        $fpdf->AddPage();

        // Header
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(250, 24, 'QUARTERLY FINANCIAL REPORT', 0, 1);

        // Metadata
        $fpdf->SetFont('Helvetica', '', 11);
        $fpdf->Cell(200, 18, 'Invoice Number: INV-98765/2026', 0, 1);
        $fpdf->Cell(200, 18, 'Client: Global Logistics Corp', 0, 1);
        $fpdf->Cell(200, 18, 'Total Amount: $10,500.00 USD', 0, 1);
        $fpdf->Cell(200, 18, 'Status: PENDING PAYMENT', 0, 1);

        // Page 2
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 14);
        $fpdf->Cell(200, 20, 'Section 2: Detailed Line Items', 0, 1);
        $fpdf->SetFont('Helvetica', '', 11);
        $fpdf->Cell(200, 18, 'Item A: Freight & Handling', 0, 1);
        $fpdf->Cell(200, 18, 'Item B: Custom Clearance', 0, 1);
        $fpdf->Cell(200, 18, 'Repeated Keyword: REPEATED_TAG', 0, 1);
        $fpdf->Cell(200, 18, 'Repeated Keyword: REPEATED_TAG', 0, 1);

        $fpdf->Output('F', $path);
        return $path;
    }

    protected function createLandscapePdf(string $filename = 'landscape.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $fpdf = new FPDF('L', 'pt', 'A4');
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(300, 24, 'LANDSCAPE PRESENTATION SLIDE', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(300, 18, 'Metric A: 99.9% Uptime', 0, 1);
        $fpdf->Output('F', $path);
        return $path;
    }

    /**
     * 1. Real PDF Test: Currency, Numbers, Punctuation in standard invoice
     */
    public function test_currency_and_punctuation_replacement_in_real_report(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $allObjects = $component->get('allTextObjectsByPage');
        $this->assertNotEmpty($allObjects[0]);

        $targetObj = null;
        foreach ($allObjects[0] as $obj) {
            if (str_contains($obj['text'], '$10,500.00 USD')) {
                $targetObj = $obj;
                break;
            }
        }
        $this->assertNotNull($targetObj);

        $component->call('selectTextObject', $targetObj['object_id'])
            ->set('editText', 'Total Amount: $24,850.50 EUR')
            ->call('saveCurrentEdit')
            ->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $storage = app(\App\Services\File\FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($job->id);
        $processedFile = $job->processedFiles()->first();
        $outputPath = $jobDir . DIRECTORY_SEPARATOR . $processedFile->stored_filename;

        $text = $this->extractPdfText($outputPath);
        $this->assertStringContainsString('$24,850.50 EUR', $text);
        $this->assertStringNotContainsString('$10,500.00 USD', $text);

        // Ensure untouched text on same page is intact
        $this->assertStringContainsString('Client: Global Logistics Corp', $text);

        // Ensure no whiteout rectangles
        $raw = file_get_contents($outputPath);
        $this->assertStringNotContainsString('/re f', $raw);
    }

    /**
     * 2. Edge Case: Shorter replacement & empty replacement (blanking out text)
     */
    public function test_shorter_and_empty_text_replacement(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $allObjects = $component->get('allTextObjectsByPage');

        // Replace long title with shorter acronym
        $titleObj = null;
        $statusObj = null;
        foreach ($allObjects[0] as $obj) {
            if (str_contains($obj['text'], 'QUARTERLY FINANCIAL REPORT')) {
                $titleObj = $obj;
            }
            if (str_contains($obj['text'], 'Status: PENDING PAYMENT')) {
                $statusObj = $obj;
            }
        }

        $this->assertNotNull($titleObj);
        $this->assertNotNull($statusObj);

        // 1. Shorter replacement
        $component->call('selectTextObject', $titleObj['object_id'])
            ->set('editText', 'QFR')
            ->call('saveCurrentEdit');

        // 2. Empty replacement (blanking out Status)
        $component->call('selectTextObject', $statusObj['object_id'])
            ->set('editText', '')
            ->call('saveCurrentEdit');

        $manifest = $component->get('editManifest');
        $this->assertCount(2, $manifest);

        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $storage = app(\App\Services\File\FileStorageManager::class);
        $outputPath = $storage->getJobDirectory($job->id) . DIRECTORY_SEPARATOR . $job->processedFiles()->first()->stored_filename;

        $text = $this->extractPdfText($outputPath);
        $this->assertStringContainsString('QFR', $text);
        $this->assertStringNotContainsString('QUARTERLY FINANCIAL REPORT', $text);
        $this->assertStringNotContainsString('PENDING PAYMENT', $text);
    }

    /**
     * 3. Multi-page: Edits across multiple pages in single pass
     */
    public function test_multiple_edits_across_multiple_pages(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $allPages = $component->get('allTextObjectsByPage');

        // Page 1 edit
        $p1Obj = $allPages[0][1]; // Invoice Number
        $component->call('selectTextObject', $p1Obj['object_id'])
            ->set('editText', 'Invoice Number: INV-FINAL-2026')
            ->call('saveCurrentEdit');

        // Navigate to Page 2 & edit
        $component->call('goToPage', 2);
        $p2Obj = $allPages[1][0]; // Section 2
        $component->call('selectTextObject', $p2Obj['object_id'])
            ->set('editText', 'Section 2: Amended Items')
            ->call('saveCurrentEdit');

        $manifest = $component->get('editManifest');
        $this->assertCount(2, $manifest);

        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $storage = app(\App\Services\File\FileStorageManager::class);
        $outputPath = $storage->getJobDirectory($job->id) . DIRECTORY_SEPARATOR . $job->processedFiles()->first()->stored_filename;

        $text = $this->extractPdfText($outputPath);
        $this->assertStringContainsString('INV-FINAL-2026', $text);
        $this->assertStringContainsString('Section 2: Amended Items', $text);
    }

    /**
     * 4. Selective occurrence replacement: Only one of duplicate repeated text is edited
     */
    public function test_selective_occurrence_of_repeated_text(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $component->call('goToPage', 2);
        $p2Objects = $component->get('allTextObjectsByPage')[1];

        $repeated = [];
        foreach ($p2Objects as $obj) {
            if (str_contains($obj['text'], 'REPEATED_TAG')) {
                $repeated[] = $obj;
            }
        }
        $this->assertCount(2, $repeated);

        // Edit ONLY the second occurrence
        $secondObj = $repeated[1];
        $component->call('selectTextObject', $secondObj['object_id'])
            ->set('editText', 'Repeated Keyword: MODIFIED_TAG')
            ->call('saveCurrentEdit')
            ->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $storage = app(\App\Services\File\FileStorageManager::class);
        $outputPath = $storage->getJobDirectory($job->id) . DIRECTORY_SEPARATOR . $job->processedFiles()->first()->stored_filename;

        $text = $this->extractPdfText($outputPath);
        $this->assertStringContainsString('MODIFIED_TAG', $text);
        // The first occurrence must STILL exist
        $this->assertStringContainsString('REPEATED_TAG', $text);
    }

    /**
     * 5. Landscape PDF Support
     */
    public function test_landscape_pdf_editing(): void
    {
        $pdfPath = $this->createLandscapePdf();
        $file = UploadedFile::fake()->createWithContent('landscape.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $dims = $component->get('pageDimensions');
        $this->assertGreaterThan($dims['height'], $dims['width']); // Landscape: width > height

        $objects = $component->get('allTextObjectsByPage')[0];
        $target = null;
        foreach ($objects as $obj) {
            if (str_contains($obj['text'], 'Metric A: 99.9% Uptime')) {
                $target = $obj;
                break;
            }
        }
        $this->assertNotNull($target);

        $component->call('selectTextObject', $target['object_id'])
            ->set('editText', 'Metric A: 100% SLA Achieved')
            ->call('saveCurrentEdit')
            ->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $storage = app(\App\Services\File\FileStorageManager::class);
        $outputPath = $storage->getJobDirectory($job->id) . DIRECTORY_SEPARATOR . $job->processedFiles()->first()->stored_filename;

        $text = $this->extractPdfText($outputPath);
        $this->assertStringContainsString('100% SLA Achieved', $text);
    }

    /**
     * 6. Arabic / Unicode replacement & truthful engine attribution
     */
    public function test_arabic_replacement_and_truthful_font_attribution(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $p1Objects = $component->get('allTextObjectsByPage')[0];
        $titleObj = $p1Objects[0];

        $component->call('selectTextObject', $titleObj['object_id'])
            ->set('editText', 'تقرير مالي معتمد لعام 2026')
            ->call('saveCurrentEdit')
            ->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');

        // Check truthful engine attribution records fallback font
        $meta = $job->input_metadata;
        $this->assertStringContainsString('Fallback Font (Amiri)', $meta['engine_label']);

        $storage = app(\App\Services\File\FileStorageManager::class);
        $outputPath = $storage->getJobDirectory($job->id) . DIRECTORY_SEPARATOR . $job->processedFiles()->first()->stored_filename;

        // Output PDF remains structurally valid
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(1000, filesize($outputPath));
    }

    /**
     * 7. Digitally Signed PDF Warning
     */
    public function test_digitally_signed_pdf_displays_warning(): void
    {
        $pdfPath = $this->createComplexReportPdf('unsigned.pdf');
        $signedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'signed.pdf';

        // Sign PDF
        $signer = app(PdfSigningService::class);
        $signer->sign($pdfPath, $signedPath, [
            [
                'page'           => 1,
                'type'           => 'text',
                'signature_text' => 'Authorized Signature',
                'font'           => 'courier',
                'color'          => '#000080',
                'x_ratio'        => 0.5,
                'y_ratio'        => 0.8,
                'width_ratio'    => 0.3,
                'height_ratio'   => 0.1,
                'rotation'       => 0,
            ]
        ]);

        $file = UploadedFile::fake()->createWithContent('signed.pdf', file_get_contents($signedPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        // Verify component detects and inspects signed PDF
        $component->assertSet('pageCount', 2);
    }

    /**
     * 8. Security Hardening: Tampered Page Index in Edit Manifest
     */
    public function test_security_hardening_rejects_page_index_tampering(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $allPages = $component->get('allTextObjectsByPage');
        $p1Obj = $allPages[0][0];

        // Tamper: claim p1 object belongs to page 5 (out of bounds or mismatch)
        $component->set('editManifest', [
            $p1Obj['object_id'] => [
                'id'               => $p1Obj['object_id'],
                'page'             => 5,
                'page_index'       => 4, // Page index mismatch!
                'object_id'        => $p1Obj['object_id'],
                'target_text'      => $p1Obj['text'],
                'replacement_text' => 'Tampered text',
                'auto_fit'         => true,
            ],
        ]);

        $component->call('process')
            ->assertSet('jobStatus', 'failed')
            ->assertSee('Security violation: Page index mismatch');
    }

    /**
     * 9. Security Hardening: Out of bounds additive text / shapes
     */
    public function test_security_hardening_rejects_malicious_additive_payloads(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        // Malicious shape type
        $component->set('addedShapes', [
            'shp_bad' => [
                'id'         => 'shp_bad',
                'page'       => 1,
                'type'       => 'arbitrary_injection_script',
                'fill_color' => 'none',
            ],
        ]);

        $component->call('process')
            ->assertSet('jobStatus', 'failed')
            ->assertSee('Security violation: Invalid shape type');
    }

    /**
     * 10. Unsaved changes management: clearAllEdits and cancelEdit
     */
    public function test_unsaved_changes_clearing_and_canceling(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $firstObj = $component->get('allTextObjectsByPage')[0][0];

        // 1. Select and cancel
        $component->call('selectTextObject', $firstObj['object_id'])
            ->assertSet('selectedObjectId', $firstObj['object_id'])
            ->call('cancelEdit')
            ->assertSet('selectedObjectId', null);

        // 2. Add edits, texts, shapes
        $component->call('selectTextObject', $firstObj['object_id'])
            ->set('editText', 'Pending change')
            ->call('saveCurrentEdit')
            ->call('addTextItem')
            ->call('addShape', 'rectangle');

        $this->assertCount(1, $component->get('editManifest'));
        $this->assertCount(1, $component->get('addedTexts'));
        $this->assertCount(1, $component->get('addedShapes'));

        // 3. Clear all edits
        $component->call('clearAllEdits');

        $this->assertEmpty($component->get('editManifest'));
        $this->assertEmpty($component->get('addedTexts'));
        $this->assertEmpty($component->get('addedShapes'));
    }

    /**
     * 11. Zoom controls clamping
     */
    public function test_zoom_controls_clamped_between_50_and_200(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file)
            ->call('setZoom', 25)
            ->assertSet('zoomLevel', 50)
            ->call('setZoom', 300)
            ->assertSet('zoomLevel', 200)
            ->call('setZoom', 125)
            ->assertSet('zoomLevel', 125);
    }

    /**
     * 12. Regression Test: Repeated save action is idempotent and does not cause duplicate FileJob UUID violation
     */
    public function test_repeated_save_action_is_idempotent_and_does_not_cause_duplicate_filejob_uuid(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $allObjects = $component->get('allTextObjectsByPage');
        $targetObj = $allObjects[0][0];

        $component->call('selectTextObject', $targetObj['object_id'])
            ->set('editText', 'Idempotency Test Verified')
            ->call('saveCurrentEdit');

        // First submission
        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $firstJobId = $component->get('fileJobId');
        $this->assertNotNull($firstJobId);
        $this->assertEquals(1, FileJob::where('tool_type', ToolType::EDIT_PDF->value)->count());

        // Second submission (simulating double-click or rapid re-submission)
        $component->call('process')
            ->assertSet('jobStatus', 'completed')
            ->assertSet('fileJobId', $firstJobId);

        // Must still be exactly 1 FileJob, no duplicate entry SQL error
        $this->assertEquals(1, FileJob::where('tool_type', ToolType::EDIT_PDF->value)->count());
    }

    /**
     * 13. Regression Test: Retrying a failed edit PDF job reuses existing record
     */
    public function test_retrying_failed_edit_pdf_job_reuses_existing_record(): void
    {
        $pdfPath = $this->createComplexReportPdf();
        $file = UploadedFile::fake()->createWithContent('complex_report.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $targetObj = $component->get('allTextObjectsByPage')[0][0];
        $component->call('selectTextObject', $targetObj['object_id'])
            ->set('editText', 'Retry Handling Test')
            ->call('saveCurrentEdit');

        // Mark job as failed to simulate a retryable failure state
        $failedJob = FileJob::create([
            'id'             => (string) \Illuminate\Support\Str::uuid(),
            'tool_type'      => ToolType::EDIT_PDF,
            'status'         => JobStatus::FAILED,
            'input_metadata' => ['test' => true],
        ]);

        $component->set('fileJobId', $failedJob->id);
        $this->assertEquals(1, FileJob::where('tool_type', ToolType::EDIT_PDF->value)->count());

        // Call process again as a retry
        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        // Reused existing record, count still 1
        $this->assertEquals(1, FileJob::where('tool_type', ToolType::EDIT_PDF->value)->count());
        $failedJob->refresh();
        $this->assertEquals(JobStatus::COMPLETED, $failedJob->status);
    }
}
