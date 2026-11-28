<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Apps\Definition\DefinitionObjects;
use App\Apps\Definition\DefinitionParts;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Report;
use App\Models\User;
use App\Reports\DefinitionResolver;
use App\Reports\Dialects\QueryDialectResolver;
use App\Reports\Editor\DesignerState;
use App\Reports\Editor\FieldCatalog;
use App\Reports\InvalidReportException;
use App\Reports\ReportDefinition;
use App\Reports\ReportDescriber;
use App\Reports\ReportQuery;
use App\Reports\ReportResult;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use Illuminate\Support\Facades\DB;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** B-183: Systemspalten angelegt/geändert am und von in Auswertungen – Namen ohne Verknüpfung über die Verbindungen. */
class ReportSystemFieldsTest extends DynamicDataTestCase
{
    private User $admin;

    private User $zoe;

    private User $anna;

    private CustomTable $kunden;

    private CustomTable $auftraege;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        // Zoe hat die kleinere ID: Nach der ID sortiert stünde sie vor Anna.
        $this->zoe = User::factory()->create(['name' => 'Zoe Zimmer']);
        $this->anna = User::factory()->create(['name' => 'Anna Admin']);

        $this->kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->auftraege = $this->service()->createTable(['name' => 'auftraege', 'label' => 'Aufträge'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);

        $writer = app(RecordWriter::class);

        $this->travelTo('2026-03-01 09:00:00');
        $this->actingAs($this->anna);
        $kunde = $writer->create($this->kunden, ['firma' => 'Muster AG'], WriteOptions::ui());
        $writer->create($this->auftraege, ['titel' => 'A1', 'betrag' => '100.00', 'kunde_id' => $kunde->getKey()], WriteOptions::ui());
        $writer->create($this->auftraege, ['titel' => 'A2', 'betrag' => '50.00'], WriteOptions::ui());

        $this->travelTo('2026-06-15 14:30:00');
        $this->actingAs($this->zoe);
        $writer->create($this->auftraege, ['titel' => 'Z1', 'betrag' => '10.00', 'kunde_id' => $kunde->getKey()], WriteOptions::ui());

        // Ohne Person: Import, Aufgabe, Seed.
        auth()->logout();
        $writer->create($this->auftraege, ['titel' => 'S1', 'betrag' => '1.00'], WriteOptions::seed());
        $this->travelBack();

        $this->actingAs($this->admin);
    }

    public function test_list_shows_dates_and_names_also_of_the_related_table(): void
    {
        $result = $this->report([
            'base' => $this->auftraege->id,
            'fields' => [
                $this->ref('titel', 'auftraege.titel'),
                $this->system('am', 'created_at'),
                $this->system('von', 'created_by'),
                $this->system('kunde_von', 'created_by', ['auftraege.kunde_id']),
            ],
            'sort' => [['key' => 'titel', 'dir' => 'asc']],
        ]);
        $formatter = app(ValueFormatter::class);
        $cell = fn (int $row, string $key): string => $formatter->format($result->rows[$row][$key], $result->column($key));

        $this->assertSame(['Titel', 'Angelegt am', 'Angelegt von', 'Kunde › Angelegt von'], array_map(static fn ($c): string => $c->label, $result->columns));
        $this->assertSame(['A1', 'A2', 'S1', 'Z1'], array_column($result->rows, 'titel'));
        $this->assertSame('Anna Admin', $cell(0, 'von'));
        $this->assertSame('Anna Admin', $cell(0, 'kunde_von'));
        $this->assertSame('', $cell(1, 'kunde_von'));
        $this->assertSame('', $cell(2, 'von'), 'Ohne Person bleibt leer.');
        $this->assertSame('Zoe Zimmer', $cell(3, 'von'));
        $this->assertStringStartsWith('01.03.2026', $cell(0, 'am'));
    }

    public function test_grouped_by_person_counts_and_sorts_by_name(): void
    {
        $definition = [
            'base' => $this->auftraege->id,
            'fields' => [$this->system('von', 'created_by'), $this->ref('betrag', 'auftraege.betrag')],
            'groups' => ['von'],
            'measures' => [['key' => 'n', 'field' => null, 'agg' => 'count'], ['key' => 's', 'field' => 'betrag', 'agg' => 'sum']],
            'filters' => [['field' => 'von', 'op' => 'not_empty', 'value' => null]],
        ];
        $result = $this->report($definition);
        $names = fn (ReportResult $r): array => array_map(fn (array $row): string => app(ValueFormatter::class)->format($row['von'], $r->column('von')), $r->rows);

        $this->assertSame(['Anna Admin', 'Zoe Zimmer'], $names($result), 'Nach Namen, nicht nach Benutzer-ID.');
        $this->assertSame([2, 1], array_column($result->rows, 'n'));
        $this->assertSame([150.0, 10.0], array_column($result->rows, 's'));

        $descending = $this->report([...$definition, 'sort' => [['key' => 'von', 'dir' => 'desc']]]);
        $this->assertSame(['Zoe Zimmer', 'Anna Admin'], $names($descending));

        // Ein gelöschter Benutzer bleibt als Nummer sichtbar.
        $id = $this->zoe->id;
        $this->zoe->delete();
        app()->forgetScopedInstances();
        $this->assertContains('#'.$id, $names($this->report($definition)));
    }

