<?php

namespace Tests\Feature;

use App\Services\Pdf\PdfOrganizeService;
use App\Services\Pdf\RotatableFpdi;
use App\Services\System\SystemBinaryDetector;
use Tests\TestCase;

class PdfOrganizeFunctionalTest extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_pdf_org_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . DIRECTORY_SEPARATOR . '*');
        foreach ($files as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    /**
     * Helper to create a multi-page PDF with mixed portrait and landscape pages.
     * Page 1: Portrait (210x297)
     * Page 2: Landscape (297x210)
     * Page 3: Portrait (210x297)
     * Page 4: Portrait (210x297)
     */
    protected function createMixedTestPdf(string $path): string
    {
        $pdf = new RotatableFpdi();

        // Page 1: Portrait
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Text(20, 30, 'PAGE 1 - PORTRAIT');

        // Page 2: Landscape
        $pdf->AddPage('L', [297, 210]);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Text(30, 30, 'PAGE 2 - LANDSCAPE');

        // Page 3: Portrait
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Text(20, 30, 'PAGE 3 - PORTRAIT');

        // Page 4: Portrait
        $pdf->AddPage('P', [210, 297]);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->Text(20, 30, 'PAGE 4 - PORTRAIT');

        $pdf->Output($path, 'F');

        return $path;
    }

    public function test_portrait_page_rotation_90_180_270_and_verify_rendered_orientation_via_poppler(): void
    {
        $detector = app(SystemBinaryDetector::class);
        $sourcePdf = $this->createMixedTestPdf($this->tmpDir . '/source.pdf');
        $outPdf = $this->tmpDir . '/rotated_output.pdf';

        $service = new PdfOrganizeService();
        // Rotate page 1 by 90°, page 3 by 180°, page 4 by 270°
        $operations = [
            ['page' => 1, 'rotation' => 90],
            ['page' => 3, 'rotation' => 180],
            ['page' => 4, 'rotation' => 270],
        ];

        $res = $service->organize($sourcePdf, $outPdf, $operations);

        $this->assertFileExists($outPdf);
        $this->assertSame(3, $res['total_pages']);

        // Verify that rotation is present in PDF structure
        $content = file_get_contents($outPdf);
        $this->assertStringContainsString('/Rotate 90', $content);
        $this->assertStringContainsString('/Rotate 180', $content);
        $this->assertStringContainsString('/Rotate 270', $content);

        // Crucial requirement: Verify rotation on actual rendered output using Poppler
        if ($detector->hasPoppler()) {
            $popplerPath = $detector->getPopplerPath();
            $prefix = $this->tmpDir . '/rendered_rot';
            exec("\"{$popplerPath}\" -png -r 72 \"{$outPdf}\" \"{$prefix}\"");

            $p1Png = $prefix . '-1.png'; // Rotated 90° (Portrait -> Landscape orientation)
            $p2Png = $prefix . '-2.png'; // Rotated 180° (Portrait -> Portrait orientation)
            $p3Png = $prefix . '-3.png'; // Rotated 270° (Portrait -> Landscape orientation)

            $this->assertFileExists($p1Png);
            $this->assertFileExists($p2Png);
            $this->assertFileExists($p3Png);

            $p1Info = getimagesize($p1Png);
            $p2Info = getimagesize($p2Png);
            $p3Info = getimagesize($p3Png);

            // Page 1 rotated 90° must be wider than tall (Landscape rendered)
            $this->assertGreaterThan($p1Info[1], $p1Info[0], "Page 1 rotated 90° must visually render as landscape (width > height).");

            // Page 2 rotated 180° must be taller than wide (Portrait rendered)
            $this->assertGreaterThan($p2Info[0], $p2Info[1], "Page 2 rotated 180° must visually render as portrait (height > width).");

            // Page 3 rotated 270° must be wider than tall (Landscape rendered)
            $this->assertGreaterThan($p3Info[1], $p3Info[0], "Page 3 rotated 270° must visually render as landscape (width > height).");
        }
    }

    public function test_landscape_page_rotation_90_renders_portrait_via_poppler(): void
    {
        $detector = app(SystemBinaryDetector::class);
        $sourcePdf = $this->createMixedTestPdf($this->tmpDir . '/source.pdf');
        $outPdf = $this->tmpDir . '/landscape_rot.pdf';

        $service = new PdfOrganizeService();
        // Page 2 is originally Landscape (297x210). Rotate by 90°.
        $operations = [
            ['page' => 2, 'rotation' => 90],
        ];

        $res = $service->organize($sourcePdf, $outPdf, $operations);

        $this->assertFileExists($outPdf);
        $this->assertSame(1, $res['total_pages']);

        if ($detector->hasPoppler()) {
            $popplerPath = $detector->getPopplerPath();
            $prefix = $this->tmpDir . '/rendered_land';
            exec("\"{$popplerPath}\" -png -r 72 \"{$outPdf}\" \"{$prefix}\"");

            $p1Png = $prefix . '-1.png';
            $this->assertFileExists($p1Png);
            $p1Info = getimagesize($p1Png);

            // Landscape rotated 90° must visually render as portrait (height > width)
            $this->assertGreaterThan($p1Info[0], $p1Info[1], "Landscape page rotated 90° must visually render as portrait (height > width).");
        }
    }

    public function test_reorder_and_delete_combination(): void
    {
        $sourcePdf = $this->createMixedTestPdf($this->tmpDir . '/source.pdf');
        $outPdf = $this->tmpDir . '/reordered.pdf';

        $service = new PdfOrganizeService();
        // Original: 1, 2, 3, 4
        // Desired: Page 3 (rotated 90°), Page 1 (no rotation), delete Page 2 and 4
        $operations = [
            ['page' => 3, 'rotation' => 90],
            ['page' => 1, 'rotation' => 0],
        ];

        $res = $service->organize($sourcePdf, $outPdf, $operations);

        $this->assertFileExists($outPdf);
        $this->assertSame(2, $res['total_pages']);

        $pdf = new RotatableFpdi();
        $count = $pdf->setSourceFile($outPdf);
        $this->assertSame(2, $count);
    }

    public function test_rejects_empty_page_operations(): void
    {
        $sourcePdf = $this->createMixedTestPdf($this->tmpDir . '/source.pdf');
        $outPdf = $this->tmpDir . '/empty.pdf';

        $this->expectException(\InvalidArgumentException::class);
        $service = new PdfOrganizeService();
        $service->organize($sourcePdf, $outPdf, []);
    }
}
