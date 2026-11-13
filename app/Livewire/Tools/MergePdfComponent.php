<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\Common\SortOrderValidator;
use App\Services\Pdf\PdfSplitService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MergePdfComponent extends BaseToolComponent
{
    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $newFiles = [];

    public function updatedUploads(): void
    {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'uploads.*' => "required|file|max:{$maxPdfKb}",
        ]);

        if (!empty($this->uploads)) {
            $this->jobStatus = 'uploaded';
        }
    }

    public function updatedNewFiles(): void
    {
        if (! $this->newFiles) {
            return;
        }

        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'newFiles.*' => "required|file|max:{$maxPdfKb}",
        ]);

        $this->uploads = array_values([...$this->uploads, ...$this->newFiles]);
        $this->newFiles = [];

        if (!empty($this->uploads)) {
            $this->jobStatus = 'uploaded';
        }
    }

    public function getTotalSizeFormattedProperty(): string
    {
        $bytes = array_sum(array_map(
            static fn ($upload): int => is_object($upload) && method_exists($upload, 'getSize') ? (int) $upload->getSize() : 0,
            $this->uploads
        ));

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . ' MB';
        }

        return number_format($bytes / 1024, 1) . ' KB';
    }

    /**
     * Get signed temporary URL for page 1 PDF preview.
     */
    public function getPreviewUrl(int $index, int $page = 1, int $dpi = 120): ?string
    {
        if (! isset($this->uploads[$index])) {
            return null;
        }

        $file = $this->uploads[$index];
        if (! is_object($file) || ! method_exists($file, 'getFilename')) {
            return null;
        }

        $filename = basename($file->getFilename());
        $sessionId = session()->get('preview_session_id') ?? session()->getId();
        session()->put('preview_session_id', $sessionId);

        return URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addMinutes(60),
            [
                'filename'   => $filename,
                'session_id' => $sessionId,
                'page'       => $page,
                'dpi'        => $dpi,
            ]
        );
    }

    /** Refresh a preview only when the file is still part of the current merge selection. */
    public function getPreviewUrlForFilename(string $filename, int $page = 1, int $dpi = 120): ?string
    {
        $filename = basename($filename);

        foreach ($this->uploads as $index => $file) {
            if (is_object($file) && method_exists($file, 'getFilename') && $file->getFilename() === $filename) {
                return $this->getPreviewUrl($index, $page, $dpi);
            }
        }

        return null;
    }

    /**
     * Get high-resolution signed URL for inspecting page 1 (220 DPI).
     */
    public function getHighResPreviewUrl(int $index, int $page = 1): ?string
    {
        return $this->getPreviewUrl($index, $page, 220);
    }

    /**
     * Get total pages in an uploaded PDF if accessible.
     */
    public function getPageCountForUpload(int $index): ?int
    {
        if (! isset($this->uploads[$index])) {
            return null;
        }

        try {
            $file = $this->uploads[$index];
            if (is_object($file) && method_exists($file, 'getRealPath') && $file->getRealPath() && file_exists($file->getRealPath())) {
                return app(PdfSplitService::class)->getPageCount($file->getRealPath());
            }
        } catch (\Throwable) {}

        return null;
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
    public function reorderUploads(array $orderedIds): void
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

    public function removeFile(int $index): void
    {
        if (isset($this->uploads[$index])) {
            $this->cleanupPdfPreviewCacheForUpload($this->uploads[$index]);
        }

        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);

        if (empty($this->uploads)) {
            $this->jobStatus = 'empty';
        }
    }

    public function removeUpload(int $index): void
    {
        $this->removeFile($index);
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'uploads'   => 'required|array|min:2',
            'uploads.*' => "required|file|max:{$maxPdfKb}",
        ]);
        $this->validateUploadBatchLimits($this->uploads);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $jobDir = $storage->getJobDirectory($jobId);
            $storedFiles = [];
            $totalBytes = 0;

            foreach ($this->uploads as $upload) {
                $sanitized = $sanitizer->execute($upload, ['application/pdf']);
                $upload->storeAs(config('fileprocessor.storage.temp_path', 'secure_temp') . "/{$jobId}", $sanitized['safe_filename'], config('fileprocessor.storage.disk', 'local'));
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
            $this->errorMessage = $this->reportToolFailure($e);
        }
    }

    public function render()
    {
        return view('livewire.tools.merge-pdf-component')
            ->layout('layouts.app', [
                'title'       => __('tools.merge_pdf.title'),
                'description' => __('tools.merge_pdf.desc'),
            ]);
    }
}
