<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Dialog „Lektionen erzeugen“: in der Zeile eines Kurses (dieser Kurs), in der Zeile eines Semesters (alle seine Kurse),
 * über der Tabelle Kurse (Kurse des gewählten Semesters, vorbelegt das laufende) und als Sammelaktion für die
 * ausgewählten Kurse (DataAction::BULK, seit API 1.11). Zeitraum vorbelegt: ab heute, wenn das Semester läuft, sonst ab
 * Semesterbeginn, bis Semesterende; bei ausgewählten Kursen aus mehreren Semestern das laufende.
 */
final class LessonAction
{
    public const string COURSE = 'course';

    public const string SEMESTER = 'semester';

    public const string ALL = 'all';

    public const string SELECTED = 'selected';

    private const string T = 'musikschule-planung::planung.lessons.';

    public static function make(string $scope): Action
    {
        $action = ($scope === self::SELECTED ? BulkAction::make('musikschuleLektionen'.ucfirst($scope)) : Action::make('musikschuleLektionen'.ucfirst($scope)))
            ->label(__(self::T.'action'))
            ->icon('heroicon-o-calendar')
            ->visible(static fn (): bool => self::allowed())
            ->modalHeading(__(self::T.'heading'))
            ->modalSubmitActionLabel(__(self::T.'submit'))
            ->modalWidth('lg')
            ->fillForm(static function (Action $action, ?DynamicRecord $record = null) use ($scope): array {
                $school = app(School::class);
                $records = self::selected($action);
                $semesters = $records?->map(static fn (DynamicRecord $r): int => (int) $r->getAttribute('semester'))->unique()->values();
                $semester = match ($scope) {
                    self::COURSE => (int) $record?->getAttribute('semester'),
                    self::SEMESTER => (int) $record?->getKey(),
                    self::SELECTED => $semesters?->count() === 1 ? (int) $semesters->first() : $school->currentSemester(),
                    default => $school->currentSemester(),
                };

                return [
                    'kurs' => $scope === self::COURSE ? $record?->getKey() : null,
                    'kurse' => $scope === self::SELECTED ? self::keys($records) : [],
                    'semester' => $semester,
                    ...self::defaultRange($semester),
                    'ferien' => true,
                ];
            })
            ->schema(static fn (): array => [
                Hidden::make('kurs'),
                Hidden::make('kurse'),
                Select::make('semester')->label(__(self::T.'semester'))->options(static fn (): array => app(School::class)->semesters())
                    ->required()->live()->visible($scope === self::ALL)
                    ->afterStateUpdated(static function (mixed $state, Set $set): void {
                        foreach (self::defaultRange((int) $state) as $key => $value) {
                            $set($key, $value);
                        }
                    }),
                DatePicker::make('von')->label(__(self::T.'from'))->required()->live(),
                DatePicker::make('bis')->label(__(self::T.'to'))->required()->live()->afterOrEqual('von'),
                Toggle::make('ferien')->label(__(self::T.'skip_holidays'))->live()
                    ->helperText(static fn (): ?string => app(School::class)->hasHolidays() ? null : __(self::T.'no_holiday_table'))
                    ->disabled(static fn (): bool => ! app(School::class)->hasHolidays()),
                Text::make(static fn (Get $get): string => self::preview(
                    $scope === self::SELECTED ? array_map('intval', (array) $get('kurse')) : self::courses($scope, $get('kurs'), $get('semester')),
                    $get('von'),
                    $get('bis'),
                    (bool) $get('ferien'),
                )),
            ])
            ->action(static function (Action $action, array $data, ?DynamicRecord $record = null) use ($scope): void {
                abort_unless(self::allowed(), 403);

                if ($scope === self::SELECTED) {
                    // Die ausgewählten Kurse aus der Tabelle, nicht aus dem Formular.
                    self::execute(self::keys(self::selected($action)), $data);

                    return;
                }

                $semester = $scope === self::ALL ? ($data['semester'] ?? null) : ($scope === self::SEMESTER ? $record?->getKey() : $record?->getAttribute('semester'));
                self::execute(self::courses($scope, $scope === self::COURSE ? $record?->getKey() : null, $semester), $data);
            });

        return in_array($scope, [self::ALL, self::SELECTED], true) ? $action : $action->iconButton()->tooltip(__(self::T.'action'));
    }

