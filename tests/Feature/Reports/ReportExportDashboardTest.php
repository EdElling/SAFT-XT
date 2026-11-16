<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\DynamicData\DataPermissions;
use App\DynamicData\Export\ExportFormat;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Reports\Pages\ViewReport;
use App\Filament\Widgets\ReportCardWidget;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use App\Reports\Export\ReportExporter;
use App\Reports\ReportRunner;
use App\Services\DynamicSchemaService;
use Livewire\Livewire;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Auswertungen exportieren und auf das persönliche Dashboard heften. */
class ReportExportDashboardTest extends DynamicDataTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
            ['name' => 'ort', 'label' => 'Ort', 'type' => 'select', 'options' => ['options' => [['value' => 'ber', 'label' => 'Berlin'], ['value' => 'ham', 'label' => 'Hamburg']]]],
        ]);
        $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'name']],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
        ]);

        $acme = DynamicRecord::forTableName('kunden')->create(['name' => 'ACME', 'ort' => 'ber']);
        $beta = DynamicRecord::forTableName('kunden')->create(['name' => '=Beta', 'ort' => 'ham']);

        foreach ([['B-1', $acme, '2026-01-10', '1100.50'], ['B-2', $acme, '2026-02-20', '50.25'], ['B-3', $beta, '2026-02-05', '1200.00']] as [$nr, $kunde, $datum, $betrag]) {
            DynamicRecord::forTableName('bestellungen')->create(['nummer' => $nr, 'kunde_id' => $kunde->id, 'datum' => $datum, 'betrag' => $betrag]);
        }
    }

    public function test_excel_export_keeps_numbers_and_dates_and_writes_the_crosstab(): void
    {
        $list = $this->list();
        $rows = $this->xlsx(app(ReportExporter::class)->write(app(ReportRunner::class)->run($list, $this->admin), ExportFormat::Xlsx, $list->name));

        $this->assertSame(['Nummer', 'Kunde › Name', 'Datum', 'Betrag'], $rows[0]);
        $this->assertSame('B-1', $rows[1][0]);
        $this->assertSame('=Beta', $rows[3][1], 'Text bleibt Text, keine Formel.');
        $this->assertInstanceOf(\DateTimeInterface::class, $rows[1][2]);
        $this->assertEqualsWithDelta(1100.5, $rows[1][3], 0.001);

        $cross = $this->crossTab();
        $rows = $this->xlsx(app(ReportExporter::class)->write(app(ReportRunner::class)->run($cross, $this->admin), ExportFormat::Xlsx, $cross->name));

        $this->assertSame(['Monat', 'Berlin', 'Hamburg'], $rows[0]);
        $this->assertSame(['Januar 2026', 1100.5], [$rows[1][0], $rows[1][1]]);
        $this->assertSame('', $rows[1][2] ?? '', 'Leere Zelle, wo es keinen Wert gibt.');
        $this->assertSame(['Februar 2026', 50.25, 1200], [$rows[2][0], $rows[2][1], $rows[2][2]]);
    }

    public function test_csv_export_uses_the_conventions_of_the_language(): void
    {
        $list = $this->list();
        $path = app(ReportExporter::class)->write(app(ReportRunner::class)->run($list, $this->admin), ExportFormat::Csv, $list->name);
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = ';';
        $reader = new CsvReader($options);
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();
        unlink($path);

        $this->assertSame(['B-1', 'ACME', '10.01.2026', '1100,50'], $rows[1]);
        $this->assertSame("'=Beta", $rows[3][1], 'CSV: Formel-Anfangszeichen entschärft.');
    }

    public function test_export_action_downloads_and_is_logged(): void
    {
        $list = $this->list();

        Livewire::test(ViewReport::class, ['record' => $list->getRouteKey()])
            ->callAction('export_xlsx')
            ->assertFileDownloaded();

        $log = Activity::query()->where('event', 'exported')->where('subject_type', Report::class)->sole();
        $this->assertSame(['format' => 'xlsx', 'zeilen' => 3, 'gekuerzt' => false], $log->attribute_changes['attributes']);
    }

    public function test_pin_to_my_dashboard_and_the_widget(): void
    {
        $user = $this->userWithPermissions(['reports.view', 'widgets.reports', DataPermissions::name('bestellungen', 'view'), DataPermissions::name('kunden', 'view')]);
        $cross = $this->crossTab(shared: true);
        $this->actingAs($user);

        $cards = fn (): array => array_keys(Livewire::test(Dashboard::class)->instance()->cards());
        $this->assertNotContains('report:'.$cross->id, $cards(), 'Ohne angeheftete Auswertung keine Karte.');

        Livewire::test(ViewReport::class, ['record' => $cross->getRouteKey()])
            ->assertActionHasLabel('pin', 'Auf mein Dashboard')
            ->callAction('pin')
            ->assertNotified('Auf dem Dashboard')
            ->assertActionHasLabel('pin', 'Vom Dashboard entfernen');

        // Je angehefteter Auswertung eine eigene Karte; Kreuztabelle standardmäßig in voller Breite.
        $this->assertContains('report:'.$cross->id, $cards());
        $this->assertContains('report:'.$cross->id, Livewire::test(Dashboard::class)->instance()->layout()['full']);
        Livewire::test(ReportCardWidget::class, ['reportId' => $cross->id])
            ->assertSee('Umsatz je Monat')
            ->assertSee('data-report-crosstab', false)
            ->assertSee('Januar 2026');
        $this->get(Dashboard::getUrl())->assertOk()->assertSeeLivewire(ReportCardWidget::class);

        // Ohne Leserecht auf Kunden: verständliche Meldung in der Karte statt Fehler.
        $user->revokePermissionTo(DataPermissions::name('kunden', 'view'));
        Livewire::test(ReportCardWidget::class, ['reportId' => $cross->id])->assertSee('Für diese Auswertung fehlt Ihnen das Leserecht auf: Kunden.');

        Livewire::test(ViewReport::class, ['record' => $cross->getRouteKey()])->callAction('pin');
        $this->assertNotContains('report:'.$cross->id, $cards());
        // Eine fremde bzw. nicht angeheftete Auswertung zeigt die Karte nicht.
        Livewire::test(ReportCardWidget::class, ['reportId' => $cross->id])->assertDontSee('Umsatz je Monat');

        // Persönliches Widget: Standardrolle hat das Recht.
        $this->assertTrue(Role::findByName(User::ROLE_DEFAULT)->hasPermissionTo('widgets.reports'));
    }

    private function list(): Report
    {
        return Report::create(['name' => 'Bestellungen', 'owner_id' => $this->admin->id, 'definition' => [
            'base' => $this->table('bestellungen'),
            'fields' => [
                ['key' => 'n', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.nummer')],
                ['key' => 'k', 'path' => [$this->fieldIdOf('bestellungen.kunde_id')], 'field' => $this->fieldIdOf('kunden.name')],
                ['key' => 'd', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.datum')],
                ['key' => 'b', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.betrag')],
            ],
        ]]);
    }

    private function crossTab(bool $shared = false): Report
    {
        return Report::create(['name' => 'Umsatz je Monat', 'owner_id' => $this->admin->id, 'is_shared' => $shared, 'definition' => [
            'base' => $this->table('bestellungen'),
            'fields' => [
                ['key' => 'm', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.datum'), 'bucket' => 'month', 'label' => 'Monat'],
                ['key' => 'o', 'path' => [$this->fieldIdOf('bestellungen.kunde_id')], 'field' => $this->fieldIdOf('kunden.ort')],
                ['key' => 'b', 'path' => [], 'field' => $this->fieldIdOf('bestellungen.betrag')],
            ],
            'groups' => ['m'],
            'columns' => ['o'],
            'measures' => [['key' => 's', 'field' => 'b', 'agg' => 'sum']],
            'visual' => ['type' => 'crosstab'],
        ]]);
    }

    /** @return list<list<mixed>> */
    private function xlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();
        unlink($path);

        return $rows;
    }

    private function table(string $name): int
    {
        return (int) CustomTable::query()->where('name', $name)->value('id');
    }

    private function fieldIdOf(string $qualified): int
    {
        [$table, $field] = explode('.', $qualified);

        return (int) CustomTable::query()->where('name', $table)->sole()->fields()->where('name', $field)->value('id');
    }
}
