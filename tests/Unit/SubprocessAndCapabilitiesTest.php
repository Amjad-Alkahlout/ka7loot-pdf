<?php

namespace Tests\Unit;

use App\Enums\CompressionLevel;
use App\Services\Pdf\PdfCompressionService;
use App\Services\Pdf\PdfToImageService;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use FPDF;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SubprocessAndCapabilitiesTest extends TestCase
{
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'subproc_test_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testDir);
        }
        parent::tearDown();
    }

    protected function createSamplePdf(string $filename): string
    {
        $path = $this->testDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(40, 10, "Subprocess Capability Test");
        $pdf->Output($path, 'F');
        return $path;
    }

    public function test_compression_fallback_reports_limited_optimization_when_ghostscript_missing(): void
    {
        $detector = Mockery::mock(SystemBinaryDetector::class);
        $detector->shouldReceive('hasGhostscript')->andReturn(false);

        $executor = new SubprocessExecutorService();
        $service = new PdfCompressionService($detector, $executor);

        $source = $this->createSamplePdf('test_sample.pdf');
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'optimized.pdf';

        $result = $service->compress($source, $output, CompressionLevel::RECOMMENDED);

        $this->assertFileExists($output);
        $this->assertEquals('pure_php', $result['engine']);
        $this->assertFalse($result['is_deep']);
        $this->assertEquals('Limited optimization (Pure PHP)', $result['engine_label']);
        $this->assertNotNull($result['notice']);
        $this->assertStringContainsString('Ghostscript is required for deep compression', $result['notice']);
    }

    public function test_compression_reports_deep_compression_when_ghostscript_present(): void
    {
        $detector = Mockery::mock(SystemBinaryDetector::class);
        $detector->shouldReceive('hasGhostscript')->andReturn(true);
        $detector->shouldReceive('getGhostscriptPath')->andReturn('C:\\tools\\gswin64c.exe');

        $executor = Mockery::mock(SubprocessExecutorService::class);
        $executor->shouldReceive('execute')
            ->once()
            ->andReturnUsing(function ($cmd) {
                // Mock GS writing output
                $outputArg = null;
                foreach ($cmd as $arg) {
                    if (str_starts_with($arg, '-sOutputFile=')) {
                        $outputArg = substr($arg, strlen('-sOutputFile='));
                        break;
                    }
                }
                if ($outputArg) {
                    file_put_contents($outputArg, '%PDF-1.4 Fake Compressed Content');
                }
                return ['output' => '', 'error_output' => '', 'exit_code' => 0];
            });

        $service = new PdfCompressionService($detector, $executor);

        $source = $this->createSamplePdf('test_deep.pdf');
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'deep_compressed.pdf';

        $result = $service->compress($source, $output, CompressionLevel::RECOMMENDED);

        $this->assertEquals('ghostscript', $result['engine']);
        $this->assertTrue($result['is_deep']);
        $this->assertEquals('Deep compression (Ghostscript)', $result['engine_label']);
        $this->assertNull($result['notice']);
    }

    public function test_pdf_to_image_throws_informative_exception_when_poppler_missing(): void
    {
        $detector = Mockery::mock(SystemBinaryDetector::class);
        $detector->shouldReceive('hasPoppler')->andReturn(false);

        $executor = new SubprocessExecutorService();
        $service = new PdfToImageService($detector, $executor);

        $source = $this->createSamplePdf('source_doc.pdf');
        $outDir = $this->testDir . DIRECTORY_SEPARATOR . 'images';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Poppler utility \(pdftoppm\).*choco install poppler/i');

        $service->convertPdfToImages($source, $outDir);
    }

    public function test_subprocess_executor_runs_safe_command(): void
    {
        $executor = new SubprocessExecutorService();
        $result = $executor->execute([PHP_BINARY, '-v']);

        $this->assertEquals(0, $result['exit_code']);
        $this->assertStringContainsString('PHP 8.5', $result['output']);
    }

    public function test_subprocess_executor_fails_on_nonexistent_command(): void
    {
        $this->expectException(RuntimeException::class);

        $executor = new SubprocessExecutorService();
        $executor->execute(['nonexistent_binary_xyz_123', '--help']);
    }
}
