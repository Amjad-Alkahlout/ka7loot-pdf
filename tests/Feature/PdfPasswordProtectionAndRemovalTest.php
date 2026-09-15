<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\ProtectPdfComponent;
use App\Livewire\Tools\UnlockPdfComponent;
use App\Models\FileJob;
use App\Services\Pdf\PdfPasswordRemovalService;
use App\Services\Pdf\PdfProtectionService;
use App\Services\System\SystemBinaryDetector;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class PdfPasswordProtectionAndRemovalTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected SystemBinaryDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(SystemBinaryDetector::class);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_auth_test_' . uniqid();
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

    protected function createPdfFixture(string $filename = 'plain.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(40, 10, 'Confidential Content Inside PDF');
        $pdf->Output($path, 'F');

        return $path;
    }

    protected function createMultiPageTextPdf(string $filename = 'multipage_text.pdf'): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();
        for ($i = 1; $i <= 3; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, "Document Page Number {$i}", 0, 1);
            $pdf->MultiCell(0, 8, str_repeat("Detailed enterprise report paragraph with sensitive operational figures. ", 15));
        }
        $pdf->Output($path, 'F');

        return $path;
    }

    protected function createImageBearingPdf(string $filename = 'image_bearing.pdf'): string
    {
        $imgPath = $this->tempDir . DIRECTORY_SEPARATOR . 'fixture_img.jpg';
        $im = imagecreatetruecolor(120, 120);
        $bg = imagecolorallocate($im, 79, 70, 229);
        imagefilledrectangle($im, 0, 0, 120, 120, $bg);
        imagejpeg($im, $imgPath);
        imagedestroy($im);

        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, 'Report with Embedded Graphic', 0, 1);
        $pdf->Image($imgPath, 20, 30, 60, 60);
        $pdf->Output($path, 'F');

        @unlink($imgPath);
        return $path;
    }

    public function test_pdf_protection_service_encrypts_pdf_with_password(): void
    {
        $sourcePath = $this->createPdfFixture('to_protect.pdf');
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'protected.pdf';

        $service = app(PdfProtectionService::class);
        $result = $service->protect(
            sourcePath: $sourcePath,
            outputPath: $outputPath,
            userPassword: 'SecretPassword123!',
            permissions: [
                'allow_printing'  => true,
                'allow_copying'   => false,
                'allow_modifying' => false,
            ]
        );

        $this->assertTrue(file_exists($outputPath));
        $this->assertGreaterThan(0, filesize($outputPath));
        $this->assertEquals('ghostscript', $result['engine']);

        $removalService = app(PdfPasswordRemovalService::class);
        $this->assertTrue($removalService->isEncrypted($outputPath));

        // Verify that pdfinfo with correct password succeeds
        $this->assertTrue($removalService->verifyPassword($outputPath, 'SecretPassword123!'));

        // Verify that wrong password fails
        $this->assertFalse($removalService->verifyPassword($outputPath, 'WrongPassword456!'));
    }

    public function test_pdf_password_removal_service_decrypts_authenticated_pdf(): void
    {
        $sourcePath = $this->createPdfFixture('doc.pdf');
        $encryptedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'encrypted.pdf';
        $unlockedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'unlocked.pdf';

        $protectionService = app(PdfProtectionService::class);
        $protectionService->protect(
            sourcePath: $sourcePath,
            outputPath: $encryptedPath,
            userPassword: 'UnlockMe2026',
        );

        $removalService = app(PdfPasswordRemovalService::class);
        $this->assertTrue($removalService->isEncrypted($encryptedPath));

        $result = $removalService->removePassword(
            sourcePath: $encryptedPath,
            outputPath: $unlockedPath,
            password: 'UnlockMe2026'
        );

        $this->assertTrue(file_exists($unlockedPath));
        $this->assertGreaterThan(0, filesize($unlockedPath));
        $this->assertFalse($removalService->isEncrypted($unlockedPath));
        $this->assertEquals('ghostscript', $result['engine']);
    }

    public function test_protection_and_unlock_succeeds_under_restricted_or_broken_system_temp_environment(): void
    {
        // Save current environment
        $origTemp = getenv('TEMP');
        $origTmp = getenv('TMP');
        $origServerTemp = $_SERVER['TEMP'] ?? null;
        $origServerTmp = $_SERVER['TMP'] ?? null;

        try {
            // Force unwritable/restricted system temp (e.g. C:\Windows or non-writable path)
            $restrictedPath = 'C:\\Windows';
            putenv("TEMP={$restrictedPath}");
            putenv("TMP={$restrictedPath}");
            $_SERVER['TEMP'] = $restrictedPath;
            $_SERVER['TMP'] = $restrictedPath;

            $sourcePath = $this->createPdfFixture('restricted_source.pdf');
            $originalSha1 = sha1_file($sourcePath);
            $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . 'restricted_protected.pdf';
            $unlockedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'restricted_unlocked.pdf';

            $protectionService = app(PdfProtectionService::class);
            $res = $protectionService->protect(
                sourcePath: $sourcePath,
                outputPath: $outputPath,
                userPassword: 'RestrictedPass2026!'
            );

            // 1. Verify Ghostscript succeeds and output file exists & is non-empty
            $this->assertEquals('ghostscript', $res['engine']);
            $this->assertTrue(file_exists($outputPath));
            $this->assertGreaterThan(0, filesize($outputPath));

            // 2. Verify output is actually encrypted
            $removalService = app(PdfPasswordRemovalService::class);
            $this->assertTrue($removalService->isEncrypted($outputPath));

            // 3. Verify correct password works and wrong password fails
            $this->assertTrue($removalService->verifyPassword($outputPath, 'RestrictedPass2026!'));
            $this->assertFalse($removalService->verifyPassword($outputPath, 'WrongGuess999!'));

            // 4. Verify unlock succeeds under same restricted temp conditions
            $unlockRes = $removalService->removePassword(
                sourcePath: $outputPath,
                outputPath: $unlockedPath,
                password: 'RestrictedPass2026!'
            );
            $this->assertTrue(file_exists($unlockedPath));
            $this->assertGreaterThan(0, filesize($unlockedPath));
            $this->assertFalse($removalService->isEncrypted($unlockedPath));

            // 5. Verify original input file remains unchanged
            $this->assertEquals($originalSha1, sha1_file($sourcePath));

            // 6. Verify scratch temp directories inside target dir were cleanly unlinked
            $leftoverDirs = glob($this->tempDir . DIRECTORY_SEPARATOR . 'gs_*');
            $this->assertEmpty($leftoverDirs, 'Temporary Ghostscript scratch directories were not cleaned up.');

        } finally {
            // Restore environment
            if ($origTemp !== false) putenv("TEMP={$origTemp}"); else putenv('TEMP');
            if ($origTmp !== false) putenv("TMP={$origTmp}"); else putenv('TMP');
            if ($origServerTemp !== null) $_SERVER['TEMP'] = $origServerTemp; else unset($_SERVER['TEMP']);
            if ($origServerTmp !== null) $_SERVER['TMP'] = $origServerTmp; else unset($_SERVER['TMP']);
        }
    }

    public function test_protection_works_with_small_text_and_image_pdfs(): void
    {
        $protectionService = app(PdfProtectionService::class);
        $removalService = app(PdfPasswordRemovalService::class);

        // 1. Small single-cell PDF
        $smallSource = $this->createPdfFixture('small.pdf');
        $smallOut = $this->tempDir . DIRECTORY_SEPARATOR . 'small_prot.pdf';
        $protectionService->protect($smallSource, $smallOut, 'SmallPass1');
        $this->assertTrue($removalService->isEncrypted($smallOut));
        $this->assertTrue($removalService->verifyPassword($smallOut, 'SmallPass1'));

        // 2. Multi-page text PDF
        $textSource = $this->createMultiPageTextPdf('multipage.pdf');
        $textOut = $this->tempDir . DIRECTORY_SEPARATOR . 'text_prot.pdf';
        $protectionService->protect($textSource, $textOut, 'TextPass2');
        $this->assertTrue($removalService->isEncrypted($textOut));
        $this->assertTrue($removalService->verifyPassword($textOut, 'TextPass2'));

        // 3. Image-bearing PDF
        $imgSource = $this->createImageBearingPdf('with_image.pdf');
        $imgOut = $this->tempDir . DIRECTORY_SEPARATOR . 'img_prot.pdf';
        $protectionService->protect($imgSource, $imgOut, 'ImgPass3');
        $this->assertTrue($removalService->isEncrypted($imgOut));
        $this->assertTrue($removalService->verifyPassword($imgOut, 'ImgPass3'));
    }

    public function test_protection_creates_missing_parent_directories_safely(): void
    {
        $sourcePath = $this->createPdfFixture('dir_test.pdf');
        $nestedOutput = $this->tempDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'protected.pdf';

        $this->assertDirectoryDoesNotExist(dirname($nestedOutput));

        $service = app(PdfProtectionService::class);
        $res = $service->protect($sourcePath, $nestedOutput, 'PassNested123');

        $this->assertTrue(file_exists($nestedOutput));
        $this->assertGreaterThan(0, $res['output_size']);
    }

    public function test_protection_fails_when_source_and_output_are_same(): void
    {
        $sourcePath = $this->createPdfFixture('same.pdf');
        $service = app(PdfProtectionService::class);

        $this->expectException(InvalidArgumentException::class);
        $service->protect($sourcePath, $sourcePath, 'Pass123');
    }

    public function test_protect_pdf_livewire_component_e2e_without_persisting_password(): void
    {
        $fixturePath = $this->createPdfFixture('upload_protect.pdf');
        $uploaded = UploadedFile::fake()->createWithContent('upload_protect.pdf', file_get_contents($fixturePath));

        $component = Livewire::test(ProtectPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('password', 'SecurePass987')
            ->set('password_confirmation', 'SecurePass987')
            ->set('allowPrinting', true)
            ->set('allowCopying', false)
            ->call('process');

        $component->assertSet('jobStatus', 'completed');
        $component->assertSet('password', '');
        $component->assertSet('password_confirmation', '');

        $fileJobId = $component->get('fileJobId');
        $this->assertNotNull($fileJobId);

        $job = FileJob::findOrFail($fileJobId);
        $this->assertEquals(JobStatus::COMPLETED, $job->status);
        $this->assertEquals(ToolType::PROTECT_PDF, $job->tool_type);

        // Crucial security check: Password must NEVER appear in DB
        $jobJson = json_encode($job->toArray());
        $this->assertStringNotContainsString('SecurePass987', $jobJson);

        // Entire database check for the password
        $rawOccurrences = DB::table('file_jobs')
            ->where('input_metadata', 'LIKE', '%SecurePass987%')
            ->count();
        $this->assertEquals(0, $rawOccurrences);
    }

    public function test_unlock_pdf_livewire_component_rejects_wrong_password(): void
    {
        $plainPath = $this->createPdfFixture('plain.pdf');
        $encryptedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'for_unlock_fail.pdf';

        app(PdfProtectionService::class)->protect(
            sourcePath: $plainPath,
            outputPath: $encryptedPath,
            userPassword: 'CorrectPassword111'
        );

        $uploaded = UploadedFile::fake()->createWithContent('for_unlock_fail.pdf', file_get_contents($encryptedPath));

        $component = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('password', 'WrongPassword000')
            ->call('process');

        $component->assertHasErrors(['password']);
        $component->assertSet('jobStatus', 'uploaded');
        $component->assertSet('password', '');
        $this->assertNull($component->get('fileJobId'));
    }

    public function test_unlock_pdf_livewire_component_rejects_unencrypted_pdf(): void
    {
        $plainPath = $this->createPdfFixture('plain.pdf');
        $uploaded = UploadedFile::fake()->createWithContent('plain.pdf', file_get_contents($plainPath));

        $component = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('password', 'AnyPassword123')
            ->call('process');

        $component->assertHasErrors(['upload']);
        $component->assertSet('jobStatus', 'uploaded');
    }

    public function test_unlock_pdf_livewire_component_succeeds_with_correct_password(): void
    {
        $plainPath = $this->createPdfFixture('plain_secret.pdf');
        $encryptedPath = $this->tempDir . DIRECTORY_SEPARATOR . 'for_unlock_success.pdf';

        app(PdfProtectionService::class)->protect(
            sourcePath: $plainPath,
            outputPath: $encryptedPath,
            userPassword: 'OpenSesame999'
        );

        $uploaded = UploadedFile::fake()->createWithContent('for_unlock_success.pdf', file_get_contents($encryptedPath));

        $component = Livewire::test(UnlockPdfComponent::class)
            ->set('upload', $uploaded)
            ->set('password', 'OpenSesame999')
            ->call('process');

        $component->assertSet('jobStatus', 'completed');
        $component->assertSet('password', '');

        $fileJobId = $component->get('fileJobId');
        $this->assertNotNull($fileJobId);

        $job = FileJob::findOrFail($fileJobId);
        $this->assertEquals(JobStatus::COMPLETED, $job->status);
        $this->assertEquals(ToolType::UNLOCK_PDF, $job->tool_type);

        // Verify password never persisted
        $jobJson = json_encode($job->toArray());
        $this->assertStringNotContainsString('OpenSesame999', $jobJson);
    }
}
