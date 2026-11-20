<?php

declare(strict_types=1);

namespace App\Filament\Resources\TableConnector\Actions;

use App\ExternalData\TableConnectorService;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * Angebundene Tabelle ohne Verbindung (z. B. aus einer installierten App, B-107 Etappe 4b) einer Datenbankverbindung
 * zuordnen und mit der externen Tabelle abgleichen („Neu einlesen“). Die Tabelle bleibt inaktiv, bis man sie aktiviert;
 * scheitert der Abgleich, bleibt sie ohne Verbindung.
 */
final class AssignConnectionAction
{
    public static function make(): Action
    {
        return Action::make('assignConnection')
            ->label(__('table_connector.assign.action'))
            ->icon(Heroicon::OutlinedLink)
            ->color('warning')
            ->visible(fn (CustomTable $record): bool => $record->isExternal() && $record->database_connection_id === null
                && (auth()->user()?->can('table_connector.update') ?? false))
            ->modalHeading(fn (CustomTable $record): string => __('table_connector.assign.heading', ['table' => (string) $record->label]))
            ->modalDescription(fn (CustomTable $record): string => __('table_connector.assign.help', ['source' => trim($record->external_schema.'.'.$record->external_table, '.')]))
            ->schema([
                Select::make('connection')
                    ->label(__('table_connector.form.connection'))
                    ->options(fn (): array => DatabaseConnection::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->required(),
            ])
            ->action(function (CustomTable $record, array $data): void {
                $record->forceFill(['database_connection_id' => (int) $data['connection']])->save();

                try {
                    $notices = app(TableConnectorService::class)->refresh($record->fresh() ?? $record);
                } catch (Throwable $e) {
                    $record->forceFill(['database_connection_id' => null])->save();
                    Notification::make()->danger()->title(__('table_connector.assign.failed'))->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()
                    ->title(__('table_connector.assign.done', ['table' => (string) $record->label]))
                    ->body(implode("\n", [__('table_connector.assign.done_body'), ...$notices]))
                    ->persistent()
                    ->send();
            });
    }
}
