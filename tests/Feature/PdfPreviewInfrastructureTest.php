<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\User;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfThumbnailService;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class PdfPreviewInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected string $tempDir;
    protected PdfThumbnailService $thumbnailService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thumbnailService = app(PdfThumbnailService::class);
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_prev_test_' . uniqid('', true);
        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
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
        if (! is_dir($dir)) {
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

    protected function createPdfFixture(string $filename = 'test.pdf', int $pages = 2, string $customContent = ''): string
    {
        $path = $this->tempDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF('P', 'mm', 'A4');
        for ($i = 1; $i <= $pages; $i++) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', 'B', 16);
            $text = $customContent ? "{$customContent} (Page {$i})" : "Page {$i} Content";
            $pdf->Cell(40, 10, $text);
        }
        $pdf->Output('F', $path);
        return $path;
    }

    public function test_unsigned_preview_request_is_forbidden_with_403(): void
    {
        $response = $this->get('/tools/pdf-preview?filename=sample.pdf&page=1');
        $response->assertStatus(403);
    }

    public function test_tampered_signature_is_forbidden_with_403(): void
    {
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => 'sample.pdf', 'page' => 1, 'session_id' => 'abc']
        );

        $tamperedUrl = $url . '&tamper=1';
        $response = $this->get($tamperedUrl);
        $response->assertStatus(403);
    }

    public function test_expired_signed_url_is_forbidden_with_403(): void
    {
        $expiredUrl = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->subMinutes(10),
            ['filename' => 'sample.pdf', 'page' => 1, 'session_id' => 'abc']
        );

        $response = $this->get($expiredUrl);
        $response->assertStatus(403);
    }

    public function test_missing_session_id_for_temporary_file_is_forbidden_with_403(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $filename = 'no_sess_' . uniqid() . '.pdf';
        $fixturePath = $this->createPdfFixture('no_sess.pdf', 1);
        $disk->put($livewireDir . '/' . $filename, file_get_contents($fixturePath));

        // Signed URL created WITHOUT session_id
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => $filename, 'page' => 1]
        );

        $response = $this->get($url);
        $response->assertStatus(403);

        $disk->delete($livewireDir . '/' . $filename);
    }

    public function test_session_mismatch_is_forbidden_with_403(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $filename = 'sess_test_' . uniqid() . '.pdf';
        $fixturePath = $this->createPdfFixture('sess.pdf', 1);
        $disk->put($livewireDir . '/' . $filename, file_get_contents($fixturePath));

        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            [
                'filename' => $filename,
                'page' => 1,
                'session_id' => 'uploader_session_123',
            ]
        );

        // Different session accesses the signed URL
        $response = $this->withSession(['preview_session_id' => 'different_session_456'])
            ->get($url);

        $response->assertStatus(403);

        $disk->delete($livewireDir . '/' . $filename);
    }

    public function test_path_traversal_is_rejected_with_400(): void
    {
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => '../../etc/passwd', 'session_id' => 'sess']
        );

        $response = $this->get($url);
        $response->assertStatus(400);
    }

    public function test_invalid_filename_characters_rejected_with_400(): void
    {
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => 'file<script>.pdf', 'session_id' => 'sess']
        );

        $response = $this->get($url);
        $response->assertStatus(400);
    }

    public function test_missing_pdf_file_returns_404(): void
    {
        $sessionId = 'valid_session';
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => 'non_existent_file_12345.pdf', 'page' => 1, 'session_id' => $sessionId]
        );

        $response = $this->withSession(['preview_session_id' => $sessionId])
            ->get($url);
        $response->assertStatus(404);
    }

    public function test_non_pdf_file_returns_400(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $filename = 'plain_text_' . uniqid() . '.txt';
        $disk->put($livewireDir . '/' . $filename, 'This is plain text, not a PDF.');

        $sessionId = 'valid_session';
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => $filename, 'page' => 1, 'session_id' => $sessionId]
        );

        $response = $this->withSession(['preview_session_id' => $sessionId])
            ->get($url);
        $response->assertStatus(400);

        $disk->delete($livewireDir . '/' . $filename);
    }

    public function test_file_with_pdf_extension_but_invalid_magic_header_returns_400(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        // Fake PDF with .pdf extension but invalid header
        $filename = 'fake_spoofed_' . uniqid() . '.pdf';
        $disk->put($livewireDir . '/' . $filename, 'NOT_A_REAL_PDF_DATA_HERE_123456');

        $sessionId = 'valid_session';
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => $filename, 'page' => 1, 'session_id' => $sessionId]
        );

        $response = $this->withSession(['preview_session_id' => $sessionId])
            ->get($url);
        $response->assertStatus(400);

        $disk->delete($livewireDir . '/' . $filename);
    }

    public function test_page_out_of_bounds_returns_404(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $filename = 'two_pages_' . uniqid() . '.pdf';
        $fixturePath = $this->createPdfFixture('two_pages.pdf', 2);
        $disk->put($livewireDir . '/' . $filename, file_get_contents($fixturePath));

        $sessionId = 'valid_session';
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            ['filename' => $filename, 'page' => 99, 'session_id' => $sessionId]
        );

        $response = $this->withSession(['preview_session_id' => $sessionId])
            ->get($url);
        $response->assertStatus(404);

        $disk->delete($livewireDir . '/' . $filename);
    }

    public function test_valid_signed_pdf_preview_generates_and_serves_jpeg(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $filename = 'valid_test_' . uniqid() . '.pdf';
        $fixturePath = $this->createPdfFixture('valid_test.pdf', 3);
        $disk->put($livewireDir . '/' . $filename, file_get_contents($fixturePath));

        $sessionId = 'valid_sess_' . uniqid();
        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            [
                'filename' => $filename,
                'page' => 2,
                'dpi' => 150,
                'session_id' => $sessionId,
            ]
        );

        $response = $this->withSession(['preview_session_id' => $sessionId])
            ->get($url);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/jpeg');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        // Verify valid JPEG image output
        $content = $response->streamedContent();
        $this->assertNotEmpty($content);
        $imageInfo = getimagesizefromstring($content);
        $this->assertNotFalse($imageInfo, 'Response should contain valid JPEG image dimensions');
        $this->assertEquals(IMAGETYPE_JPEG, $imageInfo[2]);

        // Verify repeat request hits disk cache directly
        $repeatResponse = $this->withSession(['preview_session_id' => $sessionId])
            ->get($url);
        $repeatResponse->assertStatus(200);
        $this->assertEquals($content, $repeatResponse->streamedContent());

        $disk->delete($livewireDir . '/' . $filename);
    }

    public function test_cache_invalidation_when_source_content_changes(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $filename = 'mutate_' . uniqid() . '.pdf';
        $initialPdf = $this->createPdfFixture('initial.pdf', 1, 'INITIAL VERSION');
        $disk->put($livewireDir . '/' . $filename, file_get_contents($initialPdf));

        $sourceDiskPath = $disk->path($livewireDir . '/' . $filename);
        $initialCacheFile = $this->thumbnailService->getCachedPreviewPath($sourceDiskPath, 1, 150, 85);

        // Render initial preview
        $this->thumbnailService->renderPageToFile($sourceDiskPath, $initialCacheFile, 1, 150, 85);
        $this->assertFileExists($initialCacheFile);

        // Sleep 1 second and rewrite source with updated content
        sleep(1);
        $updatedPdf = $this->createPdfFixture('updated.pdf', 1, 'MODIFIED DIFFERENT CONTENT VERSION');
        $disk->put($livewireDir . '/' . $filename, file_get_contents($updatedPdf));
        clearstatcache(true, $sourceDiskPath);

        $updatedCacheFile = $this->thumbnailService->getCachedPreviewPath($sourceDiskPath, 1, 150, 85);

        $this->assertNotEquals(
            $initialCacheFile,
            $updatedCacheFile,
            'Modified source file must produce a distinct cache path reflecting changed content'
        );

        $disk->delete($livewireDir . '/' . $filename);
        $this->thumbnailService->deleteThumbnailsForPdf($sourceDiskPath);
    }

    public function test_cache_invalidation_detects_middle_byte_modification_with_identical_size(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $filename = 'mid_mutate_' . uniqid() . '.pdf';
        $pdfPath = $this->createPdfFixture('mid_test.pdf', 2, 'STABLE LENGTH CONTENT PAYLOAD 12345');
        $raw = file_get_contents($pdfPath);
        $disk->put($livewireDir . '/' . $filename, $raw);

        $sourceDiskPath = $disk->path($livewireDir . '/' . $filename);
        $initialCacheFile = $this->thumbnailService->getCachedPreviewPath($sourceDiskPath, 1, 150, 85);

        // Mutate a byte strictly in the middle of the file payload without changing byte length
        $fileLen = filesize($sourceDiskPath);
        $midOffset = (int) floor($fileLen / 2);
        $mutated = file_get_contents($sourceDiskPath);
        $this->assertSame($fileLen, strlen($mutated));

        // Flip byte at offset
        $mutated[$midOffset] = ($mutated[$midOffset] === 'X') ? 'Y' : 'X';
        file_put_contents($sourceDiskPath, $mutated);
        clearstatcache(true, $sourceDiskPath);

        $this->assertSame($fileLen, filesize($sourceDiskPath), 'File size must remain strictly identical');

        $updatedCacheFile = $this->thumbnailService->getCachedPreviewPath($sourceDiskPath, 1, 150, 85);

        $this->assertNotEquals(
            $initialCacheFile,
            $updatedCacheFile,
            'Full SHA-256 cache identity must detect middle-byte modification even when file size is identical'
        );

        $disk->delete($livewireDir . '/' . $filename);
        $this->thumbnailService->deleteThumbnailsForPdf($sourceDiskPath);
    }

    public function test_cache_cleanup_for_multiple_documents_is_strictly_isolated(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $fileA = 'doc_a_' . uniqid() . '.pdf';
        $fileB = 'doc_b_' . uniqid() . '.pdf';

        $fixtureA = $this->createPdfFixture('fixture_a.pdf', 1, 'DOC A');
        $fixtureB = $this->createPdfFixture('fixture_b.pdf', 1, 'DOC B');

        $disk->put($livewireDir . '/' . $fileA, file_get_contents($fixtureA));
        $disk->put($livewireDir . '/' . $fileB, file_get_contents($fixtureB));

        $pathA = $disk->path($livewireDir . '/' . $fileA);
        $pathB = $disk->path($livewireDir . '/' . $fileB);

        $cacheDirA = $this->thumbnailService->getPreviewCacheDirectory($pathA);
        $cacheDirB = $this->thumbnailService->getPreviewCacheDirectory($pathB);

        $this->assertNotEquals($cacheDirA, $cacheDirB, 'Distinct documents must have separate cache directories');

        @mkdir($cacheDirA, 0755, true);
        @mkdir($cacheDirB, 0755, true);

        $cacheFileA = $this->thumbnailService->getCachedPreviewPath($pathA, 1, 150, 85);
        $cacheFileB = $this->thumbnailService->getCachedPreviewPath($pathB, 1, 150, 85);

        $this->thumbnailService->renderPageToFile($pathA, $cacheFileA, 1, 150, 85);
        $this->thumbnailService->renderPageToFile($pathB, $cacheFileB, 1, 150, 85);

        $this->assertFileExists($cacheFileA);
        $this->assertFileExists($cacheFileB);

        // Delete cache for Document A
        $this->thumbnailService->deleteThumbnailsForPdf($pathA);

        $this->assertDirectoryDoesNotExist($cacheDirA, 'Cache dir A should be deleted');
        $this->assertDirectoryExists($cacheDirB, 'Cache dir B must remain untouched');
        $this->assertFileExists($cacheFileB, 'Cache file B must remain intact');

        // Cleanup Document B
        $this->thumbnailService->deleteThumbnailsForPdf($pathB);
        $disk->delete($livewireDir . '/' . $fileA);
        $disk->delete($livewireDir . '/' . $fileB);
    }

    public function test_guest_job_requires_matching_session(): void
    {
        $storage = app(FileStorageManager::class);
        $job = FileJob::create([
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::COMPLETED,
            'user_id' => null, // Guest job
        ]);

        $jobDir = $storage->getJobDirectory($job->id);
        $filename = 'result.pdf';
        $fixture = $this->createPdfFixture('guest_job.pdf', 1, 'GUEST RESULT');
        file_put_contents($jobDir . DIRECTORY_SEPARATOR . $filename, file_get_contents($fixture));

        $creatorSession = 'guest_creator_session_1';
        $otherSession = 'guest_intruder_session_2';

        // Signed URL with creator session
        $validUrl = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            [
                'filename' => $filename,
                'job_id' => $job->id,
                'page' => 1,
                'session_id' => $creatorSession,
            ]
        );

        // 1. Authorized creator session succeeds
        $this->withSession(['preview_session_id' => $creatorSession])
            ->get($validUrl)
            ->assertStatus(200);

        // 2. Mismatched session fails with 403
        $this->withSession(['preview_session_id' => $otherSession])
            ->get($validUrl)
            ->assertStatus(403);

        $storage->deleteJobDirectory($job->id);
    }

    public function test_authenticated_job_rejects_unauthorized_user_with_403(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $storage = app(FileStorageManager::class);
        $job = FileJob::create([
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::COMPLETED,
            'user_id' => $owner->id,
        ]);

        $jobDir = $storage->getJobDirectory($job->id);
        $filename = 'auth_result.pdf';
        $fixture = $this->createPdfFixture('auth_job.pdf', 1, 'AUTH OWNER RESULT');
        file_put_contents($jobDir . DIRECTORY_SEPARATOR . $filename, file_get_contents($fixture));

        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            [
                'filename' => $filename,
                'job_id' => $job->id,
                'page' => 1,
            ]
        );

        // 1. Unauthenticated guest access fails with 403
        $this->get($url)->assertStatus(403);

        // 2. Another authenticated user fails with 403
        $this->actingAs($attacker)->get($url)->assertStatus(403);

        // 3. True owner succeeds with 200
        $this->actingAs($owner)->get($url)->assertStatus(200);

        $storage->deleteJobDirectory($job->id);
    }

    public function test_job_file_resolution_does_not_fall_back_to_temporary_directory(): void
    {
        $disk = Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        $owner = User::factory()->create();
        $storage = app(FileStorageManager::class);
        $job = FileJob::create([
            'tool_type' => ToolType::SPLIT_PDF,
            'status' => JobStatus::COMPLETED,
            'user_id' => $owner->id,
        ]);

        $filename = 'isolated_doc.pdf';
        $fixture = $this->createPdfFixture('isolated.pdf', 1);

        // Put file in temporary upload directory, NOT in the job directory
        $disk->put($livewireDir . '/' . $filename, file_get_contents($fixture));

        $url = URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            [
                'filename' => $filename,
                'job_id' => $job->id,
                'page' => 1,
            ]
        );

        // Requesting job preview must NOT fall back to livewire-tmp; must return 404
        $this->actingAs($owner)->get($url)->assertStatus(404);

        $disk->delete($livewireDir . '/' . $filename);
        $storage->deleteJobDirectory($job->id);
    }
}
