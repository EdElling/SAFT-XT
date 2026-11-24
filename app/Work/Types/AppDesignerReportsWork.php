<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\Apps\Designer\DraftReports;
use App\Exceptions\BusinessRuleException;
use App\Models\AppDraft;
use App\Models\User;
use App\Work\LongSteps;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use Illuminate\Support\Facades\Auth;

/**
 * Arbeit „Auswertungen der App anlegen“ (Fundament 2, F3b): nach dem Erzeugen einer App legt der Auswertungsassistent
 * je Schritt eine der gewünschten Auswertungen an (DraftReports::next(), eine KI-Anfrage mit Korrekturrunde). Der
 * Stand je Auswertung steht weiter im Entwurf; „Erneut versuchen“ setzt ihn zurück und startet eine neue Arbeit.
 *
 * Parameter: draft_id.
 */
final readonly class AppDesignerReportsWork implements LongSteps, WorkType
{
    public const string KEY = 'app_designer_reports';

    public function __construct(private DraftReports $reports) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.app_designer_reports');
    }

    public function describe(array $parameters): string
    {
        $draft = AppDraft::query()->with('app')->find((int) ($parameters['draft_id'] ?? 0));

        if ($draft === null) {
            return '';
        }

        $app = $draft->app;

        return $app === null ? (string) $draft->title : $app->getTranslation('name', app()->getLocale());
    }

    /** Eine Auswertung: eine Anfrage an den Assistenten samt Korrekturrunde. */
    public function stepSeconds(): int
    {
        return 2 * (int) config('ai.timeout', 120) + 60;
    }

    public function prepare(array $parameters): WorkPlan
    {
        $draft = AppDraft::query()->find((int) ($parameters['draft_id'] ?? 0));
        $pending = $draft === null ? 0 : count(array_filter((array) ($draft->reports ?? []), static fn (mixed $r): bool => ($r['status'] ?? null) === DraftReports::PENDING));

        return new WorkPlan(['created' => 0, 'failed' => 0], $pending);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $user = Auth::user();
        $draft = AppDraft::query()->find((int) ($step->parameters['draft_id'] ?? 0));

        if (! $user instanceof User || $draft === null) {
            throw new BusinessRuleException(__('app_designer.errors.nothing_to_create'));
        }

        $index = $this->reports->next($user, $draft);
        $draft->refresh();
        $state = $step->state;

        if ($index !== null) {
            $status = (string) ($draft->reports[$index]['status'] ?? '');
            $state[$status === DraftReports::CREATED ? 'created' : 'failed'] = (int) ($state[$status === DraftReports::CREATED ? 'created' : 'failed'] ?? 0) + 1;
        }

        if ($index !== null && DraftReports::pending($draft)) {
            return StepOutcome::continue($state, 1);
        }

        $created = (int) ($state['created'] ?? 0);
        $failed = (int) ($state['failed'] ?? 0);

        return StepOutcome::finished(
            trans_choice('app_designer.work.reports_done', $created, ['count' => $created]).($failed > 0 ? ' '.trans_choice('app_designer.work.reports_failed', $failed, ['count' => $failed]) : ''),
            ['created' => $created, 'failed' => $failed],
            $index === null ? 0 : 1,
        );
    }
}
