<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkItems\Schemas;

use App\Livewire\WorkProgress;
use App\Models\WorkItem;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkItemInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            // Status, Fortschritt, Abbrechen, Ergebnis – aktualisiert sich selbst.
            Livewire::make(WorkProgress::class, fn (WorkItem $record): array => ['workId' => $record->id])->key('work-progress'),
            Section::make(__('work.sections.details'))->columns(2)->schema([
                TextEntry::make('description')->label(__('work.fields.description'))
                    ->state(fn (WorkItem $record): string => $record->description())->columnSpanFull(),
                TextEntry::make('user.name')->label(__('work.fields.user'))->placeholder(__('work.system')),
                TextEntry::make('app.name')->label(__('work.fields.app'))->placeholder('–'),
                TextEntry::make('created_at')->label(__('work.fields.created'))->dateTime(),
                TextEntry::make('finished_at')->label(__('work.fields.finished'))->dateTime()->placeholder('–'),
            ]),
        ]);
    }
}
