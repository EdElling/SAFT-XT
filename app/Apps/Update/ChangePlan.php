<?php

declare(strict_types=1);

namespace App\Apps\Update;

/**
 * Änderungsplan (B-107, Etappe 6): Schritte vom aktuellen Stand einer App zur Definition eines Pakets, mit
 * Hinderungsgründen und Hinweisen. Eingabe sind Definitionen, nicht die Datenbank – derselbe Baustein dient Paketen,
 * dem KI-App-Designer und später der REST-API.
 */
final readonly class ChangePlan
{
    /**
     * @param  list<ChangeStep>  $steps
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $target  Definition des Pakets
     * @param  list<string>  $requires  genutzte Schnittstellen anderer Apps
     * @param  list<string>  $local  nur in SAFT vorhandene Tabellen und Felder (eigene Anpassungen, bleiben)
     */
    public function __construct(
        public array $steps,
        public array $blockers,
        public array $warnings,
        public array $target,
        public ?string $fromVersion = null,
        public ?string $baseVersion = null,
        public bool $hasBaseline = false,
        public array $requires = [],
        public array $local = [],
    ) {}

    public function canApply(): bool
    {
        return $this->blockers === [];
    }

    public function step(string $id): ?ChangeStep
    {
        foreach ($this->steps as $step) {
            if ($step->id === $id) {
                return $step;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function preselected(): array
    {
        return array_values(array_map(static fn (ChangeStep $s): string => $s->id, array_filter($this->steps, static fn (ChangeStep $s): bool => $s->preselected())));
    }

    /** @return list<ChangeStep> */
    public function selectable(): array
    {
        return array_values(array_filter($this->steps, static fn (ChangeStep $s): bool => $s->selectable()));
    }

    /** @return list<ChangeStep> Schritte, die erst Etappe 6b ausführt */
    public function later(): array
    {
        return array_values(array_filter($this->steps, static fn (ChangeStep $s): bool => ! $s->executable()));
    }

    /** Keine Änderungen (alles gleich oder nur lokale Änderungen). */
    public function isEmpty(): bool
    {
        return $this->steps === [];
    }
}
