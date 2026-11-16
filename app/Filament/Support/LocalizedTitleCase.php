<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Str;

/**
 * Überschriften aus Modellbezeichnungen: Großschreibung jedes Worts ist englischer Stil
 * („Table Designer“). In den übrigen Sprachen nur das erste Wort („Designer di tabelle“).
 */
trait LocalizedTitleCase
{
    public static function getTitleCaseModelLabel(): string
    {
        return self::localizedTitleCase(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return self::localizedTitleCase(static::getPluralModelLabel());
    }

    private static function localizedTitleCase(string $label): string
    {
        return app()->isLocale('en') ? Str::ucwords($label) : Str::ucfirst($label);
    }
}
