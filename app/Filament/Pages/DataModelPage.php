<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Apps\CurrentApp;
use App\DynamicData\Diagram\DataModelGraph;
use App\DynamicData\Diagram\DataModelGraphBuilder;
use App\DynamicData\Diagram\MermaidErRenderer;
use App\Models\Menu;
use App\Models\User;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * Grafische Übersicht der Datentabellen einer Menügruppe (inkl. Untergruppen) und ihrer
 * Verknüpfungen: /admin/data-model/{menu}. {menu} ist der eigene Menüeintrag vom Typ
 * „Datenmodell“; die Gruppe ergibt sich aus seiner Position im Menübaum.
 */
class DataModelPage extends Page
{
    public const string ROUTE = 'filament.admin.pages.data-model';

    public const string ASSET = 'mermaid';

    protected static ?string $slug = 'data-model/{menu}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.data-model';

    #[Locked]
    public int $menuId = 0;

    private ?DataModelGraph $graph = null;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'data-model';
    }

    public function mount(int|string $menu): void
    {
        $this->menuId = (int) $menu;
        app(CurrentApp::class)->follow(($user = auth()->user()) instanceof User ? $user : null, $this->entry()->app_id);
    }

    /** Eintrag muss existieren, aktiv sein, auf diese Seite zeigen und für den Benutzer sichtbar sein. */
    private function entry(): Menu
    {
        $entry = Menu::query()->active()->whereKey($this->menuId)->where('route', self::ROUTE)->first();
        abort_if($entry === null, 404);

        $user = auth()->user();
        abort_unless($user instanceof User && Menu::query()->visibleFor($user)->whereKey($entry->id)->exists(), 403);

        return $entry;
    }

    private function graph(): DataModelGraph
    {
        /** @var User $user */
        $user = auth()->user();

        return $this->graph ??= app(DataModelGraphBuilder::class)->build($this->entry(), $user);
    }

    public function getTitle(): string|Htmlable
    {
        $entry = $this->entry();
        $group = $entry->parent_id === null ? null : Menu::query()->find($entry->parent_id);

        return $group === null ? __('data_model.title_all') : __('data_model.title', ['group' => (string) $group->label]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return self::viewDataFor($this->graph());
    }

    /**
     * Daten der Ansicht für ein Datenmodell (auch für das Datenmodell einer App, AppDataModelPage).
     *
     * @return array<string, mixed>
     */
    public static function viewDataFor(DataModelGraph $graph): array
    {
        $renderer = app(MermaidErRenderer::class);

        return [
            'empty' => $graph->isEmpty(),
            'compact' => $graph->isEmpty() ? '' : $renderer->render($graph, withFields: false),
            'expanded' => $graph->isEmpty() ? '' : $renderer->render($graph, withFields: true),
            'hasOutside' => $graph->outside !== [],
            'hasExternal' => collect($graph->tables)->contains(fn ($table): bool => $table->isExternal()),
            'hasLinks' => collect($graph->edges)->contains(fn (array $edge): bool => ($edge['link'] ?? null) !== null),
            'script' => FilamentAsset::getScriptSrc(self::ASSET),
            'storageKey' => 'saft.data-model.fields',
        ];
    }
}
