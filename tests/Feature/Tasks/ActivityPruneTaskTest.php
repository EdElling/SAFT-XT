<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Models\Activity;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use App\Tasks\Types\ActivityPruneTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** System-Aufgabe „Änderungsprotokoll kürzen“: Fristen je Protokollname, ausgeliefert ausgeschaltet. */
class ActivityPruneTaskTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
    }

    private function entry(string $log, int $daysAgo, string $description): Activity
    {
        $activity = new Activity(['log_name' => $log, 'description' => $description, 'properties' => []]);
        $activity->created_at = now()->subDays($daysAgo);
        $activity->updated_at = $activity->created_at;
        $activity->save();

        return $activity;
    }

    public function test_ships_switched_off_with_defaults(): void
    {
        $task = ScheduledTask::query()->where('type', 'activity_prune')->where('is_system', true)->sole();

        $this->assertFalse($task->is_active);
        $this->assertSame(['days' => 365, 'auth_days' => 90], $task->parameterValues());
        $this->assertSame('Einträge älter als 365 Tage, Anmeldungen älter als 90 Tage', $task->description());
    }

    public function test_deletes_by_log_name_and_age(): void
    {
        Activity::query()->delete();
        $this->entry('data', 400, 'alt');
        $this->entry('data', 300, 'behalten');
        $this->entry('auth', 100, 'anmeldung alt');
        $this->entry('auth', 80, 'anmeldung behalten');
        $this->entry('default', 400, 'ohne eigenen namen alt');

        $task = ScheduledTask::query()->where('type', 'activity_prune')->where('is_system', true)->sole();
        $run = app(TaskRunner::class)->run($task, TaskRun::MANUAL);

        $this->assertSame(ScheduledTask::SUCCESS, $run->status, (string) $run->message);
        $this->assertSame('3 Einträge gelöscht, davon 1 Anmeldungen.', $run->message);
        $this->assertEqualsCanonicalizing(['behalten', 'anmeldung behalten'],
            Activity::query()->whereIn('description', ['alt', 'behalten', 'anmeldung alt', 'anmeldung behalten', 'ohne eigenen namen alt'])->pluck('description')->all());
    }

    public function test_overdue_entries_are_detected_with_the_configured_days(): void
    {
        Activity::query()->delete();
        $this->entry('data', 100, 'x');

        $this->assertFalse(ActivityPruneTask::hasOverdueEntries(['days' => 365]));
        $this->assertTrue(ActivityPruneTask::hasOverdueEntries(['days' => 30]));
        $this->assertSame(365, ActivityPruneTask::days(['days' => 'unsinn']), 'Ungültige Werte fallen auf die Vorgabe zurück.');
    }
}
