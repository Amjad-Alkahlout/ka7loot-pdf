<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Jobs\ProcessFileJob;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\ToolUsage;
use App\Services\File\FileStorageManager;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProcessFileJobIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected FileStorageManager $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FileStorageManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function createTestPdf(string $jobId, string $filename): string
    {
        $jobDir = $this->storage->getJobDirectory($jobId);
        $path = $jobDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(40, 10, 'Idempotency Test PDF');
        $pdf->Output($path, 'F');
        return $path;
    }

    public function test_retried_job_is_idempotent_with_no_duplicate_records_or_telemetry(): void
    {
        $jobId = Str::uuid()->toString();
        $doc1 = 'input1.pdf';
        $doc2 = 'input2.pdf';

        $this->createTestPdf($jobId, $doc1);
        $this->createTestPdf($jobId, $doc2);

        $fileJob = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::MERGE_PDF,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'files'           => [$doc1, $doc2],
                'output_filename' => 'merged_result.pdf',
                'ip'              => '192.168.1.10',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        // First Execution
        $jobInstance = new ProcessFileJob($jobId);
        app()->call([$jobInstance, 'handle']);

        $fileJob->refresh();
        $this->assertEquals(JobStatus::COMPLETED, $fileJob->status);
        $this->assertEquals(1, ProcessedFile::where('file_job_id', $jobId)->count());
        $this->assertEquals(1, ToolUsage::where('file_job_id', $jobId)->count());

        // Second Execution (Simulated queue retry or re-delivery)
        $retryInstance = new ProcessFileJob($jobId);
        app()->call([$retryInstance, 'handle']);

        $fileJob->refresh();
        $this->assertEquals(JobStatus::COMPLETED, $fileJob->status);

        // Invariant: exactly 1 output record, exactly 1 telemetry entry
        $this->assertEquals(1, ProcessedFile::where('file_job_id', $jobId)->count(), 'Duplicate processed_files created!');
        $this->assertEquals(1, ToolUsage::where('file_job_id', $jobId)->count(), 'Duplicate tool_usage telemetry logged!');

        // Clean up
        $this->storage->deleteJobDirectory($jobId);
    }

    public function test_concurrent_worker_protection_prevents_duplicate_processing(): void
    {
        $jobId = Str::uuid()->toString();
        $this->createTestPdf($jobId, 'doc.pdf');

        $fileJob = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::COMPRESS_PDF,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'file'       => 'doc.pdf',
                'clean_name' => 'doc',
                'ip'         => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        // Simulate Worker 1 holding the atomic lock
        $lock = Cache::lock("process_file_job:{$jobId}", 60);
        $this->assertTrue($lock->get());

        // Worker 2 attempts to process concurrently
        $worker2 = new ProcessFileJob($jobId);
        app()->call([$worker2, 'handle']);

        $fileJob->refresh();
        // Worker 2 should have been blocked by the lock, leaving job unchanged
        $this->assertEquals(JobStatus::PENDING, $fileJob->status);
        $this->assertEquals(0, ProcessedFile::where('file_job_id', $jobId)->count());

        $lock->release();
        $this->storage->deleteJobDirectory($jobId);
    }

    public function test_successful_retry_after_transient_failure(): void
    {
        $jobId = Str::uuid()->toString();

        $fileJob = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::MERGE_PDF,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'files'           => ['missing1.pdf', 'missing2.pdf'], // Missing files to induce failure
                'output_filename' => 'merged.pdf',
                'ip'              => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        // Attempt 1: Fails due to missing input files
        $jobInstance = new ProcessFileJob($jobId);
        try {
            app()->call([$jobInstance, 'handle']);
            $this->fail('Expected exception was not thrown on attempt 1');
        } catch (\Throwable $e) {
            $fileJob->refresh();
            $this->assertEquals(JobStatus::FAILED, $fileJob->status);
            $this->assertNotNull($fileJob->error_message);
            $this->assertEquals(1, ToolUsage::where('file_job_id', $jobId)->where('is_successful', false)->count());
        }

        // Fix the issue: provide valid files and update metadata
        $this->createTestPdf($jobId, 'valid1.pdf');
        $this->createTestPdf($jobId, 'valid2.pdf');
        $fileJob->update([
            'input_metadata' => [
                'files'           => ['valid1.pdf', 'valid2.pdf'],
                'output_filename' => 'merged_fixed.pdf',
                'ip'              => '127.0.0.1',
            ],
        ]);

        // Attempt 2: Retried job succeeds
        $retryInstance = new ProcessFileJob($jobId);
        app()->call([$retryInstance, 'handle']);

        $fileJob->refresh();
        $this->assertEquals(JobStatus::COMPLETED, $fileJob->status);
        $this->assertNull($fileJob->error_message);

        // Verification: Exactly 1 processed file and exactly 1 telemetry entry
        $this->assertEquals(1, ProcessedFile::where('file_job_id', $jobId)->count());
        $this->assertEquals(1, ToolUsage::where('file_job_id', $jobId)->count());
        $this->assertTrue((bool) ToolUsage::where('file_job_id', $jobId)->value('is_successful'));

        $this->storage->deleteJobDirectory($jobId);
    }
}
