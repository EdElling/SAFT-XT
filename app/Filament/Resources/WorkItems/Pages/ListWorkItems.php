<?php

declare(strict_types=1);

namespace App\Filament\Resources\WorkItems\Pages;

use App\Filament\Concerns\RemembersTableColumns;
use App\Filament\Resources\WorkItems\WorkItemResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Liste der Arbeiten; mit work.view_any die Reiter „Meine“ und „Alle“ („Meine Arbeiten“ verlinkt ?tab=mine). */
class ListWorkItems extends ListRecords
{
    use RemembersTableColumns;

    public const string MINE = 'mine';

    protected static string $resource = WorkItemResource::class;

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        if (! (auth()->user()?->can('work.view_any') ?? false)) {
            return [];
        }

        return [
            'all' => Tab::make(__('work.tabs.all')),
            self::MINE => Tab::make(__('work.tabs.mine'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where('user_id', auth()->id())),
        ];
    }
}
