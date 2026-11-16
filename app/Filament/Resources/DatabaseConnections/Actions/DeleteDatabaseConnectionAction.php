<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Actions;

use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Löscht eine Datenbankverbindung. Sind Tabellen über sie angebunden, listet der Dialog sie auf;
 * mit Bestätigung des Namens werden die Anbindungen mitgelöscht (Recht table_connector.delete).
 */
class DeleteDatabaseConnectionAction
{
    public static function make(?Closure $after = null): Action
    {
        return Action::make('delete')
            ->label(__('filament-actions::delete.single.label'))
            ->icon('heroicon-m-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->authorize(fn (DatabaseConnection $record): bool => auth()->user()?->can('delete', $record) ?? false)
            ->modalHeading(fn (DatabaseConnection $record): string => self::tables($record)->isEmpty()
                ? __('filament-actions::delete.single.modal.heading', ['label' => $record->name])
                : __('db_connections.delete.heading'))
            ->modalDescription(fn (DatabaseConnection $record): ?string => self::tables($record)->isEmpty()
                ? __('filament-actions::modal.confirmation')
                : null)
            ->modalContent(fn (DatabaseConnection $record) => self::tables($record)->isEmpty() ? null : view('filament.db-connections.delete-tables', [
                'tables' => self::tables($record),
                'description' => trans_choice('db_connections.delete.description', self::tables($record)->count(), ['count' => self::tables($record)->count()]),
                'allowed' => self::mayDisconnect(),
            ]))
            ->modalWidth(fn (DatabaseConnection $record): string => self::tables($record)->isEmpty() ? 'md' : '3xl')
            ->modalSubmitActionLabel(fn (DatabaseConnection $record): string => self::tables($record)->isEmpty()
                ? __('filament-actions::delete.single.modal.actions.delete.label')
                : __('db_connections.delete.submit'))
            ->modalSubmitAction(fn (Action $action, DatabaseConnection $record): Action => $action->disabled(self::tables($record)->isNotEmpty() && ! self::mayDisconnect()))
            ->schema(fn (DatabaseConnection $record): array => self::tables($record)->isEmpty() || ! self::mayDisconnect() ? [] : [
                TextInput::make('confirmation')
                    ->label(__('db_connections.delete.confirm', ['name' => $record->name]))
                    ->required(),
            ])
            ->action(function (DatabaseConnection $record, array $data) use ($after): void {
                if (self::tables($record)->isNotEmpty() && ! self::mayDisconnect()) {
                    Notification::make()->title(__('db_connections.delete.not_allowed'))->danger()->send();

                    return;
                }

                $name = $record->name;
                $count = app(TableConnectorService::class)->deleteConnection($record, isset($data['confirmation']) ? (string) $data['confirmation'] : null);

                Notification::make()
                    ->title(trans_choice('db_connections.delete.done', $count, ['name' => $name, 'count' => $count]))
                    ->success()
                    ->send();

                if ($after !== null) {
                    $after();
                }
            });
    }

    /** @return Collection<int, CustomTable> */
    private static function tables(DatabaseConnection $record): Collection
    {
        return $record->customTables()->orderBy('name')->get();
    }

    private static function mayDisconnect(): bool
    {
        return auth()->user()?->can('table_connector.delete') ?? false;
    }
}
