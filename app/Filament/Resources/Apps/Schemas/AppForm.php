<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Schemas;

use App\Filament\Forms\Components\IconPicker;
use App\Filament\Support\TranslatableInputs;
use App\Models\App;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AppForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                // Präfix aus dem Namen vorschlagen, solange keines eingetragen ist.
                TranslatableInputs::make('name', __('apps.name'), required: true, maxLength: 120, configure: static function ($input, string $locale): void {
                    if ($locale !== config('locales.default')) {
                        return;
                    }

                    // Folgt dem Namen, solange es leer ist oder noch dem Vorschlag aus dem bisherigen Namen entspricht.
                    $input->live(onBlur: true)->afterStateUpdated(static function (Get $get, Set $set, ?string $state, ?string $old, string $operation): void {
                        if ($operation === 'create' && (blank($get('prefix')) || $get('prefix') === self::suggestPrefix((string) $old))) {
                            $set('prefix', self::suggestPrefix((string) $state));
                        }
                    });
                })->columnSpanFull(),
                TranslatableInputs::make('description', __('apps.description'), multiline: true, maxLength: 2000)->columnSpanFull(),
                TextInput::make('prefix')
                    ->label(__('apps.prefix'))
                    ->helperText(__('apps.prefix_help'))
                    ->maxLength(20)
                    ->regex('/^[a-z][a-z0-9]*_?$/i'),
                TextInput::make('sort_order')->label(__('common.sort_order'))->integer()->minValue(0)->default(0),
                IconPicker::make('icon')->label(__('apps.icon')),
            ]),
        ]);
    }

    /** Präfix aus dem Namen: Kleinbuchstaben und Ziffern, höchstens 20 Zeichen, z. B. „Personal (HR)“ → „personalhr“. */
    public static function suggestPrefix(string $name): string
    {
        $prefix = (string) preg_replace('/[^a-z0-9]/', '', Str::ascii(mb_strtolower($name)));
        $prefix = ltrim($prefix, '0123456789');

        return mb_substr($prefix, 0, 20);
    }

    /**
     * Übersetzbare Felder als Array je Sprache ins Formular.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fill(App $app, array $data): array
    {
        return [...$data, 'name' => $app->getTranslations('name'), 'description' => $app->getTranslations('description')];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $prefix = strtolower(trim((string) ($data['prefix'] ?? '')));

        return [...$data, 'prefix' => $prefix === '' ? null : rtrim($prefix, '_'), 'sort_order' => (int) ($data['sort_order'] ?? 0)];
    }
}
