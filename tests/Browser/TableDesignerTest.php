<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\Schema\FieldType;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\Models\Permission;
use Tests\DuskTestCase;

/** Table-Designer in der Oberfläche (Bereich TDE). */
class TableDesignerTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string MODAL = 'mountedActionSchema0.';

    private CustomTable $kunden;

    private DynamicRecord $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $this->kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string'],
        ]);
        $this->acme = DynamicRecord::forTable($this->kunden)->create(['firma' => 'ACME GmbH', 'ort' => 'Berlin']);
        app(DataMenu::class)->ensure($this->kunden);
    }

    private function service(): DynamicSchemaService
    {
        return app(DynamicSchemaService::class);
    }

    private function orders(): CustomTable
    {
        $table = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);
        DynamicRecord::forTable($table)->create(['nummer' => 'B-100', 'kunde_id' => $this->acme->id]);
        app(DataMenu::class)->ensure($table);

        return $table;
    }

    private function field(CustomTable $table, string $name): CustomField
    {
        return CustomField::query()->where('custom_table_id', $table->id)->where('name', $name)->firstOrFail();
    }

    private function input(string $name): string
    {
        return '[id="'.self::MODAL.$name.'"]';
    }

    private function openDesigner(Browser $browser, CustomTable $table, bool $login = true): Browser
    {
        if ($login) {
            $this->login($browser, self::ADMIN);
        }

        return $this->openFieldList($browser->visit("/admin/table-designer/{$table->id}/edit"));
    }

    private function openNewField(Browser $browser): Browser
    {
        return $this->clickVisibleButton($browser, 'Feld hinzufügen')->waitFor($this->input('label.de'), 15);
    }

    private function typeLabel(Browser $browser, string $label): Browser
    {
        return $browser->type($this->input('label.de'), $label)
            ->keys($this->input('label.de'), '{tab}')
            ->waitUsing(15, 100, fn (): bool => filled($browser->value($this->input('name'))));
    }

    /** @param  callable(Browser): void|null  $configure */
    private function addField(Browser $browser, string $label, string $type, ?callable $configure = null): void
    {
        $this->openNewField($browser);
        $this->typeLabel($browser, $label);
        $browser->select($this->input('type'), $type)->pause(700);

        if ($configure !== null) {
            $configure($browser);
        }

        $this->clickVisibleButton($browser, 'Erstellen')->waitUntilMissing($this->input('label.de'), 15);
    }

    /** @return list<string> */
    private function selectOptions(Browser $browser, string $selector): array
    {
        $values = array_map(
            static fn ($option): string => (string) $option->getAttribute('value'),
            $browser->driver->findElements(WebDriverBy::cssSelector($selector.' option')),
        );

        return array_values(array_filter($values, static fn (string $value): bool => $value !== ''));
    }

    /** TDE-01: Tabelle und Felder entstehen über die Oberfläche, der Datensatz ebenfalls. */
    public function test_table_with_all_field_types_is_created_and_usable(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)
                ->visit('/admin/table-designer/create')
                ->waitFor('input[id="form.label.de"]', 15)
                ->type('input[id="form.label.de"]', 'Alle Typen')
                ->keys('input[id="form.label.de"]', '{tab}')
                ->waitUsing(15, 100, fn (): bool => $browser->value('input[id="form.name"]') === 'alle_typen')
                ->type('input[id^="form.fields."][id$=".label.de"]', 'Titel')
                ->keys('input[id^="form.fields."][id$=".label.de"]', '{tab}')
                ->waitUsing(15, 100, fn (): bool => $browser->value('input[id^="form.fields."][id$=".name"]') === 'titel');

            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitUsing(15, 100, static fn (): bool => CustomTable::query()->where('name', 'alle_typen')->exists());
            $table = CustomTable::query()->where('name', 'alle_typen')->firstOrFail();
            $browser->waitForTextIn(self::SELECTOR_SIDEBAR, 'Alle Typen', 15);

            $this->openDesigner($browser, $table, login: false);
            $this->addField($browser, 'Langtext', FieldType::Text->value);
            $this->addField($browser, 'Ganzzahl', FieldType::Integer->value);
            $this->addField($browser, 'Dezimal', FieldType::Decimal->value);
            $this->addField($browser, 'Schalter', FieldType::Boolean->value);
            $this->addField($browser, 'Datum', FieldType::Date->value);
            $this->addField($browser, 'Zeitpunkt', FieldType::DateTime->value);
            $this->addField($browser, 'Auswahl', FieldType::Select->value, function (Browser $b): void {
                $b->waitFor('input[id$=".value"]', 15)->type('input[id$=".value"]', 'neu');
            });
            $this->addField($browser, 'Kunde', FieldType::Relation->value, function (Browser $b): void {
                $b->waitFor($this->input('options.table'), 15)->select($this->input('options.table'), 'kunden')->pause(900);
                $this->pickOption($b, self::MODAL.'options.display_fields', 'Firma');
            });

            // Datei (B-021): Vorgaben genügen (beliebige Dateien, 10 MB, eine Datei).
            $this->addField($browser, 'Beleg', FieldType::File->value);

            // Berechnet: Formel mit Prüfung beim Verlassen des Felds.
            $this->addField($browser, 'Doppelt', FieldType::Computed->value, function (Browser $b): void {
                $b->waitFor('textarea[id="'.self::MODAL.'options.formula"]', 15)
                    ->type('textarea[id="'.self::MODAL.'options.formula"]', 'ganzzahl * 2')
                    ->keys('textarea[id="'.self::MODAL.'options.formula"]', '{tab}')
                    ->waitForText('hängt ab von: ganzzahl', 15);
            });

            // Nummernkreis (B-135): Vorgaben genügen (R-{YYYY}-{0000}, jährlich, Anlegedatum).
            $this->addField($browser, 'Beleg-Nr', FieldType::Number->value, function (Browser $b): void {
                $b->waitForText('Nächste Nummer: R-'.now()->format('Y').'-0001', 15);
            });

            $this->assertSame(12, $table->fields()->count());
            $this->assertEqualsCanonicalizing(
                array_map(static fn (FieldType $type): string => $type->value, FieldType::cases()),
                $table->fields()->get()->map(static fn (CustomField $f): string => $f->type->value)->unique()->values()->all(),
            );

            $browser->visit('/admin/data/alle_typen')->waitForText('Neuer Datensatz', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor($this->input('titel'), 15);
            $browser->type($this->input('titel'), 'Erster')->type($this->input('ganzzahl'), '7');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Erster', 15);

            DynamicRecord::forget('alle_typen');
            $this->assertSame(1, DynamicRecord::forTableName('alle_typen')->newQuery()->count());
            $this->assertSame('R-'.now()->format('Y').'-0001', DynamicRecord::forTableName('alle_typen')->newQuery()->value('beleg_nr'));
            $this->assertSame('14.00', DynamicRecord::forTableName('alle_typen')->newQuery()->value('doppelt'));
        });
    }

    /** TDE-02 */
    public function test_name_is_suggested_from_label_without_overwriting_own_name(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            $this->openNewField($browser);
            $this->typeLabel($browser, 'Größe in m²');
            $this->assertSame('groesse_in_m2', $browser->value($this->input('name')));

            $browser->clear($this->input('name'))->type($this->input('name'), 'eigener_name')
                ->clear($this->input('label.de'))->type($this->input('label.de'), 'Anders')
                ->keys($this->input('label.de'), '{tab}')
                ->pause(800);

            $this->assertSame('eigener_name', $browser->value($this->input('name')));
        });
    }

    /** TDE-04 */
    public function test_type_change_shows_matching_settings_and_stores_only_those(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            $this->openNewField($browser);
            $browser->assertSee('Maximale Länge');

            $browser->select($this->input('type'), FieldType::Select->value)->waitForText('Auswahlwerte', 15)->assertDontSee('Maximale Länge');
            $browser->select($this->input('type'), FieldType::Relation->value)->waitForText('Verknüpfte Tabelle', 15)->assertDontSee('Auswahlwerte');
            $browser->select($this->input('type'), FieldType::Boolean->value)->waitUntilMissingText('Verknüpfte Tabelle', 15)->assertMissing($this->input('is_unique'));
            $browser->select($this->input('type'), FieldType::String->value)->waitForText('Maximale Länge', 15);
            $browser->clear($this->input('options.length'))->type($this->input('options.length'), '77');
            $browser->select($this->input('type'), FieldType::Decimal->value)->waitForText('Stellen gesamt', 15)->assertDontSee('Maximale Länge');

            $this->typeLabel($browser, 'Betrag');
            $this->clickVisibleButton($browser, 'Erstellen')->waitUntilMissing($this->input('label.de'), 15);

            $options = $this->field($this->kunden, 'betrag')->optionsArray();
            $this->assertArrayNotHasKey('length', $options);
            $this->assertSame(12, (int) $options['precision']);
        });
    }

    /** TDE-05 */
    public function test_display_field_follows_the_target_table(): void
    {
        $this->orders();

        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            $this->openNewField($browser);
            $browser->select($this->input('type'), FieldType::Relation->value)->waitFor($this->input('options.table'), 15);

            $browser->select($this->input('options.table'), 'kunden')->pause(900);
            $this->assertEqualsCanonicalizing(['Firma', 'Ort'], $this->jsSelectOptions($browser, self::MODAL.'options.display_fields'));
            $this->pickOption($browser, self::MODAL.'options.display_fields', 'Firma');
            // Ab zwei Anzeigefeldern erscheint das Trennzeichen.
            $browser->assertMissing($this->input('options.separator'));
            // Die Mehrfachauswahl bleibt nach einer Wahl offen: erst schließen.
            $browser->click('.fi-modal-window .fi-modal-heading')->pause(300);
            $this->pickOption($browser, self::MODAL.'options.display_fields', 'Ort');
            $browser->waitFor($this->input('options.separator'), 15);

            $browser->select($this->input('options.table'), 'bestellungen')->pause(900);
            $this->assertSame(['Nummer'], $this->jsSelectOptions($browser, self::MODAL.'options.display_fields'));
            $browser->assertMissing($this->input('options.separator'));
        });
    }

    /** TDE-06 */
    public function test_required_field_on_filled_table_keeps_old_records_readable(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            $this->addField($browser, 'Land', FieldType::String->value, function (Browser $b): void {
                $b->click($this->input('is_required'))->pause(300);
            });

            $browser->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
            $this->openDetails($browser, 'ACME GmbH');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".land"]', 15);
            $this->clickVisibleButton($browser, 'Speichern')->pause(500);
            $this->assertNotSame('', $this->nativeValidationMessage($browser, 'input[id$=".land"]'));

            $browser->type('input[id$=".land"]', 'Deutschland');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);

            DynamicRecord::forget('kunden');
            $this->assertSame('Deutschland', DynamicRecord::forTableName('kunden')->newQuery()->findOrFail($this->acme->id)->getAttribute('land'));
        });
    }

    /** TDE-07 */
    public function test_deactivated_field_is_hidden_and_its_data_returns_after_reactivation(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            $this->rowAction($browser, 'ort', 'Deaktivieren')->waitForText('Bestätigen', 15);
            // Nach dem Deaktivieren heißt die Zeilenaktion „Aktivieren“ (vorher „Deaktivieren“).
            $this->clickVisibleButton($browser, 'Bestätigen')->waitForText('Aktivieren', 15);

            $browser->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15)->assertDontSee('Berlin')->assertMissing('th.fi-ta-header-cell-ort');
            $this->openDetails($browser, 'ACME GmbH')->assertDontSee('Berlin');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".firma"]', 15)->assertMissing('input[id$=".ort"]');
            $browser->clear('input[id$=".firma"]')->type('input[id$=".firma"]', 'ACME AG');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);

            $this->openDesigner($browser, $this->kunden, login: false);
            // Aktivieren braucht keine Rückfrage und darf nicht vom Ausblenden sprechen.
            $this->rowAction($browser, 'ort', 'Aktivieren')->waitUntilMissingText('Aktivieren', 15)->assertDontSee('ausgeblendet');

            $browser->visit('/admin/data/kunden')->waitForText('ACME AG', 15)->assertSee('Berlin');
        });
    }

    /** TDE-08 */
    public function test_renaming_a_display_field_keeps_the_dependent_list_working(): void
    {
        $orders = $this->orders();

        $this->browse(function (Browser $browser) use ($orders): void {
            $this->openDesigner($browser, $this->kunden);
            $this->rowAction($browser, 'firma', 'Bearbeiten')->waitFor($this->input('name'), 15);
            $browser->clear($this->input('name'))->type($this->input('name'), 'firmenname');
            $this->clickVisibleButton($browser, 'Speichern')->waitUntilMissing($this->input('name'), 15);

            $this->assertSame('firmenname', $this->field($orders, 'kunde_id')->optionsArray()['display_field']);

            $browser->visit('/admin/data/bestellungen')->waitForText('B-100', 15)->assertSee('ACME GmbH')->assertPresent(self::SELECTOR_SIDEBAR);
        });
    }

    /** TDE-09 */
    public function test_deactivated_table_disappears_everywhere_and_can_return(): void
    {
        $orders = $this->orders();
        $this->service()->updateTable($this->kunden, ['detail_field_ids' => [$this->field($orders, 'kunde_id')->id]]);

        $this->browse(function (Browser $browser) use ($orders): void {
            $this->login($browser, self::ADMIN)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Bestellungen')->visit("/admin/table-designer/{$orders->id}/edit");
            $browser->waitFor('[id="form.is_active"]', 15)->click('[id="form.is_active"]')->press('Speichern')->waitForText('Gespeichert', 15);

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Bestellungen');
            $browser->visit('/admin/data/bestellungen')->assertSee('Nicht gefunden');
            $browser->visit('/admin/data/kunden');
            $this->clickRow($browser, 'ACME GmbH')->waitFor('tr.saft-row-selected', 15)->pause(500)->assertDontSee('B-100');

            $browser->visit("/admin/table-designer/{$orders->id}/edit")->waitFor('[id="form.is_active"]', 15)
                ->click('[id="form.is_active"]')->press('Speichern')->waitForText('Gespeichert', 15);

            $browser->visit('/admin/data/kunden');
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Bestellungen');
        });
    }

    /** TDE-10 */
    public function test_wrong_confirmation_deletes_neither_table_nor_field(): void
    {
        $this->service()->updateField($this->field($this->kunden, 'ort'), ['is_active' => false]);
        $inactive = $this->service()->createTable(['name' => 'alt', 'label' => 'Alt', 'is_active' => false], [
            ['name' => 'wert', 'label' => 'Wert', 'type' => 'string'],
        ]);

        $this->browse(function (Browser $browser) use ($inactive): void {
            $this->openDesigner($browser, $this->kunden);
            // In der Feldzeile ein Symbol: Bezeichnung als aria-label.
            $browser->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'ort')]//button[@aria-label='Endgültig löschen']")
                ->waitFor($this->input('confirmation'), 15);
            $browser->type($this->input('confirmation'), 'falsch');
            $this->clickVisibleButton($browser, 'Absenden')->waitFor('.fi-no-notification', 15);

            $this->assertTrue(CustomField::query()->whereKey($this->field($this->kunden, 'ort')->id)->exists());

            $browser->visit("/admin/table-designer/{$inactive->id}/edit")->waitForText('Endgültig löschen', 15);
            $this->clickVisibleButton($browser, 'Endgültig löschen')->waitFor($this->input('confirmation'), 15);
            $browser->type($this->input('confirmation'), 'falsch');
            $this->clickVisibleButton($browser, 'Absenden')->waitFor('.fi-no-notification', 15);

            $this->assertTrue(CustomTable::query()->whereKey($inactive->id)->exists());
        });
    }

    /** TDE-11 */
    public function test_inactive_table_is_finally_deleted_with_correct_name(): void
    {
        $table = $this->service()->createTable(['name' => 'alt', 'label' => 'Altdaten'], [
            ['name' => 'wert', 'label' => 'Wert', 'type' => 'string'],
        ]);
        app(DataMenu::class)->ensure($table);
        DynamicRecord::forTable($table)->create(['wert' => 'x']);
        $this->service()->updateTable($table, ['is_active' => false]);

        $this->browse(function (Browser $browser) use ($table): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$table->id}/edit")->waitForText('Endgültig löschen', 15);
            $this->clickVisibleButton($browser, 'Endgültig löschen')->waitFor($this->input('confirmation'), 15);
            $browser->type($this->input('confirmation'), 'alt');
            $this->clickVisibleButton($browser, 'Absenden')->waitForLocation('/admin/table-designer', 15);

            $browser->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)->assertDontSee('Keine Berechtigung');
        });

        $this->assertFalse(CustomTable::query()->whereKey($table->id)->exists());
        $this->assertFalse(Menu::query()->where('route_parameters->table', 'alt')->exists());
        $this->assertFalse(Permission::query()->where('name', 'like', 'data.alt.%')->exists());
    }

    /** TDE-12 */
    public function test_details_configuration_takes_effect_immediately(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$this->kunden->id}/edit")
                ->waitForText('Noch keine Tabelle verknüpft diese Tabelle', 15);
        });

        $this->orders();

        $this->browse(function (Browser $browser): void {
            $checkbox = "//label[contains(., 'Bestellungen (über „Kunde“)')]//input";

            $browser->visit("/admin/table-designer/{$this->kunden->id}/edit")->waitForText('Bestellungen (über „Kunde“)', 15)
                ->clickAtXPath($checkbox)->press('Speichern')->waitForText('Gespeichert', 15);

            $browser->visit('/admin/data/kunden');
            $this->clickRow($browser, 'ACME GmbH')->waitForText('B-100', 15);

            $browser->visit("/admin/table-designer/{$this->kunden->id}/edit")->waitForText('Bestellungen (über „Kunde“)', 15)
                ->clickAtXPath($checkbox)->press('Speichern')->waitForText('Gespeichert', 15);

            $browser->visit('/admin/data/kunden?sel='.$this->acme->id)->waitFor('tr.saft-row-selected', 15)->pause(500)->assertDontSee('B-100');
        });
    }

    /** TDE-13 */
    public function test_menu_entry_button_creates_entry_in_chosen_group(): void
    {
        $table = $this->service()->createTable(['name' => 'ohne_menue', 'label' => 'Ohne Menü'], [
            ['name' => 'wert', 'label' => 'Wert', 'type' => 'string'],
        ]);
        $data = Menu::query()->whereNull('parent_id')->where('label->de', 'Daten')->firstOrFail();
        $archive = Menu::create(['label' => ['de' => 'Archiv'], 'parent_id' => $data->id, 'sort_order' => 900, 'is_active' => true]);

        $this->browse(function (Browser $browser) use ($table, $data, $archive): void {
            $this->login($browser, self::ADMIN)->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Ohne Menü')
                ->visit("/admin/table-designer/{$table->id}/edit")
                ->waitForText('Menüeintrag anlegen', 15);

            $this->clickVisibleButton($browser, 'Menüeintrag anlegen')->waitFor('select[id="mountedActionSchema0.menu_group"]', 15);
            // Vorbelegt ist „Daten“; Untergruppen stehen eingerückt mit Pfad darunter.
            $this->assertSame((string) $data->id, $browser->value('select[id="mountedActionSchema0.menu_group"]'));
            $browser->assertSeeIn('select[id="mountedActionSchema0.menu_group"]', 'Daten › Archiv')
                ->select('select[id="mountedActionSchema0.menu_group"]', (string) $archive->id);
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Menüeintrag angelegt', 15)
                // Die Schaltfläche bleibt: ein weiterer Eintrag an anderer Stelle ist möglich.
                ->assertSee('Menüeintrag anlegen');

            $browser->visit('/admin')->waitFor(self::SELECTOR_SIDEBAR, 15)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Archiv');
            $this->assertSame($archive->id, Menu::query()->where('route_parameters->table', 'ohne_menue')->value('parent_id'));
        });
    }

    /** TDE-14 */
    public function test_lossy_type_change_is_rejected_with_data_unchanged(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            $this->rowAction($browser, 'ort', 'Bearbeiten')->waitFor($this->input('type'), 15);
            $browser->select($this->input('type'), FieldType::Integer->value)->pause(700);
            $this->clickVisibleButton($browser, 'Speichern')->waitFor('.fi-no-notification', 15);

            $this->assertSame(FieldType::String, $this->field($this->kunden, 'ort')->type);
        });

        DynamicRecord::forget('kunden');
        $this->assertSame('Berlin', DynamicRecord::forTableName('kunden')->newQuery()->findOrFail($this->acme->id)->getAttribute('ort'));
    }

    /** TDE-37: Nummernkreis mit Baustein und Vorschau anlegen; neue Datensätze bekommen die Nummer, sie ist nicht änderbar. */
    public function test_number_sequence_field_assigns_numbers_on_create(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            $this->addField($browser, 'Kundennummer', FieldType::Number->value, function (Browser $browser): void {
                $pattern = $this->input('options.pattern');
                $browser->waitFor($pattern, 15)->clear($pattern)->type($pattern, 'K-')
                    ->select($this->input('number_block'), '{0000}')
                    ->waitUsing(15, 100, fn (): bool => $browser->value($pattern) === 'K-{0000}')
                    ->select($this->input('options.reset'), 'never')
                    ->waitForText('Nächste Nummer: K-0001', 15)
                    ->assertMissing($this->input('is_required'));
            });

            $field = $this->field($this->kunden, 'kundennummer');
            $this->assertSame([FieldType::Number, true, 'K-{0000}'], [$field->type, $field->is_unique, $field->optionsArray()['pattern']]);

            $browser->visit('/admin/data/kunden')->waitForText('Neuer Datensatz', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor($this->input('firma'), 15)
                ->assertAttribute($this->input('kundennummer'), 'placeholder', 'wird beim Speichern vergeben')
                ->assertDisabled($this->input('kundennummer'))
                ->type($this->input('firma'), 'Muster AG');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('K-0001', 15);
        });

        DynamicRecord::forget('kunden');
        $this->assertSame('K-0001', DynamicRecord::forTableName('kunden')->newQuery()->where('firma', 'Muster AG')->value('kundennummer'));
        $this->assertNull(DynamicRecord::forTableName('kunden')->newQuery()->findOrFail($this->acme->id)->getAttribute('kundennummer'), 'Vorhandene Datensätze bleiben ohne Nummer.');
    }

    /** TDE-15 */
    public function test_physical_table_box_counts_deleted_records_and_shows_no_drift(): void
    {
        DynamicRecord::forTable($this->kunden)->create(['firma' => 'Gelöscht'])->delete();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)
                ->visit("/admin/table-designer/{$this->kunden->id}/edit")
                ->waitForText($this->qualified('kunden').' · 2 Datensätze (inkl. gelöschter)', 15)
                ->assertDontSee('Abweichung');
        });
    }

    /** TDE-16 */
    public function test_unknown_table_icon_is_rejected_and_stored_one_keeps_panel_usable(): void
    {
        $table = $this->service()->createTable(['name' => 'altes_icon', 'label' => 'Altes Icon', 'icon' => 'heroicon-o-gibtsnicht'], [
            ['name' => 'wert', 'label' => 'Wert', 'type' => 'string'],
        ]);
        app(DataMenu::class)->ensure($table);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->assertSeeIn(self::SELECTOR_SIDEBAR, 'Altes Icon');

            $browser->visit('/admin/table-designer/create')->waitFor('input[id="form.label.de"]', 15)
                ->type('input[id="form.label.de"]', 'Neu')
                ->type('input[id="form.icon"]', 'heroicon-o-gibtsnicht')
                ->type('input[id^="form.fields."][id$=".label.de"]', 'Wert')
                ->type('input[id^="form.fields."][id$=".name"]', 'wert');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Dieses Icon ist nicht bekannt', 15);
        });
    }

    /** TDE-25 */
    public function test_drop_buttons_are_visible_but_locked_while_active(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openDesigner($browser, $this->kunden);
            // Tabelle: Knopf mit Text; Felder: Symbol mit aria-label.
            $button = "//button[normalize-space(.)='Endgültig löschen' or @aria-label='Endgültig löschen'][@aria-disabled='true']";

            // Tabelle und Feld sind aktiv: Knöpfe gesperrt, Hinweis beim Darüberfahren.
            $this->assertCount(3, $browser->driver->findElements(WebDriverBy::xpath($button)), 'Tabelle und beide Felder.');
            $browser->mouseover('.fi-header [aria-disabled="true"]')
                ->waitForText('Zuerst die Tabelle deaktivieren (Schalter „Aktiv“) und speichern.', 15);
        });
    }
}
