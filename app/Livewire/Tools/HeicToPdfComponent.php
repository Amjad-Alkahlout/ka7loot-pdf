<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\PageOrientation;
use App\Enums\PageSize;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\Common\SortOrderValidator;
use App\Services\File\FileStorageManager;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HeicToPdfComponent extends BaseToolComponent
{
    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newFiles = [];
    public string $pageSize = 'a4';
    public string $orientation = 'auto';
    public float $margin = 10.0;

    public function updatedNewFiles(): void
    {
        if (! $this->newFiles) {
            return;
        }

        $this->validateNewFiles();
        $this->uploads = array_values([...$this->uploads, ...$this->newFiles]);
        $this->newFiles = [];

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

    protected function validateNewFiles(): void
    {
        $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);
        $maxFiles = config('fileprocessor.limits.max_batch_files', 50);
        $maxTotalKb = config('fileprocessor.limits.max_total_batch_kb', 102400);

        $this->validate([
            'newFiles' => "required|array|max:{$maxFiles}",
            'newFiles.*' => "required|file|max:{$maxImageKb}|extensions:heic,heif",
        ]);

        if (count($this->uploads) + count($this->newFiles) > $maxFiles) {
            throw ValidationException::withMessages([
                'newFiles' => [__('ui.error_too_many_files', ['max' => $maxFiles])],
            ]);
        }

        $totalBytes = array_sum(array_map(
            static fn ($upload): int => (int) $upload->getSize(),
            [...$this->uploads, ...$this->newFiles]
        ));

        if ($totalBytes > $maxTotalKb * 1024) {
            throw ValidationException::withMessages([
                'newFiles' => [__('ui.error_batch_preupload_too_large', ['size' => round($maxTotalKb / 1024)])],
            ]);
        }
    }

    public function getTotalSizeFormattedProperty(): string
    {
        $bytes = array_sum(array_map(
            static fn ($upload): int => (int) $upload->getSize(),
            $this->uploads
        ));

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        }

        return number_format($bytes / 1024, 1) . ' KB';
    }

    /**
     * @return array<int, string>
     */
    public function getPreviewUrlsProperty(): array
    {
        $urls = [];
        $sessionId = session()->get('preview_session_id') ?? session()->getId();
        session()->put('preview_session_id', $sessionId);

        foreach ($this->uploads as $idx => $upload) {
            if (is_object($upload) && method_exists($upload, 'getFilename')) {
                $filename = basename($upload->getFilename());
                $urls[$idx] = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'tools.heic-preview',
                    now()->addMinutes(60),
                    [
                        'filename' => $filename,
                        'session_id' => $sessionId,
                    ]
                );
            }
        }

        return $urls;
    }

    public function removeUpload(int $index): void
    {
        if (isset($this->uploads[$index])) {
            $this->deleteThumbnailForUpload($this->uploads[$index]);
            unset($this->uploads[$index]);
            $this->uploads = array_values($this->uploads);
            $this->jobStatus = $this->uploads ? 'uploaded' : 'empty';
        }
    }

    public function removeFile(int $index): void
    {
        $this->removeUpload($index);
    }

    public function cancelToolUpload(): void
    {
        foreach ($this->uploads as $upload) {
            $this->deleteThumbnailForUpload($upload);
        }

        parent::cancelToolUpload();
    }

    protected function deleteThumbnailForUpload(mixed $upload): void
    {
        if (! is_object($upload) || ! method_exists($upload, 'getFilename')) {
            return;
        }

        try {
            $filename = basename($upload->getFilename());
            $thumbNames = [
                'thumb_' . md5($filename) . '.jpg',
                'thumb_' . pathinfo($filename, PATHINFO_FILENAME) . '.jpg',
            ];
            $disk = \Illuminate\Support\Facades\Storage::disk(config('livewire.temporary_file_upload.disk', 'local'));
            $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

            foreach ($thumbNames as $thumbName) {
                $disk->delete($livewireDir . '/' . $thumbName);
                $disk->delete($thumbName);

                if (method_exists($upload, 'getRealPath') && $upload->getRealPath()) {
                    $localThumb = dirname($upload->getRealPath()) . DIRECTORY_SEPARATOR . $thumbName;
                    if (file_exists($localThumb)) {
                        @unlink($localThumb);
                    }
                }

                $sysPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $thumbName;
                if (file_exists($sysPath)) {
                    @unlink($sysPath);
                }
            }
        } catch (\Throwable) {
            // Ignored
        }
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

    /**
     * Reorder uploads via SortableJS strictly validated via SortOrderValidator.
     */
    public function reorderFiles(array $orderedIds): void
    {
        try {
            $this->uploads = SortOrderValidator::applyStrictOrder(
                $this->uploads,
                $orderedIds,
                fn ($f) => is_object($f) && method_exists($f, 'getFilename') ? $f->getFilename() : ''
            );
        } catch (\InvalidArgumentException) {
            // Safely reject incomplete or malformed reorder payloads
        }
    }

    public function reorderUploads(array $orderedIds): void
    {
        $this->reorderFiles($orderedIds);
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
                $this->deleteThumbnailForUpload($upload);
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
            $this->errorMessage = $this->reportToolFailure($e);
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
