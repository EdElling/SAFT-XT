<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;
use Illuminate\Support\Carbon;

/**
 * Lektionen erzeugen: je Kurs ein Termin pro Woche an seinem Wochentag, im gewählten Zeitraum (begrenzt auf das
 * Semester des Kurses), ohne Ferien und Feiertage (Tabelle Ferien, falls vorhanden). Termine, an denen der Kurs schon
 * eine Lektion hat, werden übersprungen – wiederholbar. Dauer aus der Kursart, Lehrperson des Kurses; den Stundensatz
 * übernimmt die App selbst. Alles in einer Transaktion, mit den Rechten der angemeldeten Person.
 */
final class LessonGenerator
{
    /** Wochentag => ISO-Nummer (Montag = 1) */
    private const array ISO = ['mo' => 1, 'di' => 2, 'mi' => 3, 'do' => 4, 'fr' => 5, 'sa' => 6, 'so' => 7];

    public function __construct(private readonly School $school) {}

    /**
     * Was erzeugt würde.
     *
     * @param  list<int>  $courses
     * @return array{lessons: list<array{course: int, date: string, minutes: int, teacher: int}>, courses: int, holidays: int, existing: int, unscheduled: list<string>}
     */
    public function plan(array $courses, string $from, string $to, bool $skipHolidays = true): array
    {
        $holidays = $skipHolidays ? $this->school->holidays() : [];
        $result = ['lessons' => [], 'courses' => 0, 'holidays' => 0, 'existing' => 0, 'unscheduled' => []];
        $ranges = [];

        $records = Saft::table(School::COURSES)->withoutAuthorization()->query()->whereIn('id', $courses)->orderBy('bezeichnung')->get();

        foreach ($records as $course) {
            /** @var DynamicRecord $course */
            $day = $course->getAttribute('wochentag');

            if (! isset(self::ISO[$day])) {
                $result['unscheduled'][] = (string) $course->getAttribute('bezeichnung');

                continue;
            }

            $semester = (int) $course->getAttribute('semester');
            $range = $ranges[$semester] ??= $this->school->semesterRange($semester);
            $start = max($from, $range[0] ?? $from);
            $end = min($to, $range[1] ?? $to);
            $existing = Saft::table(School::LESSONS)->withoutAuthorization()->query()->where('kurs', $course->getKey())->pluck('datum')
                ->map(static fn (mixed $d): string => substr($d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d, 0, 10))->all();
            $minutes = $this->school->duration($course->getAttribute('kursart'));
            $count = 0;

            for ($date = Carbon::parse($start); $date->lte(Carbon::parse($end)); $date->addDay()) {
                if ($date->isoWeekday() !== self::ISO[$day]) {
                    continue;
                }

                $iso = $date->toDateString();

                if (in_array($iso, $existing, true)) {
                    $result['existing']++;
                } elseif (self::inHolidays($iso, $holidays)) {
                    $result['holidays']++;
                } else {
                    $result['lessons'][] = ['course' => (int) $course->getKey(), 'date' => $iso, 'minutes' => $minutes, 'teacher' => (int) $course->getAttribute('lehrperson')];
                    $count++;
                }
            }

            $result['courses'] += $count > 0 ? 1 : 0;
        }

        return $result;
    }

    /**
     * Erzeugen.
     *
     * @param  list<int>  $courses
     * @return array{lessons: int, courses: int, holidays: int, existing: int, unscheduled: list<string>}
     */
    public function run(array $courses, string $from, string $to, bool $skipHolidays = true): array
    {
        $lessons = Saft::table(School::LESSONS);

        return Saft::transaction(function () use ($courses, $from, $to, $skipHolidays, $lessons): array {
            $plan = $this->plan($courses, $from, $to, $skipHolidays);

            foreach ($plan['lessons'] as $lesson) {
                $lessons->create(['kurs' => $lesson['course'], 'datum' => $lesson['date'], 'dauer_minuten' => $lesson['minutes'], 'lehrperson' => $lesson['teacher']]);
            }

            return [...$plan, 'lessons' => count($plan['lessons'])];
        });
    }

    /** @return list<string> Tabellen, in denen der angemeldeten Person das Recht zum Anlegen fehlt */
    public function missingRights(): array
    {
        return Saft::table(School::LESSONS)->can('create') ? [] : [Saft::table(School::LESSONS)->label()];
    }

    /** @param  list<array{0: string, 1: string, 2: string}>  $holidays */
    private static function inHolidays(string $date, array $holidays): bool
    {
        foreach ($holidays as [$from, $to]) {
            if ($date >= $from && $date <= $to) {
                return true;
            }
        }

        return false;
    }
}
