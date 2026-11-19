<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Pages;

use App\Ai\AiConfig;
use App\Apps\CurrentApp;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\Snapshots\SnapshotService;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\Report;
use App\Models\User;
use App\Reports\Assistant\AssistantResult;
use App\Reports\Assistant\ReportAssistant;
use App\Reports\DefinitionResolver;
use App\Reports\Editor\DesignerState;
use App\Reports\Editor\FieldCatalog;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportDescriber;
use App\Reports\ReportExpression;
use App\Reports\ReportPresenter;
use App\Reports\ReportRunner;
use App\Support\Translations;
use App\Tasks\SnapshotSchedules;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Editor einer Auswertung wie eine Pivot-Tabelle: links die Felder (auch aus übergeordneten Tabellen),
 * rechts die Bereiche Zeilen, Spalten, Werte und Filter, in der Mitte die Live-Vorschau. Felder lassen
 * sich ziehen oder über „＋“ zuordnen (Tastatur, Screenreader). Gespeichert wird die Definition.
 */
class ReportDesigner extends Page
{
    protected static string $resource = ReportResource::class;

    protected string $view = 'filament.reports.designer';

    public const array ZONES = ['rows', 'columns', 'values', 'filters'];

    public ?int $recordId = null;

    /** @var array<string, mixed> DesignerState als Array */
    public array $state = [];

    /** @var array<string, mixed>|null Name, Beschreibung, geteilt */
    public ?array $meta = [];

    public string $search = '';

    /** Grundtabelle, die nach dem Dialog „Datenstand erneuern?“ gesetzt wird */
    public ?int $pendingBase = null;

    /** Gewünschte Grundtabelle (0 = Auswahl aufheben), die nach „Aufbau leeren?“ gesetzt wird */
    public ?int $pendingChange = null;

    /** KI-Assistent: Eingabe, Gesprächsverlauf (für Nachfragen), letzte Antwort bzw. Rückfrage */
    public string $assistantPrompt = '';

    /** @var list<array{role: string, content: string}> */
    public array $assistantHistory = [];

    public ?string $assistantReply = null;

    public ?string $assistantReplyKind = null;

    /** Zuletzt vom Assistenten gesetzter Name: Solange das Feld ihn noch zeigt, folgt der Name dem Assistenten. */
    public ?string $assistantTitle = null;

