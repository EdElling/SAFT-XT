<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\Rules\Operand;
use App\DynamicData\Rules\RuleEvaluator;
use App\DynamicData\Rules\TableRule;
use App\DynamicData\Rules\TableRuleValidator;
use App\DynamicData\Ui\DynamicFormBuilder;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\Exceptions\ErrorPresenter;
use App\ExternalData\CheckConstraintInfo;
use App\ExternalData\PostgresCheckTranslator;
use App\ExternalData\SchemaComparison;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use Database\Seeders\ExternalResearchDatabase;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** CHECK-Regeln angebundener Tabellen: Wertelisten, Min./Max., Vergleiche, Abgleich und Meldungen. */
class CheckConstraintTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    private DatabaseConnection $connection;

    private ExternalResearchDatabase $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->fixture = ExternalResearchDatabase::on($this->externalDatabaseName());
        $this->fixture->reset();
        $this->connection = $this->researchConnection(['is_read_only' => false]);
    }

    protected function tearDown(): void
    {
        // Ein Test ändert Regeln der Testdatenbank.
        $this->fixture->reset();
        parent::tearDown();
    }

    private function service(): TableConnectorService
    {
        return app(TableConnectorService::class);
    }

    private function external(): Connection
    {
        return $this->fixture->connection();
    }

    private function connect(string $table): CustomTable
    {
        $connected = $this->service()->connect($this->connection, ExternalResearchDatabase::SCHEMA, $table, ['name' => $table]);

        return $this->service()->update($connected, ['allow_create' => true, 'allow_update' => true, 'allow_delete' => true])->fresh('fields');
    }

    public function test_postgres_forms_are_translated_conservatively(): void
    {
        $translator = new PostgresCheckTranslator;
        $types = ['status' => 'string', 'jahr' => 'integer', 'budget' => 'decimal', 'beginn' => 'date', 'ende' => 'date', 'webseite' => 'string', 'preis' => 'decimal'];
        $translate = fn (string $clause) => $translator->translate(new CheckConstraintInfo('regel', $clause, []), $types);

        $this->assertSame(['geplant', "o'k"], $translate("(((status)::text = ANY ((ARRAY['geplant'::character varying, 'o''k'::character varying])::text[])))")->values);
        $this->assertSame([1, 2], $translate('((jahr = ANY (ARRAY[1, 2])))')->values);
        $this->assertSame(['a'], $translate("(((status)::text = ANY ((ARRAY['a'::character varying])::text[]))) NOT VALID")->values);

        $range = $translate('(((jahr >= 1900) AND (jahr <= 2100)))');
        $this->assertSame([1900, 2100], [$range->min, $range->max]);
        $this->assertSame(1900, $translate('((jahr > 1899))')->min);
        $this->assertSame(0, $translate('((budget >= (0)::numeric))')->min);

        $rule = $translate('(((ende IS NULL) OR (ende >= beginn)))')->rule;
        $this->assertSame(['ende', '>=', 'beginn', 'regel'], [$rule->left->fieldName(), $rule->operator, $rule->right->fieldName(), $rule->constraint]);

        // Nicht übersetzbar: Muster, Funktionen, gemischte Spalten, echte Ungleichung bei Dezimalzahlen, unbekannte Spalten.
        foreach ([
            "(((webseite)::text ~ '^https?://'::text))",
            '((length((status)::text) > 2))',
            '(((jahr >= 1900) AND (budget >= (0)::numeric)))',
            '((preis > (0)::numeric))',
            '((unbekannt >= 1))',
            '(((ende IS NULL) OR (ende >= beginn) OR (jahr > 1)))',
        ] as $clause) {
            $this->assertNull($translate($clause), $clause);
        }
    }

    public function test_evaluator_compares_numbers_dates_and_skips_empty_values(): void
    {
        $rule = new TableRule(Operand::field('ende'), '>=', Operand::field('beginn'));
        $values = fn (array $row): \Closure => fn (string $name): mixed => $row[$name] ?? null;
        $evaluator = new RuleEvaluator;

        $this->assertTrue($evaluator->passes($rule, $values(['beginn' => '2026-01-01', 'ende' => '2026-01-01'])));
        $this->assertFalse($evaluator->passes($rule, $values(['beginn' => '2026-02-01', 'ende' => '2026-01-31'])));
        $this->assertNull($evaluator->passes($rule, $values(['beginn' => '2026-02-01'])));
        $this->assertTrue(RuleEvaluator::compare('10', '>', '9'));
        $this->assertSame(['left' => ['kind' => 'field', 'field' => 'ende'], 'operator' => '>=', 'right' => ['kind' => 'field', 'field' => 'beginn'], 'when_filled' => true, 'source' => 'designer'], $rule->toArray());
    }

    public function test_connecting_takes_over_value_lists_ranges_and_comparisons(): void
    {
        $projects = $this->connect('projekte');
        $fields = $projects->fields->keyBy('name');

        $this->assertSame(['geplant', 'laufend', 'abgeschlossen', 'abgebrochen'], $fields['status']->optionsArray()[TableConnectorService::DB_VALUES]);
        $this->assertSame(['min' => 0], $fields['budget']->optionsArray()[TableConnectorService::DB_RULES]);
        $this->assertSame([['ende', '>=', 'beginn', 'projekte_zeitraum_check']], array_map(
            fn (TableRule $r): array => [$r->left->fieldName(), $r->operator, $r->right->fieldName(), $r->constraint],
            $projects->tableRules(),
        ));
        $this->assertSame(['projekte_budget_check' => true, 'projekte_status_check' => true, 'projekte_zeitraum_check' => true], collect($projects->check_constraints)->pluck('translated', 'name')->all());

        $organisations = $this->connect('organisationen');
        $this->assertFalse(collect($organisations->check_constraints)->firstWhere('name', 'organisationen_webseite_check')['translated']);
        $this->assertSame(['webseite'], collect($organisations->check_constraints)->firstWhere('name', 'organisationen_webseite_check')['columns']);

        // Formular: Auswahl statt Freitext; Liste: Auswahlfilter.
        $status = collect(app(DynamicFormBuilder::class)->components($projects))->first(fn ($c): bool => $c->getName() === 'status');
        $this->assertInstanceOf(Select::class, $status);
        $this->assertInstanceOf(SelectFilter::class, collect(app(DynamicTableBuilder::class)->filters($projects))->first(fn ($f): bool => $f->getName() === 'status'));

        $this->assertTrue(app(SchemaComparison::class)->compare($projects->fresh())->isEmpty());
    }

    public function test_form_checks_rules_before_the_database_does(): void
    {
        $this->connect('personen');
        $this->connect('projekte');
        $this->actingAs($this->superAdmin());
        $base = ['kurzname' => 'P-99', 'titel' => 'Neues Projekt', 'beginn' => '2026-11-10', 'projektleiter_id' => 3];

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callTableAction('create', data: [...$base, 'status' => 'bewilligt'])
            ->assertHasTableActionErrors(['status']);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callTableAction('create', data: [...$base, 'status' => 'geplant', 'ende' => '2026-11-01', 'budget' => -5])
            ->assertHasTableActionErrors(['ende', 'budget']);

        // Meldung beim Feld „Ende“, typgerecht für Datumswerte.
        $projects = CustomTable::query()->with('fields')->where('name', 'projekte')->sole();
        $this->assertSame('darf nicht vor „Beginn“ liegen.', TableRuleValidator::message($projects->tableRules()[0], $projects->fields->keyBy('name')->all()));
        $this->assertFalse($this->external()->table('forschung.projekte')->where('kurzname', 'P-99')->exists());

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callTableAction('create', data: [...$base, 'status' => 'laufend', 'ende' => '2027-11-30', 'budget' => 5000])
            ->assertHasNoTableActionErrors();
        $this->assertSame('laufend', $this->external()->table('forschung.projekte')->where('kurzname', 'P-99')->value('status'));
    }

    public function test_violated_database_only_rule_names_field_and_rule(): void
    {
        $organisations = $this->connect('organisationen');

        try {
            DynamicRecord::forTable($organisations)->create(['name' => 'Verein FTP', 'land' => 'CH', 'typ' => 'unternehmen', 'webseite' => 'ftp://example.org']);
            $this->fail('Regel nicht verletzt.');
        } catch (QueryException $e) {
            $message = app(ErrorPresenter::class)->present($e)->message;
        }

        $this->assertSame('Ein Wert in „Webseite“ verletzt die Datenbankregel „organisationen_webseite_check“.', $message);
    }

    public function test_changed_rules_are_reported_and_applied(): void
    {
        $projects = $this->connect('projekte');
        // Eigene Regel (Table-Designer-Herkunft) bleibt beim Einlesen erhalten.
        $own = (new TableRule(Operand::field('budget'), '<=', Operand::value(1000000)))->toArray();
        $projects->forceFill(['validation_rules' => [...$projects->validation_rules, $own]])->save();

        $this->external()->statement('alter table forschung.projekte drop constraint projekte_budget_check');
        $this->external()->statement("alter table forschung.projekte drop constraint projekte_status_check, add constraint projekte_status_check check (status in ('geplant', 'laufend')) not valid");

        $difference = app(SchemaComparison::class)->compare($projects->fresh());
        $this->assertEqualsCanonicalizing([['name' => 'projekte_status_check', 'state' => 'changed'], ['name' => 'projekte_budget_check', 'state' => 'removed']], $difference->checks);
        $this->artisan('table-connector:verify', ['table' => ['projekte']])->assertFailed();

        $this->artisan('table-connector:verify', ['table' => ['projekte'], '--apply' => true])->assertSuccessful();

        $projects = $projects->fresh('fields');
        $this->assertSame(['geplant', 'laufend'], $projects->fields->firstWhere('name', 'status')->optionsArray()[TableConnectorService::DB_VALUES]);
        $this->assertArrayNotHasKey(TableConnectorService::DB_RULES, $projects->fields->firstWhere('name', 'budget')->optionsArray());
        // Inhaltlich vergleichen: MySQL speichert JSON-Objekte mit sortierten Schlüsseln.
        $this->assertTrue(collect($projects->validation_rules)->contains(static fn (array $rule): bool => $rule == $own));
        $this->assertTrue(app(SchemaComparison::class)->compare($projects)->isEmpty());
    }

    public function test_tables_connected_before_this_feature_are_reported_until_re_read(): void
    {
        $projects = $this->connect('projekte');
        $projects->forceFill(['check_constraints' => null, 'validation_rules' => null])->save();

        $this->assertCount(3, app(SchemaComparison::class)->compare($projects->fresh())->checks);
        $this->service()->refresh($projects->fresh());
        $this->assertCount(1, $projects->fresh()->tableRules());
    }
}
