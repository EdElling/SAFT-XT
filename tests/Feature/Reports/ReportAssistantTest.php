<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Filament\Resources\Reports\Pages\ReportDesigner;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Reports\Assistant\AssistantClient;
use App\Reports\Assistant\AssistantReply;
use App\Reports\Assistant\ProposalSchema;
use App\Reports\Assistant\ReportAssistant;
use App\Reports\Editor\DesignerState;
use App\Reports\Editor\FieldCatalog;
use App\Reports\ReportRunner;
use App\Services\DynamicSchemaService;
use App\Settings\AppSettings;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fakes\FakeAssistantClient;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** KI-Assistent im Auswertungs-Editor (mit Modell-Attrappe, ohne Netz). */
class ReportAssistantTest extends DynamicDataTestCase
{
    private User $admin;

    private FakeAssistantClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'select', 'options' => ['options' => [['value' => 'ber', 'label' => 'Berlin'], ['value' => 'ham', 'label' => 'Hamburg']]]],
        ]);
        $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
        ]);
        $acme = DynamicRecord::forTableName('kunden')->create(['name' => 'ACME Geheim AG', 'ort' => 'ber']);
        DynamicRecord::forTableName('bestellungen')->create(['nummer' => 'B-4711', 'kunde_id' => $acme->id, 'datum' => '2026-02-20', 'betrag' => '50.50']);

        config(['ai.provider' => 'anthropic', 'ai.daily_limit' => 50]);
        $settings = app(AppSettings::class);
        $settings->ai_assistant_enabled = true;
        $settings->ai_send_choices = true;
        $settings->save();

        $this->fake = new FakeAssistantClient;
        $this->app->instance(AssistantClient::class, $this->fake);
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

    /** @param  list<string>  $path */
    private function ref(string $field, array $path = []): string
    {
        return FieldCatalog::ref(array_map($this->fieldIdOf(...), $path), $this->fieldIdOf($field));
    }

    /** @return array<string, mixed> */
    private function proposal(array $overrides = []): array
    {
        return [...[
            'kind' => 'report',
            'question' => null,
            'title' => 'Umsatz je Monat 2026',
            'base' => $this->table('bestellungen'),
            'rows' => [['ref' => $this->ref('bestellungen.datum'), 'bucket' => 'month']],
            'columns' => [],
            'values' => [['agg' => 'sum', 'ref' => $this->ref('bestellungen.betrag')]],
            'filters' => [['ref' => $this->ref('bestellungen.datum'), 'op' => 'between', 'value' => '2026-01-01', 'value_to' => '2026-12-31', 'values' => []]],
            'sort' => null,
            'limit' => null,
            'visual' => 'bar',
        ], ...$overrides];
    }

    public function test_valid_proposal_fills_the_designer_and_is_logged(): void
    {
        $this->fake->push($this->proposal());

        Livewire::test(ReportDesigner::class)
            ->assertSeeHtml('data-report-assistant')
            ->assertSee('Heute noch 50 Anfragen.')
            ->set('assistantPrompt', 'Umsatz pro Monat für 2026 als Balken')
            ->call('askAssistant')
            ->assertSet('state.base', $this->table('bestellungen'))
            ->assertSet('state.visual', 'bar')
            ->assertSet('state.values.0.agg', 'sum')
            ->assertSet('state.rows.0.bucket', 'month')
            ->assertSet('meta.name', 'Umsatz je Monat 2026')
            ->assertSet('assistantReplyKind', 'report')
            ->assertSee('Summe Betrag je Monat (Datum)')
            ->assertSee('Vorschlag übernommen');

        // System: Anweisungen + Datenmodell (zwischengespeichert); Anfrage mit Text, Datum und aktuellem Stand.
        $request = $this->fake->requests[0];
        $this->assertTrue($request['system'][1]['cache']);
        $this->assertStringContainsString('"label":"Bestellungen"', $request['system'][1]['text']);
        $this->assertStringContainsString('Request: Umsatz pro Monat für 2026 als Balken', $request['messages'][0]['content']);
        $this->assertSame(ProposalSchema::schema(), $request['schema']);

        $log = Activity::query()->where('event', ReportAssistant::EVENT)->sole();
        $this->assertSame($this->admin->id, $log->causer_id);
        // assertEquals: MySQL speichert JSON mit sortierten Schlüsseln.
        $this->assertEquals(['anfrage' => 'Umsatz pro Monat für 2026 als Balken', 'ergebnis' => 'report', 'modell' => 'fake-model', 'tokens_ein' => 2000, 'tokens_aus' => 150, 'tokens_cache' => 800], $log->attribute_changes['attributes']);
    }

    public function test_only_the_data_model_of_the_person_is_sent_never_records(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'reports.create', 'reports.assistant', 'data.bestellungen.view']);
        $this->actingAs($user);
        $this->fake->push($this->proposal());
        app(ReportAssistant::class)->propose($user, 'Bestellungen je Monat', []);

        $sent = $this->fake->sentText();
        $this->assertStringContainsString('Bestellungen', $sent);
        $this->assertStringNotContainsString('"label":"Kunden"', $sent, 'Ohne Leserecht keine Tabelle.');
        $this->assertStringNotContainsString('Berlin', $sent);
        foreach (['ACME Geheim AG', 'B-4711', '50.50', '2026-02-20'] as $record) {
            $this->assertStringNotContainsString($record, $sent, 'Keine Datensätze.');
        }

        // Mit Leserecht auf Kunden: Auswahlwerte nur, solange sie übertragen werden dürfen.
        $this->fake->push($this->proposal());
        app(ReportAssistant::class)->propose($this->admin, 'x', []);
        $this->assertStringContainsString('"choices":[{"value":"ber","label":"Berlin"}', $this->fake->requests[1]['system'][1]['text']);
        // Verknüpfungsfelder nennen, was sie anzeigen (Anzeigefelder des verknüpften Datensatzes).
        $this->assertStringContainsString('"label":"Kunde","type":"relation","to":'.$this->table('kunden').',"shows":["Name"]', $this->fake->requests[1]['system'][1]['text']);
        $settings = app(AppSettings::class);
        $settings->ai_send_choices = false;
        $settings->save();
        $this->fake->push($this->proposal());
        app(ReportAssistant::class)->propose($this->admin, 'x', []);
        $this->assertStringNotContainsString('Berlin', $this->fake->requests[2]['system'][1]['text']);
    }

    public function test_invalid_proposal_gets_one_correction_round(): void
    {
        $this->fake->push($this->proposal(['values' => [['agg' => 'sum', 'ref' => ':999999']]]))
            ->push($this->proposal());

        $result = app(ReportAssistant::class)->propose($this->admin, 'Umsatz', []);

        $this->assertSame('report', $result->status);
        $this->assertCount(2, $this->fake->requests);
        $this->assertStringContainsString('Feld :999999 gibt es nicht', end($this->fake->requests[1]['messages'])['content']);

        // Zweimal ungültig: Meldung, kein Vorschlag.
        $this->fake->push($this->proposal(['visual' => 'pie', 'rows' => []]))->push($this->proposal(['visual' => 'pie', 'rows' => []]));
        Livewire::test(ReportDesigner::class)
            ->set('assistantPrompt', 'Torte')
            ->call('askAssistant')
            ->assertSet('state.base', null)
            ->assertSet('assistantReplyKind', 'error')
            ->assertSee('Kein gültiger Vorschlag');
    }

    public function test_formulas_become_calculated_values_and_columns(): void
    {
        $betrag = $this->ref('bestellungen.betrag');
        $formula = static fn (?string $text, ?string $label = null, ?int $scale = null): array => ['formula' => $text, 'label' => $label, 'scale' => $scale];

        // Berechneter Wert; Text statt Zahl und unlesbare Formel gehen zur Korrektur zurück.
        $this->fake
            ->push($this->proposal(['values' => [['agg' => 'sum', 'ref' => null, ...$formula('['.$this->ref('bestellungen.nummer').'] * 2', 'X', 2)], ['agg' => 'sum', 'ref' => null, ...$formula('['.$betrag.'] * (', 'Y', 2)]]]))
            ->push($this->proposal(['values' => [['agg' => 'sum', 'ref' => null, ...$formula('['.$betrag.'] × 1.19', 'Brutto', 2)]]]));

        Livewire::test(ReportDesigner::class)
            ->set('assistantPrompt', 'Bruttoumsatz je Monat')
            ->call('askAssistant')
            ->assertSet('assistantReplyKind', 'report')
            ->assertSet('state.values.0.expr', ['op' => '*', 'left' => ['field' => $betrag], 'right' => ['num' => '1.19']])
            ->assertSet('state.values.0.label', 'Brutto')
            ->assertSee('Brutto')
            ->assertSeeHtml('[60.095]');

        $correction = end($this->fake->requests[1]['messages'])['content'];
        $this->assertStringContainsString('„Nummer“ ist keine Zahl', $correction);
        $this->assertStringContainsString('Formel nicht lesbar', $correction);

        // Berechnete Spalte nur in Listen; die aktuelle Formel geht als Text mit Referenzen an das Modell.
        $list = ['rows' => [['ref' => $this->ref('bestellungen.nummer'), 'bucket' => null, ...$formula(null)], ['ref' => '', 'bucket' => null, ...$formula('round(['.$betrag.'] / 3; 1)', 'Drittel', 1)]], 'values' => [], 'filters' => [], 'visual' => 'table'];
        $this->fake->push($this->proposal([...$list, 'values' => [['agg' => 'count', 'ref' => null, ...$formula(null)]]]))->push($this->proposal($list));

        $result = app(ReportAssistant::class)->propose($this->admin, 'Liste mit Drittel', []);
        $this->assertSame('report', $result->status);
        $this->assertStringContainsString('nur in Listen', end($this->fake->requests[3]['messages'])['content']);
        $this->assertSame(['Drittel', 1], [$result->state['rows'][1]['label'], $result->state['rows'][1]['scale']]);

        $this->fake->push($this->proposal($list));
        app(ReportAssistant::class)->propose($this->admin, 'sortiere absteigend', $result->state);
        $this->assertStringContainsString('"formula":"round(['.$betrag.'] ÷ 3; 1)"', end($this->fake->requests[4]['messages'])['content']);
    }

    public function test_figures_from_values_refer_to_values_and_grouped_number_columns(): void
    {
        $betrag = $this->ref('bestellungen.betrag');
        $calc = static fn (string $formula, string $label): array => ['agg' => 'calc', 'ref' => null, 'formula' => $formula, 'label' => $label, 'scale' => 2];
        $plain = static fn (string $agg, ?string $ref): array => ['agg' => $agg, 'ref' => $ref, 'formula' => null, 'label' => null, 'scale' => null];
        $rows = [['ref' => $this->ref('bestellungen.kunde_id'), 'bucket' => null, 'formula' => null, 'label' => null, 'scale' => null], ['ref' => $betrag, 'bucket' => null, 'formula' => null, 'label' => null, 'scale' => null]];
        $values = [$plain('sum', $betrag), $plain('count', null)];

        // Verweis auf eine andere Kennzahl geht zur Korrektur zurück.
        $this->fake
            ->push($this->proposal(['rows' => $rows, 'values' => [...$values, $calc('[#1] / [#2]', 'Je Bestellung'), $calc('[#3] * 2', 'Doppelt')], 'filters' => [], 'visual' => 'table']))
            ->push($this->proposal(['rows' => $rows, 'values' => [...$values, $calc('[#1] / [#2]', 'Je Bestellung'), $calc('['.$betrag.'] * [#2]', 'Betrag mal Anzahl')], 'filters' => [], 'visual' => 'table']));

        $result = app(ReportAssistant::class)->propose($this->admin, 'Betrag je Bestellung', []);

        $this->assertSame('report', $result->status);
        $this->assertCount(2, $this->fake->requests);
        $this->assertStringContainsString('Unbekanntes Feld', end($this->fake->requests[1]['messages'])['content']);
        $this->assertSame(['op' => '/', 'left' => ['field' => 'm1'], 'right' => ['field' => 'm2']], $result->state['values'][2]['calc']);
        $this->assertSame(['op' => '*', 'left' => ['field' => '@'.$betrag], 'right' => ['field' => 'm2']], $result->state['values'][3]['calc']);

        [$definition] = DesignerState::fromArray($result->state)->toDefinition();
        $rowsOut = app(ReportRunner::class)->run($definition, $this->admin)->rows;
        $this->assertEqualsWithDelta(50.5, (float) $rowsOut[0]['m3'], 0.001);

        // Folgeanfrage: Die Kennzahl geht mit Positionen und Feldreferenz an das Modell.
        $this->fake->push($this->proposal(['rows' => $rows, 'values' => $values, 'filters' => [], 'visual' => 'table']));
        app(ReportAssistant::class)->propose($this->admin, 'sortiere', $result->state);
        $last = end($this->fake->requests[2]['messages'])['content'];
        $this->assertStringContainsString('"formula":"[#1] ÷ [#2]"', $last);
        $this->assertStringContainsString('"formula":"['.$betrag.'] × [#2]"', $last);
    }

    public function test_question_and_follow_up_keep_the_conversation(): void
    {
        $this->fake->push([...$this->proposal(), 'kind' => 'question', 'question' => 'Meinen Sie mit Umsatz den Betrag der Bestellungen?', 'base' => null, 'rows' => [], 'values' => [], 'filters' => [], 'visual' => 'table'])
            ->push($this->proposal())
            ->push($this->proposal(['visual' => 'line']));

        $designer = Livewire::test(ReportDesigner::class)
            ->set('assistantPrompt', 'Umsatz')
            ->call('askAssistant')
            ->assertSet('assistantReplyKind', 'question')
            ->assertSee('Meinen Sie mit Umsatz den Betrag der Bestellungen?')
            ->assertSet('state.base', null)
            ->set('assistantPrompt', 'Ja, je Monat')
            ->call('askAssistant')
            ->assertSet('state.visual', 'bar')
            ->set('assistantPrompt', 'als Linie')
            ->call('askAssistant')
            ->assertSet('state.visual', 'line');

        // Die dritte Anfrage kennt den bisherigen Verlauf und den aktuellen Stand.
        $messages = $this->fake->requests[2]['messages'];
        $this->assertCount(5, $messages);
        $this->assertStringContainsString('"visual":"bar"', end($messages)['content']);

        $designer->call('resetAssistant')->assertSet('assistantHistory', []);
    }

    public function test_name_follows_the_assistant_until_edited_by_hand(): void
    {
        $this->fake->push($this->proposal(['title' => 'Umsatz je Monat']))
            ->push($this->proposal(['title' => 'Umsatz je Monat als Linie', 'visual' => 'line']))
            ->push($this->proposal(['title' => 'Bestellungen je Kunde']))
            ->push($this->proposal(['title' => 'Etwas anderes']));

        $designer = Livewire::test(ReportDesigner::class)
            ->set('assistantPrompt', 'Umsatz je Monat')->call('askAssistant')
            ->assertSet('meta.name', 'Umsatz je Monat')
            // Anpassung im Gespräch: neuer Name, solange niemand ihn geändert hat.
            ->set('assistantPrompt', 'als Linie')->call('askAssistant')
            ->assertSet('meta.name', 'Umsatz je Monat als Linie')
            // Neu beginnen leert den Namen, der nächste Vorschlag setzt ihn neu.
            ->call('resetAssistant')
            ->assertSet('meta.name', null)
            ->set('assistantPrompt', 'Bestellungen je Kunde')->call('askAssistant')
            ->assertSet('meta.name', 'Bestellungen je Kunde')
            // Von Hand geändert: bleibt, auch nach Neu beginnen.
            ->set('meta.name', 'Mein Bericht')
            ->call('resetAssistant')
            ->assertSet('meta.name', 'Mein Bericht')
            ->set('assistantPrompt', 'etwas anderes')->call('askAssistant')
            ->assertSet('meta.name', 'Mein Bericht');
    }

    public function test_refusal_limits_and_availability(): void
    {
        $this->fake->push(new AssistantReply(null, 'refusal'));
        Livewire::test(ReportDesigner::class)->set('assistantPrompt', 'x')->call('askAssistant')
            ->assertSet('assistantReplyKind', 'error')
            ->assertSee('hat diese Anfrage nicht bearbeitet');

        config(['ai.daily_limit' => 1]);
        Livewire::test(ReportDesigner::class)->set('assistantPrompt', 'x')->call('askAssistant')
            ->assertNotified('Das Tageslimit von 1 Anfragen ist erreicht. Morgen geht es weiter.');
        $this->assertCount(1, $this->fake->requests);

        // Ohne Recht, abgeschaltet oder ohne Anbieter: unsichtbar.
        $this->actingAs($this->userWithPermissions(['reports.view', 'reports.create']));
        Livewire::test(ReportDesigner::class)->assertDontSeeHtml('data-report-assistant')->call('askAssistant')->assertForbidden();

        $this->actingAs($this->admin);
        config(['ai.provider' => 'none']);
        Livewire::test(ReportDesigner::class)->assertDontSeeHtml('data-report-assistant');
        config(['ai.provider' => 'anthropic']);
        $settings = app(AppSettings::class);
        $settings->ai_assistant_enabled = false;
        $settings->save();
        Livewire::test(ReportDesigner::class)->assertDontSeeHtml('data-report-assistant');
    }

    public function test_schema_is_strict(): void
    {
        $check = function (array $node) use (&$check): void {
            if (($node['type'] ?? null) === 'object') {
                $this->assertFalse($node['additionalProperties']);
                $this->assertSame(array_keys($node['properties']), $node['required']);
            }

            foreach (['properties', 'items', 'anyOf'] as $key) {
                foreach ((array) ($node[$key] ?? []) as $child) {
                    if (is_array($child)) {
                        $check($child);
                    }
                }
            }
        };
        $check(ProposalSchema::schema());
        $this->assertContains('last_days', ProposalSchema::operators());
        $this->assertContains('is_true', ProposalSchema::operators());
    }
}
