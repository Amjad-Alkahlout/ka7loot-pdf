<?php

namespace Tests\Feature;

use App\Enums\JobStatus;
use App\Livewire\Tools\MergePdfComponent;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use FPDF;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MergePdfPollingAndFlowTest extends TestCase
{
    use RefreshDatabase;

    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    protected function createValidPdf(string $filename, string $text = 'Sample'): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'test_valid_pdf_') . '.pdf';
        $this->tempFiles[] = $temp;

        $pdf = new FPDF();
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(40, 10, $text);
        $pdf->Output($temp, 'F');

        return UploadedFile::fake()->createWithContent($filename, file_get_contents($temp));
    }

    public function test_merge_pdf_processing_state_renders_valid_wire_poll_directive(): void
    {
        Livewire::test(MergePdfComponent::class)
            ->set('jobStatus', 'processing')
            ->set('statusMessage', 'Processing...')
            ->assertSeeHtml('wire:poll.2500ms="pollJobStatus"')
            ->assertDontSeeHtml('wire:poll.2500ms=\"pollJobStatus\"');
    }

    public function test_merge_pdf_polling_transitions_to_completed_and_renders_result(): void
    {
        // Setup mock completed job with processed file
        $jobId = Str::uuid()->toString();
        $token = Str::random(48);

        $job = FileJob::create([
            'id'             => $jobId,
            'tool_type'      => \App\Enums\ToolType::MERGE_PDF,
            'status'         => JobStatus::COMPLETED,
            'download_token' => $token,
            'input_metadata' => [
                'files'           => ['file1.pdf', 'file2.pdf'],
                'output_filename' => 'merged_document.pdf',
            ],
            'expires_at'     => now()->addHour(),
        ]);

        $processedFile = ProcessedFile::create([
            'id'                   => Str::uuid()->toString(),
            'file_job_id'          => $job->id,
            'original_name'        => 'merged_document.pdf',
            'stored_filename'      => 'output_merged.pdf',
            'disk'                 => 'local',
            'mime_type'            => 'application/pdf',
            'original_size_bytes'  => 50000,
            'processed_size_bytes' => 45000,
        ]);

        // Verify Livewire component polls, transitions, and renders the result view
        Livewire::test(MergePdfComponent::class)
            ->set('fileJobId', $jobId)
            ->set('jobStatus', 'processing')
            ->call('pollJobStatus')
            ->assertSet('jobStatus', 'completed')
            ->assertSee('merged_document.pdf')
            ->assertSee(route('file.download', ['token' => $token, 'fileId' => $processedFile->id]))
            ->assertSee(__('ui.delete_now'));
    }

    public function test_merge_pdf_end_to_end_queued_processing_and_poll(): void
    {
        // Force queued processing with database driver
        config(['queue.default' => 'database']);
        Config::set('fileprocessor.queues.sync_threshold_size_kb', -1);

        $file1 = $this->createValidPdf('part1.pdf', 'Part 1 Content');
        $file2 = $this->createValidPdf('part2.pdf', 'Part 2 Content');

        // 1. Submit via Livewire
        $test = Livewire::test(MergePdfComponent::class)
            ->set('uploads', [$file1, $file2])
            ->assertSet('jobStatus', 'uploaded')
            ->call('process')
            ->assertSet('jobStatus', 'processing');

        $jobId = $test->get('fileJobId');
        $this->assertNotNull($jobId);

        // 2. Process the queued job with artisan queue:work --once
        $this->artisan('queue:work', ['--once' => true]);

        // 3. Poll and verify it is now completed
        $test->call('pollJobStatus')
            ->assertSet('jobStatus', 'completed')
            ->assertSee('merged_document.pdf');

        $job = FileJob::find($jobId);
        $this->assertEquals(JobStatus::COMPLETED, $job->status);
        $this->assertDatabaseHas('processed_files', [
            'file_job_id' => $jobId,
        ]);
    }
}
