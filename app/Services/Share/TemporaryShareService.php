<?php

namespace App\Services\Share;

use App\Enums\JobStatus;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\TemporaryShare;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class TemporaryShareService
{
    /**
     * Generate a cryptographically secure 64-character random token.
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Compute the SHA-256 hash of a token for secure database storage and lookup.
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Generate the public download URL for a temporary share token based strictly on config('app.url').
     * Never derives host from an internal or request-scoped host.
     */
    public function generatePublicShareUrl(string $token): string
    {
        $baseUrl = rtrim((string) config('app.url', 'http://localhost'), '/');

        return "{$baseUrl}/s/{$token}/download";
    }

    /**
     * Create a new temporary share record and generate its corresponding QR code.
     *
     * @return array{share: TemporaryShare, token: string, url: string, qr_svg: string}
     */
    public function createShare(
        FileJob $job,
        ?ProcessedFile $file = null,
        int $expirationMinutes = 15,
        ?int $maxDownloads = null
    ): array {
        if ($job->status !== JobStatus::COMPLETED) {
            throw new InvalidArgumentException("Cannot create a share for an incomplete job.");
        }

        if ($job->isExpired() || ($job->expires_at && $job->expires_at->isPast())) {
            throw new InvalidArgumentException("Cannot create a share for an expired job.");
        }

        if ($file !== null && $file->file_job_id !== $job->id) {
            throw new InvalidArgumentException("Processed file does not belong to the given job.");
        }

        $targetFile = $file ?? $job->processedFiles()->first();
        if (!$targetFile) {
            throw new RuntimeException("No processed file found to share.");
        }

        // Clamp expiration between 1 and 60 minutes, capped by the job's remaining lifetime
        $clampedMinutes = max(1, min(60, $expirationMinutes));
        $expiresAt = now()->addMinutes($clampedMinutes);
        if ($job->expires_at && $expiresAt->isAfter($job->expires_at)) {
            $expiresAt = $job->expires_at;
        }

        $effectiveMaxDownloads = ($maxDownloads !== null && $maxDownloads > 0) ? $maxDownloads : null;

        $token = $this->generateToken();
        $tokenHash = $this->hashToken($token);

        $share = TemporaryShare::create([
            'file_job_id'       => $job->id,
            'processed_file_id' => $targetFile->id,
            'token_hash'        => $tokenHash,
            'expires_at'        => $expiresAt,
            'max_downloads'     => $effectiveMaxDownloads,
            'download_count'    => 0,
        ]);

        $url = $this->generatePublicShareUrl($token);
        $qrSvg = $this->generateQrSvg($url);

        return [
            'share'  => $share,
            'token'  => $token,
            'url'    => $url,
            'qr_svg' => $qrSvg,
        ];
    }

    /**
     * Locate a share by its plaintext token.
     */
    public function findShareByToken(string $token): ?TemporaryShare
    {
        if (empty($token) || strlen($token) !== 64) {
            return null;
        }

        $tokenHash = $this->hashToken($token);

        return TemporaryShare::with(['fileJob', 'processedFile'])
            ->where('token_hash', $tokenHash)
            ->first();
    }

    /**
     * Validate share state (revocation, expiration, download limit, job status).
     *
     * @return array{valid: bool, error: ?string, status_code: int}
     */
    public function validateShare(?TemporaryShare $share): array
    {
        if (!$share) {
            return [
                'valid'       => false,
                'error'       => __('tools.share.not_found'),
                'status_code' => 404,
            ];
        }

        if ($share->isRevoked()) {
            return [
                'valid'       => false,
                'error'       => __('tools.share.revoked'),
                'status_code' => 410,
            ];
        }

        if ($share->isExpired()) {
            return [
                'valid'       => false,
                'error'       => __('tools.share.expired'),
                'status_code' => 410,
            ];
        }

        if ($share->isDownloadLimitReached()) {
            return [
                'valid'       => false,
                'error'       => __('tools.share.limit_reached'),
                'status_code' => 410,
            ];
        }

        $job = $share->fileJob;
        if (!$job || $job->isExpired() || $job->status === JobStatus::EXPIRED || $job->status !== JobStatus::COMPLETED) {
            return [
                'valid'       => false,
                'error'       => __('tools.share.file_expired'),
                'status_code' => 410,
            ];
        }

        return [
            'valid'       => true,
            'error'       => null,
            'status_code' => 200,
        ];
    }

    /**
     * Atomically increment download count using database-level conditions.
     * Prevents race conditions from exceeding max_downloads.
     */
    public function incrementDownloadCountAtomically(TemporaryShare $share): bool
    {
        $query = DB::table('temporary_shares')
            ->where('id', $share->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());

        if ($share->max_downloads !== null) {
            $query->where('download_count', '<', $share->max_downloads);
        }

        $affected = $query->increment('download_count');

        if ($affected > 0) {
            $share->refresh();
            return true;
        }

        return false;
    }

    /**
     * Revoke a temporary share immediately.
     */
    public function revokeShare(TemporaryShare|string $shareOrId): bool
    {
        $share = is_string($shareOrId) ? TemporaryShare::find($shareOrId) : $shareOrId;

        if (!$share) {
            return false;
        }

        $share->update([
            'revoked_at' => now(),
        ]);

        return true;
    }

    /**
     * Generate an SVG QR code string using bacon/bacon-qr-code.
     */
    public function generateQrSvg(string $content, int $size = 240): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 1),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        return $writer->writeString($content);
    }
}
