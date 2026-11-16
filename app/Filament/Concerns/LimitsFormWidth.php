<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Support\Enums\Width;

/**
 * Formular- und Detailseiten auf breiten Bildschirmen begrenzen (80 rem ≈ 1280 px) und linksbündig
 * ausrichten. Listen und Datentabellen nutzen weiterhin die volle Breite (Panel: maxContentWidth Full).
 */
trait LimitsFormWidth
{
    public function getMaxContentWidth(): Width|string|null
    {
        return Width::SevenExtraLarge;
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'saft-content-left'];
    }
}
