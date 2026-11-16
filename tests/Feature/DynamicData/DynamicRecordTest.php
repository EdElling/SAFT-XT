<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Models\Activity;
use App\Models\DynamicRecord;
use App\Models\User;

class DynamicRecordTest extends DynamicDataTestCase
{
    public function test_crud_with_casts_creator_and_soft_deletes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $model = DynamicRecord::forTable($this->projects());

        $record = $model->create(['titel' => 'Alpha', 'budget' => '1234.5', 'anzahl' => '7', 'aktiv' => 1, 'start' => '2026-01-15', 'status' => 'offen']);
        $loaded = DynamicRecord::forTableName('projekte')->newQuery()->findOrFail($record->id);

        $this->assertSame('Alpha', $loaded->titel);
        $this->assertSame('1234.50', $loaded->budget);
        $this->assertSame(7, $loaded->anzahl);
        $this->assertTrue($loaded->aktiv);
        $this->assertSame('2026-01-15', $loaded->start->toDateString());
        $this->assertSame($user->id, $loaded->created_by);
        $this->assertSame($user->id, $loaded->updated_by);

        $loaded->delete();
        $this->assertNull(DynamicRecord::forTableName('projekte')->newQuery()->find($record->id));
        $this->assertNotNull(DynamicRecord::forTableName('projekte')->newQuery()->withTrashed()->find($record->id));
    }

    public function test_only_defined_fields_are_mass_assignable(): void
    {
        $record = DynamicRecord::forTable($this->projects())->create(['titel' => 'A', 'created_by' => 999, 'id' => 12345]);

        $this->assertNotSame(12345, $record->id);
        $this->assertNull($record->created_by);
    }

    public function test_changes_are_audited_per_table_with_causer_and_old_values(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $record = DynamicRecord::forTable($this->projects())->create(['titel' => 'Alpha', 'anzahl' => 1]);

        $record->update(['titel' => 'Beta']);

        $logs = Activity::query()->where('subject_type', 'dynamic:projekte')->where('subject_id', $record->id)->orderBy('id')->get();

        $this->assertSame(['created', 'updated'], $logs->pluck('event')->all());
        $this->assertSame('dynamic.projekte', $logs[1]->log_name);
        $this->assertSame($user->id, $logs[1]->causer_id);
        $this->assertSame(['titel' => 'Beta'], $logs[1]->attribute_changes['attributes']);
        $this->assertSame(['titel' => 'Alpha'], $logs[1]->attribute_changes['old']);
        $this->assertArrayNotHasKey('updated_by', $logs[1]->attribute_changes['attributes'], 'Systemspalten nicht protokollieren.');
    }

    public function test_activity_subject_resolves_to_dynamic_record_even_with_eager_loading(): void
    {
        $record = DynamicRecord::forTable($this->projects())->create(['titel' => 'Alpha']);

        $activity = Activity::query()->with('subject')->where('subject_type', 'dynamic:projekte')->firstOrFail();
        $this->assertInstanceOf(DynamicRecord::class, $activity->subject);
        $this->assertSame('Alpha', $activity->subject->titel);

        $lazy = Activity::query()->where('subject_type', 'dynamic:projekte')->firstOrFail();
        $this->assertSame($record->id, $lazy->subject->id);
    }

    public function test_activity_of_dropped_table_has_no_subject_instead_of_error(): void
    {
        $table = $this->projects();
        DynamicRecord::forTable($table)->create(['titel' => 'Alpha']);
        $this->service()->updateTable($table, ['is_active' => false]);
        $this->service()->dropTable($table->fresh(), 'projekte');

        $activity = Activity::query()->with('subject')->where('subject_type', 'dynamic:projekte')->firstOrFail();
        $this->assertNull($activity->subject);
        $this->assertNull(Activity::query()->where('subject_type', 'dynamic:projekte')->firstOrFail()->subject);
    }

    public function test_dynamic_records_never_touch_public_schema(): void
    {
        $record = DynamicRecord::forTable($this->projects());

        $this->assertSame('app_data', $record->getConnectionName());
        $this->assertSame($this->qualified('projekte'), $record->getTable());
        $this->assertSame('dynamic:projekte', $record->getMorphClass());
    }
}
