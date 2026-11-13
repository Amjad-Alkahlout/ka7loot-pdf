<?php

namespace Tests\Feature;

use App\Livewire\Tools\EditPdfComponent;
use App\Services\File\FileStorageManager;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class SubPhase2BFinalVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subphase2b_final_' . uniqid('', true);
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

    protected function createThreePagePdf(string $filename = 'test_doc_3p.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $fpdf = new FPDF('P', 'pt', 'A4');

        // Page 1
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(200, 24, 'CONFIDENTIAL REPORT P1', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(200, 18, 'Client: Cyberdyne Systems', 0, 1);

        // Page 2
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(200, 24, 'FINANCIAL AUDIT P2', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(200, 18, 'Status: Verified', 0, 1);

        // Page 3
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(200, 24, 'APPENDIX P3', 0, 1);

        $fpdf->Output($path, 'F');
        return $path;
    }

    /**
     * Requirement 1: Verify accessing #[Computed] backward-compatibility properties
     * does NOT serialize inspection data into the Livewire snapshot.
     */
    public function test_computed_properties_snapshot_isolation(): void
    {
        $pdfPath = $this->createThreePagePdf();
        $uploaded = UploadedFile::fake()->createWithContent('test_doc_3p.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        $initialSnapshotStr = json_encode($test->snapshot);
        $initialSize = strlen($initialSnapshotStr);

        // 1. Access allTextGroupsByPage
        $allGroups = $test->instance()->allTextGroupsByPage;
        $this->assertNotEmpty($allGroups, 'Computed allTextGroupsByPage must return page groups');
        $this->assertArrayHasKey(0, $allGroups);
        $this->assertArrayHasKey(1, $allGroups);

        $snapAfterGroups = json_encode($test->snapshot);
        $this->assertEquals(
            $initialSize,
            strlen($snapAfterGroups),
            'Snapshot size must NOT increase after accessing allTextGroupsByPage'
        );

        // 2. Access allTextObjectsByPage
        $allObjects = $test->instance()->allTextObjectsByPage;
        $this->assertNotEmpty($allObjects, 'Computed allTextObjectsByPage must return page objects');
        $this->assertArrayHasKey(0, $allObjects);

        $snapAfterObjects = json_encode($test->snapshot);
        $this->assertEquals(
            $initialSize,
            strlen($snapAfterObjects),
            'Snapshot size must NOT increase after accessing allTextObjectsByPage'
        );

        // 3. Access allPageDimensionsByPage
        $allDims = $test->instance()->allPageDimensionsByPage;
        $this->assertCount(3, $allDims, 'Computed allPageDimensionsByPage must return all 3 pages');

        $snapAfterDims = json_encode($test->snapshot);
        $this->assertEquals(
            $initialSize,
            strlen($snapAfterDims),
            'Snapshot size must NOT increase after accessing allPageDimensionsByPage'
        );

        // 4. Access pagePreviews
        $previews = $test->instance()->pagePreviews;
        $this->assertArrayHasKey(1, $previews);
        $this->assertStringContainsString('tools/pdf-preview', $previews[1]);

        $snapAfterPreviews = json_encode($test->snapshot);
        $this->assertEquals(
            $initialSize,
            strlen($snapAfterPreviews),
            'Snapshot size must NOT increase after accessing pagePreviews'
        );

        // 5. Access pageThumbnails
        $thumbnails = $test->instance()->pageThumbnails;
        $this->assertCount(3, $thumbnails);
        $this->assertStringContainsString('tools/pdf-preview', $thumbnails[1]);

        $snapAfterThumbs = json_encode($test->snapshot);
        $this->assertEquals(
            $initialSize,
            strlen($snapAfterThumbs),
            'Snapshot size must NOT increase after accessing pageThumbnails'
        );

        // Ensure non-active pages' inspection data is NOT serialized into snapshot
        $this->assertStringNotContainsString('FINANCIAL AUDIT P2', $snapAfterThumbs, 'Page 2 text must not be in snapshot');
        $this->assertStringNotContainsString('APPENDIX P3', $snapAfterThumbs, 'Page 3 text must not be in snapshot');
    }

    /**
     * Requirement 2: Security & Recovery of inspection.json:
     * - Stored only in private secure temp directory (not public)
     * - UUID validation prevents arbitrary path traversal
     * - Corrupted and missing inspection.json triggers safe recovery
     * - Existing editManifest edits are preserved across recovery
     * - Job reset removes inspection.json
     */
    public function test_inspection_json_security_and_safe_recovery(): void
    {
        $pdfPath = $this->createThreePagePdf();
        $uploaded = UploadedFile::fake()->createWithContent('test_doc_3p.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        $jobId = $test->get('jobId');
        $this->assertNotNull($jobId);

        $storage = app(FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($jobId);
        $inspectionPath = $jobDir . DIRECTORY_SEPARATOR . 'inspection.json';

        // 1. Verify storage location is outside web root & public disk
        $this->assertFileExists($inspectionPath);
        $this->assertStringNotContainsString('public', $inspectionPath, 'inspection.json must not be in public directory');
        $this->assertStringContainsString('secure_temp', $inspectionPath);

        // 2. Verify UUID validation rejects directory traversal
        $this->expectException(InvalidArgumentException::class);
        $storage->getJobDirectory('../../etc');
    }

    public function test_corrupted_and_missing_inspection_json_recovers_and_preserves_manifest(): void
    {
        $pdfPath = $this->createThreePagePdf();
        $uploaded = UploadedFile::fake()->createWithContent('test_doc_3p.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        $jobId = $test->get('jobId');
        $storage = app(FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($jobId);
        $inspectionPath = $jobDir . DIRECTORY_SEPARATOR . 'inspection.json';

        // Create an edit on Page 1
        $p1Groups = $test->get('currentTextGroups');
        $this->assertNotEmpty($p1Groups);
        $test->call('selectTextGroup', $p1Groups[0]['group_id'])
            ->set('editText', 'REPLACED TITLE ON P1')
            ->call('saveCurrentEdit');

        $manifest = $test->get('editManifest');
        $this->assertCount(1, $manifest);
        $this->assertEquals('REPLACED TITLE ON P1', array_values($manifest)[0]['replacement_text']);

        // Scenario A: Corrupt inspection.json with malformed bytes
        file_put_contents($inspectionPath, '{"pages": [MALFORMED_JSON_CORRUPTED_DATA}');

        // Navigate to page 2: should safely re-inspect and hydrate page 2
        $test->call('goToPage', 2);
        $this->assertEquals(2, $test->get('currentPage'));
        $p2Groups = $test->get('currentTextGroups');
        $this->assertNotEmpty($p2Groups, 'Must recover and hydrate page 2 text data after corrupted inspection.json');

        // Verify manifest from page 1 is preserved
        $manifestAfterCorrupt = $test->get('editManifest');
        $this->assertCount(1, $manifestAfterCorrupt, 'Manifest edits must be preserved during recovery from corrupted JSON');
        $this->assertEquals('REPLACED TITLE ON P1', array_values($manifestAfterCorrupt)[0]['replacement_text']);

        // Scenario B: Missing inspection.json
        @unlink($inspectionPath);
        $this->assertFileDoesNotExist($inspectionPath);

        // Navigate back to page 1: should safely recreate inspection.json
        $test->call('goToPage', 1);
        $this->assertEquals(1, $test->get('currentPage'));
        $this->assertFileExists($inspectionPath, 'inspection.json must be regenerated on demand');

        $manifestAfterMissing = $test->get('editManifest');
        $this->assertCount(1, $manifestAfterMissing, 'Manifest edits must be preserved during recovery from missing JSON');
        $this->assertEquals('REPLACED TITLE ON P1', array_values($manifestAfterMissing)[0]['replacement_text']);

        // Scenario C: Job reset cleans up inspection.json
        $test->call('resetTool');
        $this->assertFileDoesNotExist($inspectionPath, 'inspection.json must be deleted when job is reset');
        $this->assertDirectoryDoesNotExist($jobDir, 'Job directory must be completely removed on reset');
    }

    /**
     * Requirement 3: Progressive Thumbnail Verification
     * - Verify exact thumbnail button count in HTML
     * - Verify pages rendered in bottom strip match document page count exactly
     * - Verify top navigation buttons explain the 14 vs 12 count
     */
    public function test_progressive_thumbnail_button_counts_and_page_mapping(): void
    {
        $pdfPath = $this->createThreePagePdf();
        $uploaded = UploadedFile::fake()->createWithContent('test_doc_3p.pdf', file_get_contents($pdfPath));

        $test = Livewire::test(EditPdfComponent::class)
            ->set('upload', $uploaded);

        $html = $test->html();

        // 1. Bottom thumbnail strip buttons: exactly pageCount (3)
        $thumbMatches = [];
        preg_match_all('/wire:key="edit-thumb-(\d+)"/', $html, $thumbMatches);
        $this->assertCount(3, $thumbMatches[1], 'Bottom thumbnail strip must contain exactly 3 thumbnails for a 3-page PDF');
        $this->assertEquals(['1', '2', '3'], $thumbMatches[1]);

        // 2. All goToPage occurrences on page: exactly pageCount + 2 (for Prev and Next toolbar buttons)
        $goToPageMatches = [];
        preg_match_all('/wire:click="goToPage\((\d+)\)"/', $html, $goToPageMatches);
        // Matches: prev (goToPage(1)), next (goToPage(2)), thumb 1 (goToPage(1)), thumb 2 (goToPage(2)), thumb 3 (goToPage(3)) = 5 total
        $this->assertCount(5, $goToPageMatches[0], 'Total goToPage buttons = 3 thumbnails + 2 top navigation buttons = 5 buttons');

        // 3. Verify no thumbnail outside page range is rendered
        $this->assertStringNotContainsString('edit-thumb-0', $html);
        $this->assertStringNotContainsString('edit-thumb-4', $html);
    }
}
