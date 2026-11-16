<?php

declare(strict_types=1);

namespace App\Filament\Forms\Components;

use App\Rules\KnownIcon;
use App\Support\IconCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

/**
 * Icon-Feld: Name von Hand eingeben oder im Dialog auswählen (Reiter je Stil, Suche).
 * Das gewählte Icon erscheint als Vorschau im Feld.
 */
class IconPicker extends TextInput
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('common.icon'))
            ->regex('/^[a-z0-9.-]+$/')
            ->rule(new KnownIcon)
            ->maxLength(100)
            ->live(onBlur: true)
            ->prefixIcon(static fn (?string $state): ?string => filled($state) && KnownIcon::exists($state) ? $state : null)
            ->suffixAction(
                Action::make('pickIcon')
                    ->label(__('common.icon_picker.choose'))
                    ->tooltip(__('common.icon_picker.choose'))
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->modalHeading(__('common.icon_picker.heading'))
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalSubmitAction(false)
                    ->schema(static fn (TextInput $component): array => [
                        ViewField::make('icon')
                            ->hiddenLabel()
                            ->view('filament.forms.icon-picker')
                            ->viewData([
                                'groups' => IconCatalog::groups(),
                                'current' => $component->getState(),
                                'currentGroup' => IconCatalog::groupOf($component->getState()),
                            ]),
                    ])
                    ->action(static function (array $data, TextInput $component): void {
                        if (is_string($data['icon'] ?? null) && KnownIcon::exists($data['icon'])) {
                            $component->state($data['icon']);
                        }
                    }),
            );
    }
}
