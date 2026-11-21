<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\RecordRejected;

/**
 * Listener: Ein Kurs wird nicht gespeichert, wenn im selben Semester am selben Wochentag zur selben Zeit dieselbe
 * Lehrperson oder derselbe Raum schon belegt ist (angrenzende Zeiten sind erlaubt). Geprüft wird beim Anlegen und
 * wenn sich Tag, Zeit, Kursart (Dauer), Lehrperson, Raum oder Semester ändern – gilt für Datenansicht, Import,
 * Schnittstelle und die Semester-Fortschreibung.
 */
final class CourseConflictGuard
{
    private const array WATCHED = ['wochentag', 'uhrzeit', 'kursart', 'lehrperson', 'raum', 'semester'];

    public function __construct(private readonly School $school) {}

    public function __invoke(RecordSaving $event): void
    {
        if (! $event->creating() && collect(self::WATCHED)->every(static fn (string $f): bool => $event->value($f) == $event->previous($f))) {
            return;
        }

        $course = $this->school->slot($event->record->getAttributes());

        if ($course['start'] === null) {
            return;
        }

        foreach ($this->school->courses($course['semester']) as $other) {
            if ($other['id'] === $course['id'] || ! School::overlaps($course, $other)) {
                continue;
            }

            if ($other['teacher'] === $course['teacher']) {
                $this->reject('teacher', $this->school->teachers()[$course['teacher']] ?? '?', $other);
            }

            if ($course['room'] !== null && $other['room'] === $course['room']) {
                $this->reject('room', $this->school->rooms()[$course['room']] ?? '?', $other);
            }
        }
    }

    /** @param  array{name: string, day: string|null, start: int|null, end: int|null}  $other */
    private function reject(string $kind, string $who, array $other): never
    {
        throw new RecordRejected(__('musikschule-planung::planung.conflict.'.$kind, [
            'name' => $who,
            'day' => __('musikschule-planung::planung.days.'.$other['day']),
            'from' => School::clock((int) $other['start']),
            'to' => School::clock((int) $other['end']),
            'course' => $other['name'],
        ]));
    }
}
