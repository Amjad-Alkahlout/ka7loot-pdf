<?php

namespace App\Exceptions;

use RuntimeException;

class DailyQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $remaining,
        public readonly int $limit,
    ) {
        parent::__construct(__('ui.daily_quota_exceeded', [
            'remaining' => $remaining,
            'limit' => $limit,
        ]));
    }
}
