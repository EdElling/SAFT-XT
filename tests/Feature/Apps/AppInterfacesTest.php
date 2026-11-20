<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\AppInterfaces;
use App\Apps\AppMover;
use App\Apps\AppRemover;
use App\Apps\CurrentApp;
use App\Apps\Definition\AppDefinition;
use App\Apps\Definition\AppInstaller;
use App\DynamicData\DetailRelations;
use App\DynamicData\RelationDisplay;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Report;
use App\Models\ReportLink;
use App\Models\Role;
use App\Models\User;
use App\Reports\Editor\FieldCatalog;
use App\Reports\InvalidReportException;
use App\Reports\Links\ReportLinks;
use App\Reports\ReportDefinition;
use App\Reports\ReportRunner;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Schnittstellen zwischen Apps (B-107, Etappe 5a): freigeben, verknüpfen, Vertrag, Rechte, Lebenszyklus. */
class AppInterfacesTest extends DynamicDataTestCase
{
    private App $stock;

    private App $shop;

    private CustomTable $articles;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());
        $this->stock = self::ensureTestApp();
        $this->stock->update(['name' => ['de' => 'Lager'], 'prefix' => 'lg']);
        $this->shop = App::query()->create(['name' => ['de' => 'Shop'], 'prefix' => 'shop', 'sort_order' => 2]);

        $this->articles = $this->service()->createTable(['app_id' => $this->stock->id, 'name' => 'lg_artikel', 'label' => 'Artikel'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'is_searchable' => true],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal'],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'string'],
        ]);
        $this->service()->createTable(['app_id' => $this->shop->id, 'name' => 'shop_bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'preis', 'label' => 'Preis', 'type' => 'decimal'],
        ]);
    }

    private function orders(): CustomTable
    {
        return CustomTable::query()->where('name', 'shop_bestellungen')->with('fields')->sole();
    }

    private function share(array $fields = ['name', 'preis']): void
    {
        $this->service()->updateInterface($this->articles->refresh(), true, $fields);
    }

    /** Verknüpfung der Bestellungen auf die Artikel des Lagers (über die Schnittstelle). */
    private function relate(array $display = ['name']): CustomField
    {
        return $this->service()->addField($this->orders(), ['name' => 'artikel_id', 'label' => 'Artikel', 'type' => 'relation', 'options' => ['table' => 'lg_artikel', 'display_fields' => $display]]);
    }

    private function rejects(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('Erwartete Ablehnung: '.$message);
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_relations_reach_other_apps_only_through_shared_tables_and_fields(): void
    {
        $this->rejects(fn () => $this->relate(), 'ist nicht freigegeben');

        $this->share();
        $this->rejects(fn () => $this->relate(['notiz']), 'Das Feld „notiz“ der Tabelle „Artikel“ ist nicht für andere Apps freigegeben');
        $relation = $this->relate(['name']);
        $this->assertSame('lg_artikel', $relation->optionsArray()['table']);

        // „Übernehmen aus“: nur freigegebene Felder.
        $this->rejects(fn () => $this->service()->addField($this->orders(), ['name' => 'bemerkung', 'label' => 'Bemerkung', 'type' => 'string', 'options' => ['copy_from' => 'artikel_id.notiz']]), 'nicht für andere Apps freigegeben');
        $this->service()->addField($this->orders(), ['name' => 'stueckpreis', 'label' => 'Stückpreis', 'type' => 'decimal', 'options' => ['copy_from' => 'artikel_id.preis']]);

        // Summen über Kind-Tabellen einer anderen App: nein.
        $this->rejects(fn () => $this->service()->addField($this->articles->refresh(), ['name' => 'bestellt', 'label' => 'Bestellt', 'type' => 'computed', 'options' => ['formula' => 'sum(shop_bestellungen.menge)', 'result' => 'integer']]), 'gehört zu einer anderen App');

        // Details der freigebenden Tabelle bieten die Bestellungen des Shops nicht an.
        $this->assertSame([], DetailRelations::fieldCandidates('lg_artikel')->all());
    }

    public function test_used_interfaces_are_a_contract_and_unused_changes_raise_the_version(): void
    {
        $this->share(['name', 'preis', 'notiz']);
        $this->relate(['name']);
        $name = $this->articles->fields()->where('name', 'name')->sole();

        $this->rejects(fn () => $this->service()->updateInterface($this->articles->refresh(), false, []), 'wird von anderen Apps über die Schnittstelle genutzt');
        $this->rejects(fn () => $this->service()->updateInterface($this->articles->refresh(), true, ['preis']), 'Das Feld „name“ wird von anderen Apps');
        $this->rejects(fn () => $this->service()->updateField($name, ['name' => 'bezeichnung']), 'Das Feld „name“ wird von anderen Apps');
        $this->rejects(fn () => $this->service()->updateTable($this->articles->refresh(), ['is_active' => false]), 'wird von anderen Apps über die Schnittstelle genutzt');

        // Unbenutztes Feld aus der Freigabe nehmen: erlaubt, Version steigt.
        $this->service()->updateInterface($this->articles->refresh(), true, ['name', 'preis']);
        $this->assertSame(2, $this->stock->fresh()->interface_version);
        $this->assertFalse($this->articles->fields()->where('name', 'notiz')->sole()->is_shared);
    }

    public function test_moving_and_removing_respect_interfaces(): void
    {
        $this->share();
        $this->relate();
        $third = App::query()->create(['name' => ['de' => 'Dritte'], 'prefix' => 'dr', 'sort_order' => 3]);

        $this->assertSame([], app(AppMover::class)->plan(['shop_bestellungen'], $third)['blockers'], 'Verweis auf eine freigegebene Tabelle darf mitwandern.');
        $this->assertNotSame([], app(AppMover::class)->plan(['lg_artikel'], $third)['blockers'], 'Genutzte Freigabe bleibt in ihrer App.');

        $this->stock->update(['is_active' => false]);
        $this->assertNotSame([], app(AppRemover::class)->blockers($this->stock), 'Genutzte Schnittstelle verhindert das Entfernen.');
    }

    public function test_references_show_not_available_while_the_sharing_app_is_deactivated(): void
    {
        $this->share();
        $relation = $this->relate();
        $article = DynamicRecord::forTableName('lg_artikel')->create(['name' => 'Schraube']);
        $options = $relation->optionsArray();
        $person = User::factory()->create();

        $this->actingAs($person);
        $this->assertSame('Schraube', RelationDisplay::label($article, $options));

        $this->stock->update(['is_active' => false]);
        request()->attributes->remove('saft.relation.app.lg_artikel');
        $this->assertSame(__('apps.interfaces.unavailable'), RelationDisplay::label($article, $options));
        $this->assertSame([], RelationDisplay::options(DynamicRecord::forTableName('lg_artikel')->newQuery(), $options, 10));

        $this->actingAs($this->superAdmin());
        $this->assertSame('Schraube', RelationDisplay::label($article, $options), 'Der Super-Admin sieht weiterhin den Wert.');
    }

    public function test_roles_of_other_apps_can_read_shared_tables_without_access_to_their_app(): void
    {
        $this->share();
        $role = Role::query()->create(['name' => 'verkauf', 'guard_name' => 'web', 'app_id' => $this->shop->id]);
        $role->givePermissionTo('data.shop_bestellungen.view');

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertSet('data.shared_permissions.lg_artikel', [])
            ->set('data.shared_permissions.lg_artikel', ['view'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($role->fresh()->hasPermissionTo('data.lg_artikel.view'));

        // Nicht doppelt unter „ausserhalb“; die Person sieht trotzdem nur die App Shop.
        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertSet('data.shared_permissions.lg_artikel', ['view'])
            ->assertDontSee(__('roles.form.other_permissions'));
        $person = User::factory()->create()->assignRole($role);
        $this->assertSame([$this->shop->id], app(CurrentApp::class)->accessible($person)->pluck('id')->all());
    }

    public function test_interface_is_set_in_the_table_designer(): void
    {
        Livewire::test(EditCustomTable::class, ['record' => $this->articles->getRouteKey()])
            ->fillForm(['is_shared' => true])
            ->assertFormSet(['shared_fields' => ['name']])
            ->fillForm(['shared_fields' => ['name', 'preis']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->articles->refresh();
        $this->assertTrue($this->articles->is_shared);
        $this->assertSame(['name', 'preis'], $this->articles->fields()->where('is_shared', true)->orderBy('sort_order')->pluck('name')->all());
    }

    public function test_used_interface_cannot_be_switched_off_in_the_designer(): void
    {
        $this->share(['name', 'preis']);
        $this->relate(['name']);

        Livewire::test(EditCustomTable::class, ['record' => $this->articles->getRouteKey()])
            ->assertFormFieldIsDisabled('is_shared')
            ->assertSee('Die Freigabe und die genutzten Felder (name)')
            // Genutzte Felder als Text, nicht in der Auswahl.
            ->assertSee(__('apps.interfaces.fields_locked', ['fields' => 'Name (name)']))
            ->assertFormSet(['shared_fields' => ['preis']])
            ->fillForm(['shared_fields' => ['preis', 'notiz']])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(['name', 'notiz', 'preis'], $this->articles->fields()->where('is_shared', true)->pluck('name')->sort()->values()->all());

        // Unbenutztes Feld abwählen; das genutzte bleibt.
        Livewire::test(EditCustomTable::class, ['record' => $this->articles->getRouteKey()])
            ->fillForm(['shared_fields' => ['preis']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->articles->refresh();
        $this->assertTrue($this->articles->is_shared, 'Freigabe bleibt.');
        $this->assertSame(['name', 'preis'], $this->articles->fields()->where('is_shared', true)->pluck('name')->sort()->values()->all(), 'Genutztes Feld bleibt fest, abgewähltes unbenutztes ist weg.');
    }

    /** Auswertung der Bestellungen (Shop) mit Feldern des Artikels (Lager) über die Verknüpfung. */
    private function orderReport(string ...$fields): ReportDefinition
    {
        $relation = $this->fieldId('shop_bestellungen', 'artikel_id');

        return ReportDefinition::fromArray([
            'base' => $this->orders()->id,
            'fields' => [
                ['key' => 'menge', 'path' => [], 'field' => $this->fieldId('shop_bestellungen', 'menge'), 'bucket' => null, 'label' => null],
                ...array_map(fn (string $f): array => ['key' => 'a_'.$f, 'path' => [$relation], 'field' => $this->fieldId('lg_artikel', $f), 'bucket' => null, 'label' => null], $fields),
            ],
        ]);
    }

    public function test_reports_read_shared_fields_of_other_apps_without_their_permission(): void
    {
        $this->share(['name']);
        $this->relate();
        $article = DynamicRecord::forTableName('lg_artikel')->create(['name' => 'Schraube', 'preis' => 2, 'notiz' => 'intern']);
        DynamicRecord::forTableName('shop_bestellungen')->create(['menge' => 3, 'artikel_id' => $article->id]);
        $seller = $this->userWithPermissions(['data.shop_bestellungen.view']);
        $runner = app(ReportRunner::class);

        // Freigegebenes Feld: ohne Leserecht auf die Artikel.
        $rows = $runner->run($this->orderReport('name'), $seller)->rows;
        $this->assertSame('Schraube', $rows[0]['a_name']);

        // Nicht freigegebenes Feld: abgelehnt, auch für den Super-Admin.
        foreach ([$seller, $this->superAdmin()] as $user) {
            try {
                $runner->resolve($this->orderReport('notiz'), $user);
                $this->fail('Nicht freigegebenes Feld erwartet');
            } catch (InvalidReportException $e) {
                $this->assertStringContainsString('nicht für andere Apps freigegeben', implode(' ', $e->errors));
            }
        }

        // Im Editor: nur freigegebene Felder des Artikels, keine fremde Grundtabelle.
        $catalog = new FieldCatalog;
        $labels = array_map(static fn (array $e): string => $e['label'], $catalog->entries($this->orders()->id, $seller));
        $this->assertContains('Artikel › Name', $labels);
        $this->assertNotContains('Artikel › Notiz', $labels);
        $this->assertNotContains($this->articles->id, $catalog->reachableFrom($this->orders()->id, $this->superAdmin()));

        // Deaktivierte freigebende App: „nicht verfügbar“ statt Rechtefehler.
        $this->stock->update(['is_active' => false]);
        $this->assertFalse($runner->canRun($this->orderReport('name'), $seller));
        $this->assertTrue($runner->canRun($this->orderReport('name'), $this->superAdmin()));
    }

    public function test_fields_used_by_reports_and_links_of_other_apps_are_a_contract(): void
    {
        $this->share(['name', 'preis']);
        $this->relate();
        Report::query()->create(['name' => 'Umsatz', 'app_id' => $this->shop->id, 'owner_id' => auth()->id(), 'is_shared' => true, 'definition' => $this->orderReport('preis')->toArray()]);

        $this->rejects(fn () => $this->service()->updateInterface($this->articles->refresh(), true, ['name']), 'Das Feld „preis“ wird von anderen Apps');
        $this->assertStringContainsString('Auswertung „Umsatz“', implode(' ', app(AppInterfaces::class)->usages($this->articles->refresh(), 'preis')));
    }

    public function test_table_links_reach_other_apps_only_through_shared_fields(): void
    {
        $this->service()->updateField($this->articles->fields()->where('name', 'name')->sole(), ['is_unique' => true]);
        $this->service()->updateField($this->articles->fields()->where('name', 'notiz')->sole(), ['is_unique' => true]);
        $this->service()->addField($this->orders(), ['name' => 'artikel_name', 'label' => 'Artikelname', 'type' => 'string']);
        $orders = $this->orders();
        $link = fn (string $target) => ReportLink::query()->create([
            'label' => ['de' => 'Artikel'],
            'source_table_id' => $orders->id,
            'source_field_id' => $this->fieldId('shop_bestellungen', 'artikel_name'),
            'target_table_id' => $this->articles->id,
            'target_field_id' => $this->fieldId('lg_artikel', $target),
        ]);

        $this->rejects(fn () => $link('name'), 'nur auf freigegebene Tabellen und Felder');

        $this->share(['name']);
        $links = app(ReportLinks::class);
        $this->assertSame([ReportLinks::ID, (string) $this->fieldId('lg_artikel', 'name')], array_map('strval', array_keys($links->targetOptions($this->articles->refresh(), null, $orders))));
        $this->rejects(fn () => $link('notiz'), 'nur auf freigegebene Tabellen und Felder');
        $created = $link('name');
        $this->assertSame($this->shop->id, $created->app_id);

        // Genutzt: Zielfeld ist Vertrag.
        $this->rejects(fn () => $this->service()->updateInterface($this->articles->refresh(), true, []), 'Das Feld „name“ wird von anderen Apps');
    }

    public function test_definition_carries_the_interface_and_what_an_app_requires(): void
    {
        $this->share(['name', 'preis']);
        $this->relate(['name']);
        $this->service()->addField($this->orders(), ['name' => 'stueckpreis', 'label' => 'Stückpreis', 'type' => 'decimal', 'options' => ['copy_from' => 'artikel_id.preis']]);
        $role = Role::query()->create(['name' => 'verkauf', 'guard_name' => 'web', 'app_id' => $this->shop->id]);
        $role->givePermissionTo('data.shop_bestellungen.view', 'data.lg_artikel.view', 'data.lg_artikel.update');
        $this->stock->update(['interface_version' => 3]);
        $definitions = app(AppDefinition::class);

        // Freigebende App: Freigaben und Version.
        $stock = $definitions->build($this->stock, AppDefinition::PARTS, $this->superAdmin());
        $this->assertSame(3, $stock['app']['interface_version']);
        $articles = collect($stock['tables'])->firstWhere('name', 'lg_artikel');
        $this->assertTrue($articles['is_shared']);
        $this->assertSame(['name', 'preis'], collect($articles['fields'])->where('is_shared', true)->pluck('name')->all());
        $this->assertSame([], $stock['requires']);

        // Nutzende App: requires mit App, Version und genutzten Feldern; Rechte „ausserhalb“ fallen weg.
        $shop = $definitions->build($this->shop, AppDefinition::PARTS, $this->superAdmin());
        $this->assertSame([[
            'app' => ['key' => (string) $this->stock->uuid, 'name' => ['de' => 'Lager'], 'prefix' => 'lg', 'interface_version' => 3],
            'tables' => [['key' => (string) $this->articles->uuid, 'name' => 'lg_artikel', 'fields' => ['name', 'preis']]],
        ]], $shop['requires']);
        $this->assertEqualsCanonicalizing(['data.shop_bestellungen.view', 'data.lg_artikel.view'], collect($shop['roles'])->firstWhere('name', 'verkauf')['permissions']);
    }

    public function test_installing_checks_required_interfaces_and_keeps_shares(): void
    {
        $this->share(['name', 'preis']);
        $this->relate(['name']);
        $definitions = app(AppDefinition::class);
        $installer = app(AppInstaller::class);
        $shop = $definitions->build($this->shop, AppDefinition::PARTS, $this->superAdmin());
        $copy = ['name' => 'Shop 2', 'prefix' => 'shopzwei'];

        // Fehlende Felder oder Tabellen: Hinderungsgrund.
        $broken = $shop;
        $broken['requires'][0]['tables'][0]['fields'][] = 'notiz';
        $this->assertStringContainsString('die Felder notiz', implode(' ', $installer->analyze($broken, $copy)->blockers));
        $broken['requires'][0]['tables'][0]['name'] = 'lg_fehlt';
        $broken['tables'] = array_map(static fn (array $t): array => [...$t, 'fields' => array_map(static fn (array $f): array => ($f['options']['table'] ?? null) === 'lg_artikel' ? array_replace_recursive($f, ['options' => ['table' => 'lg_fehlt']]) : $f, $t['fields'])], $broken['tables']);
        $this->assertStringContainsString('Benötigt die Schnittstelle „lg_fehlt“', implode(' ', $installer->analyze($broken, $copy)->blockers));

        // Vorhanden: Kopie verweist weiter auf die Artikel des Lagers.
        $analysis = $installer->analyze($shop, $copy);
        $this->assertSame([], $analysis->blockers);
        $this->assertSame(['Lager › lg_artikel (Felder: name)'], $analysis->requires);
        $installer->install($shop, $copy, $this->superAdmin());
        $relation = CustomTable::query()->where('name', 'shopzwei_bestellungen')->sole()->fields()->where('name', 'artikel_id')->sole();
        $this->assertSame('lg_artikel', $relation->optionsArray()['table']);

        // Kopie der freigebenden App: Freigaben bleiben, Version beginnt neu.
        $this->stock->update(['interface_version' => 4]);
        $stock = $definitions->build($this->stock, AppDefinition::PARTS, $this->superAdmin());
        $copied = $installer->install($stock, ['name' => 'Lager 2', 'prefix' => 'lgzwei'], $this->superAdmin());
        $articles = CustomTable::query()->where('name', 'lgzwei_artikel')->sole();
        $this->assertTrue($articles->is_shared);
        $this->assertSame(['name', 'preis'], $articles->fields()->where('is_shared', true)->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(1, $copied->interface_version);
    }

    public function test_field_used_by_another_app_cannot_be_hidden_in_the_designer(): void
    {
        $this->share(['name', 'preis']);
        $this->relate(['name']);
        $name = CustomField::query()->where('custom_table_id', $this->articles->id)->where('name', 'name')->sole();
        $price = CustomField::query()->where('custom_table_id', $this->articles->id)->where('name', 'preis')->sole();

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $this->articles->refresh(), 'pageClass' => EditCustomTable::class])
            ->assertActionDisabled(TestAction::make('toggleActive')->table($name))
            ->assertActionEnabled(TestAction::make('toggleActive')->table($price));
    }

    public function test_report_assistant_sees_the_current_app_and_shared_tables_only(): void
    {
        $this->share(['name']);
        $this->relate();
        $this->service()->createTable(['app_id' => $this->stock->id, 'name' => 'lg_orte', 'label' => 'Orte'], [['name' => 'ort', 'label' => 'Ort', 'type' => 'string']]);
        $admin = $this->superAdmin();
        app(CurrentApp::class)->switch($admin, $this->shop);

        $structure = collect((new FieldCatalog)->structure($admin->fresh(), false))->keyBy('label');

        $this->assertTrue($structure->has('Bestellungen'));
        $this->assertTrue($structure['Artikel']['interface'] ?? false, 'Freigegebene Tabelle als Schnittstelle.');
        $this->assertSame(['Name'], array_column($structure['Artikel']['fields'], 'label'));
        $this->assertFalse($structure->has('Orte'), 'Andere Tabellen der Lager-App nicht.');
    }
}
