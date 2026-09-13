<?php

namespace App\Services\File;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class FileStorageManager
{
    protected string $disk;
    protected string $baseTempPath;

    public function __construct()
    {
        $this->disk = config('fileprocessor.storage.disk', 'local');
        $this->baseTempPath = config('fileprocessor.storage.temp_path', 'secure_temp');
    }

    /**
     * Get the root absolute path of the secure temp directory.
     */
    public function getRootPath(): string
    {
        return Storage::disk($this->disk)->path($this->baseTempPath);
    }

    /**
     * Ensure the job directory exists and return its absolute path.
     */
    public function getJobDirectory(string $jobId): string
    {
        $this->validateUuid($jobId);

        $relativeJobPath = $this->baseTempPath . DIRECTORY_SEPARATOR . $jobId;
        $diskInstance = Storage::disk($this->disk);

        if (!$diskInstance->exists($relativeJobPath)) {
            $diskInstance->makeDirectory($relativeJobPath);
        }

        $fullPath = $diskInstance->path($relativeJobPath);
        $this->assertWithinRoot($fullPath);

        return $fullPath;
    }

    /**
     * Store an uploaded file into the job's directory.
     *
     * @return array{stored_path: string, absolute_path: string}
     */
    public function storeUploadedFile(UploadedFile $file, string $jobId, string $safeFilename): array
    {
        $jobDir = $this->getJobDirectory($jobId);
        $targetPath = $jobDir . DIRECTORY_SEPARATOR . $safeFilename;
        $this->assertWithinRoot($targetPath);

        if (!move_uploaded_file($file->getRealPath(), $targetPath)) {
            // Fallback to copy if move_uploaded_file fails (e.g. testing)
            if (!copy($file->getRealPath(), $targetPath)) {
                throw new RuntimeException("Failed to persist uploaded file to secure temporary storage.");
            }
        }

        $relativeStoredPath = $this->baseTempPath . '/' . $jobId . '/' . $safeFilename;

        return [
            'stored_path'   => $relativeStoredPath,
            'absolute_path' => $targetPath,
        ];
    }

    /**
     * Store raw string contents to a file in the job's directory.
     */
    public function putFileContents(string $jobId, string $filename, string $contents): string
    {
        $jobDir = $this->getJobDirectory($jobId);
        $targetPath = $jobDir . DIRECTORY_SEPARATOR . $filename;
        $this->assertWithinRoot($targetPath);

        file_put_contents($targetPath, $contents);

        return $targetPath;
    }

    /**
     * Generate an absolute path for an output file within the job directory.
     */
    public function generateOutputPath(string $jobId, string $extension, ?string $prefix = null): string
    {
        $jobDir = $this->getJobDirectory($jobId);
        $filename = ($prefix ? $prefix . '_' : '') . Str::uuid()->toString() . '.' . ltrim($extension, '.');
        $targetPath = $jobDir . DIRECTORY_SEPARATOR . $filename;
        $this->assertWithinRoot($targetPath);

        return $targetPath;
    }

    /**
     * Resolve and validate an existing file path within a job.
     */
    public function resolveFilePath(string $jobId, string $filename): string
    {
        $jobDir = $this->getJobDirectory($jobId);
        $fullPath = $jobDir . DIRECTORY_SEPARATOR . basename($filename);
        $this->assertWithinRoot($fullPath);

        if (!file_exists($fullPath)) {
            throw new RuntimeException("Target file not found in job storage: {$filename}");
        }

        return $fullPath;
    }

    /**
     * Completely clean up and remove a job's temporary directory.
     */
    public function deleteJobDirectory(string $jobId): bool
    {
        $this->validateUuid($jobId);
        $relativeJobPath = $this->baseTempPath . '/' . $jobId;

        return Storage::disk($this->disk)->deleteDirectory($relativeJobPath);
    }

    /**
     * Strict path traversal check.
     */
    protected function assertWithinRoot(string $path): void
    {
        $root = realpath($this->getRootPath());
        if (!$root) {
            // Root may not exist yet on disk; create it
            Storage::disk($this->disk)->makeDirectory($this->baseTempPath);
            $root = realpath($this->getRootPath());
        }

        $normalized = realpath(dirname($path));
        if ($normalized === false || !str_starts_with($normalized, $root)) {
            throw new InvalidArgumentException("Security Exception: Path traversal attempt detected.");
        }
    }

    protected function validateUuid(string $uuid): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw new InvalidArgumentException("Invalid job identifier format.");
        }
    }
}