    public function mount(int|string|null $record = null): void
    {
        $user = $this->user();

        if ($record !== null) {
            $report = Report::query()->when($user !== null, fn ($q) => $q->visibleTo($user))->findOrFail($record);
            abort_unless($user?->can('update', $report) ?? false, 403);
            // Bearbeiten über einen Link: in die App der Auswertung wechseln (B-107).
            app(CurrentApp::class)->follow($user, $report->app_id);
            $this->recordId = $report->id;
            $this->state = DesignerState::fromDefinition($report->definitionObject())->toArray();
            $this->form->fill(['name' => $report->name, 'description' => $report->description, 'is_shared' => $report->is_shared]);
        } else {
            abort_unless($user?->can('create', Report::class) ?? false, 403);
            $this->state = (new DesignerState)->toArray();
            $this->form->fill(['is_shared' => false]);
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->recordId === null ? __('reports.ui.create_title') : (string) ($this->meta['name'] ?? __('reports.ui.model'));
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [ReportResource::getUrl('index') => ReportResource::getBreadcrumb(), '' => (string) $this->getTitle()];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('meta')
            ->components([
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    TextInput::make('name')->label(__('reports.ui.name'))->required()->maxLength(255)->columnSpan(['md' => 2]),
                    Textarea::make('description')->label(__('reports.ui.description'))->rows(1)->maxLength(2000)->columnSpan(['md' => 1]),
                    Toggle::make('is_shared')->label(__('reports.ui.shared'))->helperText(__('reports.ui.shared_help'))->inline(false),
                ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel')
                ->label(__('filament-panels::resources/pages/edit-record.form.actions.cancel.label'))
                ->color('gray')
                ->url(fn (): string => $this->recordId === null ? ReportResource::getUrl('index') : ReportResource::getUrl('view', ['record' => $this->recordId])),
            Action::make('save')
                ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->keyBindings(['mod+s'])
                ->action(fn () => $this->save()),
        ];
    }

    // --- Bereiche bearbeiten ------------------------------------------------------------

    /**
     * Grundtabelle wählen. Angebundene Tabellen (und die über Verknüpfungen erreichbaren) brauchen einen
     * Datenstand: fehlt er, wird er erzeugt; ist er älter als reports.snapshot_stale_hours, fragt ein Dialog.
     * Erzeugen darf jede Person mit Leserecht auf die Tabelle.
     */
    public function setBase(mixed $base): void
    {
        $this->changeBase(filled($base) ? (int) $base : null, confirmed: false, inAction: false);
    }

    /**
     * Ist schon eine Grundtabelle gewählt, sind nur die über Verknüpfungen erreichbaren wählbar; neu anfangen
     * geht über „Auswahl aufheben“. Hat die Auswertung schon Felder, wird vor dem Leeren gefragt.
     */
    private function changeBase(?int $id, bool $confirmed, bool $inAction): void
    {
        $current = $this->state['base'] ?? null;

        if ($id === $current) {
            return;
        }

        if ($id !== null && ! $this->selectable($id)) {
            return;
        }

        if (! $confirmed && $this->hasFields()) {
            $this->pendingChange = $id ?? 0;
            $this->openAction('confirmBaseChange', $inAction);

            return;
        }

        $table = $id === null ? null : CustomTable::query()->find($id);

        if ($table === null) {
            $this->applyBase(null);

            return;
        }

        if ($this->staleTables($table) !== []) {
            $this->pendingBase = $table->id;
            $this->openAction('refreshSnapshots', $inAction);

            return;
        }

        $this->useSnapshots($table, refreshStale: false);
    }

    /** Lesbar und – wenn schon eine Grundtabelle gewählt ist – mit ihr über Verknüpfungen verbunden. */
    private function selectable(int $id): bool
    {
        $current = $this->state['base'] ?? null;

        return array_key_exists($id, $this->catalog()->baseTables($this->user()))
            && ($current === null || in_array($id, $this->catalog()->reachableFrom((int) $current, $this->user()), true));
    }

    private function hasFields(): bool
    {
        return collect(self::ZONES)->contains(fn (string $zone): bool => ($this->state[$zone] ?? []) !== []);
    }

    /** Aus einem laufenden Dialog heraus den nächsten Dialog an dessen Stelle setzen. */
    private function openAction(string $name, bool $inAction): void
    {
        $inAction ? $this->replaceMountedAction($name) : $this->mountAction($name);
    }

    public function confirmBaseChangeAction(): Action
    {
        return Action::make('confirmBaseChange')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $this->pendingChange === 0 ? __('reports.ui.clear_base_heading') : __('reports.ui.change_base_heading'))
            ->modalDescription(__('reports.ui.change_base_description'))
            ->modalSubmitActionLabel(__('reports.ui.change_base_confirm'))
            ->color('warning')
            ->action(function (): void {
                $id = $this->pendingChange;
                $this->pendingChange = null;

                if ($id !== null) {
                    $this->changeBase($id === 0 ? null : $id, confirmed: true, inAction: true);
                }
            });
    }

    private function applyBase(?int $base): void
    {
        $this->state = (new DesignerState(base: $base))->toArray();
    }

    /** Fehlende Stände erzeugen (auf Wunsch auch veraltete erneuern), dann die Tabelle setzen. */
    private function useSnapshots(CustomTable $base, bool $refreshStale): void
    {
        $user = $this->user();
        $stale = array_map(fn (CustomTable $t): int => $t->id, $this->staleTables($base));
        $created = [];

        foreach ($this->catalog()->externalTablesFor($base, $user) as $table) {
            $missing = $this->catalog()->snapshotAt($table) === null;

            if (! $missing && ! ($refreshStale && in_array($table->id, $stale, true))) {
                continue;
            }

            try {
                app(SnapshotService::class)->refresh($table, $user);
                $created[] = (string) $table->label;
            } catch (BusinessRuleException $e) {
                Notification::make()->danger()->persistent()
                    ->title(__('reports.ui.snapshot_failed', ['table' => $table->label]))
                    ->body($e->getMessage())
                    ->send();
            }
        }

        $this->catalog()->forgetSnapshots();

        if ($created !== []) {
            Notification::make()->success()->title(__('reports.ui.snapshot_created', ['tables' => implode(', ', $created)]))->send();
        }

        // Ohne eigenen Stand ist die Tabelle nicht auswertbar; bei verknüpften fehlen nur deren Felder.
        if ($base->isExternal() && $this->catalog()->snapshotAt($base) === null) {
            return;
        }

        $this->applyBase($base->id);
    }

    /**
     * Angebundene Tabellen mit veraltetem Datenstand: älter als reports.snapshot_stale_hours, mit Zeitplan älter als
     * zwei Intervalle (der Zeitplan greift dann offenbar nicht).
     *
     * @return list<CustomTable>
     */
    private function staleTables(CustomTable $base): array
    {
        $schedules = app(SnapshotSchedules::class);

        return array_values(array_filter(
            $this->catalog()->externalTablesFor($base, $this->user()),
            fn (CustomTable $t): bool => $this->catalog()->snapshotAt($t)?->lt(now()->subHours($schedules->staleHours($t))) ?? false,
        ));
    }

    public function refreshSnapshotsAction(): Action
    {
        $base = fn (): ?CustomTable => $this->pendingBase === null ? null : CustomTable::query()->find($this->pendingBase);
        $finish = function (bool $refresh) use ($base): void {
            $table = $base();
            $this->pendingBase = null;

            if ($table !== null) {
                $this->useSnapshots($table, $refresh);
            }
        };

        return Action::make('refreshSnapshots')
            ->modalHeading(__('reports.ui.stale_heading'))
            ->modalDescription(function () use ($base): HtmlString {
                $table = $base();
                $scheduled = false;
                $items = $table === null ? [] : array_map(
                    function (CustomTable $t) use (&$scheduled): string {
                        $time = $this->catalog()->snapshotAt($t)?->timezone(config('app.timezone'))->format('d.m.Y H:i');
                        $task = app(SnapshotSchedules::class)->for($t)->first();
                        $scheduled = $scheduled || $task !== null;

                        return '<li>'.e($task === null
                            ? __('reports.ui.stale_item', ['table' => $t->label, 'time' => $time])
                            : __('reports.ui.stale_item_scheduled', ['table' => $t->label, 'time' => $time, 'schedule' => $task->schedule()->describe()])).'</li>';
                    },
                    $this->staleTables($table),
                );

                return new HtmlString(e($scheduled
                    ? __('reports.ui.stale_description_scheduled')
                    : __('reports.ui.stale_description', ['hours' => (int) config('reports.snapshot_stale_hours', 24)]))
                    .'<ul style="list-style: disc; padding-inline-start: 1.25rem; text-align: start">'.implode('', $items).'</ul>');
            })
            ->modalIcon('heroicon-o-arrow-path')
            ->modalSubmitActionLabel(__('reports.ui.refresh_snapshots'))
            ->extraModalFooterActions([
                Action::make('useExisting')
                    ->label(__('reports.ui.use_existing'))
                    ->color('gray')
                    ->cancelParentActions()
                    ->action(fn () => $finish(false)),
            ])
            ->action(fn () => $finish(true));
    }

    public function add(string $zone, string $ref, ?int $index = null): void
    {
        $field = $this->catalog()->field($this->state['base'] ?? null, $this->user(), $ref);

        if (! in_array($zone, self::ZONES, true) || $field === null) {
            return;
        }

        $item = match ($zone) {
            'values' => ['id' => DesignerState::newValueId(), 'agg' => $this->defaultAggregate($field), 'ref' => $ref, 'label' => null],
            'filters' => ['ref' => $ref, 'op' => $this->defaultOperator($field), 'value' => null, 'value_to' => null, 'values' => []],
            default => ['ref' => $ref, 'bucket' => null, 'label' => null],
        };

        if ($zone === 'columns') {
            $this->state['columns'] = [$item];
        } else {
            $list = $this->state[$zone];
            array_splice($list, $index === null ? count($list) : max(0, min($index, count($list))), 0, [$item]);
            $this->state[$zone] = $list;
        }

        $this->afterChange();

        // Ein Filter ohne Wert filtert nicht: gleich nach dem Wert fragen.
        if ($zone === 'filters' && ! in_array($item['op'], ['is_true', 'is_empty', 'not_empty', 'this_year'], true)) {
            $this->mountAction('filter', ['index' => $index ?? count($this->state['filters']) - 1]);
        }
    }

    /** Wert „Anzahl Datensätze“ (ohne Feld). */
    public function addCount(): void
    {
        $this->state['values'][] = ['id' => DesignerState::newValueId(), 'agg' => 'count', 'ref' => null, 'label' => null];
        $this->afterChange();
    }

    public function remove(string $zone, int $index): void
    {
        if (in_array($zone, self::ZONES, true) && isset($this->state[$zone][$index])) {
            array_splice($this->state[$zone], $index, 1);
            $this->state[$zone] = array_values($this->state[$zone]);
            $this->state['sort'] = null;
            $this->afterChange();
        }
    }

    public function move(string $zone, int $from, int $to): void
    {
        $this->moveBetween($zone, $from, $zone, $to);
    }

    /** Ziehen zwischen Bereichen: ein Feld behält Referenz und Bezeichnung. */
    public function moveBetween(string $fromZone, int $from, string $toZone, int $to): void
    {
        if (! in_array($fromZone, self::ZONES, true) || ! in_array($toZone, self::ZONES, true) || ! isset($this->state[$fromZone][$from])) {
            return;
        }

        $item = $this->state[$fromZone][$from];
        array_splice($this->state[$fromZone], $from, 1);
        $this->state[$fromZone] = array_values($this->state[$fromZone]);

        if ($fromZone === $toZone) {
            array_splice($this->state[$toZone], max(0, min($to, count($this->state[$toZone]))), 0, [$item]);
            $this->state['sort'] = null;
            $this->afterChange();

            return;
        }

        if (($item['ref'] ?? null) === null) {
            $this->afterChange();

            return;
        }

        $this->add($toZone, (string) $item['ref'], $to);
    }

    public function setBucket(string $zone, int $index, ?string $bucket): void
    {
        if (in_array($zone, ['rows', 'columns'], true) && isset($this->state[$zone][$index])) {
            $this->state[$zone][$index]['bucket'] = in_array($bucket, ReportDefinition::BUCKETS, true) ? $bucket : null;
            $this->afterChange();
        }
    }

    public function setAggregate(int $index, string $agg): void
    {
        $allowed = match (true) {
            ($this->state['values'][$index]['agg'] ?? null) === ReportDefinition::CALC => [],
            is_array($this->state['values'][$index]['expr'] ?? null) => ['sum', 'avg', 'min', 'max'],
            default => ReportDefinition::AGGREGATES,
        };

        if (isset($this->state['values'][$index]) && in_array($agg, $allowed, true)) {
            $this->state['values'][$index]['agg'] = $agg;
            $this->afterChange();
        }
    }

    public function setVisual(string $visual): void
    {
        $this->state['visual'] = $visual;
        $this->afterChange();
    }

    /** Klick auf einen Spaltenkopf der Vorschau: aufsteigend, absteigend, Vorgabe. */
    public function sortBy(string $token): void
    {
        if (($this->state['sort'] ?? null) !== $token) {
            [$this->state['sort'], $this->state['sortDir']] = [$token, 'asc'];
        } elseif ($this->state['sortDir'] === 'asc') {
            $this->state['sortDir'] = 'desc';
        } else {
            [$this->state['sort'], $this->state['sortDir']] = [null, 'asc'];
        }
    }

    public function updatedState(mixed $value, string $key): void
    {
        if ($key !== 'limit') {
            return;
        }

        $limit = (int) ($this->state['limit'] ?? 0);
        $this->state['limit'] = $limit > 0 ? min($limit, (int) config('reports.max_rows')) : null;
    }

    // --- Dialoge -------------------------------------------------------------------------

    public function filterAction(): Action
    {
        return Action::make('filter')
            ->modalHeading(fn (array $arguments): string => __('reports.ui.filter_heading', ['field' => $this->fieldLabel($this->state['filters'][$arguments['index']]['ref'] ?? '')]))
            ->modalWidth('lg')
            ->fillForm(fn (array $arguments): array => $this->state['filters'][$arguments['index']] ?? [])
            ->schema(function (array $arguments): array {
                $field = $this->catalog()->field($this->state['base'] ?? null, $this->user(), (string) ($this->state['filters'][$arguments['index']]['ref'] ?? ''));

                return $field === null ? [] : $this->filterComponents($field);
            })
            ->action(function (array $data, array $arguments): void {
                $index = (int) $arguments['index'];

                if (isset($this->state['filters'][$index])) {
                    $this->state['filters'][$index] = [...$this->state['filters'][$index], ...$data];
                    $this->afterChange();
                }
            });
    }

    public function labelAction(): Action
    {
        return Action::make('label')
            ->modalHeading(__('reports.ui.label'))
            ->modalWidth('md')
            ->fillForm(fn (array $arguments): array => ['label' => $this->state[$arguments['zone']][$arguments['index']]['label'] ?? null])
            ->schema([TextInput::make('label')->label(__('reports.ui.label'))->placeholder(__('reports.ui.label_placeholder'))->maxLength(100)])
            ->action(function (array $data, array $arguments): void {
                if (in_array($arguments['zone'], ['rows', 'values'], true) && isset($this->state[$arguments['zone']][$arguments['index']])) {
                    $this->state[$arguments['zone']][$arguments['index']]['label'] = filled($data['label']) ? $data['label'] : null;
                    $this->afterChange();
                }
            });
    }

    /**
     * Berechneter Wert (zone values) bzw. berechnete Listenspalte (zone rows): Formel mit Feldern in eckigen
     * Klammern; ein Klick in der Feldliste fügt ein Feld an der Schreibmarke ein.
     */
    public function formulaAction(): Action
    {
        return Action::make('formula')
            ->modalHeading(fn (array $arguments): string => __(($arguments['zone'] ?? '') === 'values' ? 'reports.ui.formula.value' : 'reports.ui.formula.column'))
            ->modalWidth('3xl')
            ->fillForm(function (array $arguments): array {
                $item = isset($arguments['index']) ? ($this->state[$arguments['zone']][$arguments['index']] ?? []) : [];

                return [
                    'formula' => is_array($item['expr'] ?? null) ? ReportExpression::text($item['expr'], fn (string $ref): string => $this->fieldLabel($ref)) : '',
                    'agg' => $item['agg'] ?? 'sum',
                    'label' => $item['label'] ?? null,
                    'scale' => $item['scale'] ?? 2,
                ];
            })
            ->schema(fn (array $arguments): array => [
                Textarea::make('formula')
                    ->label(__('reports.ui.formula.formula'))
                    ->placeholder('[Menge] × [Artikel › Preis]')
                    ->rows(2)
                    ->required()
                    ->live(debounce: 600)
                    // Nach jeder Änderung neu prüfen: alter Fehler weg, neuer Fehler sofort sichtbar.
                    ->afterStateUpdated(function (Textarea $component): void {
                        $this->resetErrorBag($component->getStatePath());
                        $this->validateOnly($component->getStatePath());
                    })
                    ->extraInputAttributes(['data-formula-input' => true, 'spellcheck' => 'false'])
                    ->helperText(__('reports.ui.formula.help'))
                    ->hint(fn (?string $state): ?string => blank($state) ? null : ($this->formulaProblems((string) $state)[0] ?? __('reports.ui.formula.ok')))
                    ->hintColor(fn (?string $state): string => blank($state) || $this->formulaProblems((string) $state) === [] ? 'success' : 'danger')
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        foreach ($this->formulaProblems((string) $value) as $message) {
                            $fail($message);
                        }
                    }]),
                View::make('filament.components.formula-picker')
                    ->viewData([
                        'groups' => [[
                            'label' => __('reports.ui.formula.fields'),
                            'items' => array_map(fn (string $label): array => ['label' => $label, 'insert' => '['.$label.']'], $this->numericFieldLabels()),
                        ]],
                        'buttons' => [
                            ...array_map(fn (string $s): array => ['label' => $s, 'insert' => $s === ';' ? '; ' : ' '.$s.' '], ['+', '−', '×', '÷', '(', ')', ';']),
                            ...array_map(fn (string $f): array => ['label' => $f, 'insert' => $f.'('], ['round', 'abs', 'min', 'max', 'coalesce']),
                        ],
                        'empty' => __('reports.ui.formula.no_fields'),
                    ]),
                Grid::make(3)->schema([
                    Select::make('agg')
                        ->label(__('reports.ui.aggregate'))
                        ->options(collect(['sum', 'avg', 'min', 'max'])->mapWithKeys(fn (string $a): array => [$a => __('reports.aggregates.'.$a)])->all())
                        ->selectablePlaceholder(false)
                        ->required()
                        ->visible(($arguments['zone'] ?? '') === 'values'),
                    TextInput::make('label')
                        ->label(__('reports.ui.label'))
                        ->placeholder(__('reports.ui.formula.label_placeholder'))
                        ->maxLength(100),
                    TextInput::make('scale')
                        ->label(__('reports.ui.formula.scale'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(ReportExpression::MAX_SCALE)
                        ->required(),
                ]),
            ])
            ->action(function (array $data, array $arguments): void {
                $zone = in_array($arguments['zone'] ?? null, ['rows', 'values'], true) ? $arguments['zone'] : null;

                if ($zone === null) {
                    return;
                }

                $item = [
                    'ref' => null,
                    'expr' => $this->parseFormula((string) $data['formula']),
                    'label' => filled($data['label'] ?? null) ? trim((string) $data['label']) : null,
                    'scale' => max(0, min(ReportExpression::MAX_SCALE, (int) $data['scale'])),
                    ...($zone === 'values'
                        ? ['id' => $this->state['values'][$arguments['index'] ?? -1]['id'] ?? DesignerState::newValueId(), 'agg' => (string) $data['agg']]
                        : ['bucket' => null]),
                ];

                if (isset($arguments['index']) && isset($this->state[$zone][$arguments['index']])) {
                    $this->state[$zone][$arguments['index']] = $item;
                } else {
                    $this->state[$zone][] = $item;
                }

                $this->afterChange();
            });
    }

