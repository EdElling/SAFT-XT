<?php

declare(strict_types=1);

namespace Tests\Feature\Apps;

use App\Apps\Definition\AppDefinition;
use App\Filament\Resources\Apps\Actions\InstallAppAction;
use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Apps\Tables\AppsTable;
use App\Filament\Resources\TableConnector\Pages\ListConnectedTables;
use App\Models\App;
use App\Models\AppBaseline;
use App\Models\CustomTable;
use App\Models\User;
use Database\Seeders\ExternalResearchDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Concerns\UsesExternalResearchDatabase;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Oberfläche zum Installieren, Hinweise in der Übersicht, „Verbindung zuordnen“ (B-107, Etappe 4b). */
class AppInstallUiTest extends DynamicDataTestCase
{
    use UsesExternalResearchDatabase;

    private App $sales;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
        $this->sales = self::ensureTestApp();
        $this->sales->update(['name' => ['de' => 'Verkauf'], 'prefix' => 'vk', 'version' => '1.0']);
        $this->service()->createTable(['app_id' => $this->sales->id, 'name' => 'vk_kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string'],
        ]);
    }

    private function file(): UploadedFile
    {
        $definition = app(AppDefinition::class)->build($this->sales, AppDefinition::PARTS, $this->admin);

        return UploadedFile::fake()->createWithContent('vk-1.0.saft-app.json', (string) json_encode($definition));
    }

    public function test_install_dialog_installs_a_copy_and_refuses_the_same_app_twice(): void
    {
        // Gleiche App nochmals: abgelehnt, nichts angelegt.
        Livewire::test(ListApps::class)
            ->callAction('installApp', ['file' => [$this->file()], 'mode' => InstallAppAction::NEW])
            ->assertNotified(__('apps.install.errors.installed', ['app' => 'Verkauf']).' '.__('apps.install.errors.table_exists', ['table' => 'vk_kunden', 'app' => 'Verkauf']));
        $this->assertSame(1, App::query()->count());

        // Als Kopie: neue, deaktivierte App mit eigenen Tabellen und Ausgangsstand.
        Livewire::test(ListApps::class)
            ->callAction('installApp', ['file' => [$this->file()], 'mode' => InstallAppAction::COPY, 'copy_name' => 'Verkauf Test', 'copy_prefix' => 'vktest'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('apps.install.done', ['name' => 'Verkauf Test']));

        $copy = App::query()->where('prefix', 'vktest')->sole();
        $this->assertFalse($copy->is_active);
        $this->assertSame(['vktest_kunden'], $copy->customTables()->pluck('name')->all());
        $this->assertSame(AppBaseline::COPY, $copy->baseline()->kind);

        // Ohne Recht keine Aktion.
        $this->actingAs($this->userWithPermissions(['apps.view', 'apps.manage', 'apps.export']));
        Livewire::test(ListApps::class)->assertActionHidden('installApp');
    }

    public function test_overview_keeps_notes_on_missing_plugins_and_connections(): void
    {
        AppBaseline::query()->create(['app_id' => $this->sales->id, 'kind' => AppBaseline::INSTALLED, 'version' => '1.0', 'definition' => ['plugins' => ['gibt-es-nicht' => '1.0']], 'installed_by' => $this->admin->id]);
        CustomTable::query()->create(['app_id' => $this->sales->id, 'name' => 'vk_erp', 'label' => ['de' => 'ERP'], 'source' => CustomTable::SOURCE_EXTERNAL, 'external_table' => 'erp', 'is_active' => false]);

        $this->assertSame([__('apps.notices.plugins', ['plugins' => 'gibt-es-nicht']), trans_choice('apps.notices.unconnected', 1, ['count' => 1])], AppsTable::notices($this->sales));
        Livewire::test(ListApps::class)
            ->assertTableColumnStateSet('installed', __('apps.installed.installed', ['version' => '1.0', 'date' => now()->format('d.m.Y')]), $this->sales);
    }

    public function test_connection_can_be_assigned_to_a_connected_table_without_one(): void
    {
        $this->prepareExternalDatabase();
        $connection = $this->researchConnection();
        $table = CustomTable::query()->create(['app_id' => $this->sales->id, 'name' => 'vk_laender', 'label' => ['de' => 'Länder'], 'source' => CustomTable::SOURCE_EXTERNAL,
            'external_schema' => ExternalResearchDatabase::SCHEMA, 'external_table' => 'laender', 'external_kind' => 'table', 'key_columns' => ['code'], 'is_active' => false]);
        $missing = CustomTable::query()->create(['app_id' => $this->sales->id, 'name' => 'vk_fehlt', 'label' => ['de' => 'Fehlt'], 'source' => CustomTable::SOURCE_EXTERNAL,
            'external_schema' => ExternalResearchDatabase::SCHEMA, 'external_table' => 'gibt_es_nicht', 'is_active' => false]);

        Livewire::test(ListConnectedTables::class)
            ->assertTableActionVisible('assignConnection', $table)
            ->callTableAction('assignConnection', $table, ['connection' => $connection->id])
            ->assertNotified(__('table_connector.assign.done', ['table' => 'Länder']));
        $table->refresh();
        $this->assertSame($connection->id, $table->database_connection_id);
        $this->assertFalse($table->is_active, 'Bleibt inaktiv, bis man sie aktiviert.');
        $this->assertNotEmpty($table->fields()->pluck('name')->all(), 'Spalten aus der Datenbank übernommen.');

        Livewire::test(ListConnectedTables::class)
            ->callTableAction('assignConnection', $missing, ['connection' => $connection->id])
            ->assertNotified(__('table_connector.assign.failed'));
        $this->assertNull($missing->fresh()->database_connection_id);
        Livewire::test(ListConnectedTables::class)->assertTableActionHidden('assignConnection', $table->fresh());
    }
}
