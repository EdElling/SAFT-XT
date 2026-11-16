<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Dashboard\DashboardLayout;
use App\Filament\Widgets\ReportCardWidget;
use App\Models\User;
use App\Plugins\Api\DashboardWidget;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Persönlich anpassbares Dashboard ohne Lücken: zwei Spalten wachsen unabhängig (halbbreite Karten),
 * darunter ein Bereich in voller Breite. „Dashboard anpassen“ schaltet einen Bearbeitungsmodus ein:
 * Karten per Drag & Drop verschieben, ausblenden, wieder einblenden. Die Anordnung steht beim Benutzer
 * (users.dashboard_layout) und gilt über Sitzungen und Geräte hinweg; Rechte bleiben maßgeblich.
 *
 * Angeheftete Auswertungen sind eigene Karten. Ihre Breite folgt dem Platz: in einer Spalte halb, im
 * breiten Bereich voll (Standard: Diagramme und Kennzahlen halb, Tabellen voll).
 */
class Dashboard extends BaseDashboard
{
    public bool $editing = false;

    /** Widget-Klasse => Schlüssel der Bezeichnung (dashboard.*), wo er vom Klassennamen abweicht. */
    private const array LABEL_KEYS = ['IdentityProviderStatusWidget' => 'idp_status', 'AccountWidget' => 'layout.account'];

    /**
     * Ziehen und Ablegen (SortableJS, von Filament geladen) im Raster innerhalb des Bereichs: ein Bereich nimmt
     * nur Karten passender Breite an; nach dem Ablegen geht die neue Anordnung aller Bereiche an saveLayout().
     */
    private const string SORTABLE_INIT = <<<'JS'
        window.Sortable.create($el.querySelector('.fi-grid') ?? $el, {
            group: {
                name: 'saft-dashboard',
                put: (to, from, item) => {
                    const span = item.querySelector('[data-dashboard-card]')?.dataset.span;
                    const area = to.el.closest('[data-dashboard-column]')?.dataset.dashboardColumn;
                    return span === 'flex' || (span === 'full' ? area === 'full' : area !== 'full');
                },
            },
            draggable: '>.fi-grid-col',
            handle: '[data-dashboard-handle]',
            forceFallback: true,
            fallbackOnBody: true,
            animation: 150,
            onEnd: () => {
                const columns = {};
                document.querySelectorAll('[data-dashboard-column]').forEach((column) => {
                    columns[column.dataset.dashboardColumn] = [...column.querySelectorAll('[data-dashboard-card]')].map((card) => card.dataset.dashboardCard);
                });
                $wire.saveLayout(columns);
            },
        })
        JS;

