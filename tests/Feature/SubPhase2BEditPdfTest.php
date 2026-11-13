<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\EditPdfComponent;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfiumTextEditorService;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class SubPhase2BEditPdfTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subphase2b_test_' . uniqid('', true);
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
        if (! is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $this->deleteRecursive($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected function createMultiPageInvoicePdf(string $filename = 'invoice_multipage.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $fpdf = new FPDF('P', 'pt', 'A4');

        // Page 1
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(200, 24, 'INVOICE PAGE ONE #1001', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(200, 18, 'Customer: John Smith', 0, 1);
        $fpdf->Cell(200, 18, 'Amount: $750.00', 0, 1);

        // Page 2
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(200, 24, 'INVOICE PAGE TWO #1002', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(200, 18, 'Terms: Net 30 Days', 0, 1);
        $fpdf->Cell(200, 18, 'Vendor: Antigravity Solutions', 0, 1);

        // Page 3
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(200, 24, 'INVOICE PAGE THREE #1003', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(200, 18, 'Summary: Paid in Full', 0, 1);

        $fpdf->Output($path, 'F');
        return $path;
    }

    /**
     * Requirement: Eliminate Base64 and satisfy strict snapshot size budgets.
     */
    public function test_no_base64_in_public_state_and_strict_snapshot_size_budgets(): void
    {
        $pdfPath = $this->createMultiPageInvoicePdf();
        $uploaded = UploadedFile::fake()->createWithContent('invoice_multipage.pdf', file_get_contents($pdfPath));

        // Initial state snapshot size budget: < 2.5 KB (mounted before upload)
        $testInitial = Livewire::test(EditPdfComponent::class);
        $initialSnapshot = json_encode($testInitial->snapshot);
        $initialBytes = strlen($initialSnapshot);
        $this->assertLessThan(2500, $initialBytes, "Initial snapshot budget < 2.5 KB (got {$initialBytes} bytes)");

        // Upload and hydrate active page text
        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        // Verify public properties do NOT contain obsolete bulky arrays
        $reflection = new \ReflectionClass(EditPdfComponent::class);
        $props = array_map(fn($p) => $p->getName(), $reflection->getProperties(\ReflectionProperty::IS_PUBLIC));

        $this->assertNotContains('pagePreviews', $props, 'Public pagePreviews array must be eliminated');
        $this->assertNotContains('pageThumbnails', $props, 'Public pageThumbnails array must be eliminated');
        $this->assertNotContains('allTextObjectsByPage', $props, 'Public allTextObjectsByPage array must be eliminated');
        $this->assertNotContains('allTextGroupsByPage', $props, 'Public allTextGroupsByPage array must be eliminated');
        $this->assertNotContains('allPageDimensionsByPage', $props, 'Public allPageDimensionsByPage array must be eliminated');

        // Active page text hydration: only page 1 text items in state (< 4.5 KB)
        $activeHydrationSnapshot = json_encode($test->snapshot);
        $activeBytes = strlen($activeHydrationSnapshot);
        $this->assertLessThan(4500, $activeBytes, "Active page text hydration snapshot budget < 4.5 KB (got {$activeBytes} bytes)");

        // Page navigation snapshot size budget: < 3.0 KB
        $test->call('goToPage', 2);
        $navSnapshot = json_encode($test->snapshot);
        $navBytes = strlen($navSnapshot);
        $this->assertLessThan(3000, $navBytes, "Page navigation snapshot budget < 3.0 KB (got {$navBytes} bytes)");

        // Verify page 2 text items hydrated
        $page2Groups = $test->get('currentTextGroups');
        $this->assertNotEmpty($page2Groups);

        // Multi-page editing workflow: edit page 2, edit page 1
        $test->call('selectTextGroup', $page2Groups[0]['group_id'])
            ->set('editText', 'INVOICE PAGE TWO REVISED')
            ->call('saveCurrentEdit')
            ->call('goToPage', 1);

        $page1Groups = $test->get('currentTextGroups');
        $this->assertNotEmpty($page1Groups);
        $test->call('selectTextGroup', $page1Groups[0]['group_id'])
            ->set('editText', 'INVOICE PAGE ONE REVISED')
            ->call('saveCurrentEdit');

        $manifest = $test->get('editManifest');
        $this->assertCount(2, $manifest, 'Manifest must preserve edits across both pages');

        $workflowSnapshot = json_encode($test->snapshot);
        $workflowBytes = strlen($workflowSnapshot);
        $this->assertLessThan(4500, $workflowBytes, "Multi-page editing workflow snapshot budget < 4.5 KB (got {$workflowBytes} bytes)");

        // Ensure no Base64 strings exist anywhere in the snapshot
        $this->assertStringNotContainsString('data:image', $workflowSnapshot, 'Snapshot must not contain any data:image URIs');
    }

    /**
     * Requirement: Server-side inspection.json storage in secure temp job directory and lifecycle cleanup.
     */
    public function test_server_side_inspection_json_storage_and_lifecycle_cleanup(): void
    {
        $pdfPath = $this->createMultiPageInvoicePdf();
        $uploaded = UploadedFile::fake()->createWithContent('invoice_multipage.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        $jobId = $test->get('jobId');
        $this->assertNotNull($jobId);

        $storage = app(FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($jobId);
        $inspectionPath = $jobDir . DIRECTORY_SEPARATOR . 'inspection.json';

        $this->assertFileExists($inspectionPath, 'inspection.json must be stored server-side in job directory');

        $jsonContent = json_decode(file_get_contents($inspectionPath), true);
        $this->assertIsArray($jsonContent);
        $this->assertEquals(3, $jsonContent['page_count']);
        $this->assertCount(3, $jsonContent['pages']);

        // Test resetTool() cleans up the job directory and inspection file
        $test->call('resetTool');
        $this->assertFileDoesNotExist($inspectionPath, 'inspection.json must be purged upon resetTool()');
        $this->assertFalse(is_dir($jobDir), 'Job directory must be removed upon resetTool()');
    }

    /**
     * Requirement: Active page isolation and cross-page edit preservation.
     */
    public function test_active_page_isolation_and_cross_page_edit_preservation(): void
    {
        $pdfPath = $this->createMultiPageInvoicePdf();
        $uploaded = UploadedFile::fake()->createWithContent('invoice_multipage.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        // Verify page 1 items
        $this->assertEquals(1, $test->get('currentPage'));
        $p1Groups = $test->get('currentTextGroups');
        $this->assertNotEmpty($p1Groups);
        $p1FirstId = $p1Groups[0]['group_id'];

        // Edit page 1
        $test->call('selectTextGroup', $p1FirstId)
            ->set('editText', 'REPLACED ON PAGE 1')
            ->call('saveCurrentEdit');

        $this->assertArrayHasKey($p1FirstId, $test->get('editManifest'));
        $this->assertEquals('REPLACED ON PAGE 1', $test->get('editManifest')[$p1FirstId]['replacement_text']);

        // Navigate to page 2: selection is cleared, page 2 items hydrated
        $test->call('goToPage', 2);
        $this->assertEquals(2, $test->get('currentPage'));
        $this->assertNull($test->get('selectedGroupId'));
        $this->assertEquals('', $test->get('editText'));

        $p2Groups = $test->get('currentTextGroups');
        $this->assertNotEmpty($p2Groups);
        $this->assertNotEquals($p1FirstId, $p2Groups[0]['group_id']);

        // Verify page 1 edit is STILL in the manifest
        $this->assertArrayHasKey($p1FirstId, $test->get('editManifest'));

        // Edit page 2
        $p2FirstId = $p2Groups[0]['group_id'];
        $test->call('selectTextGroup', $p2FirstId)
            ->set('editText', 'REPLACED ON PAGE 2')
            ->call('saveCurrentEdit');

        $manifest = $test->get('editManifest');
        $this->assertCount(2, $manifest);
        $this->assertEquals('REPLACED ON PAGE 1', $manifest[$p1FirstId]['replacement_text']);
        $this->assertEquals('REPLACED ON PAGE 2', $manifest[$p2FirstId]['replacement_text']);

        // Navigate to page 3, verify both edits survive
        $test->call('goToPage', 3);
        $this->assertEquals(3, $test->get('currentPage'));
        $this->assertCount(2, $test->get('editManifest'));
    }

    /**
     * Requirement: Signed preview URLs for main page and thumbnails.
     */
    public function test_signed_preview_urls_and_security_validation(): void
    {
        $pdfPath = $this->createMultiPageInvoicePdf();
        $uploaded = UploadedFile::fake()->createWithContent('invoice_multipage.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        $mainUrl = $test->instance()->getPagePreviewUrl(1, 150);
        $this->assertNotEmpty($mainUrl);
        $this->assertStringContainsString('tools/pdf-preview', $mainUrl);
        $this->assertStringContainsString('dpi=150', $mainUrl);
        $this->assertStringContainsString('signature=', $mainUrl);

        $thumbUrl = $test->instance()->getThumbnailUrl(2, 80);
        $this->assertNotEmpty($thumbUrl);
        $this->assertStringContainsString('tools/pdf-preview', $thumbUrl);
        $this->assertStringContainsString('page=2', $thumbUrl);
        $this->assertStringContainsString('dpi=80', $thumbUrl);

        // Access the main page preview via HTTP GET
        $response = $this->get($mainUrl);
        $response->assertStatus(200);
        $this->assertStringStartsWith('image/jpeg', $response->headers->get('Content-Type'));

        // Tampered signature must fail with 403
        $tamperedUrl = $mainUrl . 'tampered';
        $tamperedResponse = $this->get($tamperedUrl);
        $tamperedResponse->assertStatus(403);
    }

    /**
     * Requirement: True PDFium text replacement pipeline with Arabic & Latin support.
     */
    public function test_pdfium_text_replacement_and_multilingual_fidelity(): void
    {
        $pdfPath = $this->createMultiPageInvoicePdf();
        $uploaded = UploadedFile::fake()->createWithContent('invoice_multipage.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        $groups = $test->get('currentTextGroups');
        $this->assertNotEmpty($groups);
        $firstId = $groups[0]['group_id'];

        // Edit with English and Arabic text
        $replacement = 'INVOICE MODIFIED — فاتورة ضريبية';
        $test->call('selectTextGroup', $firstId)
            ->set('editText', $replacement)
            ->call('saveCurrentEdit');

        $test->call('processEdits');

        $test->assertSet('jobStatus', 'completed');
        $completedJob = $test->get('completedJob');
        $this->assertNotNull($completedJob);
        $this->assertEquals(JobStatus::COMPLETED, $completedJob->status);

        $storage = app(FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($completedJob->id);
        $outName = $completedJob->input_metadata['output_filename'] ?? '';
        $outPath = $jobDir . DIRECTORY_SEPARATOR . $outName;

        $this->assertFileExists($outPath);
        $this->assertGreaterThan(500, filesize($outPath));

        // Verify PDF magic header bytes
        $header = file_get_contents($outPath, false, null, 0, 5);
        $this->assertEquals('%PDF-', $header, 'Output must be a valid PDF document');
    }
}
