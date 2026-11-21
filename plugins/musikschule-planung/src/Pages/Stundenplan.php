<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung\Pages;

use App\Models\DynamicRecord;
use App\Plugins\Api\Saft;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Saft\MusikschulePlanung\MusikschulePlanungPlugin;
use Saft\MusikschulePlanung\School;

/**
 * Stundenplan: Wochenraster eines Semesters je Lehrperson, Raum oder Schüler. Kurse als farbige Blöcke (Farbe je
 * Fach), Höhe nach Dauer, ein Klick öffnet den Kurs; Überschneidungen nebeneinander und rot umrandet. Kurse ohne Tag oder
 * Zeit stehen darunter.
 */
final class Stundenplan extends Page
{
    public const string ROUTE = 'filament.admin.pages.musikschule-stundenplan';

    public const string TEACHER = 'lehrperson';

    public const string ROOM = 'raum';

    public const string STUDENT = 'schueler';

    private const string T = 'musikschule-planung::planung.timetable.';

    /** Farben der Fächer (in der Reihenfolge der Fächer nach Bezeichnung, dann wiederholt). */
    private const array COLORS = ['#2563eb', '#16a34a', '#d97706', '#9333ea', '#db2777', '#0891b2', '#65a30d', '#dc2626', '#4f46e5', '#ca8a04'];

    protected static ?string $slug = 'musikschule-stundenplan';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'musikschule-planung::stundenplan';

