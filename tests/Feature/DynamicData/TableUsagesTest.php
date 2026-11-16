<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\Usages\TableUsage;
use App\DynamicData\Usages\TableUsages;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\UsagesRelationManager;
use App\Models\CustomTable;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\ScheduledTask;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use App\Tasks\Types\SnapshotRefreshTask;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/** Reiter „Verwendet in“ und Warnung beim Löschen: alle Bereiche, in denen eine Tabelle vorkommt. */
class TableUsagesTest extends DynamicDataTestCase
{
    private CustomTable $customers;

    private CustomTable $orders;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());

        $this->customers = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'options' => ['length' => 100]],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string', 'options' => ['length' => 100]],
        ]);
        $this->orders = $this->service()->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'lieferort', 'label' => 'Lieferort', 'type' => 'string', 'options' => ['length' => 100, 'copy_from' => 'kunde_id.ort']],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
        ]);
        $this->service()->addField($this->customers->fresh(), ['name' => 'umsatz', 'label' => 'Umsatz', 'type' => 'computed', 'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'sum(bestellungen.betrag)']]);
    }

    /** @return array<string, list<string>> Bereich => Namen */
    private function usages(CustomTable $table): array
    {
        $grouped = [];

        foreach (app(TableUsages::class)->of($table->fresh()) as $usage) {
            $grouped[$usage->area][] = $usage->name.' | '.$usage->detail;
        }

        return $grouped;
    }

    public function test_all_areas_are_found(): void
    {
        $customerField = $this->customers->fields()->where('name', 'firma')->sole();
        Report::create(['name' => 'Kundenliste', 'owner_id' => auth()->id(), 'is_shared' => true, 'definition' => ['base' => $this->customers->id, 'fields' => []]]);
        Report::create(['name' => 'Bestellungen je Kunde', 'owner_id' => auth()->id(), 'is_shared' => true, 'definition' => [
            'base' => $this->orders->id,
            'fields' => [['key' => 'k', 'path' => [$this->orders->fields()->where('name', 'kunde_id')->value('id')], 'field' => $customerField->id]],
        ]]);
        Report::create(['name' => 'Fremd', 'owner_id' => auth()->id(), 'is_shared' => true, 'definition' => ['base' => $this->orders->id, 'fields' => []]]);
        ReportLink::create(['label' => ['de' => 'Zum Kunden'], 'source_table_id' => $this->customers->id, 'source_field_id' => $customerField->id, 'target_table_id' => $this->orders->id]);
        app(DataMenu::class)->ensure($this->customers);
        Permission::findOrCreate(DataPermissions::name('kunden', 'view'), 'web');
        Permission::findOrCreate(DataPermissions::name('kunden', 'update'), 'web');
        Role::create(['name' => 'Verkauf', 'guard_name' => 'web'])->givePermissionTo([DataPermissions::name('kunden', 'view'), DataPermissions::name('kunden', 'update')]);
        ScheduledTask::create(['name' => 'Datenstand Kunden', 'type' => SnapshotRefreshTask::KEY, 'parameters' => ['table_id' => $this->customers->id], 'frequency' => 'daily']);
        ScheduledTask::create(['name' => 'Datenstand Bestellungen', 'type' => SnapshotRefreshTask::KEY, 'parameters' => ['table_id' => $this->orders->id], 'frequency' => 'daily']);
        app(PluginRegistry::class)->add(new class extends SaftPlugin
        {
            public function id(): string
            {
                return 'test-nutzung';
            }

            public function label(): string
            {
                return 'Test-Nutzung';
            }

            public function requiredTables(): array
            {
                return ['kunden' => ['firma']];
            }
        });

        $usages = $this->usages($this->customers);

        $this->assertSame(['Test-Nutzung | braucht: firma'], $usages[TableUsage::PLUGIN]);
        $this->assertSame(['Bestellungen je Kunde | über eine Verknüpfung', 'Kundenliste | Grundtabelle'], $usages[TableUsage::REPORT]);
        $this->assertSame(['Zum Kunden | Quelle'], $usages[TableUsage::REPORT_LINK]);
        $this->assertCount(1, $usages[TableUsage::MENU]);
        $this->assertStringEndsWith('Kunden | Datenansicht', $usages[TableUsage::MENU][0]);
        $this->assertSame(['Bestellungen › Kunde (bestellungen.kunde_id) | Verknüpfung „Kunde“'], $usages[TableUsage::RELATION]);
        $this->assertSame(['Bestellungen › Lieferort (bestellungen.lieferort) | übernimmt ort'], $usages[TableUsage::COPY]);
        $this->assertSame(['Verkauf | anzeigen, bearbeiten'], $usages[TableUsage::ROLE]);
        $this->assertSame(['Datenstand Kunden | '.__('tasks.types.snapshot_refresh')], $usages[TableUsage::TASK]);
        $this->assertArrayNotHasKey(TableUsage::FORMULA, $usages, 'Die Formel steht in Kunden selbst.');

        // Bestellungen: Kunden fasst darüber zusammen.
        $this->assertSame(['Kunden › Umsatz (kunden.umsatz) | sum(bestellungen.betrag)'], $this->usages($this->orders)[TableUsage::FORMULA]);
    }

    public function test_tab_lists_usages_with_badge_and_filter(): void
    {
        app(DataMenu::class)->ensure($this->customers);
        $this->assertSame('3', UsagesRelationManager::getBadge($this->customers->fresh(), EditCustomTable::class), 'Menü, Verknüpfung, Übernahme.');

        Livewire::test(UsagesRelationManager::class, ['ownerRecord' => $this->customers->fresh(), 'pageClass' => EditCustomTable::class])
            ->assertSee('Verknüpfung „Kunde“')
            ->assertSee('übernimmt ort')
            ->filterTable('area', TableUsage::COPY)
            ->assertSee('übernimmt ort')
            ->assertDontSee('Verknüpfung „Kunde“');

        $notes = $this->service()->createTable(['name' => 'notizen', 'label' => 'Notizen'], [
            ['name' => 'text', 'label' => 'Text', 'type' => 'string', 'options' => ['length' => 100]],
        ]);
        $this->assertNull(UsagesRelationManager::getBadge($notes, EditCustomTable::class));
        Livewire::test(UsagesRelationManager::class, ['ownerRecord' => $notes, 'pageClass' => EditCustomTable::class])
            ->assertSee('Diese Tabelle wird nirgends verwendet.');
    }

    public function test_links_only_for_permitted_targets(): void
    {
        Report::create(['name' => 'Privat', 'owner_id' => $this->superAdmin()->id, 'is_shared' => false, 'definition' => ['base' => $this->customers->id, 'fields' => []]]);
        $this->assertNotNull(app(TableUsages::class)->of($this->customers)[0]->url ?? null);

        $this->actingAs($this->userWithPermissions(['custom_tables.view']));
        $report = collect(app(TableUsages::class)->of($this->customers))->firstWhere('area', TableUsage::REPORT);
        $this->assertSame('Privat', $report->name, 'Eintrag sichtbar, nur zur Information.');
        $this->assertNull($report->url, 'Ohne Recht kein Link.');
    }

    public function test_drop_dialog_warns_about_usages(): void
    {
        app(DataMenu::class)->ensure($this->customers);
        $this->customers->update(['is_active' => false]);

        $this->assertSame(
            'Verwendet in: 1 Menüeintrag, 1 Verknüpfung aus anderen Tabellen, 1 Übernahme. Diese Verweise funktionieren nach dem Löschen nicht mehr. Einzelheiten im Reiter „Verwendet in“.',
            EditCustomTable::usageSummary($this->customers->fresh()),
        );

        // Inhalt von Modals erscheint im HTML der Livewire-Tests nicht; der Dialog öffnet sich mit der Warnung.
        Livewire::test(EditCustomTable::class, ['record' => $this->customers->id])
            ->mountAction(TestAction::make('dropTable'))
            ->assertActionMounted(TestAction::make('dropTable'));
    }
}
