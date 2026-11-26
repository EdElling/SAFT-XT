<?php

declare(strict_types=1);

namespace Tests\Feature\DynamicData;

use App\DynamicData\Numbering\NumberCondition;
use App\DynamicData\Numbering\NumberPattern;
use App\Models\Activity;
use App\Models\App;
use App\Models\CustomTable;
use App\Models\DynamicRecord;
use App\Services\DynamicSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nummernkreise (B-135): Mehrere Prozesse legen gleichzeitig an – keine Nummer doppelt, keine fehlt. Läuft ohne
 * Test-Transaktion (die anderen Prozesse sähen deren Daten nicht) und räumt danach selbst auf; in der CI auf
 * PostgreSQL, MySQL und MariaDB.
 */
class NumberSequenceConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const int PROCESSES = 4;

    private const int RECORDS = 15;

    /** Keine Test-Transaktion: die Hilfsprozesse müssen Tabelle und Daten sehen. */
    protected array $connectionsToTransact = [];

    private ?CustomTable $table = null;

    private int $lastActivity = 0;

    private ?App $testApp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastActivity = (int) Activity::query()->max('id');
    }

    protected function tearDown(): void
    {
        if ($this->table !== null) {
            $service = app(DynamicSchemaService::class);
            $service->updateTable($this->table, ['is_active' => false]);
            $service->dropTable($this->table->fresh(), $this->table->name);
        }

        $this->testApp?->delete();
        Activity::query()->where('id', '>', $this->lastActivity)->delete();

        parent::tearDown();
    }

    public function test_parallel_creation_assigns_each_number_exactly_once(): void
    {
        $this->runParallel('create', [['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [NumberPattern::PATTERN => 'P-{0000}']]]);
    }

    /** N3: Die Nummer entsteht beim Ändern („Vergeben, wenn“), gleichzeitig in mehreren Prozessen. */
    public function test_parallel_conditional_assignment_on_update(): void
    {
        $this->runParallel('update', [
            ['name' => 'fertig', 'label' => 'Fertig', 'type' => 'boolean'],
            ['name' => 'nummer', 'label' => 'Nummer', 'type' => 'number', 'options' => [NumberPattern::PATTERN => 'P-{0000}', NumberCondition::OPTION => 'fertig']],
        ]);
    }

    /** @param  list<array<string, mixed>>  $fields */
    private function runParallel(string $mode, array $fields): void
    {
        $this->testApp = App::query()->create(['name' => ['de' => 'Parallel'], 'prefix' => null, 'sort_order' => 99]);
        $this->table = app(DynamicSchemaService::class)->createTable(['name' => 'nk_parallel', 'label' => 'Parallel', 'app_id' => $this->testApp->id], [
            ['name' => 'titel', 'label' => 'Titel', 'type' => 'string'],
            ...$fields,
        ]);

        $script = base_path('tests/Support/scripts/create-numbered-records.php');
        $startAt = sprintf('%.3f', microtime(true) + 2.0);
        $processes = [];

        for ($i = 0; $i < self::PROCESSES; $i++) {
            $process = proc_open([PHP_BINARY, $script, 'nk_parallel', (string) self::RECORDS, $startAt, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            $this->assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        foreach ($processes as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            $exit = proc_close($process);
            $this->assertSame(0, $exit, "Hilfsprozess gescheitert: {$out}{$err}");
        }

        $numbers = DynamicRecord::forTable($this->table)->newQuery()->pluck('nummer')->sort()->values()->all();
        $expected = array_map(static fn (int $n): string => sprintf('P-%04d', $n), range(1, self::PROCESSES * self::RECORDS));

        $this->assertSame($expected, $numbers, 'Jede Nummer genau einmal, ohne Lücke.');
    }
}