    /** @var array{semester?: int|string|null, ansicht?: string|null, auswahl?: int|string|null} */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(MusikschulePlanungPlugin::ID.'.timetable') ?? false;
    }

    public function getTitle(): string
    {
        return __(self::T.'title');
    }

    public function mount(): void
    {
        $school = $this->school();
        $view = in_array(request()->query('ansicht'), [self::TEACHER, self::ROOM, self::STUDENT], true) ? (string) request()->query('ansicht') : self::TEACHER;
        $choices = $school->available() ? $this->choices($view) : [];

        $this->form->fill([
            'semester' => request()->integer('semester') ?: ($school->available() ? $school->currentSemester() : null),
            'ansicht' => $view,
            'auswahl' => request()->integer('auswahl') ?: array_key_first($choices),
        ]);
    }

    private function school(): School
    {
        return app(School::class);
    }

    public function form(Schema $schema): Schema
    {
        if (! $this->school()->available()) {
            return $schema->statePath('data')->components([]);
        }

        return $schema
            ->statePath('data')
            ->components([Grid::make(['default' => 1, 'md' => 3])->schema([
                Select::make('semester')
                    ->label(__(self::T.'semester'))
                    ->options(fn (): array => $this->school()->semesters())
                    ->selectablePlaceholder(false)
                    ->live(),
                ToggleButtons::make('ansicht')
                    ->label(__(self::T.'view'))
                    ->options([self::TEACHER => __(self::T.'views.teacher'), self::ROOM => __(self::T.'views.room'), self::STUDENT => __(self::T.'views.student')])
                    ->inline()
                    ->grouped()
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Set $set) => $set('auswahl', array_key_first($this->choices((string) $state)))),
                Select::make('auswahl')
                    ->label(fn (Get $get): string => __(self::T.'choice.'.($get('ansicht') ?? self::TEACHER)))
                    ->options(fn (Get $get): array => $this->choices((string) ($get('ansicht') ?? self::TEACHER)))
                    ->searchable()
                    ->live(),
            ])]);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->school()->available()) {
            return $schema->components([Callout::make(__(self::T.'unavailable_title'))
                ->description(__(self::T.'unavailable').' '.implode('; ', $this->school()->missing()))
                ->warning()]);
        }

        if (! Saft::table(School::COURSES)->can('view')) {
            return $schema->components([Callout::make(__(self::T.'no_rights'))->danger()]);
        }

        return $schema->components([EmbeddedSchema::make('form')]);
    }

    /** @return array<int, string> Auswahl je Ansicht */
    private function choices(string $view): array
    {
        return match ($view) {
            self::ROOM => $this->school()->rooms(),
            self::STUDENT => $this->school()->students(),
            default => $this->school()->teachers(),
        };
    }

    /** Darf und kann der Stundenplan gezeigt werden? */
    public function ready(): bool
    {
        return $this->school()->available() && Saft::table(School::COURSES)->can('view')
            && filled($this->data['semester'] ?? null) && filled($this->data['auswahl'] ?? null);
    }

    /**
     * Raster für die gewählte Person bzw. den Raum: Tage mit Blöcken, Zeitachse, Kurse ohne Zeit, Legende.
     *
     * @return array{days: array<string, list<array<string, mixed>>>, from: int, to: int, hours: list<int>, unscheduled: list<array<string, mixed>>, legend: array<string, string>, conflicts: int}
     */
    public function timetable(): array
    {
        $school = $this->school();
        $view = (string) ($this->data['ansicht'] ?? self::TEACHER);
        $choice = (int) ($this->data['auswahl'] ?? 0);
        $courses = $school->courses((int) ($this->data['semester'] ?? 0));
        $enrolled = $this->participants($courses->pluck('id')->all());
        $waiting = Saft::table(School::ENROLMENTS)->withoutAuthorization()->query()->whereIn('kurs', $courses->pluck('id')->all())
            ->where('status', 'warteliste')->get()->countBy(static fn (DynamicRecord $e): int => (int) $e->getAttribute('kurs'))->all();

        $courses = $courses->filter(static fn (array $c): bool => match ($view) {
            self::ROOM => $c['room'] === $choice,
            self::STUDENT => in_array($choice, array_keys($enrolled[$c['id']] ?? []), true),
            default => $c['teacher'] === $choice,
        })->values();

        $subjects = $school->subjects();
        $colors = [];

        foreach (array_keys($subjects) as $i => $id) {
            $colors[$id] = self::COLORS[$i % count(self::COLORS)];
        }

        $teachers = $school->teachers();
        $rooms = $school->rooms();
        $table = Saft::table(School::COURSES);
        $block = static function (array $c) use ($view, $teachers, $rooms, $subjects, $colors, $enrolled, $waiting, $table): array {
            $names = array_values($enrolled[$c['id']] ?? []);
            $detail = match ($view) {
                self::ROOM => $teachers[$c['teacher']] ?? '',
                self::TEACHER => $rooms[$c['room']] ?? '',
                default => trim(($teachers[$c['teacher']] ?? '').' · '.($rooms[$c['room']] ?? ''), ' ·'),
            };

            return [
                'id' => $c['id'], 'name' => $c['name'], 'detail' => $detail, 'start' => $c['start'], 'end' => $c['end'],
                'time' => $c['start'] === null ? '' : School::clock($c['start']).'–'.School::clock($c['end']),
                'subject' => $subjects[$c['subject']] ?? '', 'color' => $colors[$c['subject']] ?? '#6b7280',
                'tooltip' => __(self::T.'tooltip', ['count' => count($names), 'max' => $c['max']])
                    .(($waiting[$c['id']] ?? 0) > 0 ? ', '.__(self::T.'waiting', ['count' => $waiting[$c['id']]]) : '')
                    .($names === [] ? '' : "\n".implode("\n", $names)),
                'url' => $table->url($c['id']), 'conflict' => false, 'lane' => 0, 'lanes' => 1,
            ];
        };

        $scheduled = $courses->filter(static fn (array $c): bool => $c['start'] !== null);
        $days = [];

        foreach (School::DAYS as $day) {
            $blocks = $scheduled->where('day', $day)->sortBy('start')->map($block)->values()->all();

            if ($blocks !== [] || $day !== 'so') {
                $days[$day] = self::lanes($blocks);
            }
        }

        $from = $scheduled->isEmpty() ? 8 * 60 : intdiv((int) $scheduled->min('start'), 60) * 60;
        $to = $scheduled->isEmpty() ? 18 * 60 : (int) ceil((int) $scheduled->max('end') / 60) * 60;
        $used = $courses->pluck('subject')->unique()->all();

        return [
            'days' => $days,
            'from' => $from,
            'to' => max($to, $from + 60),
            'hours' => range(intdiv($from, 60), intdiv(max($to, $from + 60), 60) - 1),
            'unscheduled' => $courses->filter(static fn (array $c): bool => $c['start'] === null)->map($block)->values()->all(),
            'legend' => collect($subjects)->only($used)->mapWithKeys(static fn (string $name, int $id): array => [$name => $colors[$id]])->all(),
            'conflicts' => collect($days)->flatten(1)->where('conflict', true)->count(),
        ];
    }

    /**
     * Überschneidende Blöcke eines Tages nebeneinander (Spuren) und als Konflikt markiert.
     *
     * @param  list<array<string, mixed>>  $blocks  nach Beginn sortiert
     * @return list<array<string, mixed>>
     */
    private static function lanes(array $blocks): array
    {
        $cluster = [];
        $clusterEnd = -1;
        $result = [];
        $flush = static function () use (&$cluster, &$result): void {
            $ends = [];

            foreach ($cluster as $i) {
                $lane = 0;

                while (isset($ends[$lane]) && $ends[$lane] > $result[$i]['start']) {
                    $lane++;
                }

                $ends[$lane] = $result[$i]['end'];
                $result[$i]['lane'] = $lane;
            }

            foreach ($cluster as $i) {
                $result[$i]['lanes'] = count($ends);
                $result[$i]['conflict'] = count($cluster) > 1;
            }

            $cluster = [];
        };

        foreach ($blocks as $i => $b) {
            if ($cluster !== [] && $b['start'] >= $clusterEnd) {
                $flush();
            }

            $result[$i] = $b;
            $cluster[] = $i;
            $clusterEnd = max($clusterEnd, $b['end']);
        }

        if ($cluster !== []) {
            $flush();
        }

        return array_values($result);
    }

    /**
     * Aktive Teilnehmende je Kurs.
     *
     * @param  list<int>  $courses
     * @return array<int, array<int, string>> Kurs => Schüler-id => Name
     */
    private function participants(array $courses): array
    {
        $students = $this->school()->students();
        $result = [];

        Saft::table(School::ENROLMENTS)->withoutAuthorization()->query()->whereIn('kurs', $courses)->where('status', 'aktiv')->get()
            ->each(static function (DynamicRecord $e) use (&$result, $students): void {
                $student = (int) $e->getAttribute('schueler');
                $result[(int) $e->getAttribute('kurs')][$student] = $students[$student] ?? '?';
            });

        foreach ($result as &$names) {
            asort($names);
        }

        return $result;
    }

    /** Bezeichnung der gewählten Person bzw. des Raums und des Semesters (Überschrift der Druckansicht). */
    public function caption(): string
    {
        $view = (string) ($this->data['ansicht'] ?? self::TEACHER);

        return trim(($this->choices($view)[(int) ($this->data['auswahl'] ?? 0)] ?? '').' · '.($this->school()->semesters()[(int) ($this->data['semester'] ?? 0)] ?? ''), ' ·');
    }
}
