<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Apps\CurrentApp;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MySettingsPage;
use App\Filament\Resources\CustomTables\Schemas\FieldForm;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\User;
use App\Plugins\Api\DataAction;
use App\Plugins\PluginRegistry;
use App\Plugins\Testing\PluginTestCase;
use App\Tasks\TaskRegistry;
use Livewire\Livewire;
use Saft\LagerTafel\Pages\Lagerspiegel;

/** B-063: Seiten, Kacheln, Knöpfe und Aufgabenarten aus Plugins zeigen ihre Herkunft. */
class PluginOriginTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $relation = static fn (string $name, string $table): array => ['name' => $name, 'label' => $name, 'type' => 'relation', 'options' => ['table' => $table, 'display_field' => 'bezeichnung']];
        $this->createTable('lager_artikel', 'Artikel', [
            ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string'],
            ['name' => 'mindestbestand', 'label' => 'Mindestbestand', 'type' => 'integer'],
        ]);
        $this->createTable('lager_orte', 'Lagerorte', [['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string']]);
        $this->createTable('lager_bestaende', 'Bestände', [$relation('artikel_id', 'lager_artikel'), $relation('lagerort_id', 'lager_orte'), ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'], ['name' => 'letzte_zaehlung', 'label' => 'Zählung', 'type' => 'date']]);
        $this->createTable('lager_bewegungen', 'Lagerbewegungen', [
            $relation('artikel_id', 'lager_artikel'), $relation('lagerort_id', 'lager_orte'),
            ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['options' => [['value' => 'Eingang']]]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'zeitpunkt', 'label' => 'Zeitpunkt', 'type' => 'datetime'],
        ]);
    }

    public function test_plugin_pages_show_their_plugin_below_the_title(): void
    {
        $this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view', 'data.lager_orte.view', 'data.lager_bestaende.view']);

        $this->get(Lagerspiegel::getUrl())->assertOk()
            ->assertSee('data-plugin-origin="lager-tafel"', false)
            ->assertSee('Plugin: Lager-Tafel')
            ->assertSee('title="Aus dem Plugin Lager-Tafel (Version', false)
            ->assertDontSee('href="'.url('/admin/plugins').'"', false);

        $this->get(MySettingsPage::getUrl())->assertOk()->assertDontSee('data-plugin-origin', false);

        $this->actingWithPermissions(['lager-tafel.view', 'plugins.view', 'data.lager_artikel.view', 'data.lager_orte.view', 'data.lager_bestaende.view']);
        $this->get(Lagerspiegel::getUrl())->assertSee('href="'.url('/admin/plugins').'"', false);
    }

    public function test_dashboard_cards_from_plugins_show_their_plugin(): void
    {
        $this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view', 'data.lager_orte.view', 'data.lager_bestaende.view']);

        Livewire::test(Dashboard::class)
            ->assertSee('data-plugin-origin="lager-tafel"', false)
            ->assertSee('saft-plugin-origin-card', false);
    }

    public function test_dashboard_cards_from_plugins_appear_only_in_their_app(): void
    {
        $user = $this->actingWithPermissions(['lager-tafel.view', 'data.lager_artikel.view', 'data.lager_orte.view', 'data.lager_bestaende.view']);
        $own = CustomTable::query()->where('name', 'lager_artikel')->value('app_id');
        $other = App::query()->create(['name' => ['de' => 'Andere'], 'prefix' => 'andere', 'sort_order' => 9]);

        $user->assignRole(User::ROLE_SUPER_ADMIN); // Zugang zu beiden Apps
        app(CurrentApp::class)->switch($user, $own);
        $this->assertContains('plugin:Saft.LagerTafel.Widgets.StockByLocationChart', array_keys(Livewire::test(Dashboard::class)->instance()->cards()));

        // In einer anderen App: keine Kacheln des Lager-Plugins (Systemkacheln bleiben).
        $user->forceFill(['current_app_id' => $other->id])->save();
        $cards = array_keys(Livewire::test(Dashboard::class)->instance()->cards());
        $this->assertNotContains('plugin:Saft.LagerTafel.Widgets.StockByLocationChart', $cards);
    }

    public function test_buttons_in_data_tables_name_their_plugin_in_the_tooltip(): void
    {
        $table = CustomTable::query()->where('name', 'lager_artikel')->sole();
        $actions = app(PluginRegistry::class)->dataActions($table, DataAction::TABLE);
        $this->assertSame('Aus dem Plugin Lager-Tafel', $actions[0]->getTooltip(), 'Ohne eigenen Tooltip nur die Herkunft.');

        $actions = app(PluginRegistry::class)->dataActions($table, DataAction::RECORD);
        $this->assertSame('Auf Lagertafel verteilen · Aus dem Plugin Lager-Tafel', $actions[0]->getTooltip(), 'Eigener Tooltip bleibt vorne.');
    }

    public function test_field_rules_from_plugins_show_symbol_and_plugin(): void
    {
        $options = (new \ReflectionMethod(FieldForm::class, 'pluginCheckOptions'))->invoke(null, 'string', ['weg.<b>']);

        $this->assertStringContainsString('<svg', $options['ch-pruefungen.iban']);
        $this->assertStringContainsString('<span>IBAN</span><span class="saft-plugin-option-source">CH-Prüfungen</span>', $options['ch-pruefungen.iban']);
        $this->assertStringContainsString('title="Aus dem Plugin CH-Prüfungen"', $options['ch-pruefungen.iban']);
        $this->assertStringContainsString('weg.&lt;b&gt;', $options['weg.<b>'], 'Nicht verfügbare Regel maskiert (Liste zeigt HTML).');
    }

    public function test_task_types_from_plugins_carry_their_plugin(): void
    {
        $options = app(TaskRegistry::class)->creatableOptions();

        $this->assertSame('Lager: Inventur-Erinnerung (Plugin Lager-Tafel)', $options['lager_tafel_inventory_reminder']);
        $this->assertSame('Datenbank sichern', app(TaskRegistry::class)->labelOf(app(TaskRegistry::class)->get('db_backup')), 'Eigene Arten ohne Zusatz.');
    }

    public function test_plugin_task_types_are_offered_only_in_their_app(): void
    {
        $own = (int) CustomTable::query()->where('name', 'lager_artikel')->value('app_id');
        $other = App::query()->create(['name' => ['de' => 'Andere'], 'prefix' => 'andere2', 'sort_order' => 9]);
        $registry = app(TaskRegistry::class);

        $this->assertArrayHasKey('lager_tafel_inventory_reminder', $registry->creatableOptions($own));
        $this->assertArrayNotHasKey('lager_tafel_inventory_reminder', $registry->creatableOptions($other->id));
        $this->assertArrayHasKey('lager_tafel_inventory_reminder', $registry->creatableOptions(), 'Ohne App: alle.');
        // Plugins ohne eigene App (z. B. CH-Prüfungen) überall.
        $this->assertArrayHasKey('ch-pruefungen.iban', app(PluginRegistry::class)->fieldRules('string', $other->id));
    }
}
