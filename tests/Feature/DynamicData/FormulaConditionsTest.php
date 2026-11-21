<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Formula\Evaluator;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Parser;
use App\DynamicData\Formula\Printer;
use App\DynamicData\Schema\FieldType;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Carbon;

/** Bedingungen in Formeln: if(), Vergleiche, and/or, Text und contains() & Co. – auch in Summen über Kind-Datensätze. */
class FormulaConditionsTest extends DynamicDataTestCase
{
    private CustomTable $receipts;

    private CustomTable $bookings;

    protected function setUp(): void
    {
        parent::setUp();

        $kinds = ['options' => [['value' => 'Einnahme'], ['value' => 'Ausgabe']]];
        $this->receipts = $this->service()->createTable(['name' => 'belege', 'label' => 'Belege'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'typ', 'label' => 'Typ', 'type' => 'select', 'options' => $kinds],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
        ]);
        $this->bookings = $this->service()->createTable(['name' => 'buchungen', 'label' => 'Buchungen'], [
            ['name' => 'beleg_id', 'label' => 'Beleg', 'type' => 'relation', 'options' => ['table' => 'belege', 'display_field' => 'nummer']],
            ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['options' => [['value' => 'Warenwert'], ['value' => 'Zoll'], ['value' => 'Verkauf']]]],
            ['name' => 'brutto', 'label' => 'Brutto', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'beleg', 'label' => 'Beleg', 'type' => 'file', 'options' => ['kinds' => ['any'], 'max_files' => 2]],
        ]);
    }

    private function evaluate(string $formula, array $values = []): ?string
    {
        return (new Evaluator)->evaluate((new Parser)->parse($formula), static fn (string $f): mixed => $values[$f] ?? null);
    }

    public function test_conditions_compare_numbers_texts_and_dates(): void
    {
        $values = ['typ' => 'Ausgabe', 'brutto' => '120.50', 'menge' => 12, 'aktiv' => true, 'datum' => Carbon::parse('2026-03-01')];

        $this->assertSame('-120.50', Evaluator::round($this->evaluate("if(typ = 'Ausgabe', -brutto, brutto)", $values), 2));
        $this->assertSame('0', $this->evaluate("if(typ <> 'Ausgabe'; brutto; 0)", $values), 'Semikolon wie in den Auswertungen.');
        $this->assertSame('1', $this->evaluate('menge > 10 and aktiv', $values));
        $this->assertSame('0', $this->evaluate('menge > 10 and menge < 12', $values));
        $this->assertSame('1', $this->evaluate('menge < 5 or menge >= 12', $values));
        $this->assertSame('1', $this->evaluate("datum >= '2026-01-01' and datum < '2027-01-01'", $values));
        $this->assertSame('1', $this->evaluate("datum = '2026-03-01'", $values), 'Datum ohne Uhrzeit.');
        $this->assertSame('1', $this->evaluate('menge = 12.00', $values), 'Zahlen exakt verglichen.');
        $this->assertSame('120.50', Evaluator::round($this->evaluate('(menge > 10) * brutto', $values), 2), 'Ein Vergleich ergibt 1 oder 0.');
        $this->assertSame('1', $this->evaluate("'l''Ourson' = name", ['name' => "l'Ourson"]));

        // Leere Werte: Der Vergleich ist nicht erfüllt, auch bei <>; ohne „sonst“ bleibt das Ergebnis leer.
        $this->assertSame('2', $this->evaluate('if(leer = 1, 1, 2)'));
        $this->assertSame('2', $this->evaluate('if(leer <> 1, 1, 2)'));
        $this->assertNull($this->evaluate('if(menge < 5, 7)', $values));
        // Nur der gewählte Zweig wird gerechnet: Division durch 0 im anderen Zweig stört nicht.
        $this->assertSame('3', $this->evaluate('if(menge > 0, 3, 1 / 0)', $values));

        // Unscharfe Textvergleiche ohne Gross-/Kleinschreibung; leeres Feld = nicht erfüllt.
        $guitar = ['bezeichnung' => 'Gibson Les Paul Gitarre', 'nummer' => 'GIT-0042'];
        $this->assertSame('1', $this->evaluate("contains(bezeichnung, 'les paul')", $guitar));
        $this->assertSame('0', $this->evaluate("contains(bezeichnung, 'Fender')", $guitar));
        $this->assertSame('1', $this->evaluate("startswith(nummer, 'git-') and endswith(bezeichnung, 'GITARRE')", $guitar));
        $this->assertSame('1', $this->evaluate("contains(bezeichnung, 'Übung') or contains(nummer, '0042')", $guitar + ['x' => 1]));
        $this->assertSame('0', $this->evaluate("contains(leer, '')"));
        $this->assertSame('1', $this->evaluate("contains(name, 'über')", ['name' => 'ÜBERHOLUNG']), 'Auch Umlaute.');

        $parser = new Parser;
        $this->assertSame("if(typ = 'Ausgabe', -brutto, brutto)", Printer::print($parser->parse("if(typ='Ausgabe';-brutto;brutto)")));
        $this->assertSame('a > 1 and (b < 2 or c = 3)', Printer::print($parser->parse('a>1 and (b<2 or c=3)')));
        $this->assertSame('(a > 1) = (b > 1)', Printer::print($parser->parse('(a > 1) = (b > 1)')));
    }

    public function test_formulas_with_conditions_are_checked(): void
    {
        $fields = $this->receipts->fresh('fields')->fields->keyBy('name')->all();
        $bookingFields = $this->bookings->fresh('fields')->fields->keyBy('name')->all();
        $analyzer = app(FormulaAnalyzer::class);

        foreach ([
            [$fields, 'belege', "typ + 'x'", "Zeichen 1: „typ“ ist kein Zahlenfeld."],
            [$fields, 'belege', "if('Ausgabe', 1, 0)", "Zeichen 4: Text in Anführungszeichen ist nur in einem Vergleich möglich, z. B. typ = 'Ausgabe'."],
            [$fields, 'belege', "if(typ = 'Ausgabe')", 'Zeichen 1: Falsche Anzahl Werte für if().'],
            [$fields, 'belege', "contains(nummer)", 'Zeichen 1: Falsche Anzahl Werte für contains().'],
            [$bookingFields, 'buchungen', "contains(beleg, 'x')", 'Zeichen 10: „beleg“ lässt sich nicht vergleichen (Datei).'],
            [$fields, 'belege', "typ = 'Ausgabe", 'Zeichen 7: Das schliessende Anführungszeichen fehlt.'],
            [$fields, 'belege', 'datum < 1 < 2', 'Zeichen 11: „<“ ist hier nicht erwartet.'],
            [$bookingFields, 'buchungen', 'if(beleg = 1, 1, 0)', 'Zeichen 4: „beleg“ lässt sich nicht vergleichen (Datei).'],
            [$fields, 'belege', "sum(if(buchungen.art = 'Zoll', buchungen.brutto, nummer))", 'Zeichen 50: „nummer“ ist kein Feld von „buchungen“; in einer Zusammenfassung zählen nur Felder der Kind-Tabelle (tabelle.feld).'],
        ] as [$own, $table, $formula, $message]) {
            try {
                $analyzer->compile($formula, $own, 'neu', $table);
                $this->fail("Angenommen: {$formula}");
            } catch (FormulaException $e) {
                $this->assertSame($message, $e->getMessage(), $formula);
            }
        }

        $this->assertSame(['typ'], $analyzer->compile("if(typ = 'Ausgabe', -1, 1)", $fields, 'neu', 'belege')['fields']);
        $aggregate = FormulaAnalyzer::aggregates($analyzer->compile("sum(if(buchungen.art = 'Verkauf', buchungen.brutto, 0))", $fields, 'neu', 'belege')['ast'])[0];
        $this->assertSame(['art', 'brutto'], $aggregate['fields'], 'Auch das Vergleichsfeld löst die Neuberechnung aus.');
    }

    public function test_formula_picker_offers_fields_for_comparisons(): void
    {
        $picker = new \ReflectionMethod(FieldForm::class, 'formulaPicker');
        $groups = collect($picker->invoke(null, $this->receipts->fresh(), \Mockery::mock(Get::class), 'saldo')['groups'])->keyBy('label');
        $items = static fn (string $label): array => collect($groups[$label]['items'])->keyBy('label')->all();

        // Eine Zeile für die Felder dieser Tabelle in Tabellenreihenfolge; der Tooltip nennt den Datentyp.
        $own = $items('Felder dieser Tabelle – Klick fügt den technischen Namen ein');
        $this->assertSame(['Nummer (nummer)', 'Typ (typ)', 'Datum (datum)'], array_keys($own));
        $this->assertSame('nummer – '.FieldType::String->label(), $own['Nummer (nummer)']['title']);
        $this->assertSame('typ – '.FieldType::Select->label()." – Gespeicherte Werte: 'Einnahme', 'Ausgabe'", $own['Typ (typ)']['title']);

        // Kind-Tabelle in Tabellenreihenfolge; Dateien und Verknüpfungen nicht.
        $child = $items('Über Kind-Datensätze von „Buchungen“ (buchungen) – z. B. sum(buchungen.feld)');
        $this->assertSame(['count(buchungen)', 'Art (buchungen.art)', 'Brutto (buchungen.brutto)'], array_keys($child));
        $this->assertSame('buchungen.brutto – '.FieldType::Decimal->label(), $child['Brutto (buchungen.brutto)']['title']);
        $this->assertCount(2, $groups, 'Funktionen aus Plugins stehen unter den Rechenzeichen, nicht hier.');

        // Jeder Knopf erklärt im Tooltip Syntax und Beispiel – in allen Sprachen.
        $buttons = collect($picker->invoke(null, $this->receipts->fresh(), \Mockery::mock(Get::class), 'saldo')['buttons'])->keyBy('label');
        $this->assertStringStartsWith('if(bedingung, dann, sonst)', $buttons['if']['title']);
        $this->assertStringStartsWith('Ungleich:', $buttons['<>']['title']);

        foreach (['en', 'fr', 'it'] as $locale) {
            $this->assertSame(array_keys(trans('tables.formula.tips', [], 'de')), array_keys(trans('tables.formula.tips', [], $locale)), $locale);
        }
    }

    public function test_conditional_sums_follow_the_child_records(): void
    {
        $receipt = DynamicRecord::forTable($this->receipts->fresh('fields'))->create(['nummer' => 'B-1', 'typ' => 'Ausgabe', 'datum' => '2026-03-01']);
        $add = fn (string $art, string $brutto): DynamicRecord => DynamicRecord::forTable($this->bookings->fresh('fields'))
            ->create(['beleg_id' => $receipt->id, 'art' => $art, 'brutto' => $brutto]);
        $add('Warenwert', '800.00');
        $add('Zoll', '45.50');
        $customs = $add('Zoll', '12.00');

        foreach ([
            ['kosten', "sum(if(buchungen.art <> 'Verkauf', buchungen.brutto, 0))"],
            ['zoll', "sum(if(buchungen.art = 'Zoll', buchungen.brutto))"],
            ['anzahl_zoll', "sum(buchungen.art = 'Zoll')"],
            ['anzahl_waren', "sum(startswith(buchungen.art, 'waren'))"],
            ['saldo', "if(typ = 'Ausgabe', -kosten, kosten)"],
        ] as [$name, $formula]) {
            $this->service()->addField($this->receipts->fresh(), [
                'name' => $name, 'label' => ucfirst($name), 'type' => 'computed',
                'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => $formula],
            ]);
        }

        $totals = fn (): array => array_map(
            static fn (mixed $v): string => (string) $v,
            DynamicRecord::forTableName('belege')->newQuery()->findOrFail($receipt->id)->only(['kosten', 'zoll', 'anzahl_zoll', 'anzahl_waren', 'saldo']),
        );
        $this->assertSame(['kosten' => '857.50', 'zoll' => '57.50', 'anzahl_zoll' => '2.00', 'anzahl_waren' => '1.00', 'saldo' => '-857.50'], $totals());

        // Die Art einer Position ändern: nur das Vergleichsfeld ändert sich, die Summen folgen.
        $customs->update(['art' => 'Warenwert']);
        $this->assertSame(['kosten' => '857.50', 'zoll' => '45.50', 'anzahl_zoll' => '1.00', 'anzahl_waren' => '2.00', 'saldo' => '-857.50'], $totals());

        DynamicRecord::forTableName('belege')->newQuery()->findOrFail($receipt->id)->update(['typ' => 'Einnahme']);
        $this->assertSame('857.50', $totals()['saldo']);
    }
}
