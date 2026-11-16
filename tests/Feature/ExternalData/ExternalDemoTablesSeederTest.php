<?php

declare(strict_types=1);

namespace Tests\Feature\ExternalData;

use App\DynamicData\DataMenu;
use App\DynamicData\DetailRelations;
use App\DynamicData\RelationDisplay;
use App\DynamicData\Schema\FieldType;
use App\Exceptions\BusinessRuleException;
use App\ExternalData\TableConnectorService;
use App\Filament\Pages\DataModelPage;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use Database\Seeders\ExternalDemoTablesSeeder;
use Database\Seeders\ExternalResearchDatabase;
use Database\Seeders\ForschungDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\TestCase;

/** Demo-Konfiguration der angebundenen Forschungstabellen (external-demo:install). */
class ExternalDemoTablesSeederTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;
    use UsesExternalResearchDatabase;

    /** Die interne Demo legt Tabellen in app_data an: auch dort zurückrollen. */
    protected array $connectionsToTransact = [null, 'app_data'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAdminStructure();
        $this->prepareExternalDatabase();
    }

    public function test_tables_relations_details_and_menu_are_configured_once(): void
    {
        $connection = $this->researchConnection();
        $this->assertSame(6, app(ExternalDemoTablesSeeder::class)->install($connection));
        $this->assertSame(0, app(ExternalDemoTablesSeeder::class)->install($connection));

        $tables = CustomTable::query()->external()->with('fields')->get()->keyBy('name');
        $this->assertCount(6, $tables);
        $this->assertSame('People', $tables['fdb_personen']->getTranslation('label', 'en'));
        $this->assertSame('Arbeitgeber', $tables['fdb_personen']->fields->firstWhere('name', 'arbeitgeber_id')->getTranslation('label', 'de'));

        $leader = $tables['fdb_projekte']->fields->firstWhere('name', 'projektleiter_id');
        $this->assertSame(FieldType::Relation, $leader->type);
        $this->assertSame(['vorname', 'nachname'], RelationDisplay::fields($leader->optionsArray()));
        // Verknüpfung über uuid: über die Auswahl beschreibbar (Zuordnungen anlegen).
        $this->assertFalse($tables['fdb_publikation_autoren']->fields->firstWhere('name', 'publikation_id')->is_read_only);

        $this->assertSame(
            ['fdb_projekte.projektleiter_id', 'fdb_publikation_autoren.person_id'],
            DetailRelations::configuredFor($tables['fdb_personen'])->map(fn ($f): string => $f->child->name.'.'.$f->column())->all(),
        );

        $group = Menu::query()->whereNull('parent_id')->where('label->de', ExternalDemoTablesSeeder::GROUP)->sole();
        $this->assertSame(5, Menu::query()->where('parent_id', $group->id)->where('route', DataMenu::ROUTE)->count());
        $this->assertSame(1, Menu::query()->where('parent_id', $group->id)->where('route', DataModelPage::ROUTE)->count(), 'Ein Datenmodell-Eintrag, auch nach wiederholtem Lauf.');
        $overview = Menu::query()->where('route_parameters->table', 'fdb_projekt_uebersicht')->sole();
        $this->assertSame('Daten', $overview->parent->getTranslation('label', 'de'));
    }

    public function test_demo_command_connects_the_tables(): void
    {
        $this->artisan('external-demo:install', ['--database' => $this->externalDatabaseName(), '--name' => 'Demo'])->assertSuccessful();

        $this->assertSame(6, CustomTable::query()->external()->count());
    }

    public function test_internal_and_external_research_demo_coexist(): void
    {
        $this->assertSame(6, app(ExternalDemoTablesSeeder::class)->install($this->researchConnection()));
        $this->seed(ForschungDemoSeeder::class);

        $this->assertSame(6, CustomTable::query()->external()->where('name', 'like', 'fdb\_%')->count());
        $this->assertTrue(CustomTable::query()->where('name', 'forschung_personen')->where('source', CustomTable::SOURCE_DESIGNER)->exists());
    }

    public function test_demos_stop_before_changing_anything_when_a_name_is_taken(): void
    {
        $connection = $this->researchConnection();

        // Externe Demo: ein Name gehört einer SAFT-Tabelle.
        app(DynamicSchemaService::class)->createTable(['name' => 'fdb_personen', 'label' => 'Eigene Personen'], [['name' => 'name', 'label' => 'Name', 'type' => 'string']]);

        try {
            app(ExternalDemoTablesSeeder::class)->install($connection);
            $this->fail('Abbruch erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('fdb_personen (SAFT-Tabelle)', $e->getMessage());
        }
        $this->assertSame(0, CustomTable::query()->external()->count(), 'Nichts halb angebunden.');

        // Interne Demo: ein Name gehört einer angebundenen Tabelle.
        app(TableConnectorService::class)->connect($connection, ExternalResearchDatabase::SCHEMA, 'organisationen', ['name' => 'forschung_organisationen']);

        try {
            $this->seed(ForschungDemoSeeder::class);
            $this->fail('Abbruch erwartet');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString('forschung_organisationen', $e->getMessage());
        }
        $this->assertSame(0, CustomTable::query()->where('source', CustomTable::SOURCE_DESIGNER)->where('name', 'like', 'forschung\_%')->count());
    }
}
