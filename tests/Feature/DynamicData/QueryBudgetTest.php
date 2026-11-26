<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\DetailRelations;
use App\Filament\Pages\DynamicDataPage;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Database\Seeders\LagerDemoSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Obergrenze der Abfragen je Seite (B-150): Auf dem Webspace kostet jede Abfrage eine Rundreise zum Datenbankrechner
 * (Strato rund 2,2 ms). Lager-Demo, Lagerorte mit drei Detailtabellen; Messung 26.11.2026 nach B-150 (PostgreSQL, frischer
 * Request): 47 Abfragen beim Aufruf der Seite mit Auswahl, 32 je Klick (vorher mindestens 101 und 58).
 */
class QueryBudgetTest extends DynamicDataTestCase
{
    private const int PAGE_BUDGET = 50;

    private const int CLICK_BUDGET = 35;

    /** @var list<int> */
    private array $orte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LagerDemoSeeder::class);
        $this->actingAs($this->superAdmin());
        $this->orte = DynamicRecord::forTableName('lager_orte')->orderBy('id')->pluck('id')->all();
    }

    public function test_page_with_selection_stays_within_query_budget(): void
    {
        $this->get('/admin/data/lager_orte')->assertOk();
        $this->freshRequest();

        $count = $this->countQueries(fn () => $this->get('/admin/data/lager_orte?sel='.$this->orte[1])->assertOk());

        $this->assertLessThanOrEqual(self::PAGE_BUDGET, $count, "Seite mit Auswahl: $count Abfragen.");
    }

    public function test_row_click_stays_within_query_budget(): void
    {
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'lager_orte'])->call('selectRecord', $this->orte[0]);
        $this->freshRequest();

        $count = $this->countQueries(fn () => $page->call('selectRecord', $this->orte[2]));

        $this->assertLessThanOrEqual(self::CLICK_BUDGET, $count, "Klick auf einen Lagerort: $count Abfragen.");
    }

    public function test_detail_candidates_follow_structure_change_in_same_request(): void
    {
        $before = DetailRelations::candidates('lager_orte')->count();

        $this->service()->addField($this->table('lager_mitarbeitende'), [
            'name' => 'stammlager_id', 'label' => 'Stammlager', 'type' => 'relation',
            'options' => ['table' => 'lager_orte', 'display_field' => 'bezeichnung'],
        ]);

        $this->assertSame($before + 1, DetailRelations::candidates('lager_orte')->count(), 'Neues Verknüpfungsfeld im selben Request sichtbar.');
    }

    /** Wie ein neuer Request: je Request gemerkte Angaben verwerfen. */
    private function freshRequest(): void
    {
        app()->forgetScopedInstances();
        DynamicRecord::forget();
    }

    private function countQueries(callable $run): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $run();

        return $count;
    }

    private function table(string $name): CustomTable
    {
        return CustomTable::query()->where('name', $name)->firstOrFail();
    }
}
