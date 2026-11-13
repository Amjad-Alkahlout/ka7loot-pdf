<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\OrganizePdfComponent;
use App\Livewire\Tools\SplitPdfComponent;
use App\Models\FileJob;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class Phase1ProgressivePdfTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p1_test_' . uniqid('', true);
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

    protected function createPdfFixture(string $filename = 'test.pdf', int $pages = 5): UploadedFile
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('P', 'mm', 'A4');
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(40, 10, "Page {$i} Test Document");
        }
        $pdf->Output('F', $path);

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($path));
    }

    public function test_split_pdf_has_no_thumbnails_property_and_remains_under_budget(): void
    {
        $file = $this->createPdfFixture('split_sample.pdf', 10);

        $test = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $file);

        // Verify property is purged
        $this->assertFalse(property_exists($test->instance(), 'thumbnails'));

        // Verify state is minimal
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot), 'Split PDF Livewire state snapshot must be under 5 KB');
        $this->assertStringNotContainsString('data:image', $snapshot, 'No base64 data allowed in Livewire state');

        $this->assertEquals(10, $test->get('pageCount'));
        $this->assertEquals('uploaded', $test->get('jobStatus'));
    }

    public function test_organize_pdf_has_no_thumbnails_property_and_remains_under_budget(): void
    {
        $file = $this->createPdfFixture('org_sample.pdf', 10);

        $test = Livewire::test(OrganizePdfComponent::class)
            ->set('upload', $file);

        // Verify property is purged
        $this->assertFalse(property_exists($test->instance(), 'thumbnails'));

        // Verify state is minimal
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(15000, strlen($snapshot), 'Organize PDF Livewire state snapshot must be under 15 KB');
        $this->assertStringNotContainsString('data:image', $snapshot, 'No base64 data allowed in Livewire state');

        $this->assertEquals(10, $test->get('pageCount'));
        $this->assertCount(10, $test->get('pages'));
    }

    public function test_split_pdf_generates_valid_signed_preview_url(): void
    {
        $file = $this->createPdfFixture('split_url.pdf', 3);

        $test = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $file);

        $url = $test->instance()->getPreviewUrl(2, 120);
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('dpi=120', $url);
        $this->assertStringContainsString('session_id=', $url);
        $this->assertStringContainsString('signature=', $url);

        $highResUrl = $test->instance()->getHighResPreviewUrl(2);
        $this->assertStringContainsString('dpi=220', $highResUrl);
    }

    public function test_organize_pdf_generates_valid_signed_preview_url(): void
    {
        $file = $this->createPdfFixture('org_url.pdf', 3);

        $test = Livewire::test(OrganizePdfComponent::class)
            ->set('upload', $file);

        $url = $test->instance()->getPreviewUrl(1, 120);
        $this->assertNotEmpty($url);
        $this->assertStringContainsString('page=1', $url);
        $this->assertStringContainsString('dpi=120', $url);
        $this->assertStringContainsString('session_id=', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    public function test_split_pdf_interactive_range_and_page_toggle(): void
    {
        $file = $this->createPdfFixture('split_toggle.pdf', 5);

        $test = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $file)
            ->set('mode', 'range')
            ->set('range', '1-3');

        $this->assertTrue($test->instance()->validateRange());

        // Toggle page 2 off
        $test->call('togglePage', 2);
        $this->assertEquals('1, 3', $test->get('range'));

        // Toggle page 2 back on
        $test->call('togglePage', 2);
        $this->assertEquals('1-3', $test->get('range'));

        // Test out of bounds validation
        $test->set('range', '1-10');
        $this->assertFalse($test->instance()->validateRange());
        $this->assertNotNull($test->get('rangeError'));
    }

    public function test_split_pdf_modes_switching_and_groups(): void
    {
        $file = $this->createPdfFixture('split_modes.pdf', 6);

        $test = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $file);

        // Mode A: Range
        $test->call('setMode', 'range')->set('range', '1-2, 5-6');
        $groups = $test->instance()->getSplitGroups();
        $this->assertCount(2, $groups);

        // Mode B: Every N
        $test->call('setMode', 'every_n')->set('everyN', 2);
        $groups = $test->instance()->getSplitGroups();
        $this->assertCount(3, $groups);

        // Mode C: All
        $test->call('setMode', 'all');
        $groups = $test->instance()->getSplitGroups();
        $this->assertCount(6, $groups);
    }

    public function test_organize_pdf_page_rotation_and_deletion(): void
    {
        $file = $this->createPdfFixture('org_actions.pdf', 4);

        $test = Livewire::test(OrganizePdfComponent::class)
            ->set('upload', $file);

        // Rotate page 0 right: 0 -> 90 -> 180
        $test->call('rotateRight', 0);
        $this->assertEquals(90, $test->get('pages.0.rotation'));
        $test->call('rotateRight', 0);
        $this->assertEquals(180, $test->get('pages.0.rotation'));

        // Rotate page 0 left: 180 -> 90
        $test->call('rotateLeft', 0);
        $this->assertEquals(90, $test->get('pages.0.rotation'));

        // Delete page 1
        $test->call('deletePage', 1);
        $this->assertTrue($test->get('pages.1.deleted'));
        $this->assertEquals(3, $test->get('activeCount'));

        // Restore page 1
        $test->call('restorePage', 1);
        $this->assertFalse($test->get('pages.1.deleted'));
        $this->assertEquals(4, $test->get('activeCount'));
    }

    public function test_organize_pdf_reorder_with_sort_order_validator(): void
    {
        $file = $this->createPdfFixture('org_reorder.pdf', 4);

        $test = Livewire::test(OrganizePdfComponent::class)
            ->set('upload', $file);

        // Valid complete reorder: [4, 2, 1, 3]
        $test->call('reorderPages', [4, 2, 1, 3]);

        $currentIds = array_column($test->get('pages'), 'id');
        $this->assertEquals([4, 2, 1, 3], $currentIds);

        // Invalid reorder (missing ID 4): rejected, order unchanged
        $test->call('reorderPages', [2, 1, 3]);
        $this->assertEquals([4, 2, 1, 3], array_column($test->get('pages'), 'id'));

        // Invalid reorder (duplicate ID 2): rejected, order unchanged
        $test->call('reorderPages', [4, 2, 2, 3]);
        $this->assertEquals([4, 2, 1, 3], array_column($test->get('pages'), 'id'));

        // Invalid reorder (unexpected ID 99): rejected, order unchanged
        $test->call('reorderPages', [4, 2, 1, 99]);
        $this->assertEquals([4, 2, 1, 3], array_column($test->get('pages'), 'id'));
    }

    public function test_organize_pdf_reset_pages(): void
    {
        $file = $this->createPdfFixture('org_reset.pdf', 3);

        $test = Livewire::test(OrganizePdfComponent::class)
            ->set('upload', $file)
            ->call('rotateRight', 0)
            ->call('deletePage', 1)
            ->call('reorderPages', [3, 2, 1]);

        $this->assertEquals([3, 2, 1], array_column($test->get('pages'), 'id'));

        // Reset pages
        $test->call('resetPages');

        $this->assertEquals([1, 2, 3], array_column($test->get('pages'), 'id'));
        $this->assertEquals(0, $test->get('pages.0.rotation'));
        $this->assertFalse($test->get('pages.1.deleted'));
    }

    public function test_split_pdf_100_page_performance_and_payload(): void
    {
        $file = $this->createPdfFixture('large_100.pdf', 100);

        $test = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $file);

        $this->assertEquals(100, $test->get('pageCount'));

        // Livewire state payload must be under 5 KB even for 100 pages
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(5000, strlen($snapshot), '100-page Split PDF Livewire payload must be under 5 KB');
    }

    public function test_organize_pdf_100_page_performance_and_payload(): void
    {
        $file = $this->createPdfFixture('large_org_100.pdf', 100);

        $test = Livewire::test(OrganizePdfComponent::class)
            ->set('upload', $file);

        $this->assertEquals(100, $test->get('pageCount'));
        $this->assertCount(100, $test->get('pages'));

        // Livewire state payload for 100 page metadata items must be under 20 KB
        $snapshot = json_encode($test->instance()->all());
        $this->assertLessThan(20000, strlen($snapshot), '100-page Organize PDF Livewire payload must be under 20 KB');
    }

    public function test_preview_endpoint_serves_and_caches_rendered_jpeg(): void
    {
        $file = $this->createPdfFixture('render_test.pdf', 3);

        $test = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $file);

        $previewUrl = $test->instance()->getPreviewUrl(1, 120);
        $sessionId = session()->get('preview_session_id');

        // Make HTTP request as the authorized session
        $response = $this->withSession(['preview_session_id' => $sessionId])
            ->get($previewUrl);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertFileExists($response->getFile()->getPathname());
        $this->assertGreaterThan(0, filesize($response->getFile()->getPathname()));
    }

}

