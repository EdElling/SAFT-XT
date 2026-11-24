<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Write\RecordVersion;
use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\StaleRecordException;
use App\DynamicData\Write\WriteContext;
use App\DynamicData\Write\WriteOptions;
use App\Exceptions\BusinessRuleException;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Spatie\Activitylog\Support\ActivityLogStatus;

/**
 * Schreibweg RecordWriter (Fundament 2, F1): eine Transaktion je Vorgang über Daten und Hauptverbindung, Phasen,
 * Optionen; DynamicRecord::save()/delete() leiten dorthin.
 */
class RecordWriterTest extends DynamicDataTestCase
{
    private ?CustomTable $table = null;

    private function table(): CustomTable
    {
        return $this->table ??= $this->projects();
    }

    private function writer(): RecordWriter
    {
        return app(RecordWriter::class);
    }

    private function recordCount(): int
    {
        return DynamicRecord::forTableName('projekte')->newQuery()->withTrashed()->count();
    }

    public function test_failing_saved_listener_rolls_back_record_and_log(): void
    {
        $this->actingAs(User::factory()->create());
        Event::listen(RecordSaved::class, static function (): void {
            throw new RecordRejected('Nicht so.');
        });

        try {
            $this->writer()->create($this->table(), ['titel' => 'Alpha']);
            $this->fail('RecordRejected erwartet.');
        } catch (RecordRejected $e) {
            $this->assertSame('Nicht so.', $e->getMessage());
        }

        $this->assertSame(0, $this->recordCount(), 'Der Datensatz darf nicht gespeichert sein.');
        $this->assertSame(0, Activity::query()->where('log_name', 'dynamic.projekte')->count(), 'Auch der Protokolleintrag ist zurückgerollt.');
    }

