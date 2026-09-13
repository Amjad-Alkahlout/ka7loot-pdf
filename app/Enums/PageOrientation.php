<?php

namespace App\Enums;

enum PageOrientation: string
{
    case PORTRAIT = 'portrait';
    case LANDSCAPE = 'landscape';
    case AUTO = 'auto';
}
