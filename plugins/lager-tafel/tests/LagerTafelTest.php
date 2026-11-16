<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Tests;

use App\Filament\Pages\Dashboard;
use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use App\Plugins\Testing\PluginTestCase;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Saft\LagerTafel\ArticleNumber;
use Saft\LagerTafel\LagerTafelPlugin;
use Saft\LagerTafel\Pages\Lagerspiegel;
use Saft\LagerTafel\Pages\Lagertafel;
use Saft\LagerTafel\Widgets\StockByLocationChart;

/** Beispiel-Plugin „Lager-Tafel“: je Baustein aus Anhang A des Entwicklerhandbuchs ein Test. */
class LagerTafelTest extends PluginTestCase
{
    private int $screw;

    private int $nail;

    private int $north;

    private int $south;

    /** Lager-Demo im Kleinen: zwei Artikel, zwei Lagerorte; Bestand entsteht über Lagerbewegungen (Plugin lager-umbuchung). */
    private function demoTables(): void
    {
        $this->createTable('lager_artikel', 'Artikel', [
            ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string', 'options' => ['length' => 20], 'validation_rules' => ['checks' => ['lager-tafel.artikelnummer']]],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'options' => ['length' => 100]],
            ['name' => 'mindestbestand', 'label' => 'Mindestbestand', 'type' => 'integer'],
        ]);
        $this->createTable('lager_orte', 'Lagerorte', [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'options' => ['length' => 100]],
        ]);
        $this->createTable('lager_bestaende', 'Bestände', [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'lager_artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'lagerort_id', 'label' => 'Lagerort', 'type' => 'relation', 'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung']],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer', 'validation_rules' => ['min' => 0]],
            ['name' => 'letzte_zaehlung', 'label' => 'Letzte Zählung', 'type' => 'date'],
        ]);
        $this->createTable('lager_bewegungen', 'Lagerbewegungen', [
            ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'lager_artikel', 'display_field' => 'bezeichnung']],
            ['name' => 'lagerort_id', 'label' => 'Lagerort', 'type' => 'relation', 'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung']],
            ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['options' => [['value' => 'Eingang'], ['value' => 'Ausgang'], ['value' => 'Umbuchung'], ['value' => 'Inventur']]]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'zeitpunkt', 'label' => 'Zeitpunkt', 'type' => 'datetime'],
            ['name' => 'bemerkung', 'label' => 'Bemerkung', 'type' => 'text'],
        ]);

        $articles = Saft::table('lager_artikel')->withoutAuthorization();
        $this->screw = (int) $articles->create(['artikelnummer' => 'A-1001', 'bezeichnung' => 'Schraube', 'mindestbestand' => 8])->getKey();
        $this->nail = (int) $articles->create(['artikelnummer' => 'A-1002', 'bezeichnung' => 'Nagel', 'mindestbestand' => 2])->getKey();
        $locations = Saft::table('lager_orte')->withoutAuthorization();
        $this->north = (int) $locations->create(['bezeichnung' => 'Nord'])->getKey();
        $this->south = (int) $locations->create(['bezeichnung' => 'Süd'])->getKey();

        $this->receive($this->screw, $this->north, 10);
        $this->receive($this->nail, $this->south, 1);
    }

    private function receive(int $article, int $location, int $quantity): void
    {
        Saft::table('lager_bewegungen')->withoutAuthorization()->create([
            'artikel_id' => $article, 'lagerort_id' => $location, 'art' => 'Eingang', 'menge' => $quantity, 'zeitpunkt' => '2026-11-01 08:00:00',
        ]);
    }

    private function stock(int $article, int $location): int
    {
        return (int) DynamicRecord::forTableName('lager_bestaende')->newQuery()
            ->where('artikel_id', $article)->where('lagerort_id', $location)->sum('menge');
    }

    private function article(int $id): DynamicRecord
    {
        return DynamicRecord::forTableName('lager_artikel')->newQuery()->findOrFail($id);
    }

    // A.1 – eigene Tabellensicht

    public function test_stock_matrix_shows_one_column_per_location(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();
        app()->setLocale('de');

        Livewire::test(Lagerspiegel::class)
            ->assertCanSeeTableRecords([$this->article($this->screw), $this->article($this->nail)])
            ->assertTableColumnExists('lagerort_'.$this->north, fn ($column): bool => $column->getLabel() === 'Nord')
            ->assertTableColumnStateSet('lagerort_'.$this->north, 10, $this->screw)
            ->assertTableColumnStateSet('lagerort_'.$this->south, 0, $this->screw)
            ->assertTableColumnStateSet('gesamt', 1, $this->nail)
            ->assertTableActionVisible('verteilen', $this->screw)
            ->searchTable('Nagel')
            ->assertCanSeeTableRecords([$this->article($this->nail)])
            ->assertCanNotSeeTableRecords([$this->article($this->screw)])
            ->searchTable('')
            ->filterTable('unter_mindestbestand')
            ->assertCanSeeTableRecords([$this->article($this->nail)])
            ->assertCanNotSeeTableRecords([$this->article($this->screw)]);
    }

    public function test_stock_matrix_needs_permission_data_rights_and_the_demo(): void
    {
        $this->actingWithPermissions([]);
        $this->get(Lagerspiegel::getUrl())->assertForbidden();

        $this->actingWithPermissions(['lager-tafel.view']);
        app()->setLocale('de');
        Livewire::test(Lagerspiegel::class)->assertSee('Lager-Demo nicht vollständig')->assertSee('Tabelle lager_artikel fehlt');

        $this->demoTables();
        Livewire::test(Lagerspiegel::class)->assertSee('Ihnen fehlt das Recht')->assertDontSee('Schraube');

        $this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view', 'data.lager_orte.view', 'data.lager_bestaende.view']);
        $this->get(Lagerspiegel::getUrl())->assertOk()->assertSee('Schraube');
        Livewire::test(Lagerspiegel::class)->assertTableActionHidden('verteilen', $this->screw);
    }

    // A.2 – eigenes Bearbeiten-Formular mit Drag and Drop

    public function test_dropping_on_another_location_opens_the_dialog_and_transfers(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();
        app()->setLocale('de');

        Livewire::withQueryParams(['artikel' => $this->screw])->test(Lagertafel::class)
            ->assertFormSet(['artikel' => $this->screw])
            ->assertSeeHtml('wire:sort:group-id="'.$this->south.'"')
            ->assertSeeHtml('wire:sort:item="'.$this->north.'"')
            ->call('drop', (string) $this->north, 0, (string) $this->north)
            ->assertActionNotMounted('transfer')
            ->call('drop', (string) $this->north, 0, (string) $this->south)
            ->assertActionMounted(TestAction::make('transfer')->arguments(['from' => $this->north, 'to' => $this->south]))
            ->assertSchemaStateSet(['menge' => 10])
            ->fillForm(['menge' => 11])
            ->callMountedAction()
            ->assertHasFormErrors(['menge' => 'max'])
            ->fillForm(['menge' => 4])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('4 Stück von Nord nach Süd umgebucht.');

        $this->assertSame([6, 4], [$this->stock($this->screw, $this->north), $this->stock($this->screw, $this->south)]);
    }

    public function test_board_needs_the_transfer_permission_and_data_rights(): void
    {
        $this->demoTables();
        $this->actingWithPermissions(['lager-tafel.view']);
        $this->get(Lagertafel::getUrl())->assertForbidden();

        // Ohne Recht „Bestände bearbeiten“: Die Tafel zeigt den Bestand, die Buchung meldet die fehlenden Rechte.
        $this->actingWithPermissions(['lager-tafel.transfer', 'data.lager_artikel.view', 'data.lager_orte.view', 'data.lager_bestaende.view', 'data.lager_bewegungen.create']);
        app()->setLocale('de');
        Livewire::withQueryParams(['artikel' => $this->screw])->test(Lagertafel::class)
            ->callAction(TestAction::make('transfer')->arguments(['from' => $this->north, 'to' => $this->south]), ['menge' => 1])
            ->assertNotified('Ihnen fehlen Datenrechte für: Bestände');

        $this->assertSame(10, $this->stock($this->screw, $this->north));
    }

    public function test_board_rests_while_stock_transfer_is_switched_off(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();
        app()->setLocale('de');

        // Lager-Tafel bucht über Lager-Umbuchung (composer.json): Ist es ausgeschaltet, ruht die Tafel mit.
        $this->withoutPlugin('lager-umbuchung', function (): void {
            Livewire::withQueryParams(['artikel' => $this->screw])->test(Lagertafel::class)
                ->assertSee('Plugin Lager-Umbuchung ist ausgeschaltet')
                ->assertDontSeeHtml('wire:sort:group-id');
        });

        Livewire::withQueryParams(['artikel' => $this->screw])->test(Lagertafel::class)->assertSeeHtml('wire:sort:group-id');
    }

    // A.3 – Widget

    public function test_dashboard_chart_sums_stock_per_location(): void
    {
        $this->actingAsSuperAdmin();
        $this->assertFalse(StockByLocationChart::canView(), 'Ohne Lager-Demo.');

        $this->demoTables();
        $this->assertTrue(StockByLocationChart::canView());
        $this->receive($this->nail, $this->north, 5);

        $data = (fn (): array => $this->getData())->call(new StockByLocationChart);
        $this->assertSame(['Nord', 'Süd'], $data['labels']);
        $this->assertSame([15, 1], $data['datasets'][0]['data']);

        app()->setLocale('de');
        $key = 'plugin:Saft.LagerTafel.Widgets.StockByLocationChart';
        $this->assertSame('Lager: Bestand je Lagerort', Livewire::test(Dashboard::class)->instance()->cards()[$key]['label'] ?? null);

        $this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view']);
        $this->assertFalse(StockByLocationChart::canView(), 'Ohne Datenrecht auf Lagerorte und Bestände.');
    }

    // A.4 – Aktion in einer bestehenden Tabellensicht

    public function test_buttons_in_the_article_data_view(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();

        $this->dataView('lager_artikel')
            ->assertActionVisible(TestAction::make('lagerTafelSpiegel')->table())
            ->assertActionHasUrl(TestAction::make('lagerTafelSpiegel')->table(), Lagerspiegel::getUrl())
            ->assertActionVisible(TestAction::make('lagerTafelVerteilen')->table($this->article($this->screw)))
            ->assertActionHasUrl(TestAction::make('lagerTafelVerteilen')->table($this->article($this->screw)), Lagertafel::getUrl(['artikel' => $this->screw]));

        $this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view']);
        $this->dataView('lager_artikel')
            ->assertActionVisible(TestAction::make('lagerTafelSpiegel')->table())
            ->assertActionHidden(TestAction::make('lagerTafelVerteilen')->table($this->article($this->screw)));
    }

    // A.5 – Listener

    public function test_article_number_is_stored_in_one_spelling(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();

        $article = Saft::table('lager_artikel')->create(['artikelnummer' => ' b-2001 ', 'bezeichnung' => 'Dübel']);
        $this->assertSame('B-2001', $article->fresh()->getAttribute('artikelnummer'));

        Saft::table('lager_artikel')->update($article, ['artikelnummer' => 'c-3001']);
        $this->assertSame('C-3001', $article->fresh()->getAttribute('artikelnummer'));
    }

    public function test_article_number_is_locked_once_movements_exist(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();
        app()->setLocale('de');

        try {
            Saft::table('lager_artikel')->update($this->screw, ['artikelnummer' => 'A-9999']);
            $this->fail('Artikelnummer trotz Lagerbewegungen geändert.');
        } catch (RecordRejected $e) {
            $this->assertSame('Die Artikelnummer A-1001 ist in Lagerbewegungen verwendet und kann nicht mehr geändert werden.', $e->getMessage());
        }

        Saft::table('lager_artikel')->update($this->screw, ['artikelnummer' => 'a-1001', 'bezeichnung' => 'Schraube M4']);
        $this->assertSame(['A-1001', 'Schraube M4'], [
            $this->article($this->screw)->getAttribute('artikelnummer'),
            $this->article($this->screw)->getAttribute('bezeichnung'),
        ], 'Andere Schreibweise derselben Nummer und andere Felder bleiben änderbar.');
    }

    // A.6 – Prüfregel

    public function test_article_number_rule(): void
    {
        $this->assertTrue(ArticleNumber::isValid('A-1001'));
        $this->assertTrue(ArticleNumber::isValid(' a-1001'), 'Gültig nach dem Vereinheitlichen.');
        $this->assertFalse(ArticleNumber::isValid('A1001'));
        $this->assertFalse(ArticleNumber::isValid('AB-1001'));
        $this->assertFalse(ArticleNumber::isValid('A-100'));

        $this->actingAsSuperAdmin();
        $this->demoTables();
        app()->setLocale('de');

        try {
            Saft::table('lager_artikel')->create(['artikelnummer' => '1001', 'bezeichnung' => 'Falsch']);
            $this->fail('Ungültige Artikelnummer angenommen.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Artikelnummer im Format A-1234 erwartet', implode(' ', $e->errors()['artikelnummer'] ?? []));
        }
    }

    // A.7 – Menüvorschläge und Rechte

    public function test_permissions_and_menu_suggestions(): void
    {
        $plugin = Saft::plugin(LagerTafelPlugin::ID);
        $this->assertSame(['lager-tafel.view', 'lager-tafel.transfer'], $plugin->permissionNames());

        $suggestions = $plugin->menuSuggestions();
        $this->assertSame([Lagerspiegel::ROUTE, Lagertafel::ROUTE], array_map(static fn ($s): string => $s->route, $suggestions));
        $this->assertSame(['lager-tafel.view', 'lager-tafel.transfer'], array_map(static fn ($s): ?string => $s->permission, $suggestions));
        $this->assertSame('Lager (Demo)', $suggestions[0]->group['de'] ?? null);

        $this->actingWithPermissions(['lager-tafel.transfer']);
        $this->get(Lagertafel::getUrl())->assertOk();
    }
}
