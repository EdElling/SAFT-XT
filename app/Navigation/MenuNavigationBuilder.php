<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Apps\CurrentApp;
use App\Auth\RoleSimulation;
use App\DynamicData\DataMenu;
use App\DynamicData\Diagram\DataModelGraphBuilder;
use App\Filament\Pages\DataModelPage;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\Menu;
use App\Models\Report;
use App\Models\User;
use App\Plugins\PluginStates;
use App\Reports\ReportRunner;
use App\Rules\KnownIcon;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Baut die Filament-Navigation aus der `menus`-Tabelle, gefiltert nach den
 * Berechtigungen des aktuellen Users.
 *
 * Abbildung: Root ohne Ziel -> Gruppe, Root mit Ziel -> Einzel-Eintrag,
 * Ebene 2 -> Eintrag, Ebene 3 -> Kind-Eintrag. Tiefere Ebenen werden ignoriert.
 */
final class MenuNavigationBuilder
{
    private const string DEFAULT_PARENT_ICON = 'heroicon-o-folder';

    /** Auswertungen der Menüeinträge, einmal je Aufbau geladen. @var array<int, Report> */
    private array $reports = [];

    /** Ein Prüfer je Aufbau: Er hält Tabellen und Felder zwischen, statt sie je Auswertung neu zu laden. */
    private ?ReportRunner $runner = null;

    public function __construct(private readonly DataModelGraphBuilder $dataModels) {}

    public function build(NavigationBuilder $builder, ?User $user): NavigationBuilder
    {
        if ($user === null) {
            return $builder;
        }

        // System-Einträge und die Menüs der aktuellen App (B-107).
        $appId = app(CurrentApp::class)->id($user);
        $menus = Menu::query()
            ->active()
            ->visibleFor($user)
            ->where(static fn ($q) => $q->whereNull('app_id')->when($appId !== null, static fn ($q) => $q->orWhere('app_id', $appId)))
            ->orderBy('sort_order')
            ->orderedByLabel()
            ->get();

        $reportIds = $menus->where('route', ReportResource::VIEW_ROUTE)->map(static fn (Menu $menu): int => (int) ($menu->route_parameters['record'] ?? 0))->filter()->unique()->values()->all();
        $this->reports = $reportIds === [] ? [] : Report::query()->whereIn('id', $reportIds)->get()->keyBy('id')->all();
        $this->runner = app(ReportRunner::class);

        /** @var array<int|string, Collection<int, Menu>> $byParent */
        $byParent = $menus->groupBy(fn (Menu $menu): string => (string) ($menu->parent_id ?? 'root'))->all();

        $groups = [];
        $items = [];

        foreach ($byParent['root'] ?? [] as $root) {
            if ($this->hasTarget($root)) {
                if ($item = $this->makeItem($root, $byParent, 1, $user)) {
                    $items[] = $item;
                }

                continue;
            }

            $children = $this->makeItems($byParent[(string) $root->id] ?? null, $byParent, 2, $user);

            if ($children === []) {
                continue;
            }

            $group = NavigationGroup::make($root->label)->items($children);

            // Filament erlaubt Icons entweder an der Gruppe oder an den Einträgen.
            if (($icon = $this->usableIcon($root)) !== null && ! $this->anyHasIcon($children)) {
                $group->icon($icon);
            }

            $groups[] = $group;
        }

        return $builder->items($items)->groups($groups);
    }

