<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Actions;

use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;

/**
 * Löscht eine Datenbankverbindung. Sind Tabellen über sie angebunden, listet der Dialog sie auf und bietet an, die
 * Anbindungen ohne Verbindung und inaktiv zu behalten (Vorgabe, Recht table_connector.update) oder – mit Bestätigung
 * des Namens – mitzulöschen (Recht table_connector.delete).
 */
class DeleteDatabaseConnectionAction
{
    public const string KEEP = 'keep';

    public const string DELETE = 'delete';

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
                'allowed' => self::mayKeep() || self::mayDisconnect(),
            ]))
            ->modalWidth(fn (DatabaseConnection $record): string => self::tables($record)->isEmpty() ? 'md' : '3xl')
            ->modalSubmitActionLabel(fn (DatabaseConnection $record): string => self::tables($record)->isEmpty()
                ? __('filament-actions::delete.single.modal.actions.delete.label')
                : __('db_connections.delete.submit'))
            ->modalSubmitAction(fn (Action $action, DatabaseConnection $record): Action => $action->disabled(self::tables($record)->isNotEmpty() && ! self::mayKeep() && ! self::mayDisconnect()))
            ->schema(fn (DatabaseConnection $record): array => self::tables($record)->isEmpty() ? [] : [
                Radio::make('tables')
                    ->label(__('db_connections.delete.tables'))
                    ->options(array_filter([
                        self::KEEP => self::mayKeep() ? __('db_connections.delete.keep') : null,
                        self::DELETE => self::mayDisconnect() ? __('db_connections.delete.drop') : null,
                    ]))
                    ->descriptions([self::KEEP => __('db_connections.delete.keep_help'), self::DELETE => __('db_connections.delete.drop_help')])
                    ->default(self::mayKeep() ? self::KEEP : self::DELETE)
                    ->required()
                    ->live(),
                TextInput::make('confirmation')
                    ->label(__('db_connections.delete.confirm', ['name' => $record->name]))
                    ->visible(fn (Get $get): bool => $get('tables') === self::DELETE)
                    ->required(fn (Get $get): bool => $get('tables') === self::DELETE),
            ])
            ->action(function (DatabaseConnection $record, array $data) use ($after): void {
                $keep = ($data['tables'] ?? null) === self::KEEP;

                if (self::tables($record)->isNotEmpty() && ! ($keep ? self::mayKeep() : self::mayDisconnect())) {
                    Notification::make()->title(__('db_connections.delete.not_allowed'))->danger()->send();

                    return;
                }

                $name = $record->name;
                $count = app(TableConnectorService::class)->deleteConnection($record, isset($data['confirmation']) ? (string) $data['confirmation'] : null, $keep);

                Notification::make()
                    ->title($keep
                        ? trans_choice('db_connections.delete.done_kept', $count, ['name' => $name, 'count' => $count])
                        : trans_choice('db_connections.delete.done', $count, ['name' => $name, 'count' => $count]))
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

    /** Anbindungen ohne Verbindung behalten: ändert die Tabellen, löscht sie nicht. */
    private static function mayKeep(): bool
    {
        return auth()->user()?->can('table_connector.update') ?? false;
    }

    private static function mayDisconnect(): bool
    {
        return auth()->user()?->can('table_connector.delete') ?? false;
    }
}
