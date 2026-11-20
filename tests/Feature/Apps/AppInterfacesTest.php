<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\AppMover;
use App\Apps\AppRemover;
use App\Apps\CurrentApp;
use App\DynamicData\DetailRelations;
use App\DynamicData\RelationDisplay;
use App\Exceptions\BusinessRuleException;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\App;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Role;
use App\Models\User;
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
}
