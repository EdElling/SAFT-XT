<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\DynamicData\Export\ExportFormat;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Reports\Export\ReportExporter;
use App\Reports\ReportDefinition;
use App\Reports\ReportResult;
use App\Reports\ReportRunner;
use App\Reports\ValueFormatter;
use App\Services\DynamicSchemaService;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Verknüpfungsfelder in Auswertungen zeigen die Anzeigefelder des verknüpften Datensatzes, gruppiert wird nach dem Schlüssel. */
class ReportRelationDisplayTest extends DynamicDataTestCase
{
    private User $admin;

    /** @var array<string, int> */
    private array $customers = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);

        $schema = app(DynamicSchemaService::class);
        $schema->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'vorname', 'label' => 'Vorname', 'type' => 'string'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'string'],
        ]);
        $schema->createTable(['name' => 'bestellungen', 'label' => 'Bestellungen'], [
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_fields' => ['vorname', 'name'], 'separator' => ' ']],
            ['name' => 'quartal', 'label' => 'Quartal', 'type' => 'string'],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
        ]);

        // Zwei verschiedene Kundinnen mit gleichem Namen bleiben getrennt.
        foreach (['zora' => ['Zora', 'Beta'], 'anna1' => ['Anna', 'Muster'], 'anna2' => ['Anna', 'Muster']] as $key => [$first, $last]) {
            $this->customers[$key] = DynamicRecord::forTableName('kunden')->create(['vorname' => $first, 'name' => $last])->id;
        }

        foreach ([['B-1', 'zora', 'Q1', '10.00'], ['B-2', 'anna1', 'Q1', '20.00'], ['B-3', 'anna2', 'Q2', '5.00'], ['B-4', 'anna1', 'Q2', '1.00']] as [$nr, $customer, $quarter, $amount]) {
            DynamicRecord::forTableName('bestellungen')->create(['nummer' => $nr, 'kunde_id' => $this->customers[$customer], 'quartal' => $quarter, 'betrag' => $amount]);
        }
    }

    /** @param  array<string, mixed>  $definition */
    private function report(array $definition): ReportResult
    {
        return app(ReportRunner::class)->run(ReportDefinition::fromArray([
            'base' => (int) CustomTable::query()->where('name', 'bestellungen')->value('id'),
            ...$definition,
        ]), $this->admin);
    }

    /** @return list<list<string>> formatierte Zeilen */
    private function formatted(ReportResult $result): array
    {
        $formatter = app(ValueFormatter::class);

        return array_map(fn (array $row): array => array_map(fn ($column): string => $formatter->format($row[$column->key], $column), $result->columns), $result->rows);
    }

    public function test_grouping_by_a_relation_shows_names_keeps_namesakes_apart_and_sorts_by_name(): void
    {
        $result = $this->report([
            'fields' => [['key' => 'f1', 'path' => [], 'field' => $this->fieldId('bestellungen', 'kunde_id')], ['key' => 'f2', 'path' => [], 'field' => $this->fieldId('bestellungen', 'betrag')]],
            'groups' => ['f1'],
            'measures' => [['key' => 'm1', 'field' => 'f2', 'agg' => 'sum']],
        ]);

        $this->assertSame([['Anna Muster', '21,00'], ['Anna Muster', '5,00'], ['Zora Beta', '10,00']], $this->formatted($result));
        // Der Wert bleibt der Schlüssel.
        $this->assertSame([$this->customers['anna1'], $this->customers['anna2'], $this->customers['zora']], array_column($result->rows, 'f1'));

        // Sortierung absteigend nach der Gruppe: nach Namen.
        $sorted = $this->report([
            'fields' => [['key' => 'f1', 'path' => [], 'field' => $this->fieldId('bestellungen', 'kunde_id')]],
            'groups' => ['f1'],
            'measures' => [['key' => 'm1', 'field' => null, 'agg' => 'count']],
            'sort' => [['key' => 'f1', 'dir' => 'desc']],
        ]);
        $this->assertSame('Zora Beta', $this->formatted($sorted)[0][0]);
    }

    public function test_list_crosstab_chart_and_export_show_names(): void
    {
        $list = $this->report([
            'fields' => [['key' => 'f1', 'path' => [], 'field' => $this->fieldId('bestellungen', 'nummer')], ['key' => 'f2', 'path' => [], 'field' => $this->fieldId('bestellungen', 'kunde_id')]],
        ]);
        $this->assertSame(['B-1', 'Zora Beta'], $this->formatted($list)[0]);

        $cross = $this->report([
            'fields' => [
                ['key' => 'f1', 'path' => [], 'field' => $this->fieldId('bestellungen', 'quartal')],
                ['key' => 'f2', 'path' => [], 'field' => $this->fieldId('bestellungen', 'kunde_id')],
                ['key' => 'f3', 'path' => [], 'field' => $this->fieldId('bestellungen', 'betrag')],
            ],
            'groups' => ['f1'],
            'columns' => ['f2'],
            'measures' => [['key' => 'm1', 'field' => 'f3', 'agg' => 'sum']],
            'visual' => ['type' => 'crosstab'],
        ]);
        $formatter = app(ValueFormatter::class);
        $headers = array_map(fn ($value): string => $formatter->format($value, $cross->crossTab()->column), $cross->crossTab()->columnValues);
        $this->assertEqualsCanonicalizing(['Anna Muster', 'Anna Muster', 'Zora Beta'], $headers);

        // Excel: Namen als Text statt Nummern.
        $path = app(ReportExporter::class)->write($list, ExportFormat::Xlsx, 'Test');
        $reader = new Reader;
        $reader->open($path);
        $cells = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells[] = $row->toArray();
            }
        }
        $reader->close();
        @unlink($path);
        $this->assertSame(['B-1', 'Zora Beta'], $cells[1]);
    }

    public function test_without_display_field_the_key_stays(): void
    {
        $field = CustomTable::query()->where('name', 'bestellungen')->sole()->fields()->where('name', 'kunde_id')->sole();
        $field->update(['options' => ['table' => 'kunden']]);

        $result = $this->report([
            'fields' => [['key' => 'f1', 'path' => [], 'field' => $field->id]],
            'groups' => ['f1'],
            'measures' => [['key' => 'm1', 'field' => null, 'agg' => 'count']],
        ]);

        $this->assertSame((string) $this->customers['zora'], $this->formatted($result)[0][0]);
    }
}
