<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Filament\Widgets\TasksStatusWidget;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** Dashboard-Widget „Geplante Aufgaben“: Ampel und letzte Läufe, nur mit widgets.tasks und tasks.view. */
class TasksStatusWidgetTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
    }

    public function test_permissions(): void
    {
        $this->actingAs($this->userWithPermissions(['widgets.tasks']));
        $this->assertFalse(TasksStatusWidget::canView());
        $this->actingAs($this->userWithPermissions(['tasks.view']));
        $this->assertFalse(TasksStatusWidget::canView());
        $this->actingAs($this->userWithPermissions(['widgets.tasks', 'tasks.view']));
        $this->assertTrue(TasksStatusWidget::canView());
    }

    public function test_traffic_light_and_latest_runs(): void
    {
        $this->actingAs($this->superAdmin());
        $prune = ScheduledTask::query()->where('type', 'task_runs_prune')->sole();

        // Scheduler lief nie.
        Livewire::test(TasksStatusWidget::class)
            ->assertSeeHtml('data-tasks-status="scheduler"')
            ->assertSee('Noch keine Läufe.');

        app(TaskRunner::class)->runDue();
        app(TaskRunner::class)->run($prune, TaskRun::MANUAL);
        Livewire::test(TasksStatusWidget::class)
            ->assertSeeHtml('data-tasks-status="ok"')
            ->assertSee('Laufprotokoll bereinigen')
            ->assertSee('Erfolgreich');

        $prune->forceFill(['consecutive_failures' => 2, 'last_status' => ScheduledTask::FAILED])->save();
        Livewire::test(TasksStatusWidget::class)
            ->assertSeeHtml('data-tasks-status="failing"')
            ->assertSee('Fehlgeschlagen: Laufprotokoll bereinigen');

        Cache::forget('tasks:heartbeat');
        Livewire::test(TasksStatusWidget::class)->assertSeeHtml('data-tasks-status="scheduler"');
    }
}
