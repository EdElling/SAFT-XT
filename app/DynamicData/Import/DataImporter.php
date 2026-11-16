<?php

declare(strict_types=1);

namespace App\DynamicData\Import;

use App\DynamicData\DataMenu;
use App\DynamicData\DynamicDataNamespace;
use App\Exceptions\BusinessRuleException;
use App\Models\CustomTable;
use App\Models\User;
use App\Services\DynamicSchemaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Legt aus einer geprüften Import-Datei eine neue Tabelle an und spielt die Daten ein.
 *
 * Alles oder nichts: Unter PostgreSQL laufen Anlage und Daten in einer Transaktion. MySQL und
 * MariaDB schließen Transaktionen bei DDL selbst ab; dort entfernt ein Fehler die neue Tabelle
 * wieder (samt Rechten und Menüeintrag).
 *
 * Protokoll: ein Eintrag „importiert“ an der Tabelle statt eines Eintrags je Datensatz.
 */
final class DataImporter
{
    /** PostgreSQL und MySQL erlauben je Abfrage höchstens 65'535 Platzhalter. */
    private const int MAX_PLACEHOLDERS = 60_000;

    private const int MAX_CHUNK = 500;

    /**
     * Sortierbare Felder erhalten einen Index; MySQL/MariaDB erlauben höchstens 64 je Tabelle.
     * Weitere Spalten lassen sich später im Table-Designer sortierbar machen.
     */
    public const int MAX_SORTABLE = 10;

    /** Spalten, die SAFT beim Einfügen selbst füllt. */
    private const int SYSTEM_VALUES = 4;

    public function __construct(
        private readonly ImportAnalyzer $analyzer,
        private readonly DynamicSchemaService $schema,
        private readonly DataMenu $menu,
        private readonly DynamicDataNamespace $namespace,
    ) {}

    /**
     * @param  array{name: string, label: string}  $table
     * @param  list<ImportColumn>  $columns  übernommene Spalten mit den gewählten Namen und Typen
     * @param  int|string|null  $menuGroup  Gruppe aus DataMenu::groupOptions(); null = kein Menüeintrag
     */
    public function import(string $path, string $fileName, ?string $sheet, array $table, array $columns, int|string|null $menuGroup, ?User $user): ImportResult
    {
        if ($columns === []) {
            throw new BusinessRuleException(__('import.wizard.no_columns'), 'import.no_columns');
        }

        @set_time_limit(600);
        $analysis = $this->analyzer->analyze($path, $fileName, $sheet);
        $this->assertUnchanged($analysis, $columns);

        if (! $this->namespace->dialect()->supportsTransactionalDdl()) {
            return $this->withCompensation(fn (?CustomTable &$created): ImportResult => $this->run($analysis, $path, $fileName, $table, $columns, $menuGroup, $user, $created));
        }

        return DB::connection()->transaction(fn (): ImportResult => $this->namespace->connection()->transaction(
            fn (): ImportResult => $this->run($analysis, $path, $fileName, $table, $columns, $menuGroup, $user),
        ));
    }

    /**
     * @param  array{name: string, label: string}  $data
     * @param  list<ImportColumn>  $columns
     */
    private function run(ImportAnalysis $analysis, string $path, string $fileName, array $data, array $columns, int|string|null $menuGroup, ?User $user, ?CustomTable &$created = null): ImportResult
    {
        $created = $this->schema->createTable(
            ['name' => $data['name'], 'label' => $data['label']],
            $this->fieldDefinitions($columns),
        );

        $entry = filled($menuGroup) ? $this->menu->create($created, $menuGroup) : null;
        $rows = $this->insertRows($analysis, $path, $created, $columns, $user);

        activity('data')
            ->performedOn($created)
            ->causedBy($user)
            ->event('imported')
            ->withChanges(['attributes' => [
                'datei' => $fileName,
                'blatt' => $analysis->sheet,
                'zeilen' => $rows,
                'spalten' => count($columns),
            ]])
            ->log('imported');

        return new ImportResult($created, $rows, $entry);
    }