    public function test_direct_model_calls_run_through_the_writer(): void
    {
        Event::listen(RecordSaved::class, static function (RecordSaved $event): void {
            if ($event->value('titel') === 'kaputt') {
                throw new RuntimeException('Listener wirft.');
            }
        });

        $ok = DynamicRecord::forTable($this->table())->create(['titel' => 'gut']);
        $this->assertTrue($ok->exists);

        try {
            DynamicRecord::forTable($this->table())->create(['titel' => 'kaputt']);
            $this->fail('RuntimeException erwartet.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $this->recordCount(), 'Nur der gute Datensatz ist da – auch $model->create() läuft über den Writer.');

        $ok->update(['titel' => 'besser']);
        $this->assertSame('besser', $ok->fresh()->titel);
        $ok->delete();
        $this->assertNotNull($ok->fresh()->deleted_at);
        $ok->restore();
        $this->assertNull($ok->fresh()->deleted_at);
    }

    public function test_plugin_writes_in_saving_listener_share_the_transaction(): void
    {
        $table = $this->table();
        Event::listen(RecordSaving::class, static function (RecordSaving $event) use ($table): void {
            if ($event->creating() && $event->value('titel') === 'Haupt') {
                DynamicRecord::forTable($table)->create(['titel' => 'Nebenwirkung']);
            }
        });
        Event::listen(RecordSaved::class, static function (RecordSaved $event): void {
            if ($event->value('titel') === 'Haupt') {
                throw new RecordRejected('Abbruch nach Nebenwirkung.');
            }
        });

        try {
            $this->writer()->create($table, ['titel' => 'Haupt']);
        } catch (RecordRejected) {
        }

        $this->assertSame(0, $this->recordCount(), 'Die Nebenwirkung des Plugins ist mit zurückgerollt.');
    }

    public function test_batch_is_all_or_nothing_with_one_transaction(): void
    {
        $table = $this->table();

        try {
            $this->writer()->batch(function () use ($table): void {
                $this->writer()->create($table, ['titel' => 'Eins']);
                $this->writer()->create($table, ['titel' => 'Zwei']);

                throw new RuntimeException('Mitten im Stapel.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $this->recordCount());

        $this->writer()->batch(function () use ($table): void {
            $this->writer()->create($table, ['titel' => 'Eins']);
            $this->writer()->create($table, ['titel' => 'Zwei']);
        });

        $this->assertSame(2, $this->recordCount());
    }

    public function test_options_control_log_and_plugin_events(): void
    {
        $this->actingAs(User::factory()->create());
        $heard = 0;
        Event::listen(RecordSaving::class, static function () use (&$heard): void {
            $heard++;
        });

        $this->writer()->create($this->table(), ['titel' => 'leise'], WriteOptions::seed());
        $this->assertSame(0, $heard, 'Seed: keine Ereignisse an Plugins.');
        $this->assertSame(0, Activity::query()->where('log_name', 'dynamic.projekte')->count(), 'Seed: kein Protokoll.');

        $this->writer()->create($this->table(), ['titel' => 'laut'], WriteOptions::ui());
        $this->assertSame(1, $heard);
        $entry = Activity::query()->where('log_name', 'dynamic.projekte')->sole();
        $this->assertSame('ui', $entry->properties['via']);
        $this->assertSame('laut', $entry->attribute_changes['attributes']['titel']);

        // Optionen des äusseren Aufrufs gelten innen.
        $this->writer()->batch(fn () => DynamicRecord::forTable($this->table())->create(['titel' => 'importiert']), WriteOptions::import());
        $this->assertSame(1, Activity::query()->where('log_name', 'dynamic.projekte')->count(), 'Import: kein Eintrag je Datensatz.');
        $this->assertSame(3, $this->recordCount());
    }

    public function test_version_detects_concurrent_changes_and_allows_a_deliberate_overwrite(): void
    {
        $anna = User::factory()->create(['name' => 'Anna Muster']);
        $table = $this->table();
        $record = $this->writer()->create($table, ['titel' => 'Alpha', 'anzahl' => 1]);
        $version = RecordVersion::of($record->fresh());

        // Jemand anderes ändert inzwischen.
        $this->actingAs($anna);
        $this->writer()->update(DynamicRecord::forTable($table)->newQuery()->findOrFail($record->id), ['anzahl' => 2]);

        try {
            $this->writer()->update($record, ['titel' => 'Beta'], WriteOptions::ui()->expecting($version));
            $this->fail('StaleRecordException erwartet.');
        } catch (StaleRecordException $e) {
            $this->assertSame('Anna Muster', $e->changedBy);
            $this->assertStringContainsString('Anna Muster hat diesen Datensatz um', $e->getMessage());
            $this->assertSame('Inzwischen geändert', $e->title);
            $this->assertSame(RecordVersion::of($record->fresh()), $e->currentVersion);
        }

        $this->assertSame('Alpha', $record->fresh()->titel, 'Nichts gespeichert.');

        // Bewusst überschreiben: mit der aktuellen Version aus der Meldung.
        $this->writer()->update($record, ['titel' => 'Beta'], WriteOptions::ui()->expecting($e->currentVersion, overwriting: true));
        $this->assertSame(['Beta', 2], [$record->fresh()->titel, $record->fresh()->anzahl], 'Die fremde Änderung bleibt, die eigene kommt dazu.');
        $this->assertTrue(Activity::query()->where('log_name', 'dynamic.projekte')->orderByDesc('id')->firstOrFail()->properties['overwrote']);

        // Ohne Version prüft der Writer nichts (Sammeländerung, Läufe, Plugins ohne Formular).
        $this->writer()->update($record, ['titel' => 'Gamma']);
        $this->assertSame('Gamma', $record->fresh()->titel);
    }

    public function test_version_ignores_computed_fields_and_reports_deleted_records(): void
    {
        $table = $this->table();
        $this->service()->addField($table->fresh(), [
            'name' => 'doppelt', 'label' => 'Doppelt', 'type' => 'computed',
            'options' => ['result' => 'decimal', 'precision' => 12, 'scale' => 2, 'formula' => 'budget * 2'],
        ]);
        $record = DynamicRecord::forTableName('projekte')->create(['titel' => 'A', 'budget' => 10]);

        $this->assertNotContains('doppelt', RecordVersion::fields($record));
        $this->assertContains('budget', RecordVersion::fields($record));
        $this->assertSame(RecordVersion::of($record->fresh()), RecordVersion::of(DynamicRecord::forTableName('projekte')->newQuery()->findOrFail($record->id)), 'Gleicher Stand, gleiche Version.');

        $version = RecordVersion::of($record->fresh());
        $record->fresh()->delete();

        try {
            $this->writer()->update($record, ['titel' => 'B'], WriteOptions::ui()->expecting($version));
            $this->fail('Gelöscht erwartet.');
        } catch (BusinessRuleException $e) {
            $this->assertSame('dynamic.record_deleted', $e->errorCode);
        }
    }

    public function test_logging_state_is_restored_after_a_silent_write(): void
    {
        $this->writer()->create($this->table(), ['titel' => 'leise'], WriteOptions::seed());
        $this->assertFalse(app(ActivityLogStatus::class)->disabled());
        $this->writer()->create($this->table(), ['titel' => 'normal']);
        $this->assertSame(1, Activity::query()->where('log_name', 'dynamic.projekte')->count());
    }

    public function test_after_commit_runs_once_both_connections_are_committed_and_never_after_a_rollback(): void
    {
        $writer = app(RecordWriter::class);
        $ran = [];

        $writer->batch(function () use ($writer, &$ran): void {
            $writer->create($this->table(), ['titel' => 'A']);
            Saft::afterCommit(function () use (&$ran): void {
                $ran[] = 'a';
            });
            $writer->batch(function () use (&$ran): void {
                WriteContext::afterCommit(function () use (&$ran): void {
                    $ran[] = 'b';
                });
            });
            // Noch nichts: die Transaktion ist offen.
            $this->assertSame([], $ran);
        });
        $this->assertSame(['a', 'b'], $ran);

        // Rollback: die Wirkung verfällt.
        try {
            $writer->batch(function () use ($writer, &$ran): void {
                $writer->create($this->table(), ['titel' => 'B']);
                WriteContext::afterCommit(function () use (&$ran): void {
                    $ran[] = 'verworfen';
                });

                throw new RuntimeException('abbrechen');
            });
            $this->fail('Ausnahme erwartet.');
        } catch (RuntimeException) {
        }
        $this->assertSame(['a', 'b'], $ran);

        // Ohne Schreibvorgang sofort; später sammelt sich nichts Altes an.
        WriteContext::afterCommit(function () use (&$ran): void {
            $ran[] = 'sofort';
        });
        $this->assertSame(['a', 'b', 'sofort'], $ran);
        $writer->batch(fn () => null);
        $this->assertSame(['a', 'b', 'sofort'], $ran);
    }
}
