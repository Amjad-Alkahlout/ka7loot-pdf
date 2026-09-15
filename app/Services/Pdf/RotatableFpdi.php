<?php

namespace App\Services\Pdf;

use setasign\Fpdi\Fpdi;

class RotatableFpdi extends Fpdi
{
    /**
     * Set rotation angle for a specific page (or current page if null).
     *
     * @param int $angle Must be 0, 90, 180, or 270
     * @param int|null $page Page number (1-indexed), defaults to current page
     */
    public function setPageRotation(int $angle, ?int $page = null): void
    {
        $targetPage = $page ?? $this->page;
        if ($targetPage > 0) {
            $normalizedAngle = (($angle % 360) + 360) % 360;
            if ($normalizedAngle === 0) {
                unset($this->PageInfo[$targetPage]['rotation']);
            } else {
                $this->PageInfo[$targetPage]['rotation'] = $normalizedAngle;
            }
        }
    }

    /**
     * Get the rotation angle for a specific page.
     *
     * @param int|null $page
     * @return int
     */
    public function getPageRotation(?int $page = null): int
    {
        $targetPage = $page ?? $this->page;
        return $this->PageInfo[$targetPage]['rotation'] ?? 0;
    }
}
