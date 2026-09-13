<?php

namespace App\Http\Controllers;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Services\File\FileStorageManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FileDownloadController extends Controller
{
    public function __construct(
        protected FileStorageManager $storage
    ) {}

    /**
     * Download a processed file securely.
     */
    public function download(string $token, string $fileId)
    {
        $job = FileJob::where('download_token', $token)->first();

        if (!$job) {
            abort(Response::HTTP_NOT_FOUND, 'File job not found.');
        }

        if ($job->isExpired() || $job->status === JobStatus::EXPIRED) {
            abort(Response::HTTP_GONE, __('ui.expired_notice'));
        }

        if ($job->status !== JobStatus::COMPLETED) {
            abort(Response::HTTP_BAD_REQUEST, 'Job has not completed yet.');
        }

        $processedFile = ProcessedFile::where('file_job_id', $job->id)
            ->where('id', $fileId)
            ->first();

        if (!$processedFile) {
            abort(Response::HTTP_NOT_FOUND, 'Requested file record not found.');
        }

        $filePath = $this->storage->getJobDirectory($job->id) . DIRECTORY_SEPARATOR . $processedFile->stored_filename;

        if (!file_exists($filePath)) {
            abort(Response::HTTP_NOT_FOUND, 'File not found on disk.');
        }

        $processedFile->increment('download_count');

        return response()->download($filePath, $processedFile->original_name, [
            'Content-Type' => $processedFile->mime_type,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Delete files immediately from server.
     */
    public function deleteNow(Request $request, string $token)
    {
        $job = FileJob::where('download_token', $token)->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found.'], Response::HTTP_NOT_FOUND);
        }

        // Delete from disk
        $this->storage->deleteJobDirectory($job->id);

        // Mark as expired immediately
        $job->update([
            'status' => JobStatus::EXPIRED,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('ui.deleted_success'),
            ]);
        }

        return back()->with('status', __('ui.deleted_success'));
    }

    /**
     * Switch user language and store in session.
     */
    public function switchLocale(string $locale)
    {
        if (in_array($locale, ['en', 'ar'], true)) {
            session(['locale' => $locale]);
        }

        return redirect()->back();
    }
}
