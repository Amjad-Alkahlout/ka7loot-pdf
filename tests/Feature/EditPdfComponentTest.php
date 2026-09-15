<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\EditPdfComponent;
use App\Models\FileJob;
use App\Services\Pdf\PdfProtectionService;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\TestCase;

class EditPdfComponentTest extends TestCase
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

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'edit_pdf_test_' . uniqid();
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

    protected function createStandardInvoicePdf(string $filename = 'invoice.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $fpdf = new FPDF('P', 'pt', 'A4');
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 16);
        $fpdf->Cell(200, 24, 'TAX INVOICE #1001', 0, 1);
        $fpdf->SetFont('Helvetica', '', 12);
        $fpdf->Cell(200, 18, 'Customer: John Doe', 0, 1);
        $fpdf->Cell(200, 18, 'Total Amount: $500.00', 0, 1);
        $fpdf->Output('F', $path);
        return $path;
    }

    protected function createMultiPagePdf(string $filename = 'multipage.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $fpdf = new FPDF('P', 'pt', 'A4');
        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 14);
        $fpdf->Cell(200, 20, 'Page 1 Header', 0, 1);

        $fpdf->AddPage();
        $fpdf->SetFont('Helvetica', 'B', 14);
        $fpdf->Cell(200, 20, 'Page 2 Header', 0, 1);

        $fpdf->Output('F', $path);
        return $path;
    }

    protected function extractPdfTextWithPoppler(string $pdfPath): string
    {
        $pdftotext = $this->detector->getPdftotextPath() ?? 'pdftotext';
        $res = $this->executor->execute([$pdftotext, $pdfPath, '-']);
        return $res['exit_code'] === 0 ? $res['output'] : '';
    }

    public function test_upload_flow_and_initial_analysis(): void
    {
        $pdfPath = $this->createStandardInvoicePdf();
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $component->assertSet('pageCount', 1)
            ->assertSet('currentPage', 1)
            ->assertSet('isScanned', false)
            ->assertSet('passwordRequired', false);

        $this->assertGreaterThan(0, $component->get('totalTextObjects'));
        $this->assertArrayHasKey(1, $component->get('pagePreviews'));
    }

    public function test_scanned_pdf_detection_displays_warning_and_prevents_fake_boxes(): void
    {
        // Generate an image-only PDF
        $imgPdfPath = $this->tempDir . DIRECTORY_SEPARATOR . 'image_only.pdf';
        $img = imagecreatetruecolor(200, 200);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, 200, 200, $white);
        $imgPath = $this->tempDir . DIRECTORY_SEPARATOR . 'temp_img.jpg';
        imagejpeg($img, $imgPath);
        imagedestroy($img);

        $fpdf = new FPDF('P', 'pt', 'A4');
        $fpdf->AddPage();
        $fpdf->Image($imgPath, 50, 50, 200, 200);
        $fpdf->Output('F', $imgPdfPath);

        $file = UploadedFile::fake()->createWithContent('scanned.pdf', file_get_contents($imgPdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $component->assertSet('isScanned', true);
        $component->assertSee(__('tools.edit_pdf.scanned_warning_title'));
    }

    public function test_encrypted_pdf_handling_and_password_unlock(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('unprotected.pdf');
        $encPath = $this->tempDir . DIRECTORY_SEPARATOR . 'protected.pdf';

        $protector = app(PdfProtectionService::class);
        $protector->protect($pdfPath, $encPath, 'secret123');

        $file = UploadedFile::fake()->createWithContent('protected.pdf', file_get_contents($encPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $component->assertSet('passwordRequired', true);
        $component->assertSee(__('tools.edit_pdf.encrypted_notice'));

        // Wrong password
        $component->set('password', 'wrong')
            ->call('unlockWithPassword')
            ->assertSet('passwordRequired', true)
            ->assertSee(__('tools.unlock_pdf.error_invalid_password'));

        // Correct password
        $component->set('password', 'secret123')
            ->call('unlockWithPassword')
            ->assertSet('passwordRequired', false)
            ->assertSet('pageCount', 1);
    }

    public function test_page_navigation_in_multi_page_document(): void
    {
        $pdfPath = $this->createMultiPagePdf();
        $file = UploadedFile::fake()->createWithContent('multipage.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $component->assertSet('pageCount', 2)
            ->assertSet('currentPage', 1)
            ->call('goToPage', 2)
            ->assertSet('currentPage', 2)
            ->call('goToPage', 1)
            ->assertSet('currentPage', 1);
    }

    public function test_text_object_selection_and_manifest_saving(): void
    {
        $pdfPath = $this->createStandardInvoicePdf();
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $allObjects = $component->get('allTextObjectsByPage');
        $this->assertNotEmpty($allObjects[0]);

        $firstObj = $allObjects[0][0];
        $objId = $firstObj['object_id'];

        $component->call('selectTextObject', $objId)
            ->assertSet('selectedObjectId', $objId)
            ->assertSet('editText', $firstObj['text'])
            ->set('editText', 'MODIFIED TEXT')
            ->call('saveCurrentEdit');

        $manifest = $component->get('editManifest');
        $this->assertArrayHasKey($objId, $manifest);
        $this->assertEquals('MODIFIED TEXT', $manifest[$objId]['replacement_text']);
    }

    public function test_additive_text_and_shapes_management(): void
    {
        $pdfPath = $this->createStandardInvoicePdf();
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file)
            ->call('addTextItem')
            ->assertCount('addedTexts', 1)
            ->call('addShape', 'highlight')
            ->assertCount('addedShapes', 1);

        $shapes = $component->get('addedShapes');
        $sId = array_key_first($shapes);
        $this->assertEquals('highlight', $shapes[$sId]['type']);

        $component->call('removeShape', $sId)
            ->assertCount('addedShapes', 0);
    }

    public function test_security_validation_rejects_invalid_or_tampered_object_ids(): void
    {
        $pdfPath = $this->createStandardInvoicePdf();
        $file = UploadedFile::fake()->createWithContent('invoice.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        // Inject forged editManifest item
        $component->set('editManifest', [
            'forged_id_999' => [
                'id'               => 'forged_id_999',
                'page'             => 1,
                'page_index'       => 0,
                'object_id'        => 'forged_id_999',
                'target_text'      => 'Something',
                'replacement_text' => 'Hacked',
                'auto_fit'         => true,
            ],
        ]);

        $component->call('process')
            ->assertSet('jobStatus', 'failed')
            ->assertSee('Security violation');
    }

    public function test_full_process_workflow_generates_valid_edited_pdf_without_whiteout(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('invoice_edit.pdf');
        $file = UploadedFile::fake()->createWithContent('invoice_edit.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $allObjects = $component->get('allTextObjectsByPage');
        $targetObj = null;
        foreach ($allObjects[0] as $to) {
            if (str_contains($to['text'], 'Customer: John Doe')) {
                $targetObj = $to;
                break;
            }
        }
        $this->assertNotNull($targetObj);

        $component->call('selectTextObject', $targetObj['object_id'])
            ->set('editText', 'Customer: Jane Smith')
            ->call('saveCurrentEdit')
            ->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $this->assertInstanceOf(FileJob::class, $job);
        $this->assertEquals(JobStatus::COMPLETED, $job->status);

        $processedFile = $job->processedFiles()->first();
        $this->assertNotNull($processedFile);

        $storage = app(\App\Services\File\FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($job->id);
        $outputPath = $jobDir . DIRECTORY_SEPARATOR . $processedFile->stored_filename;
        $this->assertFileExists($outputPath);

        // Text Verification with pdftotext
        $extracted = $this->extractPdfTextWithPoppler($outputPath);
        $this->assertStringContainsString('Customer: Jane Smith', $extracted);
        $this->assertStringNotContainsString('Customer: John Doe', $extracted);

        // Stream Verification: Ensure NO whiteout rectangles were injected
        $raw = file_get_contents($outputPath);
        $this->assertStringNotContainsString('/re f', $raw);
        $this->assertStringNotContainsString('1.000 1.000 1.000 rg', $raw);
    }

    public function test_arabic_locale_and_rtl_interface(): void
    {
        App::setLocale('ar');

        $response = $this->withSession(['locale' => 'ar'])->get(route('tools.edit-pdf'));
        $response->assertStatus(200);
        $response->assertSee('تعديل PDF');
        $response->assertSee('تعديل النصوص الأصلية');
        $response->assertDontSee('tools.edit_pdf');
    }

    public function test_rendered_markup_cleanliness_in_both_locales(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('clean_markup.pdf');
        $file = UploadedFile::fake()->createWithContent('clean_markup.pdf', file_get_contents($pdfPath));

        foreach (['en', 'ar'] as $locale) {
            App::setLocale($locale);

            $component = Livewire::test(EditPdfComponent::class)
                ->set('upload', $file);

            $html = $component->html();

            // Must NOT contain escaped quotes like class=\" or alt=\"
            $this->assertStringNotContainsString('\"', $html, "Locale [{$locale}] must not contain escaped quotes");
            $this->assertStringNotContainsString('Thumb\"', $html, "Locale [{$locale}] must not contain Thumb\\\"");
            $this->assertStringNotContainsString('PDF\"', $html, "Locale [{$locale}] must not contain PDF\\\"");

            // Must NOT contain attributes followed by backslash-quote
            preg_match_all('/[a-zA-Z0-9_\-\.\/]+[\\\\]+"/', $html, $attrMatches);
            $this->assertEmpty($attrMatches[0], "Locale [{$locale}] has malformed attributes with trailing backslash");

            // Verify clean image markup
            $this->assertStringContainsString('<img', $html);
            $this->assertMatchesRegularExpression('/<img[^>]+src="data:image\/jpeg;base64,[^"]+"/', $html);
        }
    }
    public function test_logical_text_group_selection_and_replacement_workflow(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('grouped_invoice.pdf');
        $file = UploadedFile::fake()->createWithContent('grouped_invoice.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $groups = $component->get('allTextGroupsByPage')[0] ?? [];
        $this->assertNotEmpty($groups, 'Page 1 should have clustered text groups');

        // Find group containing "Customer: John Doe"
        $customerGroup = null;
        foreach ($groups as $g) {
            if (str_contains($g['text'], 'Customer')) {
                $customerGroup = $g;
                break;
            }
        }
        $this->assertNotNull($customerGroup, 'Customer text group should be clustered');
        $groupId = $customerGroup['group_id'];

        $component->call('selectTextGroup', $groupId)
            ->assertSet('selectedGroupId', $groupId)
            ->set('editText', 'Customer: Global Tech Corp')
            ->call('saveCurrentEdit');

        $manifest = $component->get('editManifest');
        $this->assertArrayHasKey($groupId, $manifest);
        $this->assertEquals('Customer: Global Tech Corp', $manifest[$groupId]['replacement_text']);

        // Process the logical group replacement
        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $processedFile = $job->processedFiles()->first();
        $this->assertNotNull($processedFile);

        $storage = app(\App\Services\File\FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($job->id);
        $outputPath = $jobDir . DIRECTORY_SEPARATOR . $processedFile->stored_filename;

        // Verify pdftotext output
        $extracted = $this->extractPdfTextWithPoppler($outputPath);
        $this->assertStringContainsString('Customer: Global Tech Corp', $extracted);
        $this->assertStringNotContainsString('Customer: John Doe', $extracted);

        // Verify stream has no whiteout
        $raw = file_get_contents($outputPath);
        $this->assertStringNotContainsString('1.000 1.000 1.000 rg', $raw);
    }
    public function test_type3_font_page_detection_shows_readonly_warning_and_blocks_native_edit(): void
    {
        $fixture = base_path('storage/payment_status_qa.pdf');
        if (!file_exists($fixture)) {
            $this->markTestSkipped('payment_status_qa.pdf not available');
        }

        $file = UploadedFile::fake()->createWithContent('payment_status_qa.pdf', file_get_contents($fixture));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $this->assertFalse($component->get('pageNativeEditable')[1], 'Page 1 must be marked not native editable');
        $this->assertTrue($component->get('pageHasType3')[1], 'Page 1 must have Type 3 fonts flag set');

        // Select a group
        $groups = $component->get('allTextGroupsByPage')[0] ?? [];
        $this->assertNotEmpty($groups);
        $firstGroup = $groups[0];

        $component->call('selectTextGroup', $firstGroup['group_id'])
            ->set('editText', 'New Group Text')
            ->call('saveCurrentEdit')
            ->assertHasErrors(['edit_text']);

        $manifest = $component->get('editManifest');
        $this->assertEmpty($manifest, 'Manifest must remain empty when editing Type 3 page');

        // Verify HTML renders warning banner and disabled controls
        $html = $component->html();
        $this->assertStringContainsString(__('tools.edit_pdf.type3_warning_title'), $html);
        $this->assertStringContainsString(__('tools.edit_pdf.read_only_badge'), $html);
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_additive_text_and_shapes_work_on_type3_page_without_native_corruption(): void
    {
        $fixture = base_path('storage/payment_status_qa.pdf');
        if (!file_exists($fixture)) {
            $this->markTestSkipped('payment_status_qa.pdf not available');
        }

        $file = UploadedFile::fake()->createWithContent('payment_status_qa.pdf', file_get_contents($fixture));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        // Add additive text item on Type 3 document
        $component->call('addTextItem')
            ->call('setActiveTab', 'shapes')
            ->call('addShape', 'rect');

        $this->assertCount(1, $component->get('addedTexts'));
        $this->assertCount(1, $component->get('addedShapes'));

        // Process should succeed using additive overlay pipeline
        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $job = $component->get('completedJob');
        $this->assertNotNull($job);
        $procFile = $job->processedFiles()->first();
        $this->assertNotNull($procFile);

        $storage = app(\App\Services\File\FileStorageManager::class);
        $jobDir = $storage->getJobDirectory($job->id);
        $outPath = $jobDir . DIRECTORY_SEPARATOR . $procFile->stored_filename;

        $this->assertFileExists($outPath);
        $this->assertGreaterThan(0, filesize($outPath));

        // Original document text remains intact
        $extracted = $this->extractPdfTextWithPoppler($outPath);
        $this->assertStringContainsString('Ethical Hacking', $extracted);
        $this->assertStringContainsString('order_TO5IBKEww6rgUh', $extracted);
    }

    public function test_compute_effective_font_size_and_auto_fit_logic(): void
    {
        $component = new EditPdfComponent();

        // When replacement is shorter or equal, base size preserved
        $this->assertEquals(14.0, $component->computeEffectiveFontSize(14.0, 'Hello World', 'Hi', true));
        $this->assertEquals(14.0, $component->computeEffectiveFontSize(14.0, 'Hello World', 'Hello World', true));

        // When replacement is significantly longer and auto-fit is true, downscaled
        $downscaled = $component->computeEffectiveFontSize(14.0, 'Short', 'This is a significantly longer replacement text string', true);
        $this->assertLessThan(14.0, $downscaled);
        $this->assertGreaterThanOrEqual(6.0, $downscaled);

        // When auto-fit is false, base size preserved regardless of length
        $this->assertEquals(14.0, $component->computeEffectiveFontSize(14.0, 'Short', 'This is a significantly longer replacement text string', false));
    }

    public function test_is_rtl_text_detection(): void
    {
        $component = new EditPdfComponent();

        // English / Latin
        $this->assertFalse($component->isRtlText('Invoice #1001'));
        $this->assertFalse($component->isRtlText('John Doe'));

        // Arabic
        $this->assertTrue($component->isRtlText('فاتورة ضريبية'));
        $this->assertTrue($component->isRtlText('محمد أحمد'));

        // Hebrew
        $this->assertTrue($component->isRtlText('שלום עולם'));

        // Group direction override
        $this->assertTrue($component->isRtlText('Mixed 123', 'rtl'));
    }

    public function test_has_unsaved_active_edit_and_queued_modification_count(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('unsaved_test.pdf');
        $file = UploadedFile::fake()->createWithContent('unsaved_test.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $groups = $component->get('allTextGroupsByPage')[0] ?? [];
        $firstGroup = $groups[0];
        $gid = $firstGroup['group_id'];

        $this->assertEquals(0, $component->get('totalQueuedModificationsCount'));

        // Select group
        $component->call('selectTextGroup', $gid);
        $this->assertFalse($component->instance()->hasUnsavedActiveEdit());
        $this->assertEquals(0, $component->get('totalQueuedModificationsCount'));

        // Modify text without saving
        $component->set('editText', 'Modified Invoice Title');
        $this->assertTrue($component->instance()->hasUnsavedActiveEdit());
        $this->assertEquals(1, $component->get('totalQueuedModificationsCount'));

        // Cancel restores state
        $component->call('cancelEdit');
        $this->assertFalse($component->instance()->hasUnsavedActiveEdit());
        $this->assertEquals(0, $component->get('totalQueuedModificationsCount'));
        $this->assertEmpty($component->get('editManifest'));
    }

    public function test_live_preview_badge_and_active_preview_box_in_html(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('preview_badge_test.pdf');
        $file = UploadedFile::fake()->createWithContent('preview_badge_test.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $groups = $component->get('allTextGroupsByPage')[0] ?? [];
        $firstGroup = $groups[0];
        $gid = $firstGroup['group_id'];

        $component->call('selectTextGroup', $gid);

        $html = $component->html();
        $this->assertStringContainsString('id="active-live-preview-box"', $html);
        $this->assertStringContainsString(__('tools.edit_pdf.live_preview'), $html);
    }

    public function test_editing_font_size_and_color_updates_live_state_and_manifest(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('style_edit_test.pdf');
        $file = UploadedFile::fake()->createWithContent('style_edit_test.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $groups = $component->get('allTextGroupsByPage')[0] ?? [];
        $firstGroup = $groups[0];
        $gid = $firstGroup['group_id'];

        $component->call('selectTextGroup', $gid)
            ->set('editText', 'Updated Styled Header')
            ->set('editFontSize', 18.5)
            ->set('editColor', '#e11d48')
            ->call('saveCurrentEdit');

        $manifest = $component->get('editManifest');
        $this->assertArrayHasKey($gid, $manifest);
        $this->assertEquals(18.5, $manifest[$gid]['font_size']);
        $this->assertEquals('#e11d48', $manifest[$gid]['color']);
        $this->assertEquals('Updated Styled Header', $manifest[$gid]['replacement_text']);
    }

    public function test_switching_groups_auto_saves_unsaved_active_edit(): void
    {
        $pdfPath = $this->createStandardInvoicePdf('switch_group_test.pdf');
        $file = UploadedFile::fake()->createWithContent('switch_group_test.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(EditPdfComponent::class)
            ->set('upload', $file);

        $groups = $component->get('allTextGroupsByPage')[0] ?? [];
        $this->assertGreaterThanOrEqual(2, count($groups));

        $groupA = $groups[0];
        $groupB = $groups[1];

        // Select Group A and type changes without manual save
        $component->call('selectTextGroup', $groupA['group_id'])
            ->set('editText', 'Group A Replacement Text');

        $this->assertTrue($component->instance()->hasUnsavedActiveEdit());

        // Switch to Group B -> Group A should auto-save into manifest
        $component->call('selectTextGroup', $groupB['group_id']);

        $manifest = $component->get('editManifest');
        $this->assertArrayHasKey($groupA['group_id'], $manifest, 'Group A must be auto-saved into manifest on switch');
        $this->assertEquals('Group A Replacement Text', $manifest[$groupA['group_id']]['replacement_text']);
        $this->assertEquals($groupB['group_id'], $component->get('selectedGroupId'));
    }
}
