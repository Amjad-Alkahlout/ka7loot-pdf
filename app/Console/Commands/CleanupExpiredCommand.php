<?php

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\TemporaryShare;
use App\Models\ToolUsage;
use App\Services\File\FileStorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredCommand extends Command
{
    protected $signature = 'app:cleanup-expired {--force : Force cleanup without asking confirmation}';
    protected $description = 'Prune expired temporary files and purge historical usage analytics';

    public function handle(FileStorageManager $storage): int
    {
        $this->info('Starting scheduled cleanup of expired files and usage analytics...');

        $deletedJobsCount = 0;
        $deletedUsageCount = 0;
        $deletedSharesCount = 0;

        // 1. Process expired FileJobs from database
        $expiredJobs = FileJob::where('expires_at', '<', now())
            ->where('status', '!=', JobStatus::EXPIRED)
            ->get();

        foreach ($expiredJobs as $job) {
            try {
                $storage->deleteJobDirectory($job->id);
                $job->update([
                    'status' => JobStatus::EXPIRED,
                ]);
                $deletedJobsCount++;
            } catch (\Throwable $e) {
                Log::warning("Failed to clean storage for expired job {$job->id}: {$e->getMessage()}");
            }
        }

        // 2. Scan disk for orphan directories older than configured retention
        $retentionMinutes = config('fileprocessor.retention.file_retention_minutes', 60);
        $rootPath = $storage->getRootPath();
        if (is_dir($rootPath)) {
            $entries = scandir($rootPath);
            $cutoff = time() - ($retentionMinutes * 60);
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $entryPath = $rootPath . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($entryPath) && filemtime($entryPath) < $cutoff) {
                    // Orphan or abandoned directory
                    try {
                        $this->deleteDirectoryRecursively($entryPath);
                        $deletedJobsCount++;
                    } catch (\Throwable $e) {
                        Log::warning("Failed to remove orphan temp directory {$entryPath}: {$e->getMessage()}");
                    }
                }
            }
        }

        // 3. Invalidate or clean expired / revoked temporary shares
        try {
            $deletedSharesCount = TemporaryShare::where('expires_at', '<', now())
                ->orWhereNotNull('revoked_at')
                ->orWhereDoesntHave('fileJob')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning("Failed to purge expired temporary shares: {$e->getMessage()}");
        }

        // 4. Purge historical usage analytics older than configured days
        $retentionDays = config('fileprocessor.retention.analytics_retention_days', 90);
        $analyticsCutoff = now()->subDays($retentionDays);
        $deletedUsageCount = ToolUsage::where('created_at', '<', $analyticsCutoff)->delete();

        $this->info("Cleanup completed: {$deletedJobsCount} expired/orphan job directories cleaned, {$deletedSharesCount} temporary shares purged, {$deletedUsageCount} old analytics records purged.");

        return Command::SUCCESS;
    }

    protected function deleteDirectoryRecursively(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (!$this->deleteDirectoryRecursively($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return rmdir($dir);
    }
}
