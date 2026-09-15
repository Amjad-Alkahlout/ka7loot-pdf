<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfSplitService;
use App\Services\Pdf\PdfThumbnailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OrganizePdfComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public string $filename = '';
    public int $fileSizeBytes = 0;
    public string $fileSizeFormatted = '';
    public int $pageCount = 0;

    /**
     * List of pages with their order, rotation, and deletion status.
     * @var array<int, array{id: int, original_page: int, rotation: int, deleted: bool}>
     */
    public array $pages = [];

    /**
     * Base64 thumbnails indexed by original page number.
     * @var array<int, string>
     */
    public array $thumbnails = [];

    public ?int $selectedPageId = null;

    public function updatedUpload(): void
    {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        if ($this->upload) {
            $realPath = $this->upload->getRealPath();
            $this->filename = $this->upload->getClientOriginalName();
            $this->fileSizeBytes = (int) $this->upload->getSize();
            $this->fileSizeFormatted = $this->formatFileSize($this->fileSizeBytes);

            try {
                $pdfSplit = app(PdfSplitService::class);
                $this->pageCount = $pdfSplit->getPageCount($realPath);
            } catch (\Throwable $e) {
                Log::warning("OrganizePdfComponent: Failed to get page count: " . $e->getMessage());
                $this->pageCount = 1;
            }

            // Generate thumbnails for each page
            try {
                $thumbService = app(PdfThumbnailService::class);
                $this->thumbnails = $thumbService->generateThumbnails($realPath, maxPages: 50, scale: 220);
            } catch (\Throwable $e) {
                Log::warning("OrganizePdfComponent: Failed to generate thumbnails: " . $e->getMessage());
                $this->thumbnails = [];
            }

            // Initialize page structure
            $this->pages = [];
            for ($i = 1; $i <= $this->pageCount; $i++) {
                $this->pages[] = [
                    'id'            => $i,
                    'original_page' => $i,
                    'rotation'      => 0,
                    'deleted'       => false,
                ];
            }

            $this->jobStatus = 'uploaded';
        }
    }

    public function selectPage(int $pageId): void
    {
        $this->selectedPageId = ($this->selectedPageId === $pageId) ? null : $pageId;
    }

    public function rotateLeft(int $index): void
    {
        if (isset($this->pages[$index])) {
            $current = $this->pages[$index]['rotation'];
            $this->pages[$index]['rotation'] = (($current - 90) % 360 + 360) % 360;
        }
    }

    public function rotateRight(int $index): void
    {
        if (isset($this->pages[$index])) {
            $current = $this->pages[$index]['rotation'];
            $this->pages[$index]['rotation'] = ($current + 90) % 360;
        }
    }

    public function toggleDelete(int $index): void
    {
        if (isset($this->pages[$index])) {
            $this->pages[$index]['deleted'] = !$this->pages[$index]['deleted'];
        }
    }

    public function deletePage(int $index): void
    {
        if (isset($this->pages[$index])) {
            $this->pages[$index]['deleted'] = true;
        }
    }

    public function restorePage(int $index): void
    {
        if (isset($this->pages[$index])) {
            $this->pages[$index]['deleted'] = false;
        }
    }

    public function moveUp(int $index): void
    {
        if ($index > 0 && isset($this->pages[$index], $this->pages[$index - 1])) {
            $temp = $this->pages[$index];
            $this->pages[$index] = $this->pages[$index - 1];
            $this->pages[$index - 1] = $temp;
        }
    }

    public function moveDown(int $index): void
    {
        if ($index < count($this->pages) - 1 && isset($this->pages[$index], $this->pages[$index + 1])) {
            $temp = $this->pages[$index];
            $this->pages[$index] = $this->pages[$index + 1];
            $this->pages[$index + 1] = $temp;
        }
    }

    public function resetPages(): void
    {
        $this->pages = [];
        for ($i = 1; $i <= $this->pageCount; $i++) {
            $this->pages[] = [
                'id'            => $i,
                'original_page' => $i,
                'rotation'      => 0,
                'deleted'       => false,
            ];
        }
        $this->selectedPageId = null;
    }

    public function getActivePagesProperty(): array
    {
        return array_values(array_filter($this->pages, fn($p) => !$p['deleted']));
    }

    public function getActiveCountProperty(): int
    {
        return count($this->activePages);
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        $activePages = $this->activePages;
        if (empty($activePages)) {
            $this->addError('pages', __('tools.organize_pdf.error_no_pages'));
            return;
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $this->upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');

            $operations = array_map(function ($pageItem) {
                return [
                    'page'     => (int) $pageItem['original_page'],
                    'rotation' => (int) $pageItem['rotation'],
                ];
            }, $activePages);

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::ORGANIZE_PDF,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'       => $sanitized['safe_filename'],
                    'clean_name' => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'operations' => $operations,
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

    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }

    public function render()
    {
        return view('livewire.tools.organize-pdf-component')
            ->layout('layouts.app', ['title' => __('tools.organize_pdf.title')]);
    }
}
