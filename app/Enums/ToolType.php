<?php

namespace App\Enums;

enum ToolType: string
{
    case JPG_TO_PDF = 'jpg-to-pdf';
    case PDF_TO_JPG = 'pdf-to-jpg';
    case PDF_TO_PNG = 'pdf-to-png';
    case MERGE_PDF = 'merge-pdf';
    case SPLIT_PDF = 'split-pdf';
    case COMPRESS_PDF = 'compress-pdf';
    case COMPRESS_IMAGE = 'compress-image';
    case RESIZE_IMAGE = 'resize-image';
    case CONVERT_IMAGE = 'convert-image';
    case ORGANIZE_PDF = 'organize-pdf';
    case PROTECT_PDF = 'protect-pdf';
    case UNLOCK_PDF = 'unlock-pdf';
    case SIGN_PDF = 'sign-pdf';
    case EDIT_PDF = 'edit-pdf';
    case REMOVE_BACKGROUND = 'remove-background';
    case CHANGE_BACKGROUND = 'change-background';

    public function isHeavy(): bool
    {
        return match ($this) {
            self::COMPRESS_PDF, self::PDF_TO_JPG, self::PDF_TO_PNG => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::JPG_TO_PDF => 'JPG to PDF',
            self::PDF_TO_JPG => 'PDF to JPG',
            self::PDF_TO_PNG => 'PDF to PNG',
            self::MERGE_PDF => 'Merge PDF',
            self::SPLIT_PDF => 'Split PDF',
            self::COMPRESS_PDF => 'Compress PDF',
            self::COMPRESS_IMAGE => 'Compress Image',
            self::RESIZE_IMAGE => 'Resize Image',
            self::CONVERT_IMAGE => 'Convert Image',
            self::ORGANIZE_PDF => 'Organize PDF',
            self::PROTECT_PDF => 'Protect PDF',
            self::UNLOCK_PDF => 'Unlock PDF',
            self::SIGN_PDF => 'Sign PDF',
            self::EDIT_PDF => 'Edit PDF',
            self::REMOVE_BACKGROUND => 'Remove Background',
            self::CHANGE_BACKGROUND => 'Change Background',
        };
    }
}
