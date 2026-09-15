<?php

namespace App\Livewire\Tools;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use App\Services\Share\TemporaryShareService;
use Livewire\Component;
use Livewire\WithFileUploads;

abstract class BaseToolComponent extends Component
{
    use WithFileUploads;

    public ?string $fileJobId = null;
    public string $jobStatus = 'empty'; // 'empty', 'ready', 'uploaded', 'processing', 'completed', 'failed'
    public ?string $errorMessage = null;
    public ?string $statusMessage = null;

    // Temporary QR Share Properties
    public bool $showShareSection = false;
    public int $shareExpirationMinutes = 15;
    public ?int $shareMaxDownloads = null;
    public ?string $selectedFileIdForShare = null;
    public ?string $activeShareId = null;
    public ?string $activeShareToken = null;
    public ?string $activeShareUrl = null;
    public ?string $activeShareQrSvg = null;
    public ?string $activeShareExpiresAt = null;
    public ?int $activeShareRemainingDownloads = null;
    public ?string $shareMessage = null;

    /**
     * Localized validation error messages for uploads.
     */
    public function messages(): array
    {
        return [
            'upload.required'    => __('validation.required', ['attribute' => __('ui.file')]),
            'upload.file'        => __('validation.file', ['attribute' => __('ui.file')]),
            'upload.max'         => __('validation.max.file', ['attribute' => __('ui.file')]),
            'upload.uploaded'    => __('validation.uploaded', ['attribute' => __('ui.file')]),
            'uploads.required'   => __('validation.required', ['attribute' => __('ui.files')]),
            'uploads.*.required' => __('validation.required', ['attribute' => __('ui.file')]),
            'uploads.*.file'     => __('validation.file', ['attribute' => __('ui.file')]),
            'uploads.*.max'      => __('validation.max.file', ['attribute' => __('ui.file')]),
            'uploads.*.uploaded' => __('validation.uploaded', ['attribute' => __('ui.file')]),
        ];
    }

    /**
     * Get maximum PDF size in MB from shared configuration.
     */
    public function getMaxPdfSizeMb(): int
    {
        return (int) round(config('fileprocessor.limits.max_pdf_size_kb', 51200) / 1024);
    }

    /**
     * Get maximum Image size in MB from shared configuration.
     */
    public function getMaxImageSizeMb(): int
    {
        return (int) round(config('fileprocessor.limits.max_image_size_kb', 25600) / 1024);
    }

    /**
     * Get maximum batch files count from shared configuration.
     */
    public function getMaxBatchFiles(): int
    {
        return (int) config('fileprocessor.limits.max_batch_files', 20);
    }

    /**
     * Get maximum total batch size in MB from shared configuration.
     */
    public function getMaxTotalBatchMb(): int
    {
        return (int) round(config('fileprocessor.limits.max_total_batch_kb', 102400) / 1024);
    }

    /**
     * Poll the server for job status updates.
     */
    public function pollJobStatus(): void
    {
        if (!$this->fileJobId) {
            return;
        }

        $job = FileJob::find($this->fileJobId);

        if (!$job) {
            $this->jobStatus = 'failed';
            $this->errorMessage = 'File job was not found.';
            return;
        }

        if ($job->status === JobStatus::COMPLETED) {
            $this->jobStatus = 'completed';
        } elseif ($job->status === JobStatus::FAILED) {
            $this->jobStatus = 'failed';
            $this->errorMessage = $job->error_message ?? __('ui.status_failed');
        } elseif ($job->status === JobStatus::PROCESSING) {
            $this->jobStatus = 'processing';
            $this->statusMessage = __('ui.status_processing');
        } else {
            $this->jobStatus = 'processing';
            $this->statusMessage = __('ui.status_pending');
        }
    }

    /**
     * Cancel and reset tool upload from client or server.
     */
    public function cancelToolUpload(): void
    {
        $this->resetUpload();
        $this->resetState();
    }

    /**
     * Reset upload properties.
     */
    public function resetUpload(): void
    {
        if (property_exists($this, 'upload')) {
            $this->upload = null;
        }
        if (property_exists($this, 'uploads')) {
            $this->uploads = [];
        }
    }

