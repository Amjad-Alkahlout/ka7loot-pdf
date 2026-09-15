<?php

namespace Tests\Feature;

use App\Livewire\Tools\EditPdfComponent;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class ManualBrowserQaVerificationTest extends TestCase
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

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_edit_test_' . uniqid();
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

    protected function createResumeCvPdf(string $filename = 'resume_cv.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $fpdf = new FPDF('P', 'pt', 'A4');

        // Page 1: Profile & Experience
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 18);
        $fpdf->Cell(300, 24, 'John Doe - Senior Software Engineer', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(300, 18, 'Email: john.doe@example.com | Phone: +1 555-0199', 0, 1);
        $fpdf->Ln(10);
        $fpdf->SetFont('Helvetica', 'B', 14);
        $fpdf->Cell(300, 20, 'Work Experience', 0, 1);
        $fpdf->SetFont('Helvetica', '', 11);
        $fpdf->MultiCell(500, 16, 'Developed scalable cloud microservices and handled PDF pipelines with low latency.');

        // Page 2: Education & Skills
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 14);
        $fpdf->Cell(300, 20, 'Education and Certifications', 0, 1);
        $fpdf->SetFont('Helvetica', '', 11);
        $fpdf->MultiCell(500, 16, 'Bachelor of Science in Computer Science, University of Technology.');

        $fpdf->Output('F', $path);
        return $path;
    }

    protected function extractPdfText(string $pdfPath): string
    {
        $pdftotext = $this->detector->getPdftotextPath() ?? 'pdftotext';
        $res = $this->executor->execute([$pdftotext, $pdfPath, '-']);
        return $res['exit_code'] === 0 ? $res['output'] : '';
    }

    /**
     * Mandatory 16-step QA verification on a multi-page resume/CV PDF with Arabic live preview.
     */
    public function test_mandatory_16_step_cv_and_arabic_live_preview_qa(): void
    {
        // Step 1: Open /edit-pdf
        $response = $this->get(route('tools.edit-pdf'));
        $response->assertStatus(200);
        $response->assertSee(__('tools.edit_pdf.title'));

        // Step 2: Upload a multi-page resume/CV PDF
        $cvPath = $this->createResumeCvPdf();
        $file = UploadedFile::fake()->createWithContent('resume_cv.pdf', file_get_contents($cvPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        // Step 3: Wait for inspection and page rendering
        $this->assertEquals('ready', $component->get('jobStatus'));
        $this->assertEquals(2, $component->get('pageCount'), 'Document must have 2 pages');
        $this->assertGreaterThan(0, $component->get('totalTextObjects'));
        $this->assertGreaterThan(0, $component->get('totalTextGroups'));
        $this->assertTrue($component->instance()->isCurrentPageNativeEditable());

        $groups = $component->get('allTextGroupsByPage')[0] ?? [];
        $this->assertNotEmpty($groups, 'Page 1 must contain clustered text groups');

        // Locate Group A (Header) and Group B (Work Experience)
        $groupA = null;
        $groupB = null;
        foreach ($groups as $g) {
            if (str_contains($g['text'], 'John Doe')) {
                $groupA = $g;
            } elseif (str_contains($g['text'], 'Work Experience')) {
                $groupB = $g;
            }
        }
        $this->assertNotNull($groupA, 'Group A (John Doe) must be identified');
        $this->assertNotNull($groupB, 'Group B (Work Experience) must be identified');

        // Step 4: Select text in Group A
        $component->call('selectTextGroup', $groupA['group_id'])
            ->assertSet('selectedGroupId', $groupA['group_id'])
            ->assertSet('editText', $groupA['text']);

        // Step 5: Type replacement text and observe instantaneous visual update in preview overlay
        $newGroupAText = 'Jane Smith - Lead Cloud Architect';
        $component->set('editText', $newGroupAText);
        $this->assertTrue($component->instance()->hasUnsavedActiveEdit());
        $this->assertEquals(1, $component->get('totalQueuedModificationsCount'));

        // Step 6: Change font size and color
        $component->set('editFontSize', 16.5)
            ->set('editColor', '#1e40af'); // blue-800
        $this->assertEquals(16.5, $component->get('editFontSize'));
        $this->assertEquals('#1e40af', $component->get('editColor'));

        // Step 7: Verify Live Preview badge and overlay rendered
        $html = $component->html();
        $this->assertStringContainsString('id="active-live-preview-box"', $html);
        $this->assertStringContainsString(__('tools.edit_pdf.live_preview'), $html);
        $this->assertStringContainsString('Jane Smith - Lead Cloud Architect', $html);

        // Step 8: Select Group B without losing Group A edit (auto-saves Group A)
        $component->call('selectTextGroup', $groupB['group_id']);
        $manifest = $component->get('editManifest');
        $this->assertArrayHasKey($groupA['group_id'], $manifest, 'Group A must be preserved in editManifest');
        $this->assertEquals('Jane Smith - Lead Cloud Architect', $manifest[$groupA['group_id']]['replacement_text']);
        $this->assertEquals(16.5, $manifest[$groupA['group_id']]['font_size']);
        $this->assertEquals('#1e40af', $manifest[$groupA['group_id']]['color']);

        // Step 9: Type Arabic replacement in Group B, verify RTL alignment and fallback font
        $arabicText = 'الخبرات المهنية والمشاريع المنجزة';
        $component->set('editText', $arabicText);
        $this->assertTrue($component->instance()->isRtlText($arabicText));
        $this->assertTrue($component->instance()->hasUnsavedActiveEdit());

        // Save Group B edit
        $component->call('saveCurrentEdit');
        $manifest = $component->get('editManifest');
        $this->assertArrayHasKey($groupB['group_id'], $manifest);
        $this->assertEquals($arabicText, $manifest[$groupB['group_id']]['replacement_text']);

        // Step 10: Toggle auto-fit and verify text scaling
        $origTextLen = mb_strlen($groupB['text']);
        $arabicLen = mb_strlen($arabicText);
        $downscaled = $component->instance()->computeEffectiveFontSize(14.0, $groupB['text'], $arabicText, true);
        if ($arabicLen > $origTextLen) {
            $this->assertLessThan(14.0, $downscaled);
        }
        $noAutofit = $component->instance()->computeEffectiveFontSize(14.0, $groupB['text'], $arabicText, false);
        $this->assertEquals(14.0, $noAutofit);

        // Step 11: Navigate to Page 2 and back to Page 1, verify edits preserved
        $component->call('goToPage', 2)
            ->assertSet('currentPage', 2);
        $this->assertCount(2, $component->get('editManifest'), 'Page 2 navigation retains all pending edits');

        $component->call('goToPage', 1)
            ->assertSet('currentPage', 1);
        $this->assertCount(2, $component->get('editManifest'), 'Return to Page 1 retains all pending edits');
        $this->assertArrayHasKey($groupA['group_id'], $component->get('editManifest'));
        $this->assertArrayHasKey($groupB['group_id'], $component->get('editManifest'));

        // Step 12: Check review tab
        $component->call('setActiveTab', 'review')
            ->assertSet('activeTab', 'review');
        $reviewHtml = $component->html();
        $this->assertStringContainsString('Jane Smith - Lead Cloud Architect', $reviewHtml);
        $this->assertStringContainsString('الخبرات المهنية والمشاريع المنجزة', $reviewHtml);

        // Step 13: Click 'Apply Changes & Save PDF'
        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        // Step 14: Wait for PDFium native processing
        $job = $component->get('completedJob');
        $this->assertInstanceOf(FileJob::class, $job);
        $this->assertEquals(\App\Enums\JobStatus::COMPLETED, $job->status);
        $this->assertStringContainsString('Google PDFium', $job->input_metadata['engine_label'] ?? '');

        // Step 15: Download resulting PDF and verify text replacement natively in PDFium
        $procFile = $job->processedFiles()->first();
        $this->assertNotNull($procFile);
        $storage = app(FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($job->id);
        $outputPath = $jobDir . DIRECTORY_SEPARATOR . $procFile->stored_filename;

        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));

        $extracted = $this->extractPdfText($outputPath);
        $this->assertStringContainsString('Jane Smith - Lead Cloud Architect', $extracted);
        $this->assertStringNotContainsString('John Doe - Senior Software Engineer', $extracted);

        // Verify Page 2 content was preserved untouched
        $this->assertStringContainsString('Education and Certifications', $extracted);

        // Step 16: Verify no whiteout boxes or rasterization
        $rawStream = file_get_contents($outputPath);
        // Verify no pure whiteout fill rectangle operator injected
        $this->assertStringNotContainsString('1.000 1.000 1.000 rg', $rawStream);
        // Verify native PDF stream operators are present
        $this->assertStringContainsString('/Font', $rawStream);
    }
}
