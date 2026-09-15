<?php

namespace App\Exceptions;

use RuntimeException;

class UnsupportedPdfFontException extends RuntimeException
{
    protected int $pageNumber = 1;
    protected string $fontType = 'Type3';

    public function __construct(
        string $message = 'Cannot perform native text edit: Document contains unsupported embedded fonts (Type 3).',
        int $pageNumber = 1,
        string $fontType = 'Type3',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        $this->pageNumber = $pageNumber;
        $this->fontType = $fontType;
        parent::__construct($message, $code, $previous);
    }

    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    public function getFontType(): string
    {
        return $this->fontType;
    }
}
