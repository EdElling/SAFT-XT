<?php

declare(strict_types=1);

namespace App\Apps\Designer;

use App\Apps\CurrentApp;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\AppDraft;
use App\Models\Menu;
use App\Models\Report;
use App\Models\User;
use App\Reports\Assistant\AssistantResult;
use App\Reports\Assistant\ReportAssistant;
use App\Reports\Editor\DesignerState;
use App\Reports\InvalidReportException;
use App\Reports\ReportRunner;
use Throwable;

/**
 * Auswertungen des App-Designers (B-107, Etappe 7c): Nach dem Erzeugen der App legt der Auswertungsassistent je Wunsch
 * aus dem Entwurf eine Auswertung an – gegen die echten Tabellen, eine nach der anderen (je Aufruf eine, damit keine
 * Anfrage zu lange dauert). Geteilt, im Untermenü „Auswertungen“ der App; Rückfragen oder Fehler halten den Wunsch als
 * „nicht möglich“ mit Grund fest.
 */
final readonly class DraftReports
{
    public const string PENDING = 'pending';

    public const string CREATED = 'created';

    public const string FAILED = 'failed';

    /** Untermenü der App für die angelegten Auswertungen. */
    public const array MENU = ['de' => 'Auswertungen', 'en' => 'Reports', 'fr' => 'Rapports', 'it' => 'Report'];

    public function __construct(
        private ReportAssistant $assistant,
        private ReportRunner $runner,
    ) {}

    /**
     * Wünsche aus dem Vorschlag als Liste mit Status (beim Erzeugen der App), auf Wunsch nur die gewählten.
     *
     * @param  array<string, mixed>  $proposal
     * @param  bool|list<int>  $selected  true = alle, false = keine, sonst die Indizes der gewählten Wünsche
     * @return list<array{title: string, description: string, status: string, report_id: int|null, message: string|null}>
     */
    public static function fromProposal(array $proposal, bool|array $selected = true): array
    {
        $wishes = array_values(array_filter((array) ($proposal['reports'] ?? []), static fn (mixed $r): bool => is_array($r) && filled($r['title'] ?? null)));

        if ($selected !== true) {
            $keep = $selected === false ? [] : array_map('intval', $selected);
            $wishes = array_values(array_filter($wishes, static fn (int $i): bool => in_array($i, $keep, true), ARRAY_FILTER_USE_KEY));
        }

        return array_map(static fn (array $r): array => [
            'title' => trim((string) ($r['title'] ?? '')),
            'description' => trim((string) ($r['description'] ?? '')),
            'status' => self::PENDING,
            'report_id' => null,
            'message' => null,
        ], $wishes);
    }

    /** Gibt es noch wartende Auswertungen? */
    public static function pending(AppDraft $draft): bool
    {
        return in_array(self::PENDING, array_column((array) ($draft->reports ?? []), 'status'), true);
    }

    /**
     * Die nächste wartende Auswertung anlegen. Gibt ihren Index zurück, null wenn keine mehr wartet.
     */
    public function next(User $user, AppDraft $draft): ?int
    {
        abort_unless($draft->user_id === $user->id && $user->can('apps.design'), 403);
        $reports = (array) ($draft->reports ?? []);
        $index = array_search(self::PENDING, array_column($reports, 'status'), true);

        if ($index === false || $draft->status !== AppDraft::CREATED || $draft->app === null) {
            return null;
        }

        // In der neuen App: Der Assistent kennt nur ihre Tabellen.
        app(CurrentApp::class)->switch($user, $draft->app);
        $reports[$index] = [...$reports[$index], ...$this->create($user, $draft, $reports[$index])];
        $draft->forceFill(['reports' => $reports])->save();

        return (int) $index;
    }

    /** Eine nicht mögliche Auswertung noch einmal versuchen. */
    public function retry(User $user, AppDraft $draft, int $index): void
    {
        abort_unless($draft->user_id === $user->id, 403);
        $reports = (array) ($draft->reports ?? []);

        if (($reports[$index]['status'] ?? null) === self::FAILED) {
            $reports[$index] = [...$reports[$index], 'status' => self::PENDING, 'message' => null];
            $draft->forceFill(['reports' => $reports])->save();
        }
    }

    /**
     * @param  array{title: string, description: string}  $wish
     * @return array{status: string, report_id: int|null, message: string|null}
     */
    private function create(User $user, AppDraft $draft, array $wish): array
    {
        try {
            $result = $this->assistant->propose($user, 'Title: '.$wish['title']."\nDescription: ".$wish['description'], [], checkPermission: false);
        } catch (BusinessRuleException $e) {
            return ['status' => self::FAILED, 'report_id' => null, 'message' => $e->getMessage()];
        }

        if ($result->status !== AssistantResult::REPORT || $result->state === null) {
            return ['status' => self::FAILED, 'report_id' => null, 'message' => (string) ($result->message ?? __('app_designer.reports.failed'))];
        }

        [$definition] = DesignerState::fromArray($result->state)->toDefinition();

        try {
            $this->runner->resolve($definition, $user);
        } catch (InvalidReportException $e) {
            return ['status' => self::FAILED, 'report_id' => null, 'message' => implode(' ', $e->errors)];
        }

        try {
            $report = Report::query()->create([
                'name' => $this->uniqueName($wish['title']),
                'description' => $wish['description'] !== '' ? $wish['description'] : null,
                'is_shared' => true,
                'definition' => $definition->toArray(),
                'owner_id' => $user->id,
            ]);
            $this->menuEntry($draft, $report);
        } catch (Throwable $e) {
            report($e);

            return ['status' => self::FAILED, 'report_id' => null, 'message' => $e->getMessage()];
        }

        return ['status' => self::CREATED, 'report_id' => (int) $report->id, 'message' => null];
    }

    private function uniqueName(string $title): string
    {
        $name = $title;

        for ($i = 2; Report::query()->where('name', $name)->exists(); $i++) {
            $name = $title.' ('.$i.')';
        }

        return $name;
    }

    /** Eintrag im Untermenü „Auswertungen“ der App (angelegt, wenn es fehlt). */
    private function menuEntry(AppDraft $draft, Report $report): void
    {
        $root = Menu::query()->where('app_id', $draft->app_id)->whereNull('parent_id')->orderBy('sort_order')->first();

        if ($root === null) {
            return;
        }

        $group = Menu::query()->where('parent_id', $root->id)->whereNull('route')->whereNull('url')->where('label->de', self::MENU['de'])->first()
            ?? Menu::create(['app_id' => $draft->app_id, 'parent_id' => $root->id, 'label' => self::MENU, 'icon' => 'heroicon-o-presentation-chart-bar',
                'sort_order' => ((int) Menu::query()->where('parent_id', $root->id)->max('sort_order')) + 10, 'is_active' => true]);

        Menu::create([
            'app_id' => $draft->app_id,
            'parent_id' => $group->id,
            'label' => array_fill_keys(array_keys(self::MENU), (string) $report->name),
            'route' => ReportResource::VIEW_ROUTE,
            'route_parameters' => ['record' => (string) $report->id],
            'icon' => 'heroicon-o-chart-bar',
            'permission_name' => 'reports.view',
            'sort_order' => ((int) Menu::query()->where('parent_id', $group->id)->max('sort_order')) + 10,
            'is_active' => true,
        ]);
    }
}
