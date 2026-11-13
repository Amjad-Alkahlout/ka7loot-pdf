<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\Common\SortOrderValidator;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfSplitService;
use App\Services\Pdf\PdfThumbnailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
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

            // Ensure isolated preview session id is established
            if (! session()->has('preview_session_id')) {
                session()->put('preview_session_id', session()->getId());
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

    /**
     * Drag-and-drop reorder handler strictly validated via SortOrderValidator.
     *
     * @param array<string|int> $orderedIds
     */
    public function reorderPages(array $orderedIds): void
    {
        try {
            $this->pages = SortOrderValidator::applyStrictOrder($this->pages, $orderedIds, 'id');
        } catch (\InvalidArgumentException $e) {
            Log::warning('OrganizePdfComponent: Reorder validation rejected: ' . $e->getMessage());
        }
    }

    /**
     * Generate secure signed temporary preview URL for an uploaded PDF page.
     */
    public function getPreviewUrl(int $page, int $dpi = 120): string
    {
        if (! $this->upload) {
            return '';
        }

        $sessionId = (string) (session()->get('preview_session_id') ?: session()->getId());
        session()->put('preview_session_id', $sessionId);

        return URL::temporarySignedRoute(
            'tools.pdf-preview',
            now()->addHour(),
            [
                'filename'   => $this->upload->getFilename(),
                'page'       => $page,
                'dpi'        => $dpi,
                'session_id' => $sessionId,
            ]
        );
    }

    /**
     * Generate higher-resolution signed URL for crisp inspection.
     */
    public function getHighResPreviewUrl(int $page): string
    {
        return $this->getPreviewUrl($page, 220);
    }

    public function cancelToolUpload(): void
    {
        parent::cancelToolUpload();
        $this->filename = '';
        $this->fileSizeBytes = 0;
        $this->fileSizeFormatted = '';
        $this->pageCount = 0;
        $this->pages = [];
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
            $this->upload->storeAs(config('fileprocessor.storage.temp_path', 'secure_temp') . "/{$jobId}", $sanitized['safe_filename'], config('fileprocessor.storage.disk', 'local'));

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
            $this->errorMessage = $this->reportToolFailure($e);
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
            ->layout('layouts.app', [
                'title'       => __('tools.organize_pdf.title'),
                'description' => __('tools.organize_pdf.desc'),
            ]);
    }
}
