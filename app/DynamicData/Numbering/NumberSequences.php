<?php

declare(strict_types=1);

namespace App\DynamicData\Numbering;

use App\DynamicData\DynamicDataNamespace;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use LogicException;

/**
 * Zähler der Nummernkreise (B-135) in der Tabelle saft_number_sequences auf der Datenverbindung – in derselben
 * Datenbank wie die Datensätze, damit Zähler und Datensatz in einer Transaktion committen (Architektur,
 * Entscheidung 26). Die Tabelle entsteht mit dem ersten Nummernkreis-Feld; eine Migration ginge bei migrate:fresh
 * mit dem Namensraum verloren.
 */
final class NumberSequences
{
    public const string TABLE = 'saft_number_sequences';

    /** Gruppe ohne Gruppenfeld (N4: Wert des Gruppenfelds). */
    public const string NO_GROUP = '';

    /** So oft wird höchstens weitergezählt, wenn die Nummer in der Tabelle schon vorkommt. */
    private const int MAX_SKIPS = 1000;

    private bool $ensured = false;

    public function __construct(private readonly DynamicDataNamespace $namespace) {}

    public function ensureTable(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        $table = $this->namespace->qualify(self::TABLE);

        if ($this->ensured || $schema->hasTable($table)) {
            $this->ensured = true;

            return;
        }

        $schema->create($table, function (Blueprint $blueprint): void {
            // Kein Fremdschlüssel: custom_fields liegt auf der Hauptverbindung. Der Schlüssel ist der Primärschlüssel,
            // damit InnoDB beim Sperren nur die Zeile sperrt (keine Lückensperre auf einem zweiten Index).
            $blueprint->unsignedBigInteger('field_id');
            $blueprint->string('period', 7);
            $blueprint->string('grp', 100)->default(self::NO_GROUP);
            $blueprint->unsignedBigInteger('last_value');
            $blueprint->timestamps();
            $blueprint->primary(['field_id', 'period', 'grp'], 'saft_number_sequences_key');
        });

        $this->ensured = true;
    }

    /**
     * Nächste Nummer ziehen: Zählerzeile gesperrt lesen (bzw. mit Startwert − 1 anlegen), weiterzählen, solange die
     * formatierte Nummer schon vergeben ist ($taken), und den Stand schreiben. Muss in der Transaktion des
     * Schreibvorgangs laufen – sonst gilt die Sperre nicht bis zum Speichern des Datensatzes.
     *
     * @param  Closure(string): bool  $taken
     */
    public function next(int $fieldId, NumberPattern $pattern, array $period, Closure $taken, string $group = self::NO_GROUP): string
    {
        $connection = $this->connection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Nummern werden nur in der Transaktion des RecordWriter gezogen.');
        }

        $this->ensureTable();
        $last = $this->locked($fieldId, $period['key'], $group, $pattern->start - 1);

        $skips = 0;

        do {
            $last++;
            $number = $pattern->format($last, $period['year'], $period['month']);

            if (++$skips > self::MAX_SKIPS) {
                throw new LogicException("Nummernkreis {$fieldId}: über ".self::MAX_SKIPS.' vergebene Nummern in Folge.');
            }
        } while ($taken($number));

        $this->write($fieldId, $period['key'], $group, $last);

        return $number;
    }

    /** Zähler auf mindestens $number anheben (Import mit Nummern, die zum Muster passen). */
    public function raise(int $fieldId, string $period, int $number, int $start = 1, string $group = self::NO_GROUP): void
    {
        $this->ensureTable();

        if ($number > $this->locked($fieldId, $period, $group, $start - 1)) {
            $this->write($fieldId, $period, $group, $number);
        }
    }

    /** Letzter vergebener Wert, ohne Sperre (Vorschau im Feldformular). */
    public function current(int $fieldId, string $period, string $group = self::NO_GROUP): ?int
    {
        if (! $this->exists()) {
            return null;
        }

        $value = $this->query()->where(['field_id' => $fieldId, 'period' => $period, 'grp' => $group])->value('last_value');

        return $value === null ? null : (int) $value;
    }

    /** Alle Zähler eines Felds löschen (Feld entfernt, App-Daten zurückgesetzt). */
    public function forget(int $fieldId): void
    {
        if ($this->exists()) {
            $this->query()->where('field_id', $fieldId)->delete();
        }
    }

    /**
     * Zählerstand gesperrt lesen; fehlt die Zeile, mit $initial anlegen.
     *
     * Fehlt sie, wird nicht gesperrt gelesen: Ein SELECT … FOR UPDATE auf eine fehlende Zeile nimmt in MySQL/MariaDB
     * eine Lückensperre, und zwei Vorgänge, die danach beide einfügen, blockieren sich (Deadlock). Stattdessen legt ein
     * Upsert die Zeile an bzw. sperrt sie exklusiv (ON DUPLICATE KEY UPDATE / ON CONFLICT DO UPDATE); gleichzeitige
     * Vorgänge warten der Reihe nach. Danach liest jeder den aktuellen Stand gesperrt.
     */
    private function locked(int $fieldId, string $period, string $group, int $initial): int
    {
        $key = ['field_id' => $fieldId, 'period' => $period, 'grp' => $group];

        if (! $this->query()->where($key)->exists()) {
            $this->query()->upsert([[...$key, 'last_value' => $initial, 'created_at' => now(), 'updated_at' => now()]], array_keys($key), ['updated_at']);
        }

        return (int) $this->query()->where($key)->lockForUpdate()->value('last_value');
    }

    private function write(int $fieldId, string $period, string $group, int $value): void
    {
        $this->query()
            ->where(['field_id' => $fieldId, 'period' => $period, 'grp' => $group])
            ->update(['last_value' => $value, 'updated_at' => now()]);
    }

    private function exists(): bool
    {
        return $this->ensured || ($this->ensured = $this->connection()->getSchemaBuilder()->hasTable($this->namespace->qualify(self::TABLE)));
    }

    private function query(): Builder
    {
        return $this->connection()->table($this->namespace->qualify(self::TABLE));
    }

    private function connection(): Connection
    {
        return $this->namespace->connection();
    }
}
