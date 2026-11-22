<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Apps\CurrentApp;
use App\Models\App;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * App-Auswahl für Listen der Administration (B-107): je App ein Eintrag in der Reihenfolge der Seite „Apps“, optional
 * „System“ (Einträge ohne App) und „Alle“, jeweils mit Anzahl; vorgewählt ist die aktuelle App der Titelleiste. Gezeigt
 * als Auswahlliste über der Tabelle (wie beim Briefpapier), nicht als Reiter – bei vielen Apps bleibt sie eine Zeile.
 * Die Spalte „App“ erscheint nur unter „Alle“ (AppFilter::column).
 */
trait HasAppTabs
{
    public const string ALL_APPS = 'all';

    public const string SYSTEM_TAB = 'system';

    /** Reiter „System“ für Einträge ohne App (z. B. Systemrollen, Administrationsmenüs). */
    protected function appTabsWithSystem(): bool
    {
        return false;
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $query = static::getResource()::getEloquentQuery();
        $column = $query->qualifyColumn('app_id');
        $counts = $query->pluck($column)->countBy(static fn (mixed $id): string => $id === null ? self::SYSTEM_TAB : (string) $id);
        $tabs = [];

        foreach (App::query()->orderBy('sort_order')->orderBy('id')->get() as $app) {
            $tabs['app-'.$app->id] = Tab::make((string) $app->name.($app->is_active ? '' : ' ('.__('apps.inactive').')'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->where($query->qualifyColumn('app_id'), $app->id))
                ->badge($counts[(string) $app->id] ?? 0);
        }

        if ($this->appTabsWithSystem()) {
            $tabs[self::SYSTEM_TAB] = Tab::make(__('apps.system'))
                ->modifyQueryUsing(static fn (Builder $query): Builder => $query->whereNull($query->qualifyColumn('app_id')))
                ->badge($counts[self::SYSTEM_TAB] ?? 0);
        }

        $tabs[self::ALL_APPS] = Tab::make(__('apps.tabs.all'))->badge($counts->sum());

        return $tabs;
    }

    /** Auswahlliste statt Reitern, gebunden an activeTab (Filterung, Adresse und Vorgabe wie bei Reitern). */
    public function getTabsContentComponent(): Component
    {
        $tabs = $this->getCachedTabs();

        return Select::make('activeTab')
            ->label(__('apps.tabs.app'))
            ->options(collect($tabs)->map(static fn (Tab $tab): string => $tab->getLabel().' ('.$tab->getBadge().')')->all())
            ->selectablePlaceholder(false)
            // Kennzeichen für PanelAppearance: Seite so breit wie die Tabelle, die Auswahl füllt diese Breite.
            ->extraFieldWrapperAttributes(['class' => 'saft-app-select'])
            ->live()
            ->hidden($tabs === []);
    }

    /** Die aktuelle App der Titelleiste, sonst der erste Eintrag. */
    public function getDefaultActiveTab(): string|int|null
    {
        $user = auth()->user();
        $current = $user instanceof User ? app(CurrentApp::class)->id($user) : null;
        $tabs = $this->getCachedTabs();

        return $current !== null && isset($tabs['app-'.$current]) ? 'app-'.$current : array_key_first($tabs);
    }

    /** Zeigt die Liste Einträge mehrerer Apps (Reiter „Alle“)? Dann mit Spalte „App“. */
    public function showsAllApps(): bool
    {
        return $this->activeTab === self::ALL_APPS;
    }
}
