<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\Apps\Designer\AppDesigner;
use App\Apps\Designer\DesignerResult;
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
 * Arbeit „App entwerfen“ (Fundament 2, F3b): eine Runde im Gespräch des KI-App-Designers – ein langer Schritt
 * (AppDesigner::propose(), bis zu zwei KI-Anfragen). Der Gesprächsstand steht danach im Entwurf; das Ergebnis der
 * Arbeit nennt Vorschlag, Rückfrage oder den Fehler der KI. Die Seite des Designers startet sie und treibt sie im
 * Browser, wenn sich das Zeitlimit anheben lässt, sonst der Scheduler.
 *
 * Parameter: draft_id, text (die Eingabe der Person).
 */
final readonly class AppDesignerTurnWork implements LongSteps, WorkType
{
    public const string KEY = 'app_designer_turn';

    public function __construct(private AppDesigner $designer) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.app_designer_turn');
    }

    public function describe(array $parameters): string
    {
        return (string) AppDraft::query()->whereKey((int) ($parameters['draft_id'] ?? 0))->value('title');
    }

    public function stepSeconds(): int
    {
        return AppDesigner::stepSeconds();
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan([], 1);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $user = Auth::user();
        $draft = AppDraft::query()->find((int) ($step->parameters['draft_id'] ?? 0));

        if (! $user instanceof User || $draft === null) {
            throw new BusinessRuleException(__('app_designer.errors.nothing_to_create'));
        }

        $result = $this->designer->propose($user, $draft, (string) ($step->parameters['text'] ?? ''));
        $message = match ($result->status) {
            DesignerResult::PROPOSAL => __('app_designer.work.proposal'),
            default => (string) $result->message,
        };

        return StepOutcome::finished($message, ['status' => $result->status, 'message' => $result->message, 'warnings' => $result->warnings], 1);
    }
}