    public static function allowed(): bool
    {
        return auth()->user()?->can(MusikschulePlanungPlugin::ID.'.lessons') ?? false;
    }

    /** @return array{von: string|null, bis: string|null} */
    private static function defaultRange(?int $semester): array
    {
        $range = $semester ? app(School::class)->semesterRange($semester) : null;

        if ($range === null) {
            return ['von' => null, 'bis' => null];
        }

        $today = now()->toDateString();

        return ['von' => $today > $range[0] && $today <= $range[1] ? $today : $range[0], 'bis' => $range[1]];
    }

    /** Ausgewählte Kurse, nur bei der Sammelaktion (andere Aktionen haben keine Auswahl). */
    private static function selected(Action $action): ?Collection
    {
        return $action instanceof BulkAction ? collect($action->getSelectedRecords()) : null;
    }

    /** @return list<int> Schlüssel der ausgewählten Kurse */
    private static function keys(?Collection $records): array
    {
        return $records === null ? [] : array_values($records->map(static fn (DynamicRecord $r): int => (int) $r->getKey())->all());
    }

    /** @return list<int> */
    private static function courses(string $scope, mixed $course, mixed $semester): array
    {
        if ($scope === self::COURSE) {
            return filled($course) ? [(int) $course] : [];
        }

        return blank($semester) ? [] : Saft::table(School::COURSES)->withoutAuthorization()->query()->where('semester', (int) $semester)
            ->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /** @param  list<int>  $courses */
    private static function preview(array $courses, mixed $from, mixed $to, bool $skipHolidays): string
    {
        if (blank($from) || blank($to) || $from > $to) {
            return __(self::T.'choose');
        }

        $plan = app(LessonGenerator::class)->plan($courses, substr((string) $from, 0, 10), substr((string) $to, 0, 10), $skipHolidays);

        return self::summary(count($plan['lessons']), $plan);
    }

    /** @param  array{courses: int, holidays: int, existing: int, unscheduled: list<string>}  $plan */
    private static function summary(int $lessons, array $plan): string
    {
        $parts = [__(self::T.'preview', ['lessons' => $lessons, 'courses' => $plan['courses']])];

        if ($plan['holidays'] > 0) {
            $parts[] = __(self::T.'preview_holidays', ['count' => $plan['holidays']]);
        }

        if ($plan['existing'] > 0) {
            $parts[] = __(self::T.'preview_existing', ['count' => $plan['existing']]);
        }

        if ($plan['unscheduled'] !== []) {
            $parts[] = __(self::T.'preview_unscheduled', ['count' => count($plan['unscheduled']), 'names' => implode(', ', $plan['unscheduled'])]);
        }

        return implode(' ', $parts);
    }

    /**
     * @param  list<int>  $courses
     * @param  array<string, mixed>  $data
     */
    private static function execute(array $courses, array $data): void
    {
        $generator = app(LessonGenerator::class);

        if (($missing = $generator->missingRights()) !== []) {
            Notification::make()->danger()->title(__(self::T.'rights', ['tables' => implode(', ', $missing)]))->send();

            return;
        }

        try {
            $result = $generator->run($courses, substr((string) $data['von'], 0, 10), substr((string) $data['bis'], 0, 10), (bool) ($data['ferien'] ?? false));
        } catch (RecordRejected $e) {
            Notification::make()->danger()->title(__(self::T.'failed'))->body($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(__(self::T.'failed'))->body(implode("\n", Arr::flatten($e->errors())))->send();

            return;
        }

        Notification::make()->success()->title(__(self::T.'done', ['lessons' => $result['lessons'], 'courses' => $result['courses']]))
            ->body(self::summary($result['lessons'], $result))->send();
    }
}
