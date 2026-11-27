<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Base\BulkChange as BaseBulkChange;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kopf einer Sammelaktion (B-133 S3, BULK_EDIT.md §13): Kennung („run“ im Änderungsprotokoll), Person, Tabelle, Art
 * (ändern, löschen, wiederherstellen), Art und Angaben je Feld, Schlüssel der geänderten Datensätze, Rücknahme.
 *
 * Ohne eigenes Änderungsprotokoll: Der Kopf ist ein Verzeichnis des Protokolls, die Änderungen selbst stehen dort je
 * Datensatz. Gelöscht wird er mit der Tabelle und von der Aufgabe „Änderungsprotokoll kürzen“ (gleiche Frist).
 */
class BulkChange extends BaseBulkChange
{
    public const string UPDATE = 'update';

    public const string DELETE = 'delete';

    public const string RESTORE = 'restore';

    /** So viele Köpfe zeigt die Liste „Letzte Sammeländerungen“ je Tabelle. */
    public const int LIST_LIMIT = 20;

    /**
     * Kopf einer erfolgreichen Sammelaktion anlegen: Person ist die angemeldete (bei einer Arbeit die startende),
     * App die der Tabelle.
     *
     * @param  list<int|string>  $recordIds  geänderte Datensätze
     * @param  array<string, mixed>  $changes  Art und Angaben je Feld (BulkChange::toArray())
     * @param  array<string, mixed>  $result  Zählungen einer Rücknahme
     */
    public static function recordRun(string $run, CustomTable $table, string $operation, array $recordIds, array $changes = [], array $result = [], ?self $undoOf = null): self
    {
        $user = auth()->user();

        return self::query()->create([
            'run' => $run,
            'app_id' => $table->app_id,
            'custom_table_id' => $table->id,
            'user_id' => $user instanceof User ? $user->id : null,
            'operation' => $operation,
            'field_changes' => $changes === [] ? null : $changes,
            'record_ids' => array_values($recordIds),
            'count' => count($recordIds),
            'result' => $result === [] ? null : $result,
            'undo_of_id' => $undoOf?->id,
        ]);
    }

    /** @return BelongsTo<self, $this> */
    public function undoOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'undo_of_id');
    }

    /** @return BelongsTo<self, $this> */
    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'undone_by_id');
    }

    /** @return list<int|string> */
    public function recordIds(): array
    {
        return array_values((array) $this->record_ids);
    }

    /** @return list<string> Felder einer Änderung (leer bei Löschen und Wiederherstellen) */
    public function fieldNames(): array
    {
        return array_map('strval', array_keys((array) $this->field_changes));
    }

    /** Vorgang, der diese Sammelaktion zurücknimmt: Ändern bleibt Ändern, Löschen ↔ Wiederherstellen. */
    public function inverseOperation(): string
    {
        return match ($this->operation) {
            self::DELETE => self::RESTORE,
            self::RESTORE => self::DELETE,
            default => self::UPDATE,
        };
    }

    /** Recht der Tabelle für Liste und Rücknahme: „ändern“ bzw. „löschen“. */
    public function permissionAction(): string
    {
        return $this->operation === self::UPDATE ? 'update' : 'delete';
    }
}
