<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\DynamicData\DataMenu;
use App\Models\CustomTable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** „Menüeintrag anlegen“ mit Wahl der Gruppe; auch ein weiterer Eintrag an anderer Stelle ist möglich. */
final class CreateMenuEntryAction
{
    public static function make(): Action
    {
        return Action::make('createMenuEntry')
            ->label(__('tables.actions.create_menu_entry'))
            ->icon('heroicon-o-bars-3')
            ->color('gray')
            ->authorize(fn (): bool => auth()->user()?->can('menus.create') ?? false)
            ->modalHeading(__('tables.actions.create_menu_entry'))
            ->modalWidth('md')
            ->modalSubmitActionLabel(__('filament-actions::create.single.modal.actions.create.label'))
            ->schema([MenuGroupSelect::make(required: true)])
            ->action(function (CustomTable $record, array $data): void {
                app(DataMenu::class)->create($record, (string) $data['menu_group']);
                Notification::make()->title(__('tables.actions.menu_entry_created'))->success()->send();
            });
    }
}
