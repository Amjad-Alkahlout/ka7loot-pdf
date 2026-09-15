<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcessedFile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'file_job_id',
        'original_name',
        'stored_filename',
        'disk',
        'mime_type',
        'original_size_bytes',
        'processed_size_bytes',
        'download_count',
    ];

    protected $casts = [
        'original_size_bytes' => 'integer',
        'processed_size_bytes' => 'integer',
        'download_count' => 'integer',
    ];

    public function fileJob(): BelongsTo
    {
        return $this->belongsTo(FileJob::class);
    }

    public function getSavingsPercentageAttribute(): float
    {
        if ($this->original_size_bytes <= 0 || $this->processed_size_bytes >= $this->original_size_bytes) {
            return 0.0;
        }

        return round((($this->original_size_bytes - $this->processed_size_bytes) / $this->original_size_bytes) * 100, 1);
    }

    public function savingsPercent(): float
    {
        return $this->savings_percentage;
    }
}
