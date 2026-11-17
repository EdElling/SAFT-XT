<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\Models\DynamicRecord;
use App\Models\ScheduledTask;
use App\Models\TaskRun;
use App\Tasks\TaskRunner;
use App\Tasks\TaskSkipped;
use App\Tasks\Types\DemoResetTask;
use Database\Seeders\ForschungDemoSeeder;
use Database\Seeders\LagerDemoSeeder;

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

    public function test_skips_when_no_chosen_demo_is_loaded(): void
    {
        config(['saft.demo_reset' => true]);

        $this->expectException(TaskSkipped::class);
        app(DemoResetTask::class)->run(['demos' => ['shop', 'forschung'], 'backup' => false]);
    }
}
