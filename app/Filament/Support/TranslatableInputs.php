<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\AppLocale;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Fieldset;

/**
 * Ein Eingabefeld je Sprache für einen übersetzbaren Text (Zustand: [sprache => text]).
 * Die Vorgabesprache kann Pflicht sein; leere Sprachen fallen auf sie zurück.
 */
final class TranslatableInputs
{
    /**
     * @param  Closure(TextInput|Textarea, string): mixed|null  $configure  z. B. für live() an der Vorgabesprache
     */
    public static function make(string $name, string $label, bool $required = false, bool $multiline = false, int $maxLength = 255, ?Closure $configure = null): Fieldset
    {
        $default = (string) config('locales.default');

        $inputs = [];

        foreach (AppLocale::labels() as $locale => $language) {
            $input = ($multiline ? Textarea::make("{$name}.{$locale}")->rows(2) : TextInput::make("{$name}.{$locale}")->maxLength($maxLength))
                ->label($language)
                ->validationAttribute("{$label} ({$language})")
                ->required($required && $locale === $default)
                ->helperText($locale === $default ? null : __('common.translation_fallback', ['language' => AppLocale::labels()[$default] ?? $default]));

            if ($configure !== null) {
                $configure($input, $locale);
            }

            $inputs[] = $input;
        }

        return Fieldset::make($label)->schema($inputs)->columns(min(count($inputs), 2))->columnSpanFull();
    }
}
