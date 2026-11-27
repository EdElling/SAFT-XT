<?php

declare(strict_types=1);

namespace Saft\MusikschulePlanung;

use App\Models\DynamicRecord;
use App\Plugins\Api\DataAction;
use App\Plugins\Api\Events\RecordDeleted;
use App\Plugins\Api\Events\RecordSaved;
use App\Plugins\Api\Events\RecordSaving;
use App\Plugins\Api\Manual;
use App\Plugins\Api\RecordListener;
use App\Plugins\MenuSuggestion;
use App\Plugins\SaftPlugin;
use Filament\Actions\Action;
use Saft\MusikschulePlanung\Pages\Praesenz;
use Saft\MusikschulePlanung\Pages\Stundenplan;

/**
 * Beispiel-Plugin für die Demo „Musikschule“: Stundenplan je Lehrperson, Raum oder Schüler und Präsenz erfassen (Seiten
 * unter src/Pages werden automatisch gefunden), Konfliktprüfung beim Speichern eines Kurses, Semester-Fortschreibung,
 * Lektionen erzeugen und Hinweis, wenn für Wartende ein Platz frei wird.
 */
final class MusikschulePlanungPlugin extends SaftPlugin
{
    public const string ID = 'musikschule-planung';

    public function id(): string
    {
        return self::ID;
    }

    public function label(): string
    {
        return __('musikschule-planung::planung.plugin');
    }

    public function description(): string
    {
        return __('musikschule-planung::planung.description');
    }

    public function permissions(): array
    {
        return [
            'timetable' => __('musikschule-planung::planung.permissions.timetable'),
            'rollover' => __('musikschule-planung::planung.permissions.rollover'),
            'lessons' => __('musikschule-planung::planung.permissions.lessons'),
            'attendance' => __('musikschule-planung::planung.permissions.attendance'),
        ];
    }

    public function requiredTables(): array
    {
        return School::REQUIRED;
    }

    /** Konfliktprüfung (verbietet); Hinweise zu den Plätzen des Raums und zur Warteliste (melden nur). */
    public function recordListeners(): array
    {
        return [
            new RecordListener(School::COURSES, RecordSaving::class, CourseConflictGuard::class),
            new RecordListener(School::COURSES, RecordSaved::class, RoomCapacityHint::class),
            new RecordListener(School::COURSES, RecordSaved::class, WaitingListHint::class),
            new RecordListener(School::ENROLMENTS, RecordSaved::class, WaitingListHint::class),
            new RecordListener(School::ENROLMENTS, RecordDeleted::class, WaitingListHint::class),
        ];
    }

    /**
     * Semester fortschreiben (je Zeile der Semester und über der Tabelle), Lektionen erzeugen (je Kurs, je Semester und
     * über der Tabelle Kurse), Präsenz erfassen (je Lektion).
     */
    public function dataActions(): array
    {
        return [
            new DataAction(School::SEMESTERS, DataAction::RECORD, static fn () => RolloverAction::make(true)),
            new DataAction(School::SEMESTERS, DataAction::TABLE, static fn () => RolloverAction::make(false)),
            new DataAction(School::SEMESTERS, DataAction::RECORD, static fn () => LessonAction::make(LessonAction::SEMESTER)),
            new DataAction(School::COURSES, DataAction::RECORD, static fn () => LessonAction::make(LessonAction::COURSE)),
            new DataAction(School::COURSES, DataAction::TABLE, static fn () => LessonAction::make(LessonAction::ALL)),
            new DataAction(School::COURSES, DataAction::BULK, static fn () => LessonAction::make(LessonAction::SELECTED)),
            new DataAction(School::LESSONS, DataAction::RECORD, static fn () => Action::make('musikschulePraesenz')
                ->label(__('musikschule-planung::planung.attendance.title'))
                ->icon('heroicon-o-clipboard-document-check')
                ->iconButton()
                ->tooltip(__('musikschule-planung::planung.attendance.title'))
                ->visible(static fn (): bool => Praesenz::canAccess())
                ->url(static fn (DynamicRecord $record): string => Praesenz::getUrl(['lektion' => $record->getKey()]))),
        ];
    }

    public function manuals(): array
    {
        return [new Manual(
            id: 'manual',
            title: ['de' => 'Musikschule: Planung', 'en' => 'Music school: planning', 'fr' => 'École de musique : planification', 'it' => 'Scuola di musica: pianificazione'],
            path: 'docs/manual',
            permissions: [...$this->permissionNames(), 'data.'.School::COURSES.'.view'],
        )];
    }

    public function menuSuggestions(): array
    {
        return [
            new MenuSuggestion(
                route: Stundenplan::ROUTE,
                label: ['de' => 'Stundenplan', 'en' => 'Timetable', 'fr' => 'Horaire', 'it' => 'Orario'],
                icon: 'heroicon-o-calendar-days',
                permission: $this->permissionName('timetable'),
                group: ['de' => 'Musikschule', 'en' => 'Music school', 'fr' => 'École de musique', 'it' => 'Scuola di musica'],
                sortOrder: 5,
                subgroup: ['de' => 'Unterricht', 'en' => 'Teaching', 'fr' => 'Enseignement', 'it' => 'Insegnamento'],
            ),
            new MenuSuggestion(
                route: Praesenz::ROUTE,
                label: ['de' => 'Präsenz erfassen', 'en' => 'Record attendance', 'fr' => 'Saisir les présences', 'it' => 'Registrare le presenze'],
                icon: 'heroicon-o-clipboard-document-check',
                permission: $this->permissionName('attendance'),
                group: ['de' => 'Musikschule', 'en' => 'Music school', 'fr' => 'École de musique', 'it' => 'Scuola di musica'],
                sortOrder: 6,
                subgroup: ['de' => 'Unterricht', 'en' => 'Teaching', 'fr' => 'Enseignement', 'it' => 'Insegnamento'],
            ),
        ];
    }
}