    /**
     * @param  Collection<int, Menu>|null  $menus
     * @param  array<int|string, Collection<int, Menu>>  $byParent
     * @return list<NavigationItem>
     */
    private function makeItems(?Collection $menus, array $byParent, int $depth, User $user): array
    {
        $items = [];

        foreach ($menus ?? [] as $menu) {
            if ($item = $this->makeItem($menu, $byParent, $depth, $user)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param  array<int|string, Collection<int, Menu>>  $byParent
     */
    private function makeItem(Menu $menu, array $byParent, int $depth, User $user): ?NavigationItem
    {
        // Einträge ausgeschalteter Plugins bleiben gespeichert, erscheinen aber nicht.
        if (app(PluginStates::class)->hidesMenu($menu->route, $menu->permission_name)) {
            return null;
        }

        // Ein Datenmodell ohne sichtbare Tabelle wäre leer; ohne ihn entfällt auch eine sonst leere Gruppe.
        if ($menu->route === DataModelPage::ROUTE && ! $this->dataModels->hasTables($menu, $user)) {
            return null;
        }

        // Auswertung: nur, wenn die Person sie sehen und mit ihren Datenrechten ausführen darf.
        if ($menu->route === ReportResource::VIEW_ROUTE && ! $this->canRunReport($menu, $user)) {
            return null;
        }

        $url = $this->resolveUrl($menu);
        $childMenus = $byParent[(string) $menu->id] ?? null;

        if ($depth >= 3 && $childMenus?->isNotEmpty()) {
            Log::warning('Menü-Einträge unterhalb der 3. Ebene werden nicht angezeigt.', ['menu_id' => $menu->id]);
        }

        $children = $depth < 3 ? $this->makeItems($childMenus, $byParent, $depth + 1, $user) : [];

        // Ohne eigenes Ziel ist ein Eintrag nur als Container mit sichtbaren Kindern sinnvoll.
        if ($url === null && $children === []) {
            return null;
        }

        // Untermenü (aufklappbar): Pfeil rechts neben der Bezeichnung, damit es sich von Seiten unterscheidet (B-066).
        $item = NavigationItem::make($children === [] ? $menu->label : self::submenuLabel((string) $menu->label))
            ->url($url ?? $children[0]->getUrl())
            ->sort($menu->sort_order)
            ->isActiveWhen($this->activeCallback($menu));

        // Filament verlangt für Einträge mit Untereinträgen ein Icon und bricht sonst das ganze Panel ab.
        $icon = $this->usableIcon($menu) ?? ($children === [] ? null : self::DEFAULT_PARENT_ICON);

        if ($icon !== null) {
            $item->icon($icon);
        }

        if ($menu->url !== null && $menu->route === null) {
            $item->openUrlInNewTab();
        }

        return $children === [] ? $item : $item->childItems($children);
    }

    /**
     * Navigation einmal je Anfrage (Panel): Schlüssel aus Person, Sprache, Rollen-Simulation, ein- und ausgeschalteten
     * Plugins und einem Fingerabdruck der Menüs (Anzahl, letzte Änderung) – ändert sich etwas davon, wird neu aufgebaut.
     */
    public static function cachedForRequest(NavigationBuilder $builder, ?User $user): NavigationBuilder
    {
        if ($user === null) {
            return $builder;
        }

        $fingerprint = Menu::query()->toBase()->selectRaw('count(*) as entries, max(updated_at) as changed')->first();
        $key = 'saft.navigation.'.md5(json_encode([
            $user->getKey(),
            app()->getLocale(),
            app(RoleSimulation::class)->active()?->all(),
            app(CurrentApp::class)->id($user),
            app(PluginStates::class)->fingerprint(),
            $fingerprint?->entries,
            $fingerprint?->changed,
        ], JSON_THROW_ON_ERROR));
        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $attributes->set($key, app(self::class)->build($builder, $user));
        }

        return $attributes->get($key);
    }

    /** Bezeichnung eines Untermenüs mit Pfeil; Filament entfernt das HTML selbst für Tooltip und Vorlesen. */
    public static function submenuLabel(string $label): HtmlString
    {
        return new HtmlString('<span class="saft-submenu-label">'.e($label).'</span>'.svg('heroicon-m-chevron-right', 'saft-submenu-chevron')->toHtml());
    }

    private function canRunReport(Menu $menu, User $user): bool
    {
        $report = $this->reports[(int) ($menu->route_parameters['record'] ?? 0)] ?? null;

        return $report !== null && $user->can('view', $report) && ($this->runner ?? app(ReportRunner::class))->canRun($report, $user);
    }

    /** Ein unbekanntes Icon darf nie das ganze Panel ausfallen lassen: weglassen und protokollieren. */
    private function usableIcon(Menu $menu): ?string
    {
        if (blank($menu->icon)) {
            return null;
        }

        if (KnownIcon::exists($menu->icon)) {
            return $menu->icon;
        }

        Log::warning('Menü-Eintrag mit unbekanntem Icon wird ohne Icon angezeigt.', ['menu_id' => $menu->id, 'icon' => $menu->icon]);

        return null;
    }

    private function hasTarget(Menu $menu): bool
    {
        return filled($menu->route) || filled($menu->url);
    }

    private function resolveUrl(Menu $menu): ?string
    {
        if (filled($menu->route)) {
            if (Route::has($menu->route)) {
                try {
                    return route($menu->route, $this->routeParameters($menu));
                } catch (UrlGenerationException) {
                    Log::warning('Menü-Eintrag mit unvollständigen Routenparametern wird ausgeblendet.', [
                        'menu_id' => $menu->id,
                        'route' => $menu->route,
                    ]);

                    return null;
                }
            }

            // Einmal pro Tag und Eintrag (z. B. nach dem Entfernen eines Plugins), nicht bei jedem Seitenaufruf.
            if (Cache::add('menu-unknown-route:'.$menu->id, true, now()->addDay())) {
                Log::warning('Menü-Eintrag verweist auf unbekannte Route und wird ausgeblendet.', [
                    'menu_id' => $menu->id,
                    'route' => $menu->route,
                ]);
            }

            return null;
        }

        return filled($menu->url) ? $menu->url : null;
    }

    /**
     * Index-Routen markieren alle Unterseiten der Resource als aktiv (z. B. ".users.*").
     */
    private function activeCallback(Menu $menu): \Closure
    {
        $route = $menu->route;

        if (blank($route)) {
            return static fn (): bool => false;
        }

        $pattern = Str::endsWith($route, '.index') ? Str::beforeLast($route, '.index').'.*' : $route;
        $parameters = $this->routeParameters($menu);

        // Eintrag ohne eigene Ansicht ist nicht aktiv, wenn gerade eine Ansicht (?view=) offen ist.
        if ($route === DataMenu::ROUTE && ! isset($parameters['view'])) {
            $parameters['view'] = '';
        }

        $patterns = [$pattern];

        // Bei Parametern (z. B. {table}, ?view=) nur aktiv, wenn auch diese übereinstimmen.
        return static fn (): bool => request()->routeIs(...$patterns)
            && collect($parameters)->every(fn (mixed $value, string $key): bool => (string) (request()->route($key) ?? request()->query($key)) === (string) $value);
    }

    /**
     * Routenparameter inkl. Ansicht: Einträge mit eigener Spaltenauswahl (view_config)
     * übergeben ihre ID als ?view=, damit die Datenseite die Ansicht laden kann.
     *
     * @return array<string, mixed>
     */
    private function routeParameters(Menu $menu): array
    {
        $parameters = (array) ($menu->route_parameters ?? []);

        if (! empty($menu->view_config)) {
            $parameters['view'] = $menu->id;
        }

        return $parameters;
    }

    /**
     * @param  list<NavigationItem>  $items
     */
    private function anyHasIcon(array $items): bool
    {
        foreach ($items as $item) {
            if ($item->getIcon() !== null) {
                return true;
            }
        }

        return false;
    }
}
