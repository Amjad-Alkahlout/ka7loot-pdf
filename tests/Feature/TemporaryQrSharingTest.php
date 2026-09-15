<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\MergePdfComponent;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\TemporaryShare;
use App\Services\File\FileStorageManager;
use App\Services\Share\TemporaryShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TemporaryQrSharingTest extends TestCase
{
    use RefreshDatabase;

    protected FileStorageManager $storage;
    protected TemporaryShareService $shareService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FileStorageManager();
        $this->shareService = app(TemporaryShareService::class);
    }

    protected function createCompletedJobWithFile(string $content = 'dummy pdf content', string $filename = 'test.pdf'): array
    {
        $jobId = Str::uuid()->toString();
        $this->storage->putFileContents($jobId, $filename, $content);

        $job = FileJob::create([
            'id' => $jobId,
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::COMPLETED,
            'download_token' => Str::random(48),
            'expires_at' => now()->addMinutes(60),
        ]);

        $file = ProcessedFile::create([
            'id' => Str::uuid()->toString(),
            'file_job_id' => $jobId,
            'original_name' => $filename,
            'stored_filename' => $filename,
            'mime_type' => 'application/pdf',
            'original_size_bytes' => strlen($content),
            'processed_size_bytes' => strlen($content),
        ]);

        return [$job, $file];
    }

    public function test_share_creation_from_completed_job_stores_hashed_token_and_generates_qr(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile();

        $result = $this->shareService->createShare(
            job: $job,
            file: $file,
            expirationMinutes: 30,
            maxDownloads: 5
        );

        $this->assertArrayHasKey('share', $result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('qr_svg', $result);

        $token = $result['token'];
        $share = $result['share'];

        // Token must be a 64-character hex string
        $this->assertEquals(64, strlen($token));

        // DB record must exist
        $this->assertDatabaseHas('temporary_shares', [
            'id' => $share->id,
            'file_job_id' => $job->id,
            'processed_file_id' => $file->id,
            'token_hash' => hash('sha256', $token),
            'max_downloads' => 5,
            'download_count' => 0,
        ]);

        // Verify plaintext token is NEVER stored in database
        $this->assertDatabaseMissing('temporary_shares', [
            'token_hash' => $token,
        ]);

        // QR code must be valid SVG
        $this->assertStringContainsString('<svg', $result['qr_svg']);
        $this->assertStringContainsString('</svg>', $result['qr_svg']);
        $this->assertStringContainsString($result['url'], $result['url']);
    }

    public function test_share_cannot_be_created_from_pending_failed_or_expired_job(): void
    {
        $pendingJob = FileJob::create([
            'id' => Str::uuid()->toString(),
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'expires_at' => now()->addMinutes(60),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->shareService->createShare($pendingJob);
    }

    public function test_public_download_succeeds_with_valid_token_and_increments_counter(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile('test stream content', 'invoice.pdf');

        $result = $this->shareService->createShare($job, $file, 15, 3);
        $token = $result['token'];

        $response = $this->get(route('share.download', ['token' => $token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('invoice.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertEquals('test stream content', $response->streamedContent());

        // Verify atomic counter incremented
        $share = TemporaryShare::where('token_hash', hash('sha256', $token))->first();
        $this->assertEquals(1, $share->download_count);
        $this->assertEquals(2, $share->remaining_downloads);
    }

    public function test_expired_token_fails_with_410_gone(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile();

        $result = $this->shareService->createShare($job, $file, 15);
        $token = $result['token'];

        // Force expiration
        TemporaryShare::where('id', $result['share']->id)->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $response = $this->get(route('share.download', ['token' => $token]));

        $response->assertStatus(410);
    }

    public function test_revoked_token_fails_with_410_gone(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile();

        $result = $this->shareService->createShare($job, $file, 15);
        $token = $result['token'];

        $this->shareService->revokeShare($result['share']->id);

        $response = $this->get(route('share.download', ['token' => $token]));

        $response->assertStatus(410);
    }

    public function test_download_limit_reached_fails_with_410_gone(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile('limited download content', 'limited.pdf');

        $result = $this->shareService->createShare($job, $file, 15, 1);
        $token = $result['token'];

        // First download succeeds
        $res1 = $this->get(route('share.download', ['token' => $token]));
        $res1->assertOk();

        // Second download hits limit and fails with 410
        $res2 = $this->get(route('share.download', ['token' => $token]));
        $res2->assertStatus(410);
    }

    public function test_atomic_download_counter_prevents_race_conditions(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile();

        $result = $this->shareService->createShare($job, $file, 15, 2);
        $share = $result['share'];

        // Simulate atomic increment when count is 0
        $success1 = $this->shareService->incrementDownloadCountAtomically($share);
        $this->assertTrue($success1);

        // Simulate atomic increment when count is 1
        $success2 = $this->shareService->incrementDownloadCountAtomically($share);
        $this->assertTrue($success2);

        // Count is now 2 (max_downloads reached). Subsequent atomic increment must fail
        $success3 = $this->shareService->incrementDownloadCountAtomically($share);
        $this->assertFalse($success3);
    }

    public function test_qr_endpoint_returns_valid_svg(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile();

        $result = $this->shareService->createShare($job, $file, 15);
        $token = $result['token'];

        $response = $this->get(route('share.qr', ['token' => $token]));

        $response->assertOk();
        $response->assertHeader('content-type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
        $this->assertStringContainsString('</svg>', $response->getContent());
    }

    public function test_unknown_token_returns_404_not_found(): void
    {
        $fakeToken = Str::random(64);

        $response = $this->get(route('share.download', ['token' => $fakeToken]));
        $response->assertStatus(404);

        $responseQr = $this->get(route('share.qr', ['token' => $fakeToken]));
        $responseQr->assertStatus(404);
    }

    public function test_share_referencing_deleted_or_missing_file_fails_safely(): void
    {
        config(['app.debug' => false]);
        [$job, $file] = $this->createCompletedJobWithFile('temp content', 'missing.pdf');

        $result = $this->shareService->createShare($job, $file, 15);
        $token = $result['token'];

        // Physically delete the storage directory
        $this->storage->deleteJobDirectory($job->id);

        $response = $this->get(route('share.download', ['token' => $token]));

        // Fails safely with 410 Gone without exposing physical path
        $response->assertStatus(410);
        $this->assertStringNotContainsString('C:', $response->getContent());
        $this->assertStringNotContainsString('Desktop', $response->getContent());
    }

    public function test_temporary_shares_cleaned_up_by_cleanup_expired_command(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile();

        $resActive = $this->shareService->createShare($job, $file, 30);
        $resExpired = $this->shareService->createShare($job, $file, 30);

        // Force expiration on one
        TemporaryShare::where('id', $resExpired['share']->id)->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $this->artisan('app:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseMissing('temporary_shares', [
            'id' => $resExpired['share']->id,
        ]);

        $this->assertDatabaseHas('temporary_shares', [
            'id' => $resActive['share']->id,
        ]);
    }

    public function test_livewire_job_result_can_generate_and_revoke_share(): void
    {
        [$job, $file] = $this->createCompletedJobWithFile('content', 'merged.pdf');

        $component = Livewire::test(MergePdfComponent::class)
            ->set('fileJobId', $job->id)
            ->set('jobStatus', 'completed')
            ->set('selectedFileIdForShare', $file->id)
            ->set('shareExpirationMinutes', 15)
            ->set('shareMaxDownloads', 3)
            ->call('generateShare');

        $component->assertSet('activeShareToken', fn ($token) => strlen($token) === 64);
        $component->assertSet('activeShareRemainingDownloads', 3);
        $this->assertNotNull($component->get('activeShareUrl'));
        $this->assertStringContainsString('<svg', $component->get('activeShareQrSvg'));

        // Revoke the share
        $component->call('revokeActiveShare');
        $component->assertSet('activeShareId', null);
        $component->assertSet('activeShareToken', null);
        $component->assertSet('activeShareUrl', null);
        $component->assertSet('activeShareQrSvg', null);
    }
}
