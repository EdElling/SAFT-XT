<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DynamicDataPage;
use App\Livewire\DynamicDetailTable;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\DataTable;
use App\Plugins\PluginRegistry;
use App\Plugins\SaftPlugin;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Feature\DynamicData\DynamicDataTestCase;

/** Sammelaktionen aus Plugins (DataAction::BULK, API 1.11, B-133 S4b): Ort, Datensätze, Papierkorb, Grenze, Typ. */
class PluginBulkActionTest extends DynamicDataTestCase
{
    /** @var list<list<int>> ausgewählte Schlüssel je Ausführung */
    public static array $runs = [];

    /** Wie oft der eigene before()-Hook des Plugins lief. */
    public static int $ownBefore = 0;

    private CustomTable $kunden;

    private CustomTable $projekte;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        self::$runs = [];
        self::$ownBefore = 0;
        $this->actingAs($this->superAdmin());

        $this->kunden = $this->service()->createTable(['name' => 'kunden', 'label' => 'Kunden'], [
            ['name' => 'firma', 'label' => 'Firma', 'type' => 'string', 'is_required' => true],
        ]);
        $this->projekte = $this->service()->createTable(['name' => 'projekte', 'label' => 'Projekte'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true, 'is_searchable' => true],
            ['name' => 'kunde_id', 'label' => 'Kunde', 'type' => 'relation', 'options' => ['table' => 'kunden', 'display_field' => 'firma']],
        ]);

        app(PluginRegistry::class)->add(new class extends SaftPlugin
        {
            public function id(): string
            {
                return 'testbulk';
            }

            public function label(): string
            {
                return 'Test-Sammelaktion';
            }

            public function dataActions(): array
            {
                return [
                    new DataAction('projekte', DataAction::BULK, static fn (DataTable $table): BulkAction => BulkAction::make('testbulkMark')
                        ->label('Markieren')
                        ->before(static function (): void {
                            PluginBulkActionTest::$ownBefore++;
                        })
                        ->action(static function (Collection $records): void {
                            PluginBulkActionTest::$runs[] = $records->map(static fn (DynamicRecord $r): int => (int) $r->getKey())->sort()->values()->all();
                        })),
                    // Falscher Typ: wird gemeldet und übergangen.
                    new DataAction('projekte', DataAction::BULK, static fn (DataTable $table): Action => Action::make('testbulkWrong')),
                ];
            }
        });
    }

    /** @return list<int> */
    private function seedProjects(int $count, ?int $kunde = null): array
    {
        return array_map(fn (int $i): int => (int) app(RecordWriter::class)->create($this->projekte, ['titel' => "Projekt {$i}", 'kunde_id' => $kunde], WriteOptions::seed())->getKey(), range(1, $count));
    }

    private function action(): TestAction
    {
        return TestAction::make('testbulkMark')->table()->bulk();
    }

    public function test_plugin_bulk_action_gets_the_selected_records_and_names_its_plugin(): void
    {
        $ids = $this->seedProjects(3);

        $actions = app(PluginRegistry::class)->dataActions($this->projekte, DataAction::BULK);
        $this->assertCount(1, $actions, 'Nur die BulkAction, die falsche wird übergangen');
        $this->assertSame('Aus dem Plugin Test-Sammelaktion', $actions[0]->getTooltip());

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertActionVisible($this->action())
            ->selectTableRecords([(string) $ids[0], (string) $ids[2]])
            ->callAction($this->action())
            ->assertHasNoActionErrors()
            ->assertDispatched('deselectAllTableRecords');

        $this->assertSame([[$ids[0], $ids[2]]], self::$runs);
        $this->assertSame(1, self::$ownBefore, 'Der eigene before()-Hook des Plugins läuft weiter');
    }

    public function test_wrong_type_is_reported_with_the_plugin(): void
    {
        Exceptions::fake();

        app(PluginRegistry::class)->dataActions($this->projekte, DataAction::BULK);

        Exceptions::assertReported(static fn (InvalidArgumentException $e): bool => str_contains($e->getMessage(), 'Plugin testbulk')
            && str_contains($e->getMessage(), BulkAction::class));
    }

    public function test_plugin_bulk_action_is_hidden_with_the_trash_filter(): void
    {
        $this->seedProjects(1);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->assertActionVisible($this->action())
            ->set('tableFilters.trashed.value', true)
            ->assertActionHidden($this->action())
            ->set('tableFilters.trashed.value', false)
            ->assertActionHidden($this->action());
    }

    public function test_limit_is_checked_before_the_plugin_runs(): void
    {
        config(['dynamic_data.bulk_max' => 2]);
        $ids = array_map('strval', $this->seedProjects(3));

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->selectTableRecords($ids)
            ->callAction($this->action())
            ->assertNotified('3 Datensätze ausgewählt; höchstens 2 sind je Sammelaktion möglich. Bitte den Filter eingrenzen.');

        $this->assertSame([], self::$runs);
        $this->assertSame(0, self::$ownBefore);

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->selectTableRecords([$ids[0], $ids[1]])
            ->callAction($this->action());

        $this->assertCount(1, self::$runs);
    }

    public function test_plugin_bulk_action_also_appears_in_detail_tables(): void
    {
        $this->service()->updateTable($this->kunden, ['detail_field_ids' => [$this->fieldId('projekte', 'kunde_id')]]);
        $kunde = DynamicRecord::forTable($this->kunden)->create(['firma' => 'Mein']);
        $ids = $this->seedProjects(2, (int) $kunde->getKey());

        Livewire::test(DynamicDetailTable::class, [
            'parentTable' => 'kunden',
            'parentId' => $kunde->getKey(),
            'relationFieldId' => $this->fieldId('projekte', 'kunde_id'),
            'level' => 1,
        ])
            ->selectTableRecords([(string) $ids[1]])
            ->callAction($this->action());

        $this->assertSame([[$ids[1]]], self::$runs);
    }
}
