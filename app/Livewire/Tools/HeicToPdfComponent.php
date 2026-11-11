<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\PageOrientation;
use App\Enums\PageSize;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HeicToPdfComponent extends BaseToolComponent
{
    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];
    public string $pageSize = 'a4';
    public string $orientation = 'auto';
    public float $margin = 10.0;

    public function updatedUploads(): void
    {
        $this->validateUploads();
        if ($this->uploads) {
            $this->jobStatus = 'uploaded';
        }
    }

    protected function validateUploads(): void
    {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);
        $maxFiles = config('fileprocessor.limits.max_batch_files', 50);
        $maxTotalKb = config('fileprocessor.limits.max_total_batch_kb', 102400);

        $this->validate([
            'uploads' => "required|array|max:{$maxFiles}",
            'uploads.*' => "required|file|max:{$maxImageKb}|extensions:heic,heif",
        ]);

        $totalBytes = array_sum(array_map(
            static fn ($upload): int => (int) $upload->getSize(),
            $this->uploads
        ));

        if ($totalBytes > $maxTotalKb * 1024) {
            throw ValidationException::withMessages([
                'uploads' => [__('ui.error_batch_preupload_too_large', ['size' => round($maxTotalKb / 1024)])],
            ]);
        }
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
        $this->jobStatus = $this->uploads ? 'uploaded' : 'empty';
    }

    public function moveUp(int $index): void
    {
        if ($index > 0 && isset($this->uploads[$index], $this->uploads[$index - 1])) {
            [$this->uploads[$index - 1], $this->uploads[$index]] = [$this->uploads[$index], $this->uploads[$index - 1]];
        }
    }

    public function moveDown(int $index): void
    {
        if ($index < count($this->uploads) - 1 && isset($this->uploads[$index], $this->uploads[$index + 1])) {
            [$this->uploads[$index], $this->uploads[$index + 1]] = [$this->uploads[$index + 1], $this->uploads[$index]];
        }
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $this->validateUploads();
        $this->validate([
            'pageSize' => 'required|in:a4,letter,legal,fit',
            'orientation' => 'required|in:portrait,landscape,auto',
            'margin' => 'required|numeric|min:0|max:50',
        ]);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);
            $storedFiles = [];
            $totalBytes = 0;

            foreach ($this->uploads as $upload) {
                $sanitized = $sanitizer->execute($upload, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence']);
                $upload->storeAs(config('fileprocessor.storage.temp_path', 'secure_temp') . "/{$jobId}", $sanitized['safe_filename'], config('fileprocessor.storage.disk', 'local'));
                $storedFiles[] = $sanitized['safe_filename'];
                $totalBytes += (int) $upload->getSize();
            }

            $job = FileJob::create([
                'id' => $jobId,
                'tool_type' => ToolType::HEIC_TO_PDF,
                'status' => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'files' => $storedFiles,
                    'page_size' => $this->pageSize,
                    'orientation' => $this->orientation,
                    'margin' => $this->margin,
                    'output_filename' => 'heic_images_document.pdf',
                    'ip' => request()->ip(),
                ],
                'expires_at' => now()->addMinutes(config('fileprocessor.retention.file_retention_minutes', 60)),
            ]);

            $this->fileJobId = $job->id;
            $dispatcher->execute($job, (int) round($totalBytes / 1024), count($storedFiles));
            $this->pollJobStatus();
        } catch (\Throwable $e) {
            $this->jobStatus = 'failed';
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.tools.heic-to-pdf-component')
            ->layout('layouts.app', [
                'title' => __('tools.heic_to_pdf.title'),
                'description' => __('tools.heic_to_pdf.desc'),
            ]);
    }
}
