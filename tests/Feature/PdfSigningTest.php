<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\SignPdfComponent;
use App\Models\FileJob;
use App\Services\Pdf\PdfSigningService;
use App\Services\Pdf\PdfThumbnailService;
use App\Services\Pdf\RotatableFpdi;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class PdfSigningTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected PdfSigningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PdfSigningService::class);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_sign_test_' . uniqid('', true);
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
        if (!is_dir($dir)) {
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

    protected function createPdfFixture(string $filename = 'test.pdf', int $pages = 2, string $orientation = 'P'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF($orientation, 'mm', 'A4');
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage($orientation);
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, "Document Page {$i} Header", 0, 1);
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 8, "This is original vector text on page {$i}. It must not be rasterized or degraded during electronic signature overlay.");
        }
        $pdf->Output($path, 'F');
        return $path;
    }

    protected function createMixedOrientationPdf(string $filename = 'mixed.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('P', 'mm', 'A4');
        // Page 1: Portrait
        $pdf->AddPage('P');
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, "Portrait Page 1", 0, 1);

        // Page 2: Landscape
        $pdf->AddPage('L');
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, "Landscape Page 2", 0, 1);

        $pdf->Output($path, 'F');
        return $path;
    }

    protected function createSamplePngDataUri(): string
    {
        $im = imagecreatetruecolor(200, 80);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 200, 80, $trans);
        imagealphablending($im, true);

        $ink = imagecolorallocate($im, 15, 23, 42);
        imageline($im, 10, 40, 190, 40, $ink);
        imageline($im, 40, 15, 160, 65, $ink);

        ob_start();
        imagepng($im);
        $raw = ob_get_clean();
        imagedestroy($im);

        return 'data:image/png;base64,' . base64_encode($raw);
    }

    protected function createSampleImageFile(string $filename = 'sig.png'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $im = imagecreatetruecolor(180, 60);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $trans = imagecolorallocatealpha($im, 0, 0, 0, 127);
        imagefilledrectangle($im, 0, 0, 180, 60, $trans);
        imagealphablending($im, true);

        $ink = imagecolorallocate($im, 30, 41, 59);
        imagestring($im, 5, 20, 20, "Sig Sample", $ink);

        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    public function test_pdf_signing_service_signs_pdf_with_draw_signature(): void
    {
        $source = $this->createPdfFixture('source.pdf', 2);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'output_signed.pdf';
        $originalHash = hash_file('sha256', $source);

        $dataUri = $this->createSamplePngDataUri();
        $placements = [
            [
                'page'           => 1,
                'x_ratio'        => 0.2,
                'y_ratio'        => 0.7,
                'width_ratio'    => 0.3,
                'height_ratio'   => 0.1,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
        ];

        $res = $this->service->sign($source, $output, $placements);

        $this->assertFileExists($output);
        $this->assertGreaterThan(0, filesize($output));
        $this->assertEquals(2, $res['total_pages']);
        $this->assertEquals([1], $res['signed_pages']);
        $this->assertEquals(1, $res['placements_count']);

        // Assert original source file remained completely untouched
        $this->assertEquals($originalHash, hash_file('sha256', $source));

        // Assert output PDF is readable by FPDI
        $fpdi = new RotatableFpdi();
        $pages = $fpdi->setSourceFile($output);
        $this->assertEquals(2, $pages);
    }

    public function test_pdf_signing_service_signs_pdf_with_uploaded_image_signature(): void
    {
        $source = $this->createPdfFixture('source_upload.pdf', 2);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'output_upload.pdf';
        $sigImage = $this->createSampleImageFile('user_signature.png');

        $placements = [
            [
                'page'           => 2,
                'x_ratio'        => 0.5,
                'y_ratio'        => 0.8,
                'width_ratio'    => 0.25,
                'height_ratio'   => 0.1,
                'signature_path' => $sigImage,
                'signature_type' => 'upload',
            ],
        ];

        $res = $this->service->sign($source, $output, $placements);

        $this->assertFileExists($output);
        $this->assertEquals([2], $res['signed_pages']);
    }

    public function test_pdf_signing_service_signs_pdf_with_typed_signature(): void
    {
        $source = $this->createPdfFixture('source_typed.pdf', 1);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'output_typed.pdf';

        $placements = [
            [
                'page'           => 1,
                'x_ratio'        => 0.4,
                'y_ratio'        => 0.6,
                'width_ratio'    => 0.35,
                'height_ratio'   => 0.12,
                'signature_text' => 'Alexander Hamilton',
                'signature_type' => 'type',
            ],
        ];

        $res = $this->service->sign($source, $output, $placements);

        $this->assertFileExists($output);
        $this->assertGreaterThan(0, $res['size_bytes']);
    }

    public function test_pdf_signing_service_supports_multiple_signatures_across_pages(): void
    {
        $source = $this->createPdfFixture('source_multi.pdf', 3);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'output_multi.pdf';
        $dataUri = $this->createSamplePngDataUri();

        $placements = [
            [
                'page'           => 1,
                'x_ratio'        => 0.1,
                'y_ratio'        => 0.8,
                'width_ratio'    => 0.25,
                'height_ratio'   => 0.1,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
            [
                'page'           => 1,
                'x_ratio'        => 0.6,
                'y_ratio'        => 0.8,
                'width_ratio'    => 0.25,
                'height_ratio'   => 0.1,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
            [
                'page'           => 3,
                'x_ratio'        => 0.5,
                'y_ratio'        => 0.75,
                'width_ratio'    => 0.3,
                'height_ratio'   => 0.12,
                'signature_text' => 'Approved By Management',
                'signature_type' => 'type',
            ],
        ];

        $res = $this->service->sign($source, $output, $placements);

        $this->assertFileExists($output);
        $this->assertEquals(3, $res['total_pages']);
        $this->assertEquals([1, 3], $res['signed_pages']);
        $this->assertEquals(3, $res['placements_count']);
    }

    public function test_pdf_signing_service_supports_rotated_signature(): void
    {
        $source = $this->createPdfFixture('source_rot.pdf', 1);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'output_rot.pdf';
        $dataUri = $this->createSamplePngDataUri();

        $placements = [
            [
                'page'           => 1,
                'x_ratio'        => 0.3,
                'y_ratio'        => 0.5,
                'width_ratio'    => 0.3,
                'height_ratio'   => 0.15,
                'rotation'       => 45,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
        ];

        $res = $this->service->sign($source, $output, $placements);

        $this->assertFileExists($output);
        $this->assertEquals([1], $res['signed_pages']);
    }

    public function test_pdf_signing_service_preserves_landscape_and_portrait_geometry(): void
    {
        $source = $this->createMixedOrientationPdf('mixed_in.pdf');
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'mixed_out.pdf';
        $dataUri = $this->createSamplePngDataUri();

        $placements = [
            [
                'page'           => 2, // Landscape page
                'x_ratio'        => 0.4,
                'y_ratio'        => 0.7,
                'width_ratio'    => 0.2,
                'height_ratio'   => 0.1,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
        ];

        $this->service->sign($source, $output, $placements);

        $this->assertFileExists($output);

        $fpdi = new RotatableFpdi();
        $fpdi->setSourceFile($output);

        $p1 = $fpdi->importPage(1);
        $s1 = $fpdi->getTemplateSize($p1);
        $p2 = $fpdi->importPage(2);
        $s2 = $fpdi->getTemplateSize($p2);

        // Page 1 is Portrait (h > w)
        $this->assertGreaterThan($s1['width'], $s1['height']);
        // Page 2 is Landscape (w > h)
        $this->assertGreaterThan($s2['height'], $s2['width']);
    }

    public function test_pdf_signing_service_rejects_out_of_bounds_coordinates(): void
    {
        $source = $this->createPdfFixture('bounds.pdf', 1);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'out_bounds.pdf';
        $dataUri = $this->createSamplePngDataUri();

        $this->expectException(InvalidArgumentException::class);

        $this->service->sign($source, $output, [
            [
                'page'           => 1,
                'x_ratio'        => 1.5, // > 1.0 out of bounds
                'y_ratio'        => 0.2,
                'width_ratio'    => 0.3,
                'height_ratio'   => 0.1,
                'signature_data' => $dataUri,
            ],
        ]);
    }

    public function test_pdf_signing_service_rejects_invalid_page_numbers(): void
    {
        $source = $this->createPdfFixture('pages.pdf', 2);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'out_pages.pdf';
        $dataUri = $this->createSamplePngDataUri();

        $this->expectException(InvalidArgumentException::class);

        $this->service->sign($source, $output, [
            [
                'page'           => 99, // Document only has 2 pages
                'x_ratio'        => 0.2,
                'y_ratio'        => 0.2,
                'width_ratio'    => 0.3,
                'height_ratio'   => 0.1,
                'signature_data' => $dataUri,
            ],
        ]);
    }

    public function test_pdf_signing_service_rejects_empty_placements(): void
    {
        $source = $this->createPdfFixture('empty_placements.pdf', 1);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'out_empty.pdf';

        $this->expectException(InvalidArgumentException::class);
        $this->service->sign($source, $output, []);
    }

    public function test_livewire_sign_pdf_component_renders_and_workflow(): void
    {
        $pdfPath = $this->createPdfFixture('lw_test.pdf', 2);
        $uploaded = UploadedFile::fake()->createWithContent('lw_test.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded)
            ->assertSet('filename', 'lw_test.pdf')
            ->assertSet('pageCount', 2)
            ->assertSet('currentPage', 1)
            ->assertSet('jobStatus', 'uploaded');

        // Verify page navigation
        $component->call('goToPage', 2)
            ->assertSet('currentPage', 2);

        // Add typed signature
        $component->set('typedName', 'Dr. John Watson')
            ->call('applyTypedSignature')
            ->assertCount('placements', 1);

        // Clear placements
        $component->call('clearPlacements')
            ->assertCount('placements', 0);

        // Try processing without signatures - should fail validation
        $component->call('process')
            ->assertHasErrors(['placements']);

        // Add drawn signature and process
        $dataUri = $this->createSamplePngDataUri();
        $component->call('setDrawData', $dataUri)
            ->assertCount('placements', 1)
            ->call('process')
            ->assertSet('jobStatus', 'completed');

        // Check created FileJob
        $jobId = $component->get('fileJobId');
        $this->assertNotNull($jobId);
        $fileJob = FileJob::find($jobId);
        $this->assertEquals(ToolType::SIGN_PDF, $fileJob->tool_type);
        $this->assertEquals(JobStatus::COMPLETED, $fileJob->status);
        $this->assertCount(1, $fileJob->processedFiles);
    }

    public function test_sign_pdf_routes_and_localization(): void
    {
        // Check English route
        $this->get('/sign-pdf')
            ->assertStatus(200)
            ->assertSee(__('tools.sign_pdf.title'))
            ->assertSee(__('tools.sign_pdf.disclaimer_title'));

        // Check Arabic route
        $this->withSession(['locale' => 'ar'])
            ->get('/sign-pdf')
            ->assertStatus(200)
            ->assertSee('توقيع PDF')
            ->assertSee('تنبيه هام');
    }

    public function test_pdf_signing_supports_four_distinct_typed_signature_styles(): void
    {
        $this->assertCount(4, PdfSigningService::TYPED_STYLES);

        foreach (['classic', 'casual', 'brush', 'formal'] as $style) {
            $this->assertArrayHasKey($style, PdfSigningService::TYPED_STYLES);
            $dataUri = $this->service->generateTypedSignatureDataUri('Jane Citizen', $style, 36);
            $this->assertStringStartsWith('data:image/png;base64,', $dataUri);

            $binary = base64_decode(substr($dataUri, strlen('data:image/png;base64,')));
            $this->assertNotEmpty($binary);
            $info = getimagesizefromstring($binary);
            $this->assertNotFalse($info);
            $this->assertGreaterThan(50, $info[0]); // width
            $this->assertGreaterThan(20, $info[1]); // height
        }
    }

    public function test_pdf_signing_placement_at_different_page_coordinates_and_rotations(): void
    {
        $source = $this->createPdfFixture('coords.pdf', 2);
        $output = $this->tempDir . DIRECTORY_SEPARATOR . 'coords_signed.pdf';
        $dataUri = $this->createSamplePngDataUri();

        $placements = [
            // Top-left
            [
                'page'           => 1,
                'x_ratio'        => 0.05,
                'y_ratio'        => 0.05,
                'width_ratio'    => 0.25,
                'height_ratio'   => 0.10,
                'rotation'       => 0,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
            // Center
            [
                'page'           => 1,
                'x_ratio'        => 0.40,
                'y_ratio'        => 0.45,
                'width_ratio'    => 0.25,
                'height_ratio'   => 0.10,
                'rotation'       => 90,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
            // Bottom-right
            [
                'page'           => 2,
                'x_ratio'        => 0.65,
                'y_ratio'        => 0.85,
                'width_ratio'    => 0.25,
                'height_ratio'   => 0.10,
                'rotation'       => 180,
                'signature_data' => $dataUri,
                'signature_type' => 'draw',
            ],
        ];

        $res = $this->service->sign($source, $output, $placements);

        $this->assertFileExists($output);
        $this->assertEquals([1, 2], $res['signed_pages']);
        $this->assertEquals(3, $res['placements_count']);
    }

    public function test_livewire_sign_pdf_supports_style_selection_and_rotation(): void
    {
        $pdfPath = $this->createPdfFixture('style_lw.pdf', 1);
        $uploaded = UploadedFile::fake()->createWithContent('style_lw.pdf', file_get_contents($pdfPath));

        $component = Livewire::test(SignPdfComponent::class)
            ->set('upload', $uploaded)
            ->assertSet('typedStyle', 'classic');

        // Change style to casual
        $component->call('setTypedStyle', 'casual')
            ->assertSet('typedStyle', 'casual');

        // Add typed signature in casual style
        $component->set('typedName', 'Jane Citizen')
            ->call('applyTypedSignature')
            ->assertCount('placements', 1);

        $placements = $component->get('placements');
        $this->assertEquals('casual', $placements[0]['signature_style']);
        $this->assertEquals(0, $placements[0]['rotation']);

        // Rotate placement by 90 degrees
        $component->call('rotatePlacement', $placements[0]['id'], 90);
        $updated = $component->get('placements');
        $this->assertEquals(90, $updated[0]['rotation']);

        // Rotate again by 90 degrees -> 180
        $component->call('rotatePlacement', $placements[0]['id'], 90);
        $updated = $component->get('placements');
        $this->assertEquals(180, $updated[0]['rotation']);
    }

    public function test_pdf_thumbnail_service_generates_high_dpi_page_preview(): void
    {
        $pdfPath = $this->createPdfFixture('dpi_test.pdf', 1);
        $thumbService = app(PdfThumbnailService::class);

        $preview = $thumbService->generatePagePreview($pdfPath, 1, 150);
        $this->assertNotNull($preview);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $preview);

        $binary = base64_decode(substr($preview, strlen('data:image/jpeg;base64,')));
        $info = getimagesizefromstring($binary);
        $this->assertNotFalse($info);
        // 150 DPI A4 page is ~1240 x 1754 px
        $this->assertGreaterThan(800, $info[0]);
        $this->assertGreaterThan(1000, $info[1]);
    }

    public function test_sign_pdf_view_renders_clean_html_without_raw_js_or_escaped_newlines(): void
    {
        $pdfPath = $this->createPdfFixture("render_clean.pdf", 1);
        $uploaded = UploadedFile::fake()->createWithContent("render_clean.pdf", file_get_contents($pdfPath));

        foreach (["en", "ar"] as $locale) {
            app()->setLocale($locale);

            // 1. Initial State (no upload)
            $comp = Livewire::test(SignPdfComponent::class);
            $initialHtml = $comp->html();

            $this->assertStringNotContainsString("\\n", $initialHtml, "Initial HTML in $locale contains literal \\n");
            $this->assertStringNotContainsString("setupCanvas", $initialHtml, "Initial HTML in $locale contains raw JS");
            $this->assertStringNotContainsString("getBoundingClientRect", $initialHtml, "Initial HTML in $locale contains raw JS");

            // 2. Uploaded State with signature placement
            $comp->set("upload", $uploaded)
                ->set("jobStatus", "uploaded")
                ->set("placements", [
                    [
                        "id" => "sig-reg-1",
                        "page" => 1,
                        "x_ratio" => 0.2,
                        "y_ratio" => 0.3,
                        "width_ratio" => 0.3,
                        "height_ratio" => 0.1,
                        "rotation" => 0,
                        "signature_data" => $this->createSamplePngDataUri(),
                        "signature_type" => "draw",
                    ],
                ]);

            foreach (["draw", "upload", "type"] as $tab) {
                $comp->set("activeSignatureType", $tab);
                $tabHtml = $comp->html();

                $this->assertStringNotContainsString("\\n", $tabHtml, "Tab $tab HTML in $locale contains literal \\n");
                $this->assertStringNotContainsString("setupCanvas", $tabHtml, "Tab $tab HTML in $locale contains raw JS");
                $this->assertStringNotContainsString("getBoundingClientRect", $tabHtml, "Tab $tab HTML in $locale contains raw JS");
            }
        }
    }

}
