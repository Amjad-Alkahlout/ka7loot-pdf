<?php

namespace App\Http\Controllers;

use App\Services\Image\HeicConverterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class HeicPreviewController extends Controller
{
    /**
     * Serve or lazily generate a small JPEG thumbnail preview for an uploaded HEIC temporary file.
     */
    public function show(Request $request, HeicConverterService $converter): Response
    {
        // 1. Verify cryptographic signature (enforces 1-hour expiration and tamper-proofing)
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired preview signature.');
        }

        $filename = (string) ($request->query('filename') ?? $request->route('filename') ?? '');

        // 2. Strict filename validation preventing path traversal (allows base64 padding '=' and '+')
        if (! $filename || ! preg_match('/^[a-zA-Z0-9._=+-]+$/', $filename) || str_contains($filename, '..') || basename($filename) !== $filename) {
            abort(400, 'Invalid filename format.');
        }

        // 3. User session isolation: must match the session that uploaded it if session_id is provided
        $expectedSessionId = $request->query('session_id');
        if ($expectedSessionId) {
            $currentSessionId = session()->getId();
            $storedSessionId = session()->get('preview_session_id');
            if ($expectedSessionId !== $currentSessionId && $expectedSessionId !== $storedSessionId) {
                abort(403, 'Unauthorized preview access.');
            }
        }

        $diskName = config('livewire.temporary_file_upload.disk', 'local');
        $disk = Storage::disk($diskName);
        $livewireDir = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';

        // Locate source HEIC file across standard temporary upload locations
        $candidates = [
            $disk->path($livewireDir . '/' . $filename),
            $disk->path($filename),
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename,
        ];

        if (class_exists(\Livewire\Features\SupportFileUploads\FileUploadConfiguration::class)) {
            try {
                $lwStorage = \Livewire\Features\SupportFileUploads\FileUploadConfiguration::storage();
                $lwPath = $lwStorage->path(\Livewire\Features\SupportFileUploads\FileUploadConfiguration::path($filename));
                array_unshift($candidates, $lwPath);
            } catch (\Throwable) {
                // Ignored if Livewire storage call fails
            }
        }

        $sourcePath = null;
        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && is_file($candidate)) {
                $sourcePath = $candidate;
                break;
            }
        }

        if (! $sourcePath) {
            abort(404, 'The temporary HEIC file was not found or has expired.');
        }

        // 4. Cached thumbnail file path in the same temporary directory
        $thumbName = 'thumb_' . md5($filename) . '.jpg';
        $thumbPath = dirname($sourcePath) . DIRECTORY_SEPARATOR . $thumbName;

        // 5. Convert lazily on demand if thumbnail does not already exist
        if (! file_exists($thumbPath) || filesize($thumbPath) === 0) {
            $tempJpeg = $thumbPath . '.raw.jpg';
            try {
                // Convert HEIC to intermediate JPEG
                $converter->convertToJpeg($sourcePath, $tempJpeg, 70);

                // Resize down to ~200px max dimension using GD for fast client loading & lightweight payload
                if (extension_loaded('gd') && is_file($tempJpeg) && ($srcImg = @imagecreatefromjpeg($tempJpeg))) {
                    $origW = imagesx($srcImg);
                    $origH = imagesy($srcImg);
                    $maxDim = 200;
                    $scale = min($maxDim / max(1, $origW), $maxDim / max(1, $origH), 1.0);
                    $thumbW = max(1, (int) round($origW * $scale));
                    $thumbH = max(1, (int) round($origH * $scale));

                    $thumbImg = imagecreatetruecolor($thumbW, $thumbH);
                    imagecopyresampled($thumbImg, $srcImg, 0, 0, 0, 0, $thumbW, $thumbH, $origW, $origH);
                    imagejpeg($thumbImg, $thumbPath, 75);

                    imagedestroy($srcImg);
                    imagedestroy($thumbImg);
                    @unlink($tempJpeg);
                } else {
                    @rename($tempFull ?? $tempJpeg, $thumbPath);
                }
            } catch (\Throwable $e) {
                if (file_exists($tempJpeg)) {
                    @unlink($tempJpeg);
                }
                // If conversion fails (e.g. corrupt or invalid HEIC), return 422
                // The client-side catches this and gracefully renders the fallback icon
                return response('HEIC preview could not be generated.', 422, ['Content-Type' => 'text/plain']);
            }
        }

        return response()->file($thumbPath, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
