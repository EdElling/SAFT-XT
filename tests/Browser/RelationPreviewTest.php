<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Facebook\WebDriver\WebDriverBy;
use Laravel\Dusk\Browser;
use Spatie\Permission\PermissionRegistrar;
use Tests\DuskTestCase;

/** Vorschau verknüpfter Datensätze (Bereich VOR). */
class RelationPreviewTest extends DuskTestCase
{
    private const string ADMIN = 'admin@example.com';

    private const string CELL = "//td[contains(@class, 'artikel-id')]//*[self::a or self::button]";

    private CustomTable $artikel;

    private CustomTable $positionen;

    private DynamicRecord $mutter;

    protected function setUp(): void
    {
        parent::setUp();

        $schema = app(DynamicSchemaService::class);
        $this->artikel = $schema->createTable(['name' => 'artikel', 'label' => 'Artikel'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'is_required' => true],
            ['name' => 'lager', 'label' => 'Lager', 'type' => 'string'],
        ]);
        $this->positionen = $schema->createTable(['name' => 'positionen', 'label' => 'Positionen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string', 'is_required' => true],
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'artikel', 'display_field' => 'bezeichnung']],
        ]);
        $this->mutter = DynamicRecord::forTable($this->artikel)->create(['bezeichnung' => 'Mutter M8', 'lager' => 'Halle 3']);
        DynamicRecord::forTable($this->positionen)->create(['nummer' => 'P-1', 'artikel_id' => $this->mutter->id]);

        app(DataMenu::class)->ensure($this->artikel);
        app(DataMenu::class)->ensure($this->positionen);
    }

    private function reader(array $rights): void
    {
        $this->activeUser('leser@example.com')->givePermissionTo($rights);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** VOR-01 */
    public function test_click_on_linked_value_opens_preview_without_selecting_the_row(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/positionen')->waitForText('Mutter M8', 15)
                ->clickAtXPath(self::CELL)
                ->waitForText('Halle 3', 15)
                ->assertSee('Artikel: Mutter M8')
                ->assertPathIs('/admin/data/positionen');

            $this->assertSame([], $this->selectedRows($browser));
        });
    }

    /** VOR-02 */
    public function test_switch_button_opens_the_target_table_with_record_selected(): void
    {
        $this->superAdmin();

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/positionen')->waitForText('Mutter M8', 15)
                ->clickAtXPath(self::CELL)
                ->waitForText('Zu „Artikel“ wechseln', 15)
                ->clickAtXPath("//*[self::a or self::button][contains(., 'Zu „Artikel“ wechseln')]")
                ->waitForLocation('/admin/data/artikel', 15)
                ->waitFor('tr.saft-row-selected', 15)
                ->assertSeeIn('tr.saft-row-selected', 'Mutter M8')
                ->assertSeeIn('.fi-sidebar-item.fi-active', 'Artikel');
        });
    }

    /** VOR-03 */
    public function test_without_read_right_the_name_is_text_and_click_selects_the_row(): void
    {
        $this->reader([DataPermissions::name('positionen', 'view')]);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, 'leser@example.com')->visit('/admin/data/positionen')->waitForText('Mutter M8', 15)
                ->clickAtXPath("//td[contains(@class, 'artikel-id')]")
                ->waitFor('tr.saft-row-selected', 15)
                ->assertDontSee('Halle 3')
                ->assertDontSee('wechseln');
        });
    }

    /** VOR-04 */
    public function test_empty_link_shows_a_dash_and_no_preview(): void
    {
        $this->superAdmin();
        DynamicRecord::forTable($this->positionen)->create(['nummer' => 'P-LEER']);

        $this->browse(function (Browser $browser): void {
            $this->login($browser, self::ADMIN)->visit('/admin/data/positionen')->waitForText('P-LEER', 15);
            $cell = "//tr[contains(@class, 'fi-ta-row')][contains(., 'P-LEER')]//td[contains(@class, 'artikel-id')]";

            $this->assertSame('—', trim($browser->driver->findElement(WebDriverBy::xpath($cell))->getText()));

            $browser->clickAtXPath($cell)->pause(800)->assertDontSee('Schliessen')->assertDontSee('Unerwarteter Fehler');
        });
    }

    /** VOR-05: Die Löschsperre wird umgangen, um den sonst seltenen Zustand herzustellen. */
    public function test_deleted_target_depends_on_the_delete_right(): void
    {
        $this->superAdmin();
        // Direkt in der Tabelle, am Writer und damit an der Löschsperre vorbei (Fundament 2, F1).
        DynamicRecord::forTable($this->artikel)->newQuery()->whereKey($this->mutter->id)->toBase()->update(['deleted_at' => now()]);
        $this->reader([DataPermissions::name('positionen', 'view'), DataPermissions::name('artikel', 'view')]);

        $this->browse(function (Browser $admin, Browser $reader): void {
            $this->login($admin, self::ADMIN)->visit('/admin/data/positionen')->waitForText('Mutter M8', 15)
                ->clickAtXPath(self::CELL)
                ->waitForText('Dieser Datensatz ist gelöscht', 15)
                ->assertSee('Halle 3');

            $this->login($reader, 'leser@example.com')->visit('/admin/data/positionen')->waitForText('Mutter M8', 15)
                ->clickAtXPath(self::CELL)
                ->waitForText('nicht (mehr) verfügbar', 15)
                ->assertDontSee('Halle 3');
        });
    }
}