    public function test_person_as_second_group_keeps_the_order_of_the_first(): void
    {
        $result = $this->report([
            'base' => $this->auftraege->id,
            'fields' => [$this->system('jahr', 'created_at', bucket: 'month'), $this->system('von', 'updated_by')],
            'groups' => ['jahr', 'von'],
            'measures' => [['key' => 'n', 'field' => null, 'agg' => 'count']],
        ]);

        $this->assertSame(['2026-03', '2026-06', '2026-06'], array_column($result->rows, 'jahr'));
        $this->assertSame([(string) $this->anna->id, null, (string) $this->zoe->id], array_column($result->rows, 'von'), 'Im Juni: ohne Person vor Zoe.');
    }

    public function test_dates_group_by_bucket_and_take_date_filters_and_minimum(): void
    {
        $result = $this->report([
            'base' => $this->auftraege->id,
            'fields' => [$this->system('monat', 'created_at', bucket: 'month'), $this->system('am', 'updated_at')],
            'groups' => ['monat'],
            'measures' => [['key' => 'n', 'field' => null, 'agg' => 'count'], ['key' => 'zuletzt', 'field' => 'am', 'agg' => 'max']],
            'filters' => [['field' => 'am', 'op' => 'gte', 'value' => '2026-06-01']],
        ]);

        $this->assertSame([['monat' => '2026-06', 'n' => 2, 'zuletzt' => '2026-06-15 14:30:00']], $result->rows);
    }

    public function test_person_filters_including_the_person_who_runs_the_report(): void
    {
        $titles = fn (string $op, mixed $value = null, ?User $as = null): array => array_column(app(ReportRunner::class)->run(ReportDefinition::fromArray([
            'base' => $this->auftraege->id,
            'fields' => [$this->ref('titel', 'auftraege.titel'), $this->system('von', 'created_by')],
            'show' => ['titel'],
            'filters' => [['field' => 'von', 'op' => $op, 'value' => $value]],
            'sort' => [['key' => 'titel', 'dir' => 'asc']],
        ]), $as ?? $this->admin)->rows, 'titel');

        $this->assertSame(['A1', 'A2'], $titles('eq', $this->anna->id));
        $this->assertSame(['S1', 'Z1'], $titles('neq', $this->anna->id), 'Ungleich schliesst Datensätze ohne Person ein, wie bei Feldern.');
        $this->assertSame(['A1', 'A2', 'Z1'], $titles('in', [$this->anna->id, $this->zoe->id]));
        $this->assertSame(['S1'], $titles('is_empty'));
        $this->assertSame(['A1', 'A2', 'Z1'], $titles('not_empty'));
        $this->assertSame([], $titles('is_me'), 'Der Admin hat nichts angelegt.');

        $this->zoe->givePermissionTo('data.auftraege.view');
        $this->assertSame(['Z1'], $titles('is_me', as: $this->zoe));
        // Aufruf ohne Person (Hintergrund): Die Abfrage trifft nichts.
        $resolved = app(DefinitionResolver::class)->resolve(ReportDefinition::fromArray([
            'base' => $this->auftraege->id,
            'fields' => [$this->ref('titel', 'auftraege.titel'), $this->system('von', 'created_by')],
            'filters' => [['field' => 'von', 'op' => 'is_me', 'value' => null]],
        ]));
        $connection = app(DynamicDataNamespace::class)->connection();
        $this->assertSame(0, app(ReportQuery::class)->build($resolved, app(QueryDialectResolver::class)->for($connection), null)->count(), 'Ohne Person trifft „bin ich“ nichts.');
    }

