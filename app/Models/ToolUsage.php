<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ToolUsage extends Model
{
    public $timestamps = false;
    protected $table = 'tool_usage';

    protected $fillable = [
        'tool_type',
        'ip_hash',
        'is_successful',
        'bytes_processed',
        'duration_ms',
        'created_at',
    ];

    protected $casts = [
        'is_successful' => 'boolean',
        'bytes_processed' => 'integer',
        'duration_ms' => 'integer',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ToolUsage $usage) {
            if (empty($usage->created_at)) {
                $usage->created_at = now();
            }
        });
    }

    public static function log(string $toolType, string $ip, bool $successful, int $bytes, int $durationMs): self
    {
        return self::create([
            'tool_type' => $toolType,
            'ip_hash' => hash('sha256', $ip . config('app.key')),
            'is_successful' => $successful,
            'bytes_processed' => $bytes,
            'duration_ms' => $durationMs,
            'created_at' => now(),
        ]);
    }
}
