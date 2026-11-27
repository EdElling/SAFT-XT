<?php

declare(strict_types=1);

namespace App\Filament\Resources\Activities\Schemas;

use App\DynamicData\History\LoggedChanges;
use App\Filament\Support\ActivityChanges;
use App\Filament\Support\FieldBlocks;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

class ActivityInfolist
{
    /** Lange Werte ohne Leerzeichen (JSON, Kennungen) brechen notfalls mitten im Wort um. */
    private const array WRAP = ['style' => 'overflow-wrap: anywhere'];

    /**
     * Felder mit nur einer Seite (nur neu bzw. nur alt, z. B. Anlegen oder Löschen), sonst null.
     *
     * @return list<array{label: string, value: ?string, numeric: bool}>|null
     */
    public static function singleValues(Activity $activity): ?array
    {
        $rows = ActivityChanges::rows($activity);

        if ($rows === []) {
            return null;
        }

        $onlyNew = collect($rows)->every(fn (array $row): bool => $row['old'] === '—');
        $onlyOld = collect($rows)->every(fn (array $row): bool => $row['new'] === '—');

        if (! $onlyNew && ! $onlyOld) {
            return null;
        }

        return array_map(fn (array $row): array => [
            'label' => $row['field'],
            'value' => ($value = $onlyNew ? $row['new'] : $row['old']) === '—' ? null : $value,
            'numeric' => is_numeric($value) && ! preg_match('/^0\d/', (string) $value),
        ], $rows);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('activity.event'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('data.history.when'))
                            ->dateTime(),
                        TextEntry::make('causer_label')
                            ->label(__('activity.user'))
                            ->state(fn (Activity $record): string => ActivityChanges::causerLabel($record)),
                        TextEntry::make('event')
                            ->label(__('activity.event'))
                            ->badge()
                            ->formatStateUsing(fn (?string $state, Activity $record): string => ActivityChanges::eventText($record))
                            ->color(fn (?string $state): string => ActivityEventColor::for($state)),
                        TextEntry::make('subject')
                            ->label(__('activity.subject'))
                            ->state(fn (Activity $record): string => ActivityChanges::subjectLabel($record))
                            ->extraAttributes(self::WRAP),
                        TextEntry::make('log_name')
                            ->label(__('activity.log')),
                        TextEntry::make('description')
                            ->label(__('common.description'))
                            ->extraAttributes(self::WRAP),
                    ]),
                Section::make(__('activity.changes'))
                    ->schema([
                        // Anlegen/Löschen: nur ein Wert je Feld – kompakt als Blöcke „Feld | Wert“.
                        Group::make(fn (Activity $record): array => [FieldBlocks::make(self::singleValues($record) ?? [])])
                            ->visible(fn (Activity $record): bool => self::singleValues($record) !== null),
                        RepeatableEntry::make('changes')
                            ->visible(fn (Activity $record): bool => self::singleValues($record) === null)
                            ->hiddenLabel()
                            ->state(fn (Activity $record): array => ActivityChanges::rows($record))
                            ->table([
                                TableColumn::make(__('activity.field')),
                                TableColumn::make(__('activity.old')),
                                TableColumn::make(__('activity.new')),
                            ])
                            ->schema([
                                TextEntry::make('field')->extraAttributes(self::WRAP),
                                TextEntry::make('old')->extraAttributes(self::WRAP),
                                TextEntry::make('new')->extraAttributes(self::WRAP),
                            ])
                            ->placeholder(__('activity.no_changes')),
                    ]),
                // B-174: oben lesbar, hier die Werte wie im Protokoll (Spaltennamen, Rohwerte) für die Fehlersuche.
                Section::make(__('activity.technical'))
                    ->collapsed()
                    ->visible(fn (Activity $record): bool => app(LoggedChanges::class)->covers($record) && ActivityChanges::technicalRows($record) !== [])
                    ->schema([
                        RepeatableEntry::make('technical')
                            ->hiddenLabel()
                            ->state(fn (Activity $record): array => ActivityChanges::technicalRows($record))
                            ->table([
                                TableColumn::make(__('activity.field')),
                                TableColumn::make(__('activity.old')),
                                TableColumn::make(__('activity.new')),
                            ])
                            ->schema([
                                TextEntry::make('field')->extraAttributes(self::WRAP),
                                TextEntry::make('old')->extraAttributes(self::WRAP),
                                TextEntry::make('new')->extraAttributes(self::WRAP),
                            ]),
                    ]),
            ]);
    }
}
