<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\DynamicData\DataMenu;
use App\DynamicData\DataPermissions;
use App\DynamicData\Ui\DynamicTableBuilder;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\CustomTables\Pages\EditCustomTable;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\WorkItems\WorkItemResource;
use App\Livewire\DynamicDetailTable;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Menu;
use App\Models\Role;
use App\Models\WorkItem;
use App\Work\TimeLimit;
use App\Work\Types\ExportWork;
use App\Work\WorkRunner;
use App\Work\WorkService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Permission;
use ZipArchive;

class TableExportTest extends DynamicDataTestCase
{
    private const array ALL_RIGHTS = ['data.projekte.view', 'data.projekte.export'];

    private CustomTable $projekte;

    private CustomTable $kunden;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

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

    /**
     * Die Arbeit „Exportieren“ (Fundament 2, F3b) direkt: Blöcke von zwei Zeilen, ein Block je Aufruf (Budget 0), die
     * serialisierte Abfrage mit Filter und Sortierung über die Schritte hinweg, am Ende Datei und Protokoll.
     */
    public function test_export_work_writes_blocks_across_steps_and_keeps_filter_order_and_formats(): void
    {
        config(['dynamic_data.export_chunk' => 2]);
        $kunde = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Schmidt AG']);

        foreach (range(1, 5) as $i) {
            $this->record(['titel' => 'P'.$i, 'status' => 'offen', 'budget' => $i.'.50', 'menge' => $i, 'start' => '2026-02-0'.$i, 'kunde_id' => $kunde->id]);
        }
        $this->record(['titel' => 'Anders', 'status' => 'fertig']);

        $user = $this->userWithPermissions(self::ALL_RIGHTS);
        $this->actingAs($user);
        $query = app(DynamicTableBuilder::class)->query($this->projekte)->where('status', 'offen')->orderBy('titel', 'desc');
        $fields = array_map(static fn (CustomField $field): string => $field->name, app(DynamicTableBuilder::class)->tableFields($this->projekte, null));
        $parameters = ['table_id' => $this->projekte->id, 'fields' => $fields, 'title' => 'Projekte', 'query' => EloquentSerializeFacade::serialize($query), 'rows' => 5, 'filter' => ['status' => ['values' => ['offen']]], 'search' => ''];
        $runner = app(WorkRunner::class);

        $item = app(WorkService::class)->start(ExportWork::KEY, [...$parameters, 'format' => 'csv', 'file' => 'projekte.csv'], $user);
        $item = $runner->advance($item, 0);
        $this->assertSame([WorkItem::RUNNING, 2, 5, ['offset' => 2, 'row' => 4]], [$item->status, $item->done, $item->total, $item->state]);
        $item = $runner->advance(WorkItem::query()->findOrFail($item->id), 0);
        $this->assertSame(['offset' => 4, 'row' => 6], $item->state);
        $item = $runner->advance($item, 0);
        $this->assertSame([WorkItem::DONE, 5, 'projekte.csv', 5], [$item->status, $item->done, $item->result['file'], $item->result['rows']]);

        $content = Storage::disk('local')->get($item->directory().'/projekte.csv');
        $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $content);
        $rows = array_values(array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), array_filter(explode("\n", substr((string) $content, 3)), filled(...))));
        $this->assertSame(['Titel', 'Status', 'Budget', 'Menge', 'Aktiv', 'Start', 'Kunde'], $rows[0]);
        $this->assertSame(['P5', 'Noch offen', '5,50', '5', '', '05.02.2026', 'Schmidt AG'], $rows[1]);
        $this->assertSame(['P5', 'P4', 'P3', 'P2', 'P1'], array_column(array_slice($rows, 1), 0));
        Storage::disk('local')->assertMissing($item->directory().'/export.part');

        $entry = Activity::query()->where('event', 'exported')->sole();
        $this->assertSame([$user->id, 5, 'csv'], [(int) $entry->causer_id, $entry->attribute_changes->get('attributes')['zeilen'], $entry->attribute_changes->get('attributes')['format']]);

        // Excel über dieselben Schritte: echte Typen, Datum als Datum, die Teildateien sind weg.
        $item = app(WorkService::class)->start(ExportWork::KEY, [...$parameters, 'format' => 'xlsx', 'file' => 'projekte.xlsx'], $user);
        do {
            $item = $runner->advance($item, 0);
        } while ($item->isOpen());
        $this->assertSame(WorkItem::DONE, $item->status);

        $reader = new Reader;
        $reader->open(Storage::disk('local')->path($item->directory().'/projekte.xlsx'));
        $cells = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $this->assertSame('Projekte', $sheet->getName());
            foreach ($sheet->getRowIterator() as $row) {
                $cells[] = $row->getCells();
            }
        }
        $reader->close();
        $this->assertCount(6, $cells);
        $this->assertSame('Titel', $cells[0][0]->getValue());
        $this->assertSame('P5', $cells[1][0]->getValue());
        $this->assertInstanceOf(Cell\NumericCell::class, $cells[1][2]);
        $this->assertSame(5.5, (float) $cells[1][2]->getValue());
        $this->assertSame(5, (int) $cells[1][3]->getValue());
        $this->assertInstanceOf(Cell\DateTimeCell::class, $cells[1][5]);
        $this->assertSame('2026-02-05', $cells[1][5]->getValue()->format('Y-m-d'));
        $this->assertSame('Schmidt AG', $cells[1][6]->getValue());
        $this->assertSame('P1', $cells[5][0]->getValue());
        Storage::disk('local')->assertMissing($item->directory().'/sheet.part');
        Storage::disk('local')->assertMissing($item->directory().'/sheet.part.xml');
    }

    /** Reicht das Budget im Request nicht, geht es auf die Seite der Arbeit; der Scheduler beendet sie, die Datei bleibt. */
    public function test_export_that_is_not_finished_in_the_request_continues_as_work(): void
    {
        app()->instance(TimeLimit::class, new class extends TimeLimit
        {
            public function budget(float $wanted, int $margin = 5): float
            {
                return 0.0;
            }
        });
        config(['dynamic_data.export_chunk' => 1]);
        $this->record(['titel' => 'A']);
        $this->record(['titel' => 'B']);
        $this->record(['titel' => 'C']);
        $user = $this->userWithPermissions(self::ALL_RIGHTS);
        $this->actingAs($user);

        $component = $this->page()->sortTable('titel', 'asc')->callAction($this->action('csv'));
        $item = WorkItem::query()->where('type', ExportWork::KEY)->latest('id')->firstOrFail();

        $this->assertArrayNotHasKey('download', $component->effects);
        $component->assertRedirect(WorkItemResource::getUrl('view', ['record' => $item], panel: 'admin'));
        $this->assertSame([WorkItem::RUNNING, 1, 3, $user->id], [$item->status, $item->done, $item->total, $item->user_id]);
        $this->assertSame(0, Activity::query()->where('event', 'exported')->count(), 'Protokolliert wird erst die fertige Datei');

        $this->travel(1)->minutes();
        $this->artisan('work:run')->assertSuccessful();
        $item->refresh();
        $this->assertSame([WorkItem::DONE, 3], [$item->status, $item->done]);
        $this->assertMatchesRegularExpression('/^projekte_\d{4}-\d{2}-\d{2}_\d{4}\.csv$/', (string) $item->resultFile());
        $this->assertSame(1, Activity::query()->where('event', 'exported')->count());

        $content = $this->get(route('work.download', $item->uuid))->assertOk()->streamedContent();
        $this->assertSame(['Titel', 'Status', 'Budget', 'Menge', 'Aktiv', 'Start', 'Kunde'], str_getcsv(explode("\n", substr($content, 3))[0], ';', '"', ''));
        $this->assertStringContainsString("\nC;", $content);
    }

    private function selectedAction(string $format): TestAction
    {
        return TestAction::make('exportSelected_'.$format)->table()->bulk();
    }

    /** B-133 S4a: nur die ausgewählten, in der Sortierung der Tabelle, Spalten wie beim Export; im Protokoll vermerkt. */
    public function test_selected_export_contains_only_the_selection_in_table_order(): void
    {
        $ids = [];

        foreach (['B', 'D', 'A', 'C'] as $titel) {
            $ids[$titel] = (string) $this->record(['titel' => $titel, 'status' => 'offen'])->getKey();
        }

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $rows = $this->csvRows($this->page()
            ->sortTable('titel', 'asc')
            ->selectTableRecords([$ids['D'], $ids['A'], $ids['C']])
            ->callAction($this->selectedAction('csv')));

        $this->assertSame(['Titel', 'Status', 'Budget', 'Menge', 'Aktiv', 'Start', 'Kunde'], $rows[0]);
        $this->assertSame(['A', 'C', 'D'], array_column(array_slice($rows, 1), 0));

        $entry = Activity::query()->where('event', 'exported')->sole();
        $this->assertTrue($entry->attribute_changes->get('attributes')['auswahl']);
        $this->assertSame(3, $entry->attribute_changes->get('attributes')['zeilen']);
    }

    /** „Alle auswählen“ über alle Seiten und einzelne abwählen: Filter und Suche minus die abgewählten. */
    public function test_selected_export_over_all_pages_without_deselected_records(): void
    {
        $ids = [];

        foreach (range(1, 25) as $i) {
            $ids[$i] = (string) $this->record(['titel' => sprintf('Treffer %02d', $i), 'status' => 'offen'])->getKey();
        }
        $this->record(['titel' => 'Treffer fertig', 'status' => 'fertig']);

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $rows = $this->csvRows($this->page()
            ->filterTable('status', ['offen'])
            ->sortTable('titel', 'asc')
            ->set('isTrackingDeselectedTableRecords', true)
            ->set('deselectedTableRecords', [$ids[3], $ids[20]])
            ->callAction($this->selectedAction('csv')));

        $this->assertCount(24, $rows, 'Kopfzeile und 23 Datensätze');
        $titles = array_column(array_slice($rows, 1), 0);
        $this->assertNotContains('Treffer 03', $titles);
        $this->assertNotContains('Treffer 20', $titles);
        $this->assertNotContains('Treffer fertig', $titles, 'Der Filter gilt auch bei „alle auswählen“');
        $this->assertSame('Treffer 01', $titles[0]);
    }

    public function test_selected_export_needs_export_right_and_setting_but_not_bulk_edit(): void
    {
        $id = (string) $this->record()->getKey();

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));
        $this->page()->assertActionVisible($this->selectedAction('xlsx'))
            ->assertActionHidden(TestAction::make('bulkEdit')->table()->bulk());
        $this->assertArrayHasKey('download', $this->page()->selectTableRecords([$id])->callAction($this->selectedAction('xlsx'))->effects);

        $this->actingAs($this->userWithPermissions(['data.projekte.view']));
        $this->page()->assertActionHidden($this->selectedAction('xlsx'));

        $this->service()->updateTable($this->projekte, ['export_enabled' => false]);
        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));
        $this->page()->assertActionHidden($this->selectedAction('xlsx'));
    }

    public function test_selected_export_respects_the_export_limit(): void
    {
        config(['dynamic_data.export_max_rows' => 2]);
        $ids = [(string) $this->record()->getKey(), (string) $this->record()->getKey(), (string) $this->record()->getKey()];

        $this->actingAs($this->userWithPermissions(self::ALL_RIGHTS));

        $component = $this->page()->selectTableRecords($ids)->callAction($this->selectedAction('csv'))->assertNotified('Export zu gross');
        $this->assertArrayNotHasKey('download', $component->effects);
        $this->assertArrayHasKey('download', $this->page()->selectTableRecords([$ids[0], $ids[1]])->callAction($this->selectedAction('csv'))->effects);
    }

    public function test_detail_table_exports_selected_child_records(): void
    {
        $this->service()->updateTable($this->kunden, ['detail_field_ids' => [$this->fieldId('projekte', 'kunde_id')]]);
        $kunde = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Mein']);
        $eins = $this->record(['titel' => 'Eins', 'kunde_id' => $kunde->id]);
        $this->record(['titel' => 'Zwei', 'kunde_id' => $kunde->id]);

        $this->actingAs($this->userWithPermissions(['data.kunden.view', ...self::ALL_RIGHTS]));

        $rows = $this->csvRows(Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'kunden',
            'parentId' => $kunde->id,
            'relationFieldId' => $this->fieldId('projekte', 'kunde_id'),
            'level' => 1,
        ])->selectTableRecords([(string) $eins->getKey()])->callAction($this->selectedAction('csv')));

        $this->assertCount(2, $rows);
        $this->assertSame('Eins', $rows[1][0]);
        $this->assertNotContains('Kunde', $rows[0]);
    }
}
