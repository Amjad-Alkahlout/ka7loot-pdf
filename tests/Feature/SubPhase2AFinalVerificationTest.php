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

class SubPhase2AFinalVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected PdfSigningService $signingService;
    protected FileStorageManager $storageManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->signingService = app(PdfSigningService::class);
        $this->storageManager = app(FileStorageManager::class);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subphase2a_final_' . uniqid('', true);
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

    protected function createCustomPdfFixture(float $widthMm, float $heightMm, int $pages = 1): string
    {
        $orientation = $widthMm > $heightMm ? 'L' : 'P';
        $path = $this->tempDir . DIRECTORY_SEPARATOR . "custom_{$widthMm}x{$heightMm}_" . uniqid() . '.pdf';
        $pdf = new FPDF($orientation, 'mm', [$widthMm, $heightMm]);
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage($orientation, [$widthMm, $heightMm]);
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(40, 10, "Page {$i} ({$widthMm}x{$heightMm} mm)");
        }
        $pdf->Output($path, 'F');
        return $path;
    }

    protected function createSamplePngDataUri(): string
    {
        $im = imagecreatetruecolor(160, 60);
        $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefill($im, 0, 0, $trans);
        imagesavealpha($im, true);
        $blue = imagecolorallocate($im, 30, 58, 138);
        imageline($im, 10, 50, 150, 10, $blue);
        imageline($im, 10, 10, 150, 50, $blue);

        ob_start();
        imagepng($im);
        $binary = ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($binary);
    }

    /**
     * Parse content streams of a generated PDF to independently extract image placements and coordinates.
     */
    protected function extractPdfImagePlacements(string $pdfPath): array
    {
        $raw = file_get_contents($pdfPath);
        $this->assertNotEmpty($raw, "PDF file {$pdfPath} is empty");

        // Parse pages and MediaBoxes
        preg_match_all('/\/MediaBox\s*\[\s*0\s+0\s+([\d\.]+)\s+([\d\.]+)\s*\]/', $raw, $mbMatches, PREG_SET_ORDER);
        $defaultPageW = !empty($mbMatches) ? (float) $mbMatches[0][1] : 595.28;
        $defaultPageH = !empty($mbMatches) ? (float) $mbMatches[0][2] : 841.89;

        // Extract and decompress content streams
        $streams = [];
        $offset = 0;
        while (($start = strpos($raw, 'stream', $offset)) !== false) {
            $dataStart = $start + 6;
            if ($raw[$dataStart] === "\r") $dataStart++;
            if ($raw[$dataStart] === "\n") $dataStart++;

            $end = strpos($raw, 'endstream', $dataStart);
            if ($end === false) break;

            $streamData = substr($raw, $dataStart, $end - $dataStart);
            $uncompressed = @gzuncompress($streamData);
            if ($uncompressed === false) {
                $uncompressed = $streamData;
            }
            $streams[] = $uncompressed;
            $offset = $end + 9;
        }

        $placements = [];
        foreach ($streams as $sIndex => $stream) {
            // Find FPDF transformation matrix: [w] 0 0 [h] [x] [y] cm /I... Do
            if (preg_match_all('/([\d\.]+)\s+0(?:\.0+)?\s+0(?:\.0+)?\s+([\d\.]+)\s+([\d\.]+)\s+([\d\.]+)\s+cm\s+\/I\d+\s+Do/', $stream, $matches, PREG_SET_ORDER)) {
                $pageW = isset($mbMatches[$sIndex]) ? (float) $mbMatches[$sIndex][1] : $defaultPageW;
                $pageH = isset($mbMatches[$sIndex]) ? (float) $mbMatches[$sIndex][2] : $defaultPageH;

                foreach ($matches as $m) {
                    $w_pt = (float)$m[1];
                    $h_pt = (float)$m[2];
                    $x_pt = (float)$m[3];
                    $y_pt = (float)$m[4];

                    $top_y_pt = $pageH - ($y_pt + $h_pt);
                    $placements[] = [
                        'stream_index' => $sIndex,
                        'page_w_pt'    => $pageW,
                        'page_h_pt'    => $pageH,
                        'w_pt'         => $w_pt,
                        'h_pt'         => $h_pt,
                        'x_pt'         => $x_pt,
                        'y_pt'         => $y_pt,
                        'top_y_pt'     => $top_y_pt,
                        'x_ratio'      => $x_pt / $pageW,
                        'y_ratio'      => $top_y_pt / $pageH,
                        'width_ratio'  => $w_pt / $pageW,
                        'height_ratio' => $h_pt / $pageH,
                    ];
                }
            }
        }

        return $placements;
    }

    // =========================================================================
    // SECTION 1: ACTUAL PDF COORDINATE VERIFICATION
    // =========================================================================

    public function test_portrait_a4_pdf_coordinate_precision(): void
    {
        $portraitPdf = $this->createCustomPdfFixture(210, 297, 1);
        $uploaded = UploadedFile::fake()->createWithContent('portrait_a4.pdf', file_get_contents($portraitPdf));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);

        $placements = $component->get('placements');
        $id = $placements[0]['id'];

        // Position at: x = 20%, y = 65%, w = 30%, h = 12%
        $component->call('updatePlacement', $id, 0.20, 0.65, 0.30, 0.12, 0);

        // Process PDF
        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $jobId = $component->get('fileJobId');
        $job = FileJob::find($jobId);
        $outputPath = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $job->input_metadata['output_filename'];

        $extracted = $this->extractPdfImagePlacements($outputPath);
        $this->assertCount(1, $extracted);
        $actual = $extracted[0];

        // Verify precision within 0.001 (0.1%)
        $this->assertEqualsWithDelta(0.20, $actual['x_ratio'], 0.001, 'X ratio mismatch in Portrait PDF');
        $this->assertEqualsWithDelta(0.65, $actual['y_ratio'], 0.001, 'Y ratio mismatch in Portrait PDF');
        $this->assertEqualsWithDelta(0.30, $actual['width_ratio'], 0.001, 'Width ratio mismatch in Portrait PDF');
        $this->assertEqualsWithDelta(0.12, $actual['height_ratio'], 0.001, 'Height ratio mismatch in Portrait PDF');
    }

    public function test_landscape_a4_pdf_coordinate_precision(): void
    {
        $landscapePdf = $this->createCustomPdfFixture(297, 210, 1);
        $uploaded = UploadedFile::fake()->createWithContent('landscape_a4.pdf', file_get_contents($landscapePdf));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);

        $placements = $component->get('placements');
        $id = $placements[0]['id'];

        // Position at: x = 50%, y = 40%, w = 25%, h = 15%
        $component->call('updatePlacement', $id, 0.50, 0.40, 0.25, 0.15, 0);

        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $jobId = $component->get('fileJobId');
        $job = FileJob::find($jobId);
        $outputPath = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $job->input_metadata['output_filename'];

        $extracted = $this->extractPdfImagePlacements($outputPath);
        $this->assertCount(1, $extracted);
        $actual = $extracted[0];

        $this->assertEqualsWithDelta(0.50, $actual['x_ratio'], 0.001, 'X ratio mismatch in Landscape PDF');
        $this->assertEqualsWithDelta(0.40, $actual['y_ratio'], 0.001, 'Y ratio mismatch in Landscape PDF');
        $this->assertEqualsWithDelta(0.25, $actual['width_ratio'], 0.001, 'Width ratio mismatch in Landscape PDF');
        $this->assertEqualsWithDelta(0.15, $actual['height_ratio'], 0.001, 'Height ratio mismatch in Landscape PDF');
    }

    public function test_custom_aspect_ratios_square_and_tall(): void
    {
        // 1. Square PDF (200 x 200 mm, 1:1)
        $squarePdf = $this->createCustomPdfFixture(200, 200, 1);
        $uploadedSquare = UploadedFile::fake()->createWithContent('square.pdf', file_get_contents($squarePdf));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploadedSquare);
        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);
        $id = $component->get('placements')[0]['id'];
        $component->call('updatePlacement', $id, 0.10, 0.10, 0.40, 0.20, 0);
        $component->call('process')->assertSet('jobStatus', 'completed');

        $jobId = $component->get('fileJobId');
        $job = FileJob::find($jobId);
        $out = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $job->input_metadata['output_filename'];
        $actual = $this->extractPdfImagePlacements($out)[0];
        $this->assertEqualsWithDelta(0.10, $actual['x_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.10, $actual['y_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.40, $actual['width_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.20, $actual['height_ratio'], 0.001);

        // 2. Tall PDF (150 x 300 mm, 1:2)
        $tallPdf = $this->createCustomPdfFixture(150, 300, 1);
        $uploadedTall = UploadedFile::fake()->createWithContent('tall.pdf', file_get_contents($tallPdf));

        $componentTall = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploadedTall);
        $componentTall->call('setDrawData', $dataUri);
        $idTall = $componentTall->get('placements')[0]['id'];
        $componentTall->call('updatePlacement', $idTall, 0.30, 0.75, 0.45, 0.10, 0);
        $componentTall->call('process')->assertSet('jobStatus', 'completed');

        $jobIdTall = $componentTall->get('fileJobId');
        $jobTall = FileJob::find($jobIdTall);
        $outTall = $this->storageManager->getJobDirectory($jobIdTall) . DIRECTORY_SEPARATOR . $jobTall->input_metadata['output_filename'];
        $actualTall = $this->extractPdfImagePlacements($outTall)[0];
        $this->assertEqualsWithDelta(0.30, $actualTall['x_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.75, $actualTall['y_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.45, $actualTall['width_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.10, $actualTall['height_ratio'], 0.001);
    }

    public function test_rotation_values_0_90_180_270_preserve_position_and_generate_valid_pdf(): void
    {
        $rotations = [0, 90, 180, 270];

        foreach ($rotations as $deg) {
            $pdfPath = $this->createCustomPdfFixture(210, 297, 1);
            $uploaded = UploadedFile::fake()->createWithContent("rot_{$deg}.pdf", file_get_contents($pdfPath));

            $component = Livewire::test(SignPdfComponent::class)
                ->set('upload', $uploaded);
            $dataUri = $this->createSamplePngDataUri();
            $component->call('setDrawData', $dataUri);
            $id = $component->get('placements')[0]['id'];

            $component->call('updatePlacement', $id, 0.25, 0.50, 0.35, 0.15, $deg);
            $component->call('process')->assertSet('jobStatus', 'completed');

            $jobId = $component->get('fileJobId');
            $job = FileJob::find($jobId);
            $outputPath = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $job->input_metadata['output_filename'];

            $extracted = $this->extractPdfImagePlacements($outputPath);
            $this->assertCount(1, $extracted, "Rotation {$deg}° should produce exactly 1 placed image");
            $actual = $extracted[0];

            $this->assertEqualsWithDelta(0.25, $actual['x_ratio'], 0.001, "Rotation {$deg}° X ratio");
            $this->assertEqualsWithDelta(0.50, $actual['y_ratio'], 0.001, "Rotation {$deg}° Y ratio");
            $this->assertEqualsWithDelta(0.35, $actual['width_ratio'], 0.001, "Rotation {$deg}° Width ratio");
            $this->assertEqualsWithDelta(0.15, $actual['height_ratio'], 0.001, "Rotation {$deg}° Height ratio");
        }
    }

    public function test_signature_dragging_resizing_and_page_navigation_before_signing(): void
    {
        // 4-page PDF document
        $pdfPath = $this->createCustomPdfFixture(210, 297, 4);
        $uploaded = UploadedFile::fake()->createWithContent('multipage_nav.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();

        // 1. Place on Page 1, simulate drag and resize
        $component->call('setDrawData', $dataUri);
        $p1Id = $component->get('placements')[0]['id'];
        $component->call('updatePlacement', $p1Id, 0.15, 0.20, 0.50, 0.25, 0);

        // 2. Navigate to Page 3, place typed signature
        $component->call('goToPage', 3)
            ->assertSet('currentPage', 3);
        $component->set('typedName', 'Charles Darwin')
            ->call('setTypedStyle', 'classic')
            ->call('applyTypedSignature');

        $p3Id = $component->get('placements')[1]['id'];
        $component->call('updatePlacement', $p3Id, 0.40, 0.70, 0.35, 0.12, 90);

        // 3. Navigate to Page 2 (leave blank)
        $component->call('goToPage', 2)
            ->assertSet('currentPage', 2);

        // 4. Navigate to Page 4, place uploaded signature
        $component->call('goToPage', 4)
            ->assertSet('currentPage', 4);
        $sigImage = UploadedFile::fake()->image('audited_sig.png', 200, 80);
        $component->set('signatureUpload', $sigImage);
        $p4Id = $component->get('placements')[2]['id'];
        $component->call('updatePlacement', $p4Id, 0.55, 0.80, 0.25, 0.10, 0);

        // Process final PDF
        $component->call('process')
            ->assertSet('jobStatus', 'completed');

        $jobId = $component->get('fileJobId');
        $job = FileJob::find($jobId);
        $outputPath = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $job->input_metadata['output_filename'];

        $extracted = $this->extractPdfImagePlacements($outputPath);
        // Pages 1, 3, 4 each have 1 signature; Page 2 has 0 signatures
        $this->assertCount(3, $extracted, "Expected exactly 3 placements across 4 pages");

        // Verify Page 1 placement (stream_index 0)
        $p1Actual = $extracted[0];
        $this->assertEqualsWithDelta(0.15, $p1Actual['x_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.20, $p1Actual['y_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.50, $p1Actual['width_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.25, $p1Actual['height_ratio'], 0.001);

        // Verify Page 3 placement (stream_index 2)
        $p3Actual = $extracted[1];
        $this->assertEqualsWithDelta(0.40, $p3Actual['x_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.70, $p3Actual['y_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.35, $p3Actual['width_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.12, $p3Actual['height_ratio'], 0.001);

        // Verify Page 4 placement (stream_index 3)
        $p4Actual = $extracted[2];
        $this->assertEqualsWithDelta(0.55, $p4Actual['x_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.80, $p4Actual['y_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.25, $p4Actual['width_ratio'], 0.001);
        $this->assertEqualsWithDelta(0.10, $p4Actual['height_ratio'], 0.001);
    }

    // =========================================================================
    // SECTION 2: SIGNATURE FILE LIFECYCLE VERIFICATION
    // =========================================================================

    public function test_drawn_signature_survives_multiple_consecutive_livewire_requests(): void
    {
        $pdfPath = $this->createCustomPdfFixture(210, 297, 3);
        $uploaded = UploadedFile::fake()->createWithContent('lifecycle_doc.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);

        $p = $component->get('placements')[0];
        $jobId = $component->get('jobId');
        $fullPath = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $p['signature_path'];
        $this->assertFileExists($fullPath);

        // Perform 5 consecutive Livewire requests
        $component->call('goToPage', 2);
        $this->assertFileExists($fullPath, 'File must survive goToPage(2)');

        $component->call('goToPage', 3);
        $this->assertFileExists($fullPath, 'File must survive goToPage(3)');

        $component->call('setTypedStyle', 'brush');
        $this->assertFileExists($fullPath, 'File must survive setTypedStyle');

        $component->call('goToPage', 1);
        $this->assertFileExists($fullPath, 'File must survive returning to Page 1');

        $component->call('updatePlacement', $p['id'], 0.30, 0.40, 0.20, 0.10, 90);
        $this->assertFileExists($fullPath, 'File must survive updatePlacement');
    }

    public function test_uploaded_signature_survives_subsequent_placement_interactions(): void
    {
        $pdfPath = $this->createCustomPdfFixture(210, 297, 2);
        $uploaded = UploadedFile::fake()->createWithContent('upload_lifecycle.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $sigImage = UploadedFile::fake()->image('lifecycle_sig.png', 180, 70);
        $component->set('signatureUpload', $sigImage);

        $p = $component->get('placements')[0];
        $jobId = $component->get('jobId');
        $fullPath = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $p['signature_path'];
        $this->assertFileExists($fullPath);

        // Subsequent interactions
        $component->call('rotatePlacement', $p['id'], 90);
        $this->assertFileExists($fullPath);

        $component->call('goToPage', 2);
        $this->assertFileExists($fullPath);

        $component->call('goToPage', 1);
        $this->assertFileExists($fullPath);
    }

    public function test_removing_placement_deletes_file_only_when_no_other_placement_references_it(): void
    {
        $pdfPath = $this->createCustomPdfFixture(210, 297, 2);
        $uploaded = UploadedFile::fake()->createWithContent('ref_count_doc.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);

        $p1 = $component->get('placements')[0];
        $jobId = $component->get('jobId');
        $fullPath = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . $p1['signature_path'];
        $this->assertFileExists($fullPath);

        // Add second placement referencing the SAME file (e.g. user stamps same signature on page 2)
        $component->call('addPlacement', [
            'page'               => 2,
            'signature_type'     => 'draw',
            'signature_path'     => $p1['signature_path'],
            'signature_filename' => $p1['signature_filename'],
        ]);
        $placements = $component->get('placements');
        $this->assertCount(2, $placements);
        $p2 = $placements[1];

        // 1. Remove placement 1: file MUST STILL EXIST because placement 2 references it!
        $component->call('removePlacement', $p1['id']);
        $this->assertCount(1, $component->get('placements'));
        $this->assertFileExists($fullPath, 'File must NOT be deleted while still referenced by placement 2');

        // 2. Remove placement 2: file MUST NOW BE DELETED because 0 placements reference it!
        $component->call('removePlacement', $p2['id']);
        $this->assertCount(0, $component->get('placements'));
        $this->assertFileDoesNotExist($fullPath, 'File MUST be deleted when last referencing placement is removed');
    }

    public function test_clearing_placements_removes_all_relevant_signature_files(): void
    {
        $pdfPath = $this->createCustomPdfFixture(210, 297, 1);
        $uploaded = UploadedFile::fake()->createWithContent('clear_all_doc.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);
        $component->call('setDrawData', $dataUri);

        $jobId = $component->get('jobId');
        $sigDir = $this->storageManager->getJobDirectory($jobId) . DIRECTORY_SEPARATOR . 'signatures';
        $filesBefore = glob($sigDir . DIRECTORY_SEPARATOR . '*.png');
        $this->assertCount(2, $filesBefore);

        // Clear all placements
        $component->call('clearPlacements');
        $this->assertCount(0, $component->get('placements'));

        $filesAfter = glob($sigDir . DIRECTORY_SEPARATOR . '*.png');
        $this->assertEmpty($filesAfter, 'All signature files must be deleted after clearPlacements');
    }

    public function test_job_reset_and_cleanup_removes_remaining_signature_files_and_directory(): void
    {
        $pdfPath = $this->createCustomPdfFixture(210, 297, 1);
        $uploaded = UploadedFile::fake()->createWithContent('reset_doc.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);

        $jobId = $component->get('jobId');
        $jobDir = $this->storageManager->getJobDirectory($jobId);
        $this->assertDirectoryExists($jobDir);

        // Reset tool
        $component->call('resetTool');
        $this->assertNull($component->get('jobId'));
        $this->assertDirectoryDoesNotExist($jobDir, 'Job directory must be deleted upon resetTool');
    }

    public function test_unauthorized_session_or_job_cannot_retrieve_signature_files(): void
    {
        $pdfPath = $this->createCustomPdfFixture(210, 297, 1);
        $uploaded = UploadedFile::fake()->createWithContent('auth_doc.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded);

        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri);

        $p = $component->get('placements')[0];
        $jobId = $component->get('jobId');
        $filename = $p['signature_filename'];
        $sessionId = (string) (session()->get('preview_session_id') ?: session()->getId());
        session()->put('preview_session_id', $sessionId);

        // 1. Valid signed route from owner session succeeds
        $validUrl = $component->instance()->getSignaturePreviewUrl($p);
        $this->withSession(['preview_session_id' => $sessionId])->get($validUrl)->assertStatus(200);

        // 2. Tampered signature returns 403
        $this->withSession(['preview_session_id' => $sessionId])->get($validUrl . 'tampered')->assertStatus(403);

        // 3. Different session_id returns 403
        $foreignSessionUrl = URL::temporarySignedRoute('tools.signature-preview', now()->addHour(), [
            'filename'   => $filename,
            'job_id'     => $jobId,
            'session_id' => 'foreign_session_id_1234567890',
        ]);
        $this->withSession(['preview_session_id' => $sessionId])->get($foreignSessionUrl)->assertStatus(403);

        // 4. Different job_id returns 404 (file not in that job directory)
        $foreignJobId = \Illuminate\Support\Str::uuid()->toString();
        $this->storageManager->getJobDirectory($foreignJobId);
        $foreignJobUrl = URL::temporarySignedRoute('tools.signature-preview', now()->addHour(), [
            'filename'   => $filename,
            'job_id'     => $foreignJobId,
            'session_id' => $sessionId,
        ]);
        $this->withSession(['preview_session_id' => $sessionId])->get($foreignJobUrl)->assertStatus(404);
        $this->storageManager->deleteJobDirectory($foreignJobId);

        // 5. Path traversal attempts return 400
        $traversalUrl = URL::temporarySignedRoute('tools.signature-preview', now()->addHour(), [
            'filename'   => '../../../etc/passwd',
            'job_id'     => $jobId,
            'session_id' => $sessionId,
        ]);
        $this->withSession(['preview_session_id' => $sessionId])->get($traversalUrl)->assertStatus(400);
    }
}
