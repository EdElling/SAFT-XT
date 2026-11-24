<?php

declare(strict_types=1);

namespace Tests\Fixtures\Work;

use App\Apps\CurrentApp;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Art nur für Tests: zählt bis „total“ in Schritten von „per_step“. „fail_at“ wirft bei dieser Position (mit
 * „business“ eine BusinessRuleException), „file“ legt am Ende eine Ergebnisdatei an. Das Ergebnis nennt Person,
 * Rechte und App, unter denen der letzte Schritt lief.
 */
final class CountingWork implements WorkType
{
    public const string KEY = 'test_counting';

    /** Schritte, die in diesem Prozess liefen (für Tests). */
    public static int $steps = 0;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Zählen';
    }

    public function describe(array $parameters): string
    {
        return 'bis '.($parameters['total'] ?? 0);
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan(['position' => 0], (int) ($parameters['total'] ?? 0));
    }

    public function step(WorkStep $step): StepOutcome
    {
        self::$steps++;
        $position = (int) $step->state['position'] + (int) ($step->parameters['per_step'] ?? 1);

        if (isset($step->parameters['fail_at']) && $position >= (int) $step->parameters['fail_at']) {
            throw ! empty($step->parameters['business'])
                ? new BusinessRuleException('Fachlicher Fehler bei '.$position)
                : new RuntimeException('Technischer Fehler bei '.$position);
        }

        if (! empty($step->parameters['file'])) {
            file_put_contents($step->path('ergebnis.txt'), (string) $position);
        }

        if ($position >= (int) ($step->parameters['total'] ?? 0)) {
            $user = Auth::user();

            return StepOutcome::finished('Gezählt bis '.$position, [
                'user' => Auth::id(),
                'can_view_reports' => $user instanceof User && $user->can('reports.view'),
                'app' => app(CurrentApp::class)->id($user instanceof User ? $user : null),
                'file' => empty($step->parameters['file']) ? null : 'ergebnis.txt',
            ], $position - (int) $step->state['position']);
        }

        return StepOutcome::continue(['position' => $position], $position - (int) $step->state['position']);
    }
}
