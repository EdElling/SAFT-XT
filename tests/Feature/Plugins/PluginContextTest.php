<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\DynamicData\DynamicDataNamespace;
use App\Filament\Pages\PluginsPage;
use App\Models\CustomTable;
use App\Models\DatabaseConnection;
use App\Models\DynamicRecord;
use App\Plugins\Api\ApiManifest;
use App\Plugins\Context\ApiSurface;
use App\Plugins\Context\ContextBundle;
use App\Plugins\Context\SchemaExporter;
use App\Plugins\Context\SchemaImporter;
use App\Plugins\PluginDependencies;
use Database\Seeders\LagerDemoSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;
use ZipArchive;

/** Entwickler-Kontext: AGENTS.md, API-Referenz, Schema; Rundlauf über schema.json; Download nur mit Recht, ohne Daten. */
class PluginContextTest extends DynamicDataTestCase
{
    private function seedSchema(): void
    {
        $this->seed(LagerDemoSeeder::class);
        // Gegenseitige Verknüpfung: geht nur in mehreren Durchgängen.
        $departments = $this->service()->createTable(['app_id' => self::ensureTestApp()->id, 'name' => 'abteilungen', 'label' => ['de' => 'Abteilungen', 'en' => 'Departments']], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['length' => 80], 'is_required' => true],
        ]);
        $this->service()->createTable(['app_id' => self::ensureTestApp()->id, 'name' => 'personal', 'label' => 'Personal'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string', 'options' => ['length' => 80]],
            ['name' => 'abteilung_id', 'label' => 'Abteilung', 'type' => 'relation', 'options' => ['table' => 'abteilungen', 'display_field' => 'name']],
        ]);
        $this->service()->addField($departments, ['name' => 'leitung_id', 'label' => 'Leitung', 'type' => 'relation', 'options' => ['table' => 'personal', 'display_field' => 'name']]);
        $this->service()->addField($departments->refresh(), ['name' => 'kopfzahl', 'label' => 'Kopfzahl', 'type' => 'computed', 'options' => ['result' => 'integer', 'formula' => 'count(personal)']]);
    }

    private function wipeDesignerTables(): void
    {
        app(DynamicDataNamespace::class)->reset();
        CustomTable::query()->where('source', CustomTable::SOURCE_DESIGNER)->each(static fn (CustomTable $t) => $t->fields()->delete());
        CustomTable::query()->where('source', CustomTable::SOURCE_DESIGNER)->delete();
        DynamicRecord::forget();
        app(PluginDependencies::class)->flush();
    }

    public function test_schema_round_trip_rebuilds_the_same_structure(): void
    {
        $this->actingAs($this->superAdmin());
        $this->seedSchema();
        $before = app(SchemaExporter::class)->export();
        $this->assertSame(SchemaExporter::FORMAT, $before['format']);

        $this->wipeDesignerTables();
        $this->assertSame([], app(SchemaExporter::class)->export()['tables']);

        $created = app(SchemaImporter::class)->import(json_decode((string) json_encode($before), true), app: self::ensureTestApp());
        $this->assertArrayHasKey('lager_bewegungen', $created);
        $this->assertEquals($before['tables'], app(SchemaExporter::class)->export()['tables'], 'Gleiche Tabellen, Felder, Regeln und Detailtabellen.');

        // Die nachgebauten Tabellen funktionieren: Verknüpfung, Formel über Kind-Tabelle.
        $department = DynamicRecord::forTableName('abteilungen')->create(['name' => 'Einkauf']);
        DynamicRecord::forTableName('personal')->create(['name' => 'Muster', 'abteilung_id' => $department->getKey()]);
        $this->assertSame(1, (int) $department->fresh()->getAttribute('kopfzahl'));
    }

    public function test_import_rejects_unknown_formats(): void
    {
        $this->expectExceptionMessage('Unbekanntes Schemaformat');
        app(SchemaImporter::class)->import(['format' => 'saft-schema/99', 'tables' => []]);
    }

    public function test_bundle_contains_rules_reference_and_schema_without_data(): void
    {
        $this->actingAs($this->superAdmin());
        $this->seedSchema();
        $connection = DatabaseConnection::create(['name' => 'extern', 'driver' => 'pgsql', 'host' => 'db.example', 'port' => 5432, 'database' => 'x', 'username' => 'leser', 'password' => 'Geheim-123!']);

        $files = app(ContextBundle::class)->files();
        $this->assertSame(['AGENTS.md', 'api-reference.md', 'schema.md', 'schema.json'], array_keys($files));

        $this->assertStringContainsString('plugin API '.ApiManifest::current(), $files['AGENTS.md']);
        $this->assertStringContainsString('`lager-umbuchung` 1.5.0', $files['AGENTS.md']);
        $this->assertStringContainsString('`ch-pruefungen.iban`', $files['AGENTS.md']);
        $this->assertStringNotContainsString('{{', $files['AGENTS.md']);

        foreach (array_keys(ApiSurface::classes()) as $class) {
            $this->assertStringContainsString('`'.$class.'`', $files['api-reference.md']);
        }
        $this->assertStringContainsString('public static function table', str_replace('static function table', 'public static function table', $files['api-reference.md']));

        $this->assertStringContainsString('## `lager_bewegungen` – Stock movements (Lagerbewegungen)', $files['schema.md']);
        $this->assertStringContainsString('formula `count(personal)`', $files['schema.md']);

        // Keine Datensätze, keine Zugangsdaten.
        $all = implode("\n", $files);
        $this->assertStringNotContainsString('Geheim-123!', $all);
        $this->assertStringNotContainsString('db.example', $all);
        $this->assertStringNotContainsString((string) DynamicRecord::forTableName('lager_artikel')->newQuery()->value('bezeichnung'), $all);
        $connection->delete();
    }

    public function test_console_writes_folder_and_zip(): void
    {
        $directory = storage_path('framework/testing/context-'.uniqid());

        try {
            $this->artisan('saft:plugin-context', ['--output' => $directory])->assertSuccessful();
            $this->assertFileExists($directory.'/AGENTS.md');
            $this->assertFileExists($directory.'/schema.json');

            $this->artisan('saft:plugin-context', ['--output' => $directory.'/ctx.zip', '--zip' => true])->assertSuccessful();
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($directory.'/ctx.zip'));
            $this->assertNotFalse($zip->locateName('saft-plugin-context/api-reference.md'));
            $zip->close();

            $this->artisan('saft:plugin-context', ['--output' => $directory, '--tables' => ['gibt_es_nicht']])->assertFailed();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    public function test_download_needs_the_manage_permission_and_is_logged(): void
    {
        $this->actingAs($this->userWithPermissions(['plugins.view']));
        Livewire::test(PluginsPage::class)->assertActionHidden(TestAction::make('developerContext'));

        $user = $this->userWithPermissions(['plugins.view', 'plugins.manage']);
        $this->actingAs($user);
        $this->seedSchema();

        Livewire::test(PluginsPage::class)
            ->assertActionVisible(TestAction::make('developerContext'))
            // Gruppiert nach Menügruppe; ohne Menüeintrag unter „Ohne Menüeintrag“ – dort nur „abteilungen“ wählen.
            ->callAction(TestAction::make('developerContext'), ['tables' => [md5('Ohne Menüeintrag') => ['abteilungen']], 'external' => false])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();

        $activity = Activity::query()->where('event', 'context_exported')->sole();
        $this->assertSame($user->id, $activity->causer_id);
        $exported = $activity->attribute_changes['attributes']['tabellen'] ?? $activity->properties['attributes']['tabellen'] ?? [];
        $this->assertContains('abteilungen', $exported);
        $this->assertNotContains('personal', $exported, 'Abgewählt.');
        $this->assertContains('lager_artikel', $exported, 'Andere Gruppe (Lager-Demo): vorausgewählt.');
    }
}
