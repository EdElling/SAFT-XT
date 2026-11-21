<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Database\DatabaseBackup;
use App\DynamicData\Files\RecordFiles;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Models\RecordFile;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use App\Tasks\TaskSkipped;
use App\Tasks\Types\DemoResetTask;
use Database\Seeders\ForschungDemoSeeder;
use Database\Seeders\GitarrenshopDemoSeeder;
use Database\Seeders\LagerDemoSeeder;
use Database\Seeders\VertriebDemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/** System-Aufgabe „Demo-Daten zurücksetzen“: nur mit DEMO_RESET=true, nur Daten der gewählten Demos. */
class DemoResetTest extends DynamicDataTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('de');
        $this->travelTo('2026-11-14 12:00:00');
    }

    /** @return array<string, int> */
    private function counts(array $tables): array
    {
        return collect($tables)->mapWithKeys(static fn (string $table): array => [$table => DynamicRecord::forTableName($table)->newQuery()->withTrashed()->count()])->all();
    }

    public function test_is_skipped_without_permission_in_the_environment(): void
    {
        $this->seed(LagerDemoSeeder::class);
        DynamicRecord::forTableName('lager_orte')->newQuery()->firstOrFail()->update(['bezeichnung' => 'Geändert']);

        $task = ScheduledTask::query()->where('type', 'demo_reset')->sole();
        $this->assertFalse($task->is_active, 'Ausgeliefert ausgeschaltet.');
        $run = app(TaskRunner::class)->run($task, TaskRun::MANUAL);

        $this->assertSame(ScheduledTask::SKIPPED, $run->status);
        $this->assertStringContainsString('DEMO_RESET=true', (string) $run->message);
        $this->assertTrue(DynamicRecord::forTableName('lager_orte')->newQuery()->where('bezeichnung', 'Geändert')->exists(), 'Nichts gelöscht.');
    }

    public function test_resets_only_the_data_of_the_chosen_demos(): void
    {
        config(['saft.demo_reset' => true]);
        $this->seed(LagerDemoSeeder::class);
        $this->seed(ForschungDemoSeeder::class);
        $lager = app(LagerDemoSeeder::class)->demoTableNames();
        $research = app(ForschungDemoSeeder::class)->demoTableNames();
        $before = $this->counts($lager);
        $hammerBefore = DynamicRecord::forTableName('lager_artikel')->newQuery()->where('artikelnummer', 'A-1001')->sole();

        // Besucher der Demo ändern, löschen und ergänzen.
        $hammerBefore->update(['bezeichnung' => 'Umbenannt']);
        DynamicRecord::forTableName('lager_messprotokoll')->newQuery()->firstOrFail()->delete();
        DynamicRecord::forTableName('lager_orte')->create(['bezeichnung' => 'Neuer Ort']);
        $researchChanged = DynamicRecord::forTableName('forschung_organisationen')->newQuery()->firstOrFail();
        $researchChanged->update(['name' => 'Nicht zurückgesetzt']);
        $researchCounts = $this->counts($research);

        $result = app(DemoResetTask::class)->run(['demos' => ['lager'], 'backup' => false]);

        $this->assertSame($before, $this->counts($lager), 'Wieder genau der Auslieferungsstand.');
        $this->assertFalse(DynamicRecord::forTableName('lager_orte')->newQuery()->where('bezeichnung', 'Neuer Ort')->exists());
        $hammer = DynamicRecord::forTableName('lager_artikel')->newQuery()->where('artikelnummer', 'A-1001')->sole();
        $this->assertNotSame('Umbenannt', $hammer->bezeichnung);
        $this->assertSame([30, 20], [(int) $hammer->bestand, (int) $hammer->reserve], 'Berechnete Felder neu berechnet.');
        $this->assertSame(0, DynamicRecord::forTableName('lager_bewegungen')->newQuery()->whereNull('einkaufspreis')->count());
        $paper = DynamicRecord::forTableName('lager_artikelgruppen')->newQuery()->where('code', 'BUERO-PAP')->sole();
        $this->assertSame('BUERO', DynamicRecord::forTableName('lager_artikelgruppen')->newQuery()->findOrFail($paper->obergruppe_id)->code, 'Verweis auf die eigene Tabelle.');

        $this->assertSame($researchCounts, $this->counts($research), 'Andere Demo unberührt.');
        $this->assertSame('Nicht zurückgesetzt', $researchChanged->fresh()->name);
        $this->assertSame(array_sum($before), $result->data['records']['lager']);
        $this->assertNull($result->data['backup']);
    }

    public function test_resetting_the_lager_demo_also_resets_the_vertrieb_demo_that_links_to_its_items(): void
    {
        config(['saft.demo_reset' => true]);
        $this->seed(VertriebDemoSeeder::class);
        $sales = app(VertriebDemoSeeder::class)->demoTableNames();
        $before = $this->counts($sales);
        DynamicRecord::forTableName('vertrieb_kunden')->create(['kundennummer' => 'K-9999', 'name' => 'Besucher', 'land' => 'CH']);

        // Nur „Lager“ gewählt: Die Anfragepositionen verweisen auf Lager-Artikel und werden mit zurückgesetzt.
        $result = app(DemoResetTask::class)->run(['demos' => ['lager'], 'backup' => false]);

        $this->assertSame(['lager', 'vertrieb'], array_keys($result->data['records']));
        $this->assertSame($before, $this->counts($sales));
        $this->assertFalse(DynamicRecord::forTableName('vertrieb_kunden')->newQuery()->where('kundennummer', 'K-9999')->exists());
        $items = DynamicRecord::forTableName('lager_artikel')->newQuery()->pluck('id')->all();
        $this->assertSame([], DynamicRecord::forTableName('vertrieb_anfragepositionen')->newQuery()->whereNotIn('artikel_id', $items)->pluck('id')->all(), 'Positionen zeigen auf die neuen Artikel.');
        $this->assertGreaterThan(0, (float) DynamicRecord::forTableName('vertrieb_anfragen')->newQuery()->sum('summe'), 'Summen neu berechnet.');
    }

    public function test_gitarrenshop_reset_recreates_records_and_files(): void
    {
        config(['saft.demo_reset' => true]);
        Storage::fake('local');
        $this->seed(GitarrenshopDemoSeeder::class);
        $tables = app(GitarrenshopDemoSeeder::class)->demoTableNames();
        $before = $this->counts($tables);
        $files = RecordFile::query()->count();
        DynamicRecord::forTableName('gitarren_artikel')->newQuery()->where('inventarnummer', 'G-003')->sole()->update(['status' => 'verkauft']);

        $result = app(DemoResetTask::class)->run(['demos' => ['gitarrenshop'], 'backup' => false]);

        $this->assertSame($before, $this->counts($tables));
        $this->assertSame('an_lager', DynamicRecord::forTableName('gitarren_artikel')->newQuery()->where('inventarnummer', 'G-003')->sole()->status);
        $this->assertSame($files, RecordFile::query()->count(), 'Alte Dateien entfernt, neue angelegt.');
        $this->assertSame(array_sum($before), $result->data['records']['gitarrenshop']);
    }

    public function test_skips_when_no_chosen_demo_is_loaded(): void
    {
        config(['saft.demo_reset' => true]);

        $this->expectException(TaskSkipped::class);
        app(DemoResetTask::class)->run(['demos' => ['shop', 'forschung'], 'backup' => false]);
    }

    public function test_files_of_the_demo_tables_are_removed_and_backed_up(): void
    {
        config(['saft.demo_reset' => true]);
        Storage::fake('local');
        Process::fake(static function (PendingProcess $process) {
            foreach ((array) $process->command as $argument) {
                if (str_starts_with($argument, '--file=')) {
                    File::put(substr($argument, 7), 'dump');
                }
            }

            return Process::result(output: "-- dump\n");
        });
        $storage = sys_get_temp_dir().'/saft-demo-reset-'.uniqid();
        $this->app->useStoragePath($storage);

        $this->seed(LagerDemoSeeder::class);
        $orte = CustomTable::query()->where('name', 'lager_orte')->sole();
        $doku = $this->service()->addField($orte, ['name' => 'doku', 'label' => 'Doku', 'type' => 'file', 'options' => ['kinds' => ['any'], 'max_files' => 5]]);
        $file = app(RecordFiles::class)->store($orte->fresh(), $doku, UploadedFile::fake()->createWithContent('plan.txt', 'Lageplan'));
        DynamicRecord::forTableName('lager_orte')->newQuery()->firstOrFail()->update(['doku' => [$file->toReference()]]);

        $task = ScheduledTask::query()->where('type', 'demo_reset')->sole();
        $task->update(['parameters' => ['demos' => ['lager'], 'backup' => true]]);
        $run = app(TaskRunner::class)->run($task, TaskRun::MANUAL);

        $this->assertSame(ScheduledTask::SUCCESS, $run->status, (string) $run->message);
        $this->assertSame(0, RecordFile::query()->count());
        Storage::disk('local')->assertMissing($file->path);
        $this->assertCount(1, File::glob($storage.'/backups/*'.DatabaseBackup::FILES_SUFFIX), 'Die Dateien sind vorher gesichert.');
        File::deleteDirectory($storage);
    }
}
