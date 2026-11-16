<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Models\ScheduledTask;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Zeitplan einer Aufgabe: nächster Termin und Beschreibung in Worten. Gerechnet wird in der Zeitzone der
 * Anwendung (APP_TIMEZONE); über die Zeitumstellung hinweg gelten die Uhrzeiten der Wanduhr.
 */
final readonly class TaskSchedule
{
    public function __construct(
        public Frequency $frequency,
        public int $hour,
        public int $minute,
        public ?int $intervalHours = null,
        public ?int $weekday = null,
    ) {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new InvalidArgumentException("Ungültige Uhrzeit {$hour}:{$minute}.");
        }

        if ($frequency === Frequency::EveryHours && ! in_array($intervalHours, Frequency::INTERVALS, true)) {
            throw new InvalidArgumentException('Ungültiges Intervall in Stunden.');
        }

        if ($frequency === Frequency::Weekly && ($weekday === null || $weekday < 1 || $weekday > 7)) {
            throw new InvalidArgumentException('Ungültiger Wochentag.');
        }
    }

    public static function for(ScheduledTask $task): self
    {
        [$hour, $minute] = array_map('intval', explode(':', $task->at_time ?: '00:00') + [0, 0]);

        return new self(Frequency::from($task->frequency), $hour, $minute, $task->interval_hours, $task->weekday);
    }

    /** Erster Termin nach $after (verpasste Termine werden nicht einzeln nachgeholt). */
    public function next(CarbonInterface $after): CarbonImmutable
    {
        $timezone = (string) config('app.timezone');
        $after = CarbonImmutable::instance($after)->setTimezone($timezone);
        $day = $after->startOfDay();

        // Höchstens 8 Tage voraus (wöchentlich); innerhalb eines Tages die Termine dieses Tages.
        for ($i = 0; $i <= 8; $i++, $day = $day->addDay()) {
            foreach ($this->slots($day) as $slot) {
                if ($slot->greaterThan($after)) {
                    return $slot;
                }
            }
        }

        throw new InvalidArgumentException('Kein Termin gefunden.');
    }

    public function describe(): string
    {
        $time = sprintf('%02d:%02d', $this->hour, $this->minute);

        return match ($this->frequency) {
            Frequency::Hourly => __('tasks.describe.hourly', ['minute' => sprintf('%02d', $this->minute)]),
            Frequency::EveryHours => __('tasks.describe.every_hours', ['hours' => $this->intervalHours, 'time' => $time]),
            Frequency::Daily => __('tasks.describe.daily', ['time' => $time]),
            Frequency::Weekdays => __('tasks.describe.weekdays', ['time' => $time]),
            Frequency::Weekly => __('tasks.describe.weekly', ['day' => self::weekdayName((int) $this->weekday), 'time' => $time]),
        };
    }

    /** Länge eines Intervalls in Stunden (für „veraltet“-Schwellen). */
    public function intervalHours(): int
    {
        return match ($this->frequency) {
            Frequency::Hourly => 1,
            Frequency::EveryHours => (int) $this->intervalHours,
            Frequency::Daily => 24,
            Frequency::Weekdays => 72,
            Frequency::Weekly => 168,
        };
    }

    public static function weekdayName(int $isoDay): string
    {
        return (string) CarbonImmutable::create(2024, 1, $isoDay)->locale(app()->getLocale())->isoFormat('dddd');
    }

    /** @return array<int, string> ISO-Wochentag (1 = Montag) => Name */
    public static function weekdayOptions(): array
    {
        $days = [];

        foreach (range(1, 7) as $day) {
            $days[$day] = self::weekdayName($day);
        }

        return $days;
    }

    /** @return list<CarbonImmutable> Termine eines Tages, aufsteigend. */
    private function slots(CarbonImmutable $day): array
    {
        $at = fn (int $hour): CarbonImmutable => $day->setTime($hour, $this->minute);

        $slots = match ($this->frequency) {
            Frequency::Hourly => array_map($at, range(0, 23)),
            Frequency::EveryHours => array_map($at, array_values(array_filter(
                range(0, 23),
                fn (int $hour): bool => ($hour - $this->hour + 24) % (int) $this->intervalHours === 0,
            ))),
            Frequency::Daily => [$at($this->hour)],
            Frequency::Weekdays => $day->isoWeekday() <= 5 ? [$at($this->hour)] : [],
            Frequency::Weekly => $day->isoWeekday() === $this->weekday ? [$at($this->hour)] : [],
        };

        // Zeitumstellung: Eine fehlende Uhrzeit (z. B. 02:30 im März) verschiebt Carbon nach vorn; doppelte entfallen.
        usort($slots, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return $slots;
    }
}
