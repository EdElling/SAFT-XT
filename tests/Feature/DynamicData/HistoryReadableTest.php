<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\History\LoggedChanges;
use App\DynamicData\Ui\DynamicRecordActions;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Filament\Pages\DynamicDataPage;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Support\ActivityChanges;
use App\Filament\Widgets\MyChangesWidget;
use App\Filament\Widgets\RecentChangesWidget;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Enums\Width;
use Livewire\Livewire;

/** B-174: Verlauf lesbar – Bezeichnungen statt Spaltennamen, Werte im Format der Anwendung, heutiger Anzeigewert. */
class HistoryReadableTest extends DynamicDataTestCase
{
    private CustomTable $faecher;

    private CustomTable $kurse;

    private DynamicRecord $klavier;

    private DynamicRecord $schlagzeug;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->actingAs($this->superAdmin());

        $this->faecher = $this->service()->createTable(['name' => 'faecher', 'label' => 'Fächer'], [
            ['name' => 'bezeichnung', 'label' => 'Bezeichnung', 'type' => 'string', 'is_required' => true],
        ]);
        $this->kurse = $this->service()->createTable(['name' => 'kurse', 'label' => 'Kurse'], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string', 'is_required' => true],
            ['name' => 'fach', 'label' => 'Fach', 'type' => 'relation', 'options' => ['table' => 'faecher', 'display_field' => 'bezeichnung']],
            ['name' => 'wochentag', 'label' => 'Wochentag', 'type' => 'select', 'options' => ['options' => [['value' => 'mo', 'label' => 'Montag'], ['value' => 'do', 'label' => 'Donnerstag']]]],
            ['name' => 'tarif', 'label' => 'Tarif', 'type' => 'decimal', 'options' => ['precision' => 10, 'scale' => 2]],
            ['name' => 'aktiv', 'label' => 'Aktiv', 'type' => 'boolean'],
            ['name' => 'beginn', 'label' => 'Beginn', 'type' => 'date'],
        ]);

        $writer = app(RecordWriter::class);
        $this->klavier = $writer->create($this->faecher, ['bezeichnung' => 'Klavier'], WriteOptions::seed());
        $this->schlagzeug = $writer->create($this->faecher, ['bezeichnung' => 'Schlagzeug'], WriteOptions::seed());
    }

    private function course(): DynamicRecord
    {
        $writer = app(RecordWriter::class);
        $course = $writer->create($this->kurse, ['titel' => 'Kurs A', 'fach' => $this->klavier->getKey(), 'wochentag' => 'mo', 'tarif' => '120', 'aktiv' => false, 'beginn' => '2026-08-17'], WriteOptions::ui());

        return $writer->update($course->refresh(), ['fach' => $this->schlagzeug->getKey(), 'wochentag' => 'do', 'tarif' => '1234.5', 'aktiv' => true, 'beginn' => '2026-09-01'], WriteOptions::ui());
    }

    /** Ein Protokolleintrag wie aus der Datenbank, ohne zu schreiben. */
    private function entry(array $old, array $new): Activity
    {
        return (new Activity)->forceFill(['subject_type' => 'dynamic:kurse', 'event' => 'updated', 'attribute_changes' => ['old' => $old, 'attributes' => $new]]);
    }

    public function test_history_shows_labels_and_formatted_values_one_field_per_line(): void
    {
        $what = DynamicRecordActions::historyRows($this->course())[0]['what'];

        $this->assertStringStartsWith("geändert\n", $what);
        $this->assertStringContainsString("Fach: Klavier → Schlagzeug\n", $what);
        $this->assertStringContainsString('Wochentag: Montag → Donnerstag', $what);
        $this->assertStringContainsString('Tarif: 120,00 → 1.234,50', $what);
        $this->assertStringContainsString('Aktiv: Nein → Ja', $what);
        $this->assertStringContainsString('Beginn: 17.08.2026 → 01.09.2026', $what);
        $this->assertStringNotContainsString('wochentag', $what);
    }

    public function test_linked_records_show_todays_name_deleted_and_missing(): void
    {
        $this->course();
        $this->klavier->refresh();
        app(RecordWriter::class)->update($this->klavier, ['bezeichnung' => 'Klavier klassisch'], WriteOptions::ui());
        $gitarre = app(RecordWriter::class)->create($this->faecher, ['bezeichnung' => 'Gitarre'], WriteOptions::seed());
        app(RecordWriter::class)->delete($gitarre, WriteOptions::ui());
        app()->forgetScopedInstances();

        $fach = collect(ActivityChanges::rows(Activity::query()->where('subject_type', 'dynamic:kurse')->where('event', 'updated')->sole()))->firstWhere('field', 'Fach');
        $this->assertSame(['Klavier klassisch', 'Schlagzeug'], [$fach['old'], $fach['new']], 'Heutiger Name');

        $other = collect(ActivityChanges::rows($this->entry(['fach' => 999], ['fach' => $gitarre->getKey()])))->firstWhere('field', 'Fach');
        $this->assertSame(['#999', 'Gitarre (gelöscht)'], [$other['old'], $other['new']]);
    }

    public function test_removed_choice_removed_field_old_utc_dates_and_empty_values(): void
    {
        $rows = collect(ActivityChanges::rows($this->entry(
            ['wochentag' => 'sa', 'gestrichen' => 'x', 'beginn' => null, 'tarif' => '5'],
            ['wochentag' => 'mo', 'gestrichen' => 'y', 'beginn' => '2026-08-31T22:00:00.000000Z', 'tarif' => null],
        )))->keyBy('field');

        $this->assertSame(['sa', 'Montag'], [$rows['Wochentag']['old'], $rows['Wochentag']['new']], 'Entfernte Wahl: gespeicherter Wert');
        $this->assertSame(['x', 'y'], [$rows['gestrichen']['old'], $rows['gestrichen']['new']], 'Entferntes Feld: technischer Name');
        $this->assertSame(['∅', '01.09.2026'], [$rows['Beginn']['old'], $rows['Beginn']['new']], 'Altes UTC-Datum in Ortszeit');
        $this->assertSame(['5,00', '∅'], [$rows['Tarif']['old'], $rows['Tarif']['new']]);
    }

    public function test_linked_records_of_all_entries_are_looked_up_once_per_table(): void
    {
        $course = $this->course();
        $writer = app(RecordWriter::class);

        foreach (range(1, 10) as $i) {
            $course = $writer->update($course->refresh(), ['fach' => $i % 2 === 0 ? $this->klavier->getKey() : $this->schlagzeug->getKey()], WriteOptions::ui());
        }

        app()->forgetScopedInstances();
        $connection = DynamicRecord::forTable($this->faecher)->getConnection();
        $connection->enableQueryLog();
        DynamicRecordActions::historyRows($course);
        $lookups = collect($connection->getQueryLog())->filter(static fn (array $q): bool => str_contains($q['query'], 'faecher'))->count();
        $connection->disableQueryLog();

        $this->assertSame(1, $lookups, 'Eine Abfrage der Fächer für alle Einträge');
    }

    public function test_widget_is_readable_and_the_administration_also_shows_technical_values(): void
    {
        $this->course();

        Livewire::test(RecentChangesWidget::class)->assertSee('Fach: Klavier → Schlagzeug');

        $entry = Activity::query()->where('subject_type', 'dynamic:kurse')->where('event', 'updated')->sole();
        $this->assertTrue(app(LoggedChanges::class)->covers($entry));
        $this->assertContains('wochentag', array_column(ActivityChanges::technicalRows($entry), 'field'));

        Livewire::test(ViewActivity::class, ['record' => $entry->getKey()])
            ->assertSee('Donnerstag')
            ->assertSee('Technische Werte');
    }

    /** Herkunft „Sammeländerung“ überall gleich: Verlauf, Widgets, Änderungsprotokoll (Liste und Einzelansicht). */
    public function test_bulk_origin_is_shown_in_history_widgets_and_the_change_log(): void
    {
        $course = app(RecordWriter::class)->create($this->kurse, ['titel' => 'Kurs B'], WriteOptions::ui());
        app(RecordWriter::class)->update($course->refresh(), ['wochentag' => 'do'], WriteOptions::bulk('bulk:test'));
        $entry = Activity::query()->where('subject_type', 'dynamic:kurse')->where('event', 'updated')->sole();

        $this->assertSame('geändert (Sammeländerung)', ActivityChanges::eventText($entry));
        $this->assertStringStartsWith('geändert (Sammeländerung)', DynamicRecordActions::historyRows($course)[0]['what']);
        Livewire::test(RecentChangesWidget::class)->assertSee('geändert (Sammeländerung)');
        Livewire::test(MyChangesWidget::class)->assertSee('geändert (Sammeländerung)');
        Livewire::test(ListActivities::class)->assertSee('geändert (Sammeländerung)');
        Livewire::test(ViewActivity::class, ['record' => $entry->getKey()])->assertSee('geändert (Sammeländerung)');
    }

    /** Dialog „Verlauf“: breiter, Zellen oben ausgerichtet, ohne Autofokus (sonst scrollt er nach unten). */
    public function test_history_dialog_is_wide_aligned_top_and_not_autofocused(): void
    {
        $course = $this->course();
        $action = TestAction::make('historyRow')->table($course);

        $page = Livewire::test(DynamicDataPage::class, ['table' => 'kurse'])
            ->mountAction($action)
            ->assertMountedActionModalSeeHtml('saft-history');

        $mounted = $page->instance()->getMountedAction();
        $this->assertSame(Width::SevenExtraLarge, $mounted->getModalWidth());
        $this->assertFalse($mounted->isModalAutofocused());
    }
}
