<?php

declare(strict_types=1);

namespace Tests\Feature\Work;

use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Models\WorkItem;
use App\Tasks\TaskRunner;
use App\Tasks\Types\WorkPruneTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** System-Aufgabe „Arbeiten aufräumen“ (Fundament 2, F3a). */
class WorkPruneTaskTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    public function test_expired_results_old_work_and_orphaned_folders_are_removed(): void
    {
        app()->setLocale('de');
        $this->seedAdminStructure();
        Storage::fake('local');
        $disk = Storage::disk('local');
        $make = function (array $values) use ($disk): WorkItem {
            $item = WorkItem::query()->create(['type' => 'recompute', ...$values]);
            $disk->put($item->directory().'/ergebnis.txt', 'x');

            return $item;
        };

        $fresh = $make(['status' => WorkItem::DONE, 'expires_at' => now()->addHour(), 'result' => ['file' => 'ergebnis.txt']]);
        $expired = $make(['status' => WorkItem::DONE, 'expires_at' => now()->subHour(), 'result' => ['file' => 'ergebnis.txt']]);
        $old = $make(['status' => WorkItem::FAILED]);
        $old->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();
        $oldButOpen = $make(['status' => WorkItem::RUNNING]);
        $oldButOpen->forceFill(['updated_at' => now()->subDays(31)])->saveQuietly();
        $disk->put('work/'.Str::uuid().'/rest.txt', 'x');

        $task = ScheduledTask::query()->where('type', WorkPruneTask::KEY)->where('is_system', true)->sole();
        $this->assertSame(['hourly', true], [$task->frequency, $task->is_active]);
        $run = app(TaskRunner::class)->run($task, TaskRun::MANUAL);

        $this->assertSame(ScheduledTask::SUCCESS, $run->status);
        $this->assertSame('Arbeiten gelöscht: 1, Ergebnisordner gelöscht: 2.', $run->message);
        $this->assertNull($old->fresh());
        $this->assertNotNull($expired->fresh());
        $this->assertNull($expired->fresh()->resultFile());
        $this->assertNotNull($oldButOpen->fresh());
        $this->assertFalse($disk->exists($expired->directory()));
        $this->assertTrue($disk->exists($fresh->directory()));
        $this->assertTrue($disk->exists($oldButOpen->directory()));
        $this->assertCount(2, $disk->directories('work'));
    }
}
