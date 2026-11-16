<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\CustomTable;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Text;

/**
 * Hinweis (kein Fehler), wenn andere Tabellen schon dieselbe Bezeichnung tragen: erlaubt, aber in Menüs und
 * Auswahllisten schwer zu unterscheiden. Verglichen wird in der Vorgabesprache, ohne Groß-/Kleinschreibung.
 */
final class TableLabelHint
{
    public static function apply(Field $input, ?string $locale = null): Field
    {
        $locale ??= (string) config('locales.default');

        return $input
            ->live(onBlur: true)
            ->belowContent(function (mixed $state, ?CustomTable $record = null) use ($locale): ?Text {
                $message = is_string($state) ? self::message($state, $locale, $record) : null;

                return $message === null ? null : Text::make($message)->color('warning');
            });
    }

    public static function message(string $label, string $locale, ?CustomTable $ignore = null): ?string
    {
        $wanted = mb_strtolower(trim($label));

        if ($wanted === '') {
            return null;
        }

        $same = CustomTable::query()->with('databaseConnection')->orderBy('name')->get()
            ->filter(fn (CustomTable $t): bool => ! ($ignore?->exists && $t->is($ignore))
                && mb_strtolower(trim((string) $t->getTranslation('label', $locale))) === $wanted)
            ->map(fn (CustomTable $t): string => $t->isExternal()
                ? __('tables.form.same_label_external', ['name' => $t->name, 'connection' => (string) $t->databaseConnection?->name])
                : __('tables.form.same_label_designer', ['name' => $t->name]));

        return $same->isEmpty() ? null : __('tables.form.same_label', ['label' => trim($label), 'tables' => $same->implode(', ')]);
    }
}
