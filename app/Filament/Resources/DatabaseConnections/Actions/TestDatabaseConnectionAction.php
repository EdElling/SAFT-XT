<?php

declare(strict_types=1);

namespace App\Filament\Resources\DatabaseConnections\Actions;

use App\ExternalData\ConnectionTester;
use App\Models\DatabaseConnection;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** Testet die gespeicherte Konfiguration einer externen Datenbankverbindung. */
class TestDatabaseConnectionAction
{
    public static function make(?\Closure $before = null): Action
    {
        return Action::make('testConnection')
            ->label(__('db_connections.test.run'))
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->authorize(fn (DatabaseConnection $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->action(function (DatabaseConnection $record) use ($before): void {
                if ($before !== null) {
                    $before();
                }

                $result = app(ConnectionTester::class)->test($record->refresh());

                Notification::make()
                    ->title(__($result->successful ? 'db_connections.test.success' : 'db_connections.test.failure'))
                    ->body($result->message)
                    ->{$result->successful ? 'success' : 'danger'}()
                    ->persistent()
                    ->send();
            });
    }
}
