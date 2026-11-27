<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Bulk\BulkUndo;
use App\DynamicData\Ui\BulkUndoActions;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\WorkItems\Pages\ViewWorkItem;
use App\Models\BulkChange;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Models\WorkItem;
use App\Work\Types\BulkRecordsWork;
use App\Work\WorkService;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Filament\Notifications\NotificationMailbox;
use Livewire\Livewire;

/** Sammeländerung S3 „Rückgängig“ (B-133): Liste „Letzte Sammeländerungen“, Bestätigung, Link in Meldung und auf der Seite der Arbeit. */
class BulkUndoUiTest extends DynamicDataTestCase
{
    private CustomTable $courses;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->superAdmin();
        $this->actingAs($this->admin);
        $this->courses = $this->service()->createTable(['name' => 'kurse', 'label' => 'Kurse'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true, 'show_in_table' => true],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
        ]);
    }

    /** @return list<string> */
    private function seedCourses(int $count): array
    {
        $writer = app(RecordWriter::class);

        return array_map(fn (int $i): string => (string) $writer->create($this->courses, ['titel' => "Gitarre {$i}", 'tarif' => '120.00'], WriteOptions::seed())->getKey(), range(1, $count));
    }

    /** @return list<mixed> */
    private function tariffs(): array
    {
        return array_values(DynamicRecord::forTable($this->courses)->newQuery()->orderBy('id')->pluck('tarif')->all());
    }

    /** @param  list<string>  $ids */
    private function raise(array $ids): WorkItem
    {
        return app(WorkService::class)->startAndDrive(BulkRecordsWork::KEY, [
            'table_id' => $this->courses->id, 'locked' => [], 'record_ids' => $ids, 'dry_run' => false,
            'changes' => ['tarif' => ['op' => 'calc', 'operator' => 'percent', 'operand' => '5', 'round_mode' => 'multiple', 'round_value' => '0.05']],
        ], auth()->user());
    }

    /** Zuletzt gesendete Meldung (wie Notification::assertNotified(), aber mit Zugriff auf Text und Links). */
    private function lastNotification(): ?Notification
    {
        app(NotificationMailbox::class)->flush();
        $component = new Notifications;
        $component->mount();
        $component->pullNotifications();

        return $component->notifications->last();
    }

    public function test_the_success_message_offers_undo(): void
    {
        $ids = $this->seedCourses(2);

        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->selectTableRecords($ids)
            ->callAction(TestAction::make('bulkEdit')->table()->bulk(), ['changes' => ['tarif' => ['on' => true, 'op' => 'calc', 'operator' => 'percent', 'operand' => '5']]]);

        $head = BulkChange::query()->sole();
        $last = $this->lastNotification();
        $this->assertSame('2 Datensätze geändert.', $last?->getTitle());
        $this->assertSame(BulkUndoActions::undoUrl($head), $last?->getActions()[0]?->getUrl());
        $this->assertStringContainsString('action=undoBulkChange', BulkUndoActions::undoUrl($head));
    }

    public function test_history_lists_the_bulk_edit_and_undo_restores_the_values(): void
    {
        $ids = $this->seedCourses(3);
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->assertActionHidden(TestAction::make(BulkUndoActions::HISTORY)->table());
        $head = BulkChange::query()->where('run', (string) $this->raise($ids)->result['run'])->sole();

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->assertActionVisible(TestAction::make(BulkUndoActions::HISTORY)->table())
            ->mountAction(TestAction::make(BulkUndoActions::HISTORY)->table())
            ->assertMountedActionModalSee('Tarif: um % ändern 5 %, gerundet auf ein Vielfaches von 0.05')
            ->assertMountedActionModalSeeHtml('data-bulk-undo="'.$head->id.'"')
            // Wie der Link in der Liste: Bestätigung aus dem Seitenfenster heraus öffnen.
            ->call('mountAction', BulkUndoActions::UNDO, ['head' => $head->id])
            ->assertActionMounted([TestAction::make(BulkUndoActions::HISTORY)->table(), TestAction::make(BulkUndoActions::UNDO)->arguments(['head' => $head->id])])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame(['120.00', '120.00', '120.00'], $this->tariffs(), 'Aus der Liste zurückgenommen.');
        $head = BulkChange::query()->where('run', (string) $this->raise($ids)->result['run'])->sole();

        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction(BulkUndoActions::UNDO, ['head' => $head->id])
            ->assertMountedActionModalSee('Sammeländerung rückgängig machen?')
            ->assertMountedActionModalSee('Datensätze, bei denen ein geändertes Feld seither einen anderen Wert hat, bleiben ganz stehen.')
            ->callMountedAction()
            ->assertNotified('3 Datensätze zurückgenommen.');

        $this->assertSame(['120.00', '120.00', '120.00'], $this->tariffs());
        $this->assertNotNull($head->fresh()->undone_by_id);

        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction(TestAction::make(BulkUndoActions::HISTORY)->table())
            ->assertMountedActionModalSee('zurückgenommen am')
            ->assertMountedActionModalSee('Rücknahme vom');
    }

    public function test_a_record_changed_by_hand_stays_and_the_message_names_it(): void
    {
        $ids = $this->seedCourses(2);
        $head = BulkChange::query()->where('run', (string) $this->raise($ids)->result['run'])->sole();
        app(RecordWriter::class)->update(DynamicRecord::forTable($this->courses)->newQuery()->find($ids[1]), ['tarif' => '200.00']);

        // Vorschau in der Bestätigung: was zurückkommt, was bleibt.
        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction(BulkUndoActions::UNDO, ['head' => $head->id])
            ->assertMountedActionModalSee('1 Datensatz wird zurückgenommen.')
            ->assertMountedActionModalSee('1 seither geändert, bleibt.')
            ->assertMountedActionModalSee('„Gitarre 1“')
            ->assertMountedActionModalSee('wird zurückgenommen')
            ->assertMountedActionModalSee('seither geändert, bleibt')
            ->assertMountedActionModalSee('1 Datensatz zurücknehmen');

        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->callAction(BulkUndoActions::UNDO, arguments: ['head' => $head->id]);

        $notification = $this->lastNotification();
        $this->assertSame('1 Datensatz zurückgenommen. 1 seither geändert, bleibt.', $notification?->getTitle());
        $this->assertStringContainsString('„Gitarre 2“: seither geändert', (string) $notification?->getBody());
        $this->assertSame(['120.00', '200.00'], $this->tariffs());
    }

    public function test_the_address_opens_the_confirmation_and_others_need_the_change_log_right(): void
    {
        $ids = $this->seedCourses(1);
        $head = BulkChange::query()->where('run', (string) $this->raise($ids)->result['run'])->sole();

        Livewire::withQueryParams(['action' => BulkUndoActions::UNDO, 'actionArguments' => ['head' => $head->id]])
            ->test(DynamicDataPage::class, ['table' => 'kurse'])
            ->call('mountDefaultActions')
            ->assertSet('mountedActions.0.name', BulkUndoActions::UNDO)
            ->assertMountedActionModalSee('Sammeländerung rückgängig machen?');

        $this->actingAs($this->userWithPermissions(['data.kurse.view', 'data.kurse.update']));
        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->assertActionHidden(TestAction::make(BulkUndoActions::HISTORY)->table())
            ->assertActionHidden(TestAction::make(BulkUndoActions::UNDO)->arguments(['head' => $head->id]));

        $this->actingAs($this->userWithPermissions(['data.kurse.view', 'data.kurse.update', 'activity_log.view']));
        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->assertActionVisible(TestAction::make(BulkUndoActions::HISTORY)->table())
            ->assertActionVisible(TestAction::make(BulkUndoActions::UNDO)->arguments(['head' => $head->id]));
    }

    public function test_the_confirmation_shows_before_and_after_and_blocks_when_nothing_would_come_back(): void
    {
        $ids = $this->seedCourses(2);
        $first = BulkChange::query()->where('run', (string) $this->raise($ids)->result['run'])->sole();
        $second = BulkChange::query()->where('run', (string) $this->raise($ids)->result['run'])->sole();

        $data = BulkUndoActions::previewData(new BulkUndo($second, $this->courses));
        $this->assertSame(['Tarif'], $data['columns']);
        $this->assertSame(2, $data['counts'][BulkUndo::REVERT]);
        $this->assertSame(['now' => '132,30', 'after' => '126,00'], [
            'now' => str_replace("'", '', $data['rows'][0]['cells'][0]['now']),
            'after' => str_replace("'", '', $data['rows'][0]['cells'][0]['after']),
        ]);

        // Die ältere: alle seither von der jüngeren geändert, nichts käme zurück.
        Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction(BulkUndoActions::UNDO, ['head' => $first->id])
            ->assertMountedActionModalSee('Kein Datensatz würde zurückgenommen.')
            ->assertMountedActionModalSee('2 seither geändert, bleiben.')
            ->callMountedAction();

        $this->assertNull($first->fresh()->undone_by_id, 'Gesperrter Knopf: keine Rücknahme.');
    }

    public function test_the_page_of_a_finished_bulk_edit_offers_undo(): void
    {
        $ids = $this->seedCourses(1);
        $item = $this->raise($ids);
        $head = BulkChange::query()->where('run', (string) $item->result['run'])->sole();

        Livewire::test(ViewWorkItem::class, ['record' => $item->getRouteKey()])
            ->assertActionVisible('undo')
            ->assertSee(BulkUndoActions::undoUrl($head));
    }
}
