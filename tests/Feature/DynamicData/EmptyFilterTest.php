<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Ui\EmptyFilter;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DynamicDataPage;
use App\Models\DynamicRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** B-158: Filter „leer“ bzw. „gefüllt“ in der Datenansicht, Bedeutung wie is_empty/not_empty der Auswertungen. */
class EmptyFilterTest extends DynamicDataTestCase
{
    private DynamicRecord $voll;

    private DynamicRecord $leer;

    private DynamicRecord $leertext;

    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');

        $kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $belege = $this->service()->createTable(['name' => 'belege', 'label' => 'Belege'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'string'],
            ['name' => 'notiz', 'label' => 'Notiz', 'type' => 'text'],
            ['name' => 'betrag', 'label' => 'Betrag', 'type' => 'decimal', 'options' => ['precision' => 12, 'scale' => 2]],
            ['name' => 'anzahl', 'label' => 'Anzahl', 'type' => 'integer'],
            ['name' => 'datum', 'label' => 'Datum', 'type' => 'date'],
            ['name' => 'bezahlt', 'label' => 'Bezahlt', 'type' => 'boolean'],
            ['name' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => ['options' => [['value' => 'offen'], ['value' => 'erledigt']]]],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);

        $writer = app(RecordWriter::class);
        $this->anna = User::factory()->create(['name' => 'Anna Admin']);
        $this->actingAs($this->anna);
        $kunde = $writer->create($kunden, ['firma' => 'Muster AG'], WriteOptions::ui());
        $this->voll = $writer->create($belege, [
            'titel' => 'Voll', 'nummer' => 'R-2026-001', 'notiz' => 'Text', 'betrag' => '0.00', 'anzahl' => 0, 'datum' => '2026-03-01',
            'bezahlt' => false, 'status' => 'offen', 'kunde_id' => $kunde->getKey(),
        ], WriteOptions::ui());

        // Ohne Person: wie Import, Aufgabe oder Seed.
        auth()->logout();
        $this->leer = $writer->create($belege, ['titel' => 'Leer'], WriteOptions::seed());
        $this->leertext = $writer->create($belege, ['titel' => 'Leertext', 'bezahlt' => true, 'status' => 'erledigt'], WriteOptions::seed());

        // Leertext und NULL am Schreibweg vorbei, wie sie Import oder eine angebundene Tabelle hinterlassen.
        $table = DynamicRecord::forTable($belege)->getTable();
        DB::connection('app_data')->table($table)->where('id', $this->leertext->getKey())->update(['nummer' => '', 'notiz' => '']);
        DB::connection('app_data')->table($table)->where('id', $this->leer->getKey())->update(['bezahlt' => null, 'status' => null, 'nummer' => null, 'notiz' => null]);

