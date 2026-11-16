<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Schemas;

final class ActivityEventColor
{
    public static function for(?string $event): string
    {
        return match ($event) {
            'created' => 'success',
            'updated' => 'warning',
            'deleted' => 'danger',
            'restored' => 'info',
            default => 'gray',
        };
    }
}