    public function test_count_distinct_of_persons_and_what_is_rejected(): void
    {
        $result = $this->report([
            'base' => $this->auftraege->id,
            'fields' => [$this->system('von', 'created_by')],
            'measures' => [['key' => 'personen', 'field' => 'von', 'agg' => 'count_distinct']],
        ]);
        $this->assertSame([['personen' => 2]], $result->rows);

        $errors = function (array $definition): array {
            try {
                $this->report(['base' => $this->auftraege->id, ...$definition]);
            } catch (InvalidReportException $e) {
                return $e->errors;
            }

            return [];
        };

        $this->assertSame(['Summe passt nicht zu „Angelegt von“ (nur bei Zahlen bzw. Datum).'], $errors([
            'fields' => [$this->system('von', 'created_by')],
            'measures' => [['key' => 's', 'field' => 'von', 'agg' => 'sum']],
        ]));
        $this->assertSame(['Eine Liste lässt sich nicht nach „Angelegt von“ sortieren. Gruppiert nach der Person wird nach dem Namen sortiert.'], $errors([
            'fields' => [$this->system('von', 'created_by')],
            'sort' => [['key' => 'von', 'dir' => 'asc']],
        ]));
        $this->assertSame(['Dieser Filter passt nicht zum Feld „Angelegt von“.'], $errors([
            'fields' => [$this->system('von', 'created_by')],
            'filters' => [['field' => 'von', 'op' => 'contains', 'value' => 'Anna']],
        ]));
        $this->assertSame(['Das Feld „deleted_at“ in „Aufträge“ gibt es nicht mehr oder es ist deaktiviert.'], $errors([
            'fields' => [$this->system('x', 'deleted_at')],
        ]));
    }

    public function test_system_fields_stop_at_interfaces_of_other_apps(): void
    {
        $lager = self::ensureTestApp();
        $shop = App::query()->create(['name' => ['de' => 'Shop'], 'prefix' => 'shop', 'sort_order' => 2]);
        $artikel = $this->service()->createTable(['app_id' => $lager->id, 'name' => 'lg_artikel', 'label' => 'Artikel'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
        ]);
        $this->service()->updateInterface($artikel->refresh(), true, ['name']);
        $bestellungen = $this->service()->createTable(['app_id' => $shop->id, 'name' => 'shop_bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'lg_artikel', 'display_fields' => ['name']]],
        ]);
        $relation = $this->fieldId('shop_bestellungen', 'artikel_id');

        $refs = array_column(app(FieldCatalog::class)->entries($bestellungen->id, $this->admin), 'ref');
        $this->assertContains(':created_by', $refs);
        $this->assertContains($relation.':'.$this->fieldId('lg_artikel', 'name'), $refs);
        $this->assertNotContains($relation.':created_by', $refs, 'Hinter der Schnittstelle nur freigegebene Felder.');

        try {
            $this->report(['base' => $bestellungen->id, 'fields' => [['key' => 'x', 'path' => [$relation], 'field' => 0, 'system' => 'created_by']]]);
            $this->fail('Prüffehler erwartet');
        } catch (InvalidReportException $e) {
            $this->assertSame(['Das Feld „created_by“ in „Artikel“ gibt es nicht mehr oder es ist deaktiviert.'], $e->errors);
        }
    }

    public function test_editor_references_system_fields_by_name_and_keeps_old_definitions_unchanged(): void
    {
        $catalog = app(FieldCatalog::class);
        $entries = collect($catalog->entries($this->auftraege->id, $this->admin))->keyBy('ref');
        $relation = $this->fieldId('auftraege', 'kunde_id');

        $this->assertSame('Angelegt von', $entries[':created_by']['label']);
        $this->assertSame('Kunde › Geändert am', $entries[$relation.':updated_at']['label']);
        $this->assertSame(['path' => [$relation], 'field' => 0, 'system' => 'updated_by'], FieldCatalog::parse($relation.':updated_by'));

        $state = new DesignerState(
            base: $this->auftraege->id,
            rows: [['ref' => ':created_by', 'bucket' => null, 'label' => null]],
            values: [['id' => 'n', 'agg' => 'count', 'ref' => null, 'label' => null]],
            filters: [['ref' => $relation.':created_at', 'op' => 'this_year', 'value' => null, 'value_to' => null, 'values' => []]],
        );
        [$definition] = $state->toDefinition();

        $this->assertSame('created_by', $definition->fields[0]['system']);
        $this->assertSame(0, $definition->fields[0]['field']);
        $this->assertSame([':created_by'], array_column(DesignerState::fromDefinition($definition)->rows, 'ref'));
        $this->assertSame($relation.':created_at', DesignerState::fromDefinition($definition)->filters[0]['ref']);

        // Ohne Systemspalte bleibt die gespeicherte Form wie vor B-183.
        $plain = ReportDefinition::fromArray(['base' => $this->auftraege->id, 'fields' => [$this->ref('t', 'auftraege.titel')]]);
        $this->assertSame(['key', 'path', 'field', 'bucket', 'label'], array_keys($plain->toArray()['fields'][0]));
    }

