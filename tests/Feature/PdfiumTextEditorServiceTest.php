<?php

namespace Tests\Feature;

use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfiumTextEditorService;
use App\Services\Pdf\PdfProtectionService;
use App\Services\System\SubprocessExecutorService;
use App\Services\System\SystemBinaryDetector;
use App\Exceptions\UnsupportedPdfFontException;
use App\Services\Pdf\PdfAnnotationApplierService;
use FPDF;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class PdfiumTextEditorServiceTest extends TestCase
{
    protected PdfiumTextEditorService $service;
    protected SystemBinaryDetector $detector;
    protected FileStorageManager $storage;
    protected SubprocessExecutorService $executor;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PdfiumTextEditorService::class);
        $this->detector = app(SystemBinaryDetector::class);
        $this->storage = app(FileStorageManager::class);
        $this->executor = app(SubprocessExecutorService::class);

        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdfium_test_' . uniqid();
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
            $p = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($p) ? $this->deleteRecursive($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    protected function createStandardInvoicePdf(string $filename = 'invoice.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('P', 'pt', 'A4');
        $pdf->AddPage();

        // Title
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Text(72, 90, 'Invoice #1042 - Hello World');

        // Customer
        $pdf->SetFont('Arial', '', 12);
        $pdf->Text(72, 130, 'Customer: John Doe');

        // Items
        $pdf->Text(72, 160, 'Item: Standard Consulting (Hours: 5, Rate: $50.00)');

        // Total
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Text(72, 200, 'Total Amount: $250.00 USD');

        // Repeated Hello
        $pdf->SetFont('Arial', '', 11);
        $pdf->Text(72, 240, 'Greeting line 1: Hello valuable customer!');
        $pdf->Text(72, 270, 'Greeting line 2: Hello again from accounting.');

        // Note
        $pdf->SetFont('Arial', 'I', 10);
        $pdf->Text(72, 310, 'Note: All payments are due within 30 days. Contact: billing@example.com.');

        $pdf->Output($path, 'F');
        return $path;
    }

    protected function createMultiPagePdf(string $filename = 'multipage.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('P', 'pt', 'A4');

        // Page 1
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Text(72, 100, 'Page 1 Header: Alpha Report');
        $pdf->SetFont('Arial', '', 12);
        $pdf->Text(72, 150, 'Page 1 Content: Project status is pending.');

        // Page 2
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Text(72, 100, 'Page 2 Header: Beta Metrics');
        $pdf->SetFont('Arial', '', 12);
        $pdf->Text(72, 150, 'Page 2 Content: Revenue is $50,000 USD.');

        // Page 3
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Text(72, 100, 'Page 3 Header: Gamma Summary');
        $pdf->SetFont('Arial', '', 12);
        $pdf->Text(72, 150, 'Page 3 Content: Final sign-off required.');

        $pdf->Output($path, 'F');
        return $path;
    }

    protected function createRotatedPagePdf(string $filename = 'rotated.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('L', 'pt', 'A4'); // Landscape orientation
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Text(72, 100, 'Landscape Orientation Document');
        $pdf->Output($path, 'F');
        return $path;
    }

    protected function createScannedImageOnlyPdf(string $filename = 'scanned.pdf'): string
    {
        $imgPath = $this->tempDir . DIRECTORY_SEPARATOR . 'scan_page.jpg';
        $im = imagecreatetruecolor(200, 200);
        $bg = imagecolorallocate($im, 240, 240, 240);
        imagefilledrectangle($im, 0, 0, 200, 200, $bg);
        imagejpeg($im, $imgPath);
        imagedestroy($im);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('P', 'pt', 'A4');
        $pdf->AddPage();
        // Insert ONLY image, zero text objects
        $pdf->Image($imgPath, 72, 72, 200, 200);
        $pdf->Output($path, 'F');
        return $path;
    }

    protected function extractTextWithPoppler(string $pdfPath): string
    {
        $pdftotext = $this->detector->getPdftotextPath() ?? 'pdftotext';
        $res = $this->executor->execute([$pdftotext, $pdfPath, '-']);
        return $res['output'];
    }

    // 1. Hello -> Hi replacement
    public function test_1_hello_to_hi_replacement_in_real_pdf(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Hello World');
        $this->assertNotEmpty($matches);

        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Hello World',
                'replacement_text' => 'Hi World',
            ]
        ]);

        $this->assertTrue($res['success']);
        $this->assertGreaterThan(0, $res['modified_count']);

        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Hi World', $extracted);
        $this->assertStringNotContainsString('Hello World', $extracted);
    }

    // 2. Short -> Shorter
    public function test_2_short_to_shorter_replacement(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Total Amount: $250.00 USD');
        $this->assertNotEmpty($matches);

        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Total Amount: $250.00 USD',
                'replacement_text' => 'Total: $0',
            ]
        ]);

        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Total: $0', $extracted);
        $this->assertStringNotContainsString('$250.00 USD', $extracted);
    }

    // 3. Short -> Longer with auto-fit
    public function test_3_short_to_longer_replacement_with_auto_fit(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Customer: John Doe');
        $this->assertNotEmpty($matches);

        $longName = 'Customer: Dr. Maximillian Bartholomew-Humphrey III, Senior Vice President';
        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Customer: John Doe',
                'replacement_text' => $longName,
                'fit_strategy'     => 'auto_fit',
            ]
        ]);

        $this->assertTrue($res['success']);
        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString($longName, $extracted);
        $this->assertStringNotContainsString('John Doe', $extracted);
    }

    // 4. Selective occurrence replacement
    public function test_4_selective_occurrence_replacement(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Hello valuable customer!');
        $this->assertCount(1, $matches);

        // Replace ONLY Greeting line 1, leaving Greeting line 2 untouched
        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Hello valuable customer!',
                'replacement_text' => 'Greetings esteemed partner!',
            ]
        ]);

        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Greetings esteemed partner!', $extracted);
        $this->assertStringNotContainsString('Hello valuable customer!', $extracted);
        // Greeting line 2 MUST still have its original "Hello"
        $this->assertStringContainsString('Greeting line 2: Hello again from accounting.', $extracted);
    }

    // 5. Repeated text preserves untouched occurrences
    public function test_5_repeated_text_preserves_untouched_occurrences(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $helloMatches = $this->service->findText($pdf, 'Hello');
        $this->assertCount(3, $helloMatches); // Title, Line 1, Line 2

        // Modify only the second match
        $target = $helloMatches[1];
        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $target['object_id'],
                'target_text'      => 'Hello',
                'replacement_text' => 'Welcome',
            ]
        ]);

        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Invoice #1042 - Hello World', $extracted);
        $this->assertStringContainsString('Greeting line 1: Welcome valuable customer!', $extracted);
        $this->assertStringContainsString('Greeting line 2: Hello again from accounting.', $extracted);
    }

    // 6. Multiple pages text editing
    public function test_6_multiple_pages_text_editing(): void
    {
        $pdf = $this->createMultiPagePdf();
        $inspection = $this->service->inspectDocument($pdf);
        $this->assertEquals(3, $inspection['page_count']);

        $matchP1 = $this->service->findText($pdf, 'Project status is pending.');
        $matchP3 = $this->service->findText($pdf, 'Final sign-off required.');

        $res = $this->service->replaceText($pdf, [
            [
                'page_index'       => 0,
                'object_id'        => $matchP1[0]['object_id'],
                'target_text'      => 'pending',
                'replacement_text' => 'COMPLETED',
            ],
            [
                'page_index'       => 2,
                'object_id'        => $matchP3[0]['object_id'],
                'target_text'      => 'required',
                'replacement_text' => 'APPROVED',
            ],
        ]);

        $this->assertTrue($res['success']);
        $this->assertEquals(2, $res['modified_count']);

        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Project status is COMPLETED.', $extracted);
        $this->assertStringContainsString('Final sign-off APPROVED.', $extracted);
        // Page 2 must remain intact
        $this->assertStringContainsString('Revenue is $50,000 USD.', $extracted);
    }

    // 7. Different fonts and styles
    public function test_7_different_fonts_and_styles(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $inspection = $this->service->inspectDocument($pdf);
        $this->assertNotEmpty($inspection['pages'][0]['text_objects']);

        // Inspect font sizes
        $sizes = array_map('floatval', array_column($inspection['pages'][0]['text_objects'], 'font_size'));
        $this->assertContains(18.0, $sizes);
        $this->assertContains(12.0, $sizes);
        $this->assertContains(14.0, $sizes);
        $this->assertContains(10.0, $sizes);
    }

    // 8. Rotated page handling
    public function test_8_rotated_page_handling(): void
    {
        $pdf = $this->createRotatedPagePdf();
        $inspection = $this->service->inspectDocument($pdf);

        $this->assertEquals(1, $inspection['page_count']);
        // Landscape A4 width ~841 pts, height ~595 pts
        $page = $inspection['pages'][0];
        $this->assertGreaterThan($page['height'], $page['width']);

        $matches = $this->service->findText($pdf, 'Landscape Orientation Document');
        $this->assertCount(1, $matches);

        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Landscape',
                'replacement_text' => 'Horizontal',
            ]
        ]);

        $this->assertTrue($res['success']);
        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Horizontal Orientation Document', $extracted);
    }

    // 9. Unicode replacement
    public function test_9_unicode_replacement(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Customer: John Doe');
        $this->assertNotEmpty($matches);

        $unicodeText = 'Client: Élégant René & Co. (€99.00)';
        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Customer: John Doe',
                'replacement_text' => $unicodeText,
            ]
        ]);

        $this->assertTrue($res['success']);
        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Élégant René & Co.', $extracted);
    }

    // 10. Arabic replacement
    public function test_10_arabic_replacement(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Customer: John Doe');
        $this->assertNotEmpty($matches);

        $arabicText = 'العميل: أحمد محمد';
        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Customer: John Doe',
                'replacement_text' => $arabicText,
            ]
        ]);

        $this->assertTrue($res['success']);
        $extracted = $this->extractTextWithPoppler($res['output_path']);
        // Verify Arabic characters exist in extracted text
        $hasArabic = strpos($extracted, 'ﺃﺣﻤﺪ ﻣﺤﻤﺪ') !== false
            || preg_match('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $extracted);
        $this->assertTrue((bool)$hasArabic, 'Arabic text was not preserved in modified PDF');
        $this->assertStringNotContainsString('John Doe', $extracted);
    }

    // 11. Mixed Arabic + English
    public function test_11_mixed_arabic_and_english(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Customer: John Doe');
        $this->assertNotEmpty($matches);

        $mixedText = 'العميل: Ahmed Mohamed (رقم 1042)';
        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Customer: John Doe',
                'replacement_text' => $mixedText,
            ]
        ]);

        $this->assertTrue($res['success']);
        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Ahmed Mohamed', $extracted);
        $this->assertStringContainsString('1042', $extracted);
    }

    // 12. Unsupported glyph handling upgrades to fallback font
    public function test_12_unsupported_glyph_handling_upgrades_to_fallback_font(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Total Amount: $250.00 USD');
        $this->assertNotEmpty($matches);

        // Cyrillic glyphs not in standard Helvetica font
        $cyrillic = 'Итого: 1,500.00 руб';
        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Total Amount: $250.00 USD',
                'replacement_text' => $cyrillic,
            ]
        ]);

        $this->assertTrue($res['success']);
        $extracted = $this->extractTextWithPoppler($res['output_path']);
        // Must NOT output corrupted 'ÿÿÿÿ'
        $this->assertStringNotContainsString('ÿÿÿÿ', $extracted);
        $this->assertStringContainsString('1,500.00', $extracted);
    }

    // 13. Scanned PDF detection
    public function test_13_scanned_pdf_detection(): void
    {
        $pdf = $this->createScannedImageOnlyPdf();
        $inspection = $this->service->inspectDocument($pdf);

        $this->assertTrue($inspection['is_scanned']);
        $this->assertEquals(0, $inspection['total_text_objects']);

        // Attempting to replace text in scanned PDF must fail with informative exception
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('image-only scan');

        $this->service->replaceText($pdf, [
            [
                'target_text'      => 'Some Text',
                'replacement_text' => 'New Text',
            ]
        ]);
    }

    // 14. Encrypted PDF handling
    public function test_14_encrypted_pdf_handling(): void
    {
        $plainPdf = $this->createStandardInvoicePdf();
        $encryptedPdf = $this->tempDir . DIRECTORY_SEPARATOR . 'encrypted.pdf';
        $protService = app(PdfProtectionService::class);
        $protService->protect($plainPdf, $encryptedPdf, 'secret123', 'adminPass99', []);

        // Without password -> throws RuntimeException
        try {
            $this->service->inspectDocument($encryptedPdf);
            $this->fail('Expected RuntimeException for encrypted PDF without password.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('password', strtolower($e->getMessage()));
        }

        // With correct password -> inspection succeeds
        $inspection = $this->service->inspectDocument($encryptedPdf, 'secret123');
        $this->assertTrue($inspection['success']);
        $this->assertEquals(1, $inspection['page_count']);
        $this->assertEquals(7, $inspection['total_text_objects']);
    }

    // 15. Malformed PDF handling
    public function test_15_malformed_pdf_handling(): void
    {
        $badPdf = $this->tempDir . DIRECTORY_SEPARATOR . 'corrupted.pdf';
        file_put_contents($badPdf, '%PDF-1.4\nCorrupted Binary Junk Header Body Garbage %%EOF');

        $this->expectException(RuntimeException::class);
        $this->service->inspectDocument($badPdf);
    }

    // 16. Original PDF unchanged
    public function test_16_original_pdf_unchanged(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $origHash = hash_file('sha256', $pdf);

        $matches = $this->service->findText($pdf, 'Hello World');
        $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Hello World',
                'replacement_text' => 'Modified World',
            ]
        ]);

        $afterHash = hash_file('sha256', $pdf);
        $this->assertEquals($origHash, $afterHash, 'Original input PDF must never be modified in place.');
    }

    // 17. Output remains selectable via pdftotext
    public function test_17_output_text_remains_selectable_via_pdftotext(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Customer: John Doe');

        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'John Doe',
                'replacement_text' => 'Alice Wonderland',
            ]
        ]);

        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Customer: Alice Wonderland', $extracted);
        $this->assertStringNotContainsString('John Doe', $extracted);
    }

    // 18. No whiteout rectangles in content stream
    public function test_18_no_whiteout_rectangles_in_content_stream(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Hello World');

        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Hello World',
                'replacement_text' => 'Universe',
            ]
        ]);

        $raw = file_get_contents($res['output_path']);
        // Scan decompressed streams for rectangle / fill path operators
        preg_match_all("/(\d+)\s+0\s+obj(.*?)endobj/s", $raw, $matches, PREG_SET_ORDER);
        $hasText = false;
        $rectFillCount = 0;

        foreach ($matches as $m) {
            if (strpos($m[2], 'stream') !== false) {
                $body = $m[2];
                $start = strpos($body, 'stream') + 6;
                if (substr($body, $start, 2) === "\r\n") $start += 2;
                elseif (substr($body, $start, 1) === "\n" || substr($body, $start, 1) === "\r") $start += 1;
                $end = strpos($body, 'endstream');
                $stream = substr($body, $start, $end - $start);
                $decomp = @gzuncompress($stream);
                if ($decomp && strpos($decomp, 'BT') !== false) {
                    $hasText = true;
                    // Check for rectangle / fill operators: re, f, F, b, B
                    preg_match_all('/\b\d+(\.\d+)?\s+\d+(\.\d+)?\s+\d+(\.\d+)?\s+\d+(\.\d+)?\s+re\b/', $decomp, $reMatches);
                    $rectFillCount += count($reMatches[0]);
                }
            }
        }

        $this->assertTrue($hasText, 'Must find text stream in output PDF');
        $this->assertEquals(0, $rectFillCount, 'Zero whiteout rectangles allowed in modified content stream');
    }

    // 19. PDF remains valid via pdfinfo
    public function test_19_pdf_remains_valid_via_pdfinfo(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $matches = $this->service->findText($pdf, 'Hello World');

        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Hello World',
                'replacement_text' => 'Verified PDF',
            ]
        ]);

        $pdfinfo = $this->detector->getPdfinfoPath() ?? 'pdfinfo';
        $infoRes = $this->executor->execute([$pdfinfo, $res['output_path']]);

        $this->assertEquals(0, $infoRes['exit_code']);
        $this->assertStringContainsString('Pages:', $infoRes['output']);
        $this->assertStringContainsString('Page size:', $infoRes['output']);
        $this->assertStringContainsString('PDF version:', $infoRes['output']);
    }
    // 20. Type 3 font detection per page
    public function test_20_detects_type3_fonts_and_marks_page_native_editable_false(): void
    {
        $fixture = base_path('storage/payment_status_qa.pdf');
        if (!file_exists($fixture)) {
            $this->markTestSkipped('payment_status_qa.pdf fixture not available');
        }

        $inspection = $this->service->inspectDocument($fixture);
        $this->assertNotEmpty($inspection['pages']);

        $page0 = $inspection['pages'][0];
        $this->assertTrue($page0['has_type3_fonts']);
        $this->assertFalse($page0['native_editable']);
        $this->assertGreaterThan(0, $page0['type3_font_count']);
        $this->assertNotNull($page0['warning']);
        $this->assertEquals('UNSUPPORTED_TYPE3_FONTS', $page0['warning']['code']);
        $this->assertStringContainsString('Type 3', $page0['editable_reason']);
    }

    // 21. Rejects native text edit on Type 3 page and preserves original PDF
    public function test_21_rejects_native_text_edit_on_type3_page_and_throws_unsupported_pdf_font_exception(): void
    {
        $fixture = base_path('storage/payment_status_qa.pdf');
        if (!file_exists($fixture)) {
            $this->markTestSkipped('payment_status_qa.pdf fixture not available');
        }

        $origHash = hash_file('sha256', $fixture);

        $this->expectException(UnsupportedPdfFontException::class);
        $this->expectExceptionMessageMatches('/Type 3/i');

        try {
            $this->service->replaceText($fixture, [
                [
                    'page_index'       => 0,
                    'target_text'      => 'Payment Status',
                    'replacement_text' => 'Billing Status',
                ]
            ]);
        } finally {
            $afterHash = hash_file('sha256', $fixture);
            $this->assertEquals($origHash, $afterHash, 'Original Type 3 PDF must remain byte-identical.');
        }
    }

    // 22. Safe TrueType document remains fully editable with unrelated text intact
    public function test_22_safe_truetype_document_remains_editable_with_unrelated_text_intact(): void
    {
        $pdf = $this->createStandardInvoicePdf();
        $inspection = $this->service->inspectDocument($pdf);
        $this->assertTrue($inspection['pages'][0]['native_editable']);
        $this->assertFalse($inspection['pages'][0]['has_type3_fonts']);

        $matches = $this->service->findText($pdf, 'Customer: John Doe');
        $this->assertNotEmpty($matches);

        $res = $this->service->replaceText($pdf, [
            [
                'object_id'        => $matches[0]['object_id'],
                'target_text'      => 'Customer: John Doe',
                'replacement_text' => 'Customer: Jane Smith',
            ]
        ]);

        $this->assertTrue($res['success']);
        $extracted = $this->extractTextWithPoppler($res['output_path']);
        $this->assertStringContainsString('Customer: Jane Smith', $extracted);
        // Ensure other content remains intact
        $this->assertStringContainsString('Invoice #1042 - Hello World', $extracted);
        $this->assertStringContainsString('Total Amount: $250.00 USD', $extracted);
    }

    // 23. Additive annotations remain functional on Type 3 documents without content stream damage
    public function test_23_additive_annotations_remain_functional_on_type3_documents(): void
    {
        $fixture = base_path('storage/payment_status_qa.pdf');
        if (!file_exists($fixture)) {
            $this->markTestSkipped('payment_status_qa.pdf fixture not available');
        }

        $applier = app(PdfAnnotationApplierService::class);
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'annotated_type3.pdf';

        $res = $applier->apply(
            $fixture,
            $outputPath,
            [
                [
                    'page'      => 1,
                    'text'      => 'VERIFIED AUDIT OVERLAY',
                    'x_ratio'   => 0.1,
                    'y_ratio'   => 0.1,
                    'font_size' => 14,
                    'color'     => '#dc2626',
                ]
            ],
            [
                [
                    'page'         => 1,
                    'type'         => 'rect',
                    'x_ratio'      => 0.05,
                    'y_ratio'      => 0.05,
                    'width_ratio'  => 0.4,
                    'height_ratio' => 0.1,
                    'stroke_color' => '#dc2626',
                    'fill_color'   => 'none',
                ]
            ]
        );

        $this->assertTrue($res['success']);
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));

        $extracted = $this->extractTextWithPoppler($outputPath);
        // Both the additive overlay AND the original document text must be present
        $this->assertStringContainsString('VERIFIED AUDIT OVERLAY', $extracted);
        $this->assertStringContainsString('Ethical Hacking', $extracted);
        $this->assertStringContainsString('order_TO5IBKEww6rgUh', $extracted);
    }

    // 24. No corrupted partial output exists when unsafe edit is rejected
    public function test_24_no_corrupted_partial_output_remains_on_rejection(): void
    {
        $fixture = base_path('storage/payment_status_qa.pdf');
        if (!file_exists($fixture)) {
            $this->markTestSkipped('payment_status_qa.pdf fixture not available');
        }

        $jobId = \Illuminate\Support\Str::uuid()->toString();

        try {
            $this->service->replaceText($fixture, [
                [
                    'page_index'       => 0,
                    'target_text'      => 'Course',
                    'replacement_text' => 'Program',
                ]
            ], [
                'job_id' => $jobId,
            ]);
            $this->fail('Expected UnsupportedPdfFontException was not thrown');
        } catch (UnsupportedPdfFontException $e) {
            $this->assertStringContainsString('Type 3', $e->getMessage());
        }

        $outputPath = $this->storage->generateOutputPath($jobId, 'pdf', 'edited');
        $this->assertFileDoesNotExist($outputPath, 'No partial or corrupt output file should remain after rejected edit.');
    }
}
