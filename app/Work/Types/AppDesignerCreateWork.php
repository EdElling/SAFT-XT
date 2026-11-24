<?php

declare(strict_types=1);

namespace App\Work\Types;

use App\Apps\Designer\AppDesigner;
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
 * Arbeit „App erzeugen“ (Fundament 2, F3b): aus dem Entwurf des KI-App-Designers eine App anlegen. Schritt 1 (lang):
 * Entwurf prüfen und die Bezeichnungen in die übrigen Sprachen übersetzen (KI); Schritt 2: installieren, aktivieren,
 * Fachrolle, in die App wechseln und die gewünschten Auswertungen vormerken (AppDesignerReportsWork legt sie an).
 *
 * Parameter: draft_id, name, prefix, reports (true = alle, sonst Indizes der gewählten Auswertungen).
 * Ergebnis: app_id, warning (wenn die Übersetzung scheiterte).
 */
final readonly class AppDesignerCreateWork implements LongSteps, WorkType
{
    public const string KEY = 'app_designer_create';

    public const string TRANSLATE = 'translate';

    public const string INSTALL = 'install';

    public function __construct(private AppDesigner $designer) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('work.types.app_designer_create');
    }

    public function describe(array $parameters): string
    {
        return (string) ($parameters['name'] ?? AppDraft::query()->whereKey((int) ($parameters['draft_id'] ?? 0))->value('title'));
    }

    public function stepSeconds(): int
    {
        return AppDesigner::stepSeconds();
    }

    public function prepare(array $parameters): WorkPlan
    {
        return new WorkPlan(['phase' => self::TRANSLATE], 2);
    }

    public function step(WorkStep $step): StepOutcome
    {
        $user = Auth::user();
        $draft = AppDraft::query()->find((int) ($step->parameters['draft_id'] ?? 0));

        if (! $user instanceof User || $draft === null) {
            throw new BusinessRuleException(__('app_designer.errors.nothing_to_create'));
        }

        if (($step->state['phase'] ?? self::TRANSLATE) === self::TRANSLATE) {
            $translated = $this->designer->translateDefinition($user, $this->designer->prepareCreate($user, $draft));

            return StepOutcome::continue(['phase' => self::INSTALL, 'definition' => $translated['definition'], 'warning' => $translated['warning']], 1);
        }

        $reports = $step->parameters['reports'] ?? true;
        $app = $this->designer->install(
            $user,
            $draft,
            (array) ($step->state['definition'] ?? []),
            isset($step->parameters['name']) ? (string) $step->parameters['name'] : null,
            isset($step->parameters['prefix']) ? (string) $step->parameters['prefix'] : null,
            is_array($reports) ? array_values(array_map('intval', $reports)) : (bool) $reports,
        );

        return StepOutcome::finished(
            __('app_designer.created', ['app' => $app->getTranslation('name', app()->getLocale())]),
            ['app_id' => $app->id, 'warning' => $step->state['warning'] ?? null],
            1,
        );
    }
}
