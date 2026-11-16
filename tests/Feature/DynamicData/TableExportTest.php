<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Livewire\DynamicDetailTable;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use Filament\Actions\Testing\TestAction;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use ZipArchive;

class TableExportTest extends DynamicDataTestCase
{
    private const array ALL_RIGHTS = ['data.projekte.view', 'data.projekte.export'];

    private CustomTable $projekte;

    private CustomTable $kunden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->projekte = $this->service()->createTable(['name' => 'projekte', 'label' => 'Projekte', 'export_enabled' => true], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen', 'label' => 'Noch offen'], ['value' => 'fertig', 'label' => 'Erledigt']]]],
            ['name' => 'budget', 'label' => 'Budget', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'menge', 'label' => 'Menge', 'type' => 'integer'],
            ['name' => 'aktiv', 'label' => 'Aktiv', 'type' => 'boolean'],
            ['name' => 'start', 'label' => 'Start', 'type' => 'date'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'text', 'show_in_table' => false],
        ]);
    }

    private function record(array $attributes = []): DynamicRecord
    {
        return DynamicRecord::forTable($this->projekte)->create(['titel' => 'Projekt', ...$attributes]);
    }

    private function page(array $parameters = []): Testable
    {
        return Livewire::test(DynamicDataPage::class, ['table' => 'projekte', ...$parameters]);
    }

    private function action(string $format): TestAction
    {
        return TestAction::make('export_'.$format)->table();
    }

    private function fileOf(Testable $component): string
    {
        $download = $component->effects['download'] ?? null;
        $this->assertNotNull($download, 'Es wurde keine Datei ausgeliefert.');

        return base64_decode($download['content']);
    }

    /**
     * @return list<list<string>>
     */
    private function csvRows(Testable $component): array
    {
        $content = $this->fileOf($component);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        $lines = array_filter(explode("\n", substr($content, 3)), filled(...));

        return array_values(array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), $lines));
    }

    /**
     * @return list<list<Cell>>
     */
    private function xlsxCells(Testable $component): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, $this->fileOf($component));

        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->getCells();
            }
        }

        $reader->close();
        unlink($path);

        return $rows;
    }

    private function worksheetXml(Testable $component): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, $this->fileOf($component));

        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);

        return $xml;
    }

    public function test_button_needs_setting_and_right(): void
    {
        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));
        $this->page()->assertActionVisible($this->action('xlsx'))->assertActionVisible($this->action('csv'));

        $this->actingAs($this->userWithPermissions(['data.projekte.view']));
        $this->page()->assertActionHidden($this->action('xlsx'))->assertActionHidden($this->action('csv'));

        $this->service()->updateTable($this->projekte, ['export_enabled' => false]);
        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));
        $this->page()->assertActionHidden($this->action('xlsx'))->assertActionHidden($this->action('csv'));

        // Auch Super-Admins brauchen die Freigabe in den Stammdaten.
        $this->actingAs($this->superAdmin());
        $this->page()->assertActionHidden($this->action('csv'));

        $this->service()->updateTable($this->projekte, ['export_enabled' => true]);
        $this->page()->assertActionVisible($this->action('csv'));
    }

    public function test_export_without_right_is_rejected_on_the_server(): void
    {
        $this->record();
        $this->actingAs($this->userWithPermissions(['data.projekte.view']));

        $this->assertArrayNotHasKey('download', $this->page()->call('mountAction', 'export_csv', [], ['table' => true])->effects);
        $this->assertSame(0, Activity::query()->where('event', 'exported')->count());

        // Gegenprobe: Derselbe Aufruf liefert mit dem Recht die Datei.
        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));
        $this->assertArrayHasKey('download', $this->page()->call('mountAction', 'export_csv', [], ['table' => true])->effects);
    }

    public function test_setting_is_off_by_default_and_can_be_switched_in_the_form(): void
    {
        $this->assertFalse($this->kunden->fresh()->export_enabled);

        $this->actingAs($this->superAdmin());
        Livewire::test(EditCustomTable::class, ['record' => $this->kunden->getRouteKey()])
            ->fillForm(['export_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->kunden->fresh()->export_enabled);
    }

    public function test_right_comes_and_goes_with_the_table(): void
    {
        $table = $this->service()->createTable(['name' => 'kurzlebig', 'label' => 'Kurzlebig'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string'],
        ]);
        $this->assertTrue(Permission::query()->where('name', 'data.kurzlebig.export')->exists());

        $this->service()->updateTable($table, ['is_active' => false]);
        $this->service()->dropTable($table->fresh(), 'kurzlebig');

        $this->assertFalse(Permission::query()->where('name', 'data.kurzlebig.export')->exists());
    }

    public function test_role_form_saves_export_right_independently(): void
    {
        $this->actingAs($this->superAdmin());
        $role = Role::create(['name' => 'vertrieb', 'guard_name' => 'web']);

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['data_permissions.projekte' => ['view', 'export']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            ['data.projekte.view', 'data.projekte.export'],
            $role->fresh()->permissions->pluck('name')->all(),
        );
        $this->assertContains('data.projekte.export', DataPermissions::names('projekte'));
    }

    public function test_csv_contains_all_pages_with_filter_search_sort_and_formatted_values(): void
    {
        $kunde = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Schmidt AG']);

        foreach (range(1, 30) as $i) {
            $this->record(['titel' => sprintf('Treffer %02d', $i), 'status' => 'offen', 'budget' => '1234.50', 'menge' => $i, 'aktiv' => true, 'start' => '2026-02-01', 'kunde_id' => $kunde->id]);
        }
        $this->record(['titel' => 'Treffer anders', 'status' => 'fertig']);
        $this->record(['titel' => 'Sonstiges', 'status' => 'offen']);

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $rows = $this->csvRows($this->page()
            ->filterTable('status', ['offen'])
            ->searchTable('Treffer')
            ->sortTable('titel', 'desc')
            ->callAction($this->action('csv')));

        $this->assertSame(['Titel', 'Status', 'Budget', 'Menge', 'Aktiv', 'Start', 'Kunde'], $rows[0], 'Spalten wie in der Liste, ohne ausgeblendete');
        $this->assertCount(31, $rows, 'Alle Seiten, nur Treffer');
        $this->assertSame(['Treffer 30', 'Noch offen', '1234,50', '30', 'Ja', '01.02.2026', 'Schmidt AG'], $rows[1]);
        $this->assertSame('Treffer 01', $rows[30][0]);
    }

    public function test_csv_and_excel_follow_the_language(): void
    {
        $this->record(['titel' => 'A', 'budget' => '1234.50', 'start' => '2026-02-01', 'aktiv' => true]);
        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));
        app()->setLocale('en');

        $content = $this->fileOf($this->page()->callAction($this->action('csv')));
        $rows = array_values(array_filter(explode("\n", substr($content, 3)), filled(...)));

        $this->assertStringContainsString('1234.50', $rows[1]);
        $this->assertStringContainsString('01/02/2026', $rows[1]);
        $this->assertSame('A', str_getcsv($rows[1], ',', '"', '')[0], 'Komma als Trennzeichen');

        $xlsx = $this->fileOf($this->page()->callAction($this->action('xlsx')));
        $path = (string) tempnam(sys_get_temp_dir(), 'saft-test-');
        file_put_contents($path, $xlsx);
        $zip = new ZipArchive;
        $zip->open($path);
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('dd/mm/yyyy', $styles);
    }

    public function test_csv_defuses_formulas_but_keeps_negative_numbers(): void
    {
        $this->record(['titel' => '=HYPERLINK("http://boese.example")', 'budget' => '-5.00', 'menge' => -3]);
        $this->record(['titel' => '@SUM(A1)']);
        $this->record(['titel' => '-1+1']);

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $rows = $this->csvRows($this->page()->sortTable('titel', 'asc')->callAction($this->action('csv')));
        $titles = array_column(array_slice($rows, 1), 0);

        foreach ($titles as $title) {
            $this->assertStringStartsWith("'", $title);
        }

        $negative = collect($rows)->firstWhere(0, "'=HYPERLINK(\"http://boese.example\")");
        $this->assertSame('-5,00', $negative[2]);
        $this->assertSame('-3', $negative[3]);
    }

    public function test_xlsx_keeps_real_types_and_never_creates_formulas(): void
    {
        $this->record(['titel' => '=1+1', 'status' => 'fertig', 'budget' => '1234.50', 'menge' => 7, 'aktiv' => false, 'start' => '2026-02-01']);

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $component = $this->page()->callAction($this->action('xlsx'));
        [$header, $row] = $this->xlsxCells($component);

        $this->assertSame('Titel', $header[0]->getValue());
        $this->assertSame('=1+1', $row[0]->getValue());
        // Der Reader deutet „=“ selbst als Formel, daher zählt nur die geschriebene Datei.
        $this->assertStringNotContainsString('<f>', $this->worksheetXml($component));
        $this->assertSame('Erledigt', $row[1]->getValue());
        $this->assertSame(1234.5, (float) $row[2]->getValue());
        $this->assertInstanceOf(Cell\NumericCell::class, $row[2]);
        $this->assertSame(7, (int) $row[3]->getValue());
        $this->assertSame('Nein', $row[4]->getValue());
        $this->assertInstanceOf(Cell\DateTimeCell::class, $row[5]);
        $this->assertSame('2026-02-01', $row[5]->getValue()->format('Y-m-d'));
    }

    public function test_xlsx_shortens_only_what_a_cell_cannot_hold(): void
    {
        $this->service()->updateField($this->projekte->fields()->where('name', 'notiz')->firstOrFail(), ['show_in_table' => true]);
        $this->record(['notiz' => str_repeat('a', 40000)]);
        $this->record(['notiz' => str_repeat('b', 100)]);

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $rows = $this->xlsxCells($this->page()->callAction($this->action('xlsx')));
        $lengths = array_map(static fn (array $row): int => mb_strlen((string) end($row)->getValue()), array_slice($rows, 1));
        sort($lengths);

        $this->assertSame([100, 32767], $lengths, 'Excel-Grenze greift, die Kürzung der Liste nicht');
    }

    public function test_menu_view_defines_the_exported_columns(): void
    {
        $this->record(['titel' => 'A', 'menge' => 1]);

        $menu = Menu::create([
            'label' => 'Kurz',
            'route' => DataMenu::ROUTE,
            'route_parameters' => ['table' => 'projekte'],
            'view_config' => ['columns' => [$this->fieldId('projekte', 'menge')]],
        ]);

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        // Ein Menüeintrag öffnet die Seite mit seiner Ansicht (?view=…).
        $rows = $this->csvRows(Livewire::withQueryParams(['view' => $menu->id])->test(DynamicDataPage::class, ['table' => 'projekte'])->callAction($this->action('csv')));

        $this->assertSame(['Titel', 'Menge'], $rows[0]);
        $this->assertSame(['A', '1'], $rows[1]);
    }

    public function test_detail_table_exports_only_the_selected_parent_and_uses_its_own_setting(): void
    {
        $this->service()->updateTable($this->kunden, ['detail_field_ids' => [$this->fieldId('projekte', 'kunde_id')]]);
        $mein = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Mein']);
        $fremd = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Fremd']);
        $this->record(['titel' => 'Meins', 'kunde_id' => $mein->id]);
        $this->record(['titel' => 'Fremdes', 'kunde_id' => $fremd->id]);

        $this->actingAs($this->userWithPermissions(['data.kunden.view', ...self::ALL_RIGHTS]));

        $detail = fn (): Testable => Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'kunden',
            'parentId' => $mein->id,
            'relationFieldId' => $this->fieldId('projekte', 'kunde_id'),
            'level' => 1,
        ]);

        $rows = $this->csvRows($detail()->callAction($this->action('csv')));

        $this->assertNotContains('Kunde', $rows[0], 'Die Verknüpfung zur Haupttabelle fehlt auch in der Liste');
        $this->assertCount(2, $rows);
        $this->assertSame('Meins', $rows[1][0]);

        $this->service()->updateTable($this->projekte, ['export_enabled' => false]);
        $detail()->assertActionHidden($this->action('csv'));
    }

    public function test_too_large_export_is_refused_not_shortened(): void
    {
        config(['dynamic_data.export_max_rows' => 2]);
        $this->record();
        $this->record();
        $this->record();

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $component = $this->page()->callAction($this->action('csv'))->assertNotified('Export zu gross');

        $this->assertArrayNotHasKey('download', $component->effects);
        $this->assertSame(0, Activity::query()->where('event', 'exported')->count());

        $this->assertArrayHasKey('download', $this->page()->searchTable('gibtsnicht')->callAction($this->action('csv'))->effects, 'Die Grenze zählt nur die Auswahl');
    }

    public function test_export_is_logged_without_the_data(): void
    {
        $this->record(['titel' => 'Geheimer Inhalt', 'status' => 'offen']);
        $user = $this->userWithPermissions(self::ALL_RIGHTS);
        $this->actingAs($user);

        $this->page()->filterTable('status', ['offen'])->searchTable('Geheimer')->callAction($this->action('xlsx'));

        $entry = Activity::query()->where('event', 'exported')->sole();
        $this->assertSame($user->id, (int) $entry->causer_id);
        $this->assertSame($this->projekte->id, (int) $entry->subject_id);

        $changes = $entry->attribute_changes->get('attributes');
        $this->assertSame('xlsx', $changes['format']);
        $this->assertSame(1, $changes['zeilen']);
        $this->assertSame('Geheimer', $changes['suche']);
        $this->assertArrayHasKey('status', $changes['filter']);
        $this->assertStringNotContainsString('Geheimer Inhalt', json_encode($changes));
    }
}
