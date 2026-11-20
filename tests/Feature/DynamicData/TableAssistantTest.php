<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Ai\AiUsage;
use App\DynamicData\Assistant\TableAssistant;
use App\DynamicData\Rules\TableRuleNormalizer;
use App\DynamicData\Schema\FieldType;
use App\Filament\Resources\CustomTables\Pages\CreateCustomTable;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\Schemas\CustomTableForm;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\User;
use App\Reports\Assistant\AssistantClient;
use App\Settings\AppSettings;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Fakes\FakeAssistantClient;

/** KI-Assistent im Table-Designer (mit Modell-Attrappe, ohne Netz). */
class TableAssistantTest extends DynamicDataTestCase
{
    private User $admin;

    private FakeAssistantClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        $this->service()->createTable(['name' => 'vereine', 'label' => ['de' => 'Vereine']], [
            ['name' => 'name', 'label' => ['de' => 'Name'], 'type' => 'string'],
            ['name' => 'ort', 'label' => ['de' => 'Ort'], 'type' => 'string'],
        ]);
        DynamicRecord::forTableName('vereine')->create(['name' => 'Geheimverein Nord', 'ort' => 'Bern']);

        config(['ai.provider' => 'anthropic', 'ai.daily_limit' => 50]);
        $settings = app(AppSettings::class);
        $settings->ai_assistant_enabled = true;
        $settings->save();

