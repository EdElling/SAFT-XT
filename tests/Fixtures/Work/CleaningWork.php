<?php

declare(strict_types=1);

namespace Tests\Fixtures\Work;

use App\Work\CleansUp;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Art nur für Tests: drei Schritte, „fail_in_step“ wirft in diesem Schritt. abort() hält fest, womit es gerufen wurde
 * (Zustand, Status, angemeldete Person); mit „fail_abort“ scheitert auch das Aufräumen.
 */
final class CleaningWork implements CleansUp, WorkType
{
    public const string KEY = 'test_cleaning';

    /** @var list<array<string, mixed>> */
    public static array $aborts = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Aufräumen';
    }

    public function describe(array $parameters): string
    {
        return '';
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan(['step' => 0], 3);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $number = (int) $step->state['step'] + 1;

        if ((int) ($step->parameters['fail_in_step'] ?? 0) === $number) {
            throw new RuntimeException('Fehler in Schritt '.$number);
        }

        return $number === 3 ? StepOutcome::finished('Drei Schritte', [], 1) : StepOutcome::continue(['step' => $number], 1);
    }

    public function abort(array $parameters, array $state, string $status): void
    {
        self::$aborts[] = ['state' => $state, 'status' => $status, 'user' => Auth::id()];

        if (! empty($parameters['fail_abort'])) {
            throw new RuntimeException('Aufräumen gescheitert');
        }
    }
}