    /** @var array<string, array{widget: class-string<Widget>|WidgetConfiguration, span: string, default: string, label: string}>|null */
    private ?array $cards = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customizeDashboard')
                ->label(__('dashboard.layout.customize'))
                ->icon('heroicon-o-squares-plus')
                ->color('gray')
                ->visible(fn (): bool => ! $this->editing && $this->user() !== null)
                ->action(fn () => $this->editing = true),
            Action::make('resetDashboard')
                ->label(__('dashboard.layout.reset'))
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('dashboard.layout.reset_description'))
                ->visible(fn (): bool => $this->editing)
                ->action(function (): void {
                    $this->save(null);
                    Notification::make()->success()->title(__('dashboard.layout.reset_done'))->send();
                }),
            Action::make('finishDashboard')
                ->label(__('dashboard.layout.done'))
                ->icon('heroicon-o-check')
                ->visible(fn (): bool => $this->editing)
                ->action(fn () => $this->editing = false),
        ];
    }

    public function getWidgetsContentComponent(): Component
    {
        return Group::make(fn (): array => $this->layoutComponents());
    }

    // --- Anordnung ändern (nur eigene; Schlüssel werden gegen die erlaubten Karten geprüft) ----------------

    /** @param  array<string, mixed>  $columns  Bereich => Schlüssel in neuer Reihenfolge */
    public function saveLayout(array $columns): void
    {
        $this->save(DashboardLayout::store($this->cards(), $columns, $this->layout()['hidden']));
    }

    public function hideCard(string $key): void
    {
        $layout = $this->layout();

        if (! isset($this->cards()[$key])) {
            return;
        }

        foreach (DashboardLayout::COLUMNS as $column) {
            $layout[$column] = array_values(array_diff($layout[$column], [$key]));
        }

        $layout['hidden'][] = $key;
        $this->save($layout);
    }

    public function showCard(string $key): void
    {
        $layout = $this->layout();
        $layout['hidden'] = array_values(array_diff($layout['hidden'], [$key]));
        // Wieder eingeblendet: an den Standardplatz (resolve hängt fehlende Karten an).
        $this->save(DashboardLayout::resolve($this->cards(), $layout));
    }

    /** @param  array<string, mixed>|null  $layout */
    private function save(?array $layout): void
    {
        $this->user()?->forceFill(['dashboard_layout' => $layout])->save();
    }

    /** @return array{left: list<string>, right: list<string>, full: list<string>, hidden: list<string>} */
    public function layout(): array
    {
        return DashboardLayout::resolve($this->cards(), $this->user()?->fresh()?->dashboard_layout);
    }

    // --- Karten -------------------------------------------------------------------------------

    /**
     * Alle Karten, die die Person sehen darf, in Standardreihenfolge.
     *
     * @return array<string, array{widget: class-string<Widget>|WidgetConfiguration, span: string, default: string, label: string}>
     */
    public function cards(): array
    {
        if ($this->cards !== null) {
            return $this->cards;
        }

        $cards = [];

        foreach ($this->getWidgets() as $widget) {
            $class = $this->normalizeWidgetClass($widget);

            // Kacheln ausgeschalteter Plugins entfallen (ihre Livewire-Komponente wäre gesperrt).
            if (app(PluginStates::class)->blocks($class) || ! $class::canView()) {
                continue;
            }

            $span = $this->spansFullWidth($class) ? DashboardLayout::SPAN_FULL : DashboardLayout::SPAN_HALF;

            // Widgets aus Plugins: voller Klassenname als Schlüssel (keine Verwechslung gleichnamiger Widgets; Punkte
            // statt Backslashes, der Schlüssel steht in wire:click), Bezeichnung vom Widget selbst.
            if ($this->isPluginWidget($class)) {
                $label = is_subclass_of($class, DashboardWidget::class) ? $class::dashboardLabel() : class_basename($class);
                $cards['plugin:'.str_replace('\\', '.', $class)] = ['widget' => $widget, 'span' => $span, 'default' => $span, 'label' => $label];

                continue;
            }

            $name = class_basename($class);
            $labelKey = 'dashboard.'.(self::LABEL_KEYS[$name] ?? Str::snake(Str::beforeLast($name, 'Widget')));
            $cards[$name] = ['widget' => $widget, 'span' => $span, 'default' => $span, 'label' => __($labelKey) === $labelKey ? $name : __($labelKey)];
        }

        $user = $this->user();

        if ($user !== null && ReportCardWidget::canView()) {
            foreach ($user->dashboardReports()->orderBy('reports.name')->get() as $report) {
                if (! $user->can('view', $report)) {
                    continue;
                }

                $cards['report:'.$report->id] = [
                    'widget' => ReportCardWidget::make(['reportId' => $report->id]),
                    'span' => DashboardLayout::SPAN_FLEX,
                    'default' => ReportCardWidget::prefersFullWidth($report) ? DashboardLayout::SPAN_FULL : DashboardLayout::SPAN_HALF,
                    'label' => $report->name,
                ];
            }
        }

        return $this->cards = $cards;
    }

    /** @return list<Component> */
    private function layoutComponents(): array
    {
        $layout = $this->layout();
        $cards = $this->cards();
        $components = [];

        if ($this->editing) {
            $components[] = View::make('filament.dashboard.editor')->viewData([
                'hidden' => array_map(fn (string $key): array => ['key' => $key, 'label' => $cards[$key]['label']], $layout['hidden']),
            ]);
        }

        $halves = $layout[DashboardLayout::LEFT] !== [] || $layout[DashboardLayout::RIGHT] !== [];

        if ($halves || $this->editing) {
            $components[] = Grid::make(['default' => 1, 'lg' => 2])->schema([
                $this->column($layout[DashboardLayout::LEFT], DashboardLayout::LEFT),
                $this->column($layout[DashboardLayout::RIGHT], DashboardLayout::RIGHT),
            ]);
        }

        if ($layout[DashboardLayout::FULL] !== [] || $this->editing) {
            $components[] = $this->column($layout[DashboardLayout::FULL], DashboardLayout::FULL);
        }

        return $components;
    }

    /** @param  list<string>  $keys */
    private function column(array $keys, string $name): Component
    {
        $cards = $this->cards();
        $attributes = ['data-dashboard-column' => $name];

        if ($this->editing) {
            // Sortable je Bereich; ob eine Karte hinein darf, entscheidet ihre Breite (data-span).
            $attributes['x-data'] = '';
            $attributes['x-init'] = self::SORTABLE_INIT;
            $attributes['class'] = 'saft-dashboard-dropzone';
        }

        return Grid::make(1)
            ->schema(array_map(fn (string $key): Component => Group::make([
                View::make('filament.dashboard.card-toolbar')
                    ->viewData(['key' => $key, 'label' => $cards[$key]['label']])
                    ->visible($this->editing),
                // Eigener Livewire-Schlüssel je Karte: Filament nummeriert je Aufruf ab 0, sonst teilten sich alle
                // angehefteten Auswertungen „ReportCardWidget-0“ und Livewire zeigte nach dem Neuaufbau eine mehrfach.
                ...array_map(
                    static fn (Component $component): Component => $component->columnSpanFull()->key('dashboard-card-'.preg_replace('/[^A-Za-z0-9_-]/', '-', $key)),
                    $this->getWidgetsSchemaComponents([$cards[$key]['widget']]),
                ),
            ])->extraAttributes(['data-dashboard-card' => $key, 'data-span' => $cards[$key]['span'], 'wire:key' => 'card-'.$key]), $keys))
            ->extraAttributes($attributes);
    }

    /** Widget aus einem Plugin (im Namensraum einer installierten Plugin-Klasse)? */
    private function isPluginWidget(string $class): bool
    {
        foreach (app(PluginRegistry::class)->all() as $plugin) {
            if (str_starts_with($class, (new ReflectionClass($plugin))->getNamespaceName().'\\')) {
                return true;
            }
        }

        return false;
    }

    /** @param  class-string<Widget>  $class */
    private function spansFullWidth(string $class): bool
    {
        $span = (new $class)->getColumnSpan();

        return $span === 'full' || (is_array($span) && in_array('full', $span, true));
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
