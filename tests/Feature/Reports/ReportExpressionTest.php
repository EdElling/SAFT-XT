<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Reports\DefinitionResolver;
use App\Reports\Editor\DesignerState;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportDescriber;
use App\Reports\ReportExpression;
use App\Reports\ReportResult;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use App\Services\DynamicSchemaService;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Formeln in Auswertungen: berechnete Werte und Listenspalten, auch über Verknüpfungen. */
class ReportExpressionTest extends DynamicDataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'artikel', 'label' => ['de' => 'Artikel']], [
            ['name' => 'name', 'label' => ['de' => 'Name'], 'type' => 'string'],
            ['name' => 'preis', 'label' => ['de' => 'Preis'], 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
        $schema->createTable(['name' => 'bewegungen', 'label' => ['de' => 'Bewegungen']], [
            ['name' => 'nr', 'label' => ['de' => 'Nr'], 'type' => 'string'],
            ['name' => 'artikel_id', 'label' => ['de' => 'Artikel'], 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_fields' => ['name']]],
            ['name' => 'menge', 'label' => ['de' => 'Menge'], 'type' => 'integer'],
            ['name' => 'teiler', 'label' => ['de' => 'Teiler'], 'type' => 'integer'],
        ]);

        $hammer = DynamicRecord::forTableName('artikel')->create(['name' => 'Hammer', 'preis' => '12.50'])->id;
        $zange = DynamicRecord::forTableName('artikel')->create(['name' => 'Zange', 'preis' => '7.25'])->id;

        foreach ([['B1', $hammer, 2, 4], ['B2', $hammer, 3, 0], ['B3', $zange, 7, 2], ['B4', $zange, null, 1]] as [$nr, $item, $menge, $teiler]) {
            DynamicRecord::forTableName('bewegungen')->create(['nr' => $nr, 'artikel_id' => $item, 'menge' => $menge, 'teiler' => $teiler]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        return [
            ['key' => 'nr', 'path' => [], 'field' => $this->fieldId('bewegungen', 'nr')],
            ['key' => 'artikel', 'path' => [], 'field' => $this->fieldId('bewegungen', 'artikel_id')],
            ['key' => 'menge', 'path' => [], 'field' => $this->fieldId('bewegungen', 'menge')],
            ['key' => 'teiler', 'path' => [], 'field' => $this->fieldId('bewegungen', 'teiler')],
            ['key' => 'preis', 'path' => [$this->fieldId('bewegungen', 'artikel_id')], 'field' => $this->fieldId('artikel', 'preis')],
        ];
    }

    /** @return array<string, mixed> */
    private function expr(string $text): array
    {
        return ReportExpression::parse($text, ['Menge' => 'menge', 'Teiler' => 'teiler', 'Artikel › Preis' => 'preis', 'Nr' => 'nr']);
    }

    private function definition(array $data): ReportDefinition
    {
        return ReportDefinition::fromArray(['base' => (int) CustomTable::query()->where('name', 'bewegungen')->value('id'), 'fields' => $this->fields(), ...$data]);
    }

    private function report(array $data): ReportResult
    {
        return app(ReportRunner::class)->run($this->definition($data), $this->admin);
    }

    /** @return array<string, array<string, string>> formatiert je Nr bzw. Gruppe */
    private function formatted(ReportResult $result, string $by): array
    {
        $formatter = app(ValueFormatter::class);
        $rows = [];

        foreach ($result->rows as $row) {
            $rows[$formatter->format($row[$by], $result->column($by))] = array_map(fn ($c): string => $formatter->format($row[$c->key], $c), array_combine(array_map(fn ($c) => $c->key, $result->columns), $result->columns));
        }

        return $rows;
    }

    public function test_calculated_values_over_a_relation(): void
    {
        $result = $this->report([
            'groups' => ['artikel'],
            'measures' => [
                ['key' => 'umsatz', 'agg' => 'sum', 'expr' => $this->expr('[Menge] × [Artikel › Preis]'), 'scale' => 2],
                ['key' => 'schnitt', 'agg' => 'avg', 'expr' => $this->expr('[Menge] * [Artikel › Preis]'), 'scale' => 3],
                ['key' => 'gross', 'agg' => 'max', 'expr' => $this->expr('[Menge] * [Artikel › Preis]'), 'scale' => 0],
            ],
            'sort' => [['key' => 'umsatz', 'dir' => 'desc']],
            'visual' => ['type' => 'bar'],
        ]);

        // Hammer 2 × 12,50 + 3 × 12,50 = 62,50; Zange 7 × 7,25 = 50,75 (Menge leer zählt nicht).
        $rows = $this->formatted($result, 'artikel');
        $this->assertSame(['Hammer', 'Zange'], array_keys($rows));
        $this->assertSame(['62,50', '31,250', '38'], [$rows['Hammer']['umsatz'], $rows['Hammer']['schnitt'], $rows['Hammer']['gross']]);
        $this->assertSame(['50,75', '50,750', '51'], [$rows['Zange']['umsatz'], $rows['Zange']['schnitt'], $rows['Zange']['gross']]);

        $this->assertStringContainsString('Summe [Menge] × [Artikel › Preis]', app(ReportDescriber::class)->describe($result->report));
    }

    public function test_list_columns_functions_division_and_empty_values(): void
    {
        $result = $this->report([
            'fields' => $this->fields(),
            'show' => ['nr', 'quote', 'gerundet', 'kleiner', 'ersatz', 'betrag'],
            'expressions' => [
                ['key' => 'quote', 'expr' => $this->expr('[Menge] / [Teiler]'), 'label' => 'Quote', 'scale' => 2],
                ['key' => 'gerundet', 'expr' => $this->expr('round([Menge] / 3; 1)'), 'label' => null, 'scale' => 1],
                ['key' => 'kleiner', 'expr' => $this->expr('min([Menge]; [Teiler])'), 'label' => 'Kleiner', 'scale' => 0],
                ['key' => 'ersatz', 'expr' => $this->expr('coalesce([Menge]; 0) - abs(-1)'), 'label' => 'Ersatz', 'scale' => 0],
                ['key' => 'betrag', 'expr' => $this->expr('[Menge] * [Artikel › Preis]'), 'label' => 'Betrag', 'scale' => 2],
            ],
            'sort' => [['key' => 'betrag', 'dir' => 'desc']],
        ]);

        $rows = $this->formatted($result, 'nr');
        // Sortiert nach Betrag absteigend; leerer Betrag (B4) je nach Datenbank zuerst oder zuletzt.
        $this->assertSame(['B3', 'B2', 'B1'], array_values(array_filter(array_keys($rows), fn (string $nr): bool => $nr !== 'B4')));
        $this->assertSame(['0,50', '0,7', '2', '1', '25,00'], array_values(array_intersect_key($rows['B1'], array_flip(['quote', 'gerundet', 'kleiner', 'ersatz', 'betrag']))));
        $this->assertSame('', $rows['B2']['quote'], 'Division durch 0 ergibt leer.');
        $this->assertSame('3,50', $rows['B3']['quote'], '7 / 2 = 3,5 auch bei Ganzzahlen.');
        $this->assertSame(['', '', '-1'], [$rows['B4']['quote'], $rows['B4']['kleiner'], $rows['B4']['ersatz']], 'Leeres Feld ergibt leer, außer mit coalesce.');
        $this->assertSame('Quote', $result->column('quote')->label);
        $this->assertSame('round([Menge] ÷ 3; 1)', $result->column('gerundet')->label);
    }

    public function test_invalid_formulas_are_rejected(): void
    {
        $base = ['groups' => ['artikel'], 'visual' => ['type' => 'table']];
        $cases = [
            'Nr ist Text' => [['key' => 'm', 'agg' => 'sum', 'expr' => $this->expr('[Nr] * 2')], '„Nr“ ist keine Zahl'],
            'Anzahl' => [['key' => 'm', 'agg' => 'count', 'expr' => $this->expr('[Menge]')], 'Summe, Durchschnitt, Minimum oder Maximum'],
            'Funktion' => [['key' => 'm', 'agg' => 'sum', 'expr' => ['call' => 'sqrt', 'args' => [['field' => 'menge']]]], 'Unbekannte Funktion'],
            'round' => [['key' => 'm', 'agg' => 'sum', 'expr' => ['call' => 'round', 'args' => [['field' => 'menge'], ['field' => 'teiler']]]], 'round braucht'],
            'Zusammenfassung' => [['key' => 'm', 'agg' => 'sum', 'expr' => ['agg' => 'sum', 'table' => 'x', 'field' => 'menge']], 'ungültig'],
            'Einschleusen' => [['key' => 'm', 'agg' => 'sum', 'expr' => ['num' => '1) FROM users --']], 'ungültig'],
            'Feld fehlt' => [['key' => 'm', 'agg' => 'sum', 'expr' => ['field' => 'gibtsnicht']], 'Unbekanntes Feld'],
        ];

        foreach ($cases as $name => [$measure, $message]) {
            try {
                app(DefinitionResolver::class)->resolve($this->definition([...$base, 'measures' => [$measure]]));
                $this->fail($name.': erwartet ungültig');
            } catch (InvalidReportException $e) {
                $this->assertStringContainsString($message, implode(' ', $e->errors), $name);
            }
        }

        // Zu lang.
        $long = $this->expr(implode(' + ', array_fill(0, 60, '[Menge]')));
        $this->expectException(InvalidReportException::class);
        app(DefinitionResolver::class)->resolve($this->definition([...$base, 'measures' => [['key' => 'm', 'agg' => 'sum', 'expr' => $long]]]));
    }

    public function test_calculated_measures_compute_with_the_other_values_of_the_row(): void
    {
        $calc = fn (string $text): array => ReportExpression::parse($text, ['Summe' => 's', 'Anzahl' => 'n', 'Kleinster Teiler' => 'mt', 'Preis' => 'p', 'Nr' => 'nr_max', 'Quote' => 'c1']);
        $definition = [
            'groups' => ['artikel'],
            'measures' => [
                ['key' => 's', 'agg' => 'sum', 'field' => 'menge', 'label' => 'Summe'],
                ['key' => 'n', 'agg' => 'count', 'field' => null, 'label' => 'Anzahl'],
                ['key' => 'mt', 'agg' => 'min', 'field' => 'teiler', 'label' => 'Kleinster Teiler'],
                ['key' => 'p', 'agg' => 'avg', 'field' => 'preis', 'label' => 'Preis'],
                ['key' => 'c1', 'agg' => 'calc', 'field' => null, 'label' => null, 'calc' => $calc('[Summe] ÷ [Anzahl]'), 'scale' => 2],
                ['key' => 'c2', 'agg' => 'calc', 'field' => null, 'label' => 'Je Teiler', 'calc' => $calc('[Summe] / [Kleinster Teiler]'), 'scale' => 1],
                ['key' => 'c3', 'agg' => 'calc', 'field' => null, 'label' => 'Wert', 'calc' => $calc('round([Preis] * [Summe]; 1)'), 'scale' => 1],
            ],
            'sort' => [['key' => 'c1', 'dir' => 'desc']],
            'visual' => ['type' => 'table'],
        ];
        $result = $this->report($definition);

        // Hammer: Summe 5, Anzahl 2, kleinster Teiler 0, Preis 12,50; Zange: 7, 2, 1, 7,25.
        $rows = $this->formatted($result, 'artikel');
        $this->assertSame(['Zange', 'Hammer'], array_keys($rows), 'Absteigend nach der Kennzahl.');
        $this->assertSame(['3,50', '7,0', '50,8'], [$rows['Zange']['c1'], $rows['Zange']['c2'], $rows['Zange']['c3']]);
        $this->assertSame(['2,50', '', '62,5'], [$rows['Hammer']['c1'], $rows['Hammer']['c2'], $rows['Hammer']['c3']], 'Division durch 0 ergibt leer.');
        $this->assertSame('[Summe] ÷ [Anzahl]', $result->column('c1')->label);
        $this->assertStringContainsString('[Summe] ÷ [Anzahl]', app(ReportDescriber::class)->describe($result->report));

        // Auch gruppierte Zahlenspalten (je Zeile ein Wert): [Artikel › Preis] × [Summe], auch über den Editor.
        $grouped = [
            'groups' => ['artikel', 'preis'],
            'measures' => [
                ['key' => 's', 'agg' => 'sum', 'field' => 'menge', 'label' => 'Summe'],
                ['key' => 'w', 'agg' => 'calc', 'field' => null, 'label' => 'Wert', 'calc' => ReportExpression::parse('[Preis] × [Summe]', ['Preis' => 'preis', 'Summe' => 's']), 'scale' => 2],
            ],
            'visual' => ['type' => 'table'],
        ];
        $byGroup = $this->formatted($this->report($grouped), 'artikel');
        $this->assertSame(['62,50', '50,75'], [$byGroup['Hammer']['w'], $byGroup['Zange']['w']]);
        $state = DesignerState::fromDefinition($this->definition($grouped));
        $this->assertSame(['op' => '*', 'left' => ['field' => '@'.$state->rows[1]['ref']], 'right' => ['field' => 's']], $state->values[1]['calc']);
        [$back] = DesignerState::fromArray($state->toArray())->toDefinition();
        $this->assertSame(['62,50', '50,75'], array_values(array_map(fn (array $r): string => $r['w'], $this->formatted(app(ReportRunner::class)->run($back, $this->admin), $back->groups[0]))));

        // Editor: Werte behalten ihre Kennung, die Formel bleibt beim Bearbeiten erhalten.
        [$again] = DesignerState::fromArray(DesignerState::fromDefinition($this->definition($definition))->toArray())->toDefinition();
        $this->assertSame(['s', 'n', 'mt', 'p', 'c1', 'c2', 'c3'], array_column($again->measures, 'key'));
        $this->assertEquals(array_column($result->rows, 'c1'), array_column(app(ReportRunner::class)->run($again, $this->admin)->rows, 'c1'));

        // Ungültig: Verweis auf eine Kennzahl aus Werten, auf einen Wert ohne Zahl, auf einen unbekannten Wert.
        $cases = [
            'verschachtelt' => [$calc('[Quote] * 2'), 'Unbekanntes Feld'],
            'keine Zahl' => [$calc('[Nr] * 2'), 'keine Zahl'],
            'unbekannt' => [['field' => 'gibtsnicht'], 'Unbekanntes Feld'],
        ];

        foreach ($cases as $name => [$formula, $message]) {
            $measures = [
                ...array_slice($definition['measures'], 0, 5),
                ['key' => 'nr_max', 'agg' => 'max', 'field' => 'nr', 'label' => 'Nr'],
                ['key' => 'bad', 'agg' => 'calc', 'field' => null, 'label' => null, 'calc' => $formula],
            ];

            try {
                app(DefinitionResolver::class)->resolve($this->definition([...$definition, 'measures' => $measures, 'sort' => []]));
                $this->fail($name.': erwartet ungültig');
            } catch (InvalidReportException $e) {
                $this->assertStringContainsString($message, implode(' ', $e->errors), $name);
            }
        }
    }

    public function test_parse_and_designer_state_round_trip(): void
    {
        $this->assertSame(['op' => '*', 'left' => ['field' => 'menge'], 'right' => ['field' => 'preis']], $this->expr('[Menge] × [Artikel › Preis]'));

        try {
            $this->expr('[Menge] * [Bestand]');
            $this->fail('Unbekannter Name');
        } catch (InvalidReportException $e) {
            $this->assertStringContainsString('Bestand', $e->errors[0]);
        }

        try {
            $this->expr('[Menge] * (');
            $this->fail('Syntaxfehler');
        } catch (InvalidReportException $e) {
            $this->assertNotSame([], $e->errors);
        }

        $definition = $this->definition([
            'groups' => ['artikel'],
            'measures' => [['key' => 'umsatz', 'agg' => 'sum', 'expr' => $this->expr('[Menge] × [Artikel › Preis]'), 'scale' => 2, 'label' => 'Umsatz']],
        ]);
        [$again] = DesignerState::fromArray(DesignerState::fromDefinition($definition)->toArray())->toDefinition();

        $first = app(ReportRunner::class)->run($definition, $this->admin);
        $second = app(ReportRunner::class)->run($again, $this->admin);
        $this->assertEquals(array_column($first->rows, 'umsatz'), array_column($second->rows, 'umsatz'), 'Formel und Schlüssel bleiben beim Bearbeiten im Editor erhalten.');
        $this->assertSame('Umsatz', $second->column('umsatz')->label);
    }
}
