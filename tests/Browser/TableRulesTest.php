<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Table-Designer, eigene Regeln auf Tabellenebene (Bereich TDE). */
class TableRulesTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string MODAL = 'mountedActionSchema0.';

    private const string RULE = '[id^="form.validation_rules."]';

    private CustomTable $orders;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin();
        $this->orders = app(DynamicSchemaService::class)->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true],
            ['name' => 'bestelldatum', 'label' => 'Bestelldatum', 'type' => 'date'],
            ['name' => 'lieferdatum', 'label' => 'Lieferdatum', 'type' => 'date'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);
        app(DataMenu::class)->ensure($this->orders);
    }

    private function addRule(Browser $browser, string $left, string $operator): Browser
    {
        $this->clickVisibleButton($browser, 'Regel hinzufügen')->waitFor('select'.self::RULE.'[id$=".left"]', 15)
            ->select('select'.self::RULE.'[id$=".left"]', $left)->pause(600)
            ->select('select'.self::RULE.'[id$=".operator"]', $operator);

        return $browser;
    }

    private function newOrder(Browser $browser, string $number, ?string $ordered, ?string $delivered, ?string $quantity): Browser
    {
        $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="'.self::MODAL.'nummer"]', 15)
            ->type('input[id="'.self::MODAL.'nummer"]', $number);

        foreach (['bestelldatum' => $ordered, 'lieferdatum' => $delivered] as $field => $date) {
            if ($date !== null) {
                $browser->keys('input[id="'.self::MODAL.$field.'"]', $date);
            }
        }

        if ($quantity !== null) {
            $browser->type('input[id="'.self::MODAL.'menge"]', $quantity);
        }

        return $this->clickVisibleButton($browser, 'Erstellen');
    }

    /** TDE-19 */
    public function test_comparison_rule_is_created_and_checked_in_the_data_form(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$this->orders->id}/edit")->waitForText('Regeln', 15);
            $this->addRule($browser, 'lieferdatum', '>=')
                ->select('select'.self::RULE.'[id$=".right_field"]', 'bestelldatum')
                ->press('Speichern')->waitForText('Gespeichert', 15)
                ->assertSee('Lieferdatum ≥ Bestelldatum');

            $browser->visit('/admin/data/bestellungen')->waitForText('Neuer Datensatz', 15);
            $this->newOrder($browser, 'B-1', '10052026', '01052026', '2')
                ->waitForText('darf nicht vor „Bestelldatum“ liegen.', 15);
        });

        $this->assertSame(0, DynamicRecord::forTable($this->orders)->newQuery()->count());
        $this->assertSame('lieferdatum', $this->orders->fresh()->tableRules()[0]->left->fieldName());
    }

    /** TDE-20 */
    public function test_fixed_value_rule_with_own_message_warns_about_existing_records(): void
    {
        DynamicRecord::forTable($this->orders)->create(['nummer' => 'B-0', 'menge' => 0]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$this->orders->id}/edit")->waitForText('Regeln', 15);
            $this->addRule($browser, 'menge', '>');
            $browser->click('button'.self::RULE.'[id$=".right_kind-value"]')
                ->waitFor('input'.self::RULE.'[id$=".right_value"]', 15)
                ->type('input'.self::RULE.'[id$=".right_value"]', '0')
                ->type('input'.self::RULE.'[id$=".message.de"]', 'Bitte mindestens 1 bestellen.')
                ->press('Speichern')
                ->waitForText('Vorhandene Datensätze verletzen Regeln', 15)
                ->assertSee('1 Datensatz verletzt „Menge > 0“.');

            $browser->visit('/admin/data/bestellungen')->waitForText('B-0', 15);
            $this->newOrder($browser, 'B-2', null, null, '0')->waitForText('Bitte mindestens 1 bestellen.', 15);
        });

        $this->assertSame(1, DynamicRecord::forTable($this->orders)->newQuery()->count());
    }

    /** TDE-21 */
    public function test_next_position_is_suggested_below_an_order(): void
    {
        $schema = app(DynamicSchemaService::class);
        $order = DynamicRecord::forTable($this->orders)->create(['nummer' => 'B-7']);
        $lines = $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
            ['name' => 'position', 'label' => 'Position', 'type' => 'integer'],
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string'],
        ]);
        app(DataMenu::class)->ensure($lines);
        $relation = (int) $lines->fields()->where('name', 'bestellung_id')->value('id');
        $schema->updateTable($this->orders->fresh(), ['detail_field_ids' => [$relation]]);

        foreach ([1, 2] as $position) {
            DynamicRecord::forTable($lines)->create(['bestellung_id' => $order->id, 'position' => $position, 'artikel' => "Teil {$position}"]);
        }

        $this->browse(function (Browser $browser) use ($lines): void {
            // Im Table-Designer einschalten: fortlaufend, gezählt je Bestellung.
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$lines->id}/edit")->waitForText('Regeln', 15);
            $this->scrollThroughPage($browser);
            // Die Feldliste lädt erst, wenn sie sichtbar ist.
            $browser->waitUsing(15, 200, fn (): bool => count($browser->driver->findElements(WebDriverBy::xpath("//tr[contains(@class, 'fi-ta-row')][contains(., 'position')]"))) > 0);
            $this->scrollThroughPage($browser);
            $this->rowAction($browser, 'position', 'Bearbeiten');
            $browser->waitFor('[id="mountedActionSchema0.options.suggest_next"]', 15)
                ->click('[id="mountedActionSchema0.options.suggest_next"]')
                ->waitFor('select[id="mountedActionSchema0.options.suggest_scope"]', 15)
                ->select('select[id="mountedActionSchema0.options.suggest_scope"]', 'bestellung_id');
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->pause(1500);

            // Unter der Bestellung: Vorschlag 3.
            $browser->visit('/admin/data/bestellungen')->waitForText('B-7', 15);
            $this->clickRow($browser, 'B-7');
            $browser->waitForText('Teil 2', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="'.self::MODAL.'position"]', 15);
            $this->assertSame('3', $browser->value('input[id="'.self::MODAL.'position"]'));
            $browser->type('input[id="'.self::MODAL.'artikel"]', 'Teil 3');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Teil 3', 15);
        });

        $this->assertSame(3, (int) DynamicRecord::forTable($lines->fresh('fields'))->newQuery()->where('artikel', 'Teil 3')->value('position'));
    }

    /** TDE-22 */
    public function test_unit_price_is_copied_from_the_chosen_item(): void
    {
        $schema = app(DynamicSchemaService::class);
        $items = $schema->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
        $lines = $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'einzelpreis', 'label' => 'Einzelpreis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
        app(DataMenu::class)->ensure($lines);
        DynamicRecord::forTable($items)->create(['bezeichnung' => 'Schraube', 'preis' => 0.35]);
        DynamicRecord::forTable($items)->create(['bezeichnung' => 'Mutter', 'preis' => 0.20]);

        $this->browse(function (Browser $browser) use ($lines): void {
            // Im Table-Designer: Einzelpreis übernimmt Artikel → Preis.
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$lines->id}/edit")->waitForText('Regeln', 15);
            $this->scrollThroughPage($browser);
            $browser->waitUsing(15, 200, fn (): bool => count($browser->driver->findElements(WebDriverBy::xpath("//tr[contains(@class, 'fi-ta-row')][contains(., 'einzelpreis')]"))) > 0);
            $this->rowAction($browser, 'einzelpreis', 'Bearbeiten');
            $browser->waitFor('select[id="'.self::MODAL.'options.copy_from"]', 15)
                ->select('select[id="'.self::MODAL.'options.copy_from"]', 'artikel_id.preis');
            $this->clickVisibleButton($browser, 'Speichern');
            $browser->pause(1500);

            // Neue Position: Preis erscheint mit dem Artikel und folgt dem Wechsel.
            $browser->visit('/admin/data/positionen')->waitForText('Neuer Datensatz', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="'.self::MODAL.'einzelpreis"]', 15);
            $this->pickOption($browser, self::MODAL.'artikel_id', 'Schraube');
            $browser->pause(1500);
            // Zahlenfeld: der Browser zeigt z. B. 0.20 als „0.2“.
            $this->assertSame(0.35, (float) $browser->value('input[id="'.self::MODAL.'einzelpreis"]'));
            $this->pickOption($browser, self::MODAL.'artikel_id', 'Mutter');
            $browser->pause(1500);
            $this->assertSame(0.2, (float) $browser->value('input[id="'.self::MODAL.'einzelpreis"]'));
            // „Mutter“ steht schon in der Auswahl des Formulars: erst warten, bis es gespeichert und geschlossen ist.
            $this->clickVisibleButton($browser, 'Erstellen')
                ->waitUntilMissing('input[id="'.self::MODAL.'einzelpreis"]', 15)
                ->waitForText('Mutter', 15);
        });

        $this->assertSame('0.20', (string) DynamicRecord::forTable($lines->fresh('fields'))->newQuery()->value('einzelpreis'));
    }

    /** TDE-23 */
    public function test_computed_amount_is_calculated_when_saving(): void
    {
        $schema = app(DynamicSchemaService::class);
        $lines = $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'einzelpreis', 'label' => 'Einzelpreis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'round(menge * einzelpreis, 2)']],
        ]);
        app(DataMenu::class)->ensure($lines);

        $this->browse(function (Browser $browser) use ($lines): void {
            // Falsche Formel: Meldung mit Stelle, nichts gespeichert.
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$lines->id}/edit")->waitForText('Regeln', 15);
            $this->scrollThroughPage($browser);
            $browser->waitUsing(15, 200, fn (): bool => count($browser->driver->findElements(WebDriverBy::xpath("//tr[contains(@class, 'fi-ta-row')][contains(., 'betrag')]"))) > 0);
            $this->rowAction($browser, 'betrag', 'Bearbeiten');
            $formula = 'textarea[id="'.self::MODAL.'options.formula"]';
            $browser->waitFor($formula, 15)->clear($formula)->type($formula, 'menge * preis')->keys($formula, '{tab}')
                ->waitForText('Zeichen 9: Unbekanntes Feld „preis“.', 15);
            $this->clickVisibleButton($browser, 'Abbrechen');

            // Im Formular nur angezeigt; nach dem Speichern berechnet.
            $browser->visit('/admin/data/positionen')->waitForText('Neuer Datensatz', 15);
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="'.self::MODAL.'menge"]', 15);
            $this->assertNotNull($browser->element('input[id="'.self::MODAL.'betrag"]')->getAttribute('disabled'));
            $browser->type('input[id="'.self::MODAL.'artikel"]', 'Schraube')
                ->type('input[id="'.self::MODAL.'menge"]', '3')
                ->type('input[id="'.self::MODAL.'einzelpreis"]', '1.15');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Schraube', 15)->waitForText('3,45', 15);
        });

        $this->assertSame('round(menge * einzelpreis, 2)', $lines->fields()->where('name', 'betrag')->first()->optionsArray()['formula']);
    }

    /** TDE-24 */
    public function test_order_total_follows_its_lines(): void
    {
        $schema = app(DynamicSchemaService::class);
        $order = DynamicRecord::forTable($this->orders)->create(['nummer' => 'B-9']);
        $lines = $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'bestellung_id', 'label' => 'Bestellung', 'type' => 'relation', 'options' => ['table' => 'bestellungen', 'display_field' => 'nummer']],
            ['name' => 'artikel', 'label' => 'Artikel', 'type' => 'string'],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'einzelpreis', 'label' => 'Einzelpreis', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'menge * einzelpreis']],
        ]);
        $schema->addField($this->orders->fresh(), ['name' => 'gesamtpreis', 'label' => 'Gesamtpreis', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'sum(positionen.betrag)']]);
        $schema->updateTable($this->orders->fresh(), ['detail_field_ids' => [(int) $lines->fields()->where('name', 'bestellung_id')->value('id')]]);
        DynamicRecord::forTable($lines->fresh('fields'))->create(['bestellung_id' => $order->id, 'artikel' => 'Schraube', 'menge' => 4, 'einzelpreis' => 2.5]);

        $this->browse(function (Browser $browser): void {
            // Zusammenfassung über einen Ausdruck je Position, im Table-Designer angelegt und geprüft.
            $this->login($browser, self::ADMIN)->visit("/admin/table-designer/{$this->orders->id}/edit")->waitForText('Regeln', 15);
            $this->scrollThroughPage($browser);
            $this->clickVisibleButton($browser, 'Feld hinzufügen')->waitFor('input[id="'.self::MODAL.'label.de"]', 15)
                ->type('input[id="'.self::MODAL.'label.de"]', 'Warenwert')
                ->keys('input[id="'.self::MODAL.'label.de"]', '{tab}')
                ->select('select[id="'.self::MODAL.'type"]', 'computed')
                ->waitFor('textarea[id="'.self::MODAL.'options.formula"]', 15)
                ->type('textarea[id="'.self::MODAL.'options.formula"]', 'sum(positionen.einzelpreis * positionen.menge)')
                ->keys('textarea[id="'.self::MODAL.'options.formula"]', '{tab}')
                ->waitForText('hängt ab von: positionen.einzelpreis, positionen.menge', 15);
            $this->clickVisibleButton($browser, 'Erstellen');
            $browser->pause(1500);

            $browser->visit('/admin/data/bestellungen')->waitForText('B-9', 15)->assertSee('10,00');
            $this->clickRow($browser, 'B-9');
            $browser->waitForText('Schraube', 15);

            // Neue Position unter der Bestellung: Gesamtpreis steigt.
            $this->clickVisibleButton($browser, 'Neuer Datensatz')->waitFor('input[id="'.self::MODAL.'menge"]', 15)
                ->type('input[id="'.self::MODAL.'artikel"]', 'Mutter')
                ->type('input[id="'.self::MODAL.'menge"]', '3')
                ->type('input[id="'.self::MODAL.'einzelpreis"]', '1.5');
            $this->clickVisibleButton($browser, 'Erstellen')->waitForText('Mutter', 15);
            $browser->refresh()->waitForText('B-9', 15)->waitForText('14,50', 15);

            // Position löschen: Gesamtpreis sinkt.
            $browser->waitForText('Mutter', 15);
            $this->openDetails($browser, 'Mutter');
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->pause(800);
            $this->clickVisibleButton($browser, 'Löschen');
            $browser->waitUntilMissingText('Mutter', 15)->refresh()->waitForText('B-9', 15)->waitForText('10,00', 15);
        });

        $this->assertSame('10.00', DynamicRecord::forTableName('bestellungen')->newQuery()->whereKey($order->id)->value('gesamtpreis'));
        $this->assertSame('10.00', DynamicRecord::forTableName('bestellungen')->newQuery()->whereKey($order->id)->value('warenwert'));
    }
}
