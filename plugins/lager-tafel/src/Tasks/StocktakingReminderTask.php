<?php

declare(strict_types=1);

namespace Saft\LagerTafel\Tasks;

use App\Models\DynamicRecord;
use App\Tasks\TaskResult;
use App\Tasks\TaskSkipped;
use App\Tasks\TaskType;
use Filament\Forms\Components\TextInput;
use Saft\LagerTafel\Warehouse;

/**
 * A.8 – Geplante Aufgabe „Inventur-Erinnerung“: findet Bestände mit Ware, deren letzte Zählung länger als die
 * eingestellte Zahl von Tagen zurückliegt (oder die nie gezählt wurden), und meldet sie im Laufprotokoll.
 */
final readonly class StocktakingReminderTask implements TaskType
{
    private const string T = 'lager-tafel::tafel.tasks.inventory.';

    public const int DEFAULT_DAYS = 90;

    /** Namen im Ergebnis, danach „und n weitere“. */
    private const int NAMES = 5;

    public function key(): string
    {
        return 'lager_tafel_inventory_reminder';
    }

    public function label(): string
    {
        return __(self::T.'label');
    }

    public function creatable(): bool
    {
        return true;
    }

    public function parameterFields(): array
    {
        return [
            TextInput::make('days')
                ->label(__(self::T.'days'))
                ->helperText(__(self::T.'days_help'))
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(self::DEFAULT_DAYS)
                ->required(),
        ];
    }

    public function describe(array $parameters): string
    {
        return __(self::T.'describe', ['days' => $this->days($parameters)]);
    }

    public function run(array $parameters): TaskResult
    {
        $warehouse = Warehouse::system();

        if (! $warehouse->available()) {
            throw new TaskSkipped(__('lager-tafel::tafel.tasks.unavailable', ['missing' => implode('; ', $warehouse->missing())]));
        }

        if (! $warehouse->hasField(Warehouse::STOCKS, 'letzte_zaehlung')) {
            throw new TaskSkipped(__(self::T.'no_field'));
        }

        $days = $this->days($parameters);
        $due = $warehouse->stocksNotCountedSince(now()->subDays($days)->toDateString());
        $articles = $warehouse->articles();
        $locations = $warehouse->locations();
        $names = array_map(static fn (DynamicRecord $stock): string => __(self::T.'item', [
            'article' => $articles[(int) $stock->getAttribute('artikel_id')] ?? '?',
            'location' => $locations[(int) $stock->getAttribute('lagerort_id')] ?? '?',
        ]), $due);

        if ($names === []) {
            return new TaskResult(__(self::T.'none', ['days' => $days]), ['count' => 0]);
        }

        $list = implode(', ', array_slice($names, 0, self::NAMES));

        return new TaskResult(
            trans_choice(self::T.'found', count($names), [
                'count' => count($names),
                'days' => $days,
                'list' => count($names) > self::NAMES ? __(self::T.'more', ['list' => $list, 'count' => count($names) - self::NAMES]) : $list,
            ]),
            ['count' => count($names)],
        );
    }

    /** @param  array<string, mixed>  $parameters  Werte aus dem Formular kommen als Text */
    private function days(array $parameters): int
    {
        $days = $parameters['days'] ?? self::DEFAULT_DAYS;

        return is_numeric($days) ? max(1, (int) $days) : self::DEFAULT_DAYS;
    }
}
