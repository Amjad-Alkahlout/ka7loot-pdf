<?php

namespace App\Http\Controllers;

use App\Services\File\FileStorageManager;
use App\Services\Share\TemporaryShareService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicShareDownloadController extends Controller
{
    public function __construct(
        protected TemporaryShareService $shareService,
        protected FileStorageManager $storage
    ) {}

    /**
     * Show / redirect to the download endpoint for the shared file.
     */
    public function show(Request $request, string $token)
    {
        $share = $this->shareService->findShareByToken($token);

        if (!$share) {
            abort(Response::HTTP_NOT_FOUND, __('tools.share.not_found'));
        }

        $validation = $this->shareService->validateShare($share);
        if (!$validation['valid']) {
            abort($validation['status_code'], $validation['error']);
        }

        return redirect()->route('share.download', ['token' => $token]);
    }

    /**
     * Download the shared file via its secure opaque token.
     */
    public function download(Request $request, string $token)
    {
        $share = $this->shareService->findShareByToken($token);

        if (!$share) {
            abort(Response::HTTP_NOT_FOUND, __('tools.share.not_found'));
        }

        $validation = $this->shareService->validateShare($share);
        if (!$validation['valid']) {
            abort($validation['status_code'], $validation['error']);
        }

        // Atomically increment download count with DB-level limit check
        $incremented = $this->shareService->incrementDownloadCountAtomically($share);
        if (!$incremented) {
            abort(Response::HTTP_GONE, __('tools.share.limit_reached'));
        }

        $job = $share->fileJob;
        $processedFile = $share->processedFile ?? $job->processedFiles()->first();

        if (!$processedFile) {
            abort(Response::HTTP_GONE, __('tools.share.file_expired'));
        }

        $filePath = $this->storage->getJobDirectory($job->id) . DIRECTORY_SEPARATOR . basename($processedFile->stored_filename);

        if (!file_exists($filePath)) {
            abort(Response::HTTP_GONE, __('tools.share.file_expired'));
        }

        return response()->download($filePath, $processedFile->original_name, [
            'Content-Type'  => $processedFile->mime_type,
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma'        => 'no-cache',
        ]);
    }

    /**
     * Return QR code SVG (named route alias for share.qr).
     */
    public function qrCode(string $token)
    {
        return $this->downloadQr($token);
    }

    /**
     * Download the QR code directly as an SVG image.
     */
    public function downloadQr(string $token)
    {
        $share = $this->shareService->findShareByToken($token);

        if (!$share) {
            abort(Response::HTTP_NOT_FOUND, __('tools.share.not_found'));
        }

        $validation = $this->shareService->validateShare($share);
        if (!$validation['valid']) {
            abort($validation['status_code'], $validation['error']);
        }

        $url = $this->shareService->generatePublicShareUrl($token);
        $svg = $this->shareService->generateQrSvg($url);

        return response($svg, Response::HTTP_OK, [
            'Content-Type'        => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="share-qr.svg"',
            'Cache-Control'       => 'no-store, no-cache, must-revalidate',
        ]);
    }
}
