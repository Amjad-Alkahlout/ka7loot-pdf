<?php

namespace App\Livewire\Tools;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use Livewire\Component;
use Livewire\WithFileUploads;

abstract class BaseToolComponent extends Component
{
    use WithFileUploads;

    public ?string $fileJobId = null;
    public string $jobStatus = 'empty'; // 'empty', 'ready', 'processing', 'completed', 'failed'
    public ?string $errorMessage = null;
    public ?string $statusMessage = null;

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

        $this->resetState();
        session()->flash('status', __('ui.deleted_success'));
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
