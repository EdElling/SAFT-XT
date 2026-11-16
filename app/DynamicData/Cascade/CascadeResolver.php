<?php

declare(strict_types=1);

namespace App\DynamicData\Cascade;

use App\DynamicData\DataAccess;
use App\DynamicData\DetailRelations;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/**
 * Prüft einen Auswahlpfad (aus der URL, also manipulierbar) Schritt für Schritt und
 * kürzt ihn an der ersten ungültigen Stelle: Verknüpfung muss für die Tabelle
 * konfiguriert sein, Leserecht auf die Kindtabelle bestehen und der Datensatz
 * sichtbar sein und tatsächlich zum übergeordneten Datensatz gehören.
 */
final class CascadeResolver
{
    /**
     * @return array{path: CascadePath, levels: list<array{table: CustomTable, record: DynamicRecord, field: ?CustomField}>}
     */
    public function resolve(CustomTable $rootTable, CascadePath $path): array
    {
        $root = $path->root === null ? null : $this->visible($rootTable, $path->root);

        if ($root === null) {
            return ['path' => new CascadePath, 'levels' => []];
        }

        $levels = [['table' => $rootTable, 'record' => $root, 'field' => null]];
        $parentTable = $rootTable;
        $parent = $root;

        foreach ($path->segments as $index => $segment) {
            $level = $index + 1;
            $detail = DetailRelations::configuredFor($parentTable)->firstWhere('id', $segment['field']);
            $child = $detail?->child;

            if ($detail === null || ! DataAccess::current($child, 'view')) {
                return ['path' => $path->truncate($level), 'levels' => $levels];
            }

            if ($segment['record'] === null) {
                return ['path' => $path->truncate($level + 1), 'levels' => $levels];
            }

            $record = $this->visible($child->loadMissing('fields'), $segment['record']);

            // Gehört der Datensatz wirklich zum Elterndatensatz (Verknüpfungsfeld bzw. Tabellenverknüpfung)?
            $value = $detail->parentValue($parent);

            if ($record === null || $value === null || (string) $record->getAttribute($detail->column()) !== (string) $value) {
                return ['path' => $path->withoutRecordAt($level), 'levels' => $levels];
            }

            $levels[] = ['table' => $child, 'record' => $record, 'field' => $detail->field];
            $parentTable = $child;
            $parent = $record;
        }

        return ['path' => $path, 'levels' => $levels];
    }

    /** Gelöschte Datensätze nur mit Löschrecht (wie in den Listen). */
    private function visible(CustomTable $table, int|string $id): ?DynamicRecord
    {
        return DynamicRecord::forTable($table)->newQuery()
            ->when(DataAccess::current($table, 'delete'), fn ($q) => $q->withTrashed())
            ->find($id);
    }
}
