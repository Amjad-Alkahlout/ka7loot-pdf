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
        'image/heic'       => ['heic', 'heif'],
        'image/heif'       => ['heic', 'heif'],
        'image/heic-sequence' => ['heic', 'heif'],
        'image/heif-sequence' => ['heic', 'heif'],
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
                'file' => [__('ui.error_upload_corrupt')]
            ]);
        }

        $realPath = $file->getRealPath();
        if (!$realPath || !file_exists($realPath)) {
            throw ValidationException::withMessages([
                'file' => [__('ui.error_upload_unreadable')]
            ]);
        }

        // 1. Magic-byte MIME detection
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $realPath) ?: $file->getMimeType();
        finfo_close($finfo);

        if (
            in_array($detectedMime, ['application/octet-stream', 'application/octetstream', 'video/mp4', 'application/mp4'], true)
            || str_starts_with($detectedMime, 'image/')
        ) {
            $detectedMime = $this->detectHeicMime($realPath) ?? $detectedMime;
        }

        // Normalize jpeg alias
        if ($detectedMime === 'image/pjpeg' || $detectedMime === 'image/jpg') {
            $detectedMime = 'image/jpeg';
        }

        // 2. Validate against global allowed types
        if (!array_key_exists($detectedMime, self::ALLOWED_TYPES)) {
            throw ValidationException::withMessages([
                'file' => [__('ui.error_unsupported_format')]
            ]);
        }

        // 3. Validate against tool-specific whitelist if provided
        if (!empty($allowedMimeTypes) && !in_array($detectedMime, $allowedMimeTypes, true)) {
            throw ValidationException::withMessages([
                'file' => [__('ui.error_format_not_allowed_for_tool')]
            ]);
        }

        // 4. Validate extension matches real MIME type
        $clientExt = strtolower($file->getClientOriginalExtension());
        $validExtensions = self::ALLOWED_TYPES[$detectedMime];
        if (!in_array($clientExt, $validExtensions, true)) {
            // Discrepancy between magic bytes and client extension (e.g. .exe renamed to .pdf)
            throw ValidationException::withMessages([
                'file' => [__('ui.error_extension_mismatch')]
            ]);
        }

        // 5. Enforce configured size limits
        $sizeBytes = $file->getSize();
        if ($detectedMime === 'application/pdf') {
            $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);
            if ($sizeBytes > ($maxPdfKb * 1024)) {
                $maxMb = (int) round($maxPdfKb / 1024);
                throw ValidationException::withMessages([
                    'file' => [__('ui.error_pdf_too_large', ['size' => $maxMb])]
                ]);
            }
        } else {
            $maxImageKb = config('fileprocessor.limits.max_image_size_kb', 25600);
            if ($sizeBytes > ($maxImageKb * 1024)) {
                $maxMb = (int) round($maxImageKb / 1024);
                throw ValidationException::withMessages([
                    'file' => [__('ui.error_image_too_large', ['size' => $maxMb])]
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

    /**
     * Detect supported HEIC/HEIF brands in a valid ISO Base Media File header.
     */
    protected function detectHeicMime(string $path): ?string
    {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return null;
        }

        try {
            $header = fread($handle, 32);
        } finally {
            fclose($handle);
        }

        if ($header === false || strlen($header) < 12 || substr($header, 4, 4) !== 'ftyp') {
            return null;
        }

        $brands = [];
        $majorBrand = substr($header, 8, 4);
        if ($majorBrand !== false) {
            $brands[] = $majorBrand;
        }

        $minorAndCompatible = substr($header, 16);
        for ($offset = 0; $offset + 4 <= strlen($minorAndCompatible); $offset += 4) {
            $brands[] = substr($minorAndCompatible, $offset, 4);
        }

        foreach ($brands as $brand) {
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'], true)) {
                return str_starts_with($brand, 'mif') || str_starts_with($brand, 'msf')
                    ? 'image/heif'
                    : 'image/heic';
            }
        }

        return null;
    }
}
