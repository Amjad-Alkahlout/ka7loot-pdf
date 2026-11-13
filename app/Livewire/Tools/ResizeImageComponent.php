<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\ImageFit;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;

class ResizeImageComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;
    public ?int $width = 800;
    public ?int $height = 600;
    public string $fit = 'contain';

    public ?string $filename = null;
    public ?int $fileSizeBytes = null;
    public ?string $fileSizeFormatted = null;
    public ?int $originalWidth = null;
    public ?int $originalHeight = null;

    public function updatedUpload(): void
    {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload' => "required|file|max:{$maxImageKb}",
        ]);

        if ($this->upload) {
            $this->jobStatus = 'uploaded';
            $this->filename = $this->upload->getClientOriginalName();
            $this->fileSizeBytes = $this->upload->getSize();
            $this->fileSizeFormatted = $this->formatFileSize($this->fileSizeBytes);

            try {
                $path = $this->upload->getRealPath();
                if ($path && file_exists($path)) {
                    $dims = @getimagesize($path);
                    if ($dims) {
                        $this->originalWidth = $dims[0];
                        $this->originalHeight = $dims[1];
                        $this->width = $dims[0];
                        $this->height = $dims[1];
                    }
                }
            } catch (\Throwable) {}
        }
    }

    public function getPreviewUrl(): ?string
    {
        if (!$this->upload) {
            return null;
        }

        try {
            return $this->upload->temporaryUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    public function cancelToolUpload(): void
    {
        $this->reset(['upload', 'filename', 'fileSizeBytes', 'fileSizeFormatted', 'originalWidth', 'originalHeight', 'width', 'height']);
        $this->jobStatus = 'empty';
        $this->errorMessage = null;
        $this->statusMessage = null;
    }

    public function resetWorkflow(): void
    {
        $this->resetState();
        $this->reset(['upload', 'filename', 'fileSizeBytes', 'fileSizeFormatted', 'originalWidth', 'originalHeight', 'width', 'height']);
        $this->jobStatus = 'empty';
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload' => "required|file|max:{$maxImageKb}",
            'width'  => 'nullable|integer|min:1|max:10000',
            'height' => 'nullable|integer|min:1|max:10000',
            'fit'    => 'required|in:contain,cover,fill',
        ]);

        if (empty($this->width) && empty($this->height)) {
            $this->addError('width', __('ui.image_width_or_height_required'));
            return;
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['image/jpeg', 'image/png', 'image/webp']);
            $this->upload->storeAs(config('fileprocessor.storage.temp_path', 'secure_temp') . "/{$jobId}", $sanitized['safe_filename'], config('fileprocessor.storage.disk', 'local'));

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::RESIZE_IMAGE,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'          => $sanitized['safe_filename'],
                    'clean_name'    => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'target_width'  => $this->width,
                    'target_height' => $this->height,
                    'fit'           => $this->fit,
                    'ip'            => request()->ip(),
                ],
                'expires_at'     => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
            ]);

            $this->fileJobId = $job->id;
            $dispatcher->execute($job, (int) round($this->upload->getSize() / 1024));

            $this->pollJobStatus();
        } catch (\Throwable $e) {
            $this->jobStatus = 'failed';
            $this->errorMessage = $this->reportToolFailure($e);
        }
    }

    public function render()
    {
        return view('livewire.tools.resize-image-component')
            ->layout('layouts.app', [
                'title'       => __('tools.resize_image.title'),
                'description' => __('tools.resize_image.desc'),
            ]);
    }
}
