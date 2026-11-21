<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Apps\Definition\AppInstaller;
use App\Apps\Definition\DefinitionGraph;
use App\Apps\Designer\AppDesigner;
use App\Apps\Designer\DesignerResult;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Apps\AppResource;
use App\Models\AppDraft;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * KI-App-Designer (B-107, Etappe 7a): Entwürfe links, Gespräch in der Mitte, Vorschau rechts (Diagramm, Tabellen,
 * Hinweise); „App erzeugen“ legt die App an, aktiviert sie und wechselt hinein. Recht apps.design.
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
    }

    public function newDraft(): void
    {
        $this->draft = app(AppDesigner::class)->start($this->user())->id;
        $this->reset('prompt', 'notice');
    }

    public function openDraft(int $id): void
    {
        $this->draft = $this->ownDraft($id)?->id;
        $this->reset('notice');
    }

    public function deleteDraft(int $id): void
    {
        $this->ownDraft($id)?->delete();

        if ($this->draft === $id) {
            $this->draft = null;
        }
    }

    /** Eine Runde mit der KI; die Antwort steht danach im Gespräch, der Vorschlag in der Vorschau. */
    public function send(): void
    {
        $this->notice = null;

        if (trim($this->prompt) === '') {
            return;
        }

        $designer = app(AppDesigner::class);
        $draft = $this->current() ?? $designer->start($this->user());
        $this->draft = $draft->id;

        try {
            $result = $designer->propose($this->user(), $draft, $this->prompt);
        } catch (BusinessRuleException $e) {
            $this->notice = $e->getMessage();

            return;
        }

        if ($result->status === DesignerResult::ERROR) {
            $this->notice = $result->message;

            return;
        }

        $this->prompt = '';
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
                ->modalDescription(__('app_designer.create_help'))
                ->modalSubmitActionLabel(__('app_designer.create'))
                ->fillForm(fn (): array => [
                    'name' => (string) (($this->current()?->definition['app']['name'] ?? [])[config('locales.default')] ?? ''),
                    'prefix' => (string) ($this->current()?->definition['app']['prefix'] ?? ''),
                ])
                ->schema([
                    TextInput::make('name')->label(__('app_designer.app_name'))->required()->maxLength(100),
                    TextInput::make('prefix')->label(__('apps.prefix'))->helperText(__('app_designer.prefix_help'))->required()->regex('/^[a-z][a-z0-9]{0,19}$/'),
                ])
                ->action(function (array $data): void {
                    try {
                        $app = app(AppDesigner::class)->create($this->user(), $this->current() ?? throw new BusinessRuleException(__('app_designer.errors.nothing_to_create')), $data['name'], $data['prefix']);
                    } catch (BusinessRuleException $e) {
                        Notification::make()->danger()->title(__('app_designer.create_failed'))->body($e->getMessage())->persistent()->send();

                        return;
                    }

                    Notification::make()->success()->title(__('app_designer.created', ['app' => (string) $app->name]))->send();
                    // Vollständig neu laden: Menü, App-Umschalter und Rechte gehören zur neuen App.
                    $this->redirect(Dashboard::getUrl(), navigate: false);
                }),
        ];
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
