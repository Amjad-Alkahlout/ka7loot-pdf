<?php

namespace App\Enums;

enum PageSize: string
{
    case A4 = 'a4';
    case LETTER = 'letter';
    case LEGAL = 'legal';
    case FIT = 'fit';

    /**
     * Return width and height in mm (portrait).
     */
    public function dimensions(): ?array
    {
        return match ($this) {
            self::A4 => [210.0, 297.0],
            self::LETTER => [215.9, 279.4],
            self::LEGAL => [215.9, 355.6],
            self::FIT => null, // Dynamic according to image dimensions
        };
    }
}
