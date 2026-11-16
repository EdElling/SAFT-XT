<?php

declare(strict_types=1);

namespace App\Filament\Resources\CustomTables\RelationManagers;

use App\DynamicData\Usages\TableUsage;
use App\DynamicData\Usages\TableUsages;
use App\Models\CustomTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Reiter „Verwendet in“: wo die Tabelle verwendet wird (Plugins, Auswertungen, Menüs, andere Tabellen, Rollen,
 * Aufgaben). Nur lesend; die Daten kommen aus TableUsages, nicht aus einer Beziehung.
 */
class UsagesRelationManager extends RelationManager
{
    /** Formal nötig; die Zeilen liefert records(). */
    protected static string $relationship = 'fields';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tables.usages.title');
    }

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord instanceof CustomTable ? count(app(TableUsages::class)->of($ownerRecord)) : 0;

        return $count > 0 ? (string) $count : null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        /** @var CustomTable $owner */
        $owner = $this->getOwnerRecord();

        return $table
            ->records(function (array $filters) use ($owner): array {
                $area = $filters['area']['value'] ?? null;
                $rows = [];

                foreach (app(TableUsages::class)->of($owner) as $index => $usage) {
                    if ($area === null || $area === '' || $usage->area === $area) {
                        $rows[$usage->area.'-'.$index] = $usage->toRow($index);
                    }
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('area')
                    ->label(__('tables.usages.area'))
                    ->badge()
                    ->formatStateUsing(static fn (string $state): string => __('tables.usages.areas.'.$state)),
                TextColumn::make('name')
                    ->label(__('common.name'))
                    ->wrap()
                    ->url(static fn (array $record): ?string => $record['url'])
                    ->color(static fn (array $record): ?string => $record['url'] !== null ? 'primary' : null),
                TextColumn::make('detail')
                    ->label(__('tables.usages.detail'))
                    ->wrap()
                    ->fontFamily(static fn (array $record): ?string => $record['area'] === TableUsage::FORMULA ? 'mono' : null),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->label(__('tables.usages.area'))
                    ->options(array_combine(TableUsage::AREAS, array_map(static fn (string $a): string => __('tables.usages.areas.'.$a), TableUsage::AREAS))),
            ])
            // Zeilen sind Arrays, keine Modelle: die Vorgaben des Relation-Managers (Klick öffnet Ansehen/Bearbeiten)
            // ersetzen – der Link steht beim Namen.
            ->recordAction(null)
            ->recordUrl(static fn (array $record): ?string => $record['url'])
            ->emptyStateHeading(__('tables.usages.empty'))
            ->emptyStateIcon('heroicon-o-link-slash')
            ->paginated(false);
    }
}
