<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryShare extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'file_job_id',
        'processed_file_id',
        'token_hash',
        'expires_at',
        'max_downloads',
        'download_count',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at'     => 'datetime',
        'revoked_at'     => 'datetime',
        'max_downloads'  => 'integer',
        'download_count' => 'integer',
    ];

    public function fileJob(): BelongsTo
    {
        return $this->belongsTo(FileJob::class);
    }

    public function processedFile(): BelongsTo
    {
        return $this->belongsTo(ProcessedFile::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isDownloadLimitReached(): bool
    {
        return $this->max_downloads !== null && $this->download_count >= $this->max_downloads;
    }

    public function isValid(): bool
    {
        return !$this->isExpired() && !$this->isRevoked() && !$this->isDownloadLimitReached();
    }

    public function getRemainingDownloadsAttribute(): ?int
    {
        if ($this->max_downloads === null) {
            return null;
        }

        return max(0, $this->max_downloads - $this->download_count);
    }
}
