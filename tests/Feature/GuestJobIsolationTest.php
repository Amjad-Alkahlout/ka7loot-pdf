<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Livewire\Tools\MergePdfComponent;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use App\Services\Share\TemporaryShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestJobIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_job_metadata_binds_the_current_session_and_hashes_ip(): void
    {
        $ip = '203.0.113.42';
        $job = $this->createGuestJob(['ip' => $ip]);
        $metadata = $job->fresh()->input_metadata;

        $this->assertSame(FileJob::currentGuestSessionKey(), $metadata['guest_session_key']);
        $this->assertArrayNotHasKey('ip', $metadata);
        $this->assertSame(hash('sha256', $ip . config('app.key')), $metadata['ip_hash']);
    }

    public function test_guest_livewire_job_access_and_deletion_are_session_scoped(): void
    {
        $job = $this->createGuestJob(['guest_session_key' => 'another-guest-session']);
        $file = $this->addProcessedFile($job);
        $component = new MergePdfComponent;
        $component->fileJobId = $job->id;
        $component->jobStatus = 'completed';

        $this->assertNull($component->getFileJobProperty());
        $this->assertCount(0, $component->getProcessedFilesProperty());

        $component->deleteNow(app(FileStorageManager::class));

        $this->assertSame(JobStatus::COMPLETED, $job->fresh()->status);
        $this->assertDatabaseHas('processed_files', ['id' => $file->id]);
    }

    public function test_guest_processed_files_require_a_completed_owned_job(): void
    {
        $job = $this->createGuestJob([], JobStatus::FAILED);
        $this->addProcessedFile($job);
        $component = new MergePdfComponent;
        $component->fileJobId = $job->id;
        $component->jobStatus = 'completed'; // Client state is not authoritative.

        $this->assertCount(0, $component->getProcessedFilesProperty());
    }

    public function test_component_cannot_revoke_a_share_from_another_guest_job(): void
    {
        $ownedJob = $this->createGuestJob();
        $foreignJob = $this->createGuestJob(['guest_session_key' => 'another-guest-session']);
        $file = $this->addProcessedFile($foreignJob);
        $share = app(TemporaryShareService::class)->createShare($foreignJob, $file)['share'];

        $component = new MergePdfComponent;
        $component->fileJobId = $ownedJob->id;
        $component->activeShareId = $share->id;
        $component->revokeActiveShare(app(TemporaryShareService::class));

        $this->assertNull($share->fresh()->revoked_at);
    }

    private function createGuestJob(array $metadata = [], JobStatus $status = JobStatus::COMPLETED): FileJob
    {
        return FileJob::create([
            'tool_type' => ToolType::MERGE_PDF,
            'status' => $status,
            'user_id' => null,
            'input_metadata' => $metadata,
            'expires_at' => now()->addHour(),
        ]);
    }

    private function addProcessedFile(FileJob $job): ProcessedFile
    {
        return $job->processedFiles()->create([
            'original_name' => 'guest-result.pdf',
            'stored_filename' => 'guest-result.pdf',
            'mime_type' => 'application/pdf',
            'original_size_bytes' => 128,
            'processed_size_bytes' => 96,
        ]);
    }
}
