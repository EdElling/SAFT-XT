<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Formula\Evaluator;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Reports\Dialects\QueryDialectResolver;
use App\Reports\ReportExpression;
use Illuminate\Support\Facades\DB;

/**
 * Konformität der Formelsprache (docs/concept/FORMULA_SPEC.md, Abschnitt 12): jeder Fall aus
 * tests/Fixtures/formula-conformance.json wird im Feldkontext (PHP, Evaluator) und – wo erlaubt – im
 * Auswertungskontext (SQL, ReportExpression auf der Datenbank des Testlaufs) gerechnet und exakt verglichen; der Kontext
 * „bulk“ (Sammeländerung) rechnet streng: Division durch 0 ist ein Fehler (Schlüssel „error“).
 * Läuft in der CI auf PostgreSQL, MySQL und MariaDB.
 */
class FormulaConformanceTest extends DynamicDataTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/formula-conformance.json';

    private const array NUMERIC = ['a', 'b', 'c', 'i'];

    private CustomTable $table;

    protected function setUp(): void
    {
        parent::setUp();
        $this->table = $this->service()->createTable(['name' => 'formel_konformitaet', 'label' => 'Formel-Konformität'], [
            ['name' => 'a', 'label' => 'A', 'type' => 'decimal', 'options' => ['precision' => 18, 'scale' => 6]],
            ['name' => 'b', 'label' => 'B', 'type' => 'decimal', 'options' => ['precision' => 18, 'scale' => 6]],
            ['name' => 'c', 'label' => 'C', 'type' => 'decimal', 'options' => ['precision' => 18, 'scale' => 6]],
            ['name' => 'i', 'label' => 'I', 'type' => 'integer'],
            ['name' => 't', 'label' => 'T', 'type' => 'string'],
            ['name' => 's', 'label' => 'S', 'type' => 'select', 'options' => ['options' => [['value' => 'offen'], ['value' => 'erledigt']]]],
            ['name' => 'd', 'label' => 'D', 'type' => 'date'],
            ['name' => 'j', 'label' => 'J', 'type' => 'boolean'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $case */
    private function record(array $case): DynamicRecord
    {
        $values = $case['fields'];

        if (array_key_exists('j', $values)) {
            $values['j'] = (bool) (int) $values['j'];
        }

        return DynamicRecord::forTable($this->table)->create($values);
    }

    private function same(?string $expected, ?string $actual, int $scale): bool
    {
        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return bccomp($expected, $actual, $scale) === 0;
    }

    public function test_field_context_matches_the_specification(): void
    {
        $fields = $this->table->fields->keyBy('name')->all();
        $analyzer = app(FormulaAnalyzer::class);
        $failures = [];

        foreach (self::cases() as $case) {
            if (! in_array('field', $case['contexts'], true)) {
                continue;
            }

            $record = $this->record($case);
            $compiled = $analyzer->compile($case['formula'], $fields, 'ergebnis', $this->table->name);
            $actual = Evaluator::round((new Evaluator)->evaluate($compiled['ast'], static fn (string $field): mixed => $record->getAttribute($field)), (int) $case['scale']);

            if (! $this->same($case['expected'], $actual, (int) $case['scale'])) {
                $failures[] = sprintf('%s mit %s: erwartet %s, PHP liefert %s (%s)', $case['formula'], json_encode($case['fields']), $case['expected'] ?? 'leer', $actual ?? 'leer', $case['note'] ?? '');
            }
        }

        $this->assertSame([], $failures, "Feldkontext weicht von FORMULA_SPEC.md ab:\n- ".implode("\n- ", $failures));
    }

    public function test_bulk_context_matches_the_specification(): void
    {
        $fields = $this->table->fields->keyBy('name')->all();
        $analyzer = app(FormulaAnalyzer::class);
        $failures = [];

        foreach (self::cases() as $case) {
            if (! in_array('bulk', $case['contexts'], true)) {
                continue;
            }

            $record = $this->record($case);
            $compiled = $analyzer->compile($case['formula'], $fields, '', $this->table->name);

            try {
                $actual = Evaluator::round((new Evaluator)->evaluateStrict($compiled['ast'], static fn (string $field): mixed => $record->getAttribute($field)), (int) $case['scale']);
                $error = null;
            } catch (FormulaException $e) {
                $actual = null;
                $error = $e->errorCode === 'dynamic.formula.division_by_zero' ? 'division_by_zero' : $e->errorCode;
            }

            if ($error !== ($case['error'] ?? null) || ! $this->same($case['expected'], $actual, (int) $case['scale'])) {
                $failures[] = sprintf('%s mit %s: erwartet %s, PHP liefert %s (%s)', $case['formula'], json_encode($case['fields']), $case['error'] ?? $case['expected'] ?? 'leer', $error ?? $actual ?? 'leer', $case['note'] ?? '');
            }
        }

        $this->assertSame([], $failures, "Kontext Sammeländerung weicht von FORMULA_SPEC.md ab:\n- ".implode("\n- ", $failures));
    }

    public function test_report_context_matches_the_specification(): void
    {
        $connection = app(DynamicDataNamespace::class)->connection();
        $dialect = app(QueryDialectResolver::class)->for($connection);
        $grammar = $connection->getQueryGrammar();
        $qualified = app(DynamicDataNamespace::class)->qualify($this->table->name);
        $keys = array_combine(self::NUMERIC, self::NUMERIC);
        $failures = [];

        foreach (self::cases() as $case) {
            if (! in_array('report', $case['contexts'], true)) {
                continue;
            }

            $record = $this->record($case);
            // Auswertungen schreiben Felder als [Name]; die Datenbank rundet auf die Skala der Spalte.
            $text = 'round('.preg_replace('/\b('.implode('|', self::NUMERIC).')\b/', '[$1]', $case['formula']).', '.(int) $case['scale'].')';
            $sql = ReportExpression::sql(ReportExpression::parse($text, $keys), static fn (string $key): string => $grammar->wrap($key), $dialect);
            $value = $connection->table($qualified)->where('id', $record->getKey())->value(DB::raw($sql));
            $actual = $value === null ? null : (string) $value;

            if (! $this->same($case['expected'], $actual, (int) $case['scale'])) {
                $failures[] = sprintf('%s mit %s: erwartet %s, %s liefert %s (%s)', $case['formula'], json_encode($case['fields']), $case['expected'] ?? 'leer', $connection->getDriverName(), $actual ?? 'leer', $case['note'] ?? '');
            }
        }

        $this->assertSame([], $failures, "Auswertungskontext weicht von FORMULA_SPEC.md ab:\n- ".implode("\n- ", $failures));
    }

    public function test_every_case_names_only_known_contexts_and_fields(): void
    {
        foreach (self::cases() as $case) {
            $this->assertSame([], array_diff($case['contexts'], ['field', 'report', 'bulk']), $case['formula']);
            $this->assertSame([], array_diff(array_keys($case['fields']), ['a', 'b', 'c', 'i', 't', 's', 'd', 'j']), $case['formula']);
            $this->assertTrue(! isset($case['error']) || $case['contexts'] === ['bulk'], 'Fehler gibt es nur im Kontext Sammeländerung: '.$case['formula']);

            if (in_array('report', $case['contexts'], true)) {
                $this->assertSame([], array_diff(array_keys($case['fields']), self::NUMERIC), 'Auswertungen kennen nur Zahlfelder: '.$case['formula']);
            }
        }
    }
}
