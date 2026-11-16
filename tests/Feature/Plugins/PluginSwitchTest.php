<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\PluginsPage;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\PluginState;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Plugins\Api\Saft;
use App\Plugins\PluginMenus;
use App\Plugins\PluginRegistry;
use App\Plugins\PluginStates;
use App\Plugins\SaftPlugin;
use App\Plugins\Testing\PluginTestCase;
use App\Tasks\TaskResult;
use App\Tasks\TaskRunner;
use App\Tasks\TaskType;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Saft\LagerTafel\Pages\Lagerspiegel;
use Saft\LagerUmbuchung\Pages\Umbuchung;
use Saft\LagerUmbuchung\Widgets\MinimumStockWidget;
use Spatie\Activitylog\Models\Activity;

/** B-030: Plugins ein- und ausschalten – Wirkung, Sperren, abhängige Plugins, Oberfläche, Konsole, Protokoll. */
class PluginSwitchTest extends PluginTestCase
{
    private const string TRANSFER = 'lager-umbuchung';

    private const string BOARD = 'lager-tafel';

    private function states(): PluginStates
    {
        return app(PluginStates::class);
    }

    private function plugin(string $id): SaftPlugin
    {
        return app(PluginRegistry::class)->get($id);
    }

    /** Lager-Demo im Kleinen: Artikel, Lagerort, Bestände, Bewegungen. @return array{0: int, 1: int} Artikel, Lagerort */
    private function warehouse(): array
    {
        $relation = static fn (string $name, string $table): array => ['name' => $name, 'label' => $name, 'type' => 'relation', 'options' => ['table' => $table, 'display_field' => 'bezeichnung']];
        $this->createTable('lager_artikel', 'Artikel', [
            ['name' => 'artikelnummer', 'label' => 'Nummer', 'type' => 'string', 'options' => ['length' => 20]],
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'options' => ['length' => 100]],
            ['name' => 'mindestbestand', 'label' => 'Mindestbestand', 'type' => 'integer'],
        ]);
        $this->createTable('lager_orte', 'Lagerorte', [['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'options' => ['length' => 100]]]);
        $this->createTable('lager_bestaende', 'Bestände', [
            $relation('artikel_id', 'lager_artikel'), $relation('lagerort_id', 'lager_orte'),
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'letzte_zaehlung', 'label' => 'Letzte Zählung', 'type' => 'date'],
        ]);
        $this->createTable('lager_bewegungen', 'Lagerbewegungen', [
            $relation('artikel_id', 'lager_artikel'), $relation('lagerort_id', 'lager_orte'),
            ['name' => 'art', 'label' => 'Art', 'type' => 'select', 'options' => ['options' => [['value' => 'Eingang'], ['value' => 'Ausgang'], ['value' => 'Umbuchung'], ['value' => 'Inventur']]]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'zeitpunkt', 'label' => 'Zeitpunkt', 'type' => 'datetime'],
            ['name' => 'bemerkung', 'label' => 'Bemerkung', 'type' => 'text'],
        ]);

        $article = (int) Saft::table('lager_artikel')->withoutAuthorization()->create(['artikelnummer' => 'A-1001', 'bezeichnung' => 'Schraube', 'mindestbestand' => 1])->getKey();
        $location = (int) Saft::table('lager_orte')->withoutAuthorization()->create(['bezeichnung' => 'Nord'])->getKey();

        return [$article, $location];
    }

    private function receive(int $article, int $location, int $quantity): void
    {
        Saft::table('lager_bewegungen')->withoutAuthorization()->create([
            'artikel_id' => $article, 'lagerort_id' => $location, 'art' => 'Eingang', 'menge' => $quantity, 'zeitpunkt' => '2026-11-01 08:00:00',
        ]);
    }

    private function stock(int $article): int
    {
        return (int) DynamicRecord::forTableName('lager_bestaende')->newQuery()->where('artikel_id', $article)->sum('menge');
    }

    public function test_plugins_are_enabled_without_an_entry_and_switching_is_logged(): void
    {
        $admin = $this->actingAsSuperAdmin();
        $this->assertTrue($this->plugin(self::TRANSFER)->isEnabled());
        $this->assertSame(PluginStates::ACTIVE, $this->states()->status($this->plugin('ch-pruefungen')));

        $this->states()->disable(self::TRANSFER);
        $this->states()->disable(self::TRANSFER);
        $this->assertFalse($this->plugin(self::TRANSFER)->isEnabled());
        $this->assertSame(PluginStates::DISABLED, $this->states()->status($this->plugin(self::TRANSFER)));

        $this->states()->enable(self::TRANSFER);
        $this->assertTrue($this->plugin(self::TRANSFER)->isEnabled());

        $log = Activity::query()->where('subject_type', (new PluginState)->getMorphClass())->orderBy('id')->get();
        $this->assertCount(2, $log, 'Aus und wieder ein; das zweite Ausschalten ändert nichts.');
        $this->assertSame($admin->id, $log->first()->causer_id);
    }

    public function test_switched_off_plugin_does_not_take_effect(): void
    {
        $this->actingAsSuperAdmin();
        [$article, $location] = $this->warehouse();
        $this->receive($article, $location, 5);
        $this->assertSame(5, $this->stock($article), 'Eingeschaltet: Bewegung bucht den Bestand.');

        $this->states()->disable(self::TRANSFER);
        $this->receive($article, $location, 3);
        $this->assertSame(5, $this->stock($article), 'Ausgeschaltet: Listener wirken nicht.');

        $record = DynamicRecord::forTableName('lager_bestaende')->newQuery()->firstOrFail();
        app()->setLocale('de');
        $this->actingAsSuperAdmin();
        $this->dataView('lager_bestaende')
            ->assertActionDoesNotExist(TestAction::make('lagerUmbuchung')->table($record));

        $this->states()->enable(self::TRANSFER);
        $this->receive($article, $location, 1);
        $this->assertSame(6, $this->stock($article), 'Wieder eingeschaltet: wirkt für neue Änderungen, nicht rückwirkend.');
    }

    public function test_field_rules_and_formula_functions_of_a_switched_off_plugin_are_skipped(): void
    {
        $this->assertArrayHasKey('ch-pruefungen.iban', app(PluginRegistry::class)->fieldRules());
        $this->assertArrayHasKey('rappen', app(PluginRegistry::class)->formulaFunctions());

        $this->states()->disable('ch-pruefungen');

        $this->assertArrayNotHasKey('ch-pruefungen.iban', app(PluginRegistry::class)->fieldRules());
        $this->assertArrayNotHasKey('rappen', app(PluginRegistry::class)->formulaFunctions());
    }

    public function test_pages_widgets_and_menu_entries_are_blocked(): void
    {
        $this->actingAsSuperAdmin();
        $this->warehouse();
        app(PluginMenus::class)->adopt($this->plugin(self::TRANSFER));
        $this->assertTrue(Menu::query()->where('route', Umbuchung::ROUTE)->exists());
        $this->get(Umbuchung::getUrl())->assertOk()->assertSee('Umbuchung');
        $this->assertArrayHasKey('plugin:Saft.LagerUmbuchung.Widgets.MinimumStockWidget', Livewire::test(Dashboard::class)->instance()->cards());

        $this->states()->disable(self::TRANSFER);

        $this->get(Umbuchung::getUrl())->assertNotFound();
        $this->assertTrue($this->states()->hidesMenu(Umbuchung::ROUTE, 'lager-umbuchung.execute'));
        $this->assertTrue($this->states()->hidesMenu(null, 'lager-umbuchung.execute'), 'Auch nur über das Recht.');
        $this->assertFalse($this->states()->hidesMenu(PluginsPage::ROUTE, 'plugins.view'));
        $this->get(PluginsPage::getUrl())->assertOk()->assertDontSee('href="'.Umbuchung::getUrl().'"', false);
        $this->assertTrue(Menu::query()->where('route', Umbuchung::ROUTE)->exists(), 'Eintrag bleibt gespeichert.');
        $this->assertArrayNotHasKey('plugin:Saft.LagerUmbuchung.Widgets.MinimumStockWidget', Livewire::test(Dashboard::class)->instance()->cards());
        Livewire::test(MinimumStockWidget::class)->assertNotFound();
    }

    public function test_plugins_that_require_a_switched_off_plugin_rest(): void
    {
        $this->actingAsSuperAdmin();
        $this->warehouse();
        app()->setLocale('de');
        $board = $this->plugin(self::BOARD);
        $this->assertSame([self::TRANSFER], array_map(static fn (SaftPlugin $p): string => $p->id(), $this->states()->requires($board)));
        $this->assertSame([self::BOARD], array_map(static fn (SaftPlugin $p): string => $p->id(), $this->states()->dependents($this->plugin(self::TRANSFER))));

        $this->states()->disable(self::TRANSFER);

        $this->assertTrue($board->isEnabled(), 'Selbst eingeschaltet …');
        $this->assertSame(PluginStates::RESTING, $this->states()->status($board), '… aber es ruht.');
        $this->assertSame(['Plugin Lager-Umbuchung ist ausgeschaltet'], $board->missingDependencies());
        $this->get(Lagerspiegel::getUrl())->assertOk()->assertSee('Lager-Demo nicht vollständig')->assertSee('Plugin Lager-Umbuchung ist ausgeschaltet');

        $this->states()->enable(self::TRANSFER);
        $this->assertSame(PluginStates::ACTIVE, $this->states()->status($board));
    }

    public function test_tasks_of_a_switched_off_plugin_are_skipped(): void
    {
        app(PluginRegistry::class)->add(new SwitchTestPlugin);
        $this->app->forgetScopedInstances();
        $task = ScheduledTask::create(['type' => 'switch_test', 'parameters' => [], 'frequency' => 'daily', 'at_time' => '06:00']);

        $this->assertSame(ScheduledTask::SUCCESS, app(TaskRunner::class)->run($task, TaskRun::SCHEDULE)->status);

        app()->setLocale('de');
        $this->states()->disable('aufgaben-test');
        $run = app(TaskRunner::class)->run($task, TaskRun::SCHEDULE);
        $this->assertSame(ScheduledTask::SKIPPED, $run->status);
        $this->assertSame('Übersprungen: Das Plugin „Aufgaben-Test“ ist ausgeschaltet oder ruht.', $run->message);
    }

    public function test_plugins_page_switches_with_manage_permission(): void
    {
        app()->setLocale('de');
        $this->actingAs($this->userWithPermissions(['plugins.view']));
        Livewire::test(PluginsPage::class)
            ->assertSee('aktiv')
            ->assertTableActionHidden('disablePlugin', self::TRANSFER);

        $this->actingAs($this->userWithPermissions(['plugins.view', 'plugins.manage']));
        Livewire::test(PluginsPage::class)
            ->assertTableActionVisible('disablePlugin', self::TRANSFER)
            ->assertTableActionHidden('enablePlugin', self::TRANSFER)
            ->callTableAction('disablePlugin', self::TRANSFER)
            ->assertNotified('„Lager-Umbuchung“ ist ausgeschaltet.');
        $this->assertFalse($this->states()->isEnabled(self::TRANSFER));

        // Text des Bestätigungsdialogs: abhängige Plugins und Warnung bei Listenern.
        $effects = (string) PluginsPage::disableEffects($this->plugin(self::TRANSFER));
        $this->assertStringContainsString('Diese Plugins brauchen es und ruhen mit: Lager-Tafel.', $effects);
        $this->assertStringContainsString('Achtung: Das Plugin setzt Geschäftsregeln für lager_bewegungen, lager_bestaende durch.', $effects);
        $this->assertStringNotContainsString('Achtung', (string) PluginsPage::disableEffects($this->plugin('ch-pruefungen')));

        Livewire::test(PluginsPage::class)
            ->assertSee('ausgeschaltet')
            ->assertSee('Plugin Lager-Umbuchung ist ausgeschaltet')
            ->callTableAction('enablePlugin', self::TRANSFER)
            ->assertNotified('„Lager-Umbuchung“ ist eingeschaltet.');
        $this->assertTrue($this->states()->isEnabled(self::TRANSFER));
    }

    public function test_console_switches_plugins(): void
    {
        app()->setLocale('de');
        $this->artisan('saft:plugins', ['--disable' => self::TRANSFER])
            ->expectsOutputToContain('„Lager-Umbuchung“ ist ausgeschaltet.')
            ->expectsOutputToContain('Diese Plugins ruhen jetzt mit: Lager-Tafel')
            ->assertSuccessful();
        $this->assertFalse($this->states()->isEnabled(self::TRANSFER));

        $this->artisan('saft:plugins')->expectsOutputToContain('ausgeschaltet')->assertSuccessful();
        $this->artisan('saft:plugins', ['--enable' => self::TRANSFER])->assertSuccessful();
        $this->assertTrue($this->states()->isEnabled(self::TRANSFER));
        $this->artisan('saft:plugins', ['--disable' => 'gibt-es-nicht'])->assertFailed();
    }

    public function test_switching_off_only_for_a_callback_in_tests(): void
    {
        $result = PluginStates::temporarily(self::TRANSFER, false, fn (): bool => $this->plugin(self::TRANSFER)->isEnabled());

        $this->assertFalse($result);
        $this->assertTrue($this->plugin(self::TRANSFER)->isEnabled(), 'Danach wieder wie vorher.');
        $this->assertFalse(PluginState::query()->exists(), 'Ohne Eintrag in der Datenbank.');
    }
}

/** Plugin für den Test der geplanten Aufgaben (benannt: ownerOf() ordnet über den Namensraum zu). */
final class SwitchTestPlugin extends SaftPlugin
{
    public function id(): string
    {
        return 'aufgaben-test';
    }

    public function label(): string
    {
        return 'Aufgaben-Test';
    }

    public function taskTypes(): array
    {
        return [SwitchTestTask::class];
    }
}

/** Art geplanter Aufgaben für den Test oben; gehört über den Namensraum zum Test-Plugin „aufgaben-test“. */
final class SwitchTestTask implements TaskType
{
    public function key(): string
    {
        return 'switch_test';
    }

    public function label(): string
    {
        return 'Switch-Test';
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [];
    }

    public function describe(array $parameters): string
    {
        return '';
    }

    public function run(array $parameters): TaskResult
    {
        return new TaskResult('ok');
    }
}
