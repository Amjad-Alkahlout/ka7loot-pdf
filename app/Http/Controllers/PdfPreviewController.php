<?php

namespace App\Http\Controllers;

use App\Models\FileJob;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfSplitService;
use App\Services\Pdf\PdfThumbnailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PdfPreviewController extends Controller
{
    /**
     * Serve or lazily generate a crisp JPEG preview for an uploaded or job-associated PDF document page.
     * Enforces strict signed URL verification, session isolation, storage location restriction,
     * PDF magic byte header validation, page bounds checking, and isolated on-disk caching.
     */
    public function show(
        Request $request,
        PdfThumbnailService $thumbService,
        PdfSplitService $splitService
    ): Response {
        // 1. Verify cryptographic signature (enforces expiration and tamper-proofing)
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired preview signature.');
        }

        $filename = (string) ($request->query('filename') ?? $request->route('filename') ?? '');

        // 2. Strict filename validation preventing path traversal
        if (! $filename || ! preg_match('/^[a-zA-Z0-9._=+-]+$/', $filename) || str_contains($filename, '..') || basename($filename) !== $filename) {
            abort(400, 'Invalid filename format.');
        }

        $jobId = $request->query('job_id');
        $expectedSessionId = (string) $request->query('session_id');

        // Session authorization helper
        $isSessionAuthorized = function (?string $expected): bool {
            if (empty($expected)) {
                return false;
            }
            $currentSessionId = session()->getId();
            $storedSessionId = session()->get('preview_session_id');
            return $expected === $currentSessionId || $expected === $storedSessionId;
        };

        // 3. Temporary file preview security: session_id is strictly required when job_id is absent
        if (! $jobId) {
            if (empty($expectedSessionId) || ! $isSessionAuthorized($expectedSessionId)) {
                abort(403, 'Unauthorized preview access.');
            }
        }

        // 4. Job ownership isolation: verify permissions and session binding
        if ($jobId) {
            if (! preg_match('/^[a-f0-9\-]{36}$/i', $jobId)) {
                abort(400, 'Invalid job ID format.');
            }

            $job = FileJob::find($jobId);
            if (! $job) {
                abort(404, 'Associated job not found.');
            }

            if ($job->user_id !== null) {
                // Authenticated user job: verify current auth matches owner
                if (! auth()->check() || (int) $job->user_id !== (int) auth()->id()) {
                    abort(403, 'Unauthorized job preview access.');
                }
            } else {
                // Guest job: enforce matching session binding
                if (empty($expectedSessionId) || ! $isSessionAuthorized($expectedSessionId)) {
                    abort(403, 'Unauthorized guest job preview access.');
                }
            }
        }

        // 5. Restrict file resolution exclusively to authorized storage locations
        $sourcePath = null;

        if ($jobId) {
            // Job files must reside strictly within that specific job's directory
            $storageManager = app(FileStorageManager::class);
            $jobDir = $storageManager->getJobDirectory($jobId);
            $candidate = $jobDir . DIRECTORY_SEPARATOR . $filename;

            if (file_exists($candidate) && is_file($candidate)) {
                $sourcePath = $candidate;
            } else {
                abort(404, 'The processed job PDF file was not found.');
            }
        } else {
            // Temporary uploads must reside strictly within the authorized Livewire temporary directory
            $candidates = [];

            if (class_exists(\Livewire\Features\SupportFileUploads\FileUploadConfiguration::class)) {
                try {
                    $lwStorage = \Livewire\Features\SupportFileUploads\FileUploadConfiguration::storage();
                    $candidates[] = $lwStorage->path(\Livewire\Features\SupportFileUploads\FileUploadConfiguration::path($filename));
                } catch (\Throwable) {
                    // Ignored
                }
            }

            $diskName = config('livewire.temporary_file_upload.disk', 'local');
            $disk = Storage::disk($diskName);
            $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';
            $candidates[] = $disk->path($livewireDir . '/' . $filename);

            foreach ($candidates as $candidate) {
                if (file_exists($candidate) && is_file($candidate)) {
                    $sourcePath = $candidate;
                    break;
                }
            }

            if (! $sourcePath) {
                abort(404, 'The temporary PDF file was not found or has expired.');
            }
        }

        // 6. Strict PDF validation: require actual %PDF- magic bytes
        $magicHeader = @file_get_contents($sourcePath, false, null, 0, 5);
        if ($magicHeader !== '%PDF-') {
            abort(400, 'Invalid PDF document header.');
        }

        // 7. Validate page parameter and bounds
        $page = (int) $request->query('page', 1);
        if ($page < 1) {
            abort(400, 'Page number must be at least 1.');
        }

        try {
            $documentMetadata = $this->getCachedDocumentMetadata($sourcePath, $splitService);
        } catch (\Throwable) {
            abort(422, 'Could not read PDF document page count.');
        }

        $pageCount = $documentMetadata['page_count'];
        if ($page > $pageCount) {
            abort(404, "Page {$page} is out of bounds (document has {$pageCount} pages).");
        }

        // 8. Validate and clamp DPI resolution (50-300 DPI, default: 150)
        $dpi = (int) $request->query('dpi', 150);
        $dpi = max(50, min(300, $dpi));
        $quality = 85;

        // 9. On-disk thumbnail caching with content identity
        $cacheDir = $thumbService->getPreviewCacheDirectory($sourcePath);
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cachedFilePath = $thumbService->getCachedPreviewPath(
            $sourcePath,
            $page,
            $dpi,
            $quality,
            $documentMetadata['content_hash']
        );

        if (! file_exists($cachedFilePath) || filesize($cachedFilePath) === 0) {
            $success = $thumbService->renderPageToFile($sourcePath, $cachedFilePath, $page, $dpi, $quality);

            if (! $success || ! file_exists($cachedFilePath) || filesize($cachedFilePath) === 0) {
                if (file_exists($cachedFilePath)) {
                    @unlink($cachedFilePath);
                }
                return response('PDF preview could not be generated.', 422, ['Content-Type' => 'text/plain']);
            }
        }

        // 10. Stream cached JPEG with secure headers
        return response()->file($cachedFilePath, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Cache expensive PDF inspection by path and filesystem revision. */
    private function getCachedDocumentMetadata(string $sourcePath, PdfSplitService $splitService): array
    {
        clearstatcache(true, $sourcePath);
        $realPath = realpath($sourcePath) ?: $sourcePath;
        $stat = @stat($sourcePath);
        if ($stat === false) {
            throw new \RuntimeException('Could not inspect PDF file metadata.');
        }

        $revision = implode(':', [
            $realPath,
            $stat['size'] ?? '',
            $stat['mtime'] ?? '',
            $stat['ctime'] ?? '',
            $stat['ino'] ?? '',
        ]);
        $cacheKey = 'pdf-preview:document:' . hash('sha256', $revision);

        return Cache::remember($cacheKey, now()->addHour(), function () use ($sourcePath, $splitService): array {
            $contentHash = @hash_file('sha256', $sourcePath);

            return [
                'page_count' => $splitService->getPageCount($sourcePath),
                'content_hash' => is_string($contentHash) ? $contentHash : '0',
            ];
        });
    }

    /**
     * Serve a temporary, session-isolated signature image preview.
     * Enforces signed URL verification, session isolation, filename sanitization,
     * job directory boundary checks, and valid image headers.
     */
    public function showSignature(Request $request, FileStorageManager $storageManager): Response
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired signature preview signature.');
        }

        $filename = (string) ($request->query('filename') ?? '');
        $jobId = (string) ($request->query('job_id') ?? '');
        $expectedSessionId = (string) ($request->query('session_id') ?? '');

        if (! $filename || ! preg_match('/^[a-zA-Z0-9._-]+$/', $filename) || str_contains($filename, '..') || basename($filename) !== $filename) {
            abort(400, 'Invalid filename format.');
        }

        if (! $jobId || ! preg_match('/^[a-f0-9\-]{36}$/i', $jobId)) {
            abort(400, 'Invalid job ID format.');
        }

        $currentSessionId = session()->getId();
        $storedSessionId = session()->get('preview_session_id');
        if (empty($expectedSessionId) || ($expectedSessionId !== $currentSessionId && $expectedSessionId !== $storedSessionId)) {
            abort(403, 'Unauthorized signature preview access.');
        }

        $jobDir = $storageManager->getJobDirectory($jobId);
        $filePath = $jobDir . DIRECTORY_SEPARATOR . 'signatures' . DIRECTORY_SEPARATOR . $filename;

        if (! file_exists($filePath) || ! is_file($filePath)) {
            abort(404, 'Signature image not found.');
        }

        $info = @getimagesize($filePath);
        if (! $info || ! in_array($info['mime'] ?? '', ['image/png', 'image/jpeg', 'image/webp'], true)) {
            abort(400, 'Invalid signature image file.');
        }

        return response()->file($filePath, [
            'Content-Type' => $info['mime'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

