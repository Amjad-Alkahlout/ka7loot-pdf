<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;

class ConvertImageComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;
    public string $targetFormat = 'png';

    public ?string $filename = null;
    public ?int $fileSizeBytes = null;
    public ?string $fileSizeFormatted = null;
    public ?string $sourceExtension = null;
    public bool $isHeic = false;
    public ?int $imageWidth = null;
    public ?int $imageHeight = null;

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

            $ext = strtolower($this->upload->getClientOriginalExtension());
            $this->sourceExtension = $ext;
            $this->isHeic = in_array($ext, ['heic', 'heif'], true);

            $this->targetFormat = in_array($ext, ['heic', 'heif', 'png'], true) ? 'jpg' : 'png';

            if (!$this->isHeic) {
                try {
                    $path = $this->upload->getRealPath();
                    if ($path && file_exists($path)) {
                        $dims = @getimagesize($path);
                        if ($dims) {
                            $this->imageWidth = $dims[0];
                            $this->imageHeight = $dims[1];
                        }
                    }
                } catch (\Throwable) {}
            }
        }
    }

    public function getPreviewUrl(): ?string
    {
        if (!$this->upload || $this->isHeic) {
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
        $this->reset(['upload', 'filename', 'fileSizeBytes', 'fileSizeFormatted', 'sourceExtension', 'isHeic', 'imageWidth', 'imageHeight']);
        $this->jobStatus = 'idle';
        $this->errorMessage = null;
        $this->statusMessage = null;
    }

    public function resetWorkflow(): void
    {
        $this->resetState();
        $this->reset(['upload', 'filename', 'fileSizeBytes', 'fileSizeFormatted', 'sourceExtension', 'isHeic', 'imageWidth', 'imageHeight']);
        $this->jobStatus = 'idle';
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload'       => "required|file|max:{$maxImageKb}",
            'targetFormat' => 'required|in:jpg,jpeg,png,webp,gif,bmp',
        ]);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/gif',
                'image/bmp',
                'image/heic',
                'image/heif',
            ]);
            $this->upload->storeAs(config('fileprocessor.storage.temp_path', 'secure_temp') . "/{$jobId}", $sanitized['safe_filename'], config('fileprocessor.storage.disk', 'local'));

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::CONVERT_IMAGE,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'          => $sanitized['safe_filename'],
                    'clean_name'    => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'source_mime'   => $sanitized['mime_type'],
                    'target_format' => $this->targetFormat,
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
        return view('livewire.tools.convert-image-component')
            ->layout('layouts.app', [
                'title'       => __('tools.convert_image.title'),
                'description' => __('tools.convert_image.desc'),
            ]);
    }
}