        $this->actingAs($this->superAdmin());
    }

    private function page(): Testable
    {
        return Livewire::test(DynamicDataPage::class, ['table' => 'belege'])->resetTableFilters();
    }

    /**
     * @param  list<DynamicRecord>  $visible
     */
    private function assertFilter(string $filter, mixed $state, array $visible): void
    {
        $all = [$this->voll, $this->leer, $this->leertext];
        $hidden = array_values(array_filter($all, static fn (DynamicRecord $r): bool => ! in_array($r, $visible, true)));

        $this->page()->filterTable($filter, $state)
            ->assertCanSeeTableRecords($visible)
            ->assertCanNotSeeTableRecords($hidden)
            ->assertCountTableRecords(count($visible));
    }

    public function test_text_counts_null_and_empty_string_as_empty(): void
    {
        foreach (['nummer', 'notiz'] as $field) {
            $this->assertFilter($field, [EmptyFilter::CONTENT => EmptyFilter::EMPTY], [$this->leer, $this->leertext]);
            $this->assertFilter($field, [EmptyFilter::CONTENT => EmptyFilter::FILLED], [$this->voll]);
        }
    }

    public function test_numbers_and_dates_are_empty_only_when_null_and_zero_is_filled(): void
    {
        foreach (['betrag', 'anzahl', 'datum'] as $field) {
            $this->assertFilter($field, [EmptyFilter::CONTENT => EmptyFilter::EMPTY], [$this->leer, $this->leertext]);
            $this->assertFilter($field, [EmptyFilter::CONTENT => EmptyFilter::FILLED], [$this->voll]);
        }
    }

    public function test_content_combines_with_the_other_inputs_of_the_field_by_and(): void
    {
        $this->assertFilter('nummer', ['value' => '2026', EmptyFilter::CONTENT => EmptyFilter::FILLED], [$this->voll]);
        $this->assertFilter('nummer', ['value' => '2026', EmptyFilter::CONTENT => EmptyFilter::EMPTY], []);
        $this->assertFilter('betrag', ['from' => '0', EmptyFilter::CONTENT => EmptyFilter::FILLED], [$this->voll]);
        $this->assertFilter('datum', ['from' => '2026-01-01', EmptyFilter::CONTENT => EmptyFilter::EMPTY], []);
        // Ohne die neue Auswahl (gespeicherter Zustand von vor B-158): wie bisher.
        $this->assertFilter('nummer', ['value' => '2026'], [$this->voll]);
        $this->assertFilter('betrag', ['from' => '0'], [$this->voll]);
    }

    public function test_select_lists_have_entries_for_empty_and_filled_combined_by_or(): void
    {
        $this->assertFilter('status', [EmptyFilter::EMPTY], [$this->leer]);
        $this->assertFilter('status', [EmptyFilter::FILLED], [$this->voll, $this->leertext]);
        $this->assertFilter('status', [EmptyFilter::EMPTY, 'offen'], [$this->voll, $this->leer]);
        $this->assertFilter('status', ['erledigt'], [$this->leertext]);

        $this->assertFilter('kunde_id', EmptyFilter::EMPTY, [$this->leer, $this->leertext]);
        $this->assertFilter('kunde_id', EmptyFilter::FILLED, [$this->voll]);
        $this->assertFilter('kunde_id', $this->voll->getAttribute('kunde_id'), [$this->voll]);
    }

    public function test_yes_no_keeps_yes_and_no_and_adds_empty(): void
    {
        $this->assertFilter('bezahlt', '1', [$this->leertext]);
        $this->assertFilter('bezahlt', '0', [$this->voll]);
        $this->assertFilter('bezahlt', EmptyFilter::EMPTY, [$this->leer]);
        // Gespeicherter Zustand des früheren Ja/Nein-Filters.
        $this->assertFilter('bezahlt', true, [$this->leertext]);
    }

    public function test_persons_can_be_filtered_for_records_without_a_person(): void
    {
        $this->assertFilter('created_by', EmptyFilter::EMPTY, [$this->leer, $this->leertext]);
        $this->assertFilter('created_by', EmptyFilter::FILLED, [$this->voll]);
        $this->assertFilter('created_by', $this->anna->id, [$this->voll]);
    }

    public function test_indicators_name_the_field_and_the_content(): void
    {
        $indicators = fn (Testable $page): array => array_map(
            static fn ($indicator): string => (string) $indicator->getLabel(),
            $page->instance()->getTable()->getFilterIndicators(),
        );

        $this->assertSame(['Nummer: 2026', 'Nummer: gefüllt'], $indicators($this->page()->filterTable('nummer', ['value' => '2026', EmptyFilter::CONTENT => EmptyFilter::FILLED])));
        $this->assertSame(['Betrag: leer'], $indicators($this->page()->filterTable('betrag', [EmptyFilter::CONTENT => EmptyFilter::EMPTY])));
        $this->assertSame(['Status: (leer)'], $indicators($this->page()->filterTable('status', [EmptyFilter::EMPTY])));
        $this->assertSame(['Bezahlt: (leer)'], $indicators($this->page()->filterTable('bezahlt', EmptyFilter::EMPTY)));
    }

    public function test_required_fields_of_designer_tables_have_no_empty_filter(): void
    {
        $schema = fn (string $filter): string => (string) json_encode(array_map(
            static fn ($component): string => $component->getName(),
            $this->page()->instance()->getTable()->getFilter($filter)->getFormSchema()[0]->getChildComponents(),
        ));

        $this->assertStringNotContainsString(EmptyFilter::CONTENT, $schema('titel'), 'Pflichtfeld: nie leer.');
        $this->assertStringContainsString(EmptyFilter::CONTENT, $schema('nummer'));
    }
}
