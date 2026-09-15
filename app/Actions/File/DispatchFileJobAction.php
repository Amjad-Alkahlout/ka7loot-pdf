<?php

namespace App\Actions\File;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Jobs\ProcessFileJob;
use App\Models\FileJob;

class DispatchFileJobAction
{
    /**
     * Determine whether to run synchronously or dispatch to background queue, then execute.
     *
     * @param FileJob $job
     * @param int $totalSizeKb
     * @param int|null $pageCount
     * @return bool True if executed synchronously, false if dispatched to queue
     */
    public function execute(FileJob $job, int $totalSizeKb = 0, ?int $pageCount = null): bool
    {
        $syncThresholdKb = (int) config('fileprocessor.queues.sync_threshold_size_kb', 10240);
        $syncThresholdPages = (int) config('fileprocessor.queues.sync_threshold_pages', 5);

        $isHeavyTool = $job->tool_type->isHeavy();
        $isLargeFile = $totalSizeKb > $syncThresholdKb;
        $hasManyPages = $pageCount !== null && $pageCount > $syncThresholdPages;

        $shouldQueue = $isHeavyTool || $isLargeFile || $hasManyPages;

        if ($shouldQueue) {
            $queueName = config('fileprocessor.queues.default_queue', 'default');
            ProcessFileJob::dispatch($job->id)->onQueue($queueName);
            return false;
        }

        // Execute synchronously for instant interactive response
        ProcessFileJob::dispatchSync($job->id);
        return true;
    }
}
