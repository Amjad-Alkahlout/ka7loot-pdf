<?php

namespace App\Enums;

enum ImageFit: string
{
    case CONTAIN = 'contain';
    case COVER = 'cover';
    case FILL = 'fill';
}
