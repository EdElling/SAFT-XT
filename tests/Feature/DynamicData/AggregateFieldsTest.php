<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Printer;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;

/** Berechnete Felder (Teilschritt 3b): Summen über Kind-Datensätze und Weitergabe nach oben. */
class AggregateFieldsTest extends DynamicDataTestCase
{
    private CustomTable $customers;

    private CustomTable $orders;

    private CustomTable $lines;

    private DynamicRecord $acme;

    private DynamicRecord $first;

    private DynamicRecord $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customers = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string'],
        ]);
        $this->orders = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        $this->lines = $this->service()->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'einzelpreis', 'label' => 'Einzelpreis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'menge * einzelpreis']],
        ]);

        $this->acme = DynamicRecord::forTable($this->customers)->create(['firma' => 'ACME']);
        $this->first = DynamicRecord::forTable($this->orders->fresh('fields'))->create(['nummer' => 'B-1', 'kunde_id' => $this->acme->id]);
        $this->second = DynamicRecord::forTable($this->orders->fresh('fields'))->create(['nummer' => 'B-2', 'kunde_id' => $this->acme->id]);
        DynamicRecord::forTable($this->lines->fresh('fields'))->create(['bestellung_id' => $this->first->id, 'menge' => 2, 'einzelpreis' => 10]);

        $this->computed($this->orders, 'gesamtpreis', 'sum(positionen.betrag)');
        $this->computed($this->orders, 'anzahl', 'count(positionen)', 'integer');
        $this->computed($this->orders, 'mwst', 'round(gesamtpreis * 0.081, 2)');
        $this->computed($this->customers, 'umsatz', 'sum(bestellungen.gesamtpreis)');
    }

    private function computed(CustomTable $table, string $name, string $formula, string $result = 'decimal'): CustomField
    {
        return $this->service()->addField($table->fresh(), [
            'name' => $name, 'label' => ucfirst($name), 'type' => 'computed',
            'options' => ['result' => $result, 'precision' => 12, 'scale' => 2, 'formula' => $formula],
        ]);
    }

    private function line(array $values): DynamicRecord
    {
        return DynamicRecord::forTable($this->lines->fresh('fields'))->create($values);
    }

    /** @return list<mixed> Gesamtpreis, Anzahl, MWST einer Bestellung und Umsatz des Kunden */
    private function totals(DynamicRecord $order): array
    {
        $order = DynamicRecord::forTableName('bestellungen')->newQuery()->findOrFail($order->id);

        return [$order->gesamtpreis, $order->anzahl, $order->mwst, DynamicRecord::forTableName('kunden')->newQuery()->findOrFail($this->acme->id)->umsatz];
    }

    public function test_existing_records_are_calculated_when_the_formula_is_added(): void
    {
        $this->assertSame(['20.00', 1, '1.62', '20.00'], $this->totals($this->first));
        $this->assertSame(['0.00', 0, '0.00', '20.00'], $this->totals($this->second));
    }

    public function test_child_changes_update_masters_up_to_the_customer(): void
    {
        $line = $this->line(['bestellung_id' => $this->first->id, 'menge' => 1, 'einzelpreis' => 5.5]);
        $this->assertSame(['25.50', 2, '2.07', '25.50'], $this->totals($this->first));

        $line->update(['menge' => 3]);
        $this->assertSame(['36.50', 2, '2.96', '36.50'], $this->totals($this->first));

        // Wechsel der Bestellung: alte und neue werden neu berechnet.
        $line->update(['bestellung_id' => $this->second->id]);
        $this->assertSame(['20.00', 1, '1.62', '36.50'], $this->totals($this->first));
        $this->assertSame(['16.50', 1, '1.34', '36.50'], $this->totals($this->second));

        // Gelöschte zählen nicht, wiederhergestellte wieder.
        $line->delete();
        $this->assertSame(['0.00', 0, '0.00', '20.00'], $this->totals($this->second));
        $line->restore();
        $this->assertSame(['16.50', 1, '1.34', '36.50'], $this->totals($this->second));

        // Die neuen Summen stehen im Protokoll der Bestellung.
        $this->assertTrue(Activity::query()->where('subject_type', $this->second->getMorphClass())->where('subject_id', $this->second->id)->where('event', 'updated')->exists());
    }

    public function test_formula_change_recalculates_upwards(): void
    {
        $this->service()->updateField($this->lines->fresh('fields')->fields->firstWhere('name', 'betrag'), [
            'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'menge * einzelpreis * 2'],
        ]);

        $this->assertSame(['40.00', 1, '3.24', '40.00'], $this->totals($this->first));
    }

    public function test_aggregates_are_checked(): void
    {
        // Zweite Verknüpfung derselben Kind-Tabelle: dann muss die Verknüpfung genannt werden.
        $this->service()->addField($this->lines->fresh(), ['name' => 'ersatz_fuer_id', 'label' => 'Ersatz für', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']]);
        $fields = $this->orders->fresh('fields')->fields->keyBy('name')->all();
        $analyzer = app(FormulaAnalyzer::class);

        foreach ([
            'sum(positionen)' => 'Zeichen 1: sum() braucht ein Feld der Kind-Tabelle, z. B. sum(positionen.betrag).',
            'sum(positionen.preis, bestellung_id)' => 'Zeichen 5: Unbekanntes Feld „preis“ in „positionen“.',
            'sum(positionen.betrag)' => 'Zeichen 1: „positionen“ hat mehrere Verknüpfungen auf diese Tabelle (bestellung_id, ersatz_fuer_id); bitte als zweiten Wert angeben, z. B. sum(positionen.betrag, verknüpfung).',
            'sum(positionen.betrag, menge)' => 'Zeichen 24: „menge“ ist keine Verknüpfung von „positionen“ auf diese Tabelle.',
            'sum(kunden.umsatz)' => 'Zeichen 1: „kunden“ ist keine Tabelle, die mit einer Verknüpfung auf diese Tabelle zeigt.',
        ] as $formula => $message) {
            try {
                $analyzer->compile($formula, $fields, 'neu', 'bestellungen');
                $this->fail("Angenommen: {$formula}");
            } catch (FormulaException $e) {
                $this->assertSame($message, $e->getMessage(), $formula);
            }
        }

        $compiled = $analyzer->compile('sum(positionen.betrag, ersatz_fuer_id) + count(positionen, bestellung_id)', $fields, 'neu', 'bestellungen');
        $this->assertSame([], $compiled['fields']);
        $this->assertSame('sum(positionen.betrag, ersatz_fuer_id) + count(positionen, bestellung_id)', Printer::print($compiled['ast']));
    }

    public function test_child_field_changes_carry_over_or_are_blocked(): void
    {
        $amount = $this->lines->fresh('fields')->fields->firstWhere('name', 'betrag');
        $this->service()->updateField($amount, ['name' => 'summe']);
        $this->assertSame('sum(positionen.summe)', $this->orders->fresh('fields')->fields->firstWhere('name', 'gesamtpreis')->optionsArray()['formula']);

        $relation = $this->lines->fresh('fields')->fields->firstWhere('name', 'bestellung_id');
        $this->service()->updateField($relation, ['name' => 'auftrag_id']);
        $this->assertSame(['20.00', 1, '1.62', '20.00'], $this->totals($this->first));

        try {
            $this->service()->updateField($this->lines->fresh('fields')->fields->firstWhere('name', 'summe'), ['type' => 'string', 'options' => ['length' => 30]]);
            $this->fail('Typwechsel trotz Summe.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('bestellungen.gesamtpreis', $e->getMessage());
        }

        $this->service()->updateTable($this->lines->fresh(), ['is_active' => false]);

        try {
            $this->service()->dropTable($this->lines->fresh(), 'positionen');
            $this->fail('Kind-Tabelle trotz Summe gelöscht.');
        } catch (BusinessRuleException $e) {
            $this->assertMatchesRegularExpression('/bestellungen\.(gesamtpreis|anzahl)/', $e->getMessage());
        }
    }

    public function test_expression_per_child_record_is_summed_exactly(): void
    {
        $this->service()->addField($this->lines->fresh(), ['name' => 'rabatt', 'label' => 'Rabatt', 'type' => 'decimal', 'options' => ['precision' => 5, 'scale' => 2]]);
        $this->line(['bestellung_id' => $this->first->id, 'menge' => 3, 'einzelpreis' => 0.1]);
        $this->line(['bestellung_id' => $this->first->id, 'menge' => 1, 'einzelpreis' => null]);

        $value = $this->computed($this->orders, 'warenwert', 'sum(positionen.einzelpreis * positionen.menge)');
        $this->assertSame('sum(positionen.einzelpreis * positionen.menge)', $value->optionsArray()['formula']);
        $this->computed($this->orders, 'netto', 'sum(round(positionen.menge * positionen.einzelpreis * (1 - coalesce(positionen.rabatt, 0)), 2))');
        $this->computed($this->orders, 'teuerste', 'max(positionen.menge * positionen.einzelpreis)');
        $this->computed($this->orders, 'schnitt', 'avg(positionen.menge * positionen.einzelpreis)');

        // 2 × 10 + 3 × 0.1 (exakt) – die Position ohne Preis zählt nicht mit.
        $order = DynamicRecord::forTableName('bestellungen')->newQuery()->findOrFail($this->first->id);
        $this->assertSame(['20.30', '20.30', '20.00', '10.15'], [$order->warenwert, $order->netto, $order->teuerste, $order->schnitt]);
        $this->assertSame(['0.00', null], [
            DynamicRecord::forTableName('bestellungen')->newQuery()->findOrFail($this->second->id)->warenwert,
            DynamicRecord::forTableName('bestellungen')->newQuery()->findOrFail($this->second->id)->teuerste,
        ]);

        // Änderungen an den Positionen rechnen nach; Umbenennen ändert den Ausdruck mit.
        DynamicRecord::forTable($this->lines->fresh('fields'))->newQuery()->where('menge', 3)->firstOrFail()->update(['rabatt' => 0.5]);
        $this->assertSame('20.15', DynamicRecord::forTableName('bestellungen')->newQuery()->findOrFail($this->first->id)->netto);

        $this->service()->updateField($this->lines->fresh('fields')->fields->firstWhere('name', 'einzelpreis'), ['name' => 'preis']);
        $this->assertSame('sum(positionen.preis * positionen.menge)', $this->orders->fresh('fields')->fields->firstWhere('name', 'warenwert')->optionsArray()['formula']);

        $this->service()->updateField($this->lines->fresh('fields')->fields->firstWhere('name', 'rabatt'), ['is_active' => false]);
        $this->expectException(BusinessRuleException::class);
        $this->service()->dropField($this->lines->fresh('fields')->fields->firstWhere('name', 'rabatt'), 'rabatt');
    }

    public function test_expressions_in_summaries_are_checked(): void
    {
        $fields = $this->orders->fresh('fields')->fields->keyBy('name')->all();
        $analyzer = app(FormulaAnalyzer::class);

        foreach ([
            'sum(positionen.menge * gesamtpreis)' => 'Zeichen 24: „gesamtpreis“ ist kein Feld von „positionen“; in einer Zusammenfassung zählen nur Felder der Kind-Tabelle (tabelle.feld).',
            'sum(positionen.menge * bestellungen.nummer)' => 'Zeichen 24: In einer Zusammenfassung dürfen nur Felder von „positionen“ vorkommen.',
            'sum(positionen.menge * positionen.preis)' => 'Zeichen 24: Unbekanntes Feld „preis“ in „positionen“.',
            'sum(sum(positionen.menge))' => 'Zeichen 5: Zusammenfassungen lassen sich nicht verschachteln.',
            'count(positionen.menge)' => 'Zeichen 1: count() zählt Kind-Datensätze: count(tabelle), ohne Feld.',
        ] as $formula => $message) {
            try {
                $analyzer->compile($formula, $fields, 'neu', 'bestellungen');
                $this->fail("Angenommen: {$formula}");
            } catch (FormulaException $e) {
                $this->assertSame($message, $e->getMessage(), $formula);
            }
        }
    }
}
