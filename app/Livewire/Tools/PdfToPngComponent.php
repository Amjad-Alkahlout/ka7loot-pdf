<?php

namespace App\Livewire\Tools;

use App\Actions\File\DispatchFileJobAction;
use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfSplitService;
use App\Services\System\SystemBinaryDetector;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class PdfToPngComponent extends BaseToolComponent
{
    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $upload = null;
    public int $dpi = 150;

    public string $filename = '';
    public int $fileSizeBytes = 0;
    public string $fileSizeFormatted = '';
    public int $pageCount = 1;

    public function updatedUpload(): void
    {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        if ($this->upload) {
            $this->jobStatus = 'uploaded';
            $this->filename = $this->upload->getClientOriginalName();
            $this->fileSizeBytes = (int) $this->upload->getSize();
            $this->fileSizeFormatted = $this->formatFileSize($this->fileSizeBytes);

            try {
                $pdfSplit = app(PdfSplitService::class);
                $this->pageCount = max(1, $pdfSplit->getPageCount($this->upload->getRealPath()));
            } catch (\Throwable $e) {
                $this->pageCount = 1;
            }

            if (! session()->has('preview_session_id')) {
                session()->put('preview_session_id', session()->getId());
            }
        }
    }

    public function cancelToolUpload(): void
    {
        parent::cancelToolUpload();
        $this->filename = '';
        $this->fileSizeBytes = 0;
        $this->fileSizeFormatted = '';
        $this->pageCount = 1;
    }

    public function getPreviewUrl(int $page = 1, int $dpi = 120): string
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

    public function getHighResPreviewUrl(int $page = 1): string
    {
        return $this->getPreviewUrl($page, 200);
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        DispatchFileJobAction $dispatcher,
        SystemBinaryDetector $detector
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
            'dpi'    => 'required|in:150,300',
        ]);

        if (!$detector->hasPoppler()) {
            $this->addError('upload', __('ui.poppler_required_pdf_to_png'));
            return;
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        try {
            $jobId = Str::uuid()->toString();
            $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $this->upload->storeAs(config('fileprocessor.storage.temp_path', 'secure_temp') . "/{$jobId}", $sanitized['safe_filename'], config('fileprocessor.storage.disk', 'local'));

            $job = FileJob::create([
                'id'             => $jobId,
                'tool_type'      => ToolType::PDF_TO_PNG,
                'status'         => JobStatus::PENDING,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file'       => $sanitized['safe_filename'],
                    'clean_name' => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'dpi'        => $this->dpi,
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
        return view('livewire.tools.pdf-to-png-component')
            ->layout('layouts.app', [
                'title'       => __('tools.pdf_to_png.title'),
                'description' => __('tools.pdf_to_png.desc'),
            ]);
    }
}
