<?php

declare(strict_types=1);

namespace App\Filament\Resources\Apps\Tables;

use App\Apps\AppInterfaces;
use App\Filament\Resources\Apps\Actions\AppLifecycleActions;
use App\Filament\Resources\Apps\Actions\AppManualAction;
use App\Filament\Resources\Apps\Actions\ExportAppAction;
use App\Filament\Resources\Apps\Actions\UpdateAppAction;
use App\Filament\Support\MoveToAppAction;
use App\Filament\Support\RowIcons;
use App\Models\App;
use App\Models\CustomTable;
use App\Plugins\PluginRegistry;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class AppsTable
{
    /** @return list<string> Hinweise zu einer App, die bestehen bleiben, bis sie behoben sind */
    public static function notices(App $app): array
    {
        $plugins = app(PluginRegistry::class);
        $missing = array_values(array_filter($app->baseline()?->requiredPlugins() ?? [], static fn (string $id): bool => ! $plugins->has($id)));
        $unconnected = $app->customTables()->where('source', CustomTable::SOURCE_EXTERNAL)->whereNull('database_connection_id')->count();

        return array_values(array_filter([
            $missing === [] ? null : __('apps.notices.plugins', ['plugins' => implode(', ', $missing)]),
            $unconnected === 0 ? null : trans_choice('apps.notices.unconnected', $unconnected, ['count' => $unconnected]),
        ]));
    }

    public static function configure(Table $table): Table
    {
        // Spaltenname wie das Zählattribut von withCount: custom_tables_count für customTables.
        $count = static fn (string $relation, string $label): TextColumn => TextColumn::make(Str::snake($relation).'_count')
            ->label(__('apps.parts.'.$label))
            ->counts($relation)
            ->alignment(Alignment::End)
            ->sortable();

        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('apps.name'))
                    ->icon(fn (App $record): ?string => $record->icon)
                    ->description(fn (App $record): ?string => filled($record->description) ? (string) $record->description : null)
                    ->wrap(),
                TextColumn::make('prefix')->label(__('apps.prefix'))->placeholder('–'),
                // Schnittstellen (Etappe 5): Zahl der freigegebenen Tabellen.
                TextColumn::make('interfaces_count')
                    ->label(__('apps.interfaces.column'))
                    ->state(fn (App $record): int => $record->customTables()->where('is_shared', true)->count())
                    ->alignment(Alignment::End)
                    ->toggleable(),
                // Ausgangsstand (Etappe 4b): zuletzt installiert als Version am … (bzw. als Kopie).
                TextColumn::make('installed')
                    ->label(__('apps.installed.label'))
                    ->state(fn (App $record): ?string => ($baseline = $record->baseline()) === null ? null : __('apps.installed.'.$baseline->kind, [
                        'version' => $baseline->version ?? '–',
                        'date' => $baseline->created_at?->format('d.m.Y'),
                    ]))
                    ->placeholder('–')
                    ->toggleable(),
                // Dauernde Hinweise: fehlende Plugins (aus dem Ausgangsstand), angebundene Tabellen ohne Verbindung.
                TextColumn::make('notices')
                    ->label(__('apps.notices.label'))
                    ->state(fn (App $record): array => self::notices($record))
                    ->badge()
                    ->color('warning')
                    ->listWithLineBreaks()
                    ->placeholder(''),
                // Status (Etappe 3): aktiv, inaktiv, Entfernen vorgemerkt oder fehlgeschlagen.
                TextColumn::make('status')
                    ->label(__('apps.status.label'))
                    ->badge()
                    ->state(fn (App $record): string => match (true) {
                        $record->removal_error !== null => 'failed',
                        $record->removalPending() => 'removal',
                        $record->is_active => 'active',
                        default => 'inactive',
                    })
                    ->formatStateUsing(fn (string $state, App $record): string => __('apps.status.'.$state, ['time' => $record->removal_due_at?->format('d.m.Y H:i')]))
                    ->tooltip(fn (App $record): ?string => $record->removal_error)
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        default => 'danger',
                    }),
                ...array_map(static fn (string $relation, string $label): TextColumn => $count($relation, $label), array_keys(App::PARTS), App::PARTS),
                TextColumn::make('sort_order')->label(__('common.sort_order'))->alignment(Alignment::End)->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions(RowIcons::of([
                EditAction::make(),
                MoveToAppAction::forApp(),
                AppManualAction::make(),
                ActionGroup::make([
                    ExportAppAction::dataModel(),
                    Action::make('interfaces')
                        ->label(__('apps.interfaces.action'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->modalHeading(fn (App $record): string => __('apps.interfaces.heading', ['name' => (string) $record->name]))
                        ->modalContent(fn (App $record) => view('filament.apps.interfaces', [
                            'offered' => app(AppInterfaces::class)->offeredBy($record),
                            'uses' => app(AppInterfaces::class)->usedBy($record),
                            'version' => $record->interface_version,
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('common.close')),
                    ExportAppAction::make(),
                    UpdateAppAction::make(),
                    ...AppLifecycleActions::make(),
                    // Leere Apps direkt löschen; mit Inhalt deaktivieren und „Entfernen …“ (nur Super-Admin).
                    DeleteAction::make()->visible(fn (App $record): bool => $record->isEmpty()),
                ]),
            ]))
            ->filters([
                TernaryFilter::make('is_active')->label(__('common.active')),
            ]);
    }
}
