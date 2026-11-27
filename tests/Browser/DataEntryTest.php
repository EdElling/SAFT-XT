<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Dateneingabe und Feldtypen (Bereich EIN). */
class DataEntryTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string MODAL = 'mountedActionSchema0.';

    private const string RELATION_SELECT = '.fi-select-input:has([id="mountedActionSchema0.kunde_id"])';

    private const string PAYLOAD = '<img src=x onerror="window.__xss=1"><script>window.__xss=1</script>';

    private CustomTable $kunden;

    private CustomTable $daten;

    private DynamicRecord $acme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $schema = app(DynamicSchemaService::class);
        $this->kunden = $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->acme = DynamicRecord::forTable($this->kunden)->create(['firma' => 'ACME GmbH']);
        DynamicRecord::forTable($this->kunden)->create(['firma' => 'Altfirma'])->delete();

        $this->daten = $schema->createTable(['name' => 'daten', 'label' => 'Daten'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'lang', 'label' => 'Langtext', 'type' => 'text'],
            ['name' => 'ganz', 'label' => 'Ganzzahl', 'type' => 'integer'],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'aktiv', 'label' => 'Aktiv', 'type' => 'boolean'],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
            ['name' => 'zeit', 'label' => 'Zeitpunkt', 'type' => 'datetime'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'wip', 'label' => 'In Arbeit'], ['value' => 'done', 'label' => 'Erledigt']]]],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'mail', 'label' => 'Mail', 'type' => 'string', 'validation_rules' => ['email' => true]],
            ['name' => 'web', 'label' => 'Web', 'type' => 'string', 'validation_rules' => ['url' => true]],
            ['name' => 'kurz', 'label' => 'Kurz', 'type' => 'string', 'validation_rules' => ['min_length' => 3]],
        ]);
        app(DataMenu::class)->ensure($this->daten);
    }

    private function input(string $name): string
    {
        return '[id="'.self::MODAL.$name.'"]';
    }

    private function openNewRecord(Browser $browser, bool $login = true): Browser
    {
        if ($login) {
            $this->login($browser, self::ADMIN);
        }

        $browser->visit('/admin/data/daten')->waitForText('Neuer Datensatz', 15);

        return $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor($this->input('titel'), 15);
    }

    private function submit(Browser $browser): Browser
    {
        return $this->clickVisibleButton($browser, 'Erstellen');
    }

    private function record(string $title): ?DynamicRecord
    {
        DynamicRecord::forget('daten');

        return DynamicRecord::forTableName('daten')->newQuery()->where('titel', $title)->first();
    }

    private function assertNoScriptRan(Browser $browser, string $where): void
    {
        $this->assertSame([true], $browser->script('return window.__xss === undefined;'), $where);
    }

    private function openRelationSelect(Browser $browser, string $search = ''): Browser
    {
        $browser->click($this->input('kunde_id'))->waitFor($this->searchInput(), 15);

        if ($search !== '') {
            $browser->append($this->searchInput(), $search)->pause(900);
        }

        return $browser;
    }

    private function searchInput(): string
    {
        return self::RELATION_SELECT.' .fi-select-input-search-ctn input';
    }

    private function optionsList(): string
    {
        return self::RELATION_SELECT.' ul[role="listbox"]';
    }

    /** EIN-01: Der native Datumsdialog ist nicht automatisierbar, geprüft wird die Tastatureingabe. */
    public function test_date_is_stored_exactly_and_shown_in_german_format(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openNewRecord($browser)->type($this->input('titel'), 'Datumstest')->keys($this->input('datum'), '01022026');
            $this->submit($browser)->waitForText('Datumstest', 15)->assertSeeIn('tr.fi-ta-row', '01.02.2026');

            $this->assertStringStartsWith('2026-02-01', (string) $this->record('Datumstest')->getRawOriginal('datum'));

            $this->openDetails($browser, 'Datumstest')->assertSee('01.02.2026');
        });
    }

    /** EIN-02 */
    public function test_datetime_keeps_the_entered_time_everywhere(): void
    {
        $this->browse(function (Browser $browser): void {
            // Das Jahresfeld nimmt mehr als vier Ziffern an, deshalb Tabulator vor der Uhrzeit.
            $this->openNewRecord($browser)->type($this->input('titel'), 'Zeittest')->keys($this->input('zeit'), '01022026', '{tab}', '1430');
            $this->submit($browser)->waitForText('Zeittest', 15)->assertSeeIn('tr.fi-ta-row', '01.02.2026 14:30');

            $this->openDetails($browser, 'Zeittest')->assertSee('14:30');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".zeit"]', 15);
            $this->assertStringContainsString('14:30', (string) $browser->value('input[id$=".zeit"]'));
        });
    }

    /** EIN-03: Beide Schreibweisen ergeben den richtigen Wert oder einen klaren Hinweis, nie einen falschen Wert. */
    public function test_decimal_input_never_stores_a_wrong_value(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN);

            foreach (['Komma' => '1249,50', 'Punkt' => '1249.50'] as $title => $value) {
                $this->openNewRecord($browser, login: false)->type($this->input('titel'), $title)->keys($this->input('preis'), $value);
                $this->submit($browser)->pause(1500);

                $record = $this->record($title);

                if ($record !== null) {
                    $this->assertEqualsWithDelta(1249.50, (float) $record->getAttribute('preis'), 0.001, $title);
                    $browser->waitForText('1.249,50', 15);
                }
            }
        });
    }

    /** EIN-04 */
    public function test_decimal_upper_limit_is_enforced(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openNewRecord($browser)->type($this->input('titel'), 'Grenze')->type($this->input('preis'), '99999999.99');
            $this->submit($browser)->waitForText('Grenze', 15);
            $this->assertEqualsWithDelta(99999999.99, (float) $this->record('Grenze')->getAttribute('preis'), 0.001);

            $this->openNewRecord($browser, login: false)->type($this->input('titel'), 'Darueber')->type($this->input('preis'), '100000000');
            $this->submit($browser)->pause(800);
            $this->assertStringContainsString('99999999,99', $this->nativeValidationMessage($browser, $this->input('preis')));
            $this->assertNull($this->record('Darueber'));

            // Auch ohne Browser-Prüfung lehnt der Server den Wert ab.
            $this->disableNativeValidation($browser);
            $this->submit($browser)->waitFor('[data-validation-error]', 15);
            $this->assertNull($this->record('Darueber'));
        });
    }

    /** EIN-05 */
    public function test_huge_integer_gets_a_field_error_and_creates_nothing(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openNewRecord($browser)->type($this->input('titel'), 'Riesig')->type($this->input('ganz'), '999999999999999999999999999999');
            $this->submit($browser)->waitFor('[data-validation-error]', 15)->assertSee('Ganzzahl');
            $this->assertNull($this->record('Riesig'));
        });
    }

    /** EIN-06 */
    public function test_script_input_is_shown_as_plain_text_everywhere(): void
    {
        DynamicRecord::forTable($this->kunden)->create(['firma' => self::PAYLOAD]);
        $kunde = DynamicRecord::forTable($this->kunden)->newQuery()->where('firma', self::PAYLOAD)->firstOrFail();
        $record = DynamicRecord::forTable($this->daten)->create(['titel' => self::PAYLOAD, 'lang' => self::PAYLOAD, 'kunde_id' => $kunde->id]);
        $this->daten->update(['description' => self::PAYLOAD]);
        app(DynamicSchemaService::class)->updateTable($this->daten, ['label' => 'Daten '.self::PAYLOAD]);

        $this->browse(function (Browser $browser) use ($record): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/daten')->waitFor('.fi-ta-table', 15)->pause(500);
            $this->assertNoScriptRan($browser, 'Liste und Menü');
            $browser->assertSee('<img src=x');

            $browser->clickAtXPath("//td[contains(@class, 'kunde')]//*[self::a or self::button]")->waitForText('Zu „Kunde“ wechseln', 15)->pause(300);
            $this->assertNoScriptRan($browser, 'Vorschau');
            $browser->refresh()->waitFor('.fi-ta-table', 15);

            $this->mobile($browser)->clickAtXPath("//tr[contains(@class, 'fi-ta-row')]//button[contains(., 'Details')]")->waitForText('Schliessen', 15)->pause(300);
            $this->assertNoScriptRan($browser, 'Details');

            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".titel"]', 15);
            $browser->clear('input[id$=".titel"]')->type('input[id$=".titel"]', 'Bereinigt');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Gespeichert', 15);
            $this->clickVisibleButton($browser, 'Verlauf')->waitForText('Bereinigt', 15)->pause(300);
            $this->assertNoScriptRan($browser, 'Verlauf');

            $browser->visit('/admin/activity-log')->waitFor('.fi-ta-table', 15)->pause(300);
            $this->assertNoScriptRan($browser, 'Änderungsprotokoll');
            $browser->visit('/admin/data/daten?sel='.$record->id)->waitFor('tr.saft-row-selected', 15)->pause(300);
            $this->assertNoScriptRan($browser, 'Kurzanzeige');
        });
    }

    /** EIN-21: Gleichzeitiges Bearbeiten (Fundament 2, F2) – Meldung „Inzwischen geändert“ mit „Trotzdem speichern“ und „Neu laden“. */
    public function test_concurrent_edit_warns_and_offers_overwrite_or_reload(): void
    {
        $record = DynamicRecord::forTable($this->daten)->create(['titel' => 'Original']);
        $other = fn (string $title) => DynamicRecord::forTable($this->daten)->newQuery()->findOrFail($record->id)->update(['titel' => $title]);

        $this->browse(function (Browser $browser) use ($record, $other): void {
            $this->login($browser, self::ADMIN);
            $browser->visit('/admin/data/daten')->waitForText('Original', 15);
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".titel"]', 15);
            $browser->clear('input[id$=".titel"]')->type('input[id$=".titel"]', 'Meine Fassung');

            // Inzwischen ändert jemand anderes: Speichern wird abgelehnt, das Fenster bleibt offen.
            $other('Fremde Fassung');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Inzwischen geändert', 15)
                ->assertSee('Trotzdem speichern')->assertSee('Neu laden')->assertVisible('input[id$=".titel"]');
            $this->assertSame('Fremde Fassung', $record->fresh()->titel);

            // Trotzdem speichern: die eigene Fassung überschreibt.
            $this->clickVisibleButton($browser, 'Trotzdem speichern')->waitForText('Gespeichert', 15)->waitUntilMissing('input[id$=".titel"]', 15);
            $this->assertSame('Meine Fassung', $record->fresh()->titel);

            // Neu laden: Fenster zu, Tabelle zeigt den fremden Stand.
            $browser->waitForText('Meine Fassung', 15);
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".titel"]', 15);
            $browser->clear('input[id$=".titel"]')->type('input[id$=".titel"]', 'Zweite Fassung');
            $other('Noch fremder');
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Inzwischen geändert', 15);
            $this->clickVisibleButton($browser, 'Neu laden')->waitUntilMissing('input[id$=".titel"]', 15)->waitForText('Noch fremder', 15);
            $this->assertSame('Noch fremder', $record->fresh()->titel);
        });
    }

    /** EIN-07: Emojis können per WebDriver nicht getippt werden und werden direkt gespeichert. */
    public function test_special_characters_are_stored_and_shown_unchanged(): void
    {
        $typed = 'ÄÖÜß äöü "Zitat" O\'Neil & Co <b>';
        DynamicRecord::forTable($this->daten)->create(['titel' => 'Emoji 😀 Test']);

        $this->browse(function (Browser $browser) use ($typed): void {
            $this->openNewRecord($browser)->type($this->input('titel'), $typed);
            $this->submit($browser)->waitForText('ÄÖÜß äöü', 15)->assertSee($typed)->assertSee('Emoji 😀 Test');

            $this->assertNotNull($this->record($typed));

            $this->openDetails($browser, 'ÄÖÜß')->assertSee($typed);
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".titel"]', 15);
            $this->assertSame($typed, $browser->value('input[id$=".titel"]'));
        });
    }

    /** EIN-08 */
    public function test_line_breaks_survive_saving_and_editing(): void
    {
        $text = "Zeile eins\nZeile zwei\nZeile drei";

        $this->browse(function (Browser $browser) use ($text): void {
            $this->openNewRecord($browser)->type($this->input('titel'), 'Mehrzeilig')->type($this->input('lang'), $text);
            $this->submit($browser)->waitForText('Mehrzeilig', 15);

            $this->assertSame($text, str_replace("\r\n", "\n", (string) $this->record('Mehrzeilig')->getAttribute('lang')));

            $this->openDetails($browser, 'Mehrzeilig');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('textarea[id$=".lang"]', 15);
            $this->assertSame($text, str_replace("\r\n", "\n", (string) $browser->value('textarea[id$=".lang"]')));
        });
    }

    /** EIN-09 */
    public function test_relation_select_finds_partial_terms_and_hides_deleted_records(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openNewRecord($browser)->type($this->input('titel'), 'Mit Kunde');

            $this->openRelationSelect($browser, 'altf');
            $this->assertStringNotContainsString('Altfirma', $browser->text(self::RELATION_SELECT));

            $browser->keys($this->searchInput(), ['{control}', 'a'], '{backspace}', 'acm')
                ->waitForTextIn($this->optionsList(), 'ACME GmbH', 15)
                ->scrollIntoView(self::RELATION_SELECT.' .fi-select-input-option')
                ->clickAtXPath("//button[@id='mountedActionSchema0.kunde_id']/ancestor::*[contains(@class, 'fi-select-input-ctn')][1]//*[contains(@class, 'fi-select-input-option')][contains(., 'ACME GmbH')]")
                ->pause(300);
            $this->submit($browser)->waitForText('Mit Kunde', 15)->assertSee('ACME GmbH');

            $this->assertSame((int) $this->acme->id, (int) $this->record('Mit Kunde')->getAttribute('kunde_id'));
        });
    }

    /** EIN-10 */
    public function test_relation_select_reaches_records_beyond_the_first_fifty(): void
    {
        for ($number = 1; $number <= 60; $number++) {
            DynamicRecord::forTable($this->kunden)->create(['firma' => sprintf('Zulieferer %03d', $number)]);
        }

        $this->browse(function (Browser $browser): void {
            $this->openNewRecord($browser)->type($this->input('titel'), 'Viele');

            $this->openRelationSelect($browser)->waitForTextIn($this->optionsList(), 'ACME GmbH', 15);
            $this->assertCount(50, $browser->elements(self::RELATION_SELECT.' .fi-select-input-option'));

            $browser->append($this->searchInput(), 'Zulieferer 060')
                ->waitForTextIn($this->optionsList(), 'Zulieferer 060', 15)
                ->clickAtXPath("//button[@id='mountedActionSchema0.kunde_id']/ancestor::*[contains(@class, 'fi-select-input-ctn')][1]//*[contains(@class, 'fi-select-input-option')][contains(., 'Zulieferer 060')]")
                ->pause(300);
            $this->submit($browser)->waitForText('Viele', 15);

            $this->openDetails($browser, 'Viele');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitForText('Zulieferer 060', 15);
        });
    }

    /** EIN-11 */
    public function test_boolean_is_stored_and_three_state_filter_distinguishes_it(): void
    {
        DynamicRecord::forTable($this->daten)->create(['titel' => 'Nein-Satz', 'aktiv' => false]);

        $this->browse(function (Browser $browser): void {
            $this->openNewRecord($browser)->type($this->input('titel'), 'Ja-Satz')->click($this->input('aktiv'))->pause(200);
            $this->submit($browser)->waitForText('Ja-Satz', 15);
            $this->assertTrue((bool) $this->record('Ja-Satz')->getAttribute('aktiv'));

            $this->openDetails($browser, 'Ja-Satz')->assertSee('Ja');
            $this->clickVisibleButton($browser, 'Schliessen')->waitUntilMissingText('Bearbeiten', 15);

            $this->applyFilters($browser, static fn (Browser $b) => $b->select('select[id="tableFiltersForm.aktiv.value"]', '1'))
                ->waitUntilMissingText('Nein-Satz', 15)
                ->assertSee('Ja-Satz');

            $this->applyFilters($browser, static fn (Browser $b) => $b->select('select[id="tableFiltersForm.aktiv.value"]', '0'))
                ->waitForText('Nein-Satz', 15)
                ->assertDontSee('Ja-Satz');
        });
    }

    /** EIN-12 */
    public function test_removing_a_used_option_warns_and_keeps_the_record_usable(): void
    {
        DynamicRecord::forTable($this->daten)->create(['titel' => 'Alter Wert', 'status' => 'done']);
        $field = CustomField::query()->where('custom_table_id', $this->daten->id)->where('name', 'status')->firstOrFail();

        $this->browse(function (Browser $browser) use ($field): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$this->daten->id}/edit");
            $this->openFieldList($browser)
                ->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'status')]//*[self::button or self::a][contains(., 'Bearbeiten') or @aria-label='Bearbeiten']")
                ->waitFor('input[id$=".value"]', 15)
                ->clickAtXPath("(//*[contains(@class, 'fi-fo-table-repeater')]//button[contains(@title, 'öschen')])[2]")
                ->pause(900);
            $this->clickVisibleButton($browser, 'Speichern')->waitForText('Verwendeter Auswahlwert entfernt', 15)->assertSee('1 Datensatz');

            $this->assertSame(['wip'], array_column($field->fresh()->optionsArray()['options'], 'value'));

            $browser->visit('/admin/data/daten')->waitForText('Alter Wert', 15)->assertSee('done');
            $this->openDetails($browser, 'Alter Wert');
            $this->clickVisibleButton($browser, 'Bearbeiten')->waitFor('input[id$=".titel"]', 15);
            $this->disableNativeValidation($browser);
            $this->clickVisibleButton($browser, 'Speichern')->waitFor('[data-validation-error]', 15)->assertSee('Status');
        });
    }

    /** EIN-13 */
    public function test_validation_messages_are_german_and_name_the_field(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->openNewRecord($browser)
                ->type($this->input('mail'), 'keine-mail')
                ->type($this->input('web'), 'keine-url')
                ->type($this->input('kurz'), 'ab');
            $this->disableNativeValidation($browser);

            $this->submit($browser)
                ->waitFor('[data-validation-error]', 15)
                ->assertSee('Titel muss ausgefüllt werden')
                ->assertSee('Mail muss eine gültige E-Mail-Adresse sein')
                ->assertSee('Web muss eine URL sein')
                ->assertSee('Kurz muss mindestens 3 Zeichen lang sein');
        });
    }

    /** EIN-17 */
    public function test_desktop_edits_and_deletes_directly_from_the_row(): void
    {
        $this->browse(function (Browser $browser): void {
            // Breit (Desktop): Symbole „Bearbeiten“ und „Löschen“ in der Zeile, kein „Details“.
            $this->login($browser, self::ADMIN)->visit('/admin/data/kunden')->waitForText('ACME GmbH', 15);
            $this->assertFalse($browser->driver->findElement(WebDriverBy::xpath("//tr[contains(., 'ACME GmbH')]//button[contains(., 'Details')]"))->isDisplayed());
            $browser->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'ACME GmbH')]//button[@aria-label='Bearbeiten']")
                ->waitFor($this->input('firma'), 15)
                ->clear($this->input('firma'))->type($this->input('firma'), 'ACME AG');
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->waitForText('ACME AG', 15)->waitUntilMissing('.fi-modal-window', 15);

            $browser->clickAtXPath("//tr[contains(@class, 'fi-ta-row')][contains(., 'ACME AG')]//button[@aria-label='Löschen']")
                ->waitFor('.fi-modal-window', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitUntilMissingText('ACME AG', 15);
        });

        $this->assertTrue(DynamicRecord::forTable($this->kunden)->newQuery()->withTrashed()->find($this->acme->id)->trashed());
    }

    /** EIN-22 (B-174): Verlauf lesbar – Bezeichnungen und Werte wie in der Anwendung, je Feld eine Zeile. */
    public function test_history_shows_labels_and_readable_values(): void
    {
        $alpha = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Alpha AG']);
        $beta = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Beta GmbH']);
        $record = app(RecordWriter::class)->create($this->daten, ['titel' => 'Verlauf', 'status' => 'wip', 'kunde_id' => $alpha->getKey(), 'aktiv' => false], WriteOptions::ui());
        app(RecordWriter::class)->update($record->refresh(), ['status' => 'done', 'kunde_id' => $beta->getKey(), 'aktiv' => true], WriteOptions::ui());

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/daten')->waitForText('Verlauf', 15);
            $this->mobile($browser)->clickAtXPath("//tr[contains(@class, 'fi-ta-row')]//button[contains(., 'Details')]")->waitForText('Schliessen', 15)->pause(300);
            $this->clickVisibleButton($browser, 'Verlauf')
                ->waitFor('.saft-history', 15)
                ->assertSeeIn('.saft-history', 'Status: In Arbeit → Erledigt')
                ->assertSeeIn('.saft-history', 'Kunde: Alpha AG → Beta GmbH')
                ->assertSeeIn('.saft-history', 'Aktiv: Nein → Ja')
                ->assertDontSeeIn('.saft-history', 'kunde_id');
        });
    }
}
