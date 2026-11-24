<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Apps\Definition\AppInstaller;
use App\Apps\Definition\DefinitionGraph;
use App\Apps\Designer\AppDesigner;
use App\Apps\Designer\DesignerResult;
use App\Apps\Designer\DraftReports;
use App\DynamicData\Schema\FieldType;
use App\Filament\Resources\Apps\AppResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\AppDraft;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\Types\AppDesignerCreateWork;
use App\Work\Types\AppDesignerReportsWork;
use App\Work\Types\AppDesignerTurnWork;
use App\Work\WorkService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * KI-App-Designer (B-107, Etappe 7a): Entwürfe links, Gespräch in der Mitte, Vorschau rechts (Diagramm, Tabellen,
 * Hinweise); „App erzeugen“ legt die App an, aktiviert sie und wechselt hinein. Recht apps.design.
 *
 * Die langen KI-Anfragen laufen als Arbeiten (Fundament 2, F3b): Die Seite startet eine Runde, das Erzeugen oder die
 * Auswertungen und blockiert nicht mehr – der eingebettete Fortschritt (App\Livewire\WorkProgress) treibt die Arbeit im
 * Browser, solange sich das Zeitlimit anheben lässt, sonst der Scheduler; die Seite fragt alle 2 s nach und übernimmt
 * das Ergebnis (Gespräch, Meldung, Wechsel in die App).
 */
class AppDesignerPage extends Page
{
    protected static ?string $slug = 'app-designer';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.app-designer';

    /** Gewählter Entwurf (in der Adresse, damit ein Neuladen ihn behält). */
    #[Url]
    public ?int $draft = null;

    public string $prompt = '';

    /** Meldung unter der Eingabe (Fehler der letzten Runde). */
    public ?string $notice = null;

    /** Offene Arbeit zu diesem Entwurf (Runde oder Erzeugen), die die Seite treibt und abfragt. */
    public ?int $workId = null;

