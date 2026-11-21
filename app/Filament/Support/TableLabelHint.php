<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\CustomTable;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Hinweis (kein Fehler), wenn andere Tabellen schon dieselbe Bezeichnung tragen: erlaubt, aber in Menüs und
 * Auswahllisten schwer zu unterscheiden. Verglichen wird in der Vorgabesprache, ohne Groß-/Kleinschreibung, und nur
 * innerhalb der App (B-107): Tabellen anderer Apps erscheinen nicht in denselben Menüs; wo doch (Schnittstellen), steht
 * der App-Name davor.
 */
final class TableLabelHint
{
    public static function apply(Field $input, ?string $locale = null): Field
    {
        $locale ??= (string) config('locales.default');

        return $input
            ->live(onBlur: true)
            ->belowContent(function (mixed $state, Get $get, mixed $record = null) use ($locale): ?Text {
                $record = $record instanceof CustomTable ? $record : null;
                $app = $record?->app_id ?? (filled($get('app_id')) ? (int) $get('app_id') : null);
                $message = is_string($state) ? self::message($state, $locale, $record, $app) : null;

                return $message === null ? null : Text::make($message)->color('warning');
            });
    }

    /** @param  int|null  $appId  App der Tabelle; null = mit allen Tabellen vergleichen */
    public static function message(string $label, string $locale, ?CustomTable $ignore = null, ?int $appId = null): ?string
    {
        $wanted = mb_strtolower(trim($label));

        if ($wanted === '') {
            return null;
        }

        $same = CustomTable::query()->with('databaseConnection')->when($appId !== null, fn ($q) => $q->where('app_id', $appId))->orderBy('name')->get()
            ->filter(fn (CustomTable $t): bool => ! ($ignore?->exists && $t->is($ignore))
                && mb_strtolower(trim((string) $t->getTranslation('label', $locale))) === $wanted)
            ->map(fn (CustomTable $t): string => $t->isExternal()
                ? __('tables.form.same_label_external', ['name' => $t->name, 'connection' => (string) $t->databaseConnection?->name])
                : __('tables.form.same_label_designer', ['name' => $t->name]));

        return $same->isEmpty() ? null : __('tables.form.same_label', ['label' => trim($label), 'tables' => $same->implode(', ')]);
    }
}
