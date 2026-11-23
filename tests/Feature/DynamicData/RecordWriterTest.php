<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Write\RecordWriter;
use App\DynamicData\Write\WriteOptions;
use App\Models\Activity;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\User;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;
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

    public function test_logging_state_is_restored_after_a_silent_write(): void
    {
        $this->writer()->create($this->table(), ['titel' => 'leise'], WriteOptions::seed());
        $this->assertFalse(app(ActivityLogStatus::class)->disabled());
        $this->writer()->create($this->table(), ['titel' => 'normal']);
        $this->assertSame(1, Activity::query()->where('log_name', 'dynamic.projekte')->count());
    }
}
