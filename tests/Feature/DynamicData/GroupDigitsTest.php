<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Export\ExportFormat;
use App\DynamicData\Import\ImportAnalyzer;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\CustomTables\RelationManagers\CustomFieldsRelationManager;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Reports\Export\ReportExporter;
use App\Reports\ReportDefinition;
use App\Reports\ReportDescriber;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use App\Services\DynamicSchemaService;
use Database\Seeders\ForschungDemoSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/** Ganzzahlen ohne Tausendertrennzeichen (Jahre, Nummern): Feld-Option group_digits. */
class GroupDigitsTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());

        app(DynamicSchemaService::class)->createTable(['name' => 'publikationen', 'label' => 'Publikationen'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string'],
            ['name' => 'jahr', 'label' => 'Jahr', 'type' => 'integer', 'options' => ['group_digits' => false]],
            ['name' => 'auflage', 'label' => 'Auflage', 'type' => 'integer', 'options' => ['group_digits' => true]],
        ]);

        foreach ([['A', 2024, 1500], ['B', 2024, 2500], ['C', 2025, 1200]] as [$titel, $jahr, $auflage]) {
            DynamicRecord::forTableName('publikationen')->create(compact('titel', 'jahr', 'auflage'));
        }
    }

    public function test_option_is_stored_only_when_switched_off(): void
    {
        $fields = CustomTable::query()->where('name', 'publikationen')->sole()->fields->keyBy('name');

        $this->assertSame(['group_digits' => false], $fields['jahr']->optionsArray());
        $this->assertSame([], $fields['auflage']->optionsArray(), 'Vorgabe (mit Trennzeichen) wird nicht gespeichert.');
        $this->assertFalse($fields['jahr']->groupsDigits());
        $this->assertTrue($fields['auflage']->groupsDigits());
    }

    public function test_data_table_shows_years_without_separator(): void
    {
        Livewire::test(DynamicDataPage::class, ['table' => 'publikationen'])
            ->assertSee('2024')
            ->assertDontSee('2.024')
            ->assertSee('1.500');
    }

    public function test_reports_and_export_show_years_without_separator(): void
    {
        $field = fn (string $name): int => (int) CustomField::query()->whereHas('custom_table', fn ($q) => $q->where('name', 'publikationen'))->where('name', $name)->value('id');
        $definition = ReportDefinition::fromArray([
            'base' => (int) CustomTable::query()->where('name', 'publikationen')->value('id'),
            'fields' => [['key' => 'j', 'path' => [], 'field' => $field('jahr')], ['key' => 'a', 'path' => [], 'field' => $field('auflage')]],
            'groups' => ['j'],
            'measures' => [['key' => 's', 'field' => 'a', 'agg' => 'sum'], ['key' => 'jmax', 'field' => 'j', 'agg' => 'max'], ['key' => 'jsum', 'field' => 'j', 'agg' => 'sum']],
            'filters' => [['field' => 'j', 'op' => 'gte', 'value' => '2024']],
        ]);
        $result = app(ReportRunner::class)->run($definition, auth()->user());
        $formatter = new ValueFormatter;

        $this->assertSame('2024', $formatter->format(2024, $result->column('j')));
        $this->assertSame('2025', $formatter->format(2025, $result->column('jmax')), 'Maximum eines Jahres bleibt ein Jahr.');
        $this->assertSame('4.048', $formatter->format(4048, $result->column('jsum')), 'Summe ist wieder eine Menge.');
        $this->assertSame('4.000', $formatter->format(4000, $result->column('s')));
        $this->assertStringContainsString('nur Jahr mindestens „2024“', app(ReportDescriber::class)->describe($result->report));

        // Excel-Zahlenformat: Jahr ohne Trennzeichen, Summe mit.
        $format = new \ReflectionMethod(ReportExporter::class, 'numberFormat');
        $exporter = app(ReportExporter::class);
        $this->assertSame(['0', '0', '#,##0', '#,##0'], array_map(
            fn (string $key): string => $format->invoke($exporter, $result->column($key)),
            ['j', 'jmax', 'jsum', 's'],
        ));
        $this->assertFileExists($path = $exporter->write($result, ExportFormat::Xlsx, 'Jahre'));
        unlink($path);
    }

    public function test_import_switches_it_off_for_year_columns(): void
    {
        $dir = sys_get_temp_dir().'/saft-digits-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        file_put_contents($path = $dir.'/liste.csv', "Titel;Erscheinungsjahr;Menge\nA;2024;1500\nB;1999;2500\n");
        $columns = collect(app(ImportAnalyzer::class)->analyze($path, 'liste.csv')->columns)->keyBy('name');
        File::deleteDirectory($dir);

        $this->assertSame(['group_digits' => false], $columns['erscheinungsjahr']->options);
        $this->assertSame([], $columns['menge']->options, 'Vierstellige Mengen ohne „Jahr“ im Titel bleiben unverändert.');
    }

    public function test_research_demo_year_without_separator_also_for_loaded_demo(): void
    {
        $this->seed(ForschungDemoSeeder::class);
        $year = CustomTable::query()->where('name', 'forschung_publikationen')->sole()->fields()->where('name', 'jahr')->sole();
        $this->assertFalse($year->groupsDigits());

        // Ältere geladene Demo: Option fehlt – der nächste Lauf ergänzt sie.
        app(DynamicSchemaService::class)->updateField($year, ['options' => []]);
        $this->assertTrue($year->fresh()->groupsDigits());
        $this->seed(ForschungDemoSeeder::class);
        $this->assertFalse($year->fresh()->groupsDigits());
    }

    public function test_switch_in_the_table_designer(): void
    {
        $table = CustomTable::query()->where('name', 'publikationen')->sole();

        Livewire::test(CustomFieldsRelationManager::class, ['ownerRecord' => $table, 'pageClass' => EditCustomTable::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'label' => ['de' => 'Geburtsjahr'], 'name' => 'geburtsjahr', 'type' => 'integer', 'options' => ['group_digits' => false],
            ])
            ->assertHasNoFormErrors()
            ->mountAction(TestAction::make(EditAction::class)->table($table->fields()->where('name', 'auflage')->sole()))
            ->assertSchemaStateSet(['options.group_digits' => true]);

        $this->assertFalse($table->fields()->where('name', 'geburtsjahr')->sole()->groupsDigits());
    }
}
