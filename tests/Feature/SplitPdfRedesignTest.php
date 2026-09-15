<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Jobs\ProcessFileJob;
use App\Livewire\Tools\SplitPdfComponent;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SplitPdfRedesignTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'split_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    protected function createPdfFixture(int $pageCount, string $filename = 'sample.pdf'): UploadedFile
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();
        for ($i = 1; $i <= $pageCount; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(40, 10, "Page {$i} of {$pageCount}");
        }
        $pdf->Output($path, 'F');

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($path));
    }

    public function test_split_pdf_page_count_detection(): void
    {
        $uploaded = $this->createPdfFixture(12, 'doc12.pdf');

        Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->assertSet('pageCount', 12)
            ->assertSet('filename', 'doc12.pdf')
            ->assertSee('12 pages')
            ->assertSee('doc12.pdf');
    }

    public function test_valid_range_preview_groups(): void
    {
        $uploaded = $this->createPdfFixture(12, 'doc12.pdf');

        Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'range')
            ->set('range', '1-5, 8, 10-12')
            ->assertSet('rangeError', null)
            ->assertSee('PDF 1')
            ->assertSee('PDF 2')
            ->assertSee('PDF 3')
            ->assertSee('Pages 1-5')
            ->assertSee('Page 8')
            ->assertSee('Pages 10-12')
            ->assertSee('Create 3 PDF files from 12 pages');
    }

    public function test_invalid_range_rejection_out_of_bounds(): void
    {
        $uploaded = $this->createPdfFixture(12, 'doc12.pdf');

        $component = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'range')
            ->set('range', '20');

        $this->assertNotNull($component->get('rangeError'));
        $this->assertStringContainsString('20', $component->get('rangeError'));
        $this->assertEmpty($component->viewData('splitGroups'));
    }

    public function test_overlapping_and_duplicate_ranges_rejection(): void
    {
        $uploaded = $this->createPdfFixture(12, 'doc12.pdf');

        $component = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'range')
            ->set('range', '1-5, 3');

        $this->assertNotNull($component->get('rangeError'));
        $this->assertStringContainsString('3', $component->get('rangeError'));
        $this->assertEmpty($component->viewData('splitGroups'));
    }

    public function test_invalid_range_interval_rejection(): void
    {
        $uploaded = $this->createPdfFixture(12, 'doc12.pdf');

        $component = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'range')
            ->set('range', '8-3');

        $this->assertNotNull($component->get('rangeError'));
        $this->assertEmpty($component->viewData('splitGroups'));
    }

    public function test_every_n_pages_preview_calculation(): void
    {
        $uploaded = $this->createPdfFixture(12, 'doc12.pdf');

        Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'every_n')
            ->set('everyN', 5)
            ->assertSet('everyNError', null)
            ->assertSee('PDF 1')
            ->assertSee('Pages 1-5')
            ->assertSee('PDF 2')
            ->assertSee('Pages 6-10')
            ->assertSee('PDF 3')
            ->assertSee('Pages 11-12')
            ->assertSee('Create 3 PDF files from 12 pages');
    }

    public function test_extract_all_pages_preview(): void
    {
        $uploaded = $this->createPdfFixture(12, 'doc12.pdf');

        Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'all')
            ->assertSee('12 pages -> 12 PDF files')
            ->assertSee('Create 12 PDF files from 12 pages');
    }

    public function test_arabic_rtl_rendering(): void
    {
        App::setLocale('ar');

        $uploaded = $this->createPdfFixture(12, 'arabic_doc.pdf');

        Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'range')
            ->set('range', '1-5, 8, 10-12')
            ->assertSee('تقسيم PDF')
            ->assertSee('المستند المرفوع')
            ->assertSee('معاينة التقسيم المباشرة')
            ->assertSee('12 صفحة')
            ->assertSee('إنشاء 3 ملفات PDF من 12 صفحة');

        // Test Arabic validation message on invalid range
        $component = Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'range')
            ->set('range', '20');

        $this->assertStringContainsString('الصفحة 20 تتجاوز إجمالي عدد صفحات المستند (12)', $component->get('rangeError'));

        App::setLocale('en');
    }

    public function test_interactive_page_toggle(): void
    {
        $uploaded = $this->createPdfFixture(5, 'doc5.pdf');

        Livewire::test(SplitPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('mode', 'range')
            ->set('range', '1-2')
            ->call('togglePage', 4) // Add page 4
            ->assertSet('range', '1-2, 4')
            ->call('togglePage', 3) // Add page 3 -> fills the gap so becomes 1-4
            ->assertSet('range', '1-4')
            ->call('togglePage', 1) // Remove page 1 -> becomes 2-4
            ->assertSet('range', '2-4');
    }

    public function test_split_pdf_end_to_end_job_processing_with_groups(): void
    {
        $storage = app(FileStorageManager::class);
        $jobId = Str::uuid()->toString();
        $jobDir = $storage->getJobDirectory($jobId);

        $fixture = $this->createPdfFixture(3, 'sample3.pdf');
        copy($fixture->getRealPath(), $jobDir . DIRECTORY_SEPARATOR . 'test_source.pdf');

        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::SPLIT_PDF,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'file'       => 'test_source.pdf',
                'clean_name' => 'sample3',
                'mode'       => 'range',
                'range'      => '1, 2-3',
                'ip'         => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        app()->call([new ProcessFileJob($job->id), 'handle']);

        $job->refresh();
        $this->assertEquals(JobStatus::COMPLETED, $job->status);

        $processed = ProcessedFile::where('file_job_id', $job->id)->get();
        // Should have 3 records: 1 ZIP bundle + 2 PDF parts
        $this->assertCount(3, $processed);

        // ZIP bundle check
        $zipRecord = $processed->firstWhere('mime_type', 'application/zip');
        $this->assertNotNull($zipRecord);
        $this->assertFileExists($jobDir . DIRECTORY_SEPARATOR . $zipRecord->stored_filename);

        // PDF parts check
        $pdfRecords = $processed->where('mime_type', 'application/pdf');
        $this->assertCount(2, $pdfRecords);
        foreach ($pdfRecords as $pdfRec) {
            $this->assertFileExists($jobDir . DIRECTORY_SEPARATOR . $pdfRec->stored_filename);
        }

        $storage->deleteJobDirectory($jobId);
    }
}
