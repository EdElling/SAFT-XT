<?php

declare(strict_types=1);

namespace App\DynamicData\Write;

use App\DynamicData\DynamicDataNamespace;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\ActivityLogStatus;
use Throwable;

/**
 * Der einzige Schreibweg für Datensätze des Table-Designers und beschreibbarer angebundener Tabellen (Fundament 2, F1).
 *
 * Jeder äusserste Aufruf öffnet eine Transaktion auf der Verbindung der Daten (app_data bzw. die externe) und eine auf
 * der Hauptverbindung (Protokoll, Benachrichtigungen, Systemtabellen der Plugins). Die Daten werden zuerst committet,
 * die Hauptverbindung danach; scheitert eine Phase, rollen beide zurück. Verschachtelte Aufrufe (Summen in
 * Master-Datensätzen, Plugins in Ereignissen, batch()) laufen in derselben Transaktion. Eine verteilte Transaktion gibt
 * es nicht: Scheitert der Commit der Hauptverbindung nach dem der Daten, fehlt der Protokolleintrag (dokumentiert).
 *
 * DynamicRecord::save()/delete() ausserhalb des Writers leiten hierher; die Oberfläche, der Import und die Plugin-API
 * rufen ihn ausdrücklich, weil nur so Optionen möglich sind (WriteOptions).
 */
final class RecordWriter
{
    public function __construct(
        private readonly WritePhases $phases,
        private readonly DynamicDataNamespace $namespace,
        private readonly ActivityLogStatus $logStatus,
    ) {}

    /** @param  array<string, mixed>  $values  Feldname => Wert; nur Felder der Tabelle ($fillable) */
    public function create(CustomTable|string $table, array $values, ?WriteOptions $options = null): DynamicRecord
    {
        $record = $table instanceof CustomTable ? DynamicRecord::forTable($table) : DynamicRecord::forTableName($table);

        return $this->save($record->fill($values), $options);
    }

    /** @param  array<string, mixed>  $values  nur die zu ändernden Felder */
    public function update(DynamicRecord $record, array $values, ?WriteOptions $options = null): DynamicRecord
    {
        return $this->save($record->fill($values), $options);
    }

    /** Anlegen oder Ändern eines bereits befüllten Datensatzes (auch forceFill). */
    public function save(DynamicRecord $record, ?WriteOptions $options = null): DynamicRecord
    {
        return $this->transactional($record->getConnection(), $options, function (WriteOptions $options) use ($record): DynamicRecord {
            $creating = ! $record->exists;

            if (! $creating && $options->expectedVersion !== null) {
                $this->assertVersion($record, $options->expectedVersion);
            }

            $this->phases->beforeSave($record, $creating, $options);
            // Die Nachher-Phase läuft im Eloquent-Ereignis „saved“ (DynamicRecord::booted()): dort sind die Originalwerte
            // noch nicht abgeglichen, was Summen in Master-Datensätzen und previous() der Plugin-Ereignisse brauchen.
            $this->perform($record, $options, $creating, static fn (): bool => $record->save());

            return $record;
        });
    }

    /** In den Papierkorb (Tabellen mit deleted_at) bzw. endgültig (angebundene Tabellen, forceDelete). */
    public function delete(DynamicRecord $record, ?WriteOptions $options = null): void
    {
        $this->transactional($record->getConnection(), $options, function (WriteOptions $options) use ($record): void {
            $this->phases->beforeDelete($record, $options);
            // Nachher-Phase im Ereignis „deleted“ (DynamicRecord::booted()).
            $this->perform($record, $options, false, static fn () => $record->delete());
        });
    }

    public function forceDelete(DynamicRecord $record, ?WriteOptions $options = null): void
    {
        // forceDelete() ruft delete(), das Model leitet in diese Transaktion zurück.
        $this->transactional($record->getConnection(), $options, static fn () => $record->forceDelete());
    }

    public function restore(DynamicRecord $record, ?WriteOptions $options = null): DynamicRecord
    {
        // restore() ruft save(): Vorher- und Nachher-Phase laufen wie beim Ändern, in dieser Transaktion.
        $this->transactional($record->getConnection(), $options, static fn () => $record->restore());

        return $record;
    }

