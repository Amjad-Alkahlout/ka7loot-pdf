<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;

class RemoveImageBackgroundComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public string $filename = '';
    public int $fileSizeBytes = 0;
    public string $fileSizeFormatted = '';
    public int $imageWidth = 0;
    public int $imageHeight = 0;

    public float $tolerance = 25.0;
    public int $feather = 2;
    public bool $floodFill = true;

    public function updatedUpload(): void
    {
        $maxKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload' => "required|file|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
        ]);

        if ($this->upload) {
            $this->jobStatus = 'uploaded';
            $this->filename = $this->upload->getClientOriginalName();
            $this->fileSizeBytes = (int) $this->upload->getSize();
            $this->fileSizeFormatted = $this->formatFileSize($this->fileSizeBytes);

            try {
                $path = $this->upload->getRealPath();
                if ($path && file_exists($path)) {
                    $dims = @getimagesize($path);
                    if ($dims) {
                        $this->imageWidth = (int) $dims[0];
                        $this->imageHeight = (int) $dims[1];
                    }
                }
            } catch (\Throwable) {
                $this->imageWidth = 0;
                $this->imageHeight = 0;
            }
        }
    }

    public function cancelToolUpload(): void
    {
        parent::cancelToolUpload();
        $this->upload = null;
        $this->filename = '';
        $this->fileSizeBytes = 0;
        $this->fileSizeFormatted = '';
        $this->imageWidth = 0;
        $this->imageHeight = 0;
        $this->tolerance = 25.0;
        $this->feather = 2;
        $this->floodFill = true;
    }

    public function resetWorkflow(): void
    {
        parent::resetWorkflow();
        $this->cancelToolUpload();
    }

    public function getPreviewUrl(): string
    {
        if (! $this->upload) {
            return '';
        }

        try {
            return $this->upload->temporaryUrl();
        } catch (\Throwable) {
            return '';
        }
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload'    => "required|file|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
            'tolerance' => 'required|numeric|min:5|max:80',
            'feather'   => 'required|integer|min:0|max:5',
            'floodFill' => 'required|boolean',
        ]);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['image/jpeg', 'image/png', 'image/webp']);
            $this->upload->storeAs(config('fileprocessor.storage.temp_path', 'secure_temp') . "/{$jobId}", $sanitized['safe_filename'], config('fileprocessor.storage.disk', 'local'));

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::REMOVE_BACKGROUND,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'       => $sanitized['safe_filename'],
                    'clean_name' => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'options'    => [
                        'tolerance'  => (float) $this->tolerance,
                        'feather'    => (int) $this->feather,
                        'flood_fill' => (bool) $this->floodFill,
                    ],
                    'ip'         => request()->ip(),
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
        return view('livewire.tools.remove-image-background-component')
            ->layout('layouts.app', [
                'title'       => __('tools.remove_background.title'),
                'description' => __('tools.remove_background.desc'),
            ]);
    }
}
