<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Tasks\Frequency;
use App\Tasks\TaskSchedule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/** Nächster Termin geplanter Aufgaben in der Zeitzone der Anwendung (Europe/Zurich). */
class TaskScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'Europe/Zurich']);
        app()->setLocale('de');
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, 'Europe/Zurich');
    }

    private function next(TaskSchedule $schedule, string $after): string
    {
        return $schedule->next($this->at($after))->format('Y-m-d H:i');
    }

    public function test_daily_and_hourly(): void
    {
        $daily = new TaskSchedule(Frequency::Daily, 6, 0);
        $this->assertSame('2026-11-14 06:00', $this->next($daily, '2026-11-14 05:00'));
        $this->assertSame('2026-11-15 06:00', $this->next($daily, '2026-11-14 06:00'), 'Genau zum Termin: der nächste.');

        $hourly = new TaskSchedule(Frequency::Hourly, 0, 15);
        $this->assertSame('2026-11-14 11:15', $this->next($hourly, '2026-11-14 10:20'));
        $this->assertSame('2026-11-15 00:15', $this->next($hourly, '2026-11-14 23:30'));
    }

    public function test_every_n_hours_from_a_start_time(): void
    {
        $schedule = new TaskSchedule(Frequency::EveryHours, 6, 0, 4);
        $this->assertSame('2026-11-14 10:00', $this->next($schedule, '2026-11-14 07:00'));
        $this->assertSame('2026-11-15 02:00', $this->next($schedule, '2026-11-14 22:30'), '06, 10, 14, 18, 22, 02 …');
        $this->assertSame('2026-11-14 02:00', $this->next($schedule, '2026-11-14 00:10'));
    }

    public function test_weekdays_and_weekly(): void
    {
        // 13.11.2026 ist ein Freitag.
        $weekdays = new TaskSchedule(Frequency::Weekdays, 6, 0);
        $this->assertSame('2026-11-16 06:00', $this->next($weekdays, '2026-11-13 07:00'));

        $weekly = new TaskSchedule(Frequency::Weekly, 18, 0, weekday: 3);
        $this->assertSame('2026-11-18 18:00', $this->next($weekly, '2026-11-13 07:00'));
        $this->assertSame('2026-11-25 18:00', $this->next($weekly, '2026-11-18 19:00'));
    }

    public function test_daylight_saving_time(): void
    {
        $daily = new TaskSchedule(Frequency::Daily, 2, 30);

        // 28.03.2027: 02:00 → 03:00, die Uhrzeit 02:30 gibt es nicht – der Lauf kommt um 03:30.
        $this->assertSame('2027-03-28 03:30', $this->next($daily, '2027-03-28 01:00'));
        $this->assertSame('2027-03-29 02:30', $this->next($daily, '2027-03-28 04:00'));

        // 31.10.2027: 03:00 → 02:00, 02:30 gibt es zweimal – ein Lauf, danach wieder am nächsten Tag.
        $first = $daily->next($this->at('2027-10-31 01:00'));
        $this->assertSame('02:30', $first->format('H:i'));
        $this->assertSame('2027-11-01 02:30', $daily->next($first)->format('Y-m-d H:i'));
    }

    public function test_description_and_validation(): void
    {
        $this->assertSame('täglich um 06:00', (new TaskSchedule(Frequency::Daily, 6, 0))->describe());
        $this->assertSame('alle 4 Stunden ab 06:00', (new TaskSchedule(Frequency::EveryHours, 6, 0, 4))->describe());
        $this->assertSame('Mittwoch um 18:00', (new TaskSchedule(Frequency::Weekly, 18, 0, weekday: 3))->describe());
        $this->assertSame(24, (new TaskSchedule(Frequency::Daily, 6, 0))->intervalHours());

        $this->expectException(InvalidArgumentException::class);
        new TaskSchedule(Frequency::EveryHours, 6, 0, 5);
    }
}
