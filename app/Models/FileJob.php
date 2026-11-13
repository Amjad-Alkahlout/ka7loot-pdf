<?php

namespace App\Models;

use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Services\File\DailyFileQuotaService;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
            if (empty($job->user_id) && auth()->check()) {
                $job->user_id = auth()->id();
            }

            $metadata = $job->input_metadata ?? [];

            // Guest jobs have no user_id, so bind their Livewire-visible job IDs
            // to the session that created them. This keeps a leaked/tampered UUID
            // from authorizing another guest session.
            if ($job->user_id === null) {
                $metadata['guest_session_key'] ??= self::currentGuestSessionKey();
            }

            // Keep the per-job metadata useful for analytics without retaining a
            // raw client IP. ProcessFileJob reads the hash when it settles usage.
            if (array_key_exists('ip', $metadata)) {
                $metadata['ip_hash'] ??= hash('sha256', (string) $metadata['ip'] . config('app.key'));
                unset($metadata['ip']);
            }

            $job->input_metadata = $metadata;

            if (empty($job->download_token)) {
                $job->download_token = Str::random(48);
            }
            if (empty($job->expires_at)) {
                $retentionMinutes = config('fileprocessor.retention.file_retention_minutes', 60);
                $job->expires_at = now()->addMinutes($retentionMinutes);
            }
        });

        static::created(function (FileJob $job) {
            try {
                app(DailyFileQuotaService::class)->reserveForJob($job);
            } catch (\Throwable $e) {
                $job->deleteQuietly();
                throw $e;
            }

            if ($job->status === JobStatus::COMPLETED) {
                app(DailyFileQuotaService::class)->settle($job);
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

    public function quotaReservation(): HasOne
    {
        return $this->hasOne(FileQuotaReservation::class);
    }

    public function isExpired(): bool
    {
        return $this->status === JobStatus::EXPIRED || $this->expires_at->isPast();
    }

    public static function currentGuestSessionKey(): string
    {
        $key = session()->get('guest_file_job_key');

        if (! is_string($key) || $key === '') {
            $key = Str::uuid()->toString();
            session()->put('guest_file_job_key', $key);
        }

        return $key;
    }
}