    /** Arbeit, die die Auswertungen der erzeugten App anlegt (Etappe 7c). */
    public ?int $reportsWorkId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('apps.design') ?? false;
    }

    public function getTitle(): string
    {
        return __('app_designer.title');
    }

    public function getBreadcrumbs(): array
    {
        return [AppResource::getUrl() => AppResource::getPluralModelLabel(), __('app_designer.title')];
    }

    public function mount(): void
    {
        $this->draft ??= AppDraft::query()->where('user_id', auth()->id())->where('status', AppDraft::DRAFT)->latest('updated_at')->value('id');
        $this->attachWork();
    }

    public function newDraft(): void
    {
        $this->draft = app(AppDesigner::class)->start($this->user())->id;
        $this->reset('prompt', 'notice', 'workId', 'reportsWorkId');
    }

    public function openDraft(int $id): void
    {
        $this->draft = $this->ownDraft($id)?->id;
        $this->reset('notice', 'workId', 'reportsWorkId');
        $this->attachWork();
    }

    public function deleteDraft(int $id): void
    {
        $this->ownDraft($id)?->delete();

        if ($this->draft === $id) {
            $this->draft = null;
        }
    }

    /** Eine Runde mit der KI starten; die Antwort kommt über poll() ins Gespräch, der Vorschlag in die Vorschau. */
    public function send(): void
    {
        $this->notice = null;

        if (trim($this->prompt) === '' || $this->openWork() !== null) {
            return;
        }

        $draft = $this->current() ?? app(AppDesigner::class)->start($this->user());
        $this->draft = $draft->id;
        $this->workId = app(WorkService::class)->start(AppDesignerTurnWork::KEY, ['draft_id' => $draft->id, 'text' => $this->prompt], $this->user())->id;
        $this->prompt = '';
    }

    /** Alle 2 s, solange eine Arbeit offen ist: ist sie zu Ende, das Ergebnis übernehmen. */
    public function poll(): void
    {
        if ($this->workId !== null) {
            $item = WorkItem::query()->find($this->workId);

            if ($item === null || ! $item->isOpen()) {
                $this->workId = null;

                if ($item !== null) {
                    $this->finished($item);
                }
            }
        }

        if ($this->reportsWorkId !== null && ! (WorkItem::query()->find($this->reportsWorkId)?->isOpen() ?? false)) {
            $this->reportsWorkId = null;
        }
    }

    /** Ergebnis einer beendeten Arbeit: Runde → Meldung unter der Eingabe; Erzeugen → Meldungen, Auswertungen oder in die App. */
    private function finished(WorkItem $item): void
    {
        $result = (array) ($item->result ?? []);

        if ($item->type === AppDesignerTurnWork::KEY) {
            $failed = $item->status !== WorkItem::DONE || ($result['status'] ?? null) === DesignerResult::ERROR;

            if ($failed) {
                $this->notice = $item->status === WorkItem::DONE ? (string) ($result['message'] ?? '') : (string) $item->message;
                // Die Eingabe bleibt stehen, damit sie sich verbessern lässt.
                $this->prompt = $this->prompt === '' ? (string) ($item->parameterValues()['text'] ?? '') : $this->prompt;
            }

            return;
        }

        if ($item->type !== AppDesignerCreateWork::KEY) {
            return;
        }

        if ($item->status !== WorkItem::DONE) {
            Notification::make()->danger()->title(__('app_designer.create_failed'))->body((string) $item->message)->persistent()->send();

            return;
        }

        Notification::make()->success()->title((string) $item->message)->send();

        if (filled($result['warning'] ?? null)) {
            Notification::make()->warning()->title((string) $result['warning'])->persistent()->send();
        }

        // Ohne Auswertungen gleich in die App (vollständig neu laden: Menü, Umschalter, Rechte). Sonst bleibt die Seite
        // und zeigt den Fortschritt der Auswertungen; „Zur neuen App“ führt danach hinein.
        if (($draft = $this->current()) === null || ! DraftReports::pending($draft)) {
            $this->redirect(Dashboard::getUrl(), navigate: false);

            return;
        }

        $this->ensureReportsWork($draft);
    }

    /** Warten Auswertungen und legt sie keine Arbeit an, eine starten. */
    private function ensureReportsWork(AppDraft $draft): void
    {
        if ($draft->status !== AppDraft::CREATED || ! DraftReports::pending($draft)) {
            return;
        }

        $this->reportsWorkId = ($this->openWorkOf([AppDesignerReportsWork::KEY], $draft)
            ?? app(WorkService::class)->start(AppDesignerReportsWork::KEY, ['draft_id' => $draft->id], $this->user(), $draft->app_id))->id;
    }

    /** Beim Öffnen: laufende Arbeiten dieses Entwurfs wieder aufnehmen (die Seite wurde z. B. neu geladen). */
    private function attachWork(): void
    {
        $draft = $this->current();

        if ($draft === null) {
            return;
        }

        $this->workId = $this->openWorkOf([AppDesignerTurnWork::KEY, AppDesignerCreateWork::KEY], $draft)?->id;
        $this->reportsWorkId = $this->openWorkOf([AppDesignerReportsWork::KEY], $draft)?->id;
    }

    private function openWork(): ?WorkItem
    {
        return $this->workId === null ? null : WorkItem::query()->whereKey($this->workId)->whereIn('status', WorkItem::OPEN)->first();
    }

    /** @param  list<string>  $types */
    private function openWorkOf(array $types, AppDraft $draft): ?WorkItem
    {
        return WorkItem::query()->where('user_id', auth()->id())->whereIn('type', $types)->whereIn('status', WorkItem::OPEN)->latest('id')->get()
            ->first(static fn (WorkItem $item): bool => (int) ($item->parameterValues()['draft_id'] ?? 0) === $draft->id);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newDraft')
                ->label(__('app_designer.new'))
                ->icon(Heroicon::OutlinedPlus)
                ->color('gray')
                ->action(fn () => $this->newDraft()),
            Action::make('createApp')
                ->label(__('app_designer.create'))
                ->icon(Heroicon::OutlinedSparkles)
                ->visible(fn (): bool => ($draft = $this->current()) !== null && $draft->status === AppDraft::DRAFT && is_array($draft->definition))
                ->modalHeading(__('app_designer.create_heading'))
                ->modalDescription(__('app_designer.create_help').' '.__('app_designer.translation_help'))
                ->modalSubmitActionLabel(__('app_designer.create'))
                ->fillForm(fn (): array => [
                    'name' => (string) (($this->current()?->definition['app']['name'] ?? [])[app()->getLocale()] ?? ($this->current()?->definition['app']['name'] ?? [])[config('locales.default')] ?? ''),
                    'prefix' => (string) ($this->current()?->definition['app']['prefix'] ?? ''),
                    // Alle vorgeschlagenen Auswertungen vorgewählt; einzeln abwählbar.
                    'reports' => array_keys(DraftReports::fromProposal((array) $this->current()?->proposal)),
                ])
                ->schema([
                    TextInput::make('name')->label(__('app_designer.app_name'))->required()->maxLength(100),
                    TextInput::make('prefix')->label(__('apps.prefix'))->helperText(__('app_designer.prefix_help'))->required()->regex('/^[a-z][a-z0-9]{0,19}$/'),
                    CheckboxList::make('reports')
                        ->label(__('app_designer.reports.create'))
                        ->helperText(__('app_designer.reports.create_help'))
                        ->options(fn (): array => array_column(DraftReports::fromProposal((array) $this->current()?->proposal), 'title'))
                        ->descriptions(fn (): array => array_column(DraftReports::fromProposal((array) $this->current()?->proposal), 'description'))
                        ->bulkToggleable()
                        ->visible(fn (): bool => DraftReports::fromProposal((array) $this->current()?->proposal) !== []),
                ])
                // Als Arbeit (Fundament 2, F3b): übersetzen, dann anlegen; das Ergebnis übernimmt poll() → finished().
                ->action(function (array $data): void {
                    $draft = $this->current();

                    if ($draft === null || $this->openWork() !== null) {
                        return;
                    }

                    $this->workId = app(WorkService::class)->start(AppDesignerCreateWork::KEY, [
                        'draft_id' => $draft->id,
                        'name' => (string) $data['name'],
                        'prefix' => (string) $data['prefix'],
                        'reports' => array_values(array_map('intval', (array) ($data['reports'] ?? []))),
                    ], $this->user())->id;
                }),
        ];
    }

    /** Eine nicht mögliche Auswertung noch einmal: Status zurück, neue Arbeit. */
    public function retryReport(int $index): void
    {
        if (($draft = $this->current()) !== null) {
            app(DraftReports::class)->retry($this->user(), $draft, $index);
            $this->ensureReportsWork($draft->refresh());
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $draft = $this->current();
        $definition = is_array($draft?->definition) ? $draft->definition : null;
        $analysis = $definition === null ? null : app(AppInstaller::class)->analyze($definition);
        $graph = $definition === null ? null : DefinitionGraph::build($definition);

        return [
            'available' => AppDesigner::availableFor($this->user()),
            'drafts' => $this->drafts(),
            'current' => $draft,
            'messages' => array_map(self::message(...), (array) ($draft?->history ?? [])),
            'definition' => $definition,
            'analysis' => $analysis,
            'diagram' => $graph === null || $graph->isEmpty() ? null : [...DataModelPage::viewDataFor($graph), 'storageKey' => 'saft.app-designer.fields'],
            'tables' => $definition === null ? [] : self::tableSummary($definition),
            // Vor dem Erzeugen die Wünsche aus dem Vorschlag, danach der Stand je Auswertung.
            'wishes' => $draft?->status === AppDraft::DRAFT ? DraftReports::fromProposal((array) $draft->proposal) : [],
            'reports' => array_map(static fn (array $r): array => [...$r, 'url' => filled($r['report_id'] ?? null) ? ReportResource::getUrl('view', ['record' => $r['report_id']]) : null], (array) ($draft?->reports ?? [])),
            'pending' => $draft !== null && DraftReports::pending($draft),
            'appUrl' => Dashboard::getUrl(),
            'workId' => $this->workId,
            'reportsWorkId' => $this->reportsWorkId,
            'creating' => $this->workId !== null && WorkItem::query()->whereKey($this->workId)->where('type', AppDesignerCreateWork::KEY)->exists(),
        ];
    }

    /**
     * Eintrag im Gespräch: Fragen im Wortlaut; Antworten als Rückfrage der KI oder als Hinweis auf den Vorschlag.
     *
     * @param  array{role: string, content: string}  $message
     * @return array{role: string, text: string}
     */
    private static function message(array $message): array
    {
        $text = (string) $message['content'];

        if ($message['role'] !== 'assistant') {
            return ['role' => 'user', 'text' => $text];
        }

        if (str_starts_with($text, 'Proposal with the tables: ')) {
            return ['role' => 'assistant', 'text' => __('app_designer.proposal_shown', ['tables' => rtrim(substr($text, strlen('Proposal with the tables: ')), '.')])];
        }

        $data = json_decode($text, true);

        return ['role' => 'assistant', 'text' => is_array($data) && filled($data['question'] ?? null) ? (string) $data['question'] : $text];
    }

    /**
     * Tabellen der Vorschau: je Feld Name, Typ und – wo sinnvoll – Verknüpfung, Formel oder Auswahlwerte.
     *
     * @param  array<string, mixed>  $definition
     * @return list<array{name: string, label: string, fields: list<array{name: string, label: string, type: string, detail: string}>}>
     */
    private static function tableSummary(array $definition): array
    {
        $locale = app()->getLocale();
        $default = (string) config('locales.default');
        $pick = static fn (mixed $label, string $fallback): string => is_array($label) ? (string) ($label[$locale] ?? $label[$default] ?? $fallback) : (filled($label) ? (string) $label : $fallback);

        return array_map(static fn (array $table): array => [
            'name' => (string) $table['name'],
            'label' => $pick($table['label'] ?? null, (string) $table['name']),
            'fields' => array_map(static function (array $field) use ($pick): array {
                $type = FieldType::tryFrom((string) ($field['type'] ?? ''));
                $options = (array) ($field['options'] ?? []);

                return [
                    'name' => (string) $field['name'],
                    'label' => $pick($field['label'] ?? null, (string) $field['name']),
                    'type' => $type?->label() ?? (string) ($field['type'] ?? ''),
                    'detail' => match ($type) {
                        FieldType::Relation => '→ '.(string) ($options['table'] ?? ''),
                        FieldType::Computed => '= '.(string) ($options['formula'] ?? ''),
                        FieldType::Select => implode(', ', array_map(static fn (mixed $o): string => (string) (is_array($o) ? ($o['value'] ?? '') : $o), (array) ($options['options'] ?? []))),
                        default => '',
                    },
                ];
            }, (array) ($table['fields'] ?? [])),
        ], (array) ($definition['tables'] ?? []));
    }

    /** @return Collection<int, AppDraft> */
    private function drafts(): Collection
    {
        return AppDraft::query()->where('user_id', auth()->id())->latest('updated_at')->limit(30)->get();
    }

    private function current(): ?AppDraft
    {
        return $this->draft === null ? null : $this->ownDraft($this->draft);
    }

    private function ownDraft(int $id): ?AppDraft
    {
        return AppDraft::query()->whereKey($id)->where('user_id', auth()->id())->first();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
