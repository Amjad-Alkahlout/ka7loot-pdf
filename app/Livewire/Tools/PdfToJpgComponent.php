<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Str;

class PdfToJpgComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;
    public int $dpi = 150;

    public function updatedUpload(): void
    {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        if ($this->upload) {
            $this->jobStatus = 'uploaded';
        }
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher,
        SystemBinaryDetector $detector
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
            'dpi'    => 'required|in:150,300',
        ]);

        if (!$detector->hasPoppler()) {
            $this->addError('upload', 'Poppler is required on the server to convert PDF to JPG.');
            return;
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $this->upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::PDF_TO_JPG,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'       => $sanitized['safe_filename'],
                    'clean_name' => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'dpi'        => $this->dpi,
                    'ip'         => request()->ip(),
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

    public function render()
    {
        return view('livewire.tools.pdf-to-jpg-component')
            ->layout('layouts.app', [
                'title'       => __('tools.pdf_to_jpg.title'),
                'description' => __('tools.pdf_to_jpg.desc'),
            ]);
    }
}
