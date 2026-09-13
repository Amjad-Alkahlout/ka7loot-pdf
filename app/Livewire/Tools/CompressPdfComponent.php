<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\CompressionLevel;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Str;

class CompressPdfComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;
    public string $level = 'recommended';

    public function updatedUpload(): void
    {
        $this->validate([
            'upload' => 'required|file|max:102400', // 100MB
        ]);

        if ($this->upload) {
            $this->jobStatus = 'ready';
        }
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $this->validate([
            'upload' => 'required|file',
            'level' => 'required|in:low,recommended,extreme',
        ]);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $this->upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::COMPRESS_PDF,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'              => $sanitized['safe_filename'],
                    'clean_name'        => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'compression_level' => $this->level,
                    'ip'                => request()->ip(),
                ],
                'expires_at'     => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
            ]);

            $this->fileJobId = $job->id;
            $dispatcher->execute($job, (int) round($this->upload->getSize() / 1024));

            $this->pollJobStatus();
        } catch (\Throwable $e) {
            $this->jobStatus = 'failed';
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(SystemBinaryDetector $detector)
    {
        return view('livewire.tools.compress-pdf-component', [
            'hasGhostscript' => $detector->hasGhostscript(),
        ])->layout('layouts.app', ['title' => __('tools.compress_pdf.title')]);
    }
}
