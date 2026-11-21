<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;
use Illuminate\Support\Collection;

/**
 * Semester-Fortschreibung: Kurse eines Semesters ins nächste (oder ein anderes) kopieren. Kurse mit gleicher Bezeichnung
 * im Ziel werden übersprungen; aktive Anmeldungen kommen auf Wunsch als Warteliste mit (zur Bestätigung). Alles in
 * einer Transaktion – lehnt die Konfliktprüfung einen Kurs ab, wird nichts angelegt. Schreibt mit den Rechten der
 * angemeldeten Person (Anlegen in Kurse bzw. Anmeldungen), also auch mit Änderungsprotokoll.
 */
final class SemesterRollover
{
    public const string WAITING = 'warteliste';

    public const string ACTIVE = 'aktiv';

    public function __construct(private readonly School $school) {}

    /**
     * Was die Fortschreibung täte.
     *
     * @return array{courses: list<string>, skipped: list<string>, enrolments: int}
     */
    public function preview(int $source, int $target, bool $withEnrolments = true): array
    {
        $existing = $this->namesIn($target);
        $courses = [];
        $skipped = [];
        $enrolments = 0;

        foreach ($this->sourceCourses($source) as $course) {
            $name = (string) $course->getAttribute('bezeichnung');

            if (in_array(mb_strtolower(trim($name)), $existing, true)) {
                $skipped[] = $name;

                continue;
            }

            $courses[] = $name;
            $enrolments += $withEnrolments ? $this->activeEnrolments((int) $course->getKey())->count() : 0;
        }

        return ['courses' => $courses, 'skipped' => $skipped, 'enrolments' => $enrolments];
    }

    /** @return list<string> Tabellen, in denen der angemeldeten Person das Recht zum Anlegen fehlt */
    public function missingRights(bool $withEnrolments = true): array
    {
        $tables = $withEnrolments ? [School::COURSES, School::ENROLMENTS] : [School::COURSES];

        return array_values(array_map(
            static fn (string $t): string => Saft::table($t)->label(),
            array_filter($tables, static fn (string $t): bool => ! Saft::table($t)->can('create')),
        ));
    }

    /**
     * Fortschreiben.
     *
     * @return array{courses: int, skipped: list<string>, enrolments: int}
     */
    public function run(int $source, int $target, bool $keepSchedule = true, bool $withEnrolments = true, bool $newTariff = true): array
    {
        if ($source === $target) {
            throw new RolloverRejected(__('musikschule-planung::planung.rollover.same'));
        }

        $courses = Saft::table(School::COURSES);
        $enrolments = Saft::table(School::ENROLMENTS);

        return Saft::transaction(function () use ($source, $target, $keepSchedule, $withEnrolments, $newTariff, $courses, $enrolments): array {
            $existing = $this->namesIn($target);
            $result = ['courses' => 0, 'skipped' => [], 'enrolments' => 0];

            foreach ($this->sourceCourses($source) as $course) {
                $name = (string) $course->getAttribute('bezeichnung');

                if (in_array(mb_strtolower(trim($name)), $existing, true)) {
                    $result['skipped'][] = $name;

                    continue;
                }

                $copy = $courses->create([
                    'bezeichnung' => $name,
                    'fach' => $course->getAttribute('fach'),
                    'kursart' => $course->getAttribute('kursart'),
                    'lehrperson' => $course->getAttribute('lehrperson'),
                    'max_teilnehmer' => $course->getAttribute('max_teilnehmer'),
                    'semester' => $target,
                    'wochentag' => $keepSchedule ? $course->getAttribute('wochentag') : null,
                    'uhrzeit' => $keepSchedule ? $course->getAttribute('uhrzeit') : null,
                    'raum' => $keepSchedule ? $course->getAttribute('raum') : null,
                    // Leer lassen: „Wert übernehmen“ setzt den aktuellen Tarif der Kursart.
                    'tarif' => $newTariff ? null : $course->getAttribute('tarif'),
                ]);
                $result['courses']++;

                if (! $withEnrolments) {
                    continue;
                }

                foreach ($this->activeEnrolments((int) $course->getKey()) as $enrolment) {
                    $enrolments->create([
                        'kurs' => $copy->getKey(),
                        'schueler' => $enrolment->getAttribute('schueler'),
                        'status' => self::WAITING,
                        'datum' => now()->toDateString(),
                        'rabatt_prozent' => $enrolment->getAttribute('rabatt_prozent'),
                    ]);
                    $result['enrolments']++;
                }
            }

            return $result;
        });
    }

    /** @return Collection<int, DynamicRecord> */
    private function sourceCourses(int $source): Collection
    {
        return Saft::table(School::COURSES)->withoutAuthorization()->query()->where('semester', $source)->orderBy('id')->get();
    }

    /** @return Collection<int, DynamicRecord> */
    private function activeEnrolments(int $course): Collection
    {
        return Saft::table(School::ENROLMENTS)->withoutAuthorization()->query()
            ->where('kurs', $course)->where('status', self::ACTIVE)->orderBy('id')->get();
    }

    /** @return list<string> Bezeichnungen der Kurse im Ziel (klein, ohne Leerzeichen am Rand) */
    private function namesIn(int $semester): array
    {
        return Saft::table(School::COURSES)->withoutAuthorization()->query()->where('semester', $semester)->pluck('bezeichnung')
            ->map(static fn (mixed $n): string => mb_strtolower(trim((string) $n)))->all();
    }
}
