<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Database\DatabaseBackup;
use App\Tasks\TaskResult;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ForschungDemoSeeder;
use Database\Seeders\LagerDemoSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;

/**
 * System-Aufgabe für öffentliche Demos: setzt die Daten der gewählten Demos auf den Auslieferungsstand zurück (nur
 * Daten, siehe SeedsDemoTables::resetDemoData()). Zerstörerisch – läuft nur mit DEMO_RESET=true in der .env, sonst
 * wird sie übersprungen; vorher wird auf Wunsch gesichert.
 */
final readonly class DemoResetTask implements TaskType
{
    /** @var array<string, class-string> Kennung => Seeder */
    public const array DEMOS = [
        'shop' => DemoDataSeeder::class,
        'lager' => LagerDemoSeeder::class,
        'forschung' => ForschungDemoSeeder::class,
    ];

    public function __construct(private DatabaseBackup $backup) {}

    public function key(): string
    {
        return 'demo_reset';
    }

    public function label(): string
    {
        return __('tasks.types.demo_reset');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [
            CheckboxList::make('demos')
                ->label(__('tasks.fields.demos'))
                ->helperText(__('tasks.fields.demos_help'))
                ->options(collect(array_keys(self::DEMOS))->mapWithKeys(static fn (string $key): array => [$key => __("tasks.demo_reset.demos.{$key}")])->all())
                ->default(array_keys(self::DEMOS))
                ->required(),
            Toggle::make('backup')
                ->label(__('tasks.fields.demo_backup'))
                ->default(true),
        ];
    }

    public function describe(array $parameters): string
    {
        return implode(', ', array_map(static fn (string $key): string => __("tasks.demo_reset.demos.{$key}"), $this->demos($parameters)));
    }

    public function run(array $parameters): TaskResult
    {
        if (! (bool) config('saft.demo_reset')) {
            throw new TaskSkipped(__('tasks.demo_reset.disabled'));
        }

        $seeders = [];

        foreach ($this->demos($parameters) as $key) {
            $seeder = app(self::DEMOS[$key]);

            if ($seeder->demoInstalled()) {
                $seeders[$key] = $seeder;
            }
        }

        if ($seeders === []) {
            throw new TaskSkipped(__('tasks.demo_reset.nothing_installed'));
        }

        $backup = ($parameters['backup'] ?? true) ? basename($this->backup->create()) : null;
        $records = [];

        foreach ($seeders as $key => $seeder) {
            $records[$key] = $seeder->resetDemoData();
        }

        $message = __('tasks.results.demo_reset', [
            'demos' => implode(', ', array_map(static fn (string $key, int $count): string => __("tasks.demo_reset.demos.{$key}")." ({$count})", array_keys($records), $records)),
        ]);

        return new TaskResult($backup === null ? $message : $message.' '.__('tasks.results.demo_reset_backup', ['file' => $backup]), ['records' => $records, 'backup' => $backup]);
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    private function demos(array $parameters): array
    {
        $chosen = $parameters['demos'] ?? array_keys(self::DEMOS);

        return array_values(array_intersect(array_keys(self::DEMOS), is_array($chosen) ? $chosen : []));
    }
}
