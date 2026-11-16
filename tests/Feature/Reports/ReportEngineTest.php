<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\DynamicData\DataPermissions;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Report;
use App\Models\User;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportDescriber;
use App\Reports\ReportResult;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use App\Services\DynamicSchemaService;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Report-Engine: Definition prüfen, Abfrage bauen, Rechte, Ergebnis und Kreuztabelle. */
class ReportEngineTest extends DynamicDataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        CarbonImmutable::setTestNow('2026-06-15 12:00:00');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'select', 'options' => ['options' => [['value' => 'ber', 'label' => 'Berlin'], ['value' => 'ham', 'label' => 'Hamburg']]]],
            ['name' => 'stammkunde', 'label' => 'Stammkunde', 'type' => 'boolean'],
        ]);
        $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
            ['name' => 'erfasst', 'label' => 'Erfasst', 'type' => 'datetime'],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
        ]);
        $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);

        $kunde = fn (array $data) => DynamicRecord::forTableName('kunden')->create($data);
        $acme = $kunde(['name' => 'ACME GmbH', 'ort' => 'ber', 'stammkunde' => true]);
        $beta = $kunde(['name' => 'Beta 100% AG', 'ort' => 'ham', 'stammkunde' => false]);
        $leer = $kunde(['name' => 'Ohne Ort', 'ort' => null, 'stammkunde' => null]);

        $order = fn (string $nr, int $kunde, string $datum, string $betrag, ?string $erfasst = null) => DynamicRecord::forTableName('bestellungen')
            ->create(['nummer' => $nr, 'kunde_id' => $kunde, 'datum' => $datum, 'betrag' => $betrag, 'erfasst' => $erfasst]);
        $b1 = $order('B-1', $acme->id, '2026-01-10', '100.00', '2026-01-10 23:30:00');
        $b2 = $order('B-2', $acme->id, '2026-01-20', '50.50');
        $b3 = $order('B-3', $beta->id, '2026-02-05', '200.00');
        $order('B-4', $leer->id, '2025-12-31', '10.00');
        $order('B-5', $beta->id, '2026-06-14', '1.00')->delete();

        foreach ([[$b1, 2], [$b1, 3], [$b2, 1], [$b3, 5]] as [$bestellung, $menge]) {
            DynamicRecord::forTableName('positionen')->create(['bestellung_id' => $bestellung->id, 'menge' => $menge]);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_list_with_fields_of_related_tables(): void
    {
        $result = $this->report([
            'base' => $this->table('positionen'),
            'fields' => [
                $this->ref('m', 'positionen.menge'),
                $this->ref('n', 'bestellungen.nummer', ['positionen.bestellung_id']),
                $this->ref('k', 'kunden.name', ['positionen.bestellung_id', 'bestellungen.kunde_id']),
            ],
            'sort' => [['key' => 'm', 'dir' => 'desc']],
        ]);

        $this->assertSame([
            ['m' => 5, 'n' => 'B-3', 'k' => 'Beta 100% AG'],
            ['m' => 3, 'n' => 'B-1', 'k' => 'ACME GmbH'],
            ['m' => 2, 'n' => 'B-1', 'k' => 'ACME GmbH'],
            ['m' => 1, 'n' => 'B-2', 'k' => 'ACME GmbH'],
        ], $result->rows);
        $this->assertSame(['Menge', 'Bestellung › Nummer', 'Bestellung › Kunde › Name'], array_column(array_map(fn ($c) => (array) $c, $result->columns), 'label'));
    }

    public function test_grouped_by_month_with_sum_count_and_relative_filter(): void
    {
        $result = $this->report([
            'base' => $this->table('bestellungen'),
            'fields' => [$this->ref('monat', 'bestellungen.datum', bucket: 'month'), $this->ref('betrag', 'bestellungen.betrag')],
            'groups' => ['monat'],
            'measures' => [['key' => 'summe', 'field' => 'betrag', 'agg' => 'sum'], ['key' => 'anzahl', 'agg' => 'count']],
            'filters' => [['field' => 'monat', 'op' => 'this_year']],
        ]);

        // B-4 ist aus 2025, B-5 gelöscht.
        $this->assertSame([
            ['monat' => '2026-01', 'summe' => 150.5, 'anzahl' => 2],
            ['monat' => '2026-02', 'summe' => 200.0, 'anzahl' => 1],
        ], $result->rows);

        $formatter = new ValueFormatter;
        $this->assertSame('Januar 2026', $formatter->format('2026-01', $result->column('monat')));
        $this->assertSame('150,50', $formatter->format(150.5, $result->column('summe')));
        $this->assertSame(['Datum', 'Summe Betrag', 'Anzahl'], array_map(fn ($c) => $c->label, $result->columns));
    }

    public function test_date_buckets_are_the_same_on_every_database(): void
    {
        $buckets = [];

        foreach (['day', 'week', 'month', 'quarter', 'year'] as $bucket) {
            $result = $this->report([
                'base' => $this->table('bestellungen'),
                'fields' => [$this->ref('s', 'bestellungen.erfasst', bucket: $bucket)],
                'groups' => ['s'],
                'measures' => [['key' => 'n', 'agg' => 'count']],
                'filters' => [['field' => 's', 'op' => 'not_empty']],
            ]);
            $buckets[$bucket] = [$result->rows[0]['s'], (new ValueFormatter)->format($result->rows[0]['s'], $result->column('s'))];
        }

        // B-1 erfasst am Samstag, 10.01.2026 23:30 – Woche ab Montag, 05.01.
        $this->assertSame([
            'day' => ['2026-01-10', '10.01.2026'],
            'week' => ['2026-01-05', 'KW 2/2026'],
            'month' => ['2026-01', 'Januar 2026'],
            'quarter' => ['2026-Q1', 'Q1 2026'],
            'year' => ['2026', '2026'],
        ], $buckets);
    }

    public function test_crosstab_with_related_select_field(): void
    {
        $result = $this->report([
            'base' => $this->table('bestellungen'),
            'fields' => [
                $this->ref('quartal', 'bestellungen.datum', bucket: 'quarter'),
                $this->ref('ort', 'kunden.ort', ['bestellungen.kunde_id']),
                $this->ref('betrag', 'bestellungen.betrag'),
            ],
            'groups' => ['quartal'],
            'columns' => ['ort'],
            'measures' => [['key' => 'summe', 'field' => 'betrag', 'agg' => 'sum']],
            'visual' => ['type' => 'crosstab'],
        ]);

        $cross = $result->crossTab();
        $this->assertSame(['ber', 'ham', null], $cross->columnValues);
        $this->assertSame([['2025-Q4'], ['2026-Q1']], array_column($cross->rows, 'keys'));
        $this->assertSame(150.5, $cross->cell($cross->rows[1], 'ber', 'summe'));
        $this->assertSame(200.0, $cross->cell($cross->rows[1], 'ham', 'summe'));
        $this->assertSame(10.0, $cross->cell($cross->rows[0], null, 'summe'));
        $this->assertNull($cross->cell($cross->rows[0], 'ber', 'summe'));

        $formatter = new ValueFormatter;
        $this->assertSame('Hamburg', $formatter->format('ham', $cross->column));
        $this->assertSame('Q1 2026', $formatter->format('2026-Q1', $cross->rowColumns[0]));
    }

    /** Nummern der Bestellungen, die der Filter durchlässt (b Betrag, d Datum, e Erfasst, k Kundenname). */
    private function numbers(string $field, string $op, mixed $value): array
    {
        $result = $this->report([
            'base' => $this->table('bestellungen'),
            'fields' => [
                $this->ref('nr', 'bestellungen.nummer'),
                $this->ref('b', 'bestellungen.betrag'),
                $this->ref('d', 'bestellungen.datum'),
                $this->ref('e', 'bestellungen.erfasst'),
                $this->ref('k', 'kunden.name', ['bestellungen.kunde_id']),
            ],
            'filters' => [['field' => $field, 'op' => $op, 'value' => $value]],
        ]);

        return array_column($result->rows, 'nr');
    }

    public function test_filters_of_all_kinds(): void
    {
        $this->assertSame(['B-1', 'B-3'], $this->numbers('b', 'between', ['60', '200']));
        $this->assertSame(['B-2', 'B-4'], $this->numbers('b', 'lt', '100'));
        $this->assertSame(['B-1', 'B-3'], $this->numbers('b', 'in', [100, 200]));
        $this->assertSame(['B-2', 'B-3', 'B-4'], $this->numbers('b', 'neq', '100'));
        $this->assertSame(['B-1', 'B-2'], $this->numbers('d', 'between', ['2026-01-01', '2026-01-31']));
        $this->assertSame(['B-3'], $this->numbers('d', 'gt', '2026-01-20'));
        // Datum und Uhrzeit: „gleich“ trifft den ganzen Tag.
        $this->assertSame(['B-1'], $this->numbers('e', 'eq', '2026-01-10'));
        $this->assertSame(['B-2', 'B-3', 'B-4'], $this->numbers('e', 'is_empty', null));
        $this->assertSame([], $this->numbers('d', 'last_days', 7), 'B-5 (gestern) ist gelöscht.');
        $this->assertSame(['B-3'], $this->numbers('k', 'contains', '100%'), '% ist kein Platzhalter.');
        $this->assertSame(['B-1', 'B-2'], $this->numbers('k', 'starts_with', 'acme'), 'Groß-/Kleinschreibung egal.');
    }

    public function test_aggregates_boolean_filter_and_deleted_parent(): void
    {
        $definition = fn (array $filters = []) => [
            'base' => $this->table('bestellungen'),
            'fields' => [
                $this->ref('stamm', 'kunden.stammkunde', ['bestellungen.kunde_id']),
                $this->ref('datum', 'bestellungen.datum'),
                $this->ref('betrag', 'bestellungen.betrag'),
                $this->ref('kunde', 'bestellungen.kunde_id'),
            ],
            'measures' => [
                ['key' => 'avg', 'field' => 'betrag', 'agg' => 'avg'],
                ['key' => 'erste', 'field' => 'datum', 'agg' => 'min'],
                ['key' => 'kunden', 'field' => 'kunde', 'agg' => 'count_distinct'],
            ],
            'filters' => $filters,
        ];

        $all = $this->report($definition())->rows[0];
        $this->assertEqualsWithDelta(90.125, $all['avg'], 0.001);
        $this->assertSame('2025-12-31', substr((string) $all['erste'], 0, 10));
        $this->assertSame(3, $all['kunden']);
        $this->assertSame(1, $this->report($definition([['field' => 'stamm', 'op' => 'is_true']]))->rows[0]['kunden'], 'Nur ACME: ein Kunde.');

        // Gelöschter Kunde: die Bestellung bleibt, die Kundenfelder sind leer.
        // (Löschen über die Oberfläche verhindert SAFT, solange Bestellungen existieren; hier direkt.)
        $kunden = DynamicRecord::forTableName('kunden');
        $kunden->getConnection()->table($kunden->getTable())->where('name', 'ACME GmbH')->update(['deleted_at' => now()]);
        $names = $this->report([
            'base' => $this->table('bestellungen'),
            'fields' => [$this->ref('nr', 'bestellungen.nummer'), $this->ref('k', 'kunden.name', ['bestellungen.kunde_id'])],
        ])->rows;
        $this->assertSame(['nr' => 'B-1', 'k' => null], $names[0]);
    }

    public function test_sentence_describes_the_report_in_every_language(): void
    {
        $definition = ReportDefinition::fromArray([
            'base' => $this->table('bestellungen'),
            'fields' => [
                $this->ref('monat', 'bestellungen.datum', bucket: 'month'),
                $this->ref('ort', 'kunden.ort', ['bestellungen.kunde_id']),
                $this->ref('betrag', 'bestellungen.betrag'),
            ],
            'groups' => ['monat'],
            'columns' => ['ort'],
            'measures' => [['key' => 's', 'field' => 'betrag', 'agg' => 'sum'], ['key' => 'n', 'agg' => 'count']],
            'filters' => [['field' => 'ort', 'op' => 'in', 'value' => ['ber', 'ham']], ['field' => 'betrag', 'op' => 'between', 'value' => ['10', '1000']]],
            'sort' => [['key' => 's', 'dir' => 'desc']],
            'limit' => 50,
        ]);
        $sentences = [];

        foreach (['de', 'en', 'fr', 'it'] as $locale) {
            app()->setLocale($locale);
            $sentences[$locale] = app(ReportDescriber::class)->describe(app(ReportRunner::class)->resolve($definition, $this->admin));
        }

        $this->assertSame('Summe Betrag und Anzahl Datensätze je Monat (Datum), aufgeteilt nach Kunde › Ort; nur Kunde › Ort ist „Berlin“ oder „Hamburg“ und Betrag zwischen „10“ und „1.000“; sortiert nach Summe Betrag absteigend; höchstens 50 Zeilen', $sentences['de']);
        $this->assertStringStartsWith('Sum of Betrag and Number of records per Month (Datum), split by Kunde › Ort; only', $sentences['en']);
        $this->assertStringContainsString('par Mois (Datum)', $sentences['fr']);
        $this->assertStringContainsString('per Mese (Datum)', $sentences['it']);

        // Heißt das Gruppenfeld schon wie die Stufe, keine Doppelung („je Monat“ statt „je Monat (Monat)“).
        app()->setLocale('de');
        $named = ReportDefinition::fromArray([
            'base' => $this->table('bestellungen'),
            'fields' => [$this->ref('m', 'bestellungen.datum', bucket: 'month', label: 'Monat')],
            'groups' => ['m'],
            'measures' => [['key' => 'n', 'agg' => 'count']],
        ]);
        $this->assertSame('Anzahl Datensätze je Monat', app(ReportDescriber::class)->describe(app(ReportRunner::class)->resolve($named, $this->admin)));
    }

    public function test_limit_marks_the_result_as_truncated(): void
    {
        $result = $this->report(['base' => $this->table('bestellungen'), 'fields' => [$this->ref('nr', 'bestellungen.nummer')], 'limit' => 3]);
        $this->assertCount(3, $result->rows);
        $this->assertTrue($result->truncated);

        $this->assertFalse($this->report(['base' => $this->table('bestellungen'), 'fields' => [$this->ref('nr', 'bestellungen.nummer')]])->truncated);
        $this->assertCount(2, app(ReportRunner::class)->run(ReportDefinition::fromArray([
            'base' => $this->table('bestellungen'), 'fields' => [$this->ref('nr', 'bestellungen.nummer')],
        ]), $this->admin, limit: 2)->rows, 'Vorschau-Grenze.');
    }

    public function test_execution_depends_only_on_the_data_permissions_of_the_caller(): void
    {
        $definition = ReportDefinition::fromArray([
            'base' => $this->table('bestellungen'),
            'fields' => [$this->ref('nr', 'bestellungen.nummer'), $this->ref('ort', 'kunden.ort', ['bestellungen.kunde_id'])],
            // Kunden nur im Filter – trotzdem ist das Leserecht nötig.
            'filters' => [['field' => 'ort', 'op' => 'eq', 'value' => 'ber']],
        ]);
        $runner = app(ReportRunner::class);

        $orders = $this->userWithPermissions([DataPermissions::name('bestellungen', 'view')]);
        $this->assertFalse($runner->canRun($definition, $orders));

        try {
            $runner->run($definition, $orders);
            $this->fail('Rechtefehler erwartet');
        } catch (InvalidReportException $e) {
            $this->assertSame('report.forbidden', $e->errorCode);
            $this->assertSame('Für diese Auswertung fehlt Ihnen das Leserecht auf: Kunden.', $e->getMessage());
        }

        $both = $this->userWithPermissions([DataPermissions::name('bestellungen', 'view'), DataPermissions::name('kunden', 'view')]);
        $this->assertSame(['B-1', 'B-2'], array_column($runner->run($definition, $both)->rows, 'nr'));
    }

    public function test_definition_is_checked_against_the_data_model_with_all_errors(): void
    {
        $orders = $this->table('bestellungen');
        app(DynamicSchemaService::class)->updateField(CustomTable::query()->find($orders)->fields()->where('name', 'erfasst')->sole(), ['is_active' => false]);

        try {
            $this->report([
                'base' => $orders,
                'fields' => [
                    $this->ref('nr', 'bestellungen.nummer', bucket: 'month'),
                    $this->ref('erf', 'bestellungen.erfasst', label: 'Erfasst am'),
                    $this->ref('Bad Key', 'bestellungen.betrag'),
                ],
                'measures' => [['key' => 's', 'field' => 'nr', 'agg' => 'sum']],
                'visual' => ['type' => 'pie'],
            ]);
            $this->fail('Prüffehler erwartet');
        } catch (InvalidReportException $e) {
            $this->assertSame([
                '„Nummer“: Gruppieren nach Tag, Woche, Monat, Quartal oder Jahr geht nur bei Datumsfeldern.',
                'Das Feld „Erfasst am“ in „Bestellungen“ gibt es nicht mehr oder es ist deaktiviert.',
                'Ungültiger oder doppelter Schlüssel „Bad Key“.',
                'Summe passt nicht zu „Nummer“ (nur bei Zahlen bzw. Datum).',
                'Die Darstellung „Kreisdiagramm“ passt nicht zum Aufbau der Auswertung.',
            ], $e->errors);
        }
    }

    public function test_reports_are_private_or_shared_and_audited(): void
    {
        $author = $this->userWithPermissions(['reports.view', 'reports.create']);
        $other = $this->userWithPermissions(['reports.view', 'reports.create']);
        $definition = ['base' => $this->table('kunden'), 'fields' => [$this->ref('n', 'kunden.name')]];
        $private = Report::create(['name' => 'Privat', 'owner_id' => $author->id, 'definition' => $definition]);
        $shared = Report::create(['name' => 'Geteilt', 'owner_id' => $author->id, 'definition' => $definition, 'is_shared' => true]);

        $this->assertSame(['Geteilt'], Report::query()->visibleTo($other)->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Privat', 'Geteilt'], Report::query()->visibleTo($author)->pluck('name')->all());
        $this->assertTrue($other->can('view', $shared));
        $this->assertFalse($other->can('view', $private));
        $this->assertFalse($other->can('update', $shared), 'Fremde Auswertung nur mit reports.update.');
        $this->assertTrue($author->can('update', $private));
        $this->assertSame($definition, $shared->definitionObject()->toArray()['fields'] === $definition['fields'] ? $definition : null);
        $this->assertTrue(Activity::query()->where('subject_type', Report::class)->where('subject_id', $shared->id)->where('event', 'created')->exists());
    }

    // --- Hilfen -----------------------------------------------------------------------

    /** @param  array<string, mixed>  $definition */
    private function report(array $definition): ReportResult
    {
        return app(ReportRunner::class)->run(ReportDefinition::fromArray($definition), $this->admin);
    }

    private function table(string $name): int
    {
        return (int) CustomTable::query()->where('name', $name)->value('id');
    }

    private function fieldIdOf(string $qualified): int
    {
        [$table, $field] = explode('.', $qualified);

        return (int) CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $field)->value('id');
    }

    /**
     * @param  list<string>  $path  Verknüpfungsfelder als tabelle.feld
     * @return array<string, mixed>
     */
    private function ref(string $key, string $field, array $path = [], ?string $bucket = null, ?string $label = null): array
    {
        return ['key' => $key, 'path' => array_map($this->fieldIdOf(...), $path), 'field' => $this->fieldIdOf($field), 'bucket' => $bucket, 'label' => $label];
    }
}
