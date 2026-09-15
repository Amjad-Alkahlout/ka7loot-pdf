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

class SplitPdfComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;

    public string $filename = '';
    public int $fileSizeBytes = 0;
    public string $fileSizeFormatted = '';
    public int $pageCount = 0;

    /** @var array<int, string> Page number to base64 data URI */
    public array $thumbnails = [];

    /** Split mode: 'range', 'every_n', or 'all' */
    public string $mode = 'range';

    /** Custom page ranges for 'range' mode (e.g. "1-5, 8, 10-12") */
    public string $range = '1';

    /** Interval for 'every_n' mode */
    public int $everyN = 2;

    /** Live validation error for range */
    public ?string $rangeError = null;

    /** Live validation error for everyN */
    public ?string $everyNError = null;

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
                Log::warning("SplitPdfComponent: Failed to read page count: " . $e->getMessage());
                $this->pageCount = 1;
            }

            // Set sensible defaults based on page count
            if ($this->pageCount > 1) {
                $defaultEnd = min(5, $this->pageCount);
                $this->range = "1-{$defaultEnd}";
                $this->everyN = max(1, min(5, (int) ceil($this->pageCount / 2)));
            } else {
                $this->range = '1';
                $this->everyN = 1;
            }

            // Generate low-resolution thumbnails
            try {
                $thumbService = app(PdfThumbnailService::class);
                $this->thumbnails = $thumbService->generateThumbnails($realPath, maxPages: 30, scale: 160);
            } catch (\Throwable $e) {
                Log::warning("SplitPdfComponent: Failed to generate thumbnails: " . $e->getMessage());
                $this->thumbnails = [];
            }

            $this->jobStatus = 'uploaded';
            $this->validateActiveMode();
        }
    }

    public function setMode(string $newMode): void
    {
        if (in_array($newMode, ['range', 'every_n', 'all'], true)) {
            $this->mode = $newMode;
            $this->validateActiveMode();
        }
    }

    public function updatedRange(): void
    {
        $this->validateRange();
    }

    public function updatedEveryN(): void
    {
        $this->validateEveryN();
    }

    /**
     * Interactive toggle: clicking a page in the grid toggles its inclusion in the range.
     */
    public function togglePage(int $page): void
    {
        if ($this->mode !== 'range' || $this->pageCount < 1) {
            return;
        }

        if ($page < 1 || $page > $this->pageCount) {
            return;
        }

        $currentPages = [];
        try {
            $pdfSplit = app(PdfSplitService::class);
            $currentPages = $pdfSplit->parsePageRange($this->range, $this->pageCount);
        } catch (\Throwable $e) {
            $currentPages = [];
        }

        if (in_array($page, $currentPages, true)) {
            $currentPages = array_values(array_filter($currentPages, fn($p) => $p !== $page));
        } else {
            $currentPages[] = $page;
        }

        if (empty($currentPages)) {
            $this->range = '';
        } else {
            $this->range = $this->buildRangeStringFromPages($currentPages);
        }

        $this->validateRange();
    }

    /**
     * Validate range mode input with detailed localized error messages.
     */
    public function validateRange(): bool
    {
        $this->rangeError = null;

        if ($this->pageCount < 1) {
            return true;
        }

        $trimmed = trim($this->range);
        if ($trimmed === '') {
            $this->rangeError = __('tools.split_pdf.error_empty_range');
            return false;
        }

        $parts = explode(',', $trimmed);
        $allPages = [];
        $seen = [];
        $duplicates = [];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            if (str_contains($part, '-')) {
                if (!preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
                    $this->rangeError = __('tools.split_pdf.error_invalid_format', ['part' => $part]);
                    return false;
                }

                $start = (int) $m[1];
                $end = (int) $m[2];

                if ($start <= 0 || $end <= 0) {
                    $this->rangeError = __('tools.split_pdf.error_invalid_format', ['part' => $part]);
                    return false;
                }

                if ($start > $end) {
                    $this->rangeError = __('tools.split_pdf.error_invalid_interval', ['part' => $part]);
                    return false;
                }

                if ($start > $this->pageCount) {
                    $this->rangeError = __('tools.split_pdf.error_page_exceeds', ['page' => $start, 'max' => $this->pageCount]);
                    return false;
                }

                if ($end > $this->pageCount) {
                    $this->rangeError = __('tools.split_pdf.error_page_exceeds', ['page' => $end, 'max' => $this->pageCount]);
                    return false;
                }

                for ($i = $start; $i <= $end; $i++) {
                    if (isset($seen[$i])) {
                        $duplicates[] = $i;
                    }
                    $seen[$i] = true;
                    $allPages[] = $i;
                }
            } else {
                if (!preg_match('/^\d+$/', $part)) {
                    $this->rangeError = __('tools.split_pdf.error_invalid_format', ['part' => $part]);
                    return false;
                }

                $p = (int) $part;
                if ($p <= 0) {
                    $this->rangeError = __('tools.split_pdf.error_invalid_format', ['part' => $part]);
                    return false;
                }

                if ($p > $this->pageCount) {
                    $this->rangeError = __('tools.split_pdf.error_page_exceeds', ['page' => $p, 'max' => $this->pageCount]);
                    return false;
                }

                if (isset($seen[$p])) {
                    $duplicates[] = $p;
                }
                $seen[$p] = true;
                $allPages[] = $p;
            }
        }

        if (!empty($duplicates)) {
            $dupStr = implode(', ', array_unique($duplicates));
            $this->rangeError = __('tools.split_pdf.error_duplicate_pages', ['pages' => $dupStr]);
            return false;
        }

        if (empty($allPages)) {
            $this->rangeError = __('tools.split_pdf.error_empty_range');
            return false;
        }

        return true;
    }

    /**
     * Validate every_n mode input.
     */
    public function validateEveryN(): bool
    {
        $this->everyNError = null;

        if ($this->pageCount < 1) {
            return true;
        }

        $n = (int) $this->everyN;
        if ($n < 1 || $n > $this->pageCount) {
            $this->everyNError = __('tools.split_pdf.error_every_n_invalid', ['max' => $this->pageCount]);
            return false;
        }

        return true;
    }

    public function validateActiveMode(): bool
    {
        if ($this->mode === 'range') {
            return $this->validateRange();
        }

        if ($this->mode === 'every_n') {
            return $this->validateEveryN();
        }

        return true;
    }

    /**
     * Compute current split groups for live preview.
     *
     * @return array<array{id: int, label: string, range: string, pages: array<int>, count: int}>
     */
    public function getSplitGroups(): array
    {
        if ($this->pageCount < 1) {
            return [];
        }

        $pdfSplit = app(PdfSplitService::class);

        try {
            if ($this->mode === 'range') {
                if (!$this->validateRange()) {
                    return [];
                }
                return $pdfSplit->parseRangeGroups($this->range, $this->pageCount);
            }

            if ($this->mode === 'every_n') {
                if (!$this->validateEveryN()) {
                    return [];
                }
                return $pdfSplit->generateEveryNGroups($this->pageCount, (int) $this->everyN);
            }

            if ($this->mode === 'all') {
                return $pdfSplit->generateIndividualGroups($this->pageCount);
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    /**
     * Build mapping: pageNumber => [groupId, groupLabel, colorIndex]
     *
     * @return array<int, array{id: int, label: string, colorIndex: int}>
     */
    public function getPageGroupMap(): array
    {
        $groups = $this->getSplitGroups();
        $map = [];

        foreach ($groups as $group) {
            $colorIdx = ($group['id'] - 1) % 6;
            foreach ($group['pages'] as $p) {
                $map[$p] = [
                    'id'         => $group['id'],
                    'label'      => $group['label'],
                    'colorIndex' => $colorIdx,
                ];
            }
        }

        return $map;
    }

    /**
     * Generate concise summary text for the submit button.
     */
    public function getSubmitSummaryText(): string
    {
        $groups = $this->getSplitGroups();
        $groupCount = count($groups);

        if ($groupCount <= 1) {
            return __('tools.split_pdf.btn_summary_single', [
                'pages' => $this->pageCount,
            ]);
        }

        return __('tools.split_pdf.btn_summary_multiple', [
            'count' => $groupCount,
            'pages' => $this->pageCount,
        ]);
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
            'mode'   => 'required|in:all,range,every_n',
        ]);

        if (!$this->validateActiveMode()) {
            return;
        }

        $groups = $this->getSplitGroups();
        if (empty($groups)) {
            $this->jobStatus = 'failed';
            $this->errorMessage = __('tools.split_pdf.error_empty_range');
            return;
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $this->upload->storeAs("secure_temp/{$jobId}", $sanitized['safe_filename'], 'local');

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::SPLIT_PDF,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'         => $sanitized['safe_filename'],
                    'clean_name'   => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'mode'         => $this->mode,
                    'range'        => $this->range,
                    'every_n'      => (int) $this->everyN,
                    'page_count'   => $this->pageCount,
                    'groups_count' => count($groups),
                    'ip'           => request()->ip(),
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

    public function cancelToolUpload(): void
    {
        parent::cancelToolUpload();
        $this->filename = '';
        $this->fileSizeBytes = 0;
        $this->fileSizeFormatted = '';
        $this->pageCount = 0;
        $this->thumbnails = [];
        $this->range = '1';
        $this->everyN = 2;
        $this->rangeError = null;
        $this->everyNError = null;
    }

    protected function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }
        return number_format(max(1, round($bytes / 1024))) . ' KB';
    }

    protected function buildRangeStringFromPages(array $pages): string
    {
        sort($pages);
        $pages = array_values(array_unique($pages));
        if (empty($pages)) {
            return '';
        }

        $ranges = [];
        $start = $pages[0];
        $prev = $start;

        for ($i = 1; $i < count($pages); $i++) {
            if ($pages[$i] === $prev + 1) {
                $prev = $pages[$i];
            } else {
                $ranges[] = ($start === $prev) ? (string) $start : "{$start}-{$prev}";
                $start = $pages[$i];
                $prev = $start;
            }
        }

        $ranges[] = ($start === $prev) ? (string) $start : "{$start}-{$prev}";

        return implode(', ', $ranges);
    }

    public function render()
    {
        return view('livewire.tools.split-pdf-component', [
            'splitGroups'       => $this->getSplitGroups(),
            'pageGroupMap'      => $this->getPageGroupMap(),
            'submitSummaryText' => $this->getSubmitSummaryText(),
        ])->layout('layouts.app', ['title' => __('tools.split_pdf.title')]);
    }
}
