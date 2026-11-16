<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Formula\Evaluator;
use App\DynamicData\Formula\FormulaAnalyzer;
use App\DynamicData\Formula\FormulaException;
use App\DynamicData\Formula\Parser;
use App\DynamicData\Formula\Printer;
use App\DynamicData\Schema\FieldType;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\DynamicData\Ui\DynamicValueFormatter;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/** Berechnete Felder (Teilschritt 3a): Formeln im selben Datensatz. */
class ComputedFieldsTest extends DynamicDataTestCase
{
    private CustomTable $lines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lines = $this->service()->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'einzelpreis', 'label' => 'Einzelpreis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'round(menge*einzelpreis, 2)']],
            ['name' => 'mwst', 'label' => 'MWST', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'betrag * 0.081']],
        ]);
    }

    private function field(string $name): CustomField
    {
        return $this->lines->fresh('fields')->fields->firstWhere('name', $name);
    }

    private function evaluate(string $formula, array $values = []): ?string
    {
        return (new Evaluator)->evaluate((new Parser)->parse($formula), static fn (string $f): mixed => $values[$f] ?? null);
    }

    public function test_parser_and_evaluator_calculate_exactly(): void
    {
        $this->assertSame('14.000000000000', $this->evaluate('2 + 3 * 4'));
        $this->assertSame('20.000000000000', $this->evaluate('(2 + 3) * 4'));
        $this->assertSame('-1.000000000000', $this->evaluate('-(4 - 3)'));
        $this->assertSame('0.300000000000', $this->evaluate('0.1 + 0.2'));
        $this->assertSame('2.67', $this->evaluate('round(8 / 3, 2)'));
        $this->assertSame('3', $this->evaluate('round(2.5)'));
        $this->assertSame('-3', $this->evaluate('round(-2.5)'));
        $this->assertNull($this->evaluate('1 / 0'));
        $this->assertNull($this->evaluate('menge * 2'));
        $this->assertSame('0', $this->evaluate('coalesce(menge, 0)'));
        $this->assertSame('7.5', $this->evaluate('max(menge, 7.5, 3)', ['menge' => 2]));
        $this->assertSame('2', $this->evaluate('min(menge, 7.5, 3)', ['menge' => 2]));
        $this->assertSame('4.2', $this->evaluate('abs(x)', ['x' => '-4.2']));
        $this->assertSame('1.10', Evaluator::round('1.095', 2));

        $this->assertSame('a - (b - c) * (d + e)', Printer::print((new Parser)->parse('a-(b-c)*(d+e)')));
        $this->assertSame('round(menge * einzelpreis, 2)', $this->field('betrag')->optionsArray()['formula']);
    }

    public function test_formulas_are_checked_with_position(): void
    {
        $fields = $this->lines->fresh('fields')->fields->keyBy('name')->all();
        $analyzer = app(FormulaAnalyzer::class);

        foreach ([
            'menge * preis' => 'Zeichen 9: Unbekanntes Feld „preis“.',
            'artikel + 1' => 'Zeichen 1: „artikel“ ist kein Zahlenfeld.',
            'neu + 1' => 'Zeichen 1: Ein Feld kann nicht mit sich selbst rechnen („neu“).',
            'sqrt(menge)' => 'Zeichen 1: Unbekannte Funktion „sqrt“.',
            'round(menge, 2, 3)' => 'Zeichen 1: Falsche Anzahl Werte für round().',
            'round(menge, einzelpreis)' => 'Zeichen 14: Die Stellen bei round() müssen eine ganze Zahl von 0 bis 10 sein.',
            'menge * (2' => 'Zeichen 11: „)“ fehlt.',
            'menge # 2' => 'Zeichen 7: Das Zeichen „#“ ist nicht erlaubt.',
            'sum(lieferungen.betrag)' => 'Zeichen 1: „lieferungen“ ist keine Tabelle, die mit einer Verknüpfung auf diese Tabelle zeigt.',
            'positionen.betrag' => 'Zeichen 1: „positionen.betrag“ ist nur in sum(), count(), min(), max() oder avg() möglich.',
        ] as $formula => $message) {
            try {
                $analyzer->compile($formula, $fields, 'neu');
                $this->fail("Angenommen: {$formula}");
            } catch (FormulaException $e) {
                $this->assertSame($message, $e->getMessage(), $formula);
            }
        }

        $this->assertSame(['menge', 'einzelpreis'], $analyzer->compile('round(menge * einzelpreis, 2)', $fields, 'neu')['fields']);
    }

    public function test_circular_references_are_rejected_and_name_the_formulas(): void
    {
        try {
            $this->service()->updateField($this->field('betrag'), ['options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'mwst * 10']]);
            $this->fail('Kreisbezug erwartet.');
        } catch (FormulaException $e) {
            // Die Meldung zeigt den Kreis und die Formeln, die ihn bilden.
            $this->assertMatchesRegularExpression('/^Kreisbezug: (betrag → mwst → betrag|mwst → betrag → mwst)\. Die Formeln: /u', $e->getMessage());
            $this->assertStringContainsString('betrag = mwst * 10', $e->getMessage());
            $this->assertStringContainsString('mwst = betrag * 0.081', $e->getMessage());
        }
    }

    public function test_formula_field_checks_while_typing_and_clears_old_errors(): void
    {
        $this->actingAs($this->superAdmin());
        $table = CustomTable::query()->where('name', 'positionen')->sole();

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->mountAction(TestAction::make('edit')->table($this->field('mwst')))
            ->fillForm(['options.formula' => 'betrag * 1,081'])
            ->assertHasFormErrors(['options.formula'])
            ->fillForm(['options.formula' => 'betrag * 0.081'])
            ->assertHasNoFormErrors(['options.formula']);
    }

    public function test_values_are_calculated_when_saving_in_order(): void
    {
        $line = DynamicRecord::forTable($this->lines->fresh('fields'))->create(['artikel' => 'Schraube', 'menge' => 3, 'einzelpreis' => 1.15]);
        $this->assertSame(['3.45', '0.28'], [$line->betrag, $line->mwst]);

        $line->update(['menge' => 10]);
        $this->assertSame(['11.50', '0.93'], [$line->fresh()->betrag, $line->fresh()->mwst]);

        // Die Änderung des berechneten Werts steht im Protokoll.
        $log = Activity::query()->where('subject_type', $line->getMorphClass())->where('subject_id', $line->id)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('11.50', (string) $log->attribute_changes['attributes']['betrag']);

        $line->update(['einzelpreis' => null]);
        $this->assertNull($line->fresh()->betrag);
    }

    public function test_form_shows_but_never_takes_computed_values(): void
    {
        $this->actingAs($this->superAdmin());

        Livewire::test(DynamicDataPage::class, ['table' => 'positionen'])
            ->mountAction(TestAction::make(CreateAction::class)->table())
            ->setActionData(['artikel' => 'Mutter', 'menge' => 4, 'einzelpreis' => '0.20', 'betrag' => '999'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame('0.80', DynamicRecord::forTable($this->lines->fresh('fields'))->newQuery()->where('artikel', 'Mutter')->value('betrag'));
        // Anzeige wie eine Dezimalzahl (deutsch: Komma).
        $record = DynamicRecord::forTable($this->lines->fresh('fields'))->newQuery()->where('artikel', 'Mutter')->firstOrFail();
        $this->assertSame('0,80', DynamicValueFormatter::display($this->field('betrag'), $record));
    }

    public function test_formula_changes_and_new_fields_recalculate_existing_records(): void
    {
        $line = DynamicRecord::forTable($this->lines->fresh('fields'))->create(['artikel' => 'Dübel', 'menge' => 2, 'einzelpreis' => 0.5]);
        $updatedAt = $line->fresh()->updated_at;

        $this->service()->updateField($this->field('betrag'), ['options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'menge * einzelpreis + 1']]);
        $this->assertSame('2.00', $line->fresh()->betrag);
        $this->assertSame('0.16', $line->fresh()->mwst);
        // Neuberechnung wegen der Formel: kein neuer Änderungszeitpunkt.
        $this->assertEquals($updatedAt, $line->fresh()->updated_at);

        $this->service()->addField($this->lines->fresh(), ['name' => 'menge_doppelt', 'label' => 'Doppelt', 'type' => 'computed', 'options' => ['result' => 'integer', 'formula' => 'menge * 2']]);
        $this->assertSame(4, $line->fresh()->menge_doppelt);

        // Direkt in der Datenbank geändert: der Befehl rechnet nach.
        app(DynamicDataNamespace::class)->connection()->table(app(DynamicDataNamespace::class)->qualify('positionen'))->update(['menge' => 5]);
        $this->artisan('computed:recompute', ['table' => ['positionen']])->assertSuccessful();
        $this->assertSame(['3.50', 10], [$line->fresh()->betrag, $line->fresh()->menge_doppelt]);
    }

    public function test_field_changes_respect_formulas(): void
    {
        $this->service()->updateField($this->field('menge'), ['name' => 'anzahl']);
        $this->assertSame('round(anzahl * einzelpreis, 2)', $this->field('betrag')->optionsArray()['formula']);

        try {
            $this->service()->updateField($this->field('anzahl'), ['type' => 'string', 'options' => ['length' => 30]]);
            $this->fail('Typwechsel trotz Formel.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('betrag', $e->getMessage());
        }

        $this->service()->updateField($this->field('anzahl'), ['is_active' => false]);
        $this->expectException(BusinessRuleException::class);
        $this->service()->dropField($this->field('anzahl'), 'anzahl');
    }

    public function test_computed_fields_behave_like_numbers_elsewhere(): void
    {
        $betrag = $this->field('betrag');

        $this->assertSame(FieldType::Decimal, $betrag->valueType());
        $this->assertNotNull(collect(app(DynamicTableBuilder::class)->filters($this->lines->fresh('fields')))->first(fn ($f): bool => $f->getName() === 'betrag'));

        // Regel mit berechnetem Feld; Typwechsel Dezimalzahl ↔ berechnet ohne Spaltenänderung.
        $this->service()->updateTable($this->lines->fresh('fields'), ['validation_rules' => [['left' => 'betrag', 'operator' => '>=', 'right_kind' => 'value', 'right_value' => '0', 'when_filled' => true]]]);
        $this->assertCount(1, $this->lines->fresh()->tableRules());

        $line = DynamicRecord::forTable($this->lines->fresh('fields'))->create(['menge' => 2, 'einzelpreis' => 1]);
        $this->service()->updateField($this->field('mwst'), ['type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]]);
        $this->assertSame('0.16', $line->fresh()->mwst);
    }
}
