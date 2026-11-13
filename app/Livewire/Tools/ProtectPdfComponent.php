<?php

namespace App\Livewire\Tools;

use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\ToolUsage;
use App\Services\File\DailyFileQuotaService;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfProtectionService;
use App\Services\Pdf\PdfSplitService;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class ProtectPdfComponent extends BaseToolComponent
{
    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $filename = '';
    public int $fileSizeBytes = 0;
    public string $fileSizeFormatted = '';
    public int $pageCount = 1;

    public string $password = '';

    public string $password_confirmation = '';

    public bool $allowPrinting = true;

    public bool $allowCopying = true;

    public bool $allowModifying = false;

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
        $this->password = '';
        $this->password_confirmation = '';
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
        PdfProtectionService $protectionService,
        DailyFileQuotaService $quota
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
            'password' => 'required|string|min:4',
            'password_confirmation' => 'required|string|min:4|same:password',
            'allowPrinting' => 'boolean',
            'allowCopying' => 'boolean',
            'allowModifying' => 'boolean',
        ]);
        try {
            $quota->assertAvailable();
        } catch (Throwable $e) {
            $this->reset(['password', 'password_confirmation']);
            $this->addError('upload', $e->getMessage());
            $this->jobStatus = 'uploaded';

            return;
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        $startTime = hrtime(true);

        try {
            $jobId = Str::uuid()->toString();
            $jobDir = $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $storageResult = $storage->storeUploadedFile($this->upload, $jobId, $sanitized['safe_filename']);
            $sourcePath = $storageResult['absolute_path'];

            $outputFilename = 'protected_'.$sanitized['safe_filename'];
            $outputPath = $jobDir.DIRECTORY_SEPARATOR.$outputFilename;

            $protectionResult = $protectionService->protect(
                sourcePath: $sourcePath,
                outputPath: $outputPath,
                userPassword: $this->password,
                ownerPassword: null,
                permissions: [
                    'allow_printing' => $this->allowPrinting,
                    'allow_copying' => $this->allowCopying,
                    'allow_modifying' => $this->allowModifying,
                ]
            );

            // Cleanly wipe password fields in component memory immediately
            $this->reset(['password', 'password_confirmation']);

            $elapsedMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $processedSizeBytes = filesize($outputPath);

            // Create completed FileJob record (Strictly WITHOUT password)
            $job = FileJob::create([
                'id' => $jobId,
                'tool_type' => ToolType::PROTECT_PDF,
                'status' => JobStatus::COMPLETED,
                'download_token' => Str::random(48),
                'input_metadata' => [
                    'file' => $sanitized['safe_filename'],
                    'output_filename' => $outputFilename,
                    'clean_name' => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'engine' => $protectionResult['engine'],
                    'ip' => request()->ip(),
                ],
                'expires_at' => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
                'processing_started_at' => now(),
                'completed_at' => now(),
            ]);

            ProcessedFile::create([
                'id' => Str::uuid()->toString(),
                'file_job_id' => $job->id,
                'original_name' => 'protected_'.$sanitized['original_name'],
                'stored_filename' => $outputFilename,
                'mime_type' => 'application/pdf',
                'original_size_bytes' => $sanitized['size_bytes'],
                'processed_size_bytes' => $processedSizeBytes,
                'download_token' => Str::random(48),
            ]);

            ToolUsage::log(
                toolType: ToolType::PROTECT_PDF->value,
                ip: request()->ip() ?? '127.0.0.1',
                successful: true,
                bytes: $processedSizeBytes,
                durationMs: $elapsedMs,
                fileJobId: $job->id
            );

            $this->fileJobId = $job->id;
            $this->jobStatus = 'completed';
        } catch (Throwable $e) {
            $this->reset(['password', 'password_confirmation']);
            $this->jobStatus = 'failed';
            $this->errorMessage = $this->reportToolFailure($e);
        }
    }

    public function render()
    {
        return view('livewire.tools.protect-pdf-component')
            ->layout('layouts.app', [
                'title' => __('tools.protect_pdf.title'),
                'description' => __('tools.protect_pdf.desc'),
            ]);
    }
}
