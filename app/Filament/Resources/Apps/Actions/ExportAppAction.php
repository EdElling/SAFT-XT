<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Actions;

use App\Apps\Definition\AppDefinition;
use App\Apps\Definition\AppDefinitionValidator;
use App\Filament\Pages\AppDataModelPage;
use App\Models\App;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * App als Definition „saft-app/1“ herunterladen (B-107, Etappe 4a). Tabellen und Menüs sind immer dabei; Auswertungen,
 * Rollen, Aufgaben und Handbuch wählbar. Recht apps.export.
 */
final class ExportAppAction
{
    public static function make(): Action
    {
        return Action::make('exportApp')
            ->label(__('apps.export.action'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (): bool => auth()->user()?->can('apps.export') ?? false)
            ->modalHeading(fn (App $record): string => __('apps.export.heading', ['name' => (string) $record->name]))
            ->modalDescription(__('apps.export.help'))
            ->modalSubmitActionLabel(__('apps.export.submit'))
            ->schema([
                CheckboxList::make('parts')
                    ->label(__('apps.export.parts'))
                    ->options(collect(AppDefinition::PARTS)->mapWithKeys(static fn (string $part): array => [$part => __("apps.export.part.{$part}")])->all())
                    ->default(AppDefinition::PARTS)
                    ->columns(2),
            ])
            ->action(function (App $record, array $data): ?StreamedResponse {
                $user = auth()->user();
                $definition = app(AppDefinition::class)->build($record, array_values(array_intersect(AppDefinition::PARTS, (array) ($data['parts'] ?? []))), $user instanceof User ? $user : null);

                // Selbstkontrolle: Eine eben erzeugte Definition muss stimmig sein, sonst lieber nichts herausgeben.
                if (($errors = app(AppDefinitionValidator::class)->errors($definition)) !== []) {
                    Notification::make()->danger()->title(__('apps.export.invalid'))->body(implode("\n", array_slice($errors, 0, 10)))->persistent()->send();

                    return null;
                }

                activity('apps')->causedBy($user)->performedOn($record)->event('exported')
                    ->withProperties(['version' => $record->version, 'parts' => $data['parts'] ?? []])
                    ->log(__('apps.export.log', ['name' => (string) $record->name]));

                $json = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

                return response()->streamDownload(static function () use ($json): void {
                    echo $json;
                }, AppDefinition::fileName($record), ['Content-Type' => 'application/json']);
            });
    }

    /** Datenmodell der App (Diagramm aller Tabellen). */
    public static function dataModel(): Action
    {
        return Action::make('dataModel')
            ->label(__('apps.data_model.action'))
            ->icon(Heroicon::OutlinedShare)
            ->url(fn (App $record): string => AppDataModelPage::getUrl(['app' => $record->id]))
            ->visible(fn (App $record): bool => $record->customTables()->exists());
    }
}
