<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\AppLocale;
use Closure;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;

/**
 * Übersetzbare Texte (Zustand je Text: [sprache => text]) kompakt: ein Reiter je Sprache, darin die Texte untereinander
 * mit der Beschriftung links. Die Vorgabesprache kann Pflicht sein; leere Sprachen fallen auf sie zurück – der Hinweis
 * darauf steht als Platzhalter (grau, kursiv) im leeren Feld.
 */
final class TranslatableInputs
{
    /** CSS-Klasse der Felder mit Rückfall-Platzhalter (Stil in PanelAppearance). */
    public const string FALLBACK_CLASS = 'saft-translation-fallback';

    /**
     * Ein übersetzbarer Text.
     *
     * @param  Closure(TextInput|Textarea, string): mixed|null  $configure  z. B. für live() an der Vorgabesprache
     */
    public static function make(string $name, string $label, bool $required = false, bool $multiline = false, int $maxLength = 255, ?Closure $configure = null): Tabs
    {
        return self::tabs([['name' => $name, 'label' => $label, 'required' => $required, 'multiline' => $multiline, 'maxLength' => $maxLength, 'configure' => $configure]]);
    }

    /**
     * Mehrere übersetzbare Texte unter denselben Reitern, z. B. Anzeigename und Beschreibung.
     *
     * @param  list<array{name: string, label: string, required?: bool, multiline?: bool, maxLength?: int, configure?: Closure|null}>  $fields
     */
    public static function tabs(array $fields): Tabs
    {
        $default = (string) config('locales.default');
        $languages = AppLocale::labels();
        $fallback = __('common.translation_fallback', ['language' => $languages[$default] ?? $default]);
        $tabs = [];

        foreach ($languages as $locale => $language) {
            $inputs = [];

            foreach ($fields as $field) {
                $name = $field['name'];
                $input = (($field['multiline'] ?? false) ? Textarea::make("{$name}.{$locale}")->rows(2) : TextInput::make("{$name}.{$locale}")->maxLength($field['maxLength'] ?? 255))
                    ->label($field['label'])
                    ->inlineLabel()
                    ->validationAttribute("{$field['label']} ({$language})")
                    ->required(($field['required'] ?? false) && $locale === $default);

                if ($locale !== $default) {
                    $input->placeholder($fallback)->extraInputAttributes(['class' => self::FALLBACK_CLASS], merge: true);
                }

                if (($field['configure'] ?? null) !== null) {
                    $field['configure']($input, $locale);
                }

                $inputs[] = $input;
            }

            // Ohne eigenen key(): Er ginge in die Kennung der Felder ein (form.<key>.label.de).
            $tabs[] = Tab::make($language)->schema($inputs);
        }

        return Tabs::make('translations')
            ->tabs($tabs)
            ->contained(false)
            ->columnSpanFull()
            ->extraAttributes(['data-translatable-tabs' => true]);
    }
}
