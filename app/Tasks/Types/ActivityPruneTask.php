<?php

declare(strict_types=1);

namespace App\Tasks\Types;

use App\Models\Activity;
use App\Models\BulkChange;
use App\Tasks\TaskResult;
use App\Tasks\TaskType;
use Filament\Forms\Components\TextInput;

/**
 * System-Aufgabe: Einträge im Änderungsprotokoll endgültig löschen, die älter als die eingestellte Zahl von Tagen
 * sind (Vorgabe 365; Anmeldungen, Protokollname „auth“, 90). Ausgeliefert ausgeschaltet – das Dashboard weist auf
 * ältere Einträge hin (TasksStatusWidget). Aufbewahrung je Tabelle und ein Löschkonzept folgen in einem eigenen
 * Vorhaben (Fundament 2, F0, Architektur-Review 4.10).
 */
final readonly class ActivityPruneTask implements TaskType
{
    public const string KEY = 'activity_prune';

    public const int DEFAULT_DAYS = 365;

    public const int DEFAULT_AUTH_DAYS = 90;

    /** Protokollname der An- und Abmeldungen (siehe activity('auth') in App\Auth). */
    public const string AUTH_LOG = 'auth';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return __('tasks.types.activity_prune');
    }

    public function creatable(): bool
    {
        return false;
    }

    public function parameterFields(): array
    {
        return [
            TextInput::make('days')
                ->label(__('tasks.fields.activity_days'))
                ->helperText(__('tasks.fields.activity_days_help'))
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(self::DEFAULT_DAYS)
                ->required(),
            TextInput::make('auth_days')
                ->label(__('tasks.fields.auth_days'))
                ->helperText(__('tasks.fields.auth_days_help'))
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(self::DEFAULT_AUTH_DAYS)
                ->required(),
        ];
    }

    public function describe(array $parameters): string
    {
        return __('tasks.describe_type.activity_prune', ['days' => self::days($parameters), 'auth_days' => self::authDays($parameters)]);
    }

    public function run(array $parameters): TaskResult
    {
        $auth = Activity::query()->where('log_name', self::AUTH_LOG)
            ->where('created_at', '<', now()->subDays(self::authDays($parameters)))->delete();
        $other = Activity::query()->where(fn ($query) => $query->whereNull('log_name')->orWhere('log_name', '!=', self::AUTH_LOG))
            ->where('created_at', '<', now()->subDays(self::days($parameters)))->delete();
        // Köpfe der Sammelaktionen mit derselben Frist: Ohne Protokolleinträge ist eine Rücknahme nicht mehr möglich
        // (B-133 S3, BULK_EDIT.md §13 Frage 1).
        BulkChange::query()->where('created_at', '<', now()->subDays(self::days($parameters)))->delete();

        return new TaskResult(__('tasks.results.activity_prune', ['deleted' => $auth + $other, 'auth' => $auth]), ['deleted' => $auth + $other, 'auth' => $auth]);
    }

    /**
     * Gibt es Einträge, die älter als die eingestellte Frist sind? Für den Hinweis auf dem Dashboard, solange die
     * Aufgabe ausgeschaltet ist.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function hasOverdueEntries(array $parameters): bool
    {
        return Activity::query()->where('created_at', '<', now()->subDays(self::days($parameters)))->exists();
    }

    /** @param  array<string, mixed>  $parameters */
    public static function days(array $parameters): int
    {
        return self::positive($parameters['days'] ?? null, self::DEFAULT_DAYS);
    }

    /** @param  array<string, mixed>  $parameters */
    private static function authDays(array $parameters): int
    {
        return self::positive($parameters['auth_days'] ?? null, self::DEFAULT_AUTH_DAYS);
    }

    private static function positive(mixed $value, int $default): int
    {
        return is_numeric($value) ? max(1, (int) $value) : $default;
    }
}