    /**
     * Kennzahl aus Werten: Formel über die übrigen Werte derselben Zeile, z. B. [Budget] ÷ [Anzahl]. Die Knöpfe fügen
     * die Bezeichnungen der Werte ein; gespeichert wird die Formel mit deren fester Kennung.
     */
    public function calcAction(): Action
    {
        return Action::make('calc')
            ->modalHeading(__('reports.ui.calc.title'))
            ->modalDescription(__('reports.ui.calc.description'))
            ->modalWidth('3xl')
            ->mountUsing(function (): void {
                $this->ensureValueIds();
            })
            ->fillForm(function (array $arguments): array {
                $item = isset($arguments['index']) ? ($this->state['values'][$arguments['index']] ?? []) : [];
                $labels = $this->valueLabels();

                return [
                    'formula' => is_array($item['calc'] ?? null) ? ReportExpression::text($item['calc'], fn (string $id): string => $labels[$id] ?? $id) : '',
                    'label' => $item['label'] ?? null,
                    'scale' => $item['scale'] ?? 2,
                ];
            })
            ->schema(fn (array $arguments): array => [
                Textarea::make('formula')
                    ->label(__('reports.ui.formula.formula'))
                    ->placeholder(__('reports.ui.calc.placeholder'))
                    ->rows(2)
                    ->required()
                    ->live(debounce: 600)
                    // Nach jeder Änderung neu prüfen: alter Fehler weg, neuer Fehler sofort sichtbar.
                    ->afterStateUpdated(function (Textarea $component): void {
                        $this->resetErrorBag($component->getStatePath());
                        $this->validateOnly($component->getStatePath());
                    })
                    ->extraInputAttributes(['data-formula-input' => true, 'spellcheck' => 'false'])
                    ->helperText(__('reports.ui.calc.help'))
                    ->hint(fn (?string $state): ?string => blank($state) ? null : ($this->calcProblems((string) $state, $arguments['index'] ?? null)[0] ?? __('reports.ui.formula.ok')))
                    ->hintColor(fn (?string $state): string => blank($state) || $this->calcProblems((string) $state, $arguments['index'] ?? null) === [] ? 'success' : 'danger')
                    ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($arguments): void {
                        foreach ($this->calcProblems((string) $value, $arguments['index'] ?? null) as $message) {
                            $fail($message);
                        }
                    }]),
                View::make('filament.components.formula-picker')
                    ->viewData([
                        'groups' => [[
                            'label' => __('reports.ui.calc.values'),
                            'items' => array_values(array_map(
                                fn (string $label): array => ['label' => $label, 'insert' => '['.$label.']'],
                                $this->calcSources($arguments['index'] ?? null),
                            )),
                        ]],
                        'buttons' => [
                            ...array_map(fn (string $s): array => ['label' => $s, 'insert' => $s === ';' ? '; ' : ' '.$s.' '], ['+', '−', '×', '÷', '(', ')', ';']),
                            ...array_map(fn (string $f): array => ['label' => $f, 'insert' => $f.'('], ['round', 'abs', 'min', 'max', 'coalesce']),
                        ],
                        'empty' => __('reports.ui.calc.no_values'),
                    ]),
                Grid::make(2)->schema([
                    TextInput::make('label')
                        ->label(__('reports.ui.label'))
                        ->placeholder(__('reports.ui.formula.label_placeholder'))
                        ->maxLength(100),
                    TextInput::make('scale')
                        ->label(__('reports.ui.formula.scale'))
                        ->integer()
                        ->minValue(0)
                        ->maxValue(ReportExpression::MAX_SCALE)
                        ->required(),
                ]),
            ])
            ->action(function (array $data, array $arguments): void {
                $index = $arguments['index'] ?? null;
                $item = [
                    'id' => $this->state['values'][$index ?? -1]['id'] ?? DesignerState::newValueId(),
                    'agg' => ReportDefinition::CALC,
                    'ref' => null,
                    'calc' => $this->parseCalc((string) $data['formula'], $index),
                    'label' => filled($data['label'] ?? null) ? trim((string) $data['label']) : null,
                    'scale' => max(0, min(ReportExpression::MAX_SCALE, (int) $data['scale'])),
                ];

                if ($index !== null && isset($this->state['values'][$index])) {
                    $this->state['values'][$index] = $item;
                } else {
                    $this->state['values'][] = $item;
                }

                $this->afterChange();
            });
    }

    /** Werte ohne feste Kennung (z. B. aus einem Vorschlag des Assistenten) bekommen eine. */
    private function ensureValueIds(): void
    {
        foreach ($this->state['values'] ?? [] as $i => $value) {
            if (! is_string($value['id'] ?? null)) {
                $this->state['values'][$i]['id'] = DesignerState::newValueId();
            }
        }
    }

    /** @return array<string, string> Kennung => Bezeichnung jedes Werts und jeder gruppierten Spalte („@<Feldreferenz>“) */
    public function valueLabels(): array
    {
        $labels = [];

        foreach ($this->state['rows'] ?? [] as $row) {
            if (filled($row['ref'] ?? null)) {
                $labels['@'.$row['ref']] ??= $row['label'] ?? $this->fieldLabel((string) $row['ref']);
            }
        }

        foreach ($this->state['values'] ?? [] as $value) {
            if (! is_string($value['id'] ?? null) || ($value['agg'] ?? null) === ReportDefinition::CALC) {
                continue;
            }

            $labels[$value['id']] = $value['label'] ?? match (true) {
                is_array($value['expr'] ?? null) => __('reports.measure_label', ['agg' => __('reports.aggregates.'.$value['agg']), 'field' => $this->formulaText($value['expr'])]),
                blank($value['ref'] ?? null) => __('reports.describe.records'),
                default => __('reports.measure_label', ['agg' => __('reports.aggregates.'.$value['agg']), 'field' => $this->fieldLabel((string) $value['ref'])]),
            };
        }

        foreach ($this->state['values'] ?? [] as $value) {
            if (is_string($value['id'] ?? null) && ($value['agg'] ?? null) === ReportDefinition::CALC) {
                $labels[$value['id']] = $value['label'] ?? ReportExpression::text((array) ($value['calc'] ?? []), fn (string $id): string => $labels[$id] ?? $id);
            }
        }

        // In Formeln stehen Bezeichnungen in eckigen Klammern; eigene Klammern würden sie zerlegen.
        return array_map(fn (string $label): string => str_replace(['[', ']'], ['(', ')'], $label), $labels);
    }

    /**
     * Worauf eine Kennzahl aus Werten verweisen darf: gruppierte Zahlenspalten ohne Stufe (je Zeile ein Wert) und die
     * übrigen gewöhnlichen Werte.
     *
     * @return array<string, string> Kennung => Bezeichnung
     */
    private function calcSources(?int $except): array
    {
        $sources = [];
        $kinds = $this->fieldKinds();

        foreach ($this->state['rows'] ?? [] as $row) {
            if (filled($row['ref'] ?? null) && blank($row['bucket'] ?? null) && ($kinds[$row['ref']] ?? null) === 'number') {
                $sources['@'.$row['ref']] = $this->valueLabels()['@'.$row['ref']];
            }
        }

        foreach ($this->state['values'] ?? [] as $i => $value) {
            if ($i !== $except && is_string($value['id'] ?? null) && ($value['agg'] ?? null) !== ReportDefinition::CALC) {
                $sources[$value['id']] = $this->valueLabels()[$value['id']];
            }
        }

        return $sources;
    }

    /** @return array<string, mixed> Formelbaum mit den Kennungen der Werte */
    private function parseCalc(string $text, ?int $except): array
    {
        return ReportExpression::parse($text, array_flip($this->calcSources($except)));
    }

    /** @return list<string> */
    private function calcProblems(string $text, ?int $except): array
    {
        if (blank($text)) {
            return [];
        }

        try {
            $tree = $this->parseCalc($text, $except);
        } catch (InvalidReportException $e) {
            return $e->errors;
        }

        $kinds = $this->fieldKinds();
        $numeric = array_map(fn (): bool => true, $this->calcSources($except));

        foreach ($this->state['values'] ?? [] as $value) {
            if (is_string($value['id'] ?? null)) {
                $numeric[$value['id']] = is_array($value['expr'] ?? null)
                    || in_array($value['agg'] ?? null, ['count', 'count_distinct', 'sum', 'avg'], true)
                    || ($kinds[$value['ref'] ?? ''] ?? null) === 'number';
            }
        }

        return ReportExpression::problems($tree, fn (string $id): ?bool => $numeric[$id] ?? null, fn (string $id): string => $this->valueLabels()[$id] ?? $id);
    }

    /** @return array<string, string> Feldreferenz => Art (number, text, date …) */
    private function fieldKinds(): array
    {
        return collect($this->catalog()->entries($this->state['base'] ?? null, $this->user()))
            ->mapWithKeys(fn (array $e): array => [$e['ref'] => $e['field']->type === FieldType::Relation ? 'relation' : DefinitionResolver::kindOf($e['field']->valueType())])
            ->all();
    }

    /** @return array<string, mixed> Formelbaum mit Feldreferenzen */
    private function parseFormula(string $text): array
    {
        $keys = [];

        foreach ($this->catalog()->entries($this->state['base'] ?? null, $this->user()) as $entry) {
            $keys[$entry['label']] ??= $entry['ref'];
        }

        return ReportExpression::parse($text, $keys);
    }

    /** @return list<string> */
    private function formulaProblems(string $text): array
    {
        if (blank($text)) {
            return [];
        }

        try {
            $tree = $this->parseFormula($text);
        } catch (InvalidReportException $e) {
            return $e->errors;
        }

        $kinds = collect($this->catalog()->entries($this->state['base'] ?? null, $this->user()))
            ->mapWithKeys(fn (array $e): array => [$e['ref'] => $e['field']->type === FieldType::Relation ? 'relation' : DefinitionResolver::kindOf($e['field']->valueType())]);

        return ReportExpression::problems(
            $tree,
            fn (string $ref): ?bool => $kinds->has($ref) ? $kinds[$ref] === 'number' : null,
            fn (string $ref): string => $this->fieldLabel($ref),
        );
    }

    /** @return list<string> Bezeichnungen der erreichbaren Zahlenfelder (für die Feldliste im Formeldialog) */
    private function numericFieldLabels(): array
    {
        return collect($this->catalog()->entries($this->state['base'] ?? null, $this->user()))
            ->filter(fn (array $e): bool => $e['field']->type !== FieldType::Relation && DefinitionResolver::kindOf($e['field']->valueType()) === 'number')
            ->map(fn (array $e): string => $e['label'])
            ->unique()
            ->values()
            ->all();
    }

    /** Bezeichnung einer berechneten Zeile bzw. eines berechneten Werts im Aufbau. */
    public function formulaText(array $expr): string
    {
        return ReportExpression::text($expr, fn (string $ref): string => $this->fieldLabel($ref));
    }

    // --- Speichern -----------------------------------------------------------------------

    public function save(): void
    {
        $meta = $this->form->getState();
        [$definition] = DesignerState::fromArray($this->state)->toDefinition();

        try {
            app(ReportRunner::class)->resolve($definition, $this->user());
        } catch (InvalidReportException $e) {
            Notification::make()
                ->title(__('reports.ui.invalid'))
                ->body('<ul style="list-style: disc; padding-inline-start: 1.25rem">'.collect($e->errors)->map(fn (string $m): string => '<li>'.e($m).'</li>')->implode('').'</ul>')
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        $data = ['name' => $meta['name'], 'description' => $meta['description'] ?? null, 'is_shared' => (bool) ($meta['is_shared'] ?? false), 'definition' => $definition->toArray()];
        $report = $this->recordId === null
            ? Report::create([...$data, 'owner_id' => $this->user()?->id])
            : tap(Report::query()->findOrFail($this->recordId))->update($data);

        Notification::make()->title(__('filament-panels::resources/pages/edit-record.notifications.saved.title'))->success()->send();
        $this->redirect(ReportResource::getUrl('view', ['record' => $report]));
    }

    // --- KI-Assistent ------------------------------------------------------------------

    /** Beschreibung an den Assistenten; ein gültiger Vorschlag ersetzt den Aufbau im Editor (gespeichert wird erst mit „Speichern“). */
    public function askAssistant(): void
    {
        $user = $this->user();
        abort_unless(ReportAssistant::availableFor($user), 403);

        try {
            $result = app(ReportAssistant::class)->propose($user, $this->assistantPrompt, $this->state, $this->assistantHistory);
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        $this->assistantReplyKind = $result->status;

        if ($result->status === AssistantResult::ERROR) {
            $this->assistantReply = $result->message;

            return;
        }

        $this->assistantHistory = $result->history;
        $this->assistantPrompt = '';

        if ($result->status === AssistantResult::QUESTION) {
            $this->assistantReply = $result->message;

            return;
        }

        $this->state = DesignerState::fromArray($result->state ?? [])->toArray();
        $this->afterChange();
        $this->assistantReply = __('assistant.applied');

        // Der Name folgt dem Assistenten, solange niemand ihn von Hand eingetragen oder geändert hat.
        if (filled($result->title) && $this->nameFollowsAssistant()) {
            $this->meta['name'] = $result->title;
            $this->assistantTitle = $result->title;
        }
    }

    private function nameFollowsAssistant(): bool
    {
        $name = $this->meta['name'] ?? null;

        return blank($name) || ($this->assistantTitle !== null && $name === $this->assistantTitle);
    }

    public function resetAssistant(): void
    {
        $this->assistantHistory = [];
        $this->assistantReply = null;
        $this->assistantReplyKind = null;
        $this->assistantPrompt = '';

        // Neu beginnen: einen vom Assistenten gesetzten Namen leeren, der nächste Vorschlag setzt ihn neu.
        if ($this->assistantTitle !== null && ($this->meta['name'] ?? null) === $this->assistantTitle) {
            $this->meta['name'] = null;
        }

        $this->assistantTitle = null;
    }

    // --- Ansicht -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $state = DesignerState::fromArray($this->state);
        [$definition, $tokens] = $state->toDefinition();
        $user = $this->user();
        $hasContent = $state->base !== null && ($state->rows !== [] || $state->values !== []);
        $view = $hasContent
            ? app(ReportPresenter::class)->present($definition, $user, (int) config('reports.preview_rows', 100))
            : ['result' => null, 'problems' => [], 'visual' => $state->visual, 'formatter' => null, 'chart' => null];

        return [
            ...$view,
            'baseTables' => $this->catalog()->baseTableOptions($user),
            'reachable' => $state->base === null ? null : $this->catalog()->reachableFrom($state->base, $user),
            'catalog' => $this->catalogGroups(),
            'labels' => collect($this->catalog()->entries($state->base, $user))->mapWithKeys(fn (array $e): array => [$e['ref'] => $e['label']])->all(),
            'kinds' => collect($this->catalog()->entries($state->base, $user))->mapWithKeys(fn (array $e): array => [$e['ref'] => DefinitionResolver::kindOf($e['field']->valueType())])->all(),
            'visuals' => collect(ReportDefinition::VISUALS)->mapWithKeys(fn (string $t): array => [$t => DefinitionResolver::visualReason($definition, $t)])->all(),
            'sentence' => $view['result'] !== null ? app(ReportDescriber::class)->describe($view['result']->report) : null,
            'sortTokens' => $tokens,
            'hasContent' => $hasContent,
            'grouped' => $state->isGrouped(),
            'assistantAvailable' => $assistant = ReportAssistant::availableFor($user),
            'assistantRemaining' => $assistant && $user !== null ? ReportAssistant::remainingToday($user) : 0,
            'assistantProvider' => app(AiConfig::class)->providerLabel(),
        ];
    }

    /**
     * Felder gruppiert nach Pfad, gefiltert nach Suchtext.
     *
     * @return array<string, list<array{ref: string, label: string, name: string, kind: string, depth: int}>>
     */
    private function catalogGroups(): array
    {
        $groups = [];
        $search = mb_strtolower(trim($this->search));

        foreach ($this->catalog()->entries($this->state['base'] ?? null, $this->user()) as $entry) {
            $name = (string) $entry['field']->label;

            if ($search !== '' && ! str_contains(mb_strtolower($entry['label']), $search)) {
                continue;
            }

            $groups[$entry['group']][] = [
                'ref' => $entry['ref'],
                'label' => $entry['label'],
                'name' => $name,
                'kind' => $entry['field']->type === FieldType::Relation ? 'relation' : DefinitionResolver::kindOf($entry['field']->valueType()),
                'depth' => count($entry['path']),
            ];
        }

        return $groups;
    }

    /** Darstellung zurücksetzen, wenn sie nach einer Änderung nicht mehr passt. */
    private function afterChange(): void
    {
        [$definition] = DesignerState::fromArray($this->state)->toDefinition();

        if (DefinitionResolver::visualReason($definition, (string) $this->state['visual']) !== null) {
            $this->state['visual'] = DefinitionResolver::visualReason($definition, 'bar') === null && ($this->state['visual'] ?? '') !== 'table' ? 'bar' : 'table';
        }
    }

    private function defaultAggregate(CustomField $field): string
    {
        return match (DefinitionResolver::kindOf($field->valueType())) {
            'number' => 'sum',
            'relation' => 'count_distinct',
            default => 'count',
        };
    }

    private function defaultOperator(CustomField $field): string
    {
        return match (DefinitionResolver::kindOf($field->valueType())) {
            'boolean' => 'is_true',
            'date' => 'this_year',
            'text' => $field->type === FieldType::Select ? 'eq' : 'contains',
            default => 'eq',
        };
    }

    /** @return list<Component|Field> */
    private function filterComponents(CustomField $field): array
    {
        $kind = DefinitionResolver::kindOf($field->valueType());
        $options = $field->type === FieldType::Select
            ? collect((array) ($field->optionsArray()['options'] ?? []))->filter(fn ($o): bool => is_array($o) && isset($o['value']))
                ->mapWithKeys(fn (array $o): array => [(string) $o['value'] => Translations::pick($o['label'] ?? null, (string) $o['value'])])->all()
            : [];
        $needsValue = fn (Get $get): bool => in_array($get('op'), ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'contains', 'starts_with'], true);
        $date = $kind === 'date';
        $select = $options !== [];

        return [
            Select::make('op')
                ->label(__('reports.ui.operator'))
                ->options(collect([...DefinitionResolver::OPERATORS[$kind], ...DefinitionResolver::ANY_OPERATORS])->mapWithKeys(fn (string $op): array => [$op => __('reports.operators.'.$op)])->all())
                ->selectablePlaceholder(false)
                ->required()
                ->live(),
            Select::make('value')->label(__('reports.ui.value'))->options($options)
                ->visible(fn (Get $get): bool => $select && in_array($get('op'), ['eq', 'neq'], true))->required(),
            DatePicker::make('value')->label(__('reports.ui.value'))
                ->visible(fn (Get $get): bool => $date && $needsValue($get))->required(),
            TextInput::make('value')
                ->label(fn (Get $get): string => $get('op') === 'last_days' ? __('reports.ui.days') : __('reports.ui.value'))
                ->numeric(fn (Get $get): bool => in_array($kind, ['number', 'relation'], true) || $get('op') === 'last_days')
                ->visible(fn (Get $get): bool => $get('op') === 'last_days' || ($needsValue($get) && ! $date && ! ($select && in_array($get('op'), ['eq', 'neq'], true))))
                ->required(),
            DatePicker::make('value_to')->label(__('reports.ui.value_to'))->visible(fn (Get $get): bool => $date && $get('op') === 'between')->required(),
            TextInput::make('value_to')->label(__('reports.ui.value_to'))->numeric()->visible(fn (Get $get): bool => ! $date && $get('op') === 'between')->required(),
            Select::make('values')->label(__('reports.ui.values'))->multiple()->options($options)->visible(fn (Get $get): bool => $select && $get('op') === 'in')->required(),
            TagsInput::make('values')->label(__('reports.ui.values'))->visible(fn (Get $get): bool => ! $select && $get('op') === 'in')->required(),
        ];
    }

    private function fieldLabel(string $ref): string
    {
        foreach ($this->catalog()->entries($this->state['base'] ?? null, $this->user()) as $entry) {
            if ($entry['ref'] === $ref) {
                return $entry['label'];
            }
        }

        return __('reports.ui.unknown_field');
    }

    private function catalog(): FieldCatalog
    {
        return once(fn (): FieldCatalog => app(FieldCatalog::class));
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
