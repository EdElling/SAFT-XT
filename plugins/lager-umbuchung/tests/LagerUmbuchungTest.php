<?php

declare(strict_types=1);

namespace Saft\LagerUmbuchung\Tests;

use App\Filament\Pages\Dashboard;
use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use App\Plugins\Testing\PluginTestCase;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Saft\LagerUmbuchung\Pages\Umbuchung;
use Saft\LagerUmbuchung\StockTransfer;
use Saft\LagerUmbuchung\TransferRejected;
use Saft\LagerUmbuchung\Widgets\MinimumStockWidget;
use Spatie\Activitylog\Models\Activity;

/** Beispiel-Plugin „Lager-Umbuchung“: Fachmaske, Bewegungen buchen den Bestand, Sperre, Mindestbestand. */
class LagerUmbuchungTest extends PluginTestCase
{
    private int $article;

    private int $north;

    private int $south;

    private function demoTables(int $minimum = 0): void
    {
        $this->createTable('lager_artikel', 'Artikel', [
            ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string', 'options' => ['length' => 20]],
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

        $this->article = (int) DynamicRecord::forTableName('lager_artikel')->create(['artikelnummer' => 'A-1', 'bezeichnung' => 'Schraube', 'mindestbestand' => $minimum])->getKey();
        $this->north = (int) DynamicRecord::forTableName('lager_orte')->create(['bezeichnung' => 'Nord'])->getKey();
        $this->south = (int) DynamicRecord::forTableName('lager_orte')->create(['bezeichnung' => 'Süd'])->getKey();
        $this->move('Eingang', $this->north, 10);
    }

    private function move(string $kind, int $location, int $quantity, string $time = '2026-11-01 08:00:00'): DynamicRecord
    {
        return Saft::table('lager_bewegungen')->withoutAuthorization()
            ->create(['artikel_id' => $this->article, 'lagerort_id' => $location, 'art' => $kind, 'menge' => $quantity, 'zeitpunkt' => $time]);
    }

    private function transfers(): StockTransfer
    {
        return app(StockTransfer::class);
    }

    /** @return array{0: int, 1: int} Bestand Nord und Süd */
    private function stocks(): array
    {
        return [$this->transfers()->stock($this->article, $this->north), $this->transfers()->stock($this->article, $this->south)];
    }

    /** @return list<array{0: int, 1: int}> Lagerort und Menge der Bewegungen */
    private function movements(): array
    {
        return DynamicRecord::forTableName('lager_bewegungen')->newQuery()->orderBy('id')->get()
            ->map(fn (DynamicRecord $m): array => [(int) $m->getAttribute('lagerort_id'), (int) $m->getAttribute('menge')])->all();
    }

    public function test_movements_book_the_stock(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();
        $this->assertSame([10, 0], $this->stocks(), 'Eingang legt den Bestand an.');

        $out = $this->move('Ausgang', $this->north, 4);
        $this->assertSame(-4, (int) $out->getAttribute('menge'), 'Ausgang immer negativ.');
        $this->assertSame([6, 0], $this->stocks());

        Saft::table('lager_bewegungen')->update($out, ['menge' => 3]);
        $this->assertSame([7, 0], $this->stocks(), 'Alter Wert zurückgenommen, neuer gebucht.');

        Saft::table('lager_bewegungen')->update($out, ['lagerort_id' => $this->south, 'art' => 'Eingang']);
        $this->assertSame([10, 3], $this->stocks(), 'Ort und Art gewechselt.');

        $out->delete();
        $this->assertSame([10, 0], $this->stocks());
        $out->restore();
        $this->assertSame([10, 3], $this->stocks());
        $out->delete();
        $out->forceDelete();
        $this->assertSame([10, 0], $this->stocks(), 'Endgültig löschen bucht nicht doppelt aus.');
    }

    public function test_negative_stock_and_direct_changes_are_rejected(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();

        try {
            $this->move('Ausgang', $this->north, 11);
            $this->fail('Negativer Bestand angenommen.');
        } catch (RecordRejected $e) {
            $this->assertStringContainsString('Nicht genug Bestand', $e->getMessage());
        }

        $stock = DynamicRecord::forTableName('lager_bestaende')->newQuery()->sole();

        foreach ([
            fn () => Saft::table('lager_bestaende')->update($stock, ['menge' => 1]),
            fn () => $stock->delete(),
            fn () => DynamicRecord::forTableName('lager_bestaende')->create(['artikel_id' => $this->article, 'lagerort_id' => $this->south, 'menge' => 5]),
        ] as $change) {
            try {
                $change();
                $this->fail('Direkte Änderung am Bestand angenommen.');
            } catch (RecordRejected $e) {
                $this->assertStringContainsString('über Lagerbewegungen geführt', $e->getMessage());
            }
        }

        $this->assertSame([10, 0], $this->stocks());
        $this->assertCount(1, $this->movements());
    }

    public function test_stocktaking_records_the_count_date(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();

        $this->move('Inventur', $this->north, -2, '2026-11-14 16:00:00');

        $stock = DynamicRecord::forTableName('lager_bestaende')->newQuery()->where('lagerort_id', $this->north)->sole();
        $this->assertSame(8, (int) $stock->getAttribute('menge'));
        $this->assertStringStartsWith('2026-11-14', (string) $stock->getAttribute('letzte_zaehlung'));
    }

    public function test_transfer_writes_a_movement_pair_and_the_ledger_books_it(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();

        $this->assertSame([$this->north => 10], $this->transfers()->stocks($this->article));
        $this->transfers()->transfer($this->article, $this->north, $this->south, 4, 'Regal voll');

        $this->assertSame([6, 4], $this->stocks());
        $this->assertSame([[$this->north, 10], [$this->north, -4], [$this->south, 4]], $this->movements());
        $this->assertSame(1, DynamicRecord::forTableName('lager_bewegungen')->newQuery()->where('bemerkung', 'Regal voll')->where('menge', 4)->count());
        $this->assertGreaterThanOrEqual(5, Activity::query()->where('causer_id', auth()->id())->count(), 'Änderungen im Protokoll.');
    }

    public function test_impossible_transfers_change_nothing(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();

        foreach ([[$this->north, $this->south, 11], [$this->north, $this->north, 1], [$this->south, $this->north, 1], [$this->north, $this->south, 0]] as [$from, $to, $quantity]) {
            try {
                $this->transfers()->transfer($this->article, $from, $to, $quantity);
                $this->fail("Umbuchung {$from} → {$to} ({$quantity}) angenommen.");
            } catch (TransferRejected) {
            }
        }

        $this->assertSame([10, 0], $this->stocks());
        $this->assertCount(1, $this->movements());
    }

    public function test_minimum_stock_alert_on_decrease_only(): void
    {
        $user = $this->actingAsSuperAdmin();
        $this->demoTables(minimum: 8);

        $this->move('Ausgang', $this->north, 1);
        $this->assertSame(0, $user->notifications()->count(), 'Gesamtbestand 9 – noch über dem Mindestbestand.');

        $this->move('Ausgang', $this->north, 2);
        $this->assertSame(1, $user->notifications()->count(), 'Gesamtbestand 7 – unter 8.');
        $this->assertStringContainsString('Gesamtbestand von „Schraube“: 7 (Mindestbestand 8)', json_encode($user->notifications()->first()->data, JSON_UNESCAPED_UNICODE));

        $this->transfers()->transfer($this->article, $this->north, $this->south, 2);
        $this->move('Eingang', $this->north, 0 + 1);
        $this->assertSame(1, $user->notifications()->count(), 'Umbuchung und Eingang melden nichts.');

        $this->move('Ausgang', $this->south, 1);
        $this->assertSame(2, $user->notifications()->count(), 'Jede weitere Senkung unter dem Mindestbestand.');
    }

    public function test_form_offers_only_locations_with_stock_and_books_the_transfer(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();
        app()->setLocale('de');

        Livewire::test(Umbuchung::class)
            ->assertFormFieldExists('bemerkung', fn ($field): bool => $field->getLabel() === 'Bemerkung')
            ->fillForm(['artikel' => $this->article])
            ->assertFormFieldExists('von', fn ($field): bool => array_keys($field->getOptions()) === [$this->north])
            ->fillForm(['von' => $this->north, 'nach' => $this->south, 'menge' => 11])
            ->call('transfer')
            ->assertHasFormErrors(['menge' => 'max'])
            ->fillForm(['menge' => 3])
            ->call('transfer')
            ->assertHasNoFormErrors()
            ->assertNotified('3 × A-1 – Schraube von Nord nach Süd umgebucht.');

        $this->assertSame([7, 3], $this->stocks());
    }

    public function test_row_action_opens_the_form_prefilled(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables();
        $this->transfers()->transfer($this->article, $this->north, $this->south, 10);
        [$empty, $filled] = [
            DynamicRecord::forTableName('lager_bestaende')->newQuery()->where('lagerort_id', $this->north)->sole(),
            DynamicRecord::forTableName('lager_bestaende')->newQuery()->where('lagerort_id', $this->south)->sole(),
        ];

        $this->dataView('lager_bestaende')
            ->assertActionVisible(TestAction::make('lagerUmbuchung')->table($filled))
            ->assertActionEnabled(TestAction::make('lagerUmbuchung')->table($filled))
            ->assertActionDisabled(TestAction::make('lagerUmbuchung')->table($empty))
            ->assertActionHasUrl(TestAction::make('lagerUmbuchung')->table($filled), Umbuchung::getUrl(['artikel' => $this->article, 'von' => $this->south]));

        $this->get(Umbuchung::getUrl(['artikel' => $this->article, 'von' => $this->south]))->assertOk();
        Livewire::withQueryParams(['artikel' => $this->article, 'von' => $this->south])->test(Umbuchung::class)
            ->assertFormSet(['artikel' => $this->article, 'von' => $this->south]);
    }

    public function test_missing_data_rights_are_reported(): void
    {
        $this->demoTables();
        $this->actingWithPermissions(['lager-umbuchung.execute', 'data.lager_artikel.view', 'data.lager_orte.view', 'data.lager_bestaende.view', 'data.lager_bewegungen.create']);
        app()->setLocale('de');

        Livewire::test(Umbuchung::class)
            ->fillForm(['artikel' => $this->article, 'von' => $this->north, 'nach' => $this->south, 'menge' => 1])
            ->call('transfer')
            ->assertNotified('Ihnen fehlen Datenrechte für: Bestände');

        $this->assertSame([10, 0], $this->stocks());
    }

    public function test_page_without_demo_tables_shows_a_hint(): void
    {
        $this->actingAsSuperAdmin();
        app()->setLocale('de');
        $this->assertFalse(Saft::hasTable('lager_bestaende'));

        Livewire::test(Umbuchung::class)->assertSee('Lager-Demo nicht vollständig')->assertSee('Tabelle lager_bestaende fehlt')->assertDontSee('Umbuchen');
    }

    public function test_dashboard_card_lists_articles_below_minimum(): void
    {
        $this->actingAsSuperAdmin();
        $this->demoTables(minimum: 8);
        app()->setLocale('de');

        Livewire::test(MinimumStockWidget::class)->assertSee('Alle Artikel über dem Mindestbestand')->assertSee('10');

        $this->move('Ausgang', $this->north, 3);
        Livewire::test(MinimumStockWidget::class)->assertSee('Artikel unter Mindestbestand')->assertSee('Schraube (7/8)');

        // Auf dem Dashboard: eigener Schlüssel, Bezeichnung vom Widget, ausblendbar.
        $key = 'plugin:Saft.LagerUmbuchung.Widgets.MinimumStockWidget';
        $dashboard = Livewire::test(Dashboard::class);
        $this->assertSame('Lager: Mindestbestand', $dashboard->instance()->cards()[$key]['label'] ?? null);
        $dashboard->call('hideCard', $key)->callAction('customizeDashboard')->assertSeeHtml('data-show-card="'.$key.'"');
        $this->assertNotContains($key, Livewire::test(Dashboard::class)->instance()->layout()['left']);
    }

    public function test_dashboard_card_needs_rights_and_the_demo(): void
    {
        $this->actingAsSuperAdmin();
        $this->assertFalse(MinimumStockWidget::canView(), 'Ohne Lager-Demo.');

        $this->demoTables();
        $this->assertTrue(MinimumStockWidget::canView());

        $this->actingWithPermissions(['lager-umbuchung.execute', 'data.lager_artikel.view']);
        $this->assertFalse(MinimumStockWidget::canView(), 'Ohne Datenrecht auf Bestände.');
    }
}
