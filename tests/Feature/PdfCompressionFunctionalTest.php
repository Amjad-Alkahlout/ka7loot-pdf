<?php

namespace Tests\Feature;

use App\Enums\CompressionLevel;
use App\Services\Pdf\PdfCompressionService;
use App\Services\Pdf\PdfTargetSizeCompressionService;
use App\Services\Pdf\RotatableFpdi;
use App\Services\System\SystemBinaryDetector;
use Tests\TestCase;

class PdfCompressionFunctionalTest extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_pdf_comp_' . uniqid();
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
     * Create an image-heavy test PDF with high-resolution images.
     */
    protected function createImageHeavyPdf(string $path): string
    {
        $imgPaths = [];
        for ($i = 1; $i <= 2; $i++) {
            $imgPath = $this->tmpDir . "/img_{$i}.jpg";
            $gd = imagecreatetruecolor(1200, 1000);
            for ($x = 0; $x < 1200; $x += 10) {
                for ($y = 0; $y < 1000; $y += 10) {
                    $c = imagecolorallocate($gd, ($x * 3) % 255, ($y * 5) % 255, (($x + $y) * 2) % 255);
                    imagefilledrectangle($gd, $x, $y, $x + 9, $y + 9, $c);
                }
            }
            imagejpeg($gd, $imgPath, 100);
            imagedestroy($gd);
            $imgPaths[] = $imgPath;
        }

        $pdf = new RotatableFpdi();
        foreach ($imgPaths as $img) {
            $pdf->AddPage('P', [210, 297]);
            $pdf->Image($img, 10, 10, 190, 150);
        }
        $pdf->Output($path, 'F');

        return $path;
    }

    /**
     * Create a text-heavy test PDF without large raster images.
     */
    protected function createTextHeavyPdf(string $path): string
    {
        $pdf = new RotatableFpdi();
        for ($p = 1; $p <= 3; $p++) {
            $pdf->AddPage('P', [210, 297]);
            $pdf->SetFont('Helvetica', '', 12);
            for ($line = 1; $line <= 40; $line++) {
                $pdf->Text(20, 15 + ($line * 6), "Paragraph line {$line} of document page {$p}: Standard textual content for vector text testing.");
            }
        }
        $pdf->Output($path, 'F');

        return $path;
    }

    public function test_preset_compression_on_image_heavy_pdf_reduces_size_via_ghostscript(): void
    {
        $detector = app(SystemBinaryDetector::class);
        $sourcePdf = $this->createImageHeavyPdf($this->tmpDir . '/image_heavy.pdf');
        $outPdf = $this->tmpDir . '/image_heavy_compressed.pdf';

        $service = app(PdfCompressionService::class);
        $res = $service->compress($sourcePdf, $outPdf, CompressionLevel::EXTREME);

        $this->assertFileExists($outPdf);
        $this->assertGreaterThan(0, $res['processed_size']);

        if ($detector->hasGhostscript()) {
            $this->assertSame('ghostscript', $res['engine']);
            $this->assertTrue($res['is_deep']);
            $this->assertLessThan($res['original_size'], $res['processed_size']);
            $this->assertGreaterThan(0.0, $res['savings_percent']);
        }
    }

    public function test_text_heavy_pdf_handles_pre_compressed_files_truthfully(): void
    {
        $sourcePdf = $this->createTextHeavyPdf($this->tmpDir . '/text_heavy.pdf');
        $outPdf = $this->tmpDir . '/text_heavy_compressed.pdf';

        $service = app(PdfCompressionService::class);
        $res = $service->compress($sourcePdf, $outPdf, CompressionLevel::LOW);

        $this->assertFileExists($outPdf);
        // Requirement: Do not claim compression if output >= input unless explained
        $this->assertLessThanOrEqual($res['original_size'], $res['processed_size']);
        if ($res['processed_size'] === $res['original_size']) {
            $this->assertSame(0, $res['saved_bytes']);
            $this->assertSame(0.0, $res['savings_percent']);
            $this->assertNotNull($res['notice']);
        }
    }

    public function test_target_size_pdf_compression_records_iterations_and_diagnostics(): void
    {
        $detector = app(SystemBinaryDetector::class);
        if (!$detector->hasGhostscript()) {
            $this->markTestSkipped('Ghostscript not available.');
        }

        $sourcePdf = $this->createImageHeavyPdf($this->tmpDir . '/source_target.pdf');
        $outPdf = $this->tmpDir . '/out_target.pdf';

        $origSize = filesize($sourcePdf);
        $targetBytes = (int) round($origSize * 0.4);

        $service = app(PdfTargetSizeCompressionService::class);
        $res = $service->compressToTarget($sourcePdf, $outPdf, $targetBytes, 'balanced');

        $this->assertFileExists($outPdf);
        $this->assertArrayHasKey('diagnostics', $res);
        $this->assertNotEmpty($res['diagnostics']['attempts']);
        $this->assertArrayHasKey('stop_reason', $res['diagnostics']);
        $this->assertSame('ghostscript', $res['engine']);

        // Verify that the output PDF opens and has valid page count
        $pdf = new RotatableFpdi();
        $pageCount = $pdf->setSourceFile($outPdf);
        $this->assertSame(2, $pageCount);
    }

    public function test_target_size_pdf_compression_truthfully_reports_unachievable_target(): void
    {
        $detector = app(SystemBinaryDetector::class);
        if (!$detector->hasGhostscript()) {
            $this->markTestSkipped('Ghostscript not available.');
        }

        $sourcePdf = $this->createImageHeavyPdf($this->tmpDir . '/source_impossible.pdf');
        $outPdf = $this->tmpDir . '/out_impossible.pdf';

        // An impossible target size: 100 bytes for a multi-page PDF
        $impossibleTargetBytes = 100;

        $service = app(PdfTargetSizeCompressionService::class);
        $res = $service->compressToTarget($sourcePdf, $outPdf, $impossibleTargetBytes, 'max_quality');

        $this->assertFileExists($outPdf);
        // Strict requirement: Never claim target achieved unless processed_size <= target_size
        $this->assertFalse($res['target_achieved']);
        $this->assertSame('not_achieved', $res['target_status']);
        $this->assertNotNull($res['explanation']);
        $this->assertStringContainsString('could not be achieved', $res['explanation']);
    }
}
