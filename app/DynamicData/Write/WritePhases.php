<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

use App\DynamicData\DeleteGuard;
use App\DynamicData\Files\RecordFiles;
use App\DynamicData\Formula\Recalculator;
use App\DynamicData\Numbering\NumberAssigner;
use App\DynamicData\Rules\CopySource;
use App\DynamicData\Rules\SequenceSuggester;
use App\Models\DynamicRecord;
use App\Plugins\Api\Events\RecordDeleted;
use App\Plugins\Api\Events\RecordDeleting;
use App\Plugins\Api\Events\RecordEvent;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;

/**
 * Die Phasen eines Schreibvorgangs – früher die Model-Hooks in DynamicRecord::booted(). Die Reihenfolge ist Teil des
 * Vertrags mit Plugins und Formeln und bleibt deshalb genau so:
 *
 * Vorher (Speichern): nur lesbar? → geschützte Dokumente → Vorgaben der Datenbank bzw. „Übernehmen aus“ und
 * fortlaufende Nummern → Nummernkreise (vergeben bzw. schützen) → RecordSaving (Plugins setzen Werte) → Nummernkreise
 * mit „Vergeben, wenn“ (N3; Plugins sehen diese Nummer erst in RecordSaved) → berechnete Felder → created_by/updated_by.
 * Nachher (Speichern): Summen in Master-Datensätzen → Dateien abgleichen → RecordSaved.
 * Vorher (Löschen): nur lesbar? → Löschsperre → RecordDeleting. Nachher: Summen → RecordDeleted → Dateien (endgültig).
 * Später kommen hier Regeln (B-142) und Zeilenrechte (B-020) hinzu.
 */
final class WritePhases
{
    public function __construct(
        private readonly Recalculator $recalculator,
        private readonly RecordFiles $files,
        private readonly DeleteGuard $deleteGuard,
        private readonly NumberAssigner $numbers,
    ) {}

    public function beforeSave(DynamicRecord $record, bool $creating, WriteOptions $options): void
    {
        $this->assertWritable($record);
        $definition = $record->definition();

        if ($definition !== null && $definition->files !== [] && ($table = $record->customTable()) !== null) {
            $this->files->assertDocumentsKept($record, $table);
        }

        if (! $record->hasSoftDeleteColumn()) {
            // Angebundene Tabelle.
            if ($creating) {
                // Leere Werte nicht als NULL schreiben, wo die Datenbank einen Vorgabewert hat (z. B. Status „geplant“).
                foreach ($definition->databaseDefaults ?? [] as $column) {
                    if ($record->getAttribute($column) === null) {
                        unset($record[$column]);
                    }
                }

                // MySQL meldet eine UUID-Vorgabe nicht zurück: Schlüssel selbst vergeben.
                if (($definition->uuidKey ?? false) && blank($record->getKey())) {
                    $record->setAttribute($record->getKeyName(), (string) Str::uuid());
                }
            } elseif ($record->isDirty($record->compositeKey() === [] ? $record->getKeyName() : $record->compositeKey())) {
                // Schlüssel angebundener Tabellen sind fest (sonst verlören Verknüpfungen und Protokoll ihren Bezug).
                throw new LogicException("Der Schlüssel von {$record->definitionName()} ist nicht änderbar.");
            }

            $this->event(RecordSaving::class, $record, $creating, $options);

            return;
        }

        if ($creating) {
            // Vorgaben aus dem Master-Datensatz für leere Felder (Kopie, z. B. Einzelpreis ← Artikel → Preis).
            foreach ($definition->copies ?? [] as $field => $copy) {
                if ($record->getAttribute($field) === null) {
                    $record->setAttribute($field, CopySource::lookup($copy['table'], $copy['source'], $record->getAttribute($copy['relation'])));
                }
            }

            // Fortlaufende Felder, die leer geblieben sind (z. B. nicht im Formular): höchster Wert + 1.
            foreach ($definition->sequences ?? [] as $field => $scope) {
                if ($record->getAttribute($field) === null) {
                    $next = SequenceSuggester::nextIn($record, $field, $scope, $scope === null ? null : $record->getAttribute($scope));

                    if ($next !== null) {
                        $record->setAttribute($field, $next);
                    }
                }
            }
        }

        // Nummernkreise (B-135): Plugins und Formeln sehen die Nummer schon; eine vergebene bleibt fest.
        $numbers = ($definition->numbers ?? []) === [] ? [] : $this->numbers->apply($record, $definition->numbers, $creating, $options);

        // Plugins vor den Formeln: Werte, die ein Listener setzt, fliessen in berechnete Felder ein.
        $this->event(RecordSaving::class, $record, $creating, $options);
        $this->numbers->assertKept($record, $numbers);

        // „Vergeben, wenn“ (N3): nach den Plugins, damit die Bedingung ihre Werte sieht; vor den Formeln.
        if (($definition->numbers ?? []) !== []) {
            $this->numbers->applyConditional($record, $definition->numbers, $creating, $options);
        }

        $record->recalculate();

        if ($creating) {
            $record->setAttribute('created_by', $record->getAttribute('created_by') ?? auth()->id());
            $record->setAttribute('updated_by', auth()->id() ?? $record->getAttribute('created_by'));
        } else {
            $record->setAttribute('updated_by', auth()->id() ?? $record->getAttribute('updated_by'));
        }
    }