    public function test_sentence_names_the_person_or_me(): void
    {
        $describe = fn (string $op, mixed $value): string => app(ReportDescriber::class)->describe(app(ReportRunner::class)->resolve(ReportDefinition::fromArray([
            'base' => $this->auftraege->id,
            'fields' => [$this->ref('titel', 'auftraege.titel'), $this->system('von', 'created_by')],
            'show' => ['titel'],
            'filters' => [['field' => 'von', 'op' => $op, 'value' => $value]],
        ]), $this->admin));

        $this->assertStringContainsString('Angelegt von gleich „Anna Admin“', $describe('eq', $this->anna->id));
        $this->assertStringContainsString('Angelegt von: bin ich', $describe('is_me', null));
    }

    public function test_assistant_model_lists_system_fields_without_user_names(): void
    {
        $structure = app(FieldCatalog::class)->structure($this->admin, true);
        $fields = collect(collect($structure)->firstWhere('label', 'Aufträge')['fields'])->keyBy('id');

        $this->assertSame('date', $fields['created_at']['type']);
        $this->assertSame('person', $fields['updated_by']['type']);
        $this->assertStringNotContainsString('Anna', (string) json_encode($structure));
    }

    public function test_app_definition_keeps_system_fields_and_drops_filters_on_specific_persons(): void
    {
        $report = Report::create(['name' => 'Meine Aufträge', 'owner_id' => $this->admin->id, 'definition' => ReportDefinition::fromArray([
            'base' => $this->auftraege->id,
            'fields' => [$this->ref('titel', 'auftraege.titel'), $this->system('von', 'created_by'), $this->system('geaendert', 'updated_by')],
            'show' => ['titel'],
            'filters' => [
                ['field' => 'von', 'op' => 'is_me', 'value' => null],
                ['field' => 'geaendert', 'op' => 'in', 'value' => [$this->anna->id]],
                ['field' => 'geaendert', 'op' => 'not_empty', 'value' => null],
            ],
        ])->toArray()]);

        $exported = app(DefinitionParts::class)->report($report)['definition'];

        $this->assertSame('auftraege', $exported['base']);
        $this->assertSame(['field' => null, 'system' => 'created_by'], array_intersect_key($exported['fields'][1], array_flip(['field', 'system'])));
        $this->assertSame(['is_me', 'not_empty'], array_column($exported['filters'], 'op'), 'Benutzer-IDs gehören nicht ins Paket.');
        $this->assertStringNotContainsString((string) json_encode([$this->anna->id]), (string) json_encode($exported['filters']));

        $imported = ReportDefinition::fromArray(app(DefinitionObjects::class)->reportDefinition($exported));
        $this->assertSame('created_by', $imported->fields[1]['system']);
        $this->assertSame(['S1'], array_column(app(ReportRunner::class)->run(ReportDefinition::fromArray([...$imported->toArray(), 'filters' => [['field' => 'von', 'op' => 'is_empty', 'value' => null]]]), $this->admin)->rows, 'titel'));
    }

    public function test_names_cost_one_query_on_the_main_connection(): void
    {
        DB::enableQueryLog();
        $this->report([
            'base' => $this->auftraege->id,
            'fields' => [$this->ref('titel', 'auftraege.titel'), $this->system('von', 'created_by'), $this->system('geaendert', 'updated_by')],
        ]);
        $users = collect(DB::getQueryLog())->filter(static fn (array $q): bool => str_contains($q['query'], '"users"') || str_contains($q['query'], '`users`'))->count();
        DB::disableQueryLog();

        $this->assertSame(1, $users);
        $this->assertNotSame([], DynamicRecord::forTable($this->auftraege)->newQuery()->pluck('id')->all());
    }

    // --- Hilfen -----------------------------------------------------------------------

    /** @param  array<string, mixed>  $definition */
    private function report(array $definition): ReportResult
    {
        return app(ReportRunner::class)->run(ReportDefinition::fromArray($definition), $this->admin);
    }

    /**
     * @param  list<string>  $path  Verknüpfungsfelder als tabelle.feld
     * @return array<string, mixed>
     */
    private function ref(string $key, string $field, array $path = []): array
    {
        [$table, $name] = explode('.', $field);

        return ['key' => $key, 'path' => array_map(fn (string $p): int => $this->fieldId(...explode('.', $p)), $path), 'field' => $this->fieldId($table, $name), 'bucket' => null, 'label' => null];
    }

    /**
     * @param  list<string>  $path
     * @return array<string, mixed>
     */
    private function system(string $key, string $name, array $path = [], ?string $bucket = null): array
    {
        return ['key' => $key, 'path' => array_map(fn (string $p): int => $this->fieldId(...explode('.', $p)), $path), 'field' => 0, 'system' => $name, 'bucket' => $bucket, 'label' => null];
    }
}
