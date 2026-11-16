<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\DynamicData\DynamicDataNamespace;
use App\DynamicData\Schema\FieldType;
use App\Filament\Resources\CustomTables\Pages\ImportCustomTable;
use App\Filament\Resources\CustomTables\Pages\ListCustomTables;
use App\Models\CustomTable;
use App\Models\Menu;
use App\Services\DynamicSchemaService;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/** Assistent „Tabelle aus Datei importieren“: Prüfung, Spalten, Tabelle und Menü. */
class ImportWizardTest extends DynamicDataTestCase
{
    private const string CSV = "Kunden-Nr.;Name;Umsatz;Seit;Bemerkung\n"
        ."0815;Müller AG;1.234,50;01.03.2020;Stammkunde\n"
        ."4711;Schmidt;99;15.11.2021;\n";

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());
    }

    public function test_page_and_button_need_the_import_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['custom_tables.view', 'custom_tables.create']));
        $this->get(ImportCustomTable::getUrl())->assertForbidden();
        Livewire::test(ListCustomTables::class)->assertActionHidden('import');

        $this->actingAs($this->userWithPermissions(['custom_tables.view', 'custom_tables.import']));
        $this->get(ImportCustomTable::getUrl())->assertOk()->assertSee('Tabelle aus Datei importieren');
        Livewire::test(ListCustomTables::class)->assertActionVisible('import');
    }

    public function test_full_import_of_the_structure_with_menu_entry(): void
    {
        $group = Menu::create(['label' => 'Verkauf', 'sort_order' => 10]);

        $wizard = $this->upload('Kunden 2026.csv', self::CSV)
            ->goToNextWizardStep()
            ->assertHasNoFormErrors()
            ->assertWizardCurrentStep(2)
            ->assertSee('2 Datenzeilen, 5 Spalten. Trennzeichen: Semikolon, Zeichensatz: UTF-8.');

        $this->assertSame(['kunden_nr', 'name', 'umsatz', 'seit', 'bemerkung'], array_column($wizard->get('data.columns'), 'name'));
        $this->assertSame(['string', 'string', 'decimal', 'date', 'string'], array_column($wizard->get('data.columns'), 'type'));

        $wizard->goToWizardStep(3)
            ->assertWizardCurrentStep(3)
            ->assertSee('Text statt Zahl, weil Werte mit führender Null vorkommen')
            ->set('data.columns.'.$this->key($wizard, 'kunden_nr').'.name', 'kundennummer')
            ->set('data.columns.'.$this->key($wizard, 'umsatz').'.type', 'text')
            ->set('data.columns.'.$this->key($wizard, 'seit').'.label', 'Kunde seit')
            ->set('data.columns.'.$this->key($wizard, 'bemerkung').'.include', false)
            ->goToWizardStep(4)
            ->assertHasNoFormErrors()
            ->assertWizardCurrentStep(4)
            ->assertSet('data.table_name', 'kunden_2026')
            ->assertSet('data.table_label', 'Kunden 2026')
            ->set('data.menu_group', (string) $group->id)
            ->call('import')
            ->assertHasNoFormErrors()
            ->assertNotified('Tabelle „Kunden 2026“ angelegt, 2 Datensätze importiert')
            ->assertRedirect(route(DataMenu::ROUTE, ['table' => 'kunden_2026']));

        $namespace = app(DynamicDataNamespace::class);
        $rows = $namespace->connection()->table($namespace->qualify('kunden_2026'))->orderBy('id')->get();
        $this->assertSame(['0815', '4711'], $rows->pluck('kundennummer')->all());
        $this->assertSame(['1.234,50', '99'], $rows->pluck('umsatz')->all(), 'Als Text gewählt: Wert wie in der Datei.');

        $table = CustomTable::query()->where('name', 'kunden_2026')->sole();
        $this->assertSame('Kunden 2026', $table->label);
        $this->assertSame(
            ['kundennummer' => FieldType::String, 'name' => FieldType::String, 'umsatz' => FieldType::Text, 'seit' => FieldType::Date],
            $table->fields()->orderBy('sort_order')->get()->mapWithKeys(fn ($f): array => [$f->name => $f->type])->all(),
        );
        $this->assertSame('Kunde seit', $table->fields()->where('name', 'seit')->sole()->label);
        $this->assertSame($group->id, Menu::query()->where('route', DataMenu::ROUTE)->where('route_parameters->table', 'kunden_2026')->sole()->parent_id);
    }

    public function test_untidy_file_stays_on_the_first_step_with_all_problems(): void
    {
        $this->upload('liste.csv', "Name;;Name\na;b;c\n")
            ->assertSee('Spalte B hat keinen Titel.', 'Schon nach dem Hochladen sichtbar.')
            ->goToNextWizardStep()
            ->assertWizardCurrentStep(1)
            ->assertSee('Die Datei kann so nicht importiert werden')
            ->assertSee('Spalte B hat keinen Titel.')
            ->assertSee('Der Titel „Name“ kommt mehrfach vor (Spalten A, C).');
    }

    public function test_names_are_validated_like_in_the_table_designer(): void
    {
        app(DynamicSchemaService::class)->createTable(['name' => 'kunden', 'label' => 'Kunden']);

        $wizard = $this->upload('kunden.csv', self::CSV)->goToNextWizardStep()->goToWizardStep(3);

        [$name, $sales, $number] = [$this->key($wizard, 'name'), $this->key($wizard, 'umsatz'), $this->key($wizard, 'kunden_nr')];

        $wizard->set("data.columns.{$name}.name", 'Name!')
            ->set("data.columns.{$sales}.name", 'seit')
            ->goToWizardStep(4)
            ->assertWizardCurrentStep(3)
            ->assertHasFormErrors(["columns.{$name}.name", "columns.{$sales}.name"]);

        // Ausgelassene Spalten werden nicht geprüft; ohne jede Spalte geht es nicht weiter.
        foreach (array_keys($wizard->get('data.columns')) as $key) {
            $wizard->set("data.columns.{$key}.include", false);
        }

        $wizard->goToWizardStep(4)->assertWizardCurrentStep(3)->assertNotified('Bitte mindestens eine Spalte übernehmen.');

        $wizard->set("data.columns.{$number}.include", true)
            ->goToWizardStep(4)
            ->assertWizardCurrentStep(4)
            ->assertSet('data.table_name', 'kunden_2', 'Vorschlag weicht vorhandenen Tabellen aus.')
            // Schon beim Verlassen des Felds, mit Eigentümer.
            ->set('data.table_name', 'kunden')
            ->assertHasErrors(['data.table_name'])
            ->assertSee('Der Name „kunden“ ist bereits vergeben: SAFT-Tabelle')
            ->call('import')
            ->assertHasFormErrors(['table_name']);

        $this->assertSame(1, CustomTable::query()->where('name', 'like', 'kunden%')->count());
    }

    public function test_excel_with_several_sheets_offers_the_sheet_choice(): void
    {
        $path = sys_get_temp_dir().'/saft-import-'.bin2hex(random_bytes(4)).'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Hinweise');
        $writer->addRow(Row::fromValues(['Bitte Blatt Artikel verwenden']));
        $writer->addNewSheetAndMakeItCurrent()->setName('Artikel');
        $writer->addRow(Row::fromValues(['Artikel', 'Preis']));
        $writer->addRow(Row::fromValues(['Tisch', 99.9]));
        $writer->close();

        $wizard = $this->upload('mappe.xlsx', (string) file_get_contents($path));
        @unlink($path);

        // Gleich nach dem Hochladen: Blattauswahl im ersten Schritt, vor „Weiter“.
        $wizard->assertWizardCurrentStep(1)
            ->assertSet('data.sheet', 'Hinweise')
            ->assertSeeHtml('id="form.sheet"')
            ->set('data.sheet', 'Artikel')
            ->assertSet('data.table_name', 'artikel')
            ->goToNextWizardStep()
            ->assertWizardCurrentStep(2)
            ->assertSee('1 Datenzeile, 2 Spalten. Blatt: Artikel.');

        $this->assertSame(['artikel', 'preis'], array_column($wizard->get('data.columns'), 'name'));
    }

    private function upload(string $name, string $content): Testable
    {
        return Livewire::test(ImportCustomTable::class)
            ->set('data.file', [UploadedFile::fake()->createWithContent($name, $content)]);
    }

    /** Schlüssel der Repeater-Zeile mit dem (vorgeschlagenen) Feldnamen. */
    private function key(Testable $wizard, string $name): string
    {
        return (string) collect($wizard->get('data.columns'))->search(fn (array $row): bool => $row['name'] === $name);
    }
}
