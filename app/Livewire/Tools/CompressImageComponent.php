<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;

class CompressImageComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public string $compressionMethod = 'preset'; // 'preset' | 'target'
    public string $level = 'recommended';

    public ?string $targetValue = null;
    public string $targetUnit = 'kb'; // 'kb' | 'mb'
    public string $targetPriority = 'balanced'; // 'max_quality' | 'balanced' | 'smallest'

    public function updatedUpload(): void
    {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload' => "required|file|max:{$maxImageKb}",
        ]);

        if ($this->upload) {
            $this->jobStatus = 'uploaded';

            if (empty($this->targetValue)) {
                $sizeKb = $this->upload->getSize() / 1024;
                if ($sizeKb > 1024) {
                    $this->targetUnit = 'mb';
                    $this->targetValue = (string) round(($sizeKb / 1024) * 0.5, 1);
                } else {
                    $this->targetUnit = 'kb';
                    $this->targetValue = (string) max(20, (int) round($sizeKb * 0.5));
                }
            }
        }
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'upload'            => "required|file|max:{$maxImageKb}",
            'compressionMethod' => 'required|in:preset,target',
        ]);

        $targetBytes = null;

        if ($this->compressionMethod === 'preset') {
            $this->validate([
                'level' => 'required|in:low,recommended,extreme',
            ]);
        } else {
            if (!is_numeric($this->targetValue) || (float) $this->targetValue <= 0) {
                $this->addError('targetValue', __('tools.validation_target_positive'));
                return;
            }

            $this->validate([
                'targetUnit'     => 'required|in:kb,mb',
                'targetPriority' => 'required|in:max_quality,balanced,smallest',
            ]);

            $val = (float) $this->targetValue;
            $targetBytes = ($this->targetUnit === 'mb')
                ? (int) round($val * 1024 * 1024)
                : (int) round($val * 1024);

            $minKb = config('fileprocessor.target_compression.min_target_kb', 10);
            $maxMb = config('fileprocessor.target_compression.max_target_mb', 50);

            if ($targetBytes < $minKb * 1024) {
                $this->addError('targetValue', __('tools.validation_target_min', ['min' => $minKb]));
                return;
            }

            if ($targetBytes > $maxMb * 1024 * 1024) {
                $this->addError('targetValue', __('tools.validation_target_max', ['max' => $maxMb]));
                return;
            }

            $originalBytes = $this->upload->getSize();
            if ($targetBytes >= $originalBytes) {
                $this->addError('targetValue', __('tools.validation_target_already_smaller'));
                return;
            }
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['image/jpeg', 'image/png', 'image/webp']);
            $this->upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::COMPRESS_IMAGE,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'               => $sanitized['safe_filename'],
                    'clean_name'         => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'compression_method' => $this->compressionMethod,
                    'compression_level'  => $this->level,
                    'target_size_bytes'  => $targetBytes,
                    'target_value'       => $this->targetValue,
                    'target_unit'        => $this->targetUnit,
                    'target_priority'    => $this->targetPriority,
                    'ip'                 => request()->ip(),
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
        return view('livewire.tools.compress-image-component')
            ->layout('layouts.app', [
                'title'       => __('tools.compress_image.title'),
                'description' => __('tools.compress_image.desc'),
            ]);
    }
}
