<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;

class ChangeImageBackgroundComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $bgUpload = null;

    public string $backgroundType = 'solid'; // 'transparent', 'solid', 'gradient', 'image'
    public string $solidColor = '#FFFFFF';
    public string $gradientStart = '#4F46E5';
    public string $gradientEnd = '#06B6D4';
    public string $gradientDirection = 'vertical'; // 'vertical', 'horizontal', 'diagonal'
    public string $bgFit = 'cover'; // 'cover', 'contain', 'stretch'

    public float $tolerance = 25.0;
    public int $feather = 2;
    public bool $floodFill = true;

    public ?string $originalPreviewUri = null;
    public ?string $bgPreviewUri = null;

    public function updatedUpload(): void
    {
        $maxKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload' => "required|file|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
        ]);

        if ($this->upload) {
            $this->jobStatus = 'uploaded';
            try {
                $path = $this->upload->getRealPath();
                if ($path && file_exists($path)) {
                    $mime = $this->upload->getMimeType() ?: 'image/png';
                    $data = file_get_contents($path);
                    $this->originalPreviewUri = 'data:' . $mime . ';base64,' . base64_encode($data);
                }
            } catch (\Throwable) {
                $this->originalPreviewUri = null;
            }
        }
    }

    public function updatedBgUpload(): void
    {
        $maxKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'bgUpload' => "nullable|file|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
        ]);

        if ($this->bgUpload) {
            try {
                $path = $this->bgUpload->getRealPath();
                if ($path && file_exists($path)) {
                    $mime = $this->bgUpload->getMimeType() ?: 'image/png';
                    $data = file_get_contents($path);
                    $this->bgPreviewUri = 'data:' . $mime . ';base64,' . base64_encode($data);
                }
            } catch (\Throwable) {
                $this->bgPreviewUri = null;
            }
        }
    }

    public function setSolidPreset(string $hex): void
    {
        $this->solidColor = $hex;
    }

    public function setGradientPreset(string $start, string $end): void
    {
        $this->gradientStart = $start;
        $this->gradientEnd = $end;
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $rules = [
            'upload'            => "required|file|mimes:jpeg,jpg,png,webp|max:{$maxKb}",
            'backgroundType'    => 'required|in:transparent,solid,gradient,image',
            'tolerance'         => 'required|numeric|min:5|max:80',
            'feather'           => 'required|integer|min:0|max:5',
            'floodFill'         => 'required|boolean',
        ];

        if ($this->backgroundType === 'solid') {
            $rules['solidColor'] = ['required', 'regex:/^#?([a-f0-9]{6}|[a-f0-9]{3})$/i'];
        } elseif ($this->backgroundType === 'gradient') {
            $rules['gradientStart'] = ['required', 'regex:/^#?([a-f0-9]{6}|[a-f0-9]{3})$/i'];
            $rules['gradientEnd'] = ['required', 'regex:/^#?([a-f0-9]{6}|[a-f0-9]{3})$/i'];
            $rules['gradientDirection'] = 'required|in:vertical,horizontal,diagonal';
        } elseif ($this->backgroundType === 'image') {
            $rules['bgUpload'] = "required|file|mimes:jpeg,jpg,png,webp|max:{$maxKb}";
            $rules['bgFit'] = 'required|in:cover,contain,stretch';
        }

        $this->validate($rules);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['image/jpeg', 'image/png', 'image/webp']);
            $this->upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');

            $bgConfig = [
                'type' => $this->backgroundType,
            ];

            if ($this->backgroundType === 'solid') {
                $bgConfig['color'] = $this->solidColor;
            } elseif ($this->backgroundType === 'gradient') {
                $bgConfig['color_start'] = $this->gradientStart;
                $bgConfig['color_end'] = $this->gradientEnd;
                $bgConfig['direction'] = $this->gradientDirection;
            } elseif ($this->backgroundType === 'image' && $this->bgUpload) {
                $bgSanitized = $sanitizer->execute($this->bgUpload, ['image/jpeg', 'image/png', 'image/webp']);
                $this->bgUpload->storeAs("secure_temp/{$jobId}", $bgSanitized['safe_filename'], 'local');
                $bgConfig['image_file'] = $bgSanitized['safe_filename'];
                $bgConfig['fit'] = $this->bgFit;
            }

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::CHANGE_BACKGROUND,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'              => $sanitized['safe_filename'],
                    'clean_name'        => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'background_config' => $bgConfig,
                    'options'           => [
                        'tolerance'  => (float) $this->tolerance,
                        'feather'    => (int) $this->feather,
                        'flood_fill' => (bool) $this->floodFill,
                    ],
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

    public function resetWorkflow(): void
    {
        parent::resetWorkflow();
        $this->reset(['upload', 'bgUpload', 'originalPreviewUri', 'bgPreviewUri']);
    }

    public function render()
    {
        return view('livewire.tools.change-image-background-component')
            ->layout('layouts.app', [
                'title'       => __('tools.change_background.title'),
                'description' => __('tools.change_background.desc'),
            ]);
    }
}
