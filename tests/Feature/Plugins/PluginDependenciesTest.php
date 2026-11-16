<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Pages\PluginsPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\Pages\ListCustomTables;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\RecordListener;
use App\Plugins\PluginDependencies;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Tables\Columns\TextColumn;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Etappe 3: Tabellen und Felder, die Plugins brauchen – Prüfung, Ruhezustand, Schutz im Table-Designer. */
class PluginDependenciesTest extends DynamicDataTestCase
{
    private CustomTable $customers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->superAdmin());
        app()->setLocale('de');
        $this->customers = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'options' => ['length' => 40]],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'string', 'options' => ['length' => 40]],
        ]);
    }

    /** @param  array<string, list<string>>  $required */
    private function plugin(array $required): SaftPlugin
    {
        $plugin = new class($required) extends SaftPlugin
        {
            public static int $calls = 0;

            /** @param  array<string, list<string>>  $required */
            public function __construct(private readonly array $required) {}

            public function id(): string
            {
                return 'test-abhaengig';
            }

            public function label(): string
            {
                return 'Test-Plugin';
            }

            public function requiredTables(): array
            {
                return $this->required;
            }

            public function recordListeners(): array
            {
                return [new RecordListener('kunden', RecordSaved::class, static function (): void {
                    self::$calls++;
                })];
            }

            public function dataActions(): array
            {
                return [new DataAction('kunden', DataAction::TABLE, static fn (): Action => Action::make('testKunden'))];
            }
        };
        app(PluginRegistry::class)->add($plugin);
        app(PluginRegistry::class)->listen($plugin);

        return $plugin;
    }

    private function field(string $name): CustomField
    {
        return $this->customers->fields()->where('name', $name)->sole();
    }

    public function test_missing_and_inactive_tables_and_fields_are_reported(): void
    {
        $plugin = $this->plugin(['kunden' => ['firma', 'telefon'], 'auftraege' => []]);

        $this->assertSame(['Feld kunden.telefon fehlt', 'Tabelle auftraege fehlt'], $plugin->missingDependencies());
        $this->assertFalse(app(PluginDependencies::class)->satisfied($plugin));

        $this->service()->addField($this->customers, ['name' => 'telefon', 'label' => 'Telefon', 'type' => 'string']);
        $this->service()->createTable(['name' => 'auftraege', 'label' => 'Aufträge'], [['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string']]);
        $this->assertSame([], $plugin->missingDependencies(), 'Neu geprüft, sobald sich Tabellen oder Felder ändern.');

        $details = app(PluginDependencies::class)->details($plugin);
        $this->assertSame(['kunden', 'Kunden', 'ok'], [$details[0]['name'], $details[0]['label'], $details[0]['state']]);
        $this->assertSame([['name' => 'firma', 'label' => 'Firma', 'state' => 'ok'], ['name' => 'telefon', 'label' => 'Telefon', 'state' => 'ok']], $details[0]['fields']);
    }

    public function test_required_tables_and_fields_are_protected_in_the_table_designer(): void
    {
        $this->plugin(['kunden' => ['firma']]);
        $attempts = [
            'deaktivieren' => fn () => $this->service()->updateField($this->field('firma'), ['is_active' => false]),
            'umbenennen' => fn () => $this->service()->updateField($this->field('firma'), ['name' => 'name']),
            'Typ ändern' => fn () => $this->service()->updateField($this->field('firma'), ['type' => 'text']),
            'Tabelle deaktivieren' => fn () => $this->service()->updateTable($this->customers, ['is_active' => false]),
        ];

        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                $this->fail("{$what} trotz Plugin.");
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString('Plugin „Test-Plugin“ gebraucht', $e->getMessage(), $what);
            }
        }

        // Alles andere bleibt frei.
        $this->service()->updateField($this->field('firma'), ['label' => ['de' => 'Firmenname'], 'is_required' => true]);
        $this->service()->updateField($this->field('ort'), ['is_active' => false]);
        $this->service()->dropField($this->field('ort'), 'ort');
        $this->assertSame('Firmenname', $this->field('firma')->getTranslation('label', 'de'));
        $this->assertTrue((bool) $this->customers->fresh()->is_active);
    }

    public function test_protected_items_cannot_be_dropped_even_if_inactive(): void
    {
        $this->service()->updateField($this->field('firma'), ['is_active' => false]);
        $this->service()->updateTable($this->customers, ['is_active' => false]);
        $this->plugin(['kunden' => ['firma']]);

        foreach ([fn () => $this->service()->dropField($this->field('firma'), 'firma'), fn () => $this->service()->dropTable($this->customers->fresh(), 'kunden')] as $drop) {
            try {
                $drop();
                $this->fail('Gelöscht trotz Plugin.');
            } catch (BusinessRuleException $e) {
                $this->assertStringContainsString('Test-Plugin', $e->getMessage());
            }
        }
    }

    public function test_plugin_with_missing_dependencies_rests(): void
    {
        $plugin = $this->plugin(['kunden' => ['firma', 'telefon']]);
        $plugin::$calls = 0;

        DynamicRecord::forTableName('kunden')->create(['firma' => 'ACME']);
        $this->assertSame(0, $plugin::$calls, 'Listener ruhen.');
        Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])->assertActionDoesNotExist(TestAction::make('testKunden')->table());

        $this->service()->addField($this->customers->fresh(), ['name' => 'telefon', 'label' => 'Telefon', 'type' => 'string']);
        DynamicRecord::forTableName('kunden')->create(['firma' => 'Beta']);
        $this->assertSame(1, $plugin::$calls);
        Livewire::test(DynamicDataPage::class, ['table' => 'kunden'])->assertActionExists(TestAction::make('testKunden')->table());
    }

    public function test_plugins_page_shows_location_dependencies_and_resting_plugins(): void
    {
        $this->plugin(['kunden' => ['firma', 'telefon']]);

        Livewire::test(PluginsPage::class)
            ->assertSee('plugins/lager-umbuchung/src/LagerUmbuchungPlugin.php')
            ->assertSee('Paket saft/lager-umbuchung')
            ->assertSee('Plugins ruhen')
            ->assertSee('Feld kunden.telefon fehlt')
            ->assertSeeHtml('data-plugin-dependencies-button="test-abhaengig"')
            ->mountAction('dependencies', ['plugin' => 'test-abhaengig'])
            ->assertActionMounted('dependencies');

        $html = view('filament.plugins.dependencies', ['tables' => app(PluginDependencies::class)->details(app(PluginRegistry::class)->get('test-abhaengig'))])->render();
        $this->assertStringContainsString('data-dependency-field="kunden.telefon"', $html);
        $this->assertStringContainsString('fehlt', $html);
        $this->assertStringNotContainsString(':count', $html);
    }

    public function test_table_designer_shows_and_locks_protected_fields(): void
    {
        $this->plugin(['kunden' => ['firma']]);

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $this->customers, 'pageClass' => EditCustomTable::class])
            ->assertSee('Wird vom Plugin „Test-Plugin“ gebraucht')
            ->assertActionDisabled(TestAction::make('toggleActive')->table($this->field('firma')))
            ->assertActionEnabled(TestAction::make('toggleActive')->table($this->field('ort')));

        Livewire::test(EditCustomTable::class, ['record' => $this->customers->id])
            ->assertSee('bleibt aktiv und lässt sich nicht löschen')
            ->assertFormFieldIsDisabled('is_active');
    }

    public function test_table_list_marks_tables_required_by_plugins(): void
    {
        $this->service()->createTable(['name' => 'notizen', 'label' => 'Notizen'], [
            ['name' => 'text', 'label' => 'Text', 'type' => 'string', 'options' => ['length' => 100]],
        ]);
        $this->plugin(['kunden' => ['firma']]);

        Livewire::test(ListCustomTables::class)
            ->assertTableColumnStateSet('label', 'Kunden', $this->customers)
            ->assertSeeHtml('Wird vom Plugin „Test-Plugin“ gebraucht')
            ->assertTableColumnExists('label', fn (TextColumn $column): bool => $column->getIcon('Kunden') === 'heroicon-o-puzzle-piece', $this->customers)
            ->assertTableColumnExists('label', fn (TextColumn $column): bool => $column->getIcon('Notizen') === null, CustomTable::query()->where('name', 'notizen')->sole());
    }
}
