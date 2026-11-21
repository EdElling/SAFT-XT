<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Plugins\Api\RecordRejected;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Dialog „Semester fortschreiben“: in der Zeile eines Semesters (Quelle vorbelegt) und über der Tabelle Semester
 * (Quelle = laufendes Semester). Ziel vorbelegt mit dem nächsten Semester; Vorschau vor dem Ausführen.
 */
final class RolloverAction
{
    private const string T = 'musikschule-planung::planung.rollover.';

    public static function make(bool $perRecord): Action
    {
        $action = Action::make($perRecord ? 'musikschuleFortschreiben' : 'musikschuleFortschreibenAlle')
            ->label(__(self::T.'action'))
            ->icon('heroicon-o-forward')
            ->visible(static fn (): bool => self::allowed())
            ->modalHeading(__(self::T.'heading'))
            ->modalSubmitActionLabel(__(self::T.'submit'))
            ->modalWidth('lg')
            ->fillForm(static function (?DynamicRecord $record = null) use ($perRecord): array {
                $school = app(School::class);
                $source = $perRecord && $record !== null ? (int) $record->getKey() : $school->currentSemester();

                return ['quelle' => $source, 'ziel' => $source === null ? null : $school->nextSemester($source),
                    'raum_zeit' => true, 'anmeldungen' => true, 'tarif_neu' => true];
            })
            ->schema(static fn (): array => [
                Select::make('quelle')->label(__(self::T.'source'))->options(static fn (): array => app(School::class)->semesters())->required()->live(),
                Select::make('ziel')->label(__(self::T.'target'))->options(static fn (): array => app(School::class)->semesters())->required()->live()
                    ->different('quelle'),
                Toggle::make('raum_zeit')->label(__(self::T.'keep_schedule'))->helperText(__(self::T.'keep_schedule_help')),
                Toggle::make('anmeldungen')->label(__(self::T.'enrolments'))->helperText(__(self::T.'enrolments_help'))->live(),
                Toggle::make('tarif_neu')->label(__(self::T.'new_tariff'))->helperText(__(self::T.'new_tariff_help')),
                Text::make(static fn (Get $get): string => self::preview($get('quelle'), $get('ziel'), (bool) $get('anmeldungen'))),
            ])
            ->action(static function (array $data): void {
                abort_unless(self::allowed(), 403);
                self::execute($data);
            });

        return $perRecord ? $action->iconButton()->tooltip(__(self::T.'action')) : $action;
    }

    public static function allowed(): bool
    {
        return auth()->user()?->can(MusikschulePlanungPlugin::ID.'.rollover') ?? false;
    }

    private static function preview(mixed $source, mixed $target, bool $withEnrolments): string
    {
        if (blank($source) || blank($target)) {
            return __(self::T.'choose');
        }

        if ((int) $source === (int) $target) {
            return __(self::T.'same');
        }

        $preview = app(SemesterRollover::class)->preview((int) $source, (int) $target, $withEnrolments);
        $text = __(self::T.'preview', ['courses' => count($preview['courses']), 'enrolments' => $preview['enrolments']]);

        if ($preview['skipped'] !== []) {
            $text .= ' '.__(self::T.'preview_skipped', ['count' => count($preview['skipped']), 'names' => implode(', ', $preview['skipped'])]);
        }

        return $text;
    }

    /** @param  array<string, mixed>  $data */
    private static function execute(array $data): void
    {
        $rollover = app(SemesterRollover::class);
        $withEnrolments = (bool) ($data['anmeldungen'] ?? false);

        if (($missing = $rollover->missingRights($withEnrolments)) !== []) {
            Notification::make()->danger()->title(__(self::T.'rights', ['tables' => implode(', ', $missing)]))->send();

            return;
        }

        try {
            $result = $rollover->run((int) $data['quelle'], (int) $data['ziel'], (bool) ($data['raum_zeit'] ?? true), $withEnrolments, (bool) ($data['tarif_neu'] ?? true));
        } catch (RolloverRejected|RecordRejected $e) {
            Notification::make()->danger()->title(__(self::T.'failed'))->body($e->getMessage())->send();

            return;
        } catch (ValidationException $e) {
            Notification::make()->danger()->title(__(self::T.'failed'))->body(implode("\n", Arr::flatten($e->errors())))->send();

            return;
        }

        $semesters = app(School::class)->semesters();
        Notification::make()->success()->title(__(self::T.'done', [
            'courses' => $result['courses'],
            'enrolments' => $result['enrolments'],
            'target' => $semesters[(int) $data['ziel']] ?? '',
        ]))->body($result['skipped'] === [] ? null : __(self::T.'preview_skipped', ['count' => count($result['skipped']), 'names' => implode(', ', $result['skipped'])]))->send();
    }
}