    /**
     * Delete files immediately from server.
     */
    public function deleteNow(FileStorageManager $storage): void
    {
        if (!$this->fileJobId) {
            return;
        }

        $job = FileJob::find($this->fileJobId);
        if ($job) {
            $storage->deleteJobDirectory($job->id);
            $job->update(['status' => JobStatus::EXPIRED]);
        }

        $this->resetUpload();
        $this->resetState();
        session()->flash('status', __('ui.deleted_success'));
    }

    /**
     * Delete files immediately from server (invoked by job-result UI).
     */
    public function deleteJobFiles(FileStorageManager $storage): void
    {
        $this->deleteNow($storage);
    }

    /**
     * Reset tool workflow to initial empty state for processing another file.
     */
    public function resetWorkflow(): void
    {
        $this->resetUpload();
        $this->resetState();
    }

    /**
     * Toggle the share section for a specific processed file or default file.
     */
    public function toggleShareSection(?string $fileId = null): void
    {
        if ($this->showShareSection && $this->selectedFileIdForShare === $fileId) {
            $this->showShareSection = false;
            return;
        }

        $this->selectedFileIdForShare = $fileId;
        $this->showShareSection = true;
        $this->shareMessage = null;
    }

    /**
     * Generate a new temporary QR share link.
     */
    public function generateShare(TemporaryShareService $shareService): void
    {
        $job = $this->fileJob;
        if (!$job || $this->jobStatus !== 'completed') {
            return;
        }

        $file = null;
        if ($this->selectedFileIdForShare) {
            $file = ProcessedFile::where('file_job_id', $job->id)->where('id', $this->selectedFileIdForShare)->first();
        }

        try {
            $res = $shareService->createShare(
                job: $job,
                file: $file,
                expirationMinutes: (int) $this->shareExpirationMinutes,
                maxDownloads: $this->shareMaxDownloads ? (int) $this->shareMaxDownloads : null
            );

            $this->activeShareId = $res['share']->id;
            $this->activeShareToken = $res['token'];
            $this->activeShareUrl = $res['url'];
            $this->activeShareQrSvg = $res['qr_svg'];
            $this->activeShareExpiresAt = $res['share']->expires_at->toIso8601String();
            $this->activeShareRemainingDownloads = $res['share']->remaining_downloads;
            $this->shareMessage = null;
        } catch (\Throwable $e) {
            $this->shareMessage = $e->getMessage();
        }
    }

    /**
     * Revoke the current active share link.
     */
    public function revokeActiveShare(TemporaryShareService $shareService): void
    {
        if ($this->activeShareId) {
            $shareService->revokeShare($this->activeShareId);
        }

        $this->activeShareId = null;
        $this->activeShareToken = null;
        $this->activeShareUrl = null;
        $this->activeShareQrSvg = null;
        $this->activeShareExpiresAt = null;
        $this->activeShareRemainingDownloads = null;
        $this->shareMessage = __('tools.share.revoked_success');
    }

    /**
     * Close the share section.
     */
    public function closeShareSection(): void
    {
        $this->showShareSection = false;
    }

    /**
     * Reset tool state to empty.
     */
    public function resetState(): void
    {
        $this->fileJobId = null;
        $this->jobStatus = 'empty';
        $this->errorMessage = null;
        $this->statusMessage = null;
        $this->showShareSection = false;
        $this->activeShareId = null;
        $this->activeShareToken = null;
        $this->activeShareUrl = null;
        $this->activeShareQrSvg = null;
        $this->activeShareExpiresAt = null;
        $this->activeShareRemainingDownloads = null;
        $this->selectedFileIdForShare = null;
        $this->shareMessage = null;
    }

    /**
     * Get processed files relation if completed.
     */
    public function getProcessedFilesProperty()
    {
        if (!$this->fileJobId || $this->jobStatus !== 'completed') {
            return collect();
        }

        return ProcessedFile::where('file_job_id', $this->fileJobId)->get();
    }

    /**
     * Get current FileJob model.
     */
    public function getFileJobProperty(): ?FileJob
    {
        if (!$this->fileJobId) {
            return null;
        }

        return FileJob::find($this->fileJobId);
    }
}
