<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung\Pages;

use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Saft\MusikschulePlanung\MusikschulePlanungPlugin;
use Saft\MusikschulePlanung\School;

/**
 * Präsenz erfassen: zu einer Lektion alle aktiv angemeldeten Schüler mit Status (Vorgabe: anwesend) und Bemerkung;
 * vorhandene Präsenzen werden geladen – auch von Schülern, die inzwischen nicht mehr aktiv angemeldet sind. Speichern
 * legt fehlende Präsenzen an und ändert geänderte, ohne Doppel, in einer Transaktion.
 */
final class Praesenz extends Page
{
    public const string ROUTE = 'filament.admin.pages.musikschule-praesenz';

    public const array STATUSES = ['anwesend', 'entschuldigt', 'unentschuldigt'];

    private const string T = 'musikschule-planung::planung.attendance.';

    protected static ?string $slug = 'musikschule-praesenz';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'musikschule-planung::praesenz';

    /** @var array{lektion?: int|string|null} */
    public ?array $data = [];

    /** @var array<int, array{name: string, status: string, bemerkung: string, active: bool, record: int|null}> Schüler-id => Eintrag */
    public array $entries = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(MusikschulePlanungPlugin::ID.'.attendance') ?? false;
    }

    public function getTitle(): string
    {
        return __(self::T.'title');
    }

    private function school(): School
    {
        return app(School::class);
    }

    private function ready(): bool
    {
        return $this->school()->available() && Saft::table(School::ATTENDANCE)->can('view');
    }

    public function mount(): void
    {
        $lesson = request()->integer('lektion') ?: ($this->ready() ? $this->defaultLesson() : null);
        $this->form->fill(['lektion' => $lesson]);
        $this->load();
    }

    /** Die nächste Lektion von heute, sonst die letzte vor heute, sonst die erste. */
    private function defaultLesson(): ?int
    {
        $today = now()->toDateString();
        $lessons = Saft::table(School::LESSONS)->withoutAuthorization()->query();
        $todays = (clone $lessons)->whereDate('datum', $today)->get();

        if ($todays->isNotEmpty()) {
            $times = Saft::table(School::COURSES)->withoutAuthorization()->query()->whereIn('id', $todays->pluck('kurs'))->pluck('uhrzeit', 'id');
            $now = School::minutes(now()->format('H:i')) ?? 0;
            $sorted = $todays->sortBy(static fn (DynamicRecord $l): int => School::minutes($times[(int) $l->getAttribute('kurs')] ?? null) ?? 0);
            $next = $sorted->first(static fn (DynamicRecord $l): bool => (School::minutes($times[(int) $l->getAttribute('kurs')] ?? null) ?? 0) + (int) $l->getAttribute('dauer_minuten') >= $now) ?? $sorted->last();

            return (int) $next->getKey();
        }

        $id = (clone $lessons)->whereDate('datum', '<', $today)->orderByDesc('datum')->orderByDesc('id')->value('id') ?? (clone $lessons)->orderBy('datum')->value('id');

        return $id === null ? null : (int) $id;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components($this->ready() ? [
                Select::make('lektion')
                    ->label(__(self::T.'lesson'))
                    ->options(fn (): array => $this->lessonOptions())
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(fn () => $this->load()),
            ] : []);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->school()->available()) {
            return $schema->components([Callout::make(__('musikschule-planung::planung.timetable.unavailable_title'))
                ->description(__('musikschule-planung::planung.timetable.unavailable').' '.implode('; ', $this->school()->missing()))
                ->warning()]);
        }

        if (! Saft::table(School::ATTENDANCE)->can('view')) {
            return $schema->components([Callout::make(__(self::T.'no_rights'))->danger()]);
        }

        return $schema->components([EmbeddedSchema::make('form')]);
    }

    /**
     * Lektionen des Semesters der gewählten (bzw. des laufenden Semesters), nach Datum: „Mo 17.08.2026 · Kurs · Lehrperson“.
     *
     * @return array<int, string>
     */
    private function lessonOptions(): array
    {
        $courses = Saft::table(School::COURSES)->withoutAuthorization()->query()->get()->keyBy(static fn (DynamicRecord $c): int => (int) $c->getKey());
        $selected = $this->lesson();
        $semester = $selected !== null ? (int) $courses[(int) $selected->getAttribute('kurs')]?->getAttribute('semester') : $this->school()->currentSemester();
        $ids = $courses->filter(static fn (DynamicRecord $c): bool => (int) $c->getAttribute('semester') === $semester)->keys()->all();
        $teachers = $this->school()->teachers();
        $days = __('musikschule-planung::planung.short_days');

        return Saft::table(School::LESSONS)->withoutAuthorization()->query()->whereIn('kurs', $ids)->orderBy('datum')->orderBy('id')->get()
            ->mapWithKeys(static function (DynamicRecord $l) use ($courses, $teachers, $days): array {
                $date = School::date($l, 'datum');
                $course = $courses[(int) $l->getAttribute('kurs')] ?? null;
                $label = ($days[(int) date('N', (int) strtotime($date)) - 1] ?? '').' '.date('d.m.Y', (int) strtotime($date))
                    .' · '.$course?->getAttribute('bezeichnung').' · '.($teachers[(int) $l->getAttribute('lehrperson')] ?? '');

                return [(int) $l->getKey() => $label];
            })->all();
    }

    public function lesson(): ?DynamicRecord
    {
        $id = (int) ($this->data['lektion'] ?? 0);

        return $id > 0 && $this->ready() ? Saft::table(School::LESSONS)->withoutAuthorization()->find($id) : null;
    }

    /** Einträge der gewählten Lektion laden: aktive Anmeldungen (Vorgabe anwesend) und vorhandene Präsenzen. */
    public function load(): void
    {
        $this->entries = [];
        $lesson = $this->lesson();

        if ($lesson === null) {
            return;
        }

        $students = $this->school()->students();
        $active = Saft::table(School::ENROLMENTS)->withoutAuthorization()->query()->where('kurs', $lesson->getAttribute('kurs'))
            ->where('status', 'aktiv')->pluck('schueler')->map(static fn (mixed $id): int => (int) $id)->all();

        foreach ($active as $student) {
            $this->entries[$student] = ['name' => $students[$student] ?? '?', 'status' => 'anwesend', 'bemerkung' => '', 'active' => true, 'record' => null];
        }

        foreach (Saft::table(School::ATTENDANCE)->withoutAuthorization()->query()->where('lektion', $lesson->getKey())->orderBy('id')->get() as $p) {
            $student = (int) $p->getAttribute('schueler');
            $this->entries[$student] = [
                'name' => $students[$student] ?? '?',
                'status' => in_array($p->getAttribute('status'), self::STATUSES, true) ? (string) $p->getAttribute('status') : 'anwesend',
                'bemerkung' => (string) $p->getAttribute('bemerkung'),
                'active' => in_array($student, $active, true),
                'record' => (int) $p->getKey(),
            ];
        }

        uasort($this->entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
    }

    /** Alle auf einen Status setzen (z. B. „alle anwesend“). */
    public function setAll(string $status): void
    {
        if (in_array($status, self::STATUSES, true)) {
            foreach (array_keys($this->entries) as $student) {
                $this->entries[$student]['status'] = $status;
            }
        }
    }

    /** @return array<string, int> Status => Anzahl */
    public function counts(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);

        foreach ($this->entries as $entry) {
            $counts[$entry['status']] = ($counts[$entry['status']] ?? 0) + 1;
        }

        return $counts;
    }

    public function canSave(): bool
    {
        $table = Saft::table(School::ATTENDANCE);

        return $table->can('create') && $table->can('update');
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $lesson = $this->lesson();

        if ($lesson === null || $this->entries === []) {
            return;
        }

        if (! $this->canSave()) {
            Notification::make()->danger()->title(__(self::T.'rights', ['table' => Saft::table(School::ATTENDANCE)->label()]))->send();

            return;
        }

        $table = Saft::table(School::ATTENDANCE);

        try {
            [$created, $updated] = Saft::transaction(function () use ($table, $lesson): array {
                $created = 0;
                $updated = 0;

                foreach ($this->entries as $student => $entry) {
                    $values = ['status' => in_array($entry['status'], self::STATUSES, true) ? $entry['status'] : 'anwesend', 'bemerkung' => filled($entry['bemerkung']) ? trim($entry['bemerkung']) : null];
                    $existing = $entry['record'] !== null ? $table->find($entry['record'])
                        : $table->query()->where('lektion', $lesson->getKey())->where('schueler', $student)->first();

                    if ($existing === null) {
                        $table->create(['lektion' => $lesson->getKey(), 'schueler' => $student, ...$values]);
                        $created++;
                    } elseif ($existing->getAttribute('status') !== $values['status'] || (string) $existing->getAttribute('bemerkung') !== (string) $values['bemerkung']) {
                        $table->update($existing, $values);
                        $updated++;
                    }
                }

                return [$created, $updated];
            });
        } catch (RecordRejected $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(__(self::T.'invalid'))->body(implode("\n", Arr::flatten($e->errors())))->send();

            return;
        }

        $this->load();
        Notification::make()->success()->title(__(self::T.'saved', ['created' => $created, 'updated' => $updated]))->send();
    }
}
