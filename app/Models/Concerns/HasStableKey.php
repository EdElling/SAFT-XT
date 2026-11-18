<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Stabiler Schlüssel (Spalte uuid, B-107): wird beim Anlegen vergeben und ändert sich nie, auch nicht beim Umbenennen.
 * Grundlage für App-Pakete, Aktualisieren und KI-Änderungen (siehe docs/concept/APPS.md).
 */
trait HasStableKey
{
    public static function bootHasStableKey(): void
    {
        static::creating(static function (self $model): void {
            if (blank($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }
}