    /**
     * @param  list<ImportColumn>  $columns
     * @return list<array<string, mixed>>
     */
    private function fieldDefinitions(array $columns): array
    {
        $sortable = 0;

        return array_map(function (ImportColumn $column) use (&$sortable): array {
            $definition = $column->toFieldDefinition($sortable < self::MAX_SORTABLE);
            $sortable += $definition['is_sortable'] ? 1 : 0;

            return $definition;
        }, $columns);
    }

    /** @param  list<ImportColumn>  $columns */
    private function insertRows(ImportAnalysis $analysis, string $path, CustomTable $table, array $columns, ?User $user): int
    {
        $reader = new SpreadsheetReader($path, $analysis->format ?? ImportFormat::Csv);
        $query = fn () => $this->namespace->connection()->table($this->namespace->qualify($table->name));
        $chunkSize = max(1, min(self::MAX_CHUNK, intdiv(self::MAX_PLACEHOLDERS, count($columns) + self::SYSTEM_VALUES)));
        $now = now();
        $chunk = [];
        $count = 0;

        foreach ($reader->rows($analysis->sheet) as $number => $cells) {
            // Zeile 1 ist die Kopfzeile; Leerzeilen gibt es laut Prüfung nur am Ende.
            if ($number === 1 || $this->isBlank($cells)) {
                continue;
            }

            $record = ['created_by' => $user?->id, 'updated_by' => $user?->id, 'created_at' => $now, 'updated_at' => $now];

            foreach ($columns as $column) {
                $raw = $cells[$column->index] ?? null;
                $value = $column->convert($raw);

                if ($value === null && ! $this->isEmpty($raw)) {
                    throw new BusinessRuleException(__('import.errors.value', [
                        'cell' => $column->letter().$number,
                        'value' => mb_strimwidth(ImportValueParser::text($raw), 0, 60, '…'),
                        'type' => $column->type->label(),
                    ]), 'import.invalid_value');
                }

                $record[$column->name] = $value;
            }

            $chunk[] = $record;
            $count++;

            if (count($chunk) >= $chunkSize) {
                $query()->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $query()->insert($chunk);
        }

        return $count;
    }

    /**
     * Die Datei muss noch so aussehen wie bei der Prüfung im Assistenten.
     *
     * @param  list<ImportColumn>  $columns
     */
    private function assertUnchanged(ImportAnalysis $analysis, array $columns): void
    {
        if (! $analysis->isImportable()) {
            throw new BusinessRuleException(__('import.errors.changed'), 'import.file_changed');
        }

        $headers = [];

        foreach ($analysis->columns as $column) {
            $headers[$column->index] = $column->header;
        }

        foreach ($columns as $column) {
            if (($headers[$column->index] ?? null) !== $column->header) {
                throw new BusinessRuleException(__('import.errors.changed'), 'import.file_changed');
            }
        }
    }

    /**
     * MySQL/MariaDB: bei einem Fehler die angelegte Tabelle wieder entfernen.
     *
     * @param  callable(?CustomTable &$created): ImportResult  $work
     */
    private function withCompensation(callable $work): ImportResult
    {
        $created = null;

        try {
            return $work($created);
        } catch (Throwable $e) {
            if ($created instanceof CustomTable) {
                $this->remove($created);
            }

            throw $e;
        }
    }

    private function remove(CustomTable $table): void
    {
        try {
            $table = $this->schema->updateTable($table->fresh() ?? $table, ['is_active' => false]);
            $this->schema->dropTable($table, $table->name);
        } catch (Throwable $e) {
            Log::error('Import: neue Tabelle konnte nach einem Fehler nicht entfernt werden', ['table' => $table->name, 'error' => $e->getMessage()]);
        }
    }

    /** @param  list<mixed>  $cells */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (! $this->isEmpty($cell)) {
                return false;
            }
        }

        return true;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
