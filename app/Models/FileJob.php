<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class FileJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'tool_type',
        'status',
        'input_metadata',
        'download_token',
        'expires_at',
        'duration_ms',
        'error_message',
    ];

    protected $casts = [
        'tool_type' => ToolType::class,
        'status' => JobStatus::class,
        'input_metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (FileJob $job) {
            if (empty($job->download_token)) {
                $job->download_token = Str::random(48);
            }
            if (empty($job->expires_at)) {
                $retentionMinutes = config('fileprocessor.retention.file_retention_minutes', 60);
                $job->expires_at = now()->addMinutes($retentionMinutes);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function processedFiles(): HasMany
    {
        return $this->hasMany(ProcessedFile::class);
    }

    public function isExpired(): bool
    {
        return $this->status === JobStatus::EXPIRED || $this->expires_at->isPast();
    }
}
