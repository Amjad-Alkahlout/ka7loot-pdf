<?php

namespace Tests\Unit;

use App\Enums\PageOrientation;
use App\Enums\PageSize;
use App\Services\Pdf\JpgToPdfService;
use App\Services\Pdf\PdfMergeService;
use App\Services\Pdf\PdfOrganizeService;
use App\Services\Pdf\PdfSplitService;
use FPDF;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class PdfProcessingTest extends TestCase
{
    protected string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_unit_test_' . uniqid();
        if (!is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->testDir)) {
            $files = glob($this->testDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testDir);
        }
        parent::tearDown();
    }

    /**
     * Helper to create a valid real multi-page PDF using FPDF.
     */
    protected function createSamplePdf(string $filename, int $pages = 2): string
    {
        $path = $this->testDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();

        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(40, 10, "Document: {$filename} - Page {$i}");
        }

        $pdf->Output($path, 'F');
        return $path;
    }

    /**
     * Helper to create a sample JPEG image.
     */
    protected function createSampleJpg(string $filename, int $w = 400, int $h = 300): string
    {
        $path = $this->testDir . DIRECTORY_SEPARATOR . $filename;
        $manager = new ImageManager(new Driver());
        $img = $manager->create($w, $h);
        $img->toJpeg(90)->save($path);
        return $path;
    }

    public function test_pdf_merge_combines_multiple_documents(): void
    {
        $service = new PdfMergeService();
        $doc1 = $this->createSamplePdf('doc1.pdf', 2);
        $doc2 = $this->createSamplePdf('doc2.pdf', 3);
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'merged.pdf';

        $result = $service->merge([$doc1, $doc2], $output);

        $this->assertFileExists($output);
        $this->assertEquals(5, $result['total_pages']);
        $this->assertEquals(2, $result['files_count']);
        $this->assertGreaterThan(0, $result['size_bytes']);

        // Verify with FPDI reader
        $reader = new Fpdi();
        $count = $reader->setSourceFile($output);
        $this->assertEquals(5, $count);
    }

    public function test_pdf_split_parses_page_ranges(): void
    {
        $service = new PdfSplitService();
        $pages = $service->parsePageRange('1-3, 5, 8-9', 10);

        $this->assertEquals([1, 2, 3, 5, 8, 9], $pages);
    }

    public function test_pdf_split_extracts_specific_pages(): void
    {
        $service = new PdfSplitService();
        $doc = $this->createSamplePdf('source_5p.pdf', 5);
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'extracted.pdf';

        $result = $service->extractPages($doc, $output, [1, 3, 5]);

        $this->assertFileExists($output);
        $this->assertEquals(3, $result['extracted_pages']);

        $reader = new Fpdi();
        $this->assertEquals(3, $reader->setSourceFile($output));
    }

    public function test_pdf_split_all_pages(): void
    {
        $service = new PdfSplitService();
        $doc = $this->createSamplePdf('source_3p.pdf', 3);
        $splitDir = $this->testDir . DIRECTORY_SEPARATOR . 'split_out';

        $results = $service->splitAllPages($doc, $splitDir);

        $this->assertCount(3, $results);
        foreach ($results as $res) {
            $this->assertFileExists($res['path']);
        }
    }

    public function test_pdf_organize_reorders_and_rotates_pages(): void
    {
        $service = new PdfOrganizeService();
        $doc = $this->createSamplePdf('source_4p.pdf', 4);
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'organized.pdf';

        // Reorder to: page 3 (rotate 90), page 1 (rotate 0). Page 2 and 4 are deleted.
        $operations = [
            ['page' => 3, 'rotation' => 90],
            ['page' => 1, 'rotation' => 0],
        ];

        $result = $service->organize($doc, $output, $operations);

        $this->assertFileExists($output);
        $this->assertEquals(2, $result['total_pages']);

        $reader = new Fpdi();
        $this->assertEquals(2, $reader->setSourceFile($output));

        // Check rotation in raw PDF content
        $content = file_get_contents($output);
        $this->assertStringContainsString('/Rotate 90', $content);
    }

    public function test_jpg_to_pdf_generates_pdf_from_images(): void
    {
        $service = new JpgToPdfService();
        $img1 = $this->createSampleJpg('img1.jpg', 600, 400);
        $img2 = $this->createSampleJpg('img2.jpg', 300, 500);
        $output = $this->testDir . DIRECTORY_SEPARATOR . 'images.pdf';

        $result = $service->convertImagesToPdf(
            [$img1, $img2],
            $output,
            PageSize::A4,
            PageOrientation::AUTO,
            margin: 5.0
        );

        $this->assertFileExists($output);
        $this->assertEquals(2, $result['total_pages']);

        $reader = new Fpdi();
        $this->assertEquals(2, $reader->setSourceFile($output));
    }
}
