<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Jobs\ProcessFileJob;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ToolProcessingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected FileStorageManager $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FileStorageManager();
    }

    protected function createFakePdf(string $filename = 'sample.pdf'): UploadedFile
    {
        $content = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\nxref\n0 1\n0000000000 65535 f\ntrailer<</Size 1>>\nstartxref\n9\n%%EOF\n";
        return UploadedFile::fake()->createWithContent($filename, $content);
    }

    public function test_landing_page_renders_successfully(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200);
        $response->assertSee('DocuMorph');
    }

    public function test_locale_switcher_updates_session_and_renders_rtl(): void
    {
        // Switch to Arabic
        $res = $this->get('/lang/ar');
        $res->assertSessionHas('locale', 'ar');

        // Check homepage in Arabic
        $page = $this->withSession(['locale' => 'ar'])->get('/');
        $page->assertStatus(200);
        $page->assertSee('dir="rtl"', false);
        $page->assertSee('دوكيومورف');

        // Switch back to English
        $res2 = $this->get('/lang/en');
        $res2->assertSessionHas('locale', 'en');
        $page2 = $this->withSession(['locale' => 'en'])->get('/');
        $page2->assertSee('dir="ltr"', false);
        $page2->assertSee('DocuMorph');
    }

    public function test_sync_processing_and_secure_download_flow(): void
    {
        $jobId = Str::uuid()->toString();
        $token = Str::random(48);
        $jobDir = $this->storage->getJobDirectory($jobId);

        // Create dummy physical output
        file_put_contents($jobDir . DIRECTORY_SEPARATOR . 'output_merged.pdf', '%PDF-1.4 Mock Merged');

        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::MERGE_PDF,
            'status'         => JobStatus::COMPLETED,
            'download_token' => $token,
            'input_metadata' => ['files' => ['a.pdf', 'b.pdf']],
            'expires_at'     => now()->addHour(),
        ]);

        $file = ProcessedFile::create([
            'file_job_id'          => $job->id,
            'original_name'        => 'my_merged.pdf',
            'stored_filename'      => 'output_merged.pdf',
            'disk'                 => 'local',
            'mime_type'            => 'application/pdf',
            'original_size_bytes'  => 1024,
            'processed_size_bytes' => 512,
        ]);

        // Download with valid token
        $downloadRes = $this->get("/download/{$token}/{$file->id}");
        $downloadRes->assertStatus(200);
        $downloadRes->assertHeader('Content-Disposition', 'attachment; filename=my_merged.pdf');

        // Download with invalid token should 404
        $invalidRes = $this->get("/download/invalid_token_123/{$file->id}");
        $invalidRes->assertStatus(404);

        $this->storage->deleteJobDirectory($jobId);
    }

    public function test_download_rejection_after_job_expiration(): void
    {
        $jobId = Str::uuid()->toString();
        $token = Str::random(48);
        $jobDir = $this->storage->getJobDirectory($jobId);

        file_put_contents($jobDir . DIRECTORY_SEPARATOR . 'output.pdf', '%PDF-1.4 Expired Test');

        // Job expired 10 minutes ago
        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::COMPRESS_PDF,
            'status'         => JobStatus::COMPLETED,
            'download_token' => $token,
            'expires_at'     => now()->subMinutes(10),
        ]);

        $file = ProcessedFile::create([
            'file_job_id'          => $job->id,
            'original_name'        => 'doc.pdf',
            'stored_filename'      => 'output.pdf',
            'disk'                 => 'local',
            'mime_type'            => 'application/pdf',
            'original_size_bytes'  => 100,
            'processed_size_bytes' => 80,
        ]);

        $res = $this->get("/download/{$token}/{$file->id}");
        $res->assertStatus(410); // HTTP 410 Gone

        $this->storage->deleteJobDirectory($jobId);
    }

    public function test_delete_now_action_purges_files_and_marks_job_expired(): void
    {
        $jobId = Str::uuid()->toString();
        $token = Str::random(48);
        $jobDir = $this->storage->getJobDirectory($jobId);

        $filePath = $jobDir . DIRECTORY_SEPARATOR . 'output.pdf';
        file_put_contents($filePath, '%PDF-1.4 Test Deletion');
        $this->assertFileExists($filePath);

        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::COMPRESS_PDF,
            'status'         => JobStatus::COMPLETED,
            'download_token' => $token,
            'expires_at'     => now()->addHour(),
        ]);

        $res = $this->postJson("/job/{$token}/delete-now");
        $res->assertStatus(200);
        $res->assertJson(['success' => true]);

        $job->refresh();
        $this->assertEquals(JobStatus::EXPIRED, $job->status);
        $this->assertFileDoesNotExist($filePath);
    }

    public function test_async_queued_processing_flow(): void
    {
        Queue::fake();

        $jobId = Str::uuid()->toString();
        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::COMPRESS_PDF,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => ['file' => 'test.pdf'],
            'expires_at'     => now()->addHour(),
        ]);

        // Heavy tool or large file dispatches to queue
        ProcessFileJob::dispatch($job->id);

        Queue::assertPushed(ProcessFileJob::class, function ($queuedJob) use ($jobId) {
            return $queuedJob->fileJobId === $jobId;
        });
    }
}
