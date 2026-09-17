<?php

namespace App\Livewire\Tools;

use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\ToolUsage;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfPasswordRemovalService;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class UnlockPdfComponent extends BaseToolComponent
{
    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public string $password = '';

    public function updatedUpload(): void
    {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        if ($this->upload) {
            $this->jobStatus = 'uploaded';
        }
    }

    public function process(
        SanitizeUploadedFileAction $sanitizer,
        FileStorageManager $storage,
        PdfPasswordRemovalService $removalService
    ): void {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload'   => "required|file|max:{$maxPdfKb}",
            'password' => 'required|string',
        ]);

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        $startTime = hrtime(true);

        try {
            $jobId = Str::uuid()->toString();
            $jobDir = $storage->getJobDirectory($jobId);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $storageResult = $storage->storeUploadedFile($this->upload, $jobId, $sanitized['safe_filename']);
            $sourcePath = $storageResult['absolute_path'];

            // 1. Check if the PDF is actually protected
            if (!$removalService->isEncrypted($sourcePath)) {
                $this->jobStatus = 'uploaded';
                $this->addError('upload', __('tools.unlock_pdf.error_not_encrypted'));
                return;
            }

            // 2. Strictly verify password
            if (!$removalService->verifyPassword($sourcePath, $this->password)) {
                $this->reset(['password']);
                $this->jobStatus = 'uploaded';
                $this->addError('password', __('tools.unlock_pdf.error_invalid_password'));
                return;
            }

            $outputFilename = 'unlocked_' . $sanitized['safe_filename'];
            $outputPath = $jobDir . DIRECTORY_SEPARATOR . $outputFilename;

            $removalResult = $removalService->removePassword(
                sourcePath: $sourcePath,
                outputPath: $outputPath,
                password: $this->password
            );

            // Wipe password immediately from memory
            $this->reset(['password']);

            $elapsedMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $processedSizeBytes = filesize($outputPath);

            // Create completed FileJob (NEVER storing the password)
            $job = FileJob::create([
                'id'                    => $jobId,
                'tool_type'             => ToolType::UNLOCK_PDF,
                'status'                => JobStatus::COMPLETED,
                'download_token'        => Str::random(48),
                'input_metadata'        => [
                    'file'            => $sanitized['safe_filename'],
                    'output_filename' => $outputFilename,
                    'clean_name'      => pathinfo($sanitized['original_name'], PATHINFO_FILENAME),
                    'engine'          => $removalResult['engine'],
                    'ip'              => request()->ip(),
                ],
                'expires_at'            => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
                'processing_started_at' => now(),
                'completed_at'          => now(),
            ]);

            ProcessedFile::create([
                'id'                   => Str::uuid()->toString(),
                'file_job_id'          => $job->id,
                'original_name'        => 'unlocked_' . $sanitized['original_name'],
                'stored_filename'      => $outputFilename,
                'mime_type'            => 'application/pdf',
                'original_size_bytes'  => $sanitized['size_bytes'],
                'processed_size_bytes' => $processedSizeBytes,
                'download_token'       => Str::random(48),
            ]);

            ToolUsage::log(
                toolType: ToolType::UNLOCK_PDF->value,
                ip: request()->ip() ?? '127.0.0.1',
                successful: true,
                bytes: $processedSizeBytes,
                durationMs: $elapsedMs,
                fileJobId: $job->id
            );

            $this->fileJobId = $job->id;
            $this->jobStatus = 'completed';
        } catch (Throwable $e) {
            $this->reset(['password']);
            $this->jobStatus = 'failed';
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.tools.unlock-pdf-component')
            ->layout('layouts.app', [
                'title'       => __('tools.unlock_pdf.title'),
                'description' => __('tools.unlock_pdf.desc'),
            ]);
    }
}