    /**
     * Mehrere Schreibvorgänge als Einheit: alles oder nichts. Die Optionen gelten für alle Aufrufe darin, die keine
     * eigenen mitbringen.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function batch(Closure $callback, ?WriteOptions $options = null, ?Connection $connection = null): mixed
    {
        return $this->transactional($connection ?? $this->namespace->connection(), $options, static fn (): mixed => $callback());
    }

    /**
     * Probelauf: Schreibvorgänge wirklich ausführen (alle Phasen, Plugin-Ereignisse, Nummernkreise, Protokoll) und
     * danach auf beiden Verbindungen zurückrollen – auch innerhalb einer laufenden Transaktion (Sicherungspunkte).
     * Wirkungen nach aussen, die darin angemeldet wurden (afterCommit), verfallen. Liefert das Ergebnis des Callbacks.
     * Für die Prüfphase der Sammeländerung (BULK_EDIT.md §6).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function trial(Closure $callback, ?WriteOptions $options = null, ?Connection $connection = null): mixed
    {
        $result = null;

        try {
            $this->savepoint(static function () use ($callback, &$result): never {
                $result = $callback();

                throw new TrialRollback;
            }, $options, $connection);
        } catch (TrialRollback) {
            // gewollt: alles zurückgerollt
        }

        return $result;
    }

    /**
     * Sicherungspunkt auf beiden Verbindungen: Scheitert der Callback, rollen nur seine Schreibvorgänge zurück (samt
     * Protokoll und afterCommit-Wirkungen), die umgebende Transaktion bleibt nutzbar; die Ausnahme wird weitergereicht.
     * Ohne laufende Transaktion wirkt er wie batch(), aber ohne Commit-Wirkungen bei einem Fehler.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function savepoint(Closure $callback, ?WriteOptions $options = null, ?Connection $connection = null): mixed
    {
        if (! WriteContext::active()) {
            return $this->batch(fn (): mixed => $this->savepoint($callback), $options, $connection);
        }

        $data = $connection ?? $this->namespace->connection();
        $main = DB::connection();
        $mark = WriteContext::afterCommitMark();
        $options ??= WriteContext::options()->nested();

        try {
            return WriteContext::run($options, static fn (): mixed => $main->transaction(
                static fn (): mixed => $data->getName() === $main->getName()
                    ? $callback()
                    : $data->transaction(static fn (): mixed => $callback()),
            ));
        } catch (Throwable $e) {
            WriteContext::discardAfterCommitSince($mark);

            throw $e;
        }
    }

    /**
     * Nur für Recalculator::recomputeBlock(): berechnete Werte schreiben – ohne Phasen, Ereignisse, Protokoll und
     * Zeitstempel, denn Auslöser ist die Formel, nicht eine Änderung am Datensatz.
     *
     * @internal
     */
    public function persist(DynamicRecord $record): void
    {
        $this->transactional($record->getConnection(), WriteContext::options()->nested()->withoutLog(), function () use ($record): void {
            $record->timestamps = false;
            WriteContext::perform($record, static fn (): bool => $record->saveQuietly());
        });
    }

    /**
     * Optimistische Sperre (F2): den aktuellen Stand in der Transaktion sperren und mit der Version vergleichen, die das
     * Formular gelesen hat. Gelöscht → wie bisher „inzwischen gelöscht“; geändert → StaleRecordException mit der
     * aktuellen Version, mit der sich bewusst überschreiben lässt.
     *
     * @throws BusinessRuleException|StaleRecordException
     */
    private function assertVersion(DynamicRecord $record, string $expected): void
    {
        $current = $record->currentRow(lock: true);

        if ($current === null || ($current->hasSoftDeleteColumn() && $current->getAttribute('deleted_at') !== null)) {
            throw new BusinessRuleException(__('errors.rules.record_deleted'), 'dynamic.record_deleted');
        }

        if (RecordVersion::of($current) !== $expected) {
            throw StaleRecordException::for($current);
        }
    }

    /**
     * @template T
     *
     * @param  Closure(WriteOptions): T  $work
     * @return T
     */
    private function transactional(Connection $data, ?WriteOptions $options, Closure $work): mixed
    {
        if (WriteContext::active()) {
            // Die erwartete Version gilt nur für den Datensatz, dem sie mitgegeben wurde (nicht für Summen im Master).
            $options ??= WriteContext::options()->nested();

            return WriteContext::run($options, static fn (): mixed => $work($options));
        }

        $options ??= WriteOptions::defaults();
        $main = DB::connection();

        try {
            $result = WriteContext::run($options, static fn (): mixed => $main->transaction(
                static fn (): mixed => $data->getName() === $main->getName()
                    ? $work($options)
                    : $data->transaction(static fn (): mixed => $work($options)),
            ));
        } catch (Throwable $e) {
            WriteContext::discardAfterCommit();

            throw $e;
        }

        // Beide Verbindungen sind committet: jetzt die Wirkungen nach aussen (WriteContext::afterCommit()).
        WriteContext::flushAfterCommit();

        return $result;
    }

    /** Der eigentliche Eloquent-Schreibvorgang; bei log: false ohne Eintrag im Änderungsprotokoll. */
    private function perform(DynamicRecord $record, WriteOptions $options, bool $creating, Closure $write): void
    {
        $wasDisabled = $this->logStatus->disabled();

        if (! $options->log) {
            $this->logStatus->disable();
        }

        try {
            WriteContext::perform($record, $write, $creating);
        } finally {
            if (! $options->log && ! $wasDisabled) {
                $this->logStatus->enable();
            }
        }
    }
}
