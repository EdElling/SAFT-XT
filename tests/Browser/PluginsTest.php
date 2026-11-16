<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/** Seite „Plugins“ und das Beispiel-Plugin „Lager-Umbuchung“. */
class PluginsTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private int $article;

    private int $north;

    private int $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superAdmin();

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'lager_artikel', 'label' => 'Artikel'], [
            ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
        ]);
        $schema->createTable(['name' => 'lager_orte', 'label' => 'Lagerorte'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
        ]);
        $schema->createTable(['name' => 'lager_bestaende', 'label' => 'Bestände'], [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'lager_artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'lagerort_id', 'label' => 'Lagerort', 'type' => 'relation', 'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
        ]);
        $schema->createTable(['name' => 'lager_bewegungen', 'label' => 'Lagerbewegungen'], [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'lager_artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'lagerort_id', 'label' => 'Lagerort', 'type' => 'relation', 'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung']],
            ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['options' => [['value' => 'Eingang'], ['value' => 'Ausgang'], ['value' => 'Umbuchung'], ['value' => 'Inventur']]]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'zeitpunkt', 'label' => 'Zeitpunkt', 'type' => 'datetime'],
            ['name' => 'bemerkung', 'label' => 'Bemerkung', 'type' => 'text'],
        ]);

        $this->article = (int) DynamicRecord::forTableName('lager_artikel')->create(['artikelnummer' => 'A-1', 'bezeichnung' => 'Schraube'])->getKey();
        $this->north = (int) DynamicRecord::forTableName('lager_orte')->create(['bezeichnung' => 'Nord'])->getKey();
        $this->south = (int) DynamicRecord::forTableName('lager_orte')->create(['bezeichnung' => 'Süd'])->getKey();
        // Bestände nur über Bewegungen (Plugin): Eingang 10 im Lager Nord.
        Saft::table('lager_bewegungen')->withoutAuthorization()->create(['artikel_id' => $this->article, 'lagerort_id' => $this->north, 'art' => 'Eingang', 'menge' => 10, 'zeitpunkt' => now()]);
    }

    /** PLG-01, PLG-02 */
    public function test_menu_entries_are_adopted_and_the_transfer_form_books(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)
                ->visit('/admin/plugins')
                ->waitForText('Lager-Umbuchung', 15)
                ->assertSee('lager-umbuchung')
                ->assertSee('0 von 1 übernommen')
                ->assertDontSeeIn(self::SELECTOR_SIDEBAR, 'Umbuchung');

            // Abhängigkeiten: Knopf in der Spalte öffnet die vollständige Aufstellung.
            $browser->assertSee('4 Tabellen vorhanden')
                ->assertSee('plugins/lager-umbuchung/src/LagerUmbuchungPlugin.php')
                ->click('[data-plugin-dependencies-button="lager-umbuchung"]')
                ->waitForText('Abhängigkeiten von „Lager-Umbuchung“', 15)
                ->assertPresent('[data-dependency-field="lager_bestaende.menge"]')
                ->assertDontSee(':count');
            $this->clickVisibleButton($browser, 'Schliessen')->pause(400);

            $this->clickVisibleButton($browser, 'Menüeinträge anlegen')->pause(400);
            $this->clickVisibleButton($browser, 'Bestätigen')
                ->waitForText('1 Menüeinträge angelegt.', 15)
                ->waitForTextIn(self::SELECTOR_SIDEBAR, 'Umbuchung', 15)
                ->assertSee('1 von 1 übernommen');

            // Über den neuen Menüeintrag zur Maske.
            $browser->clickLink('Umbuchung')
                ->waitForText('Von Lagerort', 15);
            $this->pickOption($browser, 'form.artikel', 'Schraube');
            $browser->waitUsing(15, 100, fn (): bool => in_array((string) $this->north, $this->selectOptions($browser, 'select[id="form.von"]'), true))
                ->assertSeeIn('select[id="form.von"]', 'Nord (Bestand: 10)')
                ->select('select[id="form.von"]', (string) $this->north)
                ->pause(500)
                ->assertSee('Verfügbar am Quellort: 10')
                ->select('select[id="form.nach"]', (string) $this->south)
                ->type('input[id="form.menge"]', '4')
                ->press('Umbuchen')
                ->waitForText('4 × A-1 – Schraube von Nord nach Süd umgebucht.', 15);
        });

        // Zeilenknopf „Umbuchen“ in Bestände öffnet die Maske mit Artikel und Quellort.
        $this->browse(function (Browser $browser): void {
            $browser->visit('/admin/data/lager_bestaende')
                ->waitFor('a[href*="lager-umbuchung?artikel='.$this->article.'&von='.$this->south.'"]', 15)
                ->click('a[href*="lager-umbuchung?artikel='.$this->article.'&von='.$this->south.'"]')
                ->waitForText('Von Lagerort', 15)
                ->assertSeeIn('select[id="form.von"] option:checked', 'Süd (Bestand: 4)');
        });

        $stock = fn (int $location): int => (int) DynamicRecord::forTableName('lager_bestaende')->newQuery()->where('artikel_id', $this->article)->where('lagerort_id', $location)->value('menge');
        $this->assertSame([6, 4], [$stock($this->north), $stock($this->south)]);
        $this->assertSame(2, DynamicRecord::forTableName('lager_bewegungen')->newQuery()->where('art', 'Umbuchung')->count());
    }

    /** @return list<string> */
    private function selectOptions(Browser $browser, string $selector): array
    {
        return array_map(
            static fn ($option): string => (string) $option->getAttribute('value'),
            $browser->driver->findElements(WebDriverBy::cssSelector($selector.' option')),
        );
    }
}
