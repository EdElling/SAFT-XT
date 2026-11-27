<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Schema\SystemFields;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** B-159: Systemspalten angelegt/geändert am und von in Datenansicht, Filtern und Export. */
class SystemColumnsTest extends DynamicDataTestCase
{
    private CustomTable $projekte;

    private User $anna;

    private User $ben;

    private DynamicRecord $alt;

    private DynamicRecord $neu;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        Storage::fake('local');
        $this->projekte = $this->service()->createTable(['name' => 'projekte', 'label' => 'Projekte', 'export_enabled' => true], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
        ]);

        $this->anna = User::factory()->create(['name' => 'Anna Admin']);
        $this->ben = User::factory()->create(['name' => 'Ben Benutzer']);
        $writer = app(RecordWriter::class);

        $this->travelTo('2026-03-01 09:00:00');
        $this->actingAs($this->anna);
        $this->alt = $writer->create($this->projekte, ['titel' => 'Alt'], WriteOptions::ui());

        $this->travelTo('2026-06-15 14:30:00');
        $this->actingAs($this->ben);
        $this->neu = $writer->create($this->projekte, ['titel' => 'Neu'], WriteOptions::ui());
        $writer->update($this->alt->refresh(), ['titel' => 'Alt (geändert)'], WriteOptions::ui());
        $this->travelBack();

        $this->actingAs($this->superAdmin());
    }

    private function page(): Testable
    {
        return Livewire::test(DynamicDataPage::class, ['table' => 'projekte']);
    }

    /** Alle vier Systemspalten einblenden (wie im Spaltenmenü). */
    private function showSystemColumns(Testable $page): Testable
    {
        $state = array_map(static fn (array $item): array => in_array($item['name'], [...SystemFields::DATES, ...SystemFields::PERSONS], true) ? [...$item, 'isToggled' => true] : $item, $page->get('tableColumns'));

        return $page->call('applyTableColumnManager', $state);
    }

    public function test_system_columns_are_hidden_until_shown_and_show_names(): void
    {
        $page = $this->page();

        foreach ([...SystemFields::DATES, ...SystemFields::PERSONS] as $name) {
            $this->assertTrue($page->instance()->isTableColumnToggledHidden($name), "{$name} ist zunächst ausgeblendet");
        }

        $this->showSystemColumns($page)
            ->assertTableColumnStateSet('created_by', $this->anna->id, $this->alt)
            ->assertTableColumnFormattedStateSet('created_by', 'Anna Admin', $this->alt)
            ->assertTableColumnFormattedStateSet('updated_by', 'Ben Benutzer', $this->alt)
            ->assertTableColumnFormattedStateSet('created_by', 'Ben Benutzer', $this->neu)
            ->assertSee('Angelegt am')
            ->assertSee('Geändert von');
    }

    public function test_dates_sort_and_filter_by_period_and_persons_filter_by_user(): void
    {
        // Sortiert wird über den Spaltenkopf, also nur eingeblendet.
        $page = $this->showSystemColumns($this->page());

        $page->sortTable('created_at', 'asc')->assertCanSeeTableRecords([$this->alt, $this->neu], inOrder: true)
            ->sortTable('created_at', 'desc')->assertCanSeeTableRecords([$this->neu, $this->alt], inOrder: true);
        $this->assertFalse($page->instance()->getTable()->getColumn('created_by')->isSortable(), 'Namen liegen in einer anderen Datenbank');

        $this->page()->resetTableFilters()->filterTable('created_at', ['from' => '2026-06-01'])
            ->assertCanSeeTableRecords([$this->neu])->assertCanNotSeeTableRecords([$this->alt]);
        $this->page()->resetTableFilters()->filterTable('updated_at', ['from' => '2026-06-01'])
            ->assertCanSeeTableRecords([$this->neu, $this->alt]);
        $this->page()->resetTableFilters()->filterTable('created_by', $this->anna->id)
            ->assertCanSeeTableRecords([$this->alt])->assertCanNotSeeTableRecords([$this->neu]);
        $this->page()->resetTableFilters()->filterTable('updated_by', $this->ben->id)
            ->assertCanSeeTableRecords([$this->alt, $this->neu]);
    }

    public function test_visible_system_columns_are_exported_with_names(): void
    {
        $page = $this->showSystemColumns($this->page())->sortTable('titel', 'asc');
        $download = $page->callAction(TestAction::make('export_csv')->table())->effects['download'] ?? null;
        $this->assertNotNull($download);

        $lines = array_values(array_filter(explode("\n", substr(base64_decode($download['content']), 3)), filled(...)));
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), $lines);

        $this->assertSame(['Titel', 'Angelegt am', 'Geändert am', 'Angelegt von', 'Geändert von'], $rows[0]);
        $this->assertSame(['Alt (geändert)', '01.03.2026 09:00', '15.06.2026 14:30', 'Anna Admin', 'Ben Benutzer'], $rows[1]);
    }

    public function test_a_person_column_costs_one_query_only_when_shown(): void
    {
        $page = $this->page();
        DB::enableQueryLog();
        $page->call('$refresh');
        $hidden = collect(DB::getQueryLog())->filter(static fn (array $q): bool => str_contains($q['query'], 'users'))->count();
        DB::flushQueryLog();

        $this->showSystemColumns($page);
        $page->call('$refresh');
        $shown = collect(DB::getQueryLog())->filter(static fn (array $q): bool => str_contains($q['query'], '"users"') || str_contains($q['query'], '`users`'))->count();
        DB::disableQueryLog();

        $this->assertSame(0, $hidden, 'Ausgeblendet: keine Abfrage der Namen');
        $this->assertLessThanOrEqual(1, $shown, 'Eingeblendet: höchstens eine Abfrage der Namen je Anfrage');
    }

    public function test_connected_tables_have_no_system_columns(): void
    {
        $external = new CustomTable;
        $external->forceFill(['source' => CustomTable::SOURCE_EXTERNAL]);

        $this->assertSame([], SystemFields::for($external));
        $this->assertCount(4, SystemFields::for($this->projekte));
        $this->assertNull(DynamicRecord::forTable($this->projekte)->newQuery()->whereKey($this->alt->getKey())->value('deleted_at'));
    }
}
