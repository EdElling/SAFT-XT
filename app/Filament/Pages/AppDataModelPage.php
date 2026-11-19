<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\DynamicData\Diagram\DataModelGraph;
use App\DynamicData\Diagram\DataModelGraphBuilder;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Datenmodell einer App (B-107, Etappe 4a): alle Tabellen der App mit ihren Verknüpfungen, aufgerufen von der Seite
 * „Apps“. Gleiche Darstellung wie das Datenmodell einer Menügruppe (DataModelPage); es zählen nur Tabellen, die die
 * Person lesen darf.
 */
class AppDataModelPage extends Page
{
    protected static ?string $slug = 'apps/{app}/data-model';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.data-model';

    #[Locked]
    public int $appId = 0;

    private ?DataModelGraph $graph = null;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'app-data-model';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('apps.view') ?? false;
    }

    public function mount(int|string $app): void
    {
        $this->appId = (int) $app;
        abort_if(App::query()->find($this->appId) === null, 404);
    }

    private function app(): App
    {
        return App::query()->findOrFail($this->appId);
    }

    public function getTitle(): string|Htmlable
    {
        return __('apps.data_model.title', ['app' => (string) $this->app()->name]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $this->graph ??= app(DataModelGraphBuilder::class)->buildForTables(
            CustomTable::query()->where('app_id', $this->appId)->orderBy('name')->pluck('name')->all(),
            $user,
        );

        return DataModelPage::viewDataFor($this->graph);
    }
}
