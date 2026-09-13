<?php

namespace App\Actions\File;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SanitizeUploadedFileAction
{
    /**
     * Allowed MIME types and corresponding canonical extensions.
     */
    protected const ALLOWED_TYPES = [
        'application/pdf' => ['pdf'],
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'image/gif'       => ['gif'],
        'image/bmp'       => ['bmp'],
    ];

    /**
     * Execute file sanitization and validation.
     *
     * @param UploadedFile $file
     * @param array<string> $allowedMimeTypes Optional whitelist restriction (e.g. ['application/pdf'])
     * @return array{
     *     original_name: string,
     *     safe_filename: string,
     *     mime_type: string,
     *     extension: string,
     *     size_bytes: int
     * }
     * @throws ValidationException
     */
    public function execute(UploadedFile $file, array $allowedMimeTypes = []): array
    {
        if (!$file->isValid()) {
            throw ValidationException::withMessages([
                'file' => [__('ui.error_upload_corrupt', ['default' => 'The uploaded file is corrupt or incomplete.'])]
            ]);
        }

        $realPath = $file->getRealPath();
        if (!$realPath || !file_exists($realPath)) {
            throw ValidationException::withMessages([
                'file' => [__('ui.error_upload_unreadable', ['default' => 'Unable to read the uploaded file.'])]
            ]);
        }

        // 1. Magic-byte MIME detection
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath) ?: $file->getMimeType();
        finfo_close($finfo);

        // Normalize jpeg alias
        if ($detectedMime === 'image/pjpeg' || $detectedMime === 'image/jpg') {
            $detectedMime = 'image/jpeg';
        }

        // 2. Validate against global allowed types
        if (!array_key_exists($detectedMime, self::ALLOWED_TYPES)) {
            throw ValidationException::withMessages([
                'file' => [__('ui.error_unsupported_format', ['default' => 'Unsupported file format.'])]
            ]);
        }

        // 3. Validate against tool-specific whitelist if provided
        if (!empty($allowedMimeTypes) && !in_array($detectedMime, $allowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'file' => [__('ui.error_format_not_allowed_for_tool', ['default' => 'This file type is not allowed for this tool.'])]
            ]);
        }

        // 4. Validate extension matches real MIME type
        $clientExt = strtolower($file->getClientOriginalExtension());
        $validExtensions = self::ALLOWED_TYPES[$detectedMime];
        if (!in_array($clientExt, $validExtensions, true)) {
            // Discrepancy between magic bytes and client extension (e.g. .exe renamed to .pdf)
            throw ValidationException::withMessages([
                'file' => [__('ui.error_extension_mismatch', ['default' => 'File extension does not match its genuine content.'])]
            ]);
        }

        // 5. Enforce configured size limits
        $sizeBytes = $file->getSize();
        if ($detectedMime === 'application/pdf') {
            $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);
            if ($sizeBytes > ($maxPdfKb * 1024)) {
                throw ValidationException::withMessages([
                    'file' => [__('ui.error_pdf_too_large', ['default' => "PDF exceeds maximum allowed size of {$maxPdfKb} KB."])]
                ]);
            }
        } else {
            $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);
            if ($sizeBytes > ($maxImageKb * 1024)) {
                throw ValidationException::withMessages([
                    'file' => [__('ui.error_image_too_large', ['default' => "Image exceeds maximum allowed size of {$maxImageKb} KB."])]
                ]);
            }
        }

        // 6. Sanitize original filename (strip path separators, null bytes, control characters)
        $rawName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $cleanBase = preg_replace('/[^a-zA-Z0-9_\-\s]/', '', $rawName);
        $cleanBase = trim(substr($cleanBase, 0, 100)) ?: 'document';
        $sanitizedOriginalName = $cleanBase . '.' . $clientExt;

        // 7. Cryptographically secure random filename for storage
        $safeStorageFilename = Str::uuid()->toString() . '.' . $clientExt;

        return [
            'original_name' => $sanitizedOriginalName,
            'safe_filename' => $safeStorageFilename,
            'mime_type'     => $detectedMime,
            'extension'     => $clientExt,
            'size_bytes'    => $sizeBytes,
        ];
    }
}
