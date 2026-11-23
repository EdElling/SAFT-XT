<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DynamicData\Formula\FormulaAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Die Funktionsmatrix in docs/concept/FORMULA_SPEC.md (Abschnitt 2) ist der Vertrag: Jede Funktion, die der Code kennt,
 * steht dort mit den richtigen Kontexten, und was dort als geplant (◇) steht, gibt es im Code noch nicht. Wer eine
 * Funktion baut, trägt sie zuerst in die Spezifikation ein (Fundament 2, F4).
 */
class FormulaSpecTest extends TestCase
{
    private const string SPEC = __DIR__.'/../../docs/concept/FORMULA_SPEC.md';

    /**
     * Zeilen der Matrix: Name => [feld: Zeichen, auswertung: Zeichen, aggregat: bool].
     *
     * @return array<string, array{feld: string, auswertung: string, aggregat: bool}>
     */
    private function matrix(): array
    {
        $text = (string) file_get_contents(self::SPEC);
        preg_match('/^## 2\. Funktionsmatrix.*?(?=^## 3\.)/ms', $text, $section);
        $this->assertNotEmpty($section, 'Abschnitt 2 fehlt in FORMULA_SPEC.md.');

        $rows = [];

        foreach (explode("\n", $section[0]) as $line) {
            if (! str_starts_with($line, '| `')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '| ')));
            preg_match_all('/`([a-z_]+)`/', $cells[0], $names);

            foreach ($names[1] as $name) {
                $rows[$name.(str_contains($cells[0], '(Aggregat)') ? '#agg' : '')] = [
                    'feld' => $cells[2],
                    'auswertung' => $cells[3],
                    'aggregat' => str_contains($cells[0], '(Aggregat)'),
                ];
            }
        }

        return $rows;
    }

    public function test_every_known_function_is_specified_with_its_contexts(): void
    {
        $matrix = $this->matrix();

        foreach (array_keys(FormulaAnalyzer::FUNCTIONS) as $name) {
            $this->assertArrayHasKey($name, $matrix, "Funktion {$name} fehlt in der Matrix.");
            $this->assertSame('✔', $matrix[$name]['feld'], "{$name} muss im Feldkontext erlaubt sein.");
            $this->assertSame('✔', $matrix[$name]['auswertung'], "{$name} steht in FormulaAnalyzer::FUNCTIONS und gilt damit auch für Auswertungen.");
        }

        foreach ([...FormulaAnalyzer::TEXT_FUNCTIONS, 'if', 'and', 'or'] as $name) {
            $this->assertArrayHasKey($name, $matrix, "Schlüsselwort {$name} fehlt in der Matrix.");
            $this->assertSame('✔', $matrix[$name]['feld'], "{$name} muss im Feldkontext erlaubt sein.");
            $this->assertSame('–', $matrix[$name]['auswertung'], "{$name} gibt es in Auswertungen nicht (ReportExpression kennt nur FUNCTIONS).");
        }

        foreach (FormulaAnalyzer::AGGREGATES as $name) {
            $this->assertArrayHasKey($name.'#agg', $matrix, "Aggregat {$name} fehlt in der Matrix.");
            $this->assertSame(['✔', '–'], [$matrix[$name.'#agg']['feld'], $matrix[$name.'#agg']['auswertung']], "Aggregat {$name}: nur Feldkontext.");
        }
    }

    public function test_every_specified_function_exists_and_planned_ones_do_not(): void
    {
        $known = [...array_keys(FormulaAnalyzer::FUNCTIONS), ...FormulaAnalyzer::TEXT_FUNCTIONS, 'if', 'and', 'or'];

        foreach ($this->matrix() as $key => $row) {
            $name = explode('#', $key)[0];

            if ($row['aggregat']) {
                $this->assertContains($name, FormulaAnalyzer::AGGREGATES, "Aggregat {$name} steht in der Matrix, aber nicht in FormulaAnalyzer::AGGREGATES.");

                continue;
            }

            if ($row['feld'] === '◇' || $row['auswertung'] === '◇') {
                $this->assertNotContains($name, $known, "{$name} ist in der Matrix als geplant markiert, existiert aber schon – Matrix nachführen.");

                continue;
            }

            $this->assertContains($name, $known, "{$name} steht in der Matrix, aber der Code kennt es nicht.");
        }
    }
}
