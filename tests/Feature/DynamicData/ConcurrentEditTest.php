<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Ui\DynamicRecordActions;
use App\DynamicData\Write\RecordVersion;
use App\DynamicData\Write\RecordWriter;
use App\Filament\Pages\DynamicDataPage;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;

/**
 * Gleichzeitiges Bearbeiten in der Datenansicht (Fundament 2, F2): Das Formular trägt den gelesenen Stand mit; wurde
 * der Datensatz inzwischen geändert, bleibt das Fenster offen mit der Meldung „inzwischen geändert“ und den Knöpfen
 * „Neu laden“ und „Trotzdem speichern“.
 */
class ConcurrentEditTest extends DynamicDataTestCase
{
    use InteractsWithAdmin;

    private CustomTable $table;

    private DynamicRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->table = $this->projects();
        $this->record = app(RecordWriter::class)->create($this->table, ['titel' => 'Alpha', 'anzahl' => 1]);
        $this->actingAs($this->superAdmin());
    }

    private function fresh(): DynamicRecord
    {
        return DynamicRecord::forTableName('projekte')->newQuery()->findOrFail($this->record->id);
    }

    public function test_edit_form_carries_the_version_and_saves_when_nothing_changed(): void
    {
        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->mountAction(TestAction::make('editRow')->table($this->record))
            ->assertActionDataSet([DynamicRecordActions::VERSION_FIELD => RecordVersion::of($this->fresh())])
            ->callMountedAction()
            ->assertHasNoErrors();

        Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->callAction(TestAction::make('editRow')->table($this->record), ['titel' => 'Beta'])
            ->assertHasNoErrors()
            ->assertNotNotified(__('errors.titles.stale_record'));

        $this->assertSame('Beta', $this->fresh()->titel);
    }

    public function test_stale_form_keeps_the_window_open_and_offers_reload_and_overwrite(): void
    {
        $anna = User::factory()->create(['name' => 'Anna Muster']);
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->mountAction(TestAction::make('editRow')->table($this->record))
            ->setActionData(['titel' => 'Meine Fassung']);

        // Inzwischen ändert Anna.
        $this->actingAs($anna);
        app(RecordWriter::class)->update($this->fresh(), ['anzahl' => 7]);
        $this->actingAs($this->superAdmin());

        $page->callMountedAction()
            ->assertActionHalted(TestAction::make('editRow')->table($this->record))
            ->assertNotified(__('errors.titles.stale_record'));

        $this->assertSame(['Alpha', 7], [$this->fresh()->titel, $this->fresh()->anzahl], 'Nichts überschrieben.');

        // „Trotzdem speichern“: das offene Formular mit der aktuellen Version erneut abschicken.
        $page->dispatch(DynamicRecordActions::STALE_OVERWRITE_EVENT, component: $page->instance()->getId(), version: RecordVersion::of($this->fresh()))
            ->assertHasNoErrors()
            ->assertActionNotMounted(TestAction::make('editRow')->table($this->record));

        $this->assertSame(['Meine Fassung', 1], [$this->fresh()->titel, $this->fresh()->anzahl], 'Das ganze Formular überschreibt den fremden Stand – bewusst, protokolliert.');
        $this->assertTrue(Activity::query()->where('log_name', 'dynamic.projekte')->orderByDesc('id')->firstOrFail()->properties['overwrote']);
    }

    public function test_reload_closes_the_window(): void
    {
        $page = Livewire::test(DynamicDataPage::class, ['table' => 'projekte'])
            ->mountAction(TestAction::make('editRow')->table($this->record))
            ->assertActionMounted(TestAction::make('editRow')->table($this->record));

        $page->dispatch(DynamicRecordActions::STALE_RELOAD_EVENT, component: 'jemand-anders')
            ->assertActionMounted(TestAction::make('editRow')->table($this->record));

        $page->dispatch(DynamicRecordActions::STALE_RELOAD_EVENT, component: $page->instance()->getId())
            ->assertActionNotMounted(TestAction::make('editRow')->table($this->record))
            ->assertDispatched(DynamicRecordActions::REFRESH_EVENT);
    }
}
