<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Tables;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomTable;
use App\Models\Report;
use App\Models\User;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportDescriber;
use App\Reports\ReportRunner;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Liste der Auswertungen: nur solche, die die Person auch ausführen kann (Datenrechte auf alle
 * beteiligten Tabellen) – fremde geteilte Auswertungen über nicht lesbare Tabellen erscheinen nicht.
 */
final class ReportsTable
{
    /** Darstellung => Icon (Spalte „Darstellung“). */
    private const array VISUAL_ICONS = [
        'table' => 'heroicon-o-table-cells',
        'crosstab' => 'heroicon-o-squares-2x2',
        'bar' => 'heroicon-o-chart-bar',
        'line' => 'heroicon-o-presentation-chart-line',
        'pie' => 'heroicon-o-chart-pie',
        'kpi' => 'heroicon-o-hashtag',
    ];

    private static function visual(Report $report): string
    {
        $type = (string) ($report->definition['visual']['type'] ?? 'table');

        return in_array($type, ReportDefinition::VISUALS, true) ? $type : 'table';
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereKey(self::runnableIds()))
            ->defaultSort('name')
            ->recordUrl(fn (Report $record): string => ReportResource::getUrl('view', ['record' => $record]))
            ->columns([
                // Name mit dem Satz zur Auswertung darunter; beides bricht um, statt die Tabelle zu verbreitern.
                TextColumn::make('name')->label(__('reports.ui.name'))->searchable()->sortable()->weight('medium')
                    ->description(fn (Report $record): ?string => self::sentence($record))
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 16rem; max-width: 28rem']),
                TextColumn::make('description')->label(__('reports.ui.description'))
                    ->searchable()
                    ->wrap()
                    ->extraAttributes(['style' => 'min-width: 12rem; max-width: 22rem'])
                    ->toggleable(),
                TextColumn::make('visual')
                    ->label(__('reports.ui.visual'))
                    ->state(fn (Report $record): string => self::visual($record))
                    ->formatStateUsing(fn (string $state): string => __('reports.visuals.'.$state))
                    ->icon(fn (string $state): string => self::VISUAL_ICONS[$state] ?? 'heroicon-o-table-cells')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('owner.name')->label(__('reports.ui.owner'))->placeholder('–')->toggleable(),
                IconColumn::make('is_shared')->label(__('reports.ui.shared'))->boolean()->sortable(),
                TextColumn::make('updated_at')->label(__('reports.ui.updated'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('visual')
                    ->label(__('reports.ui.visual'))
                    ->options(fn (): array => collect(ReportDefinition::VISUALS)->mapWithKeys(fn (string $v): array => [$v => __('reports.visuals.'.$v)])->all())
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->where(
                        fn (Builder $q) => $data['value'] === 'table'
                            // Ältere Definitionen ohne Angabe gelten als Tabelle.
                            ? $q->where('definition->visual->type', 'table')->orWhereNull('definition->visual->type')
                            : $q->where('definition->visual->type', $data['value']),
                    )),
                // Grundtabelle aus der Definition (als Text verglichen: JSON-Werte je Datenbank unterschiedlich typisiert).
                SelectFilter::make('base')
                    ->label(__('reports.ui.base'))
                    ->options(fn (): array => self::baseOptions())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null) ? $query : $query->where('definition->base', (string) (int) $data['value'])),
                SelectFilter::make('owner_id')
                    ->label(__('reports.ui.owner'))
                    ->options(fn (): array => User::query()->whereIn('id', Report::query()->whereKey(self::runnableIds())->whereNotNull('owner_id')->select('owner_id'))
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                TernaryFilter::make('is_shared')->label(__('reports.ui.shared')),
                Filter::make('updated_at')
                    ->schema([
                        DatePicker::make('from')->label(__('reports.ui.updated_from')),
                        DatePicker::make('until')->label(__('reports.ui.updated_until')),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('updated_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('updated_at', '<=', $date)))
                    ->indicateUsing(fn (array $data): array => array_values(array_filter([
                        filled($data['from'] ?? null) ? __('reports.ui.updated_from').' '.Carbon::parse($data['from'])->isoFormat('L') : null,
                        filled($data['until'] ?? null) ? __('reports.ui.updated_until').' '.Carbon::parse($data['until'])->isoFormat('L') : null,
                    ]))),
                Filter::make('mine')
                    ->label(__('reports.ui.mine'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->where('owner_id', auth()->id())),
            ])
            // Symbolknöpfe mit Tooltip: Mit Beschriftung brauchte die Spalte über 300 px, die Tabelle scrollte.
            ->recordActions([
                EditAction::make()->iconButton()->tooltip(__('filament-actions::edit.single.label')),
                Action::make('duplicate')
                    ->label(__('reports.ui.duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->iconButton()
                    ->tooltip(__('reports.ui.duplicate'))
                    ->visible(fn (): bool => auth()->user()?->can('create', Report::class) ?? false)
                    ->action(function (Report $record): void {
                        $copy = Report::create([
                            'name' => __('reports.ui.copy_of', ['name' => $record->name]),
                            'description' => $record->description,
                            'definition' => $record->definition,
                            'owner_id' => auth()->id(),
                            'is_shared' => false,
                        ]);
                        Notification::make()->title(__('reports.ui.duplicated'))->success()->send();
                        redirect(ReportResource::getUrl('edit', ['record' => $copy]));
                    }),
                DeleteAction::make()->iconButton()->tooltip(__('filament-actions::delete.single.label')),
            ]);
    }

    private static function sentence(Report $report): ?string
    {
        $user = auth()->user();

        try {
            return app(ReportDescriber::class)->describe(once(fn (): ReportRunner => app(ReportRunner::class))->resolve($report, $user instanceof User ? $user : null));
        } catch (InvalidReportException) {
            return null;
        }
    }

    /** @return array<int, string> Grundtabellen der sichtbaren Auswertungen */
    private static function baseOptions(): array
    {
        $ids = Report::query()->whereKey(self::runnableIds())->get()
            ->map(fn (Report $report): int => (int) ($report->definition['base'] ?? 0))
            ->filter()
            ->unique()
            ->all();

        return CustomTable::query()->whereKey($ids)->get()
            ->mapWithKeys(fn (CustomTable $table): array => [$table->id => (string) $table->label])
            ->sort()
            ->all();
    }

    /** @return list<int> */
    private static function runnableIds(): array
    {
        return once(fn (): array => self::computeRunnableIds());
    }

    /** @return list<int> */
    private static function computeRunnableIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        $runner = app(ReportRunner::class);

        return Report::query()->visibleTo($user)->get()
            ->filter(fn (Report $report): bool => $report->isOwnedBy($user) || $runner->canRun($report, $user))
            ->modelKeys();
    }
}