        $this->fake = new FakeAssistantClient;
        $this->app->instance(AssistantClient::class, $this->fake);
    }

    /** @return array<string, string> */
    private function t(string $de, ?string $en = null): array
    {
        return ['de' => $de, 'en' => $en ?? $de, 'fr' => $en ?? $de, 'it' => $en ?? $de];
    }

    /** @return array<string, mixed> */
    private function field(string $name, string $type, array $overrides = []): array
    {
        return [...[
            'name' => $name, 'label' => $this->t(ucfirst($name)), 'type' => $type,
            'required' => false, 'unique' => false, 'searchable' => false, 'sortable' => false,
            'length' => null, 'precision' => null, 'scale' => null, 'choices' => [], 'relation_table' => null, 'relation_display' => [],
            'file_kinds' => [], 'max_mb' => null, 'max_files' => null,
        ], ...$overrides];
    }

    /** @return array<string, mixed> */
    private function members(array $overrides = []): array
    {
        return [...[
            'kind' => 'table', 'question' => null, 'name' => 'mitglieder', 'label' => $this->t('Mitglieder', 'Members'), 'description' => null, 'menu_group' => null,
            'fields' => [
                $this->field('vorname', 'string', ['searchable' => true]),
                $this->field('nachname', 'string', ['required' => true, 'searchable' => true]),
                $this->field('eintritt', 'date', ['label' => $this->t('Eintrittsdatum', 'Entry date'), 'sortable' => true]),
                $this->field('kategorie', 'select', ['choices' => [['value' => 'aktiv', 'label' => $this->t('Aktiv', 'Active')], ['value' => 'passiv', 'label' => $this->t('Passiv', 'Passive')]]]),
                $this->field('verein_id', 'relation', ['label' => $this->t('Verein', 'Club'), 'relation_table' => 'vereine', 'relation_display' => ['name']]),
            ],
        ], ...$overrides];
    }

    public function test_new_table_fills_the_form_and_is_created_only_on_create(): void
    {
        $this->fake->push($this->members());

        $page = Livewire::test(CreateCustomTable::class)
            ->assertSeeHtml('data-table-assistant')
            ->set('assistantPrompt', 'Mitglieder eines Vereins mit Eintrittsdatum und Kategorie')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'proposal')
            ->assertFormSet(['name' => 'mitglieder', 'label.de' => 'Mitglieder', 'label.en' => 'Members']);

        $this->assertNull(CustomTable::query()->where('name', 'mitglieder')->first(), 'Noch nichts angelegt.');
        $fields = array_values($page->get('data.fields'));
        $this->assertSame(['vorname', 'nachname', 'eintritt', 'kategorie', 'verein_id'], array_column($fields, 'name'));
        $this->assertSame(['table' => 'vereine', 'display_fields' => ['name'], 'separator' => ' '], array_intersect_key($fields[4]['options'], array_flip(['table', 'display_fields', 'separator'])));

        $page->call('create')->assertHasNoFormErrors();
        $table = CustomTable::query()->where('name', 'mitglieder')->sole();
        $this->assertSame('Entry date', $table->fields()->where('name', 'eintritt')->sole()->getTranslation('label', 'en'));
        $this->assertSame(FieldType::Relation, $table->fields()->where('name', 'verein_id')->sole()->type);
        $this->assertTrue($table->fields()->where('name', 'nachname')->sole()->is_required);

        // Gesendet: Struktur der vorhandenen Tabellen, keine Datensätze; protokolliert wie der Auswertungs-Assistent.
        $sent = $this->fake->sentText();
        $this->assertStringContainsString('vereine', $sent);
        $this->assertStringNotContainsString('Geheimverein', $sent);
        $this->assertStringNotContainsString('Bern', $sent);
        $log = Activity::query()->where('event', AiUsage::EVENT)->sole();
        $this->assertSame(['tables', 'proposal'], [$log->log_name, $log->attribute_changes['attributes']['ergebnis']]);
    }

    public function test_invalid_proposal_gets_a_correction_and_follow_ups_see_the_form(): void
    {
        // Name schon vergeben → Korrektur.
        $this->fake->push($this->members(['name' => 'vereine']))->push($this->members());
        $page = Livewire::test(CreateCustomTable::class)
            ->set('assistantPrompt', 'Mitglieder')
            ->call('askTableAssistant')
            ->assertFormSet(['name' => 'mitglieder']);
        $this->assertStringContainsString('„vereine“ ist schon vergeben', end($this->fake->requests[1]['messages'])['content']);

        // Nachfrage: der aktuelle Formularstand geht mit (auch von Hand geänderte Bezeichnung).
        $this->fake->push($this->members(['fields' => [...$this->members()['fields'], $this->field('bemerkungen', 'text')]]));
        $page->fillForm(['label.de' => 'Vereinsmitglieder'])
            ->set('assistantPrompt', 'zusätzlich ein Feld Bemerkungen')
            ->call('askTableAssistant');
        $request = end($this->fake->requests[2]['messages'])['content'];
        $this->assertStringContainsString('Vereinsmitglieder', $request);
        $this->assertCount(5, $this->fake->requests[2]['messages'], 'Verlauf mit Korrekturrunde.');
        $this->assertContains('bemerkungen', array_column(array_values($page->get('data.fields')), 'name'));

        // Unbekannte Zieltabelle zweimal → Meldung, Formular unverändert.
        $bad = $this->members(['fields' => [$this->field('x_id', 'relation', ['relation_table' => 'gibtsnicht', 'relation_display' => ['name']])]]);
        $this->fake->push($bad)->push($bad);
        $page->set('assistantPrompt', 'neu')->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'error')
            ->assertSee('Kein gültiger Vorschlag');
    }

    public function test_existing_table_changes_with_preview_and_partial_failure(): void
    {
        $this->service()->createTable(['name' => 'mitglieder', 'label' => ['de' => 'Mitglieder']], [
            ['name' => 'email', 'label' => ['de' => 'E-Mail'], 'type' => 'string'],
            ['name' => 'kategorie', 'label' => ['de' => 'Kategorie'], 'type' => 'select', 'options' => ['options' => [['value' => 'aktiv', 'label' => ['de' => 'Aktiv']], ['value' => 'passiv', 'label' => ['de' => 'Passiv']]]]],
            ['name' => 'code', 'label' => ['de' => 'Code'], 'type' => 'string'],
        ]);
        DynamicRecord::forTableName('mitglieder')->create(['email' => 'a@example.org', 'code' => 'X']);
        DynamicRecord::forTableName('mitglieder')->create(['email' => 'b@example.org', 'code' => 'X']);
        $table = CustomTable::query()->where('name', 'mitglieder')->sole();

        $change = fn (string $name, array $overrides): array => [...['name' => $name, 'label' => null, 'required' => null, 'unique' => null, 'searchable' => null, 'sortable' => null, 'add_choices' => [], 'relation_display' => null], ...$overrides];
        $this->fake->push([
            'kind' => 'changes', 'question' => null,
            'add' => [$this->field('eintritt', 'date', ['label' => $this->t('Eintrittsdatum')]), $this->field('verein_id', 'relation', ['relation_table' => 'vereine', 'relation_display' => ['name', 'ort']])],
            'change' => [
                $change('email', ['unique' => true, 'required' => true]),
                $change('kategorie', ['add_choices' => [['value' => 'junior', 'label' => $this->t('Junior')]]]),
                // Doppelte Werte: „eindeutig“ scheitert beim Übernehmen, der Rest gilt.
                $change('code', ['unique' => true]),
            ],
        ]);

        Livewire::test(EditCustomTable::class, ['record' => $table->id])
            ->callAction('aiAdjust', ['prompt' => 'Eintrittsdatum, Verein, E-Mail eindeutig, Kategorie Junior, Code eindeutig'])
            ->assertActionMounted('aiAdjust')
            ->assertSet('aiMessages.1.role', 'assistant')
            ->assertSet('aiSummary', [
                'Neu: Eintrittsdatum (Datum)',
                'Neu: Verein_id (Verknüpfung → vereine)',
                'Ändern: E-Mail – Pflicht an, eindeutig an',
                'Ändern: Kategorie – 1 weitere Auswahloptionen',
                'Ändern: Code – eindeutig an',
            ])
            ->callAction(TestAction::make('aiApply'))
            ->assertNotified('Nicht alles übernommen:');

        $table->refresh();
        $this->assertNotNull($table->fields()->where('name', 'eintritt')->first());
        $this->assertSame(['name', 'ort'], $table->fields()->where('name', 'verein_id')->sole()->optionsArray()['display_fields']);
        $email = $table->fields()->where('name', 'email')->sole();
        $this->assertTrue($email->is_unique && $email->is_required);
        $this->assertSame(['aktiv', 'passiv', 'junior'], array_column($table->fields()->where('name', 'kategorie')->sole()->optionsArray()['options'], 'value'));
        $this->assertFalse($table->fields()->where('name', 'code')->sole()->is_unique);
    }

    public function test_removing_a_field_from_the_list_needs_a_confirmation(): void
    {
        $this->fake->push($this->members());
        $page = Livewire::test(CreateCustomTable::class)->set('assistantPrompt', 'Mitglieder')->call('askTableAssistant');
        $key = array_key_first($page->get('data.fields'));

        $page->mountAction(TestAction::make('delete')->schemaComponent('fields', 'form')->arguments(['item' => $key]))
            ->assertActionMounted(TestAction::make('delete')->schemaComponent('fields', 'form')->arguments(['item' => $key]));
        $this->assertCount(5, $page->get('data.fields'), 'Noch nichts entfernt.');

        $page->callMountedAction();
        $this->assertCount(4, $page->get('data.fields'));
    }

    public function test_field_list_headers_summarise_type_and_flags(): void
    {
        $this->assertSame('Eintrittsdatum · Datum · Pflicht, sortierbar', CustomTableForm::fieldSummary([
            'label' => ['de' => 'Eintrittsdatum'], 'type' => 'date', 'is_required' => true, 'is_sortable' => true,
        ]));
        $this->assertSame('Name · Text (einzeilig)', CustomTableForm::fieldSummary(['label' => ['de' => 'Name'], 'type' => 'string']));
        $this->assertNull(CustomTableForm::fieldSummary(['type' => 'string']), 'Ohne Bezeichnung (neues leeres Feld): Standardkopf.');
    }

    public function test_question_and_availability(): void
    {
        $table = CustomTable::query()->where('name', 'vereine')->sole();
        $this->fake->push(['kind' => 'question', 'question' => 'Typänderungen bitte von Hand im Table-Designer.', 'add' => [], 'change' => []]);

        // Beim Bearbeiten nur „Mit KI anpassen“, nicht das Eingabefeld der neuen Tabelle (dessen Methode hat nur die Anlegeseite).
        Livewire::test(EditCustomTable::class, ['record' => $table->id])
            ->assertDontSeeHtml('data-table-assistant')
            ->assertActionVisible('aiAdjust')
            ->callAction('aiAdjust', ['prompt' => 'Ort als Zahl'])
            // Rückfrage im Dialog (keine Benachrichtigung), kein Vorschlag zum Übernehmen.
            ->assertActionMounted('aiAdjust')
            ->assertSet('aiMessages', [['role' => 'user', 'text' => 'Ort als Zahl'], ['role' => 'assistant', 'text' => 'Typänderungen bitte von Hand im Table-Designer.']])
            ->assertSet('aiChanges', null)
            ->assertNotNotified();

        // Ohne Recht: weder Eingabefeld noch Aktion.
        $this->actingAs($this->userWithPermissions(['custom_tables.view', 'custom_tables.create', 'custom_tables.update']));
        Livewire::test(CreateCustomTable::class)->assertDontSeeHtml('data-table-assistant');
        Livewire::test(EditCustomTable::class, ['record' => $table->id])->assertActionHidden('aiAdjust');

        // Tageslimit gemeinsam mit dem Auswertungs-Assistenten.
        config(['ai.daily_limit' => 1]);
        $this->actingAs($this->admin);
        $this->assertSame(0, AiUsage::remainingToday($this->admin));
        $this->assertFalse(TableAssistant::availableFor($this->userWithPermissions(['custom_tables.view'])));
    }

    public function test_file_fields_can_be_proposed(): void
    {
        $this->fake->push($this->members(['fields' => [
            $this->field('nachname', 'string', ['required' => true]),
            $this->field('foto', 'file', ['file_kinds' => ['images'], 'max_mb' => 5, 'max_files' => 1, 'sortable' => true, 'unique' => true]),
            $this->field('vertrag', 'file', ['file_kinds' => ['pdf', 'any'], 'max_mb' => null, 'max_files' => null]),
        ]]));

        Livewire::test(CreateCustomTable::class)
            ->set('assistantPrompt', 'Mitglieder mit Foto und Vertrag als PDF')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'proposal')
            ->call('create')
            ->assertHasNoFormErrors();

        $fields = CustomTable::query()->where('name', 'mitglieder')->sole()->fields()->get()->keyBy('name');
        $this->assertSame(FieldType::File, $fields['foto']->type);
        $this->assertSame(['kinds' => ['images'], 'max_mb' => 5, 'max_files' => 1], $fields['foto']->options);
        $this->assertFalse($fields['foto']->is_unique || $fields['foto']->is_sortable);
        $this->assertSame(['kinds' => ['pdf'], 'max_mb' => 10, 'max_files' => 1], $fields['vertrag']->options);
        $this->assertStringContainsString('file_kinds = images', $this->fake->sentText());
    }

    public function test_the_app_comes_from_the_request_and_is_asked_for_when_open(): void
    {
        // Zwei Apps: „Verwaltung“ (mit „vereine“) und „Lagerverwaltung“ mit Präfix und Gruppe „Sales“; dazu eine Systemgruppe.
        $first = self::ensureTestApp();
        $lager = App::query()->create(['name' => ['de' => 'Lagerverwaltung'], 'prefix' => 'lager', 'sort_order' => 2]);
        $sales = Menu::query()->create(['label' => ['de' => 'Sales'], 'app_id' => $lager->id, 'sort_order' => 1]);
        $system = Menu::query()->create(['label' => ['de' => 'Administration'], 'sort_order' => 99]);
        $customers = fn (array $overrides = []): array => $this->members([
            'name' => 'kunden', 'label' => $this->t('Kunden', 'Customers'), 'fields' => [$this->field('firma', 'string')], ...$overrides,
        ]);

        // Im Text genannt: App und Gruppe gesetzt, Name mit Präfix; das Modell kennt Apps, Gruppen und Tabellen je App.
        $this->fake->push($customers(['app' => $lager->id, 'menu_group' => (string) $sales->id]));
        Livewire::test(CreateCustomTable::class)
            ->fillForm(['app_id' => null])
            ->set('assistantPrompt', 'Erstelle in der Gruppe Sales der App Lagerverwaltung eine Tabelle Kunden')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'proposal')
            ->assertFormSet(['app_id' => $lager->id, 'menu_group' => (string) $sales->id, 'name' => 'lager_kunden']);
        $sent = $this->fake->sentText();
        // Das Datenmodell steckt als Text in der Anfrage: Anführungszeichen maskiert.
        $this->assertStringContainsString('\\"apps\\":', $sent);
        $this->assertStringContainsString('Lagerverwaltung', $sent);
        $this->assertStringNotContainsString('\\"label\\":\\"Administration\\"', $sent, 'Keine Systemgruppen.');
        $this->assertStringContainsString('\\"label\\":\\"Sales\\"', $sent);

        // Keine App genannt und keine gewählt: Korrektur, dann Rückfrage.
        $this->fake->push($customers(['app' => null]))
            ->push(['kind' => 'question', 'question' => 'In welcher App: Verwaltung oder Lagerverwaltung?', 'app' => null, 'name' => '', 'label' => $this->t(''), 'description' => null, 'menu_group' => null, 'fields' => []]);
        Livewire::test(CreateCustomTable::class)
            ->fillForm(['app_id' => null])
            ->set('assistantPrompt', 'Eine Tabelle Kunden')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'question');
        $this->assertStringContainsString(__('tables_assistant.errors.app_missing'), end($this->fake->requests[array_key_last($this->fake->requests)]['messages'])['content']);

        // Systemgruppe und Verknüpfung in eine andere App werden zurückgewiesen.
        $bad = $customers(['app' => $lager->id, 'menu_group' => (string) $system->id, 'fields' => [
            $this->field('verein_id', 'relation', ['relation_table' => 'vereine', 'relation_display' => ['name']]),
        ]]);
        $this->fake->push($bad)->push($bad);
        Livewire::test(CreateCustomTable::class)
            ->set('assistantPrompt', 'Kunden im Lager mit Verein')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'error');
        $correction = end($this->fake->requests[array_key_last($this->fake->requests)]['messages'])['content'];
        $this->assertStringContainsString(__('tables_assistant.errors.menu_group', ['id' => (string) $system->id]), $correction);
        $this->assertStringContainsString('verein_id', $correction);
        $this->assertSame($first->id, CustomTable::query()->where('name', 'vereine')->value('app_id'));
    }

    public function test_relations_into_other_apps_use_shared_tables_and_fields(): void
    {
        // „vereine“ (Verwaltung) ist mit dem Feld „name“ freigegeben; „Lagerverwaltung“ darf darauf verknüpfen.
        self::ensureTestApp();
        $lager = App::query()->create(['name' => ['de' => 'Lagerverwaltung'], 'prefix' => 'lager', 'sort_order' => 2]);
        $this->service()->updateInterface(CustomTable::query()->where('name', 'vereine')->sole(), true, ['name']);
        $customers = fn (array $display): array => $this->members(['app' => $lager->id, 'name' => 'kunden', 'label' => $this->t('Kunden'), 'fields' => [
            $this->field('firma', 'string'),
            $this->field('verein_id', 'relation', ['relation_table' => 'vereine', 'relation_display' => $display]),
        ]]);

        // Nicht freigegebenes Anzeigefeld: Korrektur; danach nur freigegebene Felder.
        $this->fake->push($customers(['ort']))->push($customers(['name']));
        Livewire::test(CreateCustomTable::class)
            ->fillForm(['app_id' => $lager->id])
            ->set('assistantPrompt', 'Kunden mit ihrem Verein')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'proposal')
            ->call('create')
            ->assertHasNoFormErrors();
        $this->assertStringContainsString(__('tables_assistant.errors.relation_shared_fields', ['name' => 'verein_id', 'table' => 'vereine', 'fields' => 'ort']), end($this->fake->requests[1]['messages'])['content']);
        $this->assertSame('vereine', CustomTable::query()->where('name', 'lager_kunden')->sole()->fields()->where('name', 'verein_id')->sole()->optionsArray()['table']);
        // Im Kontext: die freigegebenen Felder der Tabelle anderer Apps.
        $this->assertStringContainsString('\\"shared_fields\\":[\\"name\\"]', $this->fake->sentText());
    }

    public function test_icon_is_proposed_from_the_installed_icons(): void
    {
        // Neue Tabelle: unbekanntes Symbol wird korrigiert, bekanntes als heroicon-o-… übernommen.
        $this->fake->push($this->members(['icon' => 'vereinsfahne']))->push($this->members(['icon' => 'users']));
        Livewire::test(CreateCustomTable::class)
            ->set('assistantPrompt', 'Mitglieder eines Vereins')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'proposal')
            ->assertFormSet(['icon' => 'heroicon-o-users']);
        $this->assertStringContainsString(__('tables_assistant.errors.icon', ['icon' => 'vereinsfahne']), end($this->fake->requests[1]['messages'])['content']);
        $this->assertStringContainsString('truck', $this->fake->sentText(), 'Die Liste der Symbole ist im Kontext.');

        // Bestehende Tabelle: nur das Symbol ändern.
        $table = CustomTable::query()->where('name', 'vereine')->sole();
        $this->fake->push(['kind' => 'changes', 'question' => null, 'icon' => 'flag', 'add' => [], 'change' => []]);
        Livewire::test(EditCustomTable::class, ['record' => $table->id])
            ->callAction('aiAdjust', ['prompt' => 'Bitte ein Fahnen-Symbol'])
            ->assertActionMounted('aiAdjust')
            ->assertSet('aiMessages.1.role', 'assistant')
            ->assertSet('aiSummary', [__('tables_assistant.summary.icon', ['icon' => 'flag'])])
            ->callAction(TestAction::make('aiApply'));
        $this->assertSame('heroicon-o-flag', $table->fresh()->icon);
    }

    public function test_field_rules_are_proposed_when_creating(): void
    {
        $proposal = fn (string $rules): array => $this->members(['fields' => [
            $this->field('nachname', 'string', ['rules' => 'max_length=40']),
            $this->field('menge', 'integer', ['rules' => $rules]),
        ]]);

        // Unbekannte Regel wird korrigiert.
        $this->fake->push($proposal('maximum=3'))->push($proposal('min=1; max=3'));
        $page = Livewire::test(CreateCustomTable::class)
            ->set('assistantPrompt', 'Mitglieder, Menge maximal 3')
            ->call('askTableAssistant')
            ->assertSet('assistantReplyKind', 'proposal');
        $this->assertStringContainsString('Regel „maximum“ gibt es nicht', end($this->fake->requests[1]['messages'])['content']);

        $page->call('create')->assertHasNoFormErrors();
        $fields = CustomTable::query()->where('name', 'mitglieder')->sole()->fields->keyBy('name');
        $this->assertEquals(['min' => 1, 'max' => 3], $fields['menge']->validation_rules);
        $this->assertEquals(['max_length' => 40], $fields['nachname']->validation_rules);
    }

    public function test_field_and_table_rules_are_added_to_an_existing_table(): void
    {
        $this->service()->createTable(['name' => 'anfragepositionen', 'label' => ['de' => 'Anfragepositionen']], [
            ['name' => 'menge', 'label' => ['de' => 'Menge'], 'type' => 'integer', 'validation_rules' => ['min' => 1]],
            ['name' => 'beginn', 'label' => ['de' => 'Beginn'], 'type' => 'date'],
        ]);
        $table = CustomTable::query()->where('name', 'anfragepositionen')->sole();
        $change = fn (string $name, array $overrides): array => [...['name' => $name, 'label' => $this->t(''), 'required' => null, 'unique' => null, 'searchable' => null, 'sortable' => null, 'add_choices' => [], 'relation_display' => [], 'rules' => ''], ...$overrides];
        $proposal = fn (array $rules): array => [
            'kind' => 'changes', 'question' => '', 'icon' => '',
            'add' => [$this->field('ende', 'date', ['label' => $this->t('Ende')])],
            'change' => [$change('menge', ['rules' => 'max=3'])],
            'add_rules' => $rules,
        ];

        // Datum mit Zahl vergleichen geht nicht: Korrektur; die neue Spalte „ende“ ist in Regeln schon nutzbar.
        $this->fake->push($proposal(['ende >= menge']))->push($proposal(['ende >= beginn', 'menge > 0']));

        Livewire::test(EditCustomTable::class, ['record' => $table->id])
            ->callAction('aiAdjust', ['prompt' => 'Menge maximal 3, Feld Ende, Ende nicht vor Beginn, Menge grösser 0'])
            ->assertActionMounted('aiAdjust')
            ->assertSet('aiMessages.1.role', 'assistant')
            ->assertSet('aiSummary', [
                'Neu: Ende (Datum)',
                'Ändern: Menge – Regeln: Maximum 3',
                'Regel: Ende ≥ Beginn',
                'Regel: Menge > 0',
            ])
            ->callAction(TestAction::make('aiApply'))
            ->assertNotified(__('tables_assistant.applied_all'));
        $this->assertStringContainsString('Tabellenregel', end($this->fake->requests[1]['messages'])['content']);

        $table->refresh()->load('fields');
        $this->assertEquals(['min' => 1, 'max' => 3], $table->fields->firstWhere('name', 'menge')->validation_rules);
        $this->assertSame(['Ende ≥ Beginn', 'Menge > 0'], array_map(fn ($rule): string => TableRuleNormalizer::describe($rule, $table), $table->tableRules()));
    }

    public function test_a_table_rule_on_the_same_fields_replaces_the_existing_one(): void
    {
        $this->service()->createTable(['name' => 'bestellungen', 'label' => ['de' => 'Bestellungen']], [
            ['name' => 'bestelldatum', 'label' => ['de' => 'Bestelldatum'], 'type' => 'date'],
            ['name' => 'lieferdatum', 'label' => ['de' => 'Lieferdatum'], 'type' => 'date'],
        ]);
        $table = CustomTable::query()->where('name', 'bestellungen')->sole();
        $this->service()->updateTable($table->load('fields'), ['validation_rules' => [[
            'left' => 'lieferdatum', 'operator' => '>=', 'right_kind' => 'field', 'right_field' => 'bestelldatum', 'when_filled' => true, 'message' => ['de' => 'Zu früh'],
        ]]]);
        $rules = fn (array $rules): array => ['kind' => 'changes', 'question' => '', 'icon' => '', 'add' => [], 'change' => [], 'add_rules' => $rules];

        // Die gleiche Regel nochmals: Korrektur; dann „>“ statt „≥“: ersetzt die vorhandene.
        $this->fake->push($rules(['lieferdatum >= bestelldatum']))->push($rules(['lieferdatum > bestelldatum']));

        Livewire::test(EditCustomTable::class, ['record' => $table->id])
            ->callAction('aiAdjust', ['prompt' => 'Lieferdatum > Bestelldatum'])
            ->assertSet('aiSummary', ['Regel ändern: Lieferdatum ≥ Bestelldatum → Lieferdatum > Bestelldatum'])
            ->callAction(TestAction::make('aiApply'));
        $this->assertStringContainsString('lieferdatum >= bestelldatum', $this->fake->sentText(), 'Vorhandene Regeln im Kontext.');
        $this->assertStringContainsString(__('tables_assistant.errors.table_rule_exists'), end($this->fake->requests[1]['messages'])['content']);

        $saved = $table->fresh()->tableRules();
        $this->assertCount(1, $saved);
        $this->assertSame('>', $saved[0]->operator);
        $this->assertSame(['de' => 'Zu früh'], $saved[0]->message, 'Eigene Meldung bleibt.');
    }

    public function test_adjusting_is_a_conversation_in_the_dialog(): void
    {
        $table = CustomTable::query()->where('name', 'vereine')->sole();
        $this->fake->push(['kind' => 'changes', 'question' => '', 'icon' => 'flag', 'add' => [], 'change' => [], 'add_rules' => []])
            ->push(['kind' => 'question', 'question' => 'Welche Farbe?', 'icon' => '', 'add' => [], 'change' => [], 'add_rules' => []]);

        $page = Livewire::test(EditCustomTable::class, ['record' => $table->id])
            ->callAction('aiAdjust', ['prompt' => 'Fahnen-Symbol'])
            ->assertActionMounted('aiAdjust')
            ->assertSet('mountedActions.0.data.prompt', null)
            // Zweiter Wunsch im selben Dialog: Rückfrage erscheint im Verlauf, der Vorschlag bleibt übernehmbar.
            ->set('mountedActions.0.data.prompt', 'Und farbig?')
            ->callMountedAction()
            ->assertActionMounted('aiAdjust')
            ->assertSet('aiMessages.3', ['role' => 'assistant', 'text' => 'Welche Farbe?'])
            ->assertNotSet('aiChanges', null);
        $this->assertCount(4, $page->get('aiMessages'));

        // Der offene Dialog zeigt den Verlauf samt Vorschlag (Livewire-Tests rendern Modals nicht: Schema direkt).
        $instance = $page->instance();
        $html = (string) $instance->getSchema($instance->getMountedActionSchemaName())?->toHtml();
        $this->assertStringContainsString('data-table-assistant-preview', $html);
        $this->assertStringContainsString(e(__('tables_assistant.summary.icon', ['icon' => 'flag'])), $html);
        $this->assertStringContainsString('Welche Farbe?', $html);

        $page->callAction(TestAction::make('aiReset'))
            ->assertSet('aiMessages', [])
            ->assertSet('aiChanges', null)
            ->assertSet('aiHistory', []);
    }

    public function test_a_rule_written_the_other_way_round_replaces_the_existing_one(): void
    {
        $this->service()->createTable(['name' => 'bestellungen', 'label' => ['de' => 'Bestellungen']], [
            ['name' => 'bestelldatum', 'label' => ['de' => 'Bestelldatum'], 'type' => 'date'],
            ['name' => 'lieferdatum', 'label' => ['de' => 'Lieferdatum'], 'type' => 'date'],
        ]);
        $table = CustomTable::query()->where('name', 'bestellungen')->sole();
        $this->service()->updateTable($table->load('fields'), ['validation_rules' => [[
            'left' => 'bestelldatum', 'operator' => '<', 'right_kind' => 'field', 'right_field' => 'lieferdatum', 'when_filled' => true, 'message' => [],
        ]]]);
        $this->fake->push(['kind' => 'changes', 'question' => '', 'icon' => '', 'add' => [], 'change' => [], 'add_rules' => ['lieferdatum >= bestelldatum']]);

        Livewire::test(EditCustomTable::class, ['record' => $table->id])
            ->callAction('aiAdjust', ['prompt' => 'Lieferdatum frühestens am Bestelldatum'])
            ->assertSet('aiSummary', ['Regel ändern: Bestelldatum < Lieferdatum → Bestelldatum ≤ Lieferdatum'])
            ->callAction(TestAction::make('aiApply'));

        $saved = $table->fresh()->tableRules();
        $this->assertCount(1, $saved, 'Keine zweite, widersprüchliche Regel.');
        $this->assertSame(['bestelldatum', '<=', 'lieferdatum'], [$saved[0]->left->fieldName(), $saved[0]->operator, $saved[0]->right->fieldName()]);
    }
}
