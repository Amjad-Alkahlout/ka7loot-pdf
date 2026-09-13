<?php

namespace App\Enums;

enum JobStatus: string
{
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case EXPIRED = 'expired';

    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::EXPIRED], true);
    }
}
