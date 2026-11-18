<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\App;
use Filament\Forms\Components\Select;

/**
 * Auswahl der App (B-107) für Tabellen, oberste Menügruppen, Rollen und Aufgaben. Vorbelegt, wenn es nur eine App
 * gibt; leer und Pflicht, wenn es mehrere gibt.
 */
final class AppSelect
{
    public static function make(string $name = 'app_id', bool $required = true): Select
    {
        return Select::make($name)
            ->label(__('apps.app'))
            ->helperText(__('apps.app_help'))
            ->options(static fn (): array => self::options())
            ->default(static fn (): ?int => ($apps = self::options()) !== [] && count($apps) === 1 ? (int) array_key_first($apps) : null)
            ->required($required)
            // Natives Auswahlfeld wie bei der Menügruppe: wenige Einträge, keine Suche nötig.
            ->native()
            ->live();
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return App::query()->orderBy('sort_order')->get()->mapWithKeys(static fn (App $app): array => [$app->id => (string) $app->name])->all();
    }

    /**
     * Technischer Name nach einem Wechsel der App: Präfix der bisherigen App durch das der neuen ersetzen (oder
     * entfernen), damit keine Tabelle mit dem Präfix einer anderen App entsteht. Der übrige Name bleibt.
     */
    public static function switchPrefix(?string $name, mixed $oldAppId, mixed $newAppId): ?string
    {
        if (blank($name)) {
            return $name;
        }

        $old = self::prefix($oldAppId);
        $new = self::prefix($newAppId);
        $base = $old !== '' && str_starts_with($name, $old) ? substr($name, strlen($old)) : $name;

        return $new !== '' && ! str_starts_with($base, $new) ? mb_substr($new.$base, 0, 63) : $base;
    }

    /** Präfix der App für einen Tabellennamen, z. B. „lager_“; leer ohne App oder Präfix. */
    public static function prefix(mixed $appId): string
    {
        return filled($appId) ? (App::query()->find((int) $appId)?->tablePrefix() ?? '') : '';
    }
}
