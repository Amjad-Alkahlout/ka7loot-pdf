<?php

namespace App\Enums;

enum CompressionLevel: string
{
    case LOW = 'low';
    case RECOMMENDED = 'recommended';
    case EXTREME = 'extreme';

    public function gsSetting(): string
    {
        return match ($this) {
            self::LOW => '/printer',         // 300 dpi
            self::RECOMMENDED => '/ebook',    // 150 dpi
            self::EXTREME => '/screen',       // 72 dpi
        };
    }

    public function imageQuality(): int
    {
        return match ($this) {
            self::LOW => 85,
            self::RECOMMENDED => 70,
            self::EXTREME => 50,
        };
    }
}
