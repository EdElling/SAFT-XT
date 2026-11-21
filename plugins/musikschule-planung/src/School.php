<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;
use Illuminate\Support\Collection;

/**
 * Zugriff auf die Tabellen der Musikschul-App (Demo „Musikschule“): Kurse mit Wochentag, Beginn und Dauer (aus der
 * Kursart), Überschneidungen. Gemeinsam für Stundenplan, Konfliktprüfung und Fortschreibung. Liest ohne Rechteprüfung –
 * wer was sehen bzw. ändern darf, prüfen Seite und Aktionen selbst.
 */
final class School
{
    public const string COURSES = 'musikschule_kurse';

    public const string COURSE_TYPES = 'musikschule_kursarten';

    public const string SEMESTERS = 'musikschule_semester';

    public const string TEACHERS = 'musikschule_lehrpersonen';

    public const string ROOMS = 'musikschule_raeume';

    public const string SUBJECTS = 'musikschule_faecher';

    public const string STUDENTS = 'musikschule_schueler';

    public const string ENROLMENTS = 'musikschule_anmeldungen';

    public const string LESSONS = 'musikschule_lektionen';

    public const string ATTENDANCE = 'musikschule_praesenzen';

    /** Ferien und Feiertage (seit App 1.1); ohne die Tabelle gibt es keine Ferienlücken. */
    public const string HOLIDAYS = 'musikschule_ferien';

    /** Wochentage in der Reihenfolge der Woche (gespeicherte Werte des Auswahlfelds „wochentag“). */
    public const array DAYS = ['mo', 'di', 'mi', 'do', 'fr', 'sa', 'so'];

    /** Dauer, wenn die Kursart keine nennt. */
    public const int DEFAULT_MINUTES = 45;

    /** @var array<string, list<string>> Tabellen und Felder, ohne die das Plugin nicht arbeiten kann */
    public const array REQUIRED = [
        self::COURSES => ['bezeichnung', 'wochentag', 'uhrzeit', 'max_teilnehmer', 'fach', 'kursart', 'lehrperson', 'raum', 'semester', 'tarif'],
        self::COURSE_TYPES => ['bezeichnung', 'dauer_minuten'],
        self::SEMESTERS => ['bezeichnung', 'beginn', 'ende'],
        self::TEACHERS => ['vorname', 'nachname'],
        self::ROOMS => ['bezeichnung', 'plaetze'],
        self::SUBJECTS => ['bezeichnung'],
        self::STUDENTS => ['vorname', 'nachname'],
        self::ENROLMENTS => ['datum', 'status', 'rabatt_prozent', 'schueler', 'kurs'],
        self::LESSONS => ['datum', 'dauer_minuten', 'thema', 'kurs', 'lehrperson'],
        self::ATTENDANCE => ['status', 'bemerkung', 'lektion', 'schueler'],
    ];

    /** @var array<int, int>|null Kursart => Dauer in Minuten */
    private ?array $durations = null;

    /** Sind alle nötigen Tabellen da? */
    public function available(): bool
    {
        return $this->missing() === [];
    }

