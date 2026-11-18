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
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithAdmin;
use Tests\TestCase;
use ZipArchive;

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
        // Nie die echten Dateien aus Dateifeldern mitsichern.
        Storage::fake('local');
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

    public function test_backup_includes_the_files_as_zip_and_prune_treats_both_as_one(): void
    {
        Storage::disk('local')->put('files/belege/scan/a.png', 'bild');
        Storage::disk('local')->put('files/belege/scan/a.png.thumb.jpg', 'vorschau');
        Storage::disk('local')->put('files/belege/vertrag/b.pdf', 'pdf');
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
        $this->assertStringContainsString('Dateien: ', (string) $run->message);

        $zipFile = $this->storage.'/backups/'.$run->result['files'];
        $this->assertFileExists($zipFile);
        $zip = new ZipArchive;
        $zip->open($zipFile);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        $this->assertSame(['files/belege/scan/a.png', 'files/belege/vertrag/b.pdf'], $names, 'Ohne Vorschaubilder, Pfade wie im Speicher.');
        $this->assertSame(DatabaseBackup::setName(basename($zipFile)), DatabaseBackup::setName((string) $run->result['file']));

        // Ohne Dateien abschaltbar; mit S3 nur ein Hinweis.
        $task = ScheduledTask::query()->where('type', 'db_backup')->sole();
        $task->update(['parameters' => ['files' => false]]);
        $this->assertArrayNotHasKey('files', $this->runSystemTask('db_backup')->result);
        $task->update(['parameters' => ['files' => true]]);
        config(['filesystems.disks.s3' => ['driver' => 's3'], 'dynamic_data.files.disk' => 's3']);
        $this->assertStringContainsString('nicht auf diesem Server', (string) $this->runSystemTask('db_backup')->message);

        // Aufräumen: Sicherung und ZIP zählen als eine Sicherung.
        foreach (File::files($this->storage.'/backups') as $file) {
            touch($file->getPathname(), now()->subDays(30)->getTimestamp());
        }
        File::put($this->storage.'/backups/neu.sql', 'x');
        File::put($this->storage.'/backups/neu'.DatabaseBackup::FILES_SUFFIX, 'x');
        $prune = ScheduledTask::query()->where('type', 'backup_prune')->sole();
        $prune->update(['parameters' => ['days' => 14, 'keep' => 1]]);
        $run = app(TaskRunner::class)->run($prune, TaskRun::MANUAL);
        $this->assertSame('3 Sicherungen gelöscht.', $run->message, 'Eine Sicherung mit ZIP zählt einmal.');
        $remaining = array_map(static fn (\SplFileInfo $file): string => $file->getFilename(), File::files($this->storage.'/backups'));
        sort($remaining);
        $this->assertSame(['neu.sql', 'neu'.DatabaseBackup::FILES_SUFFIX], $remaining, 'Die neueste Sicherung bleibt samt ZIP.');
    }
}