    public function afterSave(DynamicRecord $record, bool $creating, WriteOptions $options): void
    {
        $this->propagate($record);

        // Dateifelder (B-021): neue Dateien zuordnen, ersetzte und entfernte löschen.
        if (($record->definition()->files ?? []) !== [] && ($table = $record->customTable()) !== null) {
            $this->files->sync($record, $table);
        }

        $this->event(RecordSaved::class, $record, $creating, $options);
    }

    public function beforeDelete(DynamicRecord $record, WriteOptions $options): void
    {
        $this->assertWritable($record);
        // Löschsperre, solange aktive Detail-Datensätze per Verknüpfung auf diesen zeigen.
        $this->deleteGuard->assertDeletable($record);
        $this->event(RecordDeleting::class, $record, false, $options);
    }

    /** @param  bool  $forced  endgültig gelöscht (nicht nur in den Papierkorb) */
    public function afterDelete(DynamicRecord $record, bool $forced, WriteOptions $options): void
    {
        $this->propagate($record);
        $this->event(RecordDeleted::class, $record, false, $options);

        // Im Papierkorb bleiben die Dateien, damit Wiederherstellen sie zurückbringt; endgültig gelöscht → Dateien weg.
        if ($forced && ($record->definition()->files ?? []) !== [] && ($table = $record->customTable()) !== null) {
            $this->files->deleteForRecord($table, (string) $record->getKey());
        }
    }

    /** Externe Tabellen sind nur beschreibbar, wenn der Table-Connector es erlaubt – auch ausserhalb der Oberfläche. */
    private function assertWritable(DynamicRecord $record): void
    {
        if (! $record->isWritable()) {
            throw new LogicException("Tabelle {$record->definitionName()} ist nur lesbar.");
        }
    }

    /** Summen & Co. in Master-Datensätzen nachführen (berechnete Felder, Table-Designer). */
    private function propagate(DynamicRecord $record): void
    {
        if ($record->hasSoftDeleteColumn() && ($dependents = $record->definition()->dependents ?? []) !== []) {
            $this->recalculator->propagate($record, $dependents);
        }
    }

    /**
     * Ereignis der Programmierschnittstelle auslösen – nur wenn ein Plugin zuhört und die Optionen es erlauben.
     *
     * @param  class-string<RecordEvent>  $event
     */
    private function event(string $event, DynamicRecord $record, bool $creating, WriteOptions $options): void
    {
        if (! $options->pluginEvents || ! WriteContext::pluginEventsEnabled() || ! Event::hasListeners($event)) {
            return;
        }

        Event::dispatch(new $event($record->definitionName(), $record, $creating));
    }
}
