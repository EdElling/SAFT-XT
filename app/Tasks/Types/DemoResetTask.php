<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Database\DatabaseBackup;
use App\Models\App;
use App\Tasks\TaskResult;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ForschungDemoSeeder;
use Database\Seeders\GitarrenshopDemoSeeder;
use Database\Seeders\LagerDemoSeeder;
use Database\Seeders\VertriebDemoSeeder;
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
        // Nach dem Lager: Ihre Anfragepositionen verweisen auf Lager-Artikel.
        'vertrieb' => VertriebDemoSeeder::class,
        'forschung' => ForschungDemoSeeder::class,
        'gitarrenshop' => GitarrenshopDemoSeeder::class,
    ];

    /** @var array<string, list<string>> Demo => Demos, deren Daten auf sie verweisen (über eine Schnittstelle) */
    public const array DEPENDENTS = [
        'lager' => ['vertrieb'],
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
                // Nur installierte Demo-Apps, mit dem Namen ihrer App (B-107); Gespeichertes anderer bleibt unsichtbar.
                ->options(fn (): array => $this->installed())
                ->default(fn (): array => array_keys($this->installed()))
                ->afterStateHydrated(fn (CheckboxList $component, mixed $state): mixed => $component->state(array_values(array_intersect((array) $state, array_keys($this->installed())))))
                ->required(),
            Toggle::make('backup')
                ->label(__('tasks.fields.demo_backup'))
                ->default(true),
        ];
    }

    public function describe(array $parameters): string
    {
        return implode(', ', array_map(fn (string $key): string => $this->appLabel($key), $this->demos($parameters)));
    }

    /**
     * Installierte Demo-Apps (alle Tabellen vorhanden) als Schlüssel => Name der App.
     *
     * @return array<string, string>
     */
    private function installed(): array
    {
        $installed = [];

        foreach (self::DEMOS as $key => $class) {
            if (app($class)->demoInstalled()) {
                $installed[$key] = $this->appLabel($key);
            }
        }

        return $installed;
    }

    /** Name der App der Demo (erkannt am stabilen Schlüssel), sonst die feste Bezeichnung. */
    private function appLabel(string $key): string
    {
        $uuid = app(self::DEMOS[$key])->demoAppDefinition()['uuid'] ?? null;
        $app = $uuid === null ? null : App::query()->where('uuid', $uuid)->first();

        return $app !== null ? (string) $app->name : __("tasks.demo_reset.demos.{$key}");
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

        $backup = null;

        if ($parameters['backup'] ?? true) {
            $dump = $this->backup->create();
            // Die Dateien der Demo-Tabellen gehen beim Zurücksetzen verloren: mitsichern.
            $this->backup->backupFiles($dump);
            $backup = basename($dump);
        }

        // Wer auf eine zurückgesetzte Demo verweist, wird vorher geleert und danach mit zurückgesetzt – sonst blockieren
        // seine Verweise das Leeren (Fremdschlüssel). DEMOS legt die Reihenfolge fest: verwiesene Demos zuerst.
        foreach (array_keys($seeders) as $key) {
            foreach (self::DEPENDENTS[$key] ?? [] as $dependent) {
                $seeder = app(self::DEMOS[$dependent]);

                if ($seeder->demoInstalled()) {
                    $seeder->clearDemoData();
                    $seeders[$dependent] = $seeder;
                }
            }
        }

        $seeders = array_intersect_key(array_merge(array_fill_keys(array_keys(self::DEMOS), null), $seeders), $seeders);
        $records = [];

        foreach ($seeders as $key => $seeder) {
            $records[$key] = $seeder->resetDemoData();
        }

        $message = __('tasks.results.demo_reset', [
            'demos' => implode(', ', array_map(fn (string $key, int $count): string => $this->appLabel($key)." ({$count})", array_keys($records), $records)),
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
