<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyFileQuota extends Model
{
    protected $fillable = [
        'usage_date',
        'subject_type',
        'subject_key',
        'user_id',
        'reserved_count',
        'consumed_count',
        'released_count',
        'guest_sign_in_count',
    ];

    protected $casts = [
        'usage_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