    /** @return list<string> fehlende Tabellen bzw. Felder („tabelle.feld“) */
    public function missing(): array
    {
        $missing = [];

        foreach (self::REQUIRED as $table => $fields) {
            if (! Saft::hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            foreach (array_diff($fields, Saft::table($table)->fieldNames()) as $field) {
                $missing[] = $table.'.'.$field;
            }
        }

        return $missing;
    }

    /** „HH:MM“ (auch „H:MM“, „HH.MM“) in Minuten seit Mitternacht; null wenn nicht lesbar. */
    public static function minutes(mixed $time): ?int
    {
        if (! is_string($time) || preg_match('/^\s*(\d{1,2})[:.](\d{2})/', $time, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /** Minuten seit Mitternacht als „HH:MM“. */
    public static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Dauer einer Kursart in Minuten. */
    public function duration(mixed $courseType): int
    {
        $this->durations ??= Saft::table(self::COURSE_TYPES)->withoutAuthorization()->query()
            ->pluck('dauer_minuten', 'id')->map(static fn (mixed $m): int => (int) $m)->all();

        $minutes = $this->durations[(int) $courseType] ?? 0;

        return $minutes > 0 ? $minutes : self::DEFAULT_MINUTES;
    }

    /**
     * Kurse eines Semesters mit Zeitfenster; Kurse ohne Wochentag oder lesbare Uhrzeit haben start = null.
     *
     * @return Collection<int, array{id: int, name: string, day: string|null, start: int|null, end: int|null, teacher: int, room: int|null, subject: int, semester: int, max: int, record: DynamicRecord}>
     */
    public function courses(int $semester): Collection
    {
        return Saft::table(self::COURSES)->withoutAuthorization()->query()->where('semester', $semester)->orderBy('id')->get()
            ->map(fn (DynamicRecord $course): array => $this->slot($course->getAttributes(), $course));
    }

    /**
     * Zeitfenster aus Feldwerten eines Kurses (auch eines noch nicht gespeicherten).
     *
     * @param  array<string, mixed>  $values
     * @return array{id: int, name: string, day: string|null, start: int|null, end: int|null, teacher: int, room: int|null, subject: int, semester: int, max: int, record: DynamicRecord|null}
     */
    public function slot(array $values, ?DynamicRecord $record = null): array
    {
        $day = in_array($values['wochentag'] ?? null, self::DAYS, true) ? (string) $values['wochentag'] : null;
        $start = $day === null ? null : self::minutes($values['uhrzeit'] ?? null);

        return [
            'id' => (int) ($values['id'] ?? 0),
            'name' => (string) ($values['bezeichnung'] ?? ''),
            'day' => $start === null ? null : $day,
            'start' => $start,
            'end' => $start === null ? null : $start + $this->duration($values['kursart'] ?? null),
            'teacher' => (int) ($values['lehrperson'] ?? 0),
            'room' => filled($values['raum'] ?? null) ? (int) $values['raum'] : null,
            'subject' => (int) ($values['fach'] ?? 0),
            'semester' => (int) ($values['semester'] ?? 0),
            'max' => (int) ($values['max_teilnehmer'] ?? 0),
            'record' => $record,
        ];
    }

    /**
     * Überschneiden sich zwei Kurse (gleicher Tag, Zeiten überlappen; angrenzend ist erlaubt)?
     *
     * @param  array{day: string|null, start: int|null, end: int|null}  $a
     * @param  array{day: string|null, start: int|null, end: int|null}  $b
     */
    public static function overlaps(array $a, array $b): bool
    {
        return $a['day'] !== null && $a['day'] === $b['day'] && $a['start'] < $b['end'] && $b['start'] < $a['end'];
    }

    /** @return array<int, string> Lehrpersonen: id => „Vorname Nachname“, nach Nachname */
    public function teachers(): array
    {
        return $this->people(self::TEACHERS);
    }

    /** @return array<int, string> Schülerinnen und Schüler: id => „Vorname Nachname“, nach Nachname */
    public function students(): array
    {
        return $this->people(self::STUDENTS);
    }

    /** @return array<int, string> Räume: id => Bezeichnung */
    public function rooms(): array
    {
        return $this->labels(self::ROOMS);
    }

    /** @return array<int, string> Fächer: id => Bezeichnung */
    public function subjects(): array
    {
        return $this->labels(self::SUBJECTS);
    }

    /** @return array<int, string> Semester: id => Bezeichnung, nach Beginn */
    public function semesters(): array
    {
        return Saft::table(self::SEMESTERS)->withoutAuthorization()->query()->orderBy('beginn')->orderBy('id')->get()
            ->mapWithKeys(static fn (DynamicRecord $s): array => [(int) $s->getKey() => (string) $s->getAttribute('bezeichnung')])->all();
    }

    /** Gibt es die Tabelle Ferien (App ab 1.1)? */
    public function hasHolidays(): bool
    {
        return Saft::hasTable(self::HOLIDAYS) && array_diff(['von', 'bis'], Saft::table(self::HOLIDAYS)->fieldNames()) === [];
    }

    /** @return list<array{0: string, 1: string, 2: string}> Ferien: von, bis (Y-m-d), Bezeichnung; leer ohne die Tabelle */
    public function holidays(): array
    {
        if (! $this->hasHolidays()) {
            return [];
        }

        return Saft::table(self::HOLIDAYS)->withoutAuthorization()->query()->orderBy('von')->get()
            ->map(static fn (DynamicRecord $h): array => [self::date($h, 'von'), self::date($h, 'bis'), (string) $h->getAttribute('bezeichnung')])
            ->filter(static fn (array $h): bool => $h[0] !== '' && $h[1] !== '')->values()->all();
    }

    /** Beginn und Ende eines Semesters (Y-m-d), null wenn es das Semester nicht gibt. */
    public function semesterRange(int $semester): ?array
    {
        $record = Saft::table(self::SEMESTERS)->withoutAuthorization()->find($semester);

        return $record === null ? null : [self::date($record, 'beginn'), self::date($record, 'ende')];
    }

    /** Das laufende Semester (heute zwischen Beginn und Ende), sonst das zuletzt begonnene, sonst das erste. */
    public function currentSemester(): ?int
    {
        $today = now()->toDateString();
        $all = Saft::table(self::SEMESTERS)->withoutAuthorization()->query()->orderBy('beginn')->orderBy('id')->get();
        $running = $all->first(static fn (DynamicRecord $s): bool => self::date($s, 'beginn') <= $today && self::date($s, 'ende') >= $today);
        $started = $all->filter(static fn (DynamicRecord $s): bool => self::date($s, 'beginn') <= $today)->last();
        $semester = $running ?? $started ?? $all->first();

        return $semester === null ? null : (int) $semester->getKey();
    }

    /** Das nächste Semester nach dem gegebenen (nach Beginn), null wenn keines folgt. */
    public function nextSemester(int $semester): ?int
    {
        $ids = array_keys($this->semesters());
        $index = array_search($semester, $ids, true);

        return $index === false ? null : ($ids[$index + 1] ?? null);
    }

    /** @return array<int, string> */
    private function people(string $table): array
    {
        return Saft::table($table)->withoutAuthorization()->query()->orderBy('nachname')->orderBy('vorname')->get()
            ->mapWithKeys(static fn (DynamicRecord $p): array => [(int) $p->getKey() => trim($p->getAttribute('vorname').' '.$p->getAttribute('nachname'))])->all();
    }

    /** @return array<int, string> */
    private function labels(string $table): array
    {
        return Saft::table($table)->withoutAuthorization()->query()->orderBy('bezeichnung')->get()
            ->mapWithKeys(static fn (DynamicRecord $r): array => [(int) $r->getKey() => (string) $r->getAttribute('bezeichnung')])->all();
    }

    public static function date(DynamicRecord $record, string $field): string
    {
        $value = $record->getAttribute($field);

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
