<?php

declare(strict_types=1);

namespace App\DynamicData;

use App\Exceptions\BusinessRuleException;
use App\Models\CustomField;
use App\Models\DynamicRecord;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Löschsperre: Ein Datensatz kann nicht gelöscht werden, solange aktive (nicht
 * gelöschte) Datensätze anderer Tabellen per Verknüpfung auf ihn zeigen. Ob
 * Mitlöschen fachlich richtig wäre (Bestellung -> Positionen) oder falsch
 * (Kunde -> Bestellungen), lässt sich aus der Verknüpfung nicht ableiten.
 */
final class DeleteGuard
{
    public function assertDeletable(DynamicRecord $record): void
    {
        $blocking = [];

        foreach (DetailRelations::referencing($record->definitionName()) as $field) {
            /** @var CustomField $field */
            try {
                $count = DynamicRecord::forTableName($field->custom_table->name)->newQuery()->where($field->name, $record->getKey())->count();
            } catch (ModelNotFoundException) {
                continue;
            }

            if ($count > 0) {
                $blocking[] = "{$count} × ".DetailRelations::label($field);
            }
        }

        if ($blocking !== []) {
            throw new BusinessRuleException(
                __('data.errors.has_details', ['details' => implode(', ', $blocking)]),
                'dynamic.has_details',
            );
        }
    }
}
