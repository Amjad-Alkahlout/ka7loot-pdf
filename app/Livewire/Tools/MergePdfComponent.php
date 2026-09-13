<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;

class MergePdfComponent extends BaseToolComponent
{
    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public function updatedUploads(): void
    {
        $this->validate([
            'uploads.*' => 'required|file|max:51200', // 50MB
        ]);

        if (!empty($this->uploads)) {
            $this->jobStatus = 'ready';
        }
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);

        if (empty($this->uploads)) {
            $this->jobStatus = 'empty';
        }
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $this->validate([
            'uploads' => 'required|array|min:2',
            'uploads.*' => 'required|file',
        ], [
            'uploads.min' => 'Please select at least 2 PDF files to merge.',
        ]);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $jobDir = $storage->getJobDirectory($jobId);
            $storedFiles = [];
            $totalBytes = 0;

            foreach ($this->uploads as $upload) {
                $sanitized = $sanitizer->execute($upload, ['application/pdf']);
                $upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');
                $storedFiles[] = $sanitized['safe_filename'];
                $totalBytes += $upload->getSize();
            }

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::MERGE_PDF,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'files'           => $storedFiles,
                    'output_filename' => 'merged_document.pdf',
                    'ip'              => request()->ip(),
                ],
                'expires_at'     => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
            ]);

            $this->fileJobId = $job->id;
            $dispatcher->execute($job, (int) round($totalBytes / 1024));

            $this->pollJobStatus();
        } catch (\Throwable $e) {
            $this->jobStatus = 'failed';
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.tools.merge-pdf-component')
            ->layout('layouts.app', ['title' => __('tools.merge_pdf.title')]);
    }
}
