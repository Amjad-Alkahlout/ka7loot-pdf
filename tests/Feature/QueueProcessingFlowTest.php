<?php

namespace Tests\Feature;

use App\Actions\File\DispatchFileJobAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Jobs\ProcessFileJob;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueProcessingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected FileStorageManager $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FileStorageManager();
    }

    protected function createTestPdf(string $jobId, string $filename): string
    {
        $jobDir = $this->storage->getJobDirectory($jobId);
        $path = $jobDir . DIRECTORY_SEPARATOR . $filename;
        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(40, 10, 'Queue Flow Test PDF');
        $pdf->Output($path, 'F');
        return $path;
    }

    public function test_queue_configuration_matches_default_worker_queue(): void
    {
        $fpDefaultQueue = config('fileprocessor.queues.default_queue');
        $databaseDefaultQueue = config('queue.connections.database.queue');

        $this->assertEquals('default', $fpDefaultQueue);
        $this->assertEquals('default', $databaseDefaultQueue);
        $this->assertEquals($databaseDefaultQueue, $fpDefaultQueue);
    }

    public function test_dispatch_file_job_pushes_to_database_queue_consumed_by_standard_worker(): void
    {
        config(['queue.default' => 'database']);

        $jobId = Str::uuid()->toString();
        $filename = 'test_compress.pdf';
        $this->createTestPdf($jobId, $filename);

        $fileJob = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => ToolType::COMPRESS_PDF,
            'status'         => JobStatus::PENDING,
            'download_token' => Str::random(48),
            'input_metadata' => [
                'file'              => $filename,
                'clean_name'        => 'test_compress',
                'compression_level' => 'recommended',
                'ip'                => '127.0.0.1',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        $dispatcher = app(DispatchFileJobAction::class);
        $isSync = $dispatcher->execute($fileJob, 20480); // 20 MB > threshold -> queued

        $this->assertFalse($isSync, 'Heavy tool should have been queued');

        // Verify the job row in database jobs table has queue = default
        $queuedJob = DB::table('jobs')->where('queue', config('queue.connections.database.queue'))->first();
        $this->assertNotNull($queuedJob, 'Job was not pushed to the default database queue');

        // Run artisan queue:work --once to simulate the real worker consuming the queue
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        // Verify job transitioned to completed and output file was generated
        $fileJob->refresh();
        $this->assertEquals(JobStatus::COMPLETED, $fileJob->status);
        $this->assertEquals(1, ProcessedFile::where('file_job_id', $jobId)->count());

        $this->storage->deleteJobDirectory($jobId);
    }
}
