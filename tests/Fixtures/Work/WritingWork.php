<?php

declare(strict_types=1);

namespace Tests\Fixtures\Work;

use App\DynamicData\Write\RecordWriter;
use App\Work\StepOutcome;
use App\Work\WorkPlan;
use App\Work\WorkStep;
use App\Work\WorkType;
use App\Work\WritesRecords;
use RuntimeException;

/**
 * Art nur für Tests: legt je Schritt einen Datensatz in „table“ an, zwei Schritte. „fail_in_step“ wirft in diesem
 * Schritt nach dem Schreiben – dann darf weder der Datensatz noch der Fortschritt bleiben.
 */
final class WritingWork implements WorkType, WritesRecords
{
    public const string KEY = 'test_writing';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Schreiben';
    }

    public function describe(array $parameters): string
    {
        return (string) ($parameters['table'] ?? '');
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan(['step' => 0], 2);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $number = (int) $step->state['step'] + 1;
        app(RecordWriter::class)->create((string) $step->parameters['table'], ['titel' => 'Schritt '.$number]);

        if ((int) ($step->parameters['fail_in_step'] ?? 0) === $number) {
            throw new RuntimeException('Fehler nach dem Schreiben');
        }

        return $number === 2 ? StepOutcome::finished('Zwei Datensätze', [], 1) : StepOutcome::continue(['step' => $number], 1);
    }
}
