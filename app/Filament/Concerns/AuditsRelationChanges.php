<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Für Create-/Edit-Pages: protokolliert Pivot-Änderungen (z. B. Rollen, Permissions),
 * die Filament per sync() schreibt und das Model-Audit daher nicht sieht.
 *
 * Die Page definiert auditedRelations(): ['relation' => 'titel_spalte'].
 */
trait AuditsRelationChanges
{
    /** @var array<string, list<string>> */
    private array $relationSnapshot = [];

    /**
     * @return array<string, string>
     */
    abstract protected function auditedRelations(): array;

    protected function beforeSave(): void
    {
        $this->relationSnapshot = $this->captureRelations($this->getRecord());
    }

    protected function afterSave(): void
    {
        $this->logRelationChanges($this->getRecord(), $this->relationSnapshot);
        $this->afterRelationsAudited();
    }

    protected function afterCreate(): void
    {
        $empty = array_fill_keys(array_keys($this->auditedRelations()), []);
        $this->logRelationChanges($this->getRecord(), $empty);
        $this->afterRelationsAudited();
    }

    /** Hook für Folgeaktionen, z. B. Cache leeren. */
    protected function afterRelationsAudited(): void
    {
        //
    }

    /**
     * @return array<string, list<string>>
     */
    private function captureRelations(Model $record): array
    {
        $snapshot = [];

        foreach ($this->auditedRelations() as $relation => $titleColumn) {
            /** @var list<string> $values */
            $values = $record->{$relation}()->pluck($titleColumn)->map(fn (mixed $v): string => (string) $v)->values()->all();
            $snapshot[$relation] = $values;
        }

        return $snapshot;
    }

    /**
     * @param  array<string, list<string>>  $before
     */
    private function logRelationChanges(Model $record, array $before): void
    {
        if (! method_exists($record, 'logRelationChange')) {
            return;
        }

        foreach ($this->captureRelations($record) as $relation => $after) {
            $record->logRelationChange($relation, $before[$relation] ?? [], $after);
        }
    }
}
