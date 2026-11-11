<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FileQuotaReservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'file_job_id',
        'usage_date',
        'subject_type',
        'subject_key',
        'user_id',
        'units',
        'consumed_units',
        'released_units',
        'status',
    ];

    protected $casts = [
        'usage_date' => 'date',
    ];

    public function fileJob(): BelongsTo
    {
        return $this->belongsTo(FileJob::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
