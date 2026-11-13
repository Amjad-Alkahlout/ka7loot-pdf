<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ToolUsage;
use App\Services\File\FileStorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanupExpiredCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cleanup_deletes_expired_job_and_updates_status(): void
    {
        $storage = new FileStorageManager();
        $expiredJobId = Str::uuid()->toString();

        // Create directory and dummy file
        $storage->putFileContents($expiredJobId, 'test.pdf', 'dummy content');

        // Create expired DB record
        $job = FileJob::create([
            'id' => $expiredJobId,
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::COMPLETED,
            'download_token' => Str::random(48),
            'expires_at' => now()->subMinutes(10), // already expired
        ]);

        $this->artisan('app:cleanup-expired')
            ->assertSuccessful();

        $job->refresh();
        $this->assertEquals(JobStatus::EXPIRED, $job->status);
        $this->assertDirectoryDoesNotExist($storage->getRootPath() . DIRECTORY_SEPARATOR . $expiredJobId);
    }

    public function test_cleanup_purges_old_usage_analytics(): void
    {
        // Fresh usage
        ToolUsage::create([
            'tool_type' => 'merge-pdf',
            'ip_hash' => 'hash_recent',
            'is_successful' => true,
            'bytes_processed' => 1024,
            'duration_ms' => 200,
            'created_at' => now()->subDays(5),
        ]);

        // Old usage beyond 90 days
        ToolUsage::create([
            'tool_type' => 'merge-pdf',
            'ip_hash' => 'hash_old',
            'is_successful' => true,
            'bytes_processed' => 1024,
            'duration_ms' => 200,
            'created_at' => now()->subDays(100),
        ]);

        $this->artisan('app:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('tool_usage', ['ip_hash' => 'hash_recent']);
        $this->assertDatabaseMissing('tool_usage', ['ip_hash' => 'hash_old']);
    }

    public function test_cleanup_preserves_shared_subprocess_temp_directory(): void
    {
        $storage = new FileStorageManager();
        $tempDirectory = $storage->getSubprocessTempDirectory();
        $activeTempFile = $tempDirectory . DIRECTORY_SEPARATOR . 'active-process.tmp';
        file_put_contents($activeTempFile, 'in use');
        touch($tempDirectory, now()->subDays(2)->timestamp);

        $this->artisan('app:cleanup-expired')->assertSuccessful();

        $this->assertFileExists($activeTempFile);
    }

    public function test_cleanup_preserves_old_directory_for_a_known_active_job(): void
    {
        $storage = new FileStorageManager();
        $activeJobId = Str::uuid()->toString();
        $storage->putFileContents($activeJobId, 'active.tmp', 'in use');
        $jobDirectory = $storage->getRootPath() . DIRECTORY_SEPARATOR . $activeJobId;
        touch($jobDirectory, now()->subDays(2)->timestamp);

        FileJob::create([
            'id' => $activeJobId,
            'tool_type' => ToolType::MERGE_PDF,
            'status' => JobStatus::PROCESSING,
            'expires_at' => now()->addDay(),
        ]);

        $this->artisan('app:cleanup-expired')->assertSuccessful();

        $this->assertFileExists($jobDirectory . DIRECTORY_SEPARATOR . 'active.tmp');
    }

    public function test_cleanup_preserves_expired_pending_and_processing_job_files(): void
    {
        $storage = new FileStorageManager();

        foreach ([JobStatus::PENDING, JobStatus::PROCESSING] as $status) {
            $jobId = Str::uuid()->toString();
            $storage->putFileContents($jobId, 'active.tmp', 'still in use');

            FileJob::create([
                'id' => $jobId,
                'tool_type' => ToolType::MERGE_PDF,
                'status' => $status,
                'expires_at' => now()->subMinutes(10),
            ]);
        }

        $this->artisan('app:cleanup-expired')->assertSuccessful();

        foreach (FileJob::whereIn('status', [JobStatus::PENDING, JobStatus::PROCESSING])->get() as $job) {
            $this->assertFileExists($storage->getRootPath() . DIRECTORY_SEPARATOR . $job->id . DIRECTORY_SEPARATOR . 'active.tmp');
            $this->assertSame($job->status, $job->fresh()->status);
        }
    }
}
