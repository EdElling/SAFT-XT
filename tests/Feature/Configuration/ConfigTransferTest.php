<?php

declare(strict_types=1);

namespace Tests\Feature\Configuration;

use App\Configuration\ConfigExporter;
use App\Configuration\ConfigImporter;
use App\Configuration\ImportItem;
use App\Configuration\References;
use App\DynamicData\DynamicDataNamespace;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\ConfigurationTransferPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\User;
use App\Plugins\PluginDependencies;
use Database\Seeders\LagerDemoSeeder;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Konfiguration übertragen: Export, Vorschau, Einspielen (nur Neues), Voraussetzungen, Rechte, Konsole. */
class ConfigTransferTest extends DynamicDataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
    }

    /** Lager-Demo plus Verknüpfung für Auswertungen, Auswertung darüber und Rolle mit ausgeblendetem Menüeintrag. */
    private function seedConfiguration(): void
    {
        $this->seed(LagerDemoSeeder::class);
        $refs = app(References::class);
        $link = ReportLink::create([
            'label' => ['de' => 'Zur Gruppe', 'en' => 'To group'],
            'source_table_id' => $refs->tableId('lager_artikel'),
            'source_field_id' => $refs->fieldId('lager_artikel.artikelnummer'),
            'target_table_id' => $refs->tableId('lager_artikelgruppen'),
            'target_field_id' => $refs->fieldId('lager_artikelgruppen.bezeichnung'),
        ]);
        Report::create(['name' => 'Über Verknüpfung', 'owner_id' => $this->admin->id, 'is_shared' => false, 'definition' => [
            'base' => $refs->tableId('lager_artikel'),
            'fields' => [['key' => 'g', 'path' => [-$link->id], 'field' => $refs->fieldId('lager_artikelgruppen.bezeichnung')]],
        ]]);
        $role = Role::create(['name' => 'Lager', 'guard_name' => 'web']);
        $role->givePermissionTo(['data.lager_artikel.view', 'data.lager_bestaende.view', 'lager-umbuchung.execute']);
        $role->hiddenMenus()->attach(Menu::query()->where('route_parameters->table', 'lager_messprotokoll')->value('id'));
    }

    /** @return array<string, mixed> */
    private function exportAll(): array
    {
        $group = Menu::query()->whereNull('parent_id')->where('label->de', 'Lager (Demo)')->sole();

        return app(ConfigExporter::class)->export(
            tables: CustomTable::query()->designer()->pluck('name')->all(),
            reports: Report::query()->pluck('id')->all(),
            menus: Menu::query()->where('parent_id', $group->id)->pluck('id')->all(),
            roles: Role::query()->where('name', 'Lager')->pluck('id')->all(),
            user: $this->admin,
            label: 'Lager',
        );
    }

    private function wipe(): void
    {
        $group = Menu::query()->whereNull('parent_id')->where('label->de', 'Lager (Demo)')->sole();
        Menu::query()->where('parent_id', $group->id)->delete();
        $group->delete();
        Report::query()->delete();
        Role::query()->where('name', 'Lager')->delete();
        app(DynamicDataNamespace::class)->reset();
        CustomTable::query()->designer()->each(static fn (CustomTable $t) => $t->fields()->delete());
        CustomTable::query()->designer()->delete();
        DynamicRecord::forget();
        app(PluginDependencies::class)->flush();
    }

    /** @param  array<string, mixed>  $package */
    private static function withoutTimestamp(array $package): array
    {
        unset($package['created_at']);

        return $package;
    }

    public function test_round_trip_creates_the_same_configuration(): void
    {
        $this->seedConfiguration();
        $before = $this->exportAll();

        $this->assertSame(ConfigExporter::FORMAT, $before['format']);
        $this->assertSame(['lager-umbuchung' => '1.3.0'], $before['requires']['plugins']);
        $this->assertContains('Über Verknüpfung', array_column($before['reports'], 'name'));
        $this->assertSame('lager_artikel', $before['reports'][array_search('Über Verknüpfung', array_column($before['reports'], 'name'), true)]['definition']['base']);
        $this->assertSame([['label' => ['de' => 'Zur Gruppe', 'en' => 'To group'], 'source' => 'lager_artikel.artikelnummer', 'target_table' => 'lager_artikelgruppen', 'target_field' => 'lager_artikelgruppen.bezeichnung']], $before['report_links']);
        $this->assertSame('Lager (Demo)', $before['menus'][0]['path'], 'Übergeordnete Gruppe zuerst.');
        $this->assertSame(['Lager (Demo) › Messprotokoll'], $before['roles'][0]['hidden_menus']);

        $this->wipe();
        $items = app(ConfigImporter::class)->analyze($before);
        $this->assertSame([ImportItem::NEW], array_values(array_unique(array_map(static fn (ImportItem $i): string => $i->status, $items))), 'Alles neu.');

        $created = app(ConfigImporter::class)->apply($before, $this->admin);
        $this->assertSame(count($before['tables']), $created[ImportItem::TABLE]);
        $this->assertSame(count($before['menus']), $created[ImportItem::MENU]);
        $this->assertSame(1, $created[ImportItem::ROLE]);

        $this->assertEquals(self::withoutTimestamp($before), self::withoutTimestamp($this->exportAll()), 'Gleiche Konfiguration nach dem Einspielen.');
        $this->assertSame([ImportItem::IDENTICAL], array_values(array_unique(array_map(static fn (ImportItem $i): string => $i->status, app(ConfigImporter::class)->analyze($before)))));
        $this->assertSame(1, Activity::query()->where('event', 'config_imported')->count());

        // Die eingespielten Teile funktionieren: Auswertung läuft, Rolle sieht die Daten.
        $this->get(route('filament.admin.resources.reports.view', ['record' => Report::query()->where('name', 'Über Verknüpfung')->value('id')]))->assertOk();
        $this->assertTrue(Role::findByName('Lager')->hasPermissionTo('data.lager_artikel.view'));
    }

    public function test_existing_entries_are_not_changed_and_missing_prerequisites_are_reported(): void
    {
        $this->seedConfiguration();
        $package = $this->exportAll();
        $package['requires']['plugins']['gibt-es-nicht'] = '1.0.0';
        $package['menus'][] = ['path' => 'Lager (Demo) › Fremd', 'parent' => 'Lager (Demo)', 'label' => ['de' => 'Fremd'], 'icon' => null, 'permission_name' => null, 'sort_order' => 99, 'is_active' => true, 'target' => ['type' => 'route', 'route' => 'filament.admin.pages.gibt-es-nicht']];
        $package['reports'][] = ['name' => 'Ohne Tabelle', 'description' => null, 'is_shared' => true, 'definition' => ['base' => 'gibt_es_nicht', 'fields' => []]];
        Report::query()->where('name', 'Über Verknüpfung')->update(['description' => 'geändert']);

        $status = collect(app(ConfigImporter::class)->analyze($package))->mapWithKeys(static fn (ImportItem $i): array => [$i->area.':'.$i->key => $i->status]);
        $this->assertSame(ImportItem::IDENTICAL, $status['table:lager_artikel']);
        $this->assertSame(ImportItem::DIFFERENT, $status['report:Über Verknüpfung']);
        $this->assertSame(ImportItem::MISSING, $status['menu:Lager (Demo) › Fremd']);
        $this->assertSame(ImportItem::MISSING, $status['report:Ohne Tabelle']);
        $this->assertSame(['Plugin „gibt-es-nicht“ ist nicht installiert'], app(ConfigImporter::class)->missingRequirements($package));

        $this->assertSame(array_fill_keys(ImportItem::AREAS, 0), app(ConfigImporter::class)->apply($package, $this->admin), 'Nichts Neues angelegt.');
        $this->assertSame('geändert', Report::query()->where('name', 'Über Verknüpfung')->value('description'), 'Vorhandenes bleibt.');
    }

    public function test_invalid_packages_are_rejected(): void
    {
        $file = storage_path('framework/testing/config-'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($file));

        try {
            foreach (['kein json' => 'kein gültiges JSON', '{"format":"saft-config/9"}' => 'Unbekanntes Paketformat', '{"format":"saft-config/1","tables":{"a":1}}' => 'beschädigt'] as $content => $message) {
                File::put($file, $content);

                try {
                    app(ConfigImporter::class)->read($file);
                    $this->fail('Angenommen: '.$content);
                } catch (BusinessRuleException $e) {
                    $this->assertStringContainsString($message, $e->getMessage());
                }
            }
        } finally {
            File::delete($file);
        }
    }

    public function test_page_tabs_follow_the_permissions_and_export_downloads(): void
    {
        $this->actingAs($this->userWithPermissions(['plugins.view']));
        $this->get(ConfigurationTransferPage::getUrl())->assertForbidden();

        $this->actingAs($this->userWithPermissions(['configuration.import']));
        $this->get(ConfigurationTransferPage::getUrl())->assertForbidden();

        // Menü und Seite mit „anzeigen“; die Reiter nach „exportieren“ bzw. „einspielen“.
        $importer = $this->userWithPermissions(['configuration.view', 'configuration.import']);
        $this->actingAs($importer);
        $this->assertContains(ConfigurationTransferPage::ROUTE, Menu::query()->visibleFor($importer)->pluck('route')->all());
        Livewire::test(ConfigurationTransferPage::class)->assertSee('Einspielen')->assertDontSee('Paket herunterladen');

        $this->actingAs($this->userWithPermissions(['configuration.view']));
        $this->get(ConfigurationTransferPage::getUrl())->assertForbidden();

        $this->seedConfiguration();
        $this->actingAs($this->admin);
        Livewire::test(ConfigurationTransferPage::class)
            ->fillForm(['roles' => [Role::findByName('Lager')->id]], 'exportForm')
            ->call('exportPackage')
            ->assertFileDownloaded();

        $this->assertSame(['rollen' => ['Lager']], array_intersect_key(Activity::query()->where('event', 'config_exported')->sole()->attribute_changes['attributes'] ?? [], ['rollen' => 1]));
    }

    public function test_console_export_and_dry_run_import(): void
    {
        $this->seedConfiguration();
        $file = storage_path('framework/testing/config-'.uniqid().'.json');
        File::ensureDirectoryExists(dirname($file));

        try {
            $this->artisan('saft:config-export', ['output' => $file, '--tables' => ['lager_orte'], '--label' => 'Orte'])->assertSuccessful();
            $package = json_decode((string) file_get_contents($file), true);
            $this->assertSame(['lager_orte'], array_column($package['tables'], 'name'));

            $this->artisan('saft:config-import', ['file' => $file, '--dry-run' => true])
                ->expectsOutputToContain('0 neu, 1 identisch')
                ->expectsOutputToContain('Probelauf')
                ->assertSuccessful();
        } finally {
            File::delete($file);
        }
    }

    public function test_upload_shows_the_preview_and_applies_new_entries(): void
    {
        $this->seedConfiguration();
        $package = $this->exportAll();
        Role::query()->where('name', 'Lager')->delete();

        $page = Livewire::test(ConfigurationTransferPage::class)
            ->set('importData.file', UploadedFile::fake()->createWithContent('paket.json', (string) json_encode($package)))
            ->assertSet('packageInfo.label', 'Lager')
            ->assertSee('Lager (Demo) › Bestände')
            ->assertSee('identisch')
            ->assertSee('neu');

        $this->assertSame(1, collect($page->get('items'))->where('status', ImportItem::NEW)->count(), 'Nur die Rolle ist neu.');

        $page->callAction('applyPackage')->assertNotified('Angelegt: 0 Tabellen, 0 Verknüpfungen, 0 Auswertungen, 0 Menüeinträge, 1 Rollen.');
        $this->assertTrue(Role::query()->where('name', 'Lager')->exists());
        $this->assertSame(0, collect($page->get('items'))->where('status', ImportItem::NEW)->count());

        $page->callAction('resetPackage')->assertSet('packageFile', null);
    }

    public function test_reports_are_grouped_with_their_tables_and_missing_tables_are_named(): void
    {
        $this->seedConfiguration();
        $report = Report::query()->where('name', LagerDemoSeeder::REPORT_BELOW_MINIMUM)->sole();
        $group = md5('Lager (Demo)');

        $this->assertSame(['lager_artikel', 'lager_artikelgruppen', 'lager_lieferanten'], app(ConfigExporter::class)->reportUses($report)['tables'], 'Grundtabelle zuerst.');

        $calculation = Report::query()->where('name', LagerDemoSeeder::REPORT_CALCULATION)->sole();
        $disabled = fn (Report $r): \Closure => static fn (CheckboxList $field): bool => $field->isOptionDisabled((string) $r->id, $r->name);

        $page = Livewire::test(ConfigurationTransferPage::class)
            ->assertSee('Benötigt: Artikel, Artikelgruppen, Lieferanten')
            // Erst gewählt, dann Abhängigkeiten aus: Auswertung ohne alle Tabellen wird genannt und nicht exportiert.
            ->fillForm(['reports' => [$group => [(string) $report->id, (string) $calculation->id]], 'tables' => [$group => ['lager_artikel']]], 'exportForm')
            ->fillForm(['dependencies' => false], 'exportForm')
            ->assertSee('Gewählte Einträge werden nicht exportiert')
            ->assertSeeHtml('<strong>'.e(LagerDemoSeeder::REPORT_BELOW_MINIMUM).'</strong>: Artikelgruppen, Lieferanten')
            ->assertSee('fehlt: Artikelgruppen, Lieferanten')
            ->assertFormFieldExists('reports.'.$group, 'exportForm', $disabled($report))
            ->assertFormFieldExists('reports.'.$group, 'exportForm', fn (CheckboxList $field): bool => ! $disabled($calculation)($field));

        // Menüeinträge: Datenansicht braucht ihre Tabelle, Auswertungseintrag seine (exportierbare) Auswertung.
        $articles = (string) Menu::query()->where('route_parameters->table', 'lager_artikel')->value('id');
        $stocks = (string) Menu::query()->where('route_parameters->table', 'lager_bestaende')->value('id');
        $belowMenu = (string) Menu::query()->where('route', 'filament.admin.resources.reports.view')->where('route_parameters->record', (string) $report->id)->value('id');
        $menuDisabled = static fn (string $id): \Closure => static fn (CheckboxList $field): bool => $field->isOptionDisabled($id, '');

        $page->assertSee('Datenansicht: Bestände – fehlt: Bestände')
            ->assertSee('Auswertung: '.LagerDemoSeeder::REPORT_BELOW_MINIMUM)
            ->assertFormFieldExists('menus.'.$group, 'exportForm', $menuDisabled($stocks))
            ->assertFormFieldExists('menus.'.$group, 'exportForm', $menuDisabled($belowMenu))
            ->assertFormFieldExists('menus.'.$group, 'exportForm', fn (CheckboxList $field): bool => ! $menuDisabled($articles)($field))
            ->fillForm(['menus' => [$group => [$articles, $stocks]]], 'exportForm')
            ->assertSeeHtml('<strong>Lager (Demo) › Bestände</strong>: Bestände');

        $page->call('exportPackage');
        $menus = Activity::query()->where('event', 'config_exported')->latest('id')->first()->attribute_changes['attributes']['menues'] ?? [];
        $this->assertSame(['Lager (Demo)', 'Lager (Demo) › Artikel'], $menus, 'Bestände fehlt die Tabelle; die Gruppe kommt immer mit.');
        $exported = Activity::query()->where('event', 'config_exported')->latest('id')->first()->attribute_changes['attributes']['auswertungen'] ?? [];
        $this->assertSame([LagerDemoSeeder::REPORT_CALCULATION], $exported, 'Nur die Auswertung, deren Tabellen gewählt sind.');

        $page->fillForm(['dependencies' => true], 'exportForm')
            ->assertDontSee('Gewählte Einträge werden nicht exportiert')
            ->assertFormFieldExists('reports.'.$group, 'exportForm', fn (CheckboxList $field): bool => ! $disabled($report)($field));
    }
}
