<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\DynamicData\DynamicDataNamespace;
use App\Filament\Resources\Reports\ReportResource;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Models\Report;
use App\Reports\ChartData;
use App\Reports\ReportRunner;
use App\Services\DynamicSchemaService;
use Database\Seeders\DemoDataSeeder;

/** Demo „Shop (Demo)“: Menügruppe, Umbenennung älterer Installationen, Beispiel-Auswertung. */
class DemoShopTest extends DynamicDataTestCase
{
    public function test_demo_tables_report_and_menu_entry_are_in_the_shop_group(): void
    {
        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $shop = Menu::query()->whereNull('parent_id')->where('label->de', 'Shop (Demo)')->sole();
        $this->assertSame(DemoDataSeeder::SHOP_GROUP, $shop->getTranslations('label'));
        $this->assertEqualsCanonicalizing(
            ['artikel', 'kunden', 'bestellungen', 'bestellpositionen'],
            Menu::query()->where('parent_id', $shop->id)->where('route', DataMenu::ROUTE)->get()->map(fn (Menu $m): string => $m->route_parameters['table'])->all(),
        );
        $this->assertSame(0, Menu::query()->whereNull('parent_id')->where('label->de', DataMenu::GROUP_LABEL)->count(), 'Keine leere Gruppe „Daten“.');

        $report = Report::query()->where('name', DemoDataSeeder::REPORT_NAME)->sole();
        $this->assertTrue($report->is_shared);
        $entry = Menu::query()->where('route', ReportResource::VIEW_ROUTE)->sole();
        $this->assertSame([$shop->id, ['record' => (string) $report->id]], [$entry->parent_id, $entry->route_parameters]);

        // Die Auswertung läuft und ergibt ein Balkendiagramm mit einer Reihe je Kategorie.
        $result = app(ReportRunner::class)->run($report, $this->superAdmin());
        $this->assertNotEmpty($result->rows);
        $chart = app(ChartData::class)->build($result, 'bar');
        $this->assertNotEmpty($chart['labels']);
        $this->assertContains('Hardware', array_column($chart['datasets'], 'label'));

        // Stornierte Bestellungen zählen nicht mit.
        $total = array_sum(array_map(fn (array $row): float => (float) $row['umsatz'], $result->rows));
        $this->assertEqualsWithDelta($this->revenueWithoutCancelled(), $total, 0.01);

        // Neue Tabellen landen weiterhin in „Daten“ (wird bei Bedarf angelegt).
        $table = app(DynamicSchemaService::class)->createTable(['app_id' => self::ensureTestApp()->id, 'name' => 'notizen', 'label' => 'Notizen']);
        $this->assertSame(DataMenu::GROUP_LABEL, app(DataMenu::class)->ensure($table)->parent->getTranslation('label', 'de'));
    }

    public function test_older_installation_renames_its_data_group(): void
    {
        // Zustand wie früher: Demo-Tabellen in der Gruppe „Daten“.
        $this->seed(DemoDataSeeder::class);
        $group = Menu::query()->whereNull('parent_id')->where('label->de', 'Shop (Demo)')->sole();
        $group->update(['label' => [...DataMenu::GROUP_LABELS, 'de' => DataMenu::GROUP_LABEL]]);

        $this->seed(DemoDataSeeder::class);

        $this->assertSame('Shop (Demo)', $group->fresh()->getTranslation('label', 'de'));
        $this->assertSame(4, Menu::query()->where('parent_id', $group->id)->where('route', DataMenu::ROUTE)->count());
        $this->assertSame(1, Menu::query()->whereNull('parent_id')->whereNull('route')->whereNull('url')->count() - 1, 'Neben der Administration nur die Shop-Gruppe.');
    }

    private function revenueWithoutCancelled(): float
    {
        $positions = CustomTable::query()->where('name', 'bestellpositionen')->sole();
        $connection = app(DynamicDataNamespace::class);

        return (float) $connection->connection()->table($connection->qualify($positions->name).' as p')
            ->join($connection->qualify('bestellungen').' as b', 'b.id', '=', 'p.bestellung_id')
            ->whereNull('p.deleted_at')
            ->whereNull('b.deleted_at')
            ->where(fn ($q) => $q->where('b.status', '<>', 'storniert')->orWhereNull('b.status'))
            ->sum('p.betrag');
    }
}
