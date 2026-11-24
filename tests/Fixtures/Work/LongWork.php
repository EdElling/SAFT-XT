<?php

declare(strict_types=1);

namespace Tests\Fixtures\Work;

use App\Work\LongSteps;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;

/**
 * Art nur für Tests: zwei lange, unteilbare Schritte („seconds“ je Schritt). Das Ergebnis nennt das Zeitlimit von PHP,
 * unter dem der letzte Schritt lief.
 */
final class LongWork implements LongSteps, WorkType
{
    public const string KEY = 'test_long';

    public static int $steps = 0;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Lange Schritte';
    }

    public function describe(array $parameters): string
    {
        return '';
    }

    public function stepSeconds(): int
    {
        return 1000;
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan(['step' => 0], 2);
    }

    public function step(WorkStep $step): StepOutcome
    {
        self::$steps++;
        $number = (int) $step->state['step'] + 1;

        return $number === 2
            ? StepOutcome::finished('Fertig', ['time_limit' => (int) ini_get('max_execution_time')], 1)
            : StepOutcome::continue(['step' => $number], 1);
    }
}
