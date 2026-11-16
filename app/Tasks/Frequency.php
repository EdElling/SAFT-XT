<?php

declare(strict_types=1);

namespace App\Tasks;

/** Intervall einer geplanten Aufgabe. */
enum Frequency: string
{
    case Hourly = 'hourly';
    case EveryHours = 'every_hours';
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';

    /** Erlaubte N bei „alle N Stunden“ (Teiler von 24: jeden Tag dieselben Uhrzeiten). */
    public const array INTERVALS = [2, 3, 4, 6, 8, 12];

    public function label(): string
    {
        return __('tasks.frequency.'.$this->value);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $f): array => [$f->value => $f->label()])->all();
    }
}
