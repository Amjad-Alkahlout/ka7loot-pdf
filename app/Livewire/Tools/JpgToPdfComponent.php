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

class JpgToPdfComponent extends BaseToolComponent
{
    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];
    public string $pageSize = 'a4';
    public string $orientation = 'auto';
    public float $margin = 10.0;

    public function updatedUploads(): void
    {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'uploads.*' => "required|file|max:{$maxImageKb}",
        ]);

        if (!empty($this->uploads)) {
            $this->jobStatus = 'uploaded';
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

    public function moveUp(int $index): void
    {
        if ($index > 0 && isset($this->uploads[$index], $this->uploads[$index - 1])) {
            $temp = $this->uploads[$index - 1];
            $this->uploads[$index - 1] = $this->uploads[$index];
            $this->uploads[$index] = $temp;
        }
    }

    public function moveDown(int $index): void
    {
        if ($index < count($this->uploads) - 1 && isset($this->uploads[$index], $this->uploads[$index + 1])) {
            $temp = $this->uploads[$index + 1];
            $this->uploads[$index + 1] = $this->uploads[$index];
            $this->uploads[$index] = $temp;
        }
    }

    public function getImageDimensions($file): ?array
    {
        try {
            $path = $file->getRealPath();
            if ($path && file_exists($path)) {
                $info = @getimagesize($path);
                if ($info && !empty($info[0]) && !empty($info[1])) {
                    return ['width' => $info[0], 'height' => $info[1]];
                }
            }
        } catch (\Throwable) {}

        return null;
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);

        $this->validate([
            'uploads'     => 'required|array|min:1',
            'uploads.*'   => "required|file|max:{$maxImageKb}",
            'pageSize'    => 'required|in:a4,letter,legal,fit',
            'orientation' => 'required|in:portrait,landscape,auto',
            'margin'      => 'required|numeric|min:0|max:50',
        ]);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);
            $storedFiles = [];
            $totalBytes = 0;

            foreach ($this->uploads as $upload) {
                $sanitized = $sanitizer->execute($upload, ['image/jpeg', 'image/png', 'image/webp']);
                $upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');
                $storedFiles[] = $sanitized['safe_filename'];
                $totalBytes += $upload->getSize();
            }

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::JPG_TO_PDF,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'files'           => $storedFiles,
                    'page_size'       => $this->pageSize,
                    'orientation'     => $this->orientation,
                    'margin'          => $this->margin,
                    'output_filename' => 'images_document.pdf',
                    'ip'              => request()->ip(),
                ],
                'expires_at'     => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
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
        return view('livewire.tools.jpg-to-pdf-component')
            ->layout('layouts.app', ['title' => __('tools.jpg_to_pdf.title')]);
    }
}
