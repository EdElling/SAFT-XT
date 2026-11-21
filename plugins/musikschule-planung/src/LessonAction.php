<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use App\Plugins\Api\Saft;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Dialog „Lektionen erzeugen“: in der Zeile eines Kurses (dieser Kurs), in der Zeile eines Semesters (alle seine Kurse)
 * und über der Tabelle Kurse (Kurse des gewählten Semesters, vorbelegt das laufende). Zeitraum vorbelegt: ab heute,
 * wenn das Semester läuft, sonst ab Semesterbeginn, bis Semesterende.
 */
final class LessonAction
{
    public const string COURSE = 'course';

    public const string SEMESTER = 'semester';

    public const string ALL = 'all';

    private const string T = 'musikschule-planung::planung.lessons.';

    public static function make(string $scope): Action
    {
        $action = Action::make('musikschuleLektionen'.ucfirst($scope))
            ->label(__(self::T.'action'))
            ->icon('heroicon-o-calendar')
            ->visible(static fn (): bool => self::allowed())
            ->modalHeading(__(self::T.'heading'))
            ->modalSubmitActionLabel(__(self::T.'submit'))
            ->modalWidth('lg')
            ->fillForm(static function (?DynamicRecord $record = null) use ($scope): array {
                $school = app(School::class);
                $semester = match ($scope) {
                    self::COURSE => (int) $record?->getAttribute('semester'),
                    self::SEMESTER => (int) $record?->getKey(),
                    default => $school->currentSemester(),
                };

                return ['kurs' => $scope === self::COURSE ? $record?->getKey() : null, 'semester' => $semester, ...self::defaultRange($semester), 'ferien' => true];
            })
            ->schema(static fn (): array => [
                Hidden::make('kurs'),
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
                Text::make(static fn (Get $get): string => self::preview(self::courses($scope, $get('kurs'), $get('semester')), $get('von'), $get('bis'), (bool) $get('ferien'))),
            ])
            ->action(static function (array $data, ?DynamicRecord $record = null) use ($scope): void {
                abort_unless(self::allowed(), 403);
                $semester = $scope === self::ALL ? ($data['semester'] ?? null) : ($scope === self::SEMESTER ? $record?->getKey() : $record?->getAttribute('semester'));
                self::execute(self::courses($scope, $scope === self::COURSE ? $record?->getKey() : null, $semester), $data);
            });

        return $scope === self::ALL ? $action : $action->iconButton()->tooltip(__(self::T.'action'));
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
