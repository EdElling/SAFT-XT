<?php

declare(strict_types=1);

namespace Tests\Feature\Tasks;

use App\Database\DatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;

/** System-Aufgaben „Datenbank sichern“ und „Sicherungen aufräumen“; Sicherungen in einem eigenen Ordner. */
class SystemTasksTest extends TestCase
{
    use InteractsWithAdmin;
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->seedAdminStructure();
        $this->storage = sys_get_temp_dir().'/saft-system-tasks-'.uniqid();
        File::ensureDirectoryExists($this->storage);
        $this->app->useStoragePath($this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    private function runSystemTask(string $type): TaskRun
    {
        return app(TaskRunner::class)->run(ScheduledTask::query()->where('type', $type)->where('is_system', true)->sole(), TaskRun::MANUAL);
    }

    public function test_backup_task_creates_a_backup_and_fails_with_the_reason(): void
    {
        // pg_dump schreibt selbst in --file, mysqldump/mariadb-dump liefern auf stdout.
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });

        $run = $this->runSystemTask('db_backup');

        $this->assertSame(ScheduledTask::SUCCESS, $run->status, (string) $run->message);
        $this->assertStringStartsWith('Sicherung '.config('database.connections.'.config('database.default').'.database').'_', (string) $run->message);
        $this->assertCount(1, File::files($this->storage.'/backups'));

        Process::fake(['*' => Process::result(errorOutput: 'Verbindung abgelehnt', exitCode: 1)]);
        $run = $this->runSystemTask('db_backup');

        $this->assertSame(ScheduledTask::FAILED, $run->status);
        $this->assertStringContainsString('Verbindung abgelehnt', (string) $run->message);
        $this->assertCount(1, File::files($this->storage.'/backups'), 'Keine halbe Datei.');
    }

    public function test_prune_keeps_the_newest_and_only_touches_backups(): void
    {
        $directory = $this->storage.'/backups';
        File::ensureDirectoryExists($directory);
        $files = ['neu.sql' => 1, 'alt1.dump' => 20, 'alt2.sql' => 21, 'alt3.sql' => 30, 'alt4.dump' => 40, 'notiz.txt' => 50];

        foreach ($files as $name => $days) {
            File::put($directory.'/'.$name, 'x');
            touch($directory.'/'.$name, now()->subDays($days)->getTimestamp());
        }

        $run = $this->runSystemTask('backup_prune');

        $this->assertSame(ScheduledTask::SUCCESS, $run->status, (string) $run->message);
        $this->assertSame('2 Sicherungen gelöscht.', $run->message);
        $remaining = array_map(static fn (\SplFileInfo $file): string => $file->getFilename(), File::files($directory));
        sort($remaining);
        $this->assertSame(['alt1.dump', 'alt2.sql', 'neu.sql', 'notiz.txt'], $remaining, 'Die 3 neuesten bleiben, auch wenn älter als 14 Tage.');

        $this->assertSame([], app(DatabaseBackup::class)->prune(1, 1, $this->storage.'/gibt-es-nicht'));
    }
}
